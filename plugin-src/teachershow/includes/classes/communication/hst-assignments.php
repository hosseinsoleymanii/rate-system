<?php

defined('ABSPATH') || exit;

class HST_Assignments
{
    private $allowed_days = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday'];

    public function __construct()
    {
        add_action('wp_ajax_hst_create_assignment', [$this, 'ajax_create_assignment']);
        add_action('wp_ajax_hst_close_assignment', [$this, 'ajax_close_assignment']);
        add_action('wp_ajax_hst_delete_assignment', [$this, 'ajax_delete_assignment']);
        add_action('wp_ajax_hst_submit_assignment', [$this, 'ajax_submit_assignment']);
        add_action('wp_ajax_hst_review_assignment_submission', [$this, 'ajax_review_submission']);
    }

    private function json_error($message, $code = 400)
    {
        wp_send_json_error(['message' => $message], $code);
    }

    private function verify_ajax()
    {
        if (class_exists('HST_Guard')) {
            check_ajax_referer('hst_nonce', 'nonce');
            if (!is_user_logged_in()) {
                HST_Guard::fail('برای انجام این عملیات ابتدا وارد شوید.', 401);
            }
            return;
        }

        check_ajax_referer('hst_nonce', 'nonce');
        if (!is_user_logged_in()) {
            $this->json_error('برای انجام این عملیات ابتدا وارد شوید.', 401);
        }
    }

