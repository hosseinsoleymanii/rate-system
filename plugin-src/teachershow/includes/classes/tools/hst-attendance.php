<?php

defined('ABSPATH') || exit;

class HST_Attendance
{
    public const STATUSES = [
        'present' => 'حاضر',
        'absent'  => 'غایب',
        'late'    => 'تأخیر',
        'excused' => 'غیبت موجه',
    ];

    public function __construct()
    {
        add_action('wp_ajax_hst_attendance_load_students', [$this, 'ajax_load_students']);
        add_action('wp_ajax_hst_attendance_save', [$this, 'ajax_save_attendance']);
        add_action('wp_ajax_hst_attendance_slots', [$this, 'ajax_teaching_slots']);
    }

    /**
     * Return the weekdays (and per-shift lesson info) the current teacher
     * teaches for a given class + lesson in the active term. Used to constrain
     * the attendance date picker and annotate the shift dropdown so the teacher
     * only sees days they actually have class.
     */
    public function ajax_teaching_slots()
    {
        $this->authorize_teacher();

        global $wpdb;
        $class_id  = class_exists('HST_Guard') ? HST_Guard::post_int('class_id') : absint(wp_unslash($_POST['class_id'] ?? 0));
        $lesson_id = class_exists('HST_Guard') ? HST_Guard::post_int('lesson_id') : absint(wp_unslash($_POST['lesson_id'] ?? 0));

        $term = self::active_term();
        if (!$term || !$class_id || !$lesson_id) {
            wp_send_json_success(['weekdays' => [], 'slots' => []]);
        }

        $teacher_id = get_current_user_id();
        $is_manager = self::can_manage_attendance();

        // Managers can see all teachers' slots for the class/lesson; a teacher
        // is limited to their own.
        $teacher_condition = $is_manager ? '' : $wpdb->prepare(' AND s.teacher_id = %d', $teacher_id);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.day_of_week, s.school_shift, l.lesson_name
                 FROM {$wpdb->prefix}hst_schedules s
                 LEFT JOIN {$wpdb->prefix}hst_lessons l ON l.id = s.lesson_id
                 WHERE s.term_id = %d AND s.class_id = %d AND s.lesson_id = %d {$teacher_condition}
                 ORDER BY FIELD(s.day_of_week,'saturday','sunday','monday','tuesday','wednesday'), s.school_shift",
                (int) $term->id,
                $class_id,
                $lesson_id
            )
        ) ?: [];

        // Map weekday slug -> jalali weekday index used by the datepicker
        // (Saturday = 0 ... Wednesday = 4).
        $weekday_index = [
            'saturday'  => 0,
            'sunday'    => 1,
            'monday'    => 2,
            'tuesday'   => 3,
            'wednesday' => 4,
        ];

        $weekdays = [];
        $slots = [];
        foreach ($rows as $row) {
            if (isset($weekday_index[$row->day_of_week])) {
                $idx = $weekday_index[$row->day_of_week];
                if (!in_array($idx, $weekdays, true)) {
                    $weekdays[] = $idx;
                }
                $slots[] = [
                    'weekday' => $idx,
                    'shift'   => (int) $row->school_shift,
                    'lesson'  => $row->lesson_name,
                ];
            }
        }

        wp_send_json_success(['weekdays' => $weekdays, 'slots' => $slots]);
    }

    private function authorize_teacher(): void
    {
        check_ajax_referer('hst_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'برای انجام این عملیات باید وارد حساب کاربری شوید.'], 401);
        }

        $user = wp_get_current_user();
        $is_teacher = current_user_can('hst_teach') || in_array('teacher', (array) $user->roles, true);
        $is_manager = self::can_manage_attendance();

        if (!$is_teacher && !$is_manager) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز است.'], 403);
        }
    }

    public static function active_term()
    {
        return HST_Terms::active();
    }

    public static function days()
    {
        return [
            'saturday'  => 'شنبه',
            'sunday'    => 'یکشنبه',
            'monday'    => 'دوشنبه',
            'tuesday'   => 'سه‌شنبه',
            'wednesday' => 'چهارشنبه',
        ];
    }

    public static function shifts()
    {
        return [1 => 'زنگ ۱', 2 => 'زنگ ۲', 3 => 'زنگ ۳', 4 => 'زنگ ۴'];
    }

    private static function normalize_date($date)
    {
        $date = sanitize_text_field((string) $date);
        $date = class_exists('HST_Date') ? HST_Date::to_gregorian_date($date) : $date;

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return '';
        }

        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return '';
        }

        return $date;
    }

    private static function validate_attendance_date($date)
    {
        if (!$date) {
            return false;
        }

        $timestamp = strtotime($date . ' 00:00:00');
        if (!$timestamp) {
            return false;
        }

        $min = strtotime('-2 years', current_time('timestamp'));
        $max = strtotime('+14 days', current_time('timestamp'));

        return $timestamp >= $min && $timestamp <= $max;
    }

    private static function can_manage_attendance()
    {
        return current_user_can('manage_options') || current_user_can('hst_manage_school');
    }

    private static function clean_attendance_rows($rows)
    {
        $rows = wp_unslash($rows);
        return is_array($rows) ? array_slice($rows, 0, 120) : [];
    }

    public static function teacher_scope($teacher_id, $term_id)
    {
        global $wpdb;

        $teacher_id = absint($teacher_id);
        $term_id = absint($term_id);

        if (!$teacher_id || !$term_id) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT
                    c.id AS class_id,
                    c.class_name,
                    l.id AS lesson_id,
                    l.lesson_name,
                    l.unit
                FROM {$wpdb->prefix}hst_users_classes tc
                INNER JOIN {$wpdb->prefix}hst_users_lessons tl
                    ON tl.user_id = tc.user_id
                    AND tl.term_id = tc.term_id
                    AND tl.role = 'teacher'
                    AND tl.class_id = tc.class_id
                INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = tc.class_id
                INNER JOIN {$wpdb->prefix}hst_lessons l
                    ON l.id = tl.lesson_id
                    AND l.class_id = tc.class_id
                WHERE tc.user_id = %d
                    AND tc.term_id = %d
                    AND tc.role = 'teacher'
                ORDER BY c.class_name ASC, l.lesson_name ASC",
                $teacher_id,
                $term_id
            )
        ) ?: [];

        return HST_Classes::sort_rows($rows, 'class_name', ['lesson_name']);
    }

    public static function scope_grouped_by_class(array $scope)
    {
        $grouped = [];

        foreach ($scope as $item) {
            $class_id = (int) $item->class_id;
            if (!isset($grouped[$class_id])) {
                $grouped[$class_id] = [
                    'id' => $class_id,
                    'name' => $item->class_name,
                    'lessons' => [],
                ];
            }

            $grouped[$class_id]['lessons'][] = [
                'id' => (int) $item->lesson_id,
                'name' => $item->lesson_name,
                'unit' => (int) $item->unit,
            ];
        }

        return $grouped;
    }

    private static function teacher_can_access_scope($teacher_id, $term_id, $class_id, $lesson_id)
    {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1
                FROM {$wpdb->prefix}hst_users_classes tc
                INNER JOIN {$wpdb->prefix}hst_users_lessons tl
                    ON tl.user_id = tc.user_id
                    AND tl.term_id = tc.term_id
                    AND tl.role = 'teacher'
                    AND tl.class_id = tc.class_id
                    AND tl.lesson_id = %d
                WHERE tc.user_id = %d
                    AND tc.term_id = %d
                    AND tc.class_id = %d
                    AND tc.role = 'teacher'
                LIMIT 1",
                $lesson_id,
                $teacher_id,
                $term_id,
                $class_id
            )
        );
    }

    private static function students_for_scope($term_id, $class_id, $lesson_id)
    {
        global $wpdb;

        $students = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT
                    u.ID AS student_id,
                    u.display_name,
                    um.meta_value AS phone
                FROM {$wpdb->users} u
                INNER JOIN {$wpdb->prefix}hst_users_classes sc
                    ON sc.user_id = u.ID
                    AND sc.role = 'student'
                    AND sc.term_id = %d
                    AND sc.class_id = %d
                INNER JOIN {$wpdb->prefix}hst_users_lessons sl
                    ON sl.user_id = u.ID
                    AND sl.role = 'student'
                    AND sl.term_id = sc.term_id
                    AND sl.class_id = sc.class_id
                    AND sl.lesson_id = %d
                LEFT JOIN {$wpdb->usermeta} um
                    ON um.user_id = u.ID
                    AND um.meta_key = 'phone'",
                $term_id,
                $class_id,
                $lesson_id
            )
        ) ?: [];

        return class_exists('HST_Students') ? HST_Students::sort_student_rows($students) : $students;
    }

    private static function attendance_map($term_id, $class_id, $lesson_id, $teacher_id, $attendance_date, $school_shift)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_attendance_records';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT student_id, status, late_minutes, note
                FROM {$table}
                WHERE term_id = %d
                    AND class_id = %d
                    AND lesson_id = %d
                    AND teacher_id = %d
                    AND attendance_date = %s
                    AND school_shift = %d",
                $term_id,
                $class_id,
                $lesson_id,
                $teacher_id,
                $attendance_date,
                $school_shift
            )
        ) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->student_id] = $row;
        }

        return $map;
    }

    public static function get_teacher_context($teacher_id)
    {
        $active_term = self::active_term();
        $scope = $active_term ? self::teacher_scope($teacher_id, (int) $active_term->id) : [];

        return [
            'active_term' => $active_term,
            'scope' => $scope,
            'classes' => self::scope_grouped_by_class($scope),
            'statuses' => self::STATUSES,
            'shifts' => self::shifts(),
        ];
    }

    public function ajax_load_students()
    {
        $this->authorize_teacher();

        $active_term = self::active_term();
        if (!$active_term) {
            wp_send_json_error(['message' => 'سال تحصیلی فعالی تعریف نشده است.']);
        }

        $teacher_id = get_current_user_id();
        $class_id = class_exists('HST_Guard') ? HST_Guard::post_int('class_id') : absint(wp_unslash($_POST['class_id'] ?? 0));
        $lesson_id = class_exists('HST_Guard') ? HST_Guard::post_int('lesson_id') : absint(wp_unslash($_POST['lesson_id'] ?? 0));
        $school_shift = class_exists('HST_Guard') ? HST_Guard::post_int('school_shift') : absint(wp_unslash($_POST['school_shift'] ?? 0));
        $attendance_date = self::normalize_date(class_exists('HST_Guard') ? HST_Guard::post_text('attendance_date') : wp_unslash($_POST['attendance_date'] ?? ''));

        if (!$class_id || !$lesson_id || $school_shift < 1 || $school_shift > 4 || !self::validate_attendance_date($attendance_date)) {
            wp_send_json_error(['message' => 'کلاس، درس، تاریخ و زنگ را کامل و معتبر انتخاب کنید.'], 400);
        }

        if (!self::can_manage_attendance() && !self::teacher_can_access_scope($teacher_id, (int) $active_term->id, $class_id, $lesson_id)) {
            wp_send_json_error(['message' => 'این کلاس یا درس در محدوده دسترسی شما نیست.']);
        }

        $students = self::students_for_scope((int) $active_term->id, $class_id, $lesson_id);
        $attendance_map = self::attendance_map((int) $active_term->id, $class_id, $lesson_id, $teacher_id, $attendance_date, $school_shift);

        $items = [];
        foreach ($students as $student) {
            $existing = $attendance_map[(int) $student->student_id] ?? null;
            $sid = (int) $student->student_id;

            // Profile picture (respecting the approval module's visibility).
            $avatar_url = '';
            $att_id = absint(get_user_meta($sid, 'hst_profile_avatar_id', true));
            if ($att_id) {
                $public = !class_exists('HST_Avatar_Approval') || HST_Avatar_Approval::is_public($sid);
                if ($public) {
                    $avatar_url = wp_get_attachment_image_url($att_id, 'thumbnail') ?: '';
                }
            }

            $display_name = trim((string) $student->display_name);
            $first_name = trim((string) get_user_meta($sid, 'first_name', true));
            $last_name = trim((string) get_user_meta($sid, 'last_name', true));

            $items[] = [
                'student_id' => $sid,
                'name' => $display_name,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'initials' => $this->user_initials($first_name, $last_name, $display_name),
                'phone' => (string) ($student->phone ?? ''),
                'avatar' => $avatar_url,
                'status' => $existing ? $existing->status : 'present',
                'late_minutes' => $existing ? (int) $existing->late_minutes : 0,
                'note' => $existing ? (string) $existing->note : '',
            ];
        }

        wp_send_json_success([
            'students' => $items,
            'count' => count($items),
            'message' => count($items) ? 'لیست دانش‌آموزان آماده است.' : 'دانش‌آموزی برای این کلاس و درس یافت نشد.',
        ]);
    }

    public function ajax_save_attendance()
    {
        $this->authorize_teacher();

        $active_term = self::active_term();
        if (!$active_term) {
            wp_send_json_error(['message' => 'سال تحصیلی فعالی تعریف نشده است.']);
        }

        $teacher_id = get_current_user_id();
        $class_id = class_exists('HST_Guard') ? HST_Guard::post_int('class_id') : absint(wp_unslash($_POST['class_id'] ?? 0));
        $lesson_id = class_exists('HST_Guard') ? HST_Guard::post_int('lesson_id') : absint(wp_unslash($_POST['lesson_id'] ?? 0));
        $school_shift = class_exists('HST_Guard') ? HST_Guard::post_int('school_shift') : absint(wp_unslash($_POST['school_shift'] ?? 0));
        $attendance_date = self::normalize_date(class_exists('HST_Guard') ? HST_Guard::post_text('attendance_date') : wp_unslash($_POST['attendance_date'] ?? ''));
        $rows = self::clean_attendance_rows($_POST['rows'] ?? []);

        if (!$class_id || !$lesson_id || $school_shift < 1 || $school_shift > 4 || !self::validate_attendance_date($attendance_date) || empty($rows)) {
            wp_send_json_error(['message' => 'اطلاعات حضور و غیاب ناقص یا نامعتبر است.'], 400);
        }

        if (!self::can_manage_attendance() && !self::teacher_can_access_scope($teacher_id, (int) $active_term->id, $class_id, $lesson_id)) {
            wp_send_json_error(['message' => 'این کلاس یا درس در محدوده دسترسی شما نیست.']);
        }

        $valid_students = array_map('intval', wp_list_pluck(self::students_for_scope((int) $active_term->id, $class_id, $lesson_id), 'student_id'));
        $valid_students = array_fill_keys($valid_students, true);

        global $wpdb;
        $table = $wpdb->prefix . 'hst_attendance_records';
        $saved = 0;
        $wpdb->query('START TRANSACTION');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $student_id = absint($row['student_id'] ?? 0);
            if (!$student_id || !isset($valid_students[$student_id])) {
                continue;
            }

            $status = sanitize_key($row['status'] ?? 'present');
            if (!isset(self::STATUSES[$status])) {
                $status = 'present';
            }

            $late_minutes = max(0, min(240, absint($row['late_minutes'] ?? 0)));
            if ($status !== 'late') {
                $late_minutes = 0;
            }

            $note = mb_substr(sanitize_textarea_field($row['note'] ?? ''), 0, 300);
            $now = current_time('mysql');

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table}
                    WHERE term_id = %d AND class_id = %d AND lesson_id = %d AND teacher_id = %d
                        AND student_id = %d AND attendance_date = %s AND school_shift = %d
                    LIMIT 1",
                    (int) $active_term->id,
                    $class_id,
                    $lesson_id,
                    $teacher_id,
                    $student_id,
                    $attendance_date,
                    $school_shift
                )
            );

            $data = [
                'term_id' => (int) $active_term->id,
                'class_id' => $class_id,
                'lesson_id' => $lesson_id,
                'teacher_id' => $teacher_id,
                'student_id' => $student_id,
                'attendance_date' => $attendance_date,
                'school_shift' => $school_shift,
                'status' => $status,
                'late_minutes' => $late_minutes,
                'note' => $note,
                'updated_at' => $now,
            ];

            if ($exists) {
                $result = $wpdb->update(
                    $table,
                    $data,
                    ['id' => (int) $exists],
                    ['%d','%d','%d','%d','%d','%s','%d','%s','%d','%s','%s'],
                    ['%d']
                );
            } else {
                $data['created_at'] = $now;
                $result = $wpdb->insert(
                    $table,
                    $data,
                    ['%d','%d','%d','%d','%d','%s','%d','%s','%d','%s','%s','%s']
                );
            }

            if ($result !== false) {
                $saved++;
            }
        }

        $wpdb->query('COMMIT');

        wp_send_json_success(['message' => sprintf('حضور و غیاب %d دانش‌آموز ذخیره شد.', $saved), 'saved' => $saved]);
    }
    private function user_initials(string $first_name, string $last_name, string $display_name): string
    {
        $first_char = static function (string $value): string {
            $value = trim($value);
            if ($value === '') {
                return '';
            }
            return function_exists('mb_substr')
                ? mb_substr($value, 0, 1, 'UTF-8')
                : substr($value, 0, 1);
        };

        $parts = preg_split('/\s+/u', trim($display_name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $first_char($first_name) ?: $first_char((string) ($parts[0] ?? ''));
        $last = $first_char($last_name);
        if ($last === '' && count($parts) > 1) {
            $last = $first_char((string) $parts[count($parts) - 1]);
        }

        $initials = array_values(array_filter([$first, $last], static function (string $value): bool {
            return $value !== '';
        }));

        return $initials ? implode("\u{00A0}", $initials) : '؟';
    }

}
