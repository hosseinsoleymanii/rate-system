<?php

defined('ABSPATH') || exit;

/**
 * Bulk term transfer & promotion (انتقال سال تحصیلی).
 *
 * The manager maps each class in a source term to either a destination class
 * in a target term (promotion) or to "graduate". For every student enrolled in
 * a source class:
 *   - transfer: create a new users_classes assignment in the target term for
 *     the destination class, and auto-enroll them in all lessons defined for
 *     that destination class.
 *   - graduate: mark the student as graduated (meta) — used for the highest
 *     grade (e.g. دوازدهم) that has no higher grade to move to.
 *
 * Previous-term data (scores, attendance, old assignments) is left untouched;
 * only new assignments are created. Existing target-term assignments are not
 * duplicated.
 */
class HST_Term_Transfer
{
    const GRADUATED_META       = 'hst_graduated';

    public function __construct()
    {
        add_action('wp_ajax_hst_transfer_source_classes', [$this, 'ajax_source_classes']);
        add_action('wp_ajax_hst_transfer_class_students', [$this, 'ajax_class_students']);
        add_action('wp_ajax_hst_transfer_execute', [$this, 'ajax_execute']);
    }

    /** Classes that actually have students in the given (source) term + counts. */
    public function ajax_source_classes(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
        global $wpdb;

        $term_id = HST_Guard::post_int('term_id');
        if (!$term_id) {
            HST_Guard::fail('سال تحصیلی مبدأ را انتخاب کنید.');
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT uc.class_id, c.class_name, COUNT(DISTINCT uc.user_id) AS student_count
             FROM {$wpdb->prefix}hst_users_classes uc
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = uc.class_id
             WHERE uc.term_id = %d AND uc.role = 'student' AND c.class_name NOT LIKE %s
             GROUP BY uc.class_id, c.class_name
             ORDER BY c.class_name ASC",
            $term_id,
            '%دوازدهم%'
        )) ?: [];

        $rows = HST_Classes::sort_rows($rows);

        $classes = [];
        foreach ($rows as $r) {
            $classes[] = [
                'class_id'      => (int) $r->class_id,
                'class_name'    => $r->class_name,
                'student_count' => (int) $r->student_count,
            ];
        }

        // All classes (potential destinations) + suggested promotion guess.
        $all = array_map(static function ($c) {
            return ['id' => (int) $c->id, 'name' => $c->class_name];
        }, HST_Classes::all());

