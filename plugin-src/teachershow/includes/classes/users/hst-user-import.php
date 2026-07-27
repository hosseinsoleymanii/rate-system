<?php

defined('ABSPATH') || exit;

/**
 * Bulk user import.
 *
 * Imports students and teachers from Sida Ctrl+A text, CSV, or Excel.
 * Student import preserves the existing student-specific flow.
 * Teacher import mirrors the teacher individual registration fields that are
 * available in Sida personnel list; national code and birthdate are completed
 * manually in the preview before final import.
 */
class HST_User_Import
{

    public function __construct()
    {
        add_action('wp_ajax_hst_import_users', [$this, 'ajax_import']);
        add_action('wp_ajax_hst_import_template_rows', [$this, 'ajax_template_rows']);
        add_action('wp_ajax_hst_import_phone_conflicts', [$this, 'ajax_phone_conflicts']);
        add_action('wp_ajax_hst_import_student_photos_start', [$this, 'ajax_import_student_photos_start']);
        add_action('wp_ajax_hst_import_student_photos_step', [$this, 'ajax_import_student_photos_step']);
    }

    public function ajax_template_rows(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');

        $role = sanitize_key((string) wp_unslash($_POST['import_role'] ?? 'student'));
        if ($role === 'teacher') {
            wp_send_json_success($this->teacher_excel_template_payload());
        }

        wp_send_json_success($this->student_excel_template_payload());
    }

    public function ajax_phone_conflicts(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
        if (class_exists('HST_User_Phones')) {
            HST_User_Phones::begin_batch(true);
        }

        $role = sanitize_key((string) wp_unslash($_POST['import_role'] ?? 'student'));
        $role = $role === 'teacher' ? 'teacher' : 'student';

        $rows = isset($_POST['rows']) && is_array($_POST['rows']) ? wp_unslash($_POST['rows']) : [];
        $conflicts = [];
        $row_results = [];

        foreach ($rows as $fallback_index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $row_index = isset($row['_row_index']) ? absint($row['_row_index']) : (int) $fallback_index;
            $phone = $this->normalize_mobile(sanitize_text_field($row['phone'] ?? ''));

            $row_results[$row_index] = [
                'phone' => $phone,
                'can_update_existing' => false,
                'conflict' => false,
                'message' => '',
                'user_id' => 0,
            ];

            if ($role === 'student') {
                $target_id = $this->student_existing_user_id_from_import_row($row);
                if ($target_id) {
                    $row_results[$row_index]['can_update_existing'] = true;
                    $row_results[$row_index]['user_id'] = $target_id;
                    $phone_owner = $this->find_user_id_by_mobile($phone);
                    if (!$phone_owner || $phone_owner === $target_id) {
                        continue;
                    }
                }
            }

            if ($phone === '' || !HST_Guard::is_valid_iran_mobile($phone)) {
                continue;
            }

            $user_id = $this->find_user_id_by_mobile($phone);
            if (!$user_id) {
                continue;
            }

            $message = $this->phone_conflict_message_for_import_user($user_id, $role, $row);
            if ($message !== '') {
                $conflicts[$phone] = [
                    'user_id' => $user_id,
                    'message' => $message,
                ];

                $row_results[$row_index]['conflict'] = true;
                $row_results[$row_index]['message'] = $message;
                $row_results[$row_index]['user_id'] = $user_id;
            }
        }

        if (class_exists('HST_User_Phones')) {
            HST_User_Phones::end_batch();
        }

        wp_send_json_success([
            'conflicts' => $conflicts,
            'row_results' => $row_results,
        ]);
    }