    private function normalize_allowed_file_types($value)
    {
        $raw = is_array($value) ? implode(',', $value) : (string) $value;
        $items = array_filter(array_map('trim', explode(',', strtolower($raw))));
        $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'zip'];
        $items = array_values(array_unique(array_intersect($items, $allowed)));
        return $items ? implode(',', $items) : 'pdf,doc,docx,jpg,jpeg,png';
    }

    private function normalize_due_datetime($value)
    {
        $raw = sanitize_text_field(wp_unslash($value));
        if ($raw === '') {
            return null;
        }

        $due_at = class_exists('HST_Date') ? HST_Date::to_gregorian_datetime($raw) : null;
        if (!$due_at) {
            $timestamp = strtotime($raw);
            $due_at = $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
        }

        if (!$due_at || strtotime($due_at) === false) {
            return false;
        }

        return $due_at;
    }

    private function assignment_file_allowed($file, $assignment)
    {
        if (empty($file['name']) || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('hst_invalid_upload', 'فایل ارسال‌شده معتبر نیست.');
        }

        $max_bytes = max(1, (int) $assignment->max_file_size) * 1024 * 1024;
        if (!empty($file['size']) && (int) $file['size'] > $max_bytes) {
            return new WP_Error('hst_large_file', 'حجم فایل بیشتر از حد مجاز است.');
        }

        $allowed_exts = array_filter(array_map('trim', explode(',', strtolower((string) $assignment->allowed_types))));
        $filetype = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        $ext = strtolower((string) ($filetype['ext'] ?: pathinfo($file['name'], PATHINFO_EXTENSION)));

        if (!$ext || ($allowed_exts && !in_array($ext, $allowed_exts, true))) {
            return new WP_Error('hst_invalid_file_type', 'فرمت فایل مجاز نیست.');
        }

        return true;
    }

    private function is_manager()
    {
        return current_user_can('manage_options') || current_user_can('hst_manage_school');
    }

    private function is_teacher()
    {
        return current_user_can('hst_teach') || in_array('teacher', (array) wp_get_current_user()->roles, true);
    }

    private function is_student()
    {
        return current_user_can('hst_study') || in_array('student', (array) wp_get_current_user()->roles, true);
    }

    public static function active_term()
    {
        return HST_Terms::active();
    }

    public static function teacher_scope($teacher_id = 0, $term_id = 0)
    {
        global $wpdb;
        $teacher_id = $teacher_id ?: get_current_user_id();
        if (!$term_id) {
            $term = self::active_term();
            $term_id = $term ? (int) $term->id : 0;
        }
        if (!$teacher_id || !$term_id) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT c.id AS class_id, c.class_name, l.id AS lesson_id, l.lesson_name
             FROM {$wpdb->prefix}hst_users_lessons ul
             INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = ul.lesson_id
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = ul.class_id
             WHERE ul.user_id = %d AND ul.term_id = %d AND ul.role = 'teacher'
             ORDER BY c.class_name ASC, l.lesson_name ASC",
            $teacher_id,
            $term_id
        )) ?: [];

        return HST_Classes::sort_rows($rows, 'class_name', ['lesson_name']);
    }

    private function teacher_can_manage($teacher_id, $term_id, $class_id, $lesson_id)
    {
        if ($this->is_manager()) {
            return true;
        }
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hst_users_lessons
             WHERE user_id = %d AND term_id = %d AND class_id = %d AND lesson_id = %d AND role = 'teacher' LIMIT 1",
            $teacher_id,
            $term_id,
            $class_id,
            $lesson_id
        ));
    }

    private function student_can_access_assignment($assignment_id, $student_id)
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT a.id
             FROM {$wpdb->prefix}hst_assignments a
             INNER JOIN {$wpdb->prefix}hst_users_classes uc
                ON uc.user_id = %d AND uc.term_id = a.term_id AND uc.class_id = a.class_id AND uc.role = 'student'
             INNER JOIN {$wpdb->prefix}hst_users_lessons ul
                ON ul.user_id = %d AND ul.term_id = a.term_id AND ul.lesson_id = a.lesson_id AND ul.role = 'student'
             WHERE a.id = %d AND a.status IN ('published','closed')
             LIMIT 1",
            $student_id,
            $student_id,
            $assignment_id
        ));
    }

    public static function teacher_assignments($teacher_id = 0, $term_id = 0)
    {
        global $wpdb;
        $teacher_id = $teacher_id ?: get_current_user_id();
        if (!$term_id) {
            $term = self::active_term();
            $term_id = $term ? (int) $term->id : 0;
        }
        if (!$teacher_id || !$term_id) {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, c.class_name, l.lesson_name,
                    COUNT(DISTINCT st.user_id) AS student_count,
                    COUNT(DISTINCT sub.student_id) AS submitted_count,
                    SUM(CASE WHEN sub.status = 'reviewed' THEN 1 ELSE 0 END) AS reviewed_count
             FROM {$wpdb->prefix}hst_assignments a
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = a.class_id
             INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = a.lesson_id
             LEFT JOIN {$wpdb->prefix}hst_users_lessons st
                ON st.term_id = a.term_id AND st.class_id = a.class_id AND st.lesson_id = a.lesson_id AND st.role = 'student'
             LEFT JOIN {$wpdb->prefix}hst_assignment_submissions sub ON sub.assignment_id = a.id
             WHERE a.teacher_id = %d AND a.term_id = %d
             GROUP BY a.id
             ORDER BY a.created_at DESC",
            $teacher_id,
            $term_id
        )) ?: [];
    }

    public static function student_assignments($student_id = 0, $term_id = 0)
    {
        global $wpdb;
        $student_id = $student_id ?: get_current_user_id();
        if (!$term_id) {
            $term = self::active_term();
            $term_id = $term ? (int) $term->id : 0;
        }
        if (!$student_id || !$term_id) {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT a.*, c.class_name, l.lesson_name, u.display_name AS teacher_name,
                    sub.id AS submission_id, sub.file_url, sub.original_name, sub.status AS submission_status,
                    sub.teacher_note, sub.score, sub.submitted_at, sub.reviewed_at
             FROM {$wpdb->prefix}hst_assignments a
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = a.class_id
             INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = a.lesson_id
             INNER JOIN {$wpdb->users} u ON u.ID = a.teacher_id
             INNER JOIN {$wpdb->prefix}hst_users_classes uc
                ON uc.user_id = %d AND uc.term_id = a.term_id AND uc.class_id = a.class_id AND uc.role = 'student'
             INNER JOIN {$wpdb->prefix}hst_users_lessons ul
                ON ul.user_id = %d AND ul.term_id = a.term_id AND ul.lesson_id = a.lesson_id AND ul.role = 'student'
             LEFT JOIN {$wpdb->prefix}hst_assignment_submissions sub
                ON sub.assignment_id = a.id AND sub.student_id = %d
             WHERE a.term_id = %d AND a.status IN ('published','closed')
             ORDER BY CASE WHEN a.due_at IS NULL THEN 1 ELSE 0 END, a.due_at ASC, a.created_at DESC",
            $student_id,
            $student_id,
            $student_id,
            $term_id
        )) ?: [];
    }

    public static function assignment_submissions($assignment_id)
    {
        global $wpdb;
        $assignment_id = (int) $assignment_id;
        if (!$assignment_id) {
            return [];
        }
        $students = $wpdb->get_results($wpdb->prepare(
            "SELECT st.user_id AS student_id, u.display_name AS student_name,
                    sub.id AS submission_id, sub.file_url, sub.original_name, sub.status,
                    sub.teacher_note, sub.score, sub.submitted_at, sub.reviewed_at
             FROM {$wpdb->prefix}hst_assignments a
             INNER JOIN {$wpdb->prefix}hst_users_lessons st
                ON st.term_id = a.term_id AND st.class_id = a.class_id AND st.lesson_id = a.lesson_id AND st.role = 'student'
             INNER JOIN {$wpdb->users} u ON u.ID = st.user_id
             LEFT JOIN {$wpdb->prefix}hst_assignment_submissions sub
                ON sub.assignment_id = a.id AND sub.student_id = st.user_id
             WHERE a.id = %d
             GROUP BY st.user_id",
            $assignment_id
        )) ?: [];

        return class_exists('HST_Students') ? HST_Students::sort_student_rows($students) : $students;
    }

    public function ajax_create_assignment()
    {
        $this->verify_ajax();
        if (!$this->is_teacher() && !$this->is_manager()) {
            $this->json_error('فقط معلم یا مدیر می‌تواند تکلیف تعریف کند.', 403);
        }
        $term = self::active_term();
        if (!$term) {
            $this->json_error('سال تحصیلی فعالی برای ثبت تکلیف وجود ندارد.');
        }
        $teacher_id = get_current_user_id();
        $term_id = (int) $term->id;
        $class_id = class_exists('HST_Guard') ? HST_Guard::post_int('class_id') : absint($_POST['class_id'] ?? 0);
        $lesson_id = class_exists('HST_Guard') ? HST_Guard::post_int('lesson_id') : absint($_POST['lesson_id'] ?? 0);
        $title = class_exists('HST_Guard') ? HST_Guard::post_text('title') : sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $description = wp_kses_post(wp_unslash($_POST['description'] ?? ''));
        $due_at_raw = wp_unslash($_POST['due_at'] ?? '');
        $status = sanitize_key(wp_unslash($_POST['status'] ?? 'published'));
        $max_file_size = max(1, min(25, absint(wp_unslash($_POST['max_file_size'] ?? 10))));
        $allowed_types = $this->normalize_allowed_file_types(wp_unslash($_POST['allowed_types'] ?? 'pdf,doc,docx,ppt,pptx,jpg,jpeg,png,zip'));
        $title = mb_substr($title, 0, 160);
        $description = mb_substr($description, 0, 5000);
        $status = in_array($status, ['draft', 'published', 'closed'], true) ? $status : 'published';
        if (!$class_id || !$lesson_id || !$title) {
            $this->json_error('کلاس، درس و عنوان تکلیف الزامی است.');
        }
        if (!$this->teacher_can_manage($teacher_id, $term_id, $class_id, $lesson_id)) {
            $this->json_error('این کلاس/درس در محدوده شما نیست.', 403);
        }
        $due_at = $this->normalize_due_datetime($due_at_raw);
        if ($due_at === false) {
            $this->json_error('تاریخ تحویل تکلیف معتبر نیست.');
        }
        global $wpdb;
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'hst_assignments',
            [
                'term_id' => $term_id,
                'class_id' => $class_id,
                'lesson_id' => $lesson_id,
                'teacher_id' => $teacher_id,
                'title' => $title,
                'description' => $description,
                'due_at' => $due_at,
                'status' => $status,
                'max_file_size' => $max_file_size,
                'allowed_types' => $allowed_types,
                'created_at' => current_time('mysql'),
            ],
            ['%d','%d','%d','%d','%s','%s','%s','%s','%d','%s','%s']
        );
        if (!$inserted) {
            $this->json_error('ثبت تکلیف انجام نشد.');
        }
        do_action('hst_assignment_created', $wpdb->insert_id, $teacher_id);
        wp_send_json_success(['message' => 'تکلیف با موفقیت ثبت شد.']);
    }

    public function ajax_close_assignment()
    {
        $this->verify_ajax();
        $assignment_id = class_exists('HST_Guard') ? HST_Guard::post_int('assignment_id') : absint($_POST['assignment_id'] ?? 0);
        $status = sanitize_key(wp_unslash($_POST['status'] ?? 'closed'));
        $status = in_array($status, ['published', 'closed'], true) ? $status : 'closed';
        global $wpdb;
        $assignment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hst_assignments WHERE id = %d", $assignment_id));
        if (!$assignment) {
            $this->json_error('تکلیف پیدا نشد.');
        }
        if (!$this->is_manager() && (int) $assignment->teacher_id !== get_current_user_id()) {
            $this->json_error('اجازه تغییر این تکلیف را ندارید.', 403);
        }
        $wpdb->update($wpdb->prefix . 'hst_assignments', ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $assignment_id], ['%s','%s'], ['%d']);
        wp_send_json_success(['message' => $status === 'closed' ? 'تکلیف بسته شد.' : 'تکلیف فعال شد.']);
    }

    public function ajax_delete_assignment()
    {
        $this->verify_ajax();
        $assignment_id = class_exists('HST_Guard') ? HST_Guard::post_int('assignment_id') : absint($_POST['assignment_id'] ?? 0);
        global $wpdb;
        $assignment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hst_assignments WHERE id = %d", $assignment_id));
        if (!$assignment) {
            $this->json_error('تکلیف پیدا نشد.');
        }
        if (!$this->is_manager() && (int) $assignment->teacher_id !== get_current_user_id()) {
            $this->json_error('اجازه حذف این تکلیف را ندارید.', 403);
        }
        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT file_path FROM {$wpdb->prefix}hst_assignment_submissions WHERE assignment_id = %d",
            $assignment_id
        )) ?: [];
        foreach ($submissions as $submission) {
            $path = (string) $submission->file_path;
            if ($path && file_exists($path) && strpos(wp_normalize_path($path), wp_normalize_path(wp_upload_dir()['basedir'])) === 0) {
                wp_delete_file($path);
            }
        }
        $wpdb->delete($wpdb->prefix . 'hst_assignment_submissions', ['assignment_id' => $assignment_id], ['%d']);
        $deleted = $wpdb->delete($wpdb->prefix . 'hst_assignments', ['id' => $assignment_id], ['%d']);
        if ($deleted === false) {
            $this->json_error('حذف تکلیف انجام نشد.');
        }
        wp_send_json_success(['message' => 'تکلیف حذف شد.']);
    }

    public function ajax_submit_assignment()
    {
        $this->verify_ajax();
        if (!$this->is_student()) {
            $this->json_error('فقط دانش‌آموز می‌تواند پاسخ تکلیف ارسال کند.', 403);
        }
        $assignment_id = class_exists('HST_Guard') ? HST_Guard::post_int('assignment_id') : absint($_POST['assignment_id'] ?? 0);
        $student_id = get_current_user_id();
        if (!$this->student_can_access_assignment($assignment_id, $student_id)) {
            $this->json_error('این تکلیف برای شما قابل ارسال نیست.', 403);
        }
        global $wpdb;
        $assignment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}hst_assignments WHERE id = %d", $assignment_id));
        if (!$assignment || $assignment->status !== 'published') {
            $this->json_error('مهلت ارسال این تکلیف بسته است.');
        }

        // If the student's submission has already been reviewed/graded by the
        // teacher, they may not send a new file for the same assignment.
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, score FROM {$wpdb->prefix}hst_assignment_submissions WHERE assignment_id = %d AND student_id = %d",
            $assignment_id,
            $student_id
        ));
        if ($current && ($current->status === 'reviewed' || ($current->score !== null && $current->score !== ''))) {
            $this->json_error('این تکلیف توسط معلم بررسی و نمره‌دهی شده است و امکان ارسال مجدد وجود ندارد.');
        }

        if (empty($_FILES['assignment_file']['name'])) {
            $this->json_error('انتخاب فایل الزامی است.');
        }
        $file = $_FILES['assignment_file'];
        $file_check = $this->assignment_file_allowed($file, $assignment);
        if (is_wp_error($file_check)) {
            $this->json_error($file_check->get_error_message());
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        add_filter('upload_dir', [$this, 'assignment_upload_dir']);
        add_filter('upload_mimes', [$this, 'assignment_upload_mimes']);
        $uploaded = wp_handle_upload($file, ['test_form' => false]);
        remove_filter('upload_mimes', [$this, 'assignment_upload_mimes']);
        remove_filter('upload_dir', [$this, 'assignment_upload_dir']);
        if (!empty($uploaded['error'])) {
            $this->json_error($uploaded['error']);
        }
        $data = [
            'assignment_id' => $assignment_id,
            'student_id' => $student_id,
            'file_url' => esc_url_raw($uploaded['url']),
            'file_path' => sanitize_text_field($uploaded['file']),
            'original_name' => sanitize_file_name($file['name']),
            'status' => 'submitted',
            'teacher_note' => null,
            'score' => null,
            'submitted_at' => current_time('mysql'),
            'reviewed_at' => null,
        ];
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}hst_assignment_submissions WHERE assignment_id = %d AND student_id = %d", $assignment_id, $student_id));
        if ($exists) {
            $wpdb->update($wpdb->prefix . 'hst_assignment_submissions', $data, ['id' => (int) $exists], ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s'], ['%d']);
        } else {
            $wpdb->insert($wpdb->prefix . 'hst_assignment_submissions', $data, ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s']);
            // New submission → notify the assignment's teacher to review it.
            do_action('hst_assignment_submitted', [
                'assignment_id' => $assignment_id,
                'teacher_id'    => (int) $assignment->teacher_id,
                'student_id'    => $student_id,
                'title'         => $assignment->title,
            ]);
        }
        wp_send_json_success(['message' => 'پاسخ تکلیف با موفقیت ارسال شد.']);
    }

    public function assignment_upload_mimes($mimes)
    {
        return array_merge($mimes, [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip' => 'application/zip',
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
        ]);
    }

    public function assignment_upload_dir($dirs)
    {
        $subdir = '/hst-assignments/' . gmdate('Y/m');
        $dirs['subdir'] = $subdir;
        $dirs['path'] = $dirs['basedir'] . $subdir;
        $dirs['url'] = $dirs['baseurl'] . $subdir;
        return $dirs;
    }

    public function ajax_review_submission()
    {
        $this->verify_ajax();
        if (!$this->is_teacher() && !$this->is_manager()) {
            $this->json_error('اجازه بررسی تکلیف را ندارید.', 403);
        }
        $submission_id = class_exists('HST_Guard') ? HST_Guard::post_int('submission_id') : absint($_POST['submission_id'] ?? 0);
        $status = sanitize_key(wp_unslash($_POST['status'] ?? 'reviewed'));
        $note = class_exists('HST_Guard') ? HST_Guard::post_textarea('teacher_note') : sanitize_textarea_field(wp_unslash($_POST['teacher_note'] ?? ''));
        $note = mb_substr($note, 0, 1000);
        $score_raw = sanitize_text_field(wp_unslash($_POST['score'] ?? ''));
        $status = in_array($status, ['reviewed', 'needs_revision'], true) ? $status : 'reviewed';
                $score = null;
        if ($score_raw !== '') {
            $normalized_score = str_replace(',', '.', $score_raw);
            if (!is_numeric($normalized_score) || (float) $normalized_score < 0 || (float) $normalized_score > 20) {
                $this->json_error('نمره باید عددی بین ۰ تا ۲۰ باشد.');
            }
            $score = round((float) $normalized_score, 2);
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT sub.*, a.teacher_id FROM {$wpdb->prefix}hst_assignment_submissions sub
             INNER JOIN {$wpdb->prefix}hst_assignments a ON a.id = sub.assignment_id
             WHERE sub.id = %d",
            $submission_id
        ));
        if (!$row) {
            $this->json_error('ارسال دانش‌آموز پیدا نشد.');
        }
        if (!$this->is_manager() && (int) $row->teacher_id !== get_current_user_id()) {
            $this->json_error('این ارسال مربوط به شما نیست.', 403);
        }
        $wpdb->update(
            $wpdb->prefix . 'hst_assignment_submissions',
            ['status' => $status, 'teacher_note' => $note, 'score' => $score, 'reviewed_at' => current_time('mysql')],
            ['id' => $submission_id],
            ['%s','%s','%f','%s'],
            ['%d']
        );
        wp_send_json_success(['message' => 'بررسی تکلیف ذخیره شد.']);
    }
}