        wp_send_json_success([
            'source_classes' => $classes,
            'all_classes'    => $all,
        ]);
    }


    /** Students of one source class in one source academic year. */
    public function ajax_class_students(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
        global $wpdb;

        $term_id = HST_Guard::post_int('term_id');
        $class_id = HST_Guard::post_int('class_id');

        if (!$term_id || !$class_id) {
            HST_Guard::fail('سال تحصیلی و کلاس مبدأ نامعتبر است.');
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT u.ID,
                    u.display_name,
                    first_name.meta_value AS first_name,
                    last_name.meta_value AS last_name,
                    national.meta_value AS national_code,
                    student_code.meta_value AS student_code
             FROM {$wpdb->prefix}hst_users_classes uc
             INNER JOIN {$wpdb->users} u ON u.ID = uc.user_id
             LEFT JOIN {$wpdb->usermeta} first_name
                ON first_name.user_id = u.ID AND first_name.meta_key = 'first_name'
             LEFT JOIN {$wpdb->usermeta} last_name
                ON last_name.user_id = u.ID AND last_name.meta_key = 'last_name'
             LEFT JOIN {$wpdb->usermeta} national
                ON national.user_id = u.ID AND national.meta_key = 'hst_national_code'
             LEFT JOIN {$wpdb->usermeta} student_code
                ON student_code.user_id = u.ID AND student_code.meta_key = 'hst_student_code'
             WHERE uc.class_id = %d
               AND uc.term_id = %d
               AND uc.role = 'student'
             ORDER BY last_name.meta_value ASC, first_name.meta_value ASC, u.display_name ASC",
            $class_id,
            $term_id
        )) ?: [];

        $students = [];
        foreach ($rows as $row) {
            $first = trim((string) ($row->first_name ?? ''));
            $last = trim((string) ($row->last_name ?? ''));
            $name = trim($first . ' ' . $last);
            if ($name === '') {
                $name = (string) ($row->display_name ?? '');
            }

            $avatar_id = absint(get_user_meta((int) $row->ID, 'hst_profile_avatar_id', true));
            if (!$avatar_id && class_exists('HST_Avatar_Approval')) {
                $avatar_id = (int) HST_Avatar_Approval::display_avatar_id((int) $row->ID, get_current_user_id());
            }

            $students[] = [
                'id'            => (int) $row->ID,
                'name'          => $name,
                'first_name'    => $first,
                'last_name'     => $last,
                'initials'      => $this->user_initials($first, $last, $name),
                'national_code' => (string) ($row->national_code ?: $row->student_code ?: ''),
                'avatar_url'    => $avatar_id ? (string) wp_get_attachment_image_url($avatar_id, 'thumbnail') : '',
            ];
        }

        wp_send_json_success([
            'students' => $students,
        ]);
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

    /** Check whether a class is twelfth grade. This is a backend safety guard. */
    private function is_twelfth_class(int $class_id): bool
    {
        if (!$class_id) {
            return false;
        }

        global $wpdb;
        $class_name = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT class_name FROM {$wpdb->prefix}hst_classes WHERE id = %d LIMIT 1",
            $class_id
        ));

        return $class_name !== '' && strpos($class_name, 'دوازدهم') !== false;
    }

    /**
     * Remove any student who belongs to a twelfth-grade class in the source
     * academic year. This is stricter than hiding twelfth classes in the UI and
     * prevents accidental promotion of twelfth-grade students even if stale or
     * manipulated payloads reach the backend.
     */
    private function filter_non_twelfth_students(array $student_ids, int $source_term): array
    {
        $student_ids = array_values(array_unique(array_filter(array_map('absint', $student_ids))));
        if (empty($student_ids) || !$source_term) {
            return [];
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($student_ids), '%d'));
        $params = array_merge([$source_term], $student_ids);

        $twelfth_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT uc.user_id
             FROM {$wpdb->prefix}hst_users_classes uc
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = uc.class_id
             WHERE uc.term_id = %d
               AND uc.role = 'student'
               AND uc.user_id IN ($placeholders)
               AND c.class_name LIKE '%%دوازدهم%%'",
            ...$params
        )) ?: [];

        $twelfth_ids = array_map('intval', $twelfth_ids);
        if (empty($twelfth_ids)) {
            return $student_ids;
        }

        return array_values(array_diff($student_ids, $twelfth_ids));
    }

    /**
     * Execute the mapping.
     * Expects: source_term_id, target_term_id, map => { source_class_id: dest_class_id }
     */
    public function ajax_execute(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
        global $wpdb;

        $source_term = HST_Guard::post_int('source_term_id');
        $target_term = HST_Guard::post_int('target_term_id');
        $map = wp_unslash($_POST['map'] ?? []);
        $excluded_students = wp_unslash($_POST['excluded_students'] ?? []);

        if (!$source_term) {
            HST_Guard::fail('سال تحصیلی مبدأ را انتخاب کنید.');
        }
        if (!is_array($map) || empty($map)) {
            HST_Guard::fail('برای حداقل یک کلاس، مقصد را تعیین کنید.');
        }

        if (!$target_term) {
            HST_Guard::fail('سال تحصیلی مقصد را انتخاب کنید.');
        }
        if ($target_term === $source_term) {
            HST_Guard::fail('سال تحصیلی مقصد باید با سال تحصیلی مبدأ متفاوت باشد.');
        }


        $excluded_by_class = [];
        if (is_array($excluded_students)) {
            foreach ($excluded_students as $class_id => $ids) {
                $class_id = absint($class_id);
                if (!$class_id || !is_array($ids)) {
                    continue;
                }
                $excluded_by_class[$class_id] = array_values(array_unique(array_filter(array_map('absint', $ids))));
            }
        }

        $now = current_time('mysql');
        $uc = $wpdb->prefix . 'hst_users_classes';
        $ul = $wpdb->prefix . 'hst_users_lessons';

        $transferred = 0;
        $graduated = 0;
        $skipped = 0;
        $details = [];

        // Cache lessons per destination class.
        $lessons_cache = [];
        $get_lessons = function (int $class_id) use (&$lessons_cache, $wpdb) {
            if (!isset($lessons_cache[$class_id])) {
                $lessons_cache[$class_id] = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, COALESCE(unit, 1) AS unit FROM {$wpdb->prefix}hst_lessons WHERE class_id = %d",
                    $class_id
                )) ?: [];
            }
            return $lessons_cache[$class_id];
        };

        foreach ($map as $source_class_id => $dest) {
            $source_class_id = absint($source_class_id);
            if (!$source_class_id) { continue; }

            // Absolute backend guard: twelfth-grade classes are never promoted.
            if ($this->is_twelfth_class($source_class_id)) {
                continue;
            }

            // Students in this source class for the source term.
            $student_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$uc} WHERE class_id = %d AND term_id = %d AND role = 'student'",
                $source_class_id, $source_term
            ));
            $student_ids = array_map('intval', $student_ids ?: []);
            $student_ids = $this->filter_non_twelfth_students($student_ids, $source_term);

            $excluded_for_class = $excluded_by_class[$source_class_id] ?? [];
            if (!empty($excluded_for_class)) {
                $student_ids = array_values(array_diff($student_ids, $excluded_for_class));
                $skipped += count($excluded_for_class);
            }

            if (empty($student_ids)) { continue; }

            if ($dest === 'graduate') {
                $skipped += count($student_ids);
                $details[] = 'گزینه فارغ‌التحصیلی در انتقال سال تحصیلی غیرفعال است و این کلاس رد شد.';
                continue;
            }

            $dest_class_id = absint($dest);
            if (!$dest_class_id || !HST_Classes::exists($dest_class_id)) {
                $skipped += count($student_ids);
                continue;
            }

            $lessons = $get_lessons($dest_class_id);

            foreach ($student_ids as $sid) {
                // Skip if already assigned to this class in the target term.
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$uc} WHERE user_id = %d AND class_id = %d AND term_id = %d AND role = 'student' LIMIT 1",
                    $sid, $dest_class_id, $target_term
                ));
                if ($exists) { $skipped++; continue; }

                $wpdb->insert($uc, [
                    'user_id' => $sid,
                    'class_id' => $dest_class_id,
                    'term_id' => $target_term,
                    'role' => 'student',
                    'enrollment_date' => $now,
                ], ['%d','%d','%d','%s','%s']);

                // Auto-enroll in all destination-class lessons.
                foreach ($lessons as $lesson) {
                    $already = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$ul} WHERE user_id = %d AND lesson_id = %d AND term_id = %d AND role = 'student' LIMIT 1",
                        $sid, (int) $lesson->id, $target_term
                    ));
                    if ($already) { continue; }
                    $wpdb->insert($ul, [
                        'user_id' => $sid,
                        'class_id' => $dest_class_id,
                        'term_id' => $target_term,
                        'lesson_id' => (int) $lesson->id,
                        'lesson_unit' => (int) $lesson->unit,
                        'role' => 'student',
                        'enrollment_date' => $now,
                    ], ['%d','%d','%d','%d','%d','%s','%s']);
                }

                // A promoted student is (re)activated in case they were graduated before.
                delete_user_meta($sid, self::GRADUATED_META);
                $transferred++;
            }
        }

        wp_send_json_success([
            'message'     => 'عملیات انتقال با موفقیت انجام شد.',
            'transferred' => $transferred,
            'graduated'   => $graduated,
            'skipped'     => $skipped,
            'details'     => $details,
        ]);
    }
}