    public function ajax_import_student_photos_start(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');

        $photo_prefix = esc_url_raw(trim((string) wp_unslash($_POST['photo_prefix'] ?? '')));
        if ($photo_prefix === '') {
            HST_Guard::fail('ابتدا پیشوند عکس دانش‌آموزان را وارد کنید.');
        }

        $term_id = class_exists('HST_Terms') ? (int) HST_Terms::active_id() : 0;
        if (!$term_id) {
            HST_Guard::fail('برای انتقال تصاویر ابتدا یک سال تحصیلی فعال تعریف کنید.');
        }

        update_option('hst-import-photo-prefix', $photo_prefix);

        global $wpdb;
        $student_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT uc.user_id
             FROM {$wpdb->prefix}hst_users_classes uc
             INNER JOIN {$wpdb->users} u ON u.ID = uc.user_id
             WHERE uc.term_id = %d AND uc.role = 'student'
             ORDER BY uc.user_id ASC",
            $term_id
        ));
        $student_ids = array_values(array_unique(array_filter(array_map('absint', $student_ids ?: []))));

        if (!$student_ids) {
            HST_Guard::fail('در سال تحصیلی فعال دانش‌آموزی برای انتقال تصویر پیدا نشد.');
        }

        $pending_ids = [];
        foreach ($student_ids as $student_id) {
            if (!$this->has_valid_avatar($student_id)) {
                $pending_ids[] = $student_id;
            }
        }

        if (!$pending_ids) {
            wp_send_json_success([
                'done'    => true,
                'total'   => 0,
                'percent' => 100,
                'message' => 'همه دانش‌آموزان سال تحصیلی فعال دارای تصویر هستند.',
                'stats'   => [
                    'imported' => 0,
                    'failed'   => 0,
                    'existing' => count($student_ids),
                ],
            ]);
        }

        $token = strtolower(wp_generate_password(24, false, false));
        $state = [
            'owner_id'    => get_current_user_id(),
            'term_id'     => $term_id,
            'photo_prefix'=> $photo_prefix,
            'student_ids' => $pending_ids,
            'offset'      => 0,
            'total'       => count($pending_ids),
            'imported'    => 0,
            'failed'      => 0,
            'existing'    => count($student_ids) - count($pending_ids),
            'errors'      => [],
            'started_at'  => time(),
        ];

        set_transient($this->student_photo_job_key($token), $state, 2 * HOUR_IN_SECONDS);

        wp_send_json_success([
            'token'   => $token,
            'done'    => false,
            'total'   => count($pending_ids),
            'percent' => 0,
            'message' => 'فهرست دانش‌آموزان بدون تصویر آماده شد.',
        ]);
    }

    public function ajax_import_student_photos_step(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');

        $token = sanitize_key((string) wp_unslash($_POST['token'] ?? ''));
        if ($token === '') {
            HST_Guard::fail('شناسه عملیات انتقال تصاویر نامعتبر است.');
        }

        $key = $this->student_photo_job_key($token);
        $state = get_transient($key);
        if (!is_array($state) || empty($state)) {
            HST_Guard::fail('عملیات انتقال تصاویر پیدا نشد یا منقضی شده است. دوباره شروع کنید.');
        }

        if ((int) ($state['owner_id'] ?? 0) !== get_current_user_id()) {
            HST_Guard::fail('این عملیات متعلق به کاربر فعلی نیست.', 403);
        }

        $student_ids = array_values(array_filter(array_map('absint', $state['student_ids'] ?? [])));
        $total = max(0, (int) ($state['total'] ?? count($student_ids)));
        $offset = max(0, (int) ($state['offset'] ?? 0));
        $batch_size = max(1, (int) apply_filters('hst_sida_photo_import_batch_size', 1));
        $batch = array_slice($student_ids, $offset, $batch_size);
        $last_name = '';

        if (!$batch || $offset >= $total) {
            delete_transient($key);
            wp_send_json_success($this->student_photo_progress_payload($state, true));
        }

        wp_raise_memory_limit('admin');

        foreach ($batch as $student_id) {
            $user = get_userdata($student_id);
            $last_name = $user ? (string) $user->display_name : ('دانش‌آموز شماره ' . $student_id);

            if ($this->has_valid_avatar($student_id)) {
                $state['existing'] = (int) ($state['existing'] ?? 0) + 1;
                $state['offset'] = (int) ($state['offset'] ?? 0) + 1;
                continue;
            }

            $code = $this->student_photo_code($student_id);
            if ($code === '') {
                $state['failed'] = (int) ($state['failed'] ?? 0) + 1;
                $state['errors'][] = $last_name . ': کد دانش‌آموزی یا کد ملی برای دریافت تصویر موجود نیست.';
                $state['offset'] = (int) ($state['offset'] ?? 0) + 1;
                continue;
            }

            if ($this->import_avatar($student_id, (string) $state['photo_prefix'], $code)) {
                $state['imported'] = (int) ($state['imported'] ?? 0) + 1;
            } else {
                $state['failed'] = (int) ($state['failed'] ?? 0) + 1;
                $state['errors'][] = $last_name . ': تصویر از نشانی سیدا دریافت نشد.';
            }

            $state['offset'] = (int) ($state['offset'] ?? 0) + 1;
        }

        $state['errors'] = array_slice(array_values(array_unique(array_filter(array_map('strval', $state['errors'] ?? [])))), 0, 30);
        $done = (int) ($state['offset'] ?? 0) >= $total;

        if ($done) {
            delete_transient($key);
        } else {
            set_transient($key, $state, 2 * HOUR_IN_SECONDS);
        }

        $payload = $this->student_photo_progress_payload($state, $done);
        if (!$done && $last_name !== '') {
            $payload['message'] = 'بررسی تصویر «' . $last_name . '» انجام شد.';
        }

        wp_send_json_success($payload);
    }

    private function student_photo_progress_payload(array $state, bool $done): array
    {
        $total = max(0, (int) ($state['total'] ?? 0));
        $processed = min($total, max(0, (int) ($state['offset'] ?? 0)));
        $percent = $total > 0 ? (int) floor(($processed / $total) * 100) : 100;
        if ($done) {
            $percent = 100;
        }

        return [
            'done'      => $done,
            'processed' => $processed,
            'total'     => $total,
            'percent'   => $percent,
            'message'   => $done ? 'انتقال تصاویر دانش‌آموزان کامل شد.' : 'در حال دریافت تصاویر دانش‌آموزان...',
            'stats'     => [
                'imported' => (int) ($state['imported'] ?? 0),
                'failed'   => (int) ($state['failed'] ?? 0),
                'existing' => (int) ($state['existing'] ?? 0),
            ],
            'errors'    => array_values($state['errors'] ?? []),
        ];
    }

    private function student_photo_job_key(string $token): string
    {
        return 'hst_sida_photo_job_' . md5($token);
    }

    private function has_valid_avatar(int $user_id): bool
    {
        $avatar_id = absint(get_user_meta($user_id, 'hst_profile_avatar_id', true));
        if (!$avatar_id || !get_post($avatar_id)) {
            return false;
        }

        $file = get_attached_file($avatar_id);
        return is_string($file) && $file !== '' && file_exists($file);
    }

    private function student_photo_code(int $user_id): string
    {
        $code = (string) get_user_meta($user_id, 'hst_student_code', true);
        if ($code === '') {
            $code = (string) get_user_meta($user_id, 'hst_national_code', true);
        }
        if ($code === '') {
            $user = get_userdata($user_id);
            $code = $user ? (string) $user->user_login : '';
        }

        return $this->only_digits($code);
    }

    private function student_excel_template_payload(): array
    {
        $base_headers = [
            'نام دانش‌آموز',
            'نام خانوادگی',
            'شماره موبایل',
            'کد ملی / کد دانش‌آموزی',
            'نام پدر',
            'تاریخ تولد',
            'کلاس مقصد / عنوان کلاس',
            'شماره تماس پدر',
            'شماره تماس مادر',
        ];
        $headers = $base_headers;

        $rows = [];
        foreach ($this->template_users_by_role('student') as $user_id) {
            $user = get_userdata($user_id);
            if (!$user) {
                continue;
            }

            $class_title = $this->student_active_class_title($user_id);

            $base_row = [
                (string) get_user_meta($user_id, 'first_name', true),
                (string) get_user_meta($user_id, 'last_name', true),
                $this->template_user_phone($user_id),
                (string) (get_user_meta($user_id, 'hst_student_code', true) ?: get_user_meta($user_id, 'hst_national_code', true)),
                (string) get_user_meta($user_id, 'hst_father_name', true),
                (string) get_user_meta($user_id, 'hst_birthdate', true),
                $class_title,
                (string) get_user_meta($user_id, 'hst_parent_phone', true),
                (string) get_user_meta($user_id, 'hst_mother_phone', true),
            ];

            $rows[] = $base_row;
        }

        if (empty($rows)) {
            $rows[] = ['محمدمهدی', 'کلانتری', '09100000000', '441711944', 'حسین', '1389/01/01', 'دهم انسانی', '', ''];
        }

        return [
            'role'     => 'student',
            'filename' => 'نمونه-ورود-دانش-آموزان.xlsx',
            'title'    => 'قالب ورود و بروزرسانی دانش‌آموزان',
            'note'     => 'اگر دانش‌آموز قبلاً در سیستم ثبت شده باشد، اطلاعات او در همین فایل آمده و با آپلود مجدد، همان رکورد بروزرسانی می‌شود.',
            'required' => ['نام دانش‌آموز', 'نام خانوادگی', 'شماره موبایل', 'کد ملی / کد دانش‌آموزی', 'نام پدر', 'تاریخ تولد'],
            'headers'  => $headers,
            'rows'     => $rows,
        ];
    }

    private function teacher_excel_template_payload(): array
    {
        $base_headers = [
            'نام معلم',
            'نام خانوادگی',
            'شماره موبایل',
            'کد ملی',
            'کد پرسنلی',
            'تاریخ تولد',
        ];
        $headers = $base_headers;

        $rows = [];
        foreach ($this->template_users_by_role('teacher') as $user_id) {
            $user = get_userdata($user_id);
            if (!$user) {
                continue;
            }

            $base_row = [
                (string) get_user_meta($user_id, 'first_name', true),
                (string) get_user_meta($user_id, 'last_name', true),
                $this->template_user_phone($user_id),
                (string) get_user_meta($user_id, 'hst_national_code', true),
                (string) get_user_meta($user_id, 'hst_personnel_code', true),
                (string) get_user_meta($user_id, 'hst_birthdate', true),
            ];

            $rows[] = $base_row;
        }

        if (empty($rows)) {
            $rows[] = ['مهدی', 'جلیلی', '09396121329', '0012345678', '12556772', ''];
        }

        return [
            'role'     => 'teacher',
            'filename' => 'نمونه-ورود-معلم‌ها.xlsx',
            'title'    => 'قالب ورود و بروزرسانی معلم‌ها',
            'note'     => 'اگر معلم قبلاً در سیستم ثبت شده باشد، اطلاعات او در همین فایل آمده و با آپلود مجدد، همان رکورد بروزرسانی می‌شود. کد ملی نام کاربری معلم است.',
            'required' => ['نام معلم', 'نام خانوادگی', 'شماره موبایل', 'کد ملی', 'کد پرسنلی'],
            'headers'  => $headers,
            'rows'     => $rows,
        ];
    }

    private function template_users_by_role(string $role): array
    {
        $users = get_users([
            'role'    => $role,
            'fields'  => 'ID',
            'number'  => -1,
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ]);

        $out = [];
        foreach (array_map('intval', $users ?: []) as $user_id) {
            $user = get_userdata($user_id);
            if (!$user) {
                continue;
            }

            $roles = (array) $user->roles;
            if (!in_array($role, $roles, true)) {
                continue;
            }

            // A user should not be both teacher and student. If old bad data has
            // both roles, keep it out of Excel templates to avoid cross-role
            // sample rows and accidental re-import with the wrong role.
            if ($role === 'teacher' && in_array('student', $roles, true)) {
                continue;
            }
            if ($role === 'student' && in_array('teacher', $roles, true)) {
                continue;
            }

            $out[] = $user_id;
        }

        return $out;
    }

    private function template_user_phone(int $user_id): string
    {
        return class_exists('HST_User_Phones')
            ? HST_User_Phones::get($user_id)
            : (string) get_user_meta($user_id, 'phone', true);
    }

    private function student_active_class_title(int $user_id): string
    {
        global $wpdb;

        if (!class_exists('HST_Terms')) {
            return '';
        }

        $term_id = (int) HST_Terms::active_id();
        if (!$term_id) {
            return '';
        }

        $classes = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT c.class_name
             FROM {$wpdb->prefix}hst_users_classes uc
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = uc.class_id
             WHERE uc.user_id = %d AND uc.term_id = %d AND uc.role = 'student'
             ORDER BY c.class_name ASC",
            $user_id,
            $term_id
        ));

        $classes = HST_Classes::sort_names(array_filter(array_map('strval', $classes ?: [])));
        return implode('، ', $classes);
    }

    public function ajax_import(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
        if (class_exists('HST_User_Phones')) {
            HST_User_Phones::begin_batch(true);
        }

        $import_role = sanitize_key((string) wp_unslash($_POST['import_role'] ?? 'student'));
        if ($import_role === 'teacher') {
            $this->ajax_import_teachers();
            return;
        }

        $class_id = HST_Guard::post_int('class_id');
        $term_id  = HST_Guard::post_int('term_id');
        $auto_class = HST_Guard::post_int('auto_class') === 1;

        if (!$term_id && class_exists('HST_Terms')) {
            $term_id = (int) HST_Terms::active_id();
        }
        if (!$term_id) {
            HST_Guard::fail('سال تحصیلی مقصد را انتخاب کنید.');
        }
        if (!$auto_class && !$class_id) {
            HST_Guard::fail('کلاس مقصد را انتخاب کنید.');
        }

        $rows = isset($_POST['rows']) && is_array($_POST['rows']) ? wp_unslash($_POST['rows']) : [];
        if (empty($rows)) {
            HST_Guard::fail('هیچ ردیفی برای ورود یافت نشد.');
        }

        $photo_prefix = esc_url_raw(trim((string) wp_unslash($_POST['photo_prefix'] ?? '')));
        update_option('hst-import-photo-prefix', $photo_prefix);

        $lessons_cache = [];

        $created = 0;
        $updated = 0;
        $renamed = 0;
        $skipped = 0;
        $photos  = 0;
        $errors  = [];
        $credentials = [];

        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }

            $first = sanitize_text_field($row['first_name'] ?? '');
            $last  = sanitize_text_field($row['last_name'] ?? '');
            $phone = $this->normalize_mobile(sanitize_text_field($row['phone'] ?? ''));
            $national = $this->normalize_national_code((string) ($row['national_code'] ?? ''));
            $student_code = $this->normalize_national_code((string) ($row['student_code'] ?? ''));

            if ($national === '' && $student_code !== '') {
                $national = $student_code;
            }
            if ($student_code === '' && $national !== '') {
                $student_code = $national;
            }

            $father = sanitize_text_field($row['father_name'] ?? '');
            $father_phone = $this->normalize_mobile(sanitize_text_field($row['father_phone'] ?? ''));
            $mother_phone = $this->normalize_mobile(sanitize_text_field($row['mother_phone'] ?? ''));
            $birth  = sanitize_text_field($row['birthdate'] ?? '');
            $address = sanitize_textarea_field($row['address'] ?? '');
            $gender = sanitize_text_field($row['gender'] ?? '');
            $issue_place = sanitize_text_field($row['issue_place'] ?? '');
            $grade = sanitize_text_field($row['grade'] ?? '');
            $field = sanitize_text_field($row['field'] ?? '');
            $field_code = $this->only_digits((string) ($row['field_code'] ?? ''));
            $class_title = sanitize_text_field($row['class_title'] ?? '');

            $label = trim($first . ' ' . $last) ?: ('ردیف ' . ((int) $i + 1));

            if ($first === '' || $last === '') {
                $errors[] = $label . ': نام یا نام خانوادگی خالی است.';
                $skipped++;
                continue;
            }

            if ($father === '') {
                $errors[] = $label . ': نام پدر خالی است.';
                $skipped++;
                continue;
            }

            if ($national === '') {
                $errors[] = $label . ': کد ملی / کد دانش‌آموزی نامعتبر است.';
                $skipped++;
                continue;
            }

            $username_national = $this->national_username($national);
            if ($username_national === '') {
                $errors[] = $label . ': کد ملی باید با احتساب صفرهای ابتدای آن 10 رقم باشد.';
                $skipped++;
                continue;
            }

            if ($birth === '') {
                $errors[] = $label . ': تاریخ تولد خالی است.';
                $skipped++;
                continue;
            }

            if (!HST_Guard::is_valid_iran_mobile($phone)) {
                $errors[] = $label . ': شماره موبایل دانش‌آموز نامعتبر است.';
                $skipped++;
                continue;
            }

            $row_class_id = $auto_class ? absint($row['class_id'] ?? 0) : $class_id;
            if (!$row_class_id) {
                $errors[] = $label . ': کلاس مقصد مشخص نشد.';
                $skipped++;
                continue;
            }

            if (!isset($lessons_cache[$row_class_id])) {
                $lessons_cache[$row_class_id] = $this->class_lessons($row_class_id);
            }
            $lessons = $lessons_cache[$row_class_id];

            $extra = [
                'phone'        => $phone,
                'national'     => $username_national,
                'student_code' => $student_code,
                'father'       => $father,
                'father_phone' => $father_phone,
                'mother_phone' => $mother_phone,
                'birth'        => $birth,
                'address'      => $address,
                'gender'       => $gender,
                'issue_place'  => $issue_place,
                'grade'        => $grade,
                'field'        => $field,
                'field_code'   => $field_code,
                'class_title'  => $class_title,
                'code'         => $student_code ?: $national,
                'photo_prefix' => $photo_prefix,
            ];

            // First match a student by stable identity (national/student code).
            // This prevents a parent's/teacher's mobile from hijacking a student
            // update just because that mobile is used as another user's username.
            $matched_by = '';
            $match = $this->find_existing_student($national, $student_code, $first, $last, $father);
            $existing = $match['user_id'];

            if (!empty($match['conflict'])) {
                $errors[] = $label . ': چند دانش‌آموز با همین کد ملی/کد دانش‌آموزی پیدا شد؛ برای جلوگیری از ثبت تکراری، این ردیف رد شد.';
                $skipped++;
                continue;
            }

            if ($existing) {
                $matched_by = $match['matched_by'];
            }

            if ($existing) {
                $existing = (int) $existing;
                $before = get_userdata($existing);
                $identity_result = $this->update_identity($existing, $username_national, $phone);
                if (is_wp_error($identity_result)) {
                    $errors[] = $label . ': ' . $identity_result->get_error_message();
                    $skipped++;
                    continue;
                }

                if ($before && (string) $before->user_login !== $username_national) {
                    $renamed++;
                }

                $update_result = $this->update_student($existing, $first, $last, $row_class_id, $term_id, $lessons, $extra);
                if (is_wp_error($update_result)) {
                    $errors[] = $label . ': ' . $update_result->get_error_message();
                    $skipped++;
                    continue;
                }

                $updated++;
                $credentials[] = ['name' => $label, 'username' => $username_national, 'matched_by' => $matched_by];

                if ($photo_prefix && $national) {
                    if ($this->import_avatar($existing, $photo_prefix, $student_code ?: $national)) {
                        $photos++;
                    }
                }

                continue;
            }

            $user_id = $this->create_student($first, $last, $phone, $row_class_id, $term_id, $lessons, $extra);

            if (is_wp_error($user_id)) {
                $errors[] = $label . ': ' . $user_id->get_error_message();
                $skipped++;
                continue;
            }

            $created++;
            $credentials[] = ['name' => $label, 'username' => $username_national, 'matched_by' => 'new'];
            if ($photo_prefix && $national) {
                if ($this->import_avatar((int) $user_id, $photo_prefix, $student_code ?: $national)) {
                    $photos++;
                }
            }
        }

        $msg = sprintf('ورود انجام شد. ثبت جدید: %d، به‌روزرسانی: %d، اصلاح نام کاربری: %d، رد‌شده: %d', $created, $updated, $renamed, $skipped);
        if ($photo_prefix) {
            $msg .= sprintf('، عکس دریافت‌شده: %d', $photos);
        }

        if (class_exists('HST_User_Phones')) {
            HST_User_Phones::end_batch();
        }

        wp_send_json_success([
            'message'     => $msg,
            'created'     => $created,
            'updated'     => $updated,
            'renamed'     => $renamed,
            'skipped'     => $skipped,
            'photos'      => $photos,
            'errors'      => array_slice($errors, 0, 50),
            'credentials' => $credentials,
        ]);
    }

    private function ajax_import_teachers(): void
    {
        $rows = isset($_POST['rows']) && is_array($_POST['rows']) ? wp_unslash($_POST['rows']) : [];
        if (empty($rows)) {
            HST_Guard::fail('هیچ ردیفی برای ورود معلم‌ها یافت نشد.');
        }

        $created = 0;
        $updated = 0;
        $renamed = 0;
        $skipped = 0;
        $errors = [];
        $credentials = [];

        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }

            $first = sanitize_text_field($row['first_name'] ?? '');
            $last = sanitize_text_field($row['last_name'] ?? '');
            $phone = $this->normalize_mobile(sanitize_text_field($row['phone'] ?? ''));
            $personnel_code = $this->only_digits((string) ($row['personnel_code'] ?? ''));
            $national_code = class_exists('HST_User_Phones')
                ? HST_User_Phones::normalize_national_code($row['national_code'] ?? '')
                : '';
            $birthdate = sanitize_text_field($row['birthdate'] ?? '');

            $label = trim($first . ' ' . $last) ?: ('ردیف ' . ((int) $i + 1));

            if ($first === '' || $last === '') {
                $errors[] = $label . ': نام یا نام خانوادگی خالی است.';
                $skipped++;
                continue;
            }

            if ($this->looks_like_numeric_identifier_name($first) || $this->looks_like_numeric_identifier_name($last)) {
                $errors[] = $label . ': نام یا نام خانوادگی شبیه کد ملی/کد دانش‌آموزی است. احتمالاً فایل با نوع ورود اشتباه یا ستون‌های جابه‌جا وارد شده است.';
                $skipped++;
                continue;
            }

            if (!HST_Guard::is_valid_iran_mobile($phone)) {
                $errors[] = $label . ': شماره موبایل معلم نامعتبر است.';
                $skipped++;
                continue;
            }

            if ($national_code === '') {
                $errors[] = $label . ': کد ملی باید دقیقاً 10 رقم باشد.';
                $skipped++;
                continue;
            }

            if ($personnel_code === '') {
                $errors[] = $label . ': کد پرسنلی خالی است.';
                $skipped++;
                continue;
            }

            $match = $this->find_existing_teacher($personnel_code, $national_code, $first, $last);
            $existing = $match['user_id'];
            $matched_by = $match['matched_by'];

            if (!empty($match['conflict'])) {
                $errors[] = $label . ': چند معلم یا اطلاعات متناقض با همین نام/کد پیدا شد؛ برای جلوگیری از ثبت تکراری یا آپدیت اشتباه، این ردیف رد شد.';
                $skipped++;
                continue;
            }

            $extra = [
                'phone'          => $phone,
                'personnel_code' => $personnel_code,
                'national_code'  => $national_code,
                'birthdate'      => $birthdate,
            ];

            if ($existing) {
                $existing = (int) $existing;

                $before = get_userdata($existing);
                $identity_result = $this->update_identity($existing, $national_code, $phone);
                if (is_wp_error($identity_result)) {
                    $errors[] = $label . ': ' . $identity_result->get_error_message();
                    $skipped++;
                    continue;
                }

                if ($before && (string) $before->user_login !== $national_code) {
                    $renamed++;
                }

                $this->update_teacher($existing, $first, $last, $extra);
                $updated++;
                $credentials[] = ['name' => $label, 'username' => $national_code, 'matched_by' => $matched_by];
                continue;
            }

            $user_id = $this->create_teacher($first, $last, $phone, $extra);
            if (is_wp_error($user_id)) {
                $errors[] = $label . ': ' . $user_id->get_error_message();
                $skipped++;
                continue;
            }

            $created++;
            $credentials[] = ['name' => $label, 'username' => $national_code, 'matched_by' => 'new'];
        }

        $msg = sprintf('ورود معلم‌ها انجام شد. ثبت جدید: %d، به‌روزرسانی: %d، اصلاح نام کاربری: %d، رد‌شده: %d', $created, $updated, $renamed, $skipped);

        if (class_exists('HST_User_Phones')) {
            HST_User_Phones::end_batch();
        }

        wp_send_json_success([
            'message'     => $msg,
            'created'     => $created,
            'updated'     => $updated,
            'renamed'     => $renamed,
            'skipped'     => $skipped,
            'photos'      => 0,
            'errors'      => array_slice($errors, 0, 50),
            'credentials' => $credentials,
        ]);
    }

    private function find_user_id_by_mobile(string $phone): int
    {
        return class_exists('HST_User_Phones') ? HST_User_Phones::owner($phone) : 0;
    }

    private function current_user_mobile(int $user_id): string
    {
        return class_exists('HST_User_Phones') ? HST_User_Phones::get($user_id) : '';
    }

    private function student_existing_user_id_from_import_row(array $row): int
    {
        $first = sanitize_text_field($row['first_name'] ?? '');
        $last = sanitize_text_field($row['last_name'] ?? '');
        $father = sanitize_text_field($row['father_name'] ?? '');
        $national = $this->normalize_national_code((string) ($row['national_code'] ?? ''));
        $student_code = $this->normalize_national_code((string) ($row['student_code'] ?? ''));

        if ($national === '' && $student_code !== '') {
            $national = $student_code;
        }
        if ($student_code === '' && $national !== '') {
            $student_code = $national;
        }

        if ($national === '' && $student_code === '') {
            return 0;
        }

        $match = $this->find_existing_student($national, $student_code, $first, $last, $father);
        if (!empty($match['conflict']) || empty($match['user_id'])) {
            return 0;
        }

        $user_id = (int) $match['user_id'];
        return $this->is_student_user($user_id) ? $user_id : 0;
    }

    private function is_student_user(int $user_id): bool
    {
        $user = get_userdata($user_id);
        return $user && in_array('student', (array) $user->roles, true);
    }

    private function phone_conflict_message_for_import_user(int $user_id, string $import_role, array $row): string
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return '';
        }

        $roles = (array) $user->roles;
        $name = trim((string) $user->display_name);
        $name = $name !== '' ? $name : ('کاربر #' . $user_id);

        if ($import_role === 'student') {
            if (!in_array('student', $roles, true)) {
                return sprintf('این موبایل قبلاً برای «%s» با نقش غیر دانش‌آموز ثبت شده است؛ برای جلوگیری از آپدیت اشتباه، این ردیف را با موبایل دیگری ثبت کنید.', $name);
            }

            $incoming_codes = $this->import_code_variants((string) ($row['national_code'] ?? ''), (string) ($row['student_code'] ?? ''));
            $existing_codes = $this->import_code_variants(
                (string) get_user_meta($user_id, 'hst_national_code', true),
                (string) get_user_meta($user_id, 'hst_student_code', true)
            );

            if (!empty($incoming_codes) && !empty($existing_codes) && empty(array_intersect($incoming_codes, $existing_codes))) {
                return sprintf('این موبایل قبلاً برای دانش‌آموز دیگری («%s») ثبت شده است؛ اگر این شماره متعلق به پدر/مادر است، برای این دانش‌آموز موبایل یکتای دیگری وارد کنید.', $name);
            }

            return '';
        }

        if (!in_array('teacher', $roles, true)) {
            return sprintf('این موبایل قبلاً برای «%s» با نقش غیر معلم ثبت شده است؛ برای جلوگیری از آپدیت اشتباه، این ردیف ثبت نمی‌شود.', $name);
        }

        $incoming_personnel = $this->only_digits((string) ($row['personnel_code'] ?? ''));
        $existing_personnel = $this->only_digits((string) get_user_meta($user_id, 'hst_personnel_code', true));

        if ($incoming_personnel !== '' && $existing_personnel !== '' && $incoming_personnel !== $existing_personnel) {
            return sprintf('این موبایل قبلاً برای معلم دیگری («%s») ثبت شده است؛ کد پرسنلی با رکورد موجود همخوانی ندارد.', $name);
        }

        return '';
    }

    private function import_code_variants(string ...$values): array
    {
        $codes = [];

        foreach ($values as $value) {
            $digits = $this->only_digits((string) $value);
            if ($digits === '') {
                continue;
            }

            foreach ($this->national_code_lookup_variants($digits) as $variant) {
                $variant = $this->only_digits((string) $variant);
                if ($variant !== '') {
                    $codes[] = $variant;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    private function is_teacher_user(int $user_id): bool
    {
        $user = get_userdata($user_id);
        return $user && in_array('teacher', (array) $user->roles, true);
    }

    private function find_existing_teacher(string $personnel_code, string $national_code, string $first, string $last): array
    {
        $code_ids = [];

        foreach (array_values(array_unique(array_filter([$personnel_code, $national_code]))) as $code) {
            foreach (['hst_personnel_code', 'personnel_code', 'hst_national_code', 'national_code'] as $meta_key) {
                $users = get_users([
                    'role'       => 'teacher',
                    'fields'     => 'ID',
                    'number'     => 20,
                    'meta_key'   => $meta_key,
                    'meta_value' => $code,
                ]);

                foreach ($users as $user_id) {
                    $code_ids[] = (int) $user_id;
                }
            }

            foreach ($this->find_teacher_ids_by_any_meta_value($code) as $user_id) {
                $code_ids[] = (int) $user_id;
            }
        }

        $code_ids = $this->unique_teacher_ids($code_ids);
        $name_ids = $this->find_existing_teacher_ids_by_exact_name($first, $last);

        // Main duplicate-prevention rule for teachers:
        // if a teacher with the same first_name + last_name already exists,
        // update that teacher instead of creating a new user.
        if (count($name_ids) === 1) {
            $name_id = (int) $name_ids[0];

            if (empty($code_ids)) {
                return ['user_id' => $name_id, 'matched_by' => 'full_name', 'conflict' => false];
            }

            if (in_array($name_id, $code_ids, true)) {
                return ['user_id' => $name_id, 'matched_by' => 'full_name_and_code', 'conflict' => false];
            }

            // The same name exists, but the submitted personnel/national code belongs
            // to another teacher. Do not guess; this needs manual correction.
            return ['user_id' => 0, 'matched_by' => 'teacher_code_name_conflict', 'conflict' => true];
        }

        if (count($name_ids) > 1) {
            // If duplicate names already exist, use a unique code match to disambiguate.
            if (count($code_ids) === 1 && in_array((int) $code_ids[0], $name_ids, true)) {
                return ['user_id' => (int) $code_ids[0], 'matched_by' => 'teacher_code', 'conflict' => false];
            }

            return ['user_id' => 0, 'matched_by' => 'full_name_conflict', 'conflict' => true];
        }

        if (count($code_ids) === 1) {
            return ['user_id' => (int) $code_ids[0], 'matched_by' => 'teacher_code', 'conflict' => false];
        }

        if (count($code_ids) > 1) {
            return ['user_id' => 0, 'matched_by' => 'teacher_code_conflict', 'conflict' => true];
        }

        return ['user_id' => 0, 'matched_by' => '', 'conflict' => false];
    }

    private function find_teacher_ids_by_any_meta_value(string $value): array
    {
        global $wpdb;

        if ($value === '') {
            return [];
        }

        $cap_key = $wpdb->prefix . 'capabilities';
        $sql = $wpdb->prepare(
            "SELECT DISTINCT u.ID
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} rolemeta
                ON rolemeta.user_id = u.ID
               AND rolemeta.meta_key = %s
               AND rolemeta.meta_value LIKE %s
             INNER JOIN {$wpdb->usermeta} valuemeta
                ON valuemeta.user_id = u.ID
               AND valuemeta.meta_value = %s
             LIMIT 20",
            $cap_key,
            '%"teacher"%',
            $value
        );

        return array_map('intval', $wpdb->get_col($sql) ?: []);
    }

    private function unique_teacher_ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        $out = [];
        foreach ($ids as $user_id) {
            if ($this->is_teacher_user($user_id)) {
                $out[] = $user_id;
            }
        }

        return array_values(array_unique($out));
    }

    private function find_existing_teacher_ids_by_exact_name(string $first, string $last): array
    {
        $first = trim($first);
        $last = trim($last);

        if ($first === '' || $last === '') {
            return [];
        }

        // Do not rely on exact SQL meta_value matching because Persian text may
        // contain Arabic ي/ك, half-spaces, or extra spaces. Fetch teachers and
        // compare with normalize_fa_key().
        $users = get_users([
            'role'   => 'teacher',
            'fields' => 'ID',
            'number' => -1,
        ]);

        $matches = [];
        foreach ($users as $user_id) {
            $user_id = (int) $user_id;
            $u_first = (string) get_user_meta($user_id, 'first_name', true);
            $u_last  = (string) get_user_meta($user_id, 'last_name', true);

            if ($u_first === '' || $u_last === '') {
                $user = get_userdata($user_id);
                $display = $user ? trim((string) $user->display_name) : '';
                if ($display !== '') {
                    $parts = preg_split('/\s+/u', $display, -1, PREG_SPLIT_NO_EMPTY);
                    if (is_array($parts) && count($parts) >= 2) {
                        $u_first = $u_first ?: (string) $parts[0];
                        $u_last  = $u_last ?: implode(' ', array_slice($parts, 1));
                    }
                }
            }

            if ($this->same_name($u_first, $first) && $this->same_name($u_last, $last)) {
                $matches[] = $user_id;
            }
        }

        return array_values(array_unique($matches));
    }

    private function create_teacher(string $first, string $last, string $phone, array $extra)
    {
        $national_code = class_exists('HST_User_Phones')
            ? HST_User_Phones::normalize_national_code($extra['national_code'] ?? '')
            : '';
        if ($national_code === '') {
            return new WP_Error('hst_teacher_national_code', 'کد ملی باید دقیقاً 10 رقم باشد.');
        }
        if (username_exists($national_code)) {
            return new WP_Error('hst_teacher_username_exists', 'این کد ملی قبلاً به‌عنوان نام کاربری ثبت شده است.');
        }
        if (HST_User_Phones::owner($phone)) {
            return new WP_Error('hst_teacher_phone_exists', 'این شماره موبایل قبلاً برای کاربر دیگری ثبت شده است.');
        }

        $user_id = wp_insert_user([
            'user_login'   => $national_code,
            'user_pass'    => wp_generate_password(12, true, true),
            'role'         => 'teacher',
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim($first . ' ' . $last),
        ]);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $phone_result = HST_User_Phones::set((int) $user_id, $phone);
        if (is_wp_error($phone_result)) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user_id);
            return $phone_result;
        }

        $this->save_teacher_meta((int) $user_id, $phone, $extra);

        return $user_id;
    }

    private function update_teacher(int $user_id, string $first, string $last, array $extra): void
    {
        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim($first . ' ' . $last),
        ]);

        $phone = $extra['phone'] ?? $this->current_user_mobile($user_id);
        $this->save_teacher_meta($user_id, $phone, $extra);
    }

    private function save_teacher_meta(int $user_id, string $phone, array $extra): void
    {
        if (class_exists('HST_User_Phones')) {
            HST_User_Phones::set($user_id, $phone);
        }
        update_user_meta($user_id, 'hst_personnel_code', $extra['personnel_code'] ?? '');

        $national_code = trim((string) ($extra['national_code'] ?? ''));
        $birthdate = trim((string) ($extra['birthdate'] ?? ''));

        if ($national_code !== '') {
            update_user_meta($user_id, 'hst_national_code', $national_code);
        }

        if ($birthdate !== '') {
            update_user_meta($user_id, 'hst_birthdate', $birthdate);
        }

    }

    private function looks_like_numeric_identifier_name(string $value): bool
    {
        $plain = preg_replace('/[\s\-_.]+/u', '', trim((string) $value));
        $digits = preg_replace('/[^0-9]/', '', $plain);

        return $digits !== '' && strlen($digits) >= 6 && $plain === $digits;
    }

    private function to_english_digits(string $value): string
    {
        return strtr((string) $value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function only_digits(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $this->to_english_digits($value));
    }

    private function normalize_mobile(string $phone): string
    {
        $phone = $this->only_digits($phone);
        if (strlen($phone) === 10 && strpos($phone, '9') === 0) {
            $phone = '0' . $phone;
        }
        if (strlen($phone) === 12 && strpos($phone, '98') === 0) {
            $phone = '0' . substr($phone, 2);
        }
        return $phone;
    }

    private function normalize_national_code(string $value): string
    {
        $digits = $this->only_digits($value);

        // In Sida, the student identifier may lose one or two leading zeros.
        // Accept 8/9-digit student identifiers as stable Sida student codes and
        // keep them exactly as received; do not pad before storing.
        if (strlen($digits) === 8) {
            return preg_match('/^([0-9])\\1{7}$/', $digits) ? '' : $digits;
        }

        if (strlen($digits) === 9) {
            return preg_match('/^([0-9])\\1{8}$/', $digits) ? '' : $digits;
        }

        if (strlen($digits) === 10) {
            return $this->is_valid_iran_national_code($digits) ? $digits : '';
        }

        return '';
    }

    /**
     * Sida may omit leading zeroes from a national code. Restore them only for
     * the WordPress username, which must always be exactly ten digits.
     */
    private function national_username(string $value): string
    {
        $digits = $this->only_digits($value);
        if (strlen($digits) < 8 || strlen($digits) > 10) {
            return '';
        }

        $digits = str_pad($digits, 10, '0', STR_PAD_LEFT);
        return class_exists('HST_User_Phones')
            ? HST_User_Phones::normalize_national_code($digits)
            : '';
    }

    private function national_code_lookup_variants(string $code): array
    {
        $code = $this->only_digits($code);
        if ($code === '') {
            return [];
        }

        $variants = [$code];

        // Sida may omit one or two leading zeros. Search all plausible stored
        // variants so 8/9/10-digit forms still point to the same student.
        if (strlen($code) === 8) {
            $variants[] = '0' . $code;
            $variants[] = '00' . $code;
        }

        if (strlen($code) === 9) {
            $variants[] = '0' . $code;

            if (strpos($code, '0') === 0) {
                $variants[] = substr($code, 1);
            }
        }

        if (strlen($code) === 10 && strpos($code, '0') === 0) {
            $variants[] = substr($code, 1);

            if (strpos($code, '00') === 0) {
                $variants[] = substr($code, 2);
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function is_valid_iran_national_code(string $code): bool
    {
        if (!preg_match('/^[0-9]{10}$/', $code)) {
            return false;
        }

        if (preg_match('/^([0-9])\\1{9}$/', $code)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $code[$i] * (10 - $i);
        }

        $remainder = $sum % 11;
        $check = (int) $code[9];

        return $remainder < 2 ? $check === $remainder : $check === (11 - $remainder);
    }

    /**
     * Find a previously imported student whose mobile/username may be outdated.
     *
     * Hard rule:
     * The national code / student code is unique. If any existing student can be
     * found by this code, we update that user instead of creating a new user.
     *
     * Search priority:
     * 1. user_login equal to national/student code (old imports may have used it).
     * 2. known meta keys: hst_national_code, hst_student_code, national_code, student_code.
     * 3. fallback by exact first name + last name + father name, only when unique.
     */
    private function find_existing_student(string $national, string $student_code, string $first, string $last, string $father): array
    {
        $codes = array_values(array_unique(array_merge(
            $this->national_code_lookup_variants($national),
            $this->national_code_lookup_variants($student_code)
        )));
        $ids = [];

        foreach ($codes as $code) {
            // Old/broken imports may have used the national code as username.
            $by_login = username_exists($code);
            if ($by_login) {
                $ids[] = (int) $by_login;
            }

            // Known meta keys used by this plugin and by earlier import versions.
            foreach (['hst_national_code', 'hst_student_code', 'national_code', 'student_code', 'code'] as $meta_key) {
                $users = get_users([
                    'role'       => 'student',
                    'fields'     => 'ID',
                    'number'     => 20,
                    'meta_key'   => $meta_key,
                    'meta_value' => $code,
                ]);

                foreach ($users as $user_id) {
                    $ids[] = (int) $user_id;
                }
            }

        }

        $ids = $this->unique_student_ids($ids);

        if (count($ids) === 1) {
            return ['user_id' => $ids[0], 'matched_by' => 'national_code_anywhere', 'conflict' => false];
        }

        if (count($ids) > 1) {
            return ['user_id' => 0, 'matched_by' => 'national_code_conflict', 'conflict' => true];
        }

        $name_match = $this->find_existing_student_by_exact_name($first, $last, $father);
        if ($name_match) {
            return ['user_id' => $name_match, 'matched_by' => 'full_name_father', 'conflict' => false];
        }

        return ['user_id' => 0, 'matched_by' => '', 'conflict' => false];
    }

    private function unique_student_ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        $out = [];
        foreach ($ids as $user_id) {
            if (user_can($user_id, 'student') || user_can($user_id, 'hst_study')) {
                $out[] = $user_id;
            }
        }

        return array_values(array_unique($out));
    }

    private function find_existing_student_by_exact_name(string $first, string $last, string $father): int
    {
        $first = trim($first);
        $last = trim($last);
        $father = trim($father);

        if ($first === '' || $last === '' || $father === '') {
            return 0;
        }

        $users = get_users([
            'role'       => 'student',
            'fields'     => 'ID',
            'number'     => 50,
            'meta_key'   => 'hst_father_name',
            'meta_value' => $father,
        ]);

        $matches = [];
        foreach ($users as $user_id) {
            $user_id = (int) $user_id;
            $u_first = get_user_meta($user_id, 'first_name', true);
            $u_last  = get_user_meta($user_id, 'last_name', true);

            if ($this->same_name($u_first, $first) && $this->same_name($u_last, $last)) {
                $matches[] = $user_id;
            }
        }

        return count($matches) === 1 ? (int) $matches[0] : 0;
    }

    private function same_name(string $a, string $b): bool
    {
        return $this->normalize_fa_key($a) === $this->normalize_fa_key($b);
    }

    private function normalize_fa_key(string $value): string
    {
        $value = strtr($value, [
            'ي' => 'ی',
            'ك' => 'ک',
            "\xE2\x80\x8C" => '',
            '‌' => '',
        ]);
        $value = preg_replace('/\s+/u', '', $value);
        return trim((string) $value);
    }

    /**
     * Synchronize both parts of a TeacherShow identity in one guarded path.
     *
     * @return true|WP_Error
     */
    private function update_identity(int $user_id, string $national_code, string $phone)
    {
        if (!class_exists('HST_User_Phones')) {
            return new WP_Error('hst_identity_service_missing', 'سرویس هویت کاربران در دسترس نیست.');
        }

        $phone_owner = HST_User_Phones::owner($phone);
        if ($phone_owner && $phone_owner !== $user_id) {
            return new WP_Error('hst_identity_phone_conflict', 'این شماره موبایل قبلاً برای کاربر دیگری ثبت شده است.');
        }

        $identity_result = HST_User_Phones::sync_username($user_id, $national_code);
        if (is_wp_error($identity_result)) {
            return $identity_result;
        }

        return HST_User_Phones::set($user_id, $phone);
    }

    private function class_lessons(int $class_id): array
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare("SELECT id, unit FROM {$wpdb->prefix}hst_lessons WHERE class_id = %d", $class_id)
        ) ?: [];
    }

    private function create_student(string $first, string $last, string $phone, int $class_id, int $term_id, array $lessons, array $extra)
    {
        global $wpdb;

        $national_code = class_exists('HST_User_Phones')
            ? HST_User_Phones::normalize_national_code($extra['national'] ?? '')
            : '';
        if ($national_code === '') {
            return new WP_Error('hst_student_national_code', 'کد ملی باید دقیقاً 10 رقم باشد.');
        }
        if (username_exists($national_code)) {
            return new WP_Error('hst_student_username_exists', 'این کد ملی قبلاً به‌عنوان نام کاربری ثبت شده است.');
        }
        if (HST_User_Phones::owner($phone)) {
            return new WP_Error('hst_student_phone_exists', 'این شماره موبایل قبلاً برای کاربر دیگری ثبت شده است.');
        }

        $user_id = wp_insert_user([
            'user_login'   => $national_code,
            'user_pass'    => wp_generate_password(12, true, true),
            'role'         => 'student',
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim($first . ' ' . $last),
        ]);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $phone_result = HST_User_Phones::set((int) $user_id, $phone);
        if (is_wp_error($phone_result)) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user_id);
            return $phone_result;
        }

        $this->save_student_meta((int) $user_id, $phone, $extra);

        $enrollment = $this->replace_student_enrollment((int) $user_id, $class_id, $term_id, $lessons);
        if (is_wp_error($enrollment)) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user_id);
            return $enrollment;
        }

        return $user_id;
    }

    private function save_student_meta(int $user_id, string $phone, array $extra): void
    {
        if (class_exists('HST_User_Phones')) {
            HST_User_Phones::set($user_id, $phone);
        }

        $meta_map = [
            'hst_national_code' => $extra['national'] ?? '',
            'hst_student_code'  => $extra['student_code'] ?? ($extra['national'] ?? ''),
            'hst_father_name'   => $extra['father'] ?? '',
            'hst_parent_phone'  => $extra['father_phone'] ?? '',
            'hst_mother_phone'  => $extra['mother_phone'] ?? '',
            'hst_birthdate'     => $extra['birth'] ?? '',
            'hst_address'       => $extra['address'] ?? '',
            'hst_gender'        => $extra['gender'] ?? '',
            'hst_issue_place'   => $extra['issue_place'] ?? '',
            'hst_grade'         => $extra['grade'] ?? '',
            'hst_field'         => $extra['field'] ?? '',
            'hst_field_code'    => $extra['field_code'] ?? '',
            'hst_class_title'   => $extra['class_title'] ?? '',
        ];

        foreach ($meta_map as $key => $value) {
            if ($value !== '') {
                update_user_meta($user_id, $key, $value);
            }
        }

    }

    private function update_student(int $user_id, string $first, string $last, int $class_id, int $term_id, array $lessons, array $extra)
    {
        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim($first . ' ' . $last),
        ]);

        $phone = $extra['phone'] ?? $this->current_user_mobile($user_id);
        $this->save_student_meta($user_id, $phone, $extra);

        return $this->replace_student_enrollment($user_id, $class_id, $term_id, $lessons);
    }

    private function replace_student_enrollment(int $user_id, int $class_id, int $term_id, array $lessons)
    {
        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            // A student can belong to only one class per term. Replace the
            // previous class and previous lessons for this term instead of
            // appending another class/lesson set.
            $wpdb->delete(
                $wpdb->prefix . 'hst_users_classes',
                ['user_id' => $user_id, 'term_id' => $term_id, 'role' => 'student'],
                ['%d', '%d', '%s']
            );

            $wpdb->delete(
                $wpdb->prefix . 'hst_users_lessons',
                ['user_id' => $user_id, 'term_id' => $term_id, 'role' => 'student'],
                ['%d', '%d', '%s']
            );

            $class_inserted = $wpdb->insert(
                $wpdb->prefix . 'hst_users_classes',
                ['user_id' => $user_id, 'class_id' => $class_id, 'term_id' => $term_id, 'role' => 'student'],
                ['%d', '%d', '%d', '%s']
            );

            if ($class_inserted === false) {
                throw new \RuntimeException('class insert failed');
            }

            foreach ($lessons as $lesson) {
                $lesson_inserted = $wpdb->insert(
                    $wpdb->prefix . 'hst_users_lessons',
                    [
                        'user_id'     => $user_id,
                        'class_id'    => $class_id,
                        'lesson_id'   => (int) $lesson->id,
                        'term_id'     => $term_id,
                        'lesson_unit' => absint($lesson->unit),
                        'role'        => 'student',
                    ],
                    ['%d', '%d', '%d', '%d', '%d', '%s']
                );

                if ($lesson_inserted === false) {
                    throw new \RuntimeException('lesson insert failed');
                }
            }

            $wpdb->query('COMMIT');
            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('hst_import_enroll_failed', 'ثبت کلاس/دروس دانش‌آموز ناموفق بود.');
        }
    }

    private function import_avatar(int $user_id, string $prefix, string $code): bool
    {
        // Do not download/store the same image again on every update,
        // but only skip when the attachment file really exists.
        if ($this->has_valid_avatar($user_id)) {
            return false;
        }

        $code = $this->only_digits($code);
        if ($code === '') {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $body = '';
        $download_code = $code;

        foreach ($this->national_code_lookup_variants($code) as $candidate_code) {
            $candidate_code = $this->only_digits((string) $candidate_code);
            if ($candidate_code === '') {
                continue;
            }

            $candidate_url = esc_url_raw(rtrim($prefix, '/') . '/' . rawurlencode($candidate_code) . '.jpg');
            if (!$candidate_url) {
                continue;
            }

            $response = $this->remote_get_avatar($candidate_url);

            if (is_wp_error($response)) {
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status !== 200) {
                continue;
            }

            $candidate_body = wp_remote_retrieve_body($response);
            if (strlen($candidate_body) < 200) {
                continue;
            }

            $body = $candidate_body;
            $download_code = $candidate_code;
            break;
        }

        if ($body === '') {
            return false;
        }

        $tmp = wp_tempnam($download_code . '.jpg');
        if (!$tmp) {
            return false;
        }

        if (file_put_contents($tmp, $body) === false) {
            @unlink($tmp);
            return false;
        }

        $file_array = [
            'name'     => $download_code . '.jpg',
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file_array, 0, sprintf('تصویر دانش‌آموز %d', $user_id));

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return false;
        }

        update_user_meta($user_id, 'hst_profile_avatar_id', (int) $attachment_id);
        update_user_meta($user_id, 'hst_avatar_status', 'approved');
        update_user_meta($user_id, 'hst_avatar_source_code', $download_code);
        delete_user_meta($user_id, 'hst_avatar_pending_id');

        return true;
    }

    private function remote_get_avatar(string $url)
    {
        $args = [
            // Avatar import runs in one-row AJAX batches. A short timeout keeps
            // an unreachable Sida/private host from holding PHP until the web
            // server returns 503.
            'timeout'     => 6,
            'redirection' => 5,
            'sslverify'   => false,
            'headers'     => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari TeacherShow/1.0',
                'Accept'     => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Referer'    => home_url('/'),
            ],
        ];

        return wp_safe_remote_get($url, $args);
    }

}
