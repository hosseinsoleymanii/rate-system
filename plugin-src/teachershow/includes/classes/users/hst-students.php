<?php

defined('ABSPATH') || exit;

/**
 * Student management (CRUD + student-scoped data) extracted from the former
 * monolithic HST_Users.
 */
class HST_Students
{
    use HST_User_Ajax_Authorization;

    public function __construct()
    {
        add_action('wp_ajax_hst_get_lessons_by_classes', [$this, 'hst_get_lessons_by_classes']);
        add_action('wp_ajax_hst_add_student', [$this, 'hst_add_student']);
        add_action('wp_ajax_hst_delete_student', [$this, 'hst_delete_student']);
        add_action('wp_ajax_hst_update_student', [$this, 'hst_update_student']);
        add_action('wp_ajax_hst_get_student_lessons', [$this, 'hst_get_student_lessons']);
        add_action('wp_ajax_hst_reset_student_lessons', [$this, 'hst_reset_student_lessons']);
        add_action('wp_ajax_hst_get_student_term_data', [$this, 'hst_get_student_term_data']);
        add_action('wp_ajax_hst_get_student_details', [$this, 'hst_get_student_details']);
    }


    /**
     * Lessons available for student enrollment in one or more selected classes.
     * Teacher allocation and unit capacity are intentionally not handled here.
     */
    public function hst_get_lessons_by_classes()
    {
        $this->authorize_ajax();

        $class_ids = HST_Guard::post_id_list('class_ids');
        if (empty($class_ids)) {
            HST_Guard::fail('کلاس نامعتبر است.');
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($class_ids), '%d'));
        $lessons = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id, l.lesson_name, l.class_id, c.class_name, l.unit
             FROM {$wpdb->prefix}hst_lessons l
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = l.class_id
             WHERE l.class_id IN ($placeholders)
             ORDER BY c.class_name ASC, l.lesson_name ASC",
            ...$class_ids
        )) ?: [];

        $lessons = HST_Classes::sort_rows($lessons, 'class_name', ['lesson_name']);
        wp_send_json_success($lessons);
    }

    /**
     * Returns a student's full profile for the view / edit modals (active term).
     */
    public function hst_get_student_details()
    {
        $this->authorize_ajax();

        $student_id = absint($_POST['student_id'] ?? 0);
        $user = $student_id ? get_userdata($student_id) : null;
        if (!$user || !in_array('student', (array) $user->roles, true)) {
            HST_Guard::fail('دانش‌آموز یافت نشد.');
        }

        $phone = class_exists('HST_User_Phones')
            ? HST_User_Phones::get($student_id)
            : (string) get_user_meta($student_id, 'phone', true);

        $avatar_id = absint(get_user_meta($student_id, 'hst_profile_avatar_id', true));
        if (!$avatar_id && class_exists('HST_Avatar_Approval')) {
            $avatar_id = (int) HST_Avatar_Approval::display_avatar_id($student_id, $student_id);
        }
        $avatar_url = $avatar_id ? wp_get_attachment_image_url($avatar_id, 'thumbnail') : '';

        $first = (string) get_user_meta($student_id, 'first_name', true);
        $last = (string) get_user_meta($student_id, 'last_name', true);

        // Father's contact is the primary one; fall back to the legacy single
        // parent-phone meta for students created before the field was split.
        $father_phone = (string) get_user_meta($student_id, 'hst_father_phone', true);
        if ($father_phone === '') {
            $father_phone = (string) get_user_meta($student_id, 'hst_parent_phone', true);
        }
        $mother_phone = (string) get_user_meta($student_id, 'hst_mother_phone', true);

        global $wpdb;
        $active_term_id = (int) HST_Terms::active_id();

        $classes = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT c.class_name FROM {$wpdb->prefix}hst_users_classes uc
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = uc.class_id
             WHERE uc.user_id = %d AND uc.role = 'student' AND uc.term_id = %d",
            $student_id,
            $active_term_id
        ));
        $classes = HST_Classes::sort_names((array) $classes);
        $lessons = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT l.lesson_name FROM {$wpdb->prefix}hst_users_lessons ul
             INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = ul.lesson_id
             WHERE ul.user_id = %d AND ul.role = 'student' AND ul.term_id = %d",
            $student_id,
            $active_term_id
        ));

        $class_ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT class_id FROM {$wpdb->prefix}hst_users_classes
             WHERE user_id = %d AND role = 'student' AND term_id = %d",
            $student_id,
            $active_term_id
        )));
        $lesson_ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT lesson_id FROM {$wpdb->prefix}hst_users_lessons
             WHERE user_id = %d AND role = 'student' AND term_id = %d",
            $student_id,
            $active_term_id
        )));

        wp_send_json_success([
            'id'            => $student_id,
            'display_name'  => $user->display_name,
            'first_name'    => $first,
            'last_name'     => $last,
            'phone'         => $phone,
            'national_code' => (string) get_user_meta($student_id, 'hst_national_code', true),
            'father_name'   => (string) get_user_meta($student_id, 'hst_father_name', true),
            'father_phone'  => $father_phone,
            'mother_phone'  => $mother_phone,
            'birthdate'     => (string) get_user_meta($student_id, 'hst_birthdate', true),
            'avatar_url'    => $avatar_url,
            'classes'       => array_values(array_filter((array) $classes)),
            'lessons'       => array_values(array_filter((array) $lessons)),
            'class_ids'     => $class_ids,
            'lesson_ids'    => $lesson_ids,
        ]);
    }

    /**
     * Students visible to the current viewer with their classes and lessons.
     * A teacher (not also a manager) sees only students who share their class
     * and lesson in the active term; managers see only students enrolled in the
     * active term. Read API used by the students list page instead of querying
     * from the renderer.
     *
     * @return array
     */
    public static function list_for_viewer()
    {
        global $wpdb;
        $active_term_id = HST_Terms::active_id();

        $is_teacher_only = (current_user_can('hst_teach') || current_user_can('teacher'))
            && !current_user_can('manage_options')
            && !current_user_can('hst_manage_school');

        if ($is_teacher_only) {
            $teacher_id = get_current_user_id();
            if (!$teacher_id || !$active_term_id) {
                return [];
            }

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        u.ID,
                        u.display_name,
                        GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name SEPARATOR ', ') AS classes,
                        GROUP_CONCAT(DISTINCT l.lesson_name ORDER BY l.lesson_name SEPARATOR ', ') AS lessons
                    FROM {$wpdb->users} u
                    INNER JOIN {$wpdb->prefix}hst_users_classes sc
                        ON sc.user_id = u.ID AND sc.role = 'student' AND sc.term_id = %d
                    INNER JOIN {$wpdb->prefix}hst_users_lessons sl
                        ON sl.user_id = u.ID AND sl.role = 'student' AND sl.term_id = sc.term_id
                    INNER JOIN {$wpdb->prefix}hst_users_classes tc
                        ON tc.user_id = %d AND tc.role = 'teacher' AND tc.term_id = sc.term_id AND tc.class_id = sc.class_id
                    INNER JOIN {$wpdb->prefix}hst_users_lessons tl
                        ON tl.user_id = %d AND tl.role = 'teacher' AND tl.term_id = sc.term_id AND tl.lesson_id = sl.lesson_id
                    LEFT JOIN {$wpdb->prefix}hst_classes c ON c.id = sc.class_id
                    LEFT JOIN {$wpdb->prefix}hst_lessons l ON l.id = sl.lesson_id
                    GROUP BY u.ID, u.display_name",
                    $active_term_id,
                    $teacher_id,
                    $teacher_id
                )
            ) ?: [];

            return self::sort_student_rows($rows);
        }

        $active_term_id = HST_Terms::active_id();
        if (!$active_term_id) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.ID,
                        u.display_name,
                        GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name SEPARATOR ', ') as classes,
                        GROUP_CONCAT(DISTINCT l.lesson_name ORDER BY l.lesson_name SEPARATOR ', ') as lessons
                FROM {$wpdb->users} u
                INNER JOIN {$wpdb->prefix}hst_users_classes uc
                    ON u.ID = uc.user_id AND uc.role = 'student' AND uc.term_id = %d
                LEFT JOIN {$wpdb->prefix}hst_classes c ON uc.class_id = c.id
                LEFT JOIN {$wpdb->prefix}hst_users_lessons ul
                    ON u.ID = ul.user_id AND ul.role = 'student' AND ul.term_id = uc.term_id
                LEFT JOIN {$wpdb->prefix}hst_lessons l ON ul.lesson_id = l.id
                GROUP BY u.ID, u.display_name",
                $active_term_id
            )
        ) ?: [];

        return self::sort_student_rows($rows);
    }

    public static function sort_student_rows(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $classes = self::student_row_value($row, ['classes', 'class_names']);
            if ($classes === '') {
                continue;
            }

            $class_names = preg_split('/\s*(?:،|,)\s*/u', $classes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $sorted_classes = implode(', ', HST_Classes::sort_names($class_names));
            if (is_array($row)) {
                $rows[$index]['classes'] = $sorted_classes;
            } elseif (is_object($row)) {
                $rows[$index]->classes = $sorted_classes;
            }
        }

        if (count($rows) < 2) {
            return $rows;
        }

        // Prime first_name/last_name meta in one query to keep sorting fast.
        $ids = [];
        foreach ($rows as $row) {
            $id = self::student_row_user_id($row);
            if ($id) {
                $ids[] = $id;
            }
        }
        if (!empty($ids)) {
            update_meta_cache('user', array_values(array_unique($ids)));
        }

        usort($rows, static function ($a, $b): int {
            $a_id = self::student_row_user_id($a);
            $b_id = self::student_row_user_id($b);

            $a_last = self::student_last_name_for_sort($a, $a_id);
            $b_last = self::student_last_name_for_sort($b, $b_id);

            // Main rule: sort by the real WordPress last_name usermeta, not display_name.
            $cmp = self::persian_text_compare($a_last, $b_last);
            if ($cmp !== 0) {
                return $cmp;
            }

            $a_first = self::student_first_name_for_sort($a, $a_id);
            $b_first = self::student_first_name_for_sort($b, $b_id);

            $cmp = self::persian_text_compare($a_first, $b_first);
            if ($cmp !== 0) {
                return $cmp;
            }

            $a_display = self::student_row_value($a, ['display_name', 'student_name', 'name']);
            $b_display = self::student_row_value($b, ['display_name', 'student_name', 'name']);

            $cmp = self::persian_text_compare($a_display, $b_display);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $a_id <=> $b_id;
        });

        return $rows;
    }

    private static function student_row_user_id($row): int
    {
        foreach (['ID', 'student_id', 'user_id'] as $key) {
            if (is_array($row) && isset($row[$key])) {
                return absint($row[$key]);
            }

            if (is_object($row) && isset($row->{$key})) {
                return absint($row->{$key});
            }
        }

        return 0;
    }

    private static function student_row_value($row, array $keys): string
    {
        foreach ($keys as $key) {
            if (is_array($row) && isset($row[$key]) && (string) $row[$key] !== '') {
                return (string) $row[$key];
            }

            if (is_object($row) && isset($row->{$key}) && (string) $row->{$key} !== '') {
                return (string) $row->{$key};
            }
        }

        return '';
    }

    private static function student_last_name_for_sort($row, int $user_id): string
    {
        $last = $user_id ? (string) get_user_meta($user_id, 'last_name', true) : '';

        if ($last === '') {
            $last = self::student_row_value($row, ['last_name', 'student_last_name']);
        }

        return $last;
    }

    private static function student_first_name_for_sort($row, int $user_id): string
    {
        $first = $user_id ? (string) get_user_meta($user_id, 'first_name', true) : '';

        if ($first === '') {
            $first = self::student_row_value($row, ['first_name', 'student_first_name']);
        }

        return $first;
    }

    private static function persian_text_compare(string $a, string $b): int
    {
        $a_key = self::persian_sort_key($a);
        $b_key = self::persian_sort_key($b);

        if ($a_key === $b_key) {
            return 0;
        }

        return $a_key < $b_key ? -1 : 1;
    }

    private static function persian_sort_key(string $value): string
    {
        $value = self::normalize_student_sort_text($value);

        if ($value === '') {
            return str_repeat('99-', 20);
        }

        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            return $value;
        }

        $order = self::persian_alphabet_order();
        $parts = [];

        foreach ($chars as $char) {
            if ($char === ' ') {
                $parts[] = '00';
                continue;
            }

            if (isset($order[$char])) {
                $parts[] = str_pad((string) $order[$char], 2, '0', STR_PAD_LEFT);
                continue;
            }

            // Keep unknown characters stable but after Persian letters.
            $parts[] = '80' . bin2hex($char);
        }

        return implode('-', $parts);
    }

    private static function persian_alphabet_order(): array
    {
        static $order = null;
        if ($order !== null) {
            return $order;
        }

        // Real Persian alphabet order. This fixes cases like "کلانتری":
        // Unicode order puts ک after م, but Persian alphabetical order puts ک before گ، ل، م.
        $letters = [
            'آ', 'ا', 'أ', 'إ', 'ئ',
            'ب', 'پ',
            'ت', 'ث',
            'ج', 'چ',
            'ح', 'خ',
            'د', 'ذ',
            'ر', 'ز', 'ژ',
            'س', 'ش',
            'ص', 'ض',
            'ط', 'ظ',
            'ع', 'غ',
            'ف', 'ق',
            'ک', 'گ',
            'ل', 'م', 'ن',
            'و',
            'ه',
            'ی',
        ];

        $order = [];
        foreach ($letters as $index => $letter) {
            $order[$letter] = $index + 1;
        }

        return $order;
    }

    private static function normalize_student_sort_text(string $value): string
    {
        $value = strtr($value, [
            'ي' => 'ی',
            'ى' => 'ی',
            'ی' => 'ی',
            'ك' => 'ک',
            'ک' => 'ک',
            'ۀ' => 'ه',
            'ة' => 'ه',
            'ؤ' => 'و',
            '‌' => ' ',
        ]);

        $value = preg_replace('/[ًٌٍَُِّْ]/u', '', $value);
        $value = preg_replace('/\s+/u', ' ', trim((string) $value));

        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }



