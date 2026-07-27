<?php

defined('ABSPATH') || exit;

/**
 * Dashboard statistics service.
 *
 * Provides lightweight, real-data aggregates for the role dashboards so the
 * landing grid is not the only thing on screen. All queries are scoped to the
 * active term where that makes sense, use prepared statements, and are cached
 * per-request to avoid duplicate work within a single page load.
 */
class HST_Stats
{
    /** @var array<string,mixed> Per-request memo cache. */
    private static array $cache = [];

    /**
     * The active term row (id + term_name) or null when none is active.
     *
     * @return object|null
     */
    public static function active_term()
    {
        if (array_key_exists('active_term', self::$cache)) {
            return self::$cache['active_term'];
        }

        $row = HST_Terms::active();

        return self::$cache['active_term'] = $row ?: null;
    }

    /**
     * Manager overview: a small set of actionable KPIs plus a couple of
     * "worth a glance" lists. Returns a fully-formed array the template can
     * render without any further database access.
     *
     * @return array<string,mixed>
     */
    public static function manager_overview(): array
    {
        if (array_key_exists('manager_overview', self::$cache)) {
            return self::$cache['manager_overview'];
        }

        global $wpdb;

        $term = self::active_term();
        $term_id = $term ? (int) $term->id : 0;

        $p = $wpdb->prefix;

        // --- KPIs -----------------------------------------------------------
        $classes_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}hst_classes");

        $students_count = 0;
        $teachers_count = 0;
        if ($term_id) {
            $students_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT user_id) FROM {$p}hst_users_classes WHERE term_id = %d AND role = 'student'",
                    $term_id
                )
            );
            // Count all registered teacher users, not only teachers assigned to a
            // class in the active term. This keeps the dashboard card aligned with
            // the teachers list and with bulk-imported teachers that may not have
            // classes/lessons assigned yet.
            $teachers_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT u.ID)
                     FROM {$wpdb->users} u
                     INNER JOIN {$wpdb->usermeta} rolemeta
                        ON rolemeta.user_id = u.ID
                       AND rolemeta.meta_key = %s
                       AND rolemeta.meta_value LIKE %s",
                    $p . 'capabilities',
                    '%"teacher"%'
                )
            );
        }

        // Today's attendance rate (present + late + excused counted as "in").
        $today = current_time('Y-m-d');
        $attendance_rate = null;
        if ($term_id) {
            $totals = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN status IN ('present','late','excused') THEN 1 ELSE 0 END) AS present
                     FROM {$p}hst_attendance_records
                     WHERE term_id = %d AND attendance_date = %s",
                    $term_id,
                    $today
                )
            );
            $total = $totals ? (int) $totals->total : 0;
            if ($total > 0) {
                $present = $totals ? (int) $totals->present : 0;
                $attendance_rate = (int) round($present / $total * 100);
            }
        }

        // Unpaid tuition invoices (pending or overdue) in the active term.
        $unpaid_invoices = 0;
        if ($term_id) {
            $unpaid_invoices = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}hst_tuition_invoices WHERE term_id = %d AND status IN ('pending','overdue')",
                    $term_id
                )
            );
        }


        $sms_balance = [
            'available_balance' => '',
            'locked_balance' => '',
            'total_balance' => '',
            'unit' => '',
            'plan_title' => '',
            'error' => '',
        ];

        if (class_exists('HST_SMS')) {
            $wallet = HST_SMS::cached_wallet_summary(300);
            if (is_wp_error($wallet)) {
                $sms_balance['error'] = $wallet->get_error_message();
            } elseif (is_array($wallet)) {
                $sms_balance = array_merge($sms_balance, $wallet);
            }
        }

        $overview = [
            'term'                 => $term,
            'classes_count'        => $classes_count,
            'students_count'       => $students_count,
            'teachers_count'       => $teachers_count,
            'attendance_rate'      => $attendance_rate, // null when no data today
            'sms_balance'          => $sms_balance,
            'unpaid_invoices'      => $unpaid_invoices,
        ];

        return self::$cache['manager_overview'] = $overview;
    }

    /**
     * Teacher overview: KPIs scoped to the current teacher in the active term.
     *
     * @return array<string,mixed>
     */
    public static function teacher_overview(int $teacher_id): array
    {
        $key = 'teacher_overview_' . $teacher_id;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $term = self::active_term();
        $term_id = $term ? (int) $term->id : 0;

        $classes_count = 0;
        $lessons_count = 0;
        $students_count = 0;
        $periods_count = 0;

        if ($term_id) {
            $classes_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT class_id) FROM {$p}hst_users_lessons WHERE user_id = %d AND term_id = %d AND role = 'teacher'",
                $teacher_id, $term_id
            ));
            $lessons_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT lesson_id) FROM {$p}hst_users_lessons WHERE user_id = %d AND term_id = %d AND role = 'teacher'",
                $teacher_id, $term_id
            ));
            // Distinct students sharing any class the teacher teaches.
            $students_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT uc.user_id)
                 FROM {$p}hst_users_classes uc
                 WHERE uc.term_id = %d AND uc.role = 'student'
                   AND uc.class_id IN (
                       SELECT DISTINCT class_id FROM {$p}hst_users_lessons
                       WHERE user_id = %d AND term_id = %d AND role = 'teacher'
                   )",
                $term_id, $teacher_id, $term_id
            ));
            $periods_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}hst_schedules WHERE teacher_id = %d AND term_id = %d",
                $teacher_id, $term_id
            ));
        }

        return self::$cache[$key] = [
            'term'           => $term,
            'classes_count'  => $classes_count,
            'lessons_count'  => $lessons_count,
            'students_count' => $students_count,
            'periods_count'  => $periods_count,
        ];
    }

    /**
     * Student overview: KPIs scoped to the current student in the active term.
     *
     * @return array<string,mixed>
     */
    public static function student_overview(int $student_id): array
    {
        $key = 'student_overview_' . $student_id;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $term = self::active_term();
        $term_id = $term ? (int) $term->id : 0;

        $classes_count = 0;
        $lessons_count = 0;
        $today_periods = 0;

        if ($term_id) {
            $classes_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT class_id) FROM {$p}hst_users_classes WHERE user_id = %d AND term_id = %d AND role = 'student'",
                $student_id, $term_id
            ));
            $lessons_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT lesson_id) FROM {$p}hst_users_lessons WHERE user_id = %d AND term_id = %d AND role = 'student'",
                $student_id, $term_id
            ));

            // Periods scheduled today for the student's classes.
            $weekday_map = [0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday'];
            $today_key = $weekday_map[(int) current_time('w')] ?? '';
            if ($today_key) {
                $today_periods = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}hst_schedules
                     WHERE term_id = %d AND day_of_week = %s
                       AND class_id IN (
                           SELECT class_id FROM {$p}hst_users_classes
                           WHERE user_id = %d AND term_id = %d AND role = 'student'
                       )",
                    $term_id, $today_key, $student_id, $term_id
                ));
            }

            $today = current_time('Y-m-d');
            $exams_limit = 5;
}

        return self::$cache[$key] = [
            'term'           => $term,
            'classes_count'  => $classes_count,
            'lessons_count'  => $lessons_count,
            'today_periods'  => $today_periods,
        ];
    }
}