public function hst_add_student()
    {
        $this->authorize_ajax();

        $first_name = HST_Guard::post_text('student_name');
        $last_name = HST_Guard::post_text('student_last_name');
        $phone = HST_Guard::normalize_mobile(HST_Guard::post_text('student_phone'));
        $class_ids = HST_Guard::post_id_list('class_ids');
        $lesson_ids = HST_Guard::post_id_list('lesson_ids');
        // Term is always the active term; it is no longer chosen in the form.
        $term_id = HST_Guard::post_int('term_id');
        if (!$term_id && class_exists('HST_Terms')) {
            $term_id = (int) HST_Terms::active_id();
        }

        $father_phone = HST_Guard::normalize_mobile(HST_Guard::post_text('student_father_phone'));
        $mother_phone = HST_Guard::normalize_mobile(HST_Guard::post_text('student_mother_phone'));
        $national_code = HST_Guard::post_text('student_national_code');
        $father_name = HST_Guard::post_text('student_father_name');
        $birthdate = HST_Guard::post_text('student_birthdate');

        if (!$first_name || !$last_name || !$term_id) {
            HST_Guard::fail('نام و نام خانوادگی الزامی است و باید یک سال تحصیلی فعال وجود داشته باشد.');
        }

        if (!HST_Guard::is_valid_iran_mobile($phone)) {
            HST_Guard::fail('شماره موبایل باید با 09 شروع شود و 11 رقم باشد.');
        }

        if ($father_phone === '') {
            HST_Guard::fail('شماره تماس پدر الزامی است.');
        }

        if (!class_exists('HST_User_Phones') || HST_User_Phones::normalize_national_code($national_code) === '') {
            HST_Guard::fail('کد ملی باید دقیقاً 10 رقم باشد.');
        }

        $national_code = HST_User_Phones::normalize_national_code($national_code);

        if ($father_name === '' || $birthdate === '') {
            HST_Guard::fail('کد ملی، نام پدر و تاریخ تولد الزامی است.');
        }

        if (count($class_ids) !== 1) {
            HST_Guard::fail('دانش‌آموز باید دقیقاً در یک کلاس ثبت شود.');
        }

        if (empty($lesson_ids)) {
            HST_Guard::fail('حداقل یک درس انتخاب کنید.');
        }

        if (username_exists($national_code)) {
            HST_Guard::fail('این کد ملی قبلاً به‌عنوان نام کاربری ثبت شده است.');
        }

        if (HST_User_Phones::owner($phone)) {
            HST_Guard::fail('این شماره موبایل قبلاً برای کاربر دیگری ثبت شده است.');
        }

        global $wpdb;

        $password = wp_generate_password(12, true, true);
        $user_id = wp_insert_user([
            'user_login'   => $national_code,
            'user_pass'    => $password,
            'role'         => 'student',
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim($first_name . ' ' . $last_name),
        ]);

        if (is_wp_error($user_id)) {
            HST_Guard::fail('خطا در ساخت دانش‌آموز: ' . $user_id->get_error_message());
        }

        $phone_result = HST_User_Phones::set((int) $user_id, $phone);
        if (is_wp_error($phone_result)) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user_id);
            HST_Guard::fail($phone_result->get_error_message());
        }

        update_user_meta($user_id, 'hst_father_phone', $father_phone);
        update_user_meta($user_id, 'hst_mother_phone', $mother_phone);
        // Keep the legacy single parent-phone meta in sync (father is primary)
        // so the SMS / discipline / tuition modules keep working unchanged.
        update_user_meta($user_id, 'hst_parent_phone', $father_phone);
        update_user_meta($user_id, 'hst_birthdate', $birthdate);
        update_user_meta($user_id, 'hst_national_code', $national_code);
        update_user_meta($user_id, 'hst_father_name', $father_name);

        $class_id = absint($class_ids[0]);
        $wpdb->query('START TRANSACTION');

        try {
            $wpdb->insert(
                $wpdb->prefix . 'hst_users_classes',
                [
                    'user_id'  => $user_id,
                    'class_id' => $class_id,
                    'term_id'  => $term_id,
                    'role'     => 'student',
                ],
                ['%d', '%d', '%d', '%s']
            );

            foreach ($lesson_ids as $lesson_id) {
                $lesson = HST_Lessons::scope($lesson_id, $class_id);

                if (!$lesson) {
                    continue;
                }

                $wpdb->insert(
                    $wpdb->prefix . 'hst_users_lessons',
                    [
                        'user_id'     => $user_id,
                        'class_id'    => $class_id,
                        'lesson_id'   => $lesson_id,
                        'term_id'     => $term_id,
                        'lesson_unit' => absint($lesson->unit),
                        'role'        => 'student',
                    ],
                    ['%d', '%d', '%d', '%d', '%d', '%s']
                );
            }

            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user_id);
            HST_Guard::fail('ثبت دانش‌آموز کامل نشد. دوباره تلاش کنید.');
        }

        wp_send_json_success([
            'message'  => 'دانش‌آموز با موفقیت ثبت شد.',
            'username' => $national_code,
            'password' => $password,
        ]);
    }


    public function hst_delete_student()
    {
        $this->authorize_ajax();

        $user_id = HST_Guard::post_int('id');

        if (!$user_id) {
            HST_Guard::fail('شناسه نامعتبر است.');
        }

        if ((int) get_current_user_id() === $user_id) {
            HST_Guard::fail('امکان حذف حساب کاربری فعلی وجود ندارد.');
        }

        $user = get_userdata($user_id);
        if (!$user || !in_array('student', (array) $user->roles, true)) {
            HST_Guard::fail('کاربر انتخاب‌شده دانش‌آموز نیست یا وجود ندارد.');
        }

        global $wpdb;

        $wpdb->delete($wpdb->prefix . 'hst_exam_attempts', ['student_id' => $user_id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'hst_users_classes', ['user_id' => $user_id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'hst_users_lessons', ['user_id' => $user_id], ['%d']);

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user_id);

        wp_send_json_success(['message' => 'دانش‌آموز حذف شد.']);
    }


    public function hst_get_student_lessons()
    {
        $this->authorize_ajax();

        $user_id = intval($_POST['id'] ?? 0);
        $term_id = intval($_POST['term_id'] ?? 0);

        if (!$user_id || !$term_id) {

            wp_send_json_error([
                'message' => 'شناسه نامعتبر است'
            ]);
        }

        global $wpdb;

        $selected_lessons = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT lesson_id
                FROM {$wpdb->prefix}hst_users_lessons
                WHERE user_id = %d
                AND term_id = %d
                AND role = 'student'",
                $user_id,
                $term_id
            )
        );

        wp_send_json_success([
            'selected' => $selected_lessons
        ]);
    }

    public function hst_reset_student_lessons()
    {
        $this->authorize_ajax();

        $user_id = intval($_POST['id'] ?? 0);
        $term_id = intval($_POST['term_id'] ?? 0);

        if (!$user_id || !$term_id) {

            wp_send_json_error([
                'message' => 'اطلاعات نامعتبر است'
            ]);
        }

        global $wpdb;

        $deleted = $wpdb->delete(
            $wpdb->prefix . 'hst_users_lessons',
            [
                'user_id' => $user_id,
                'term_id' => $term_id,
                'role' => 'student'
            ],
            [
                '%d',
                '%d',
                '%s'
            ]
        );

        if ($deleted === false) {

            wp_send_json_error([
                'message' => 'خطا در ریست دروس'
            ]);
        }

        wp_send_json_success([
            'message' => 'دروس سال تحصیلی حذف شد'
        ]);
    }

    public function hst_update_student()
    {
        $this->authorize_ajax();
        $user_id = intval($_POST['id'] ?? 0);
        $first_name = sanitize_text_field(
            $_POST['student_name'] ?? ''
        );
        $last_name = sanitize_text_field(
            $_POST['student_last_name'] ?? ''
        );
        $phone = HST_Guard::normalize_mobile(HST_Guard::post_text('student_phone'));
        $national_code = HST_Guard::post_text('student_national_code');
        $class_ids = $_POST['class_ids'] ?? [];
        $lesson_ids = $_POST['lesson_ids'] ?? [];
        $term_id = intval($_POST['term_id'] ?? 0);
        if (!$term_id && class_exists('HST_Terms')) {
            $term_id = (int) HST_Terms::active_id();
        }
        if (
            !$user_id ||
            !$first_name ||
            !$last_name ||
            !$term_id
        ) {
            wp_send_json_error([
                'message' => 'اطلاعات نامعتبر است'
            ]);
        }
        $user = get_userdata($user_id);
        if (!$user || !in_array('student', (array) $user->roles, true)) {
            wp_send_json_error(['message' => 'دانش‌آموز یافت نشد.']);
        }
        if (!HST_Guard::is_valid_iran_mobile($phone)) {
            wp_send_json_error(['message' => 'شماره موبایل باید با 09 شروع شود و 11 رقم باشد.']);
        }
        $national_code = class_exists('HST_User_Phones')
            ? HST_User_Phones::normalize_national_code($national_code)
            : '';
        if ($national_code === '') {
            wp_send_json_error(['message' => 'کد ملی باید دقیقاً 10 رقم باشد.']);
        }
        if (
            !is_array($class_ids) ||
            empty($class_ids)
        ) {
            wp_send_json_error([
                'message' => 'انتخاب کلاس الزامی است'
            ]);
        }
        if (
            count($class_ids) > 1
        ) {
            wp_send_json_error([
                'message' => 'دانش آموز فقط میتواند در یک کلاس باشد'
            ]);
        }
        $phone_owner = HST_User_Phones::owner($phone);
        if ($phone_owner && $phone_owner !== $user_id) {
            wp_send_json_error(['message' => 'این شماره موبایل قبلاً برای کاربر دیگری ثبت شده است.']);
        }
        $identity_result = HST_User_Phones::sync_username($user_id, $national_code);
        if (is_wp_error($identity_result)) {
            wp_send_json_error(['message' => $identity_result->get_error_message()]);
        }
        $phone_result = HST_User_Phones::set($user_id, $phone);
        if (is_wp_error($phone_result)) {
            wp_send_json_error(['message' => $phone_result->get_error_message()]);
        }
        global $wpdb;
        wp_update_user([
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $first_name . ' ' . $last_name
        ]);

        if (isset($_POST['student_father_phone'])) {
            $father_phone = HST_Guard::normalize_mobile(HST_Guard::post_text('student_father_phone'));
            update_user_meta($user_id, 'hst_father_phone', $father_phone);
            // Keep the legacy parent-phone meta in sync (father is primary).
            update_user_meta($user_id, 'hst_parent_phone', $father_phone);
        }
        if (isset($_POST['student_mother_phone'])) {
            update_user_meta($user_id, 'hst_mother_phone', HST_Guard::normalize_mobile(HST_Guard::post_text('student_mother_phone')));
        }
        if (isset($_POST['student_birthdate'])) {
            update_user_meta($user_id, 'hst_birthdate', sanitize_text_field(wp_unslash($_POST['student_birthdate'])));
        }
        update_user_meta($user_id, 'hst_national_code', $national_code);
        if (isset($_POST['student_father_name'])) {
            update_user_meta($user_id, 'hst_father_name', sanitize_text_field(wp_unslash($_POST['student_father_name'])));
        }

        $wpdb->delete(
            $wpdb->prefix . 'hst_users_classes',
            [
                'user_id' => $user_id,
                'term_id' => $term_id,
                'role' => 'student'
            ],
            [
                '%d',
                '%d',
                '%s'
            ]
        );
        $class_id = intval($class_ids[0]);
        $wpdb->insert(
            $wpdb->prefix . 'hst_users_classes',
            [
                'user_id' => $user_id,
                'class_id' => $class_id,
                'term_id' => $term_id,
                'role' => 'student'
            ],
            [
                '%d',
                '%d',
                '%d',
                '%s'
            ]
        );
        $wpdb->delete(
            $wpdb->prefix . 'hst_users_lessons',
            [
                'user_id' => $user_id,
                'term_id' => $term_id,
                'role' => 'student'
            ],
            [
                '%d',
                '%d',
                '%s'
            ]
        );
        if (is_array($lesson_ids)) {
            foreach ($lesson_ids as $lesson_id) {
                $lesson_id = intval($lesson_id);
                $lesson = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT class_id, unit
                        FROM {$wpdb->prefix}hst_lessons
                        WHERE id = %d",
                        $lesson_id
                    )
                );
                if (!$lesson) {
                    continue;
                }
                $wpdb->insert(
                    $wpdb->prefix . 'hst_users_lessons',
                    [
                        'user_id' => $user_id,
                        'class_id' => intval($lesson->class_id),
                        'lesson_id' => $lesson_id,
                        'lesson_unit' => intval($lesson->unit),
                        'term_id' => $term_id,
                        'role' => 'student'
                    ],
                    [
                        '%d',
                        '%d',
                        '%d',
                        '%d',
                        '%d',
                        '%s'
                    ]
                );
            }
        }
        wp_send_json_success([
            'message' => 'اطلاعات دانش آموز بروزرسانی شد'
        ]);
    }

    public function hst_get_student_term_data()
    {
        $this->authorize_ajax();
        $student_id = intval($_POST['student_id'] ?? 0);
        $term_id = intval($_POST['term_id'] ?? 0);
        if (!$student_id || !$term_id) {
            wp_send_json_error([
                'message' => 'اطلاعات نامعتبر است'
            ]);
        }
        global $wpdb;
        $user_classes = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT class_id
                FROM {$wpdb->prefix}hst_users_classes
                WHERE user_id = %d
                AND term_id = %d
                AND role = 'student'",
                $student_id,
                $term_id
            )
        );
        $lesson_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT lesson_id
                FROM {$wpdb->prefix}hst_users_lessons
                WHERE user_id = %d
                AND term_id = %d
                AND role = 'student'",
                $student_id,
                $term_id
            )
        );
        wp_send_json_success([
            'class_ids' => array_map('intval', $user_classes),
            'lesson_ids' => array_map('intval', $lesson_ids),
        ]);
    }


    public static function student_teachers($student_id)
    {
        global $wpdb;
        $student_id = absint($student_id);
        if (!$student_id) {
            return [];
        }

        $term_id = HST_Terms::active_id();
        if (!$term_id) {
            return [];
        }

        // Find teachers who share a (class_id, lesson_id) with the student in the
        // active term, via hst_users_lessons (role student vs teacher).
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT t.user_id AS teacher_id,
                        u.display_name AS teacher_name,
                        l.lesson_name
                 FROM {$wpdb->prefix}hst_users_lessons s
                 INNER JOIN {$wpdb->prefix}hst_users_lessons t
                        ON t.class_id = s.class_id
                       AND t.lesson_id = s.lesson_id
                       AND t.term_id = s.term_id
                       AND t.role = 'teacher'
                 INNER JOIN {$wpdb->users} u ON u.ID = t.user_id
                 LEFT JOIN {$wpdb->prefix}hst_lessons l ON l.id = s.lesson_id
                 WHERE s.user_id = %d
                   AND s.term_id = %d
                   AND s.role = 'student'
                 ORDER BY u.display_name ASC, l.lesson_name ASC",
                $student_id,
                $term_id
            )
        ) ?: [];

        // Group lessons under each teacher.
        $teachers = [];
        foreach ($rows as $row) {
            $tid = (int) $row->teacher_id;
            if (!isset($teachers[$tid])) {
                $teachers[$tid] = (object) [
                    'teacher_id' => $tid,
                    'name'       => $row->teacher_name,
                    'bio'        => (string) get_user_meta($tid, 'hst_teacher_bio', true),
                    'lessons'    => [],
                ];
            }
            if (!empty($row->lesson_name) && !in_array($row->lesson_name, $teachers[$tid]->lessons, true)) {
                $teachers[$tid]->lessons[] = $row->lesson_name;
            }
        }

        return array_values($teachers);
    }
}
