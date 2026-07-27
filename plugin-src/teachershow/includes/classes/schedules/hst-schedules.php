<?php

defined('ABSPATH') || exit;

class HST_Schedules
{
    private const DAYS = [
        'saturday'  => 'شنبه',
        'sunday'    => 'یکشنبه',
        'monday'    => 'دوشنبه',
        'tuesday'   => 'سه‌شنبه',
        'wednesday' => 'چهارشنبه',
    ];

    private const SHIFTS = [1, 2, 3, 4];
    private const DISCIPLINE_LESSON_NAME = 'انضباط';


    public function __construct()
    {
        add_action('wp_ajax_hst_schedule_generate_all_start', [$this, 'generate_all_schedules_start']);
        add_action('wp_ajax_hst_schedule_generate_all_step', [$this, 'generate_all_schedules_step']);
        add_action('wp_ajax_hst_schedule_save_options', [$this, 'save_schedule_options']);
        add_action('wp_ajax_hst_schedule_assignment_context', [$this, 'assignment_context']);
        add_action('wp_ajax_hst_schedule_teacher_profile', [$this, 'teacher_profile']);
        add_action('wp_ajax_hst_schedule_lessons_for_teacher', [$this, 'lessons_for_teacher']);
        add_action('wp_ajax_hst_schedule_save_teacher_assignment', [$this, 'save_teacher_assignment']);
    }

    private function authorize_ajax()
    {
        HST_Guard::verify_ajax('hst_manage_school');
    }

    public static function days()
    {
        return self::DAYS;
    }

    public static function generation_status($term_id): array
    {
        global $wpdb;

        $term_id = absint($term_id);
        $status = [
            'can_generate'           => false,
            'teacher_count'          => 0,
            'assigned_teacher_count' => 0,
            'assignment_count'       => 0,
            'message'                => 'برای تولید برنامه، ابتدا یک سال تحصیلی فعال تعریف کنید.',
        ];

        if (!$term_id) {
            return $status;
        }

        $cap_key = $wpdb->prefix . 'capabilities';
        $teacher_like = '%"teacher"%';

        $status['teacher_count'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT u.ID)
                 FROM {$wpdb->users} u
                 INNER JOIN {$wpdb->usermeta} rolemeta
                    ON rolemeta.user_id = u.ID
                   AND rolemeta.meta_key = %s
                   AND rolemeta.meta_value LIKE %s",
                $cap_key,
                $teacher_like
            )
        );

        if ($status['teacher_count'] < 1) {
            $status['message'] = 'برای تولید برنامه، ابتدا حداقل یک دبیر تعریف کنید.';
            return $status;
        }

        $assignment_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT ul.id) AS assignment_count,
                        COUNT(DISTINCT ul.user_id) AS assigned_teacher_count
                 FROM {$wpdb->prefix}hst_users_lessons ul
                 INNER JOIN {$wpdb->users} u ON u.ID = ul.user_id
                 INNER JOIN {$wpdb->usermeta} rolemeta
                    ON rolemeta.user_id = u.ID
                   AND rolemeta.meta_key = %s
                   AND rolemeta.meta_value LIKE %s
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = ul.lesson_id
                 WHERE ul.term_id = %d
                   AND ul.role = 'teacher'
                   AND COALESCE(ul.lesson_unit, 0) > 0
                   AND TRIM(l.lesson_name) <> %s",
                $cap_key,
                $teacher_like,
                $term_id,
                self::DISCIPLINE_LESSON_NAME
            ),
            ARRAY_A
        ) ?: [];

        $status['assignment_count'] = (int) ($assignment_row['assignment_count'] ?? 0);
        $status['assigned_teacher_count'] = (int) ($assignment_row['assigned_teacher_count'] ?? 0);

        if ($status['assignment_count'] < 1) {
            $status['message'] = 'برای تولید برنامه، ابتدا حداقل یک درس را به یکی از دبیران تخصیص دهید.';
            return $status;
        }

        $status['can_generate'] = true;
        $status['message'] = 'تولید برنامه';

        return $status;
    }

    private function ensure_generation_ready($term_id): void
    {
        $status = self::generation_status($term_id);

        if (empty($status['can_generate'])) {
            wp_send_json_error([
                'message' => (string) $status['message'],
                'generation_status' => $status,
            ]);
        }
    }

    private function schedule_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_schedules';
    }

    private function class_exists($class_id)
    {
        return HST_Classes::exists($class_id);
    }

    private function term_exists($term_id)
    {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hst_terms WHERE id = %d",
                absint($term_id)
            )
        );
    }

        private function start_transaction()
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
    }

    private function commit_transaction()
    {
        global $wpdb;
        $wpdb->query('COMMIT');
    }

    private function rollback_transaction()
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
    }

        private function sanitize_schedule_options($raw = null)
    {
        $raw = is_array($raw) ? $raw : [];

        $blocked_slots = [];
        $posted_blocked = $raw['blocked_slots'] ?? [];

        if (is_string($posted_blocked)) {
            $posted_blocked = array_filter(array_map('trim', explode(',', $posted_blocked)));
        }

        if (is_array($posted_blocked)) {
            foreach ($posted_blocked as $key => $value) {
                /*
                 * blocked_slots may arrive in two shapes:
                 * 1) From AJAX form data: ["wednesday_4", "saturday_1"]
                 * 2) From saved/transient options after sanitizing: ["wednesday_4" => true]
                 *
                 * The previous code only read values, so saved/transient options became
                 * [true, true] and all blocked slots were silently lost in async steps.
                 */
                $slot = is_string($key) && !is_numeric($key) ? $key : $value;
                $slot = sanitize_text_field((string) $slot);

                if (!preg_match('/^([a-z]+)_(\d)$/', $slot, $matches)) {
                    continue;
                }

                $day = sanitize_key($matches[1]);
                $shift = absint($matches[2]);

                if (isset(self::DAYS[$day]) && in_array($shift, self::SHIFTS, true)) {
                    $blocked_slots[$day . '_' . $shift] = true;
                }
            }
        }

        return [
            'ignore_teacher_shift_availability' => !empty($raw['ignore_teacher_shift_availability']),
            'prefer_early_shifts'               => !empty($raw['prefer_early_shifts']),
            'blocked_slots'                     => $blocked_slots,
        ];
    }



    private function schedule_options_user_meta_key($term_id)
    {
        return 'hst_schedule_options_' . absint($term_id);
    }

    private function get_saved_schedule_options($term_id)
    {
        $term_id = absint($term_id);
        if (!$term_id) {
            return $this->sanitize_schedule_options([]);
        }

        $saved = get_user_meta(get_current_user_id(), $this->schedule_options_user_meta_key($term_id), true);
        return $this->sanitize_schedule_options(is_array($saved) ? $saved : []);
    }

    private function persist_schedule_options($term_id, array $options)
    {
        $term_id = absint($term_id);
        $options = $this->sanitize_schedule_options($options);

        if ($term_id) {
            update_user_meta(get_current_user_id(), $this->schedule_options_user_meta_key($term_id), $options);
        }

        return $options;
    }

    private function is_slot_blocked_by_options($day, $shift, array $options)
    {
        $key = sanitize_key($day) . '_' . absint($shift);
        return !empty($options['blocked_slots'][$key]);
    }

    private function teacher_available_for_slot($teacher_id, $day, $shift, array $availability, array $options)
    {
        $teacher_id = (int) $teacher_id;
        $day = sanitize_key($day);
        $shift = absint($shift);
        $slot_key = $day . '_' . $shift;

        if ($this->is_slot_blocked_by_options($day, $shift, $options)) {
            return false;
        }

        if (empty($options['ignore_teacher_shift_availability'])) {
            return !empty($availability[$teacher_id][$slot_key]);
        }

        foreach (self::SHIFTS as $available_shift) {
            if (!empty($availability[$teacher_id][$day . '_' . (int) $available_shift])) {
                return true;
            }
        }

        return false;
    }

    private function shift_preference_penalty($shift, array $options)
    {
        $shift = absint($shift);

        if (empty($options['prefer_early_shifts'])) {
            return $shift;
        }

        if ($shift >= 4) {
            return 100 + $shift;
        }

        return $shift;
    }


        private function get_classes()
    {
        return HST_Classes::all_by_name();
    }

    private function get_class_name($class_id)
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT class_name FROM {$wpdb->prefix}hst_classes WHERE id = %d",
                $class_id
            )
        );
    }

    private function get_saved_schedule($class_id, $term_id, array $options = [])
    {
        $options = $this->sanitize_schedule_options($options);
        global $wpdb;

        $schedule_table = $this->schedule_table();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.*, l.lesson_name, u.display_name AS teacher_name
                 FROM {$schedule_table} s
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON s.lesson_id = l.id
                 INNER JOIN {$wpdb->users} u ON s.teacher_id = u.ID
                 WHERE s.class_id = %d AND s.term_id = %d
                 ORDER BY FIELD(s.day_of_week, 'saturday','sunday','monday','tuesday','wednesday'), s.school_shift, s.week_type",
                $class_id,
                $term_id
            )
        ) ?: [];

        foreach ($rows as $row) {
            $row->unit_size = $row->week_type === 'every' ? 2 : 1;
            $row->valid_slots = $this->valid_slots_payload((int) $row->teacher_id, $term_id, $class_id, $options);
        }

        return $rows;
    }

    private function get_teacher_availability_map($term_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, day_of_week, school_shift
                 FROM {$wpdb->prefix}hst_users_availability
                 WHERE term_id = %d AND role = 'teacher'",
                $term_id
            )
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->user_id][$row->day_of_week . '_' . (int) $row->school_shift] = true;
        }

        return $map;
    }

    private function get_teacher_busy_map($term_id, $exclude_class_id = 0)
    {
        global $wpdb;

        $schedule_table = $this->schedule_table();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT teacher_id, day_of_week, school_shift, week_type
                 FROM {$schedule_table}
                 WHERE term_id = %d AND class_id != %d",
                $term_id,
                $exclude_class_id
            )
        );

        $busy = [];

        foreach ($rows as $row) {
            $key = $row->day_of_week . '_' . (int) $row->school_shift;
            $busy[(int) $row->teacher_id][$key][] = $row->week_type;
        }

        return $busy;
    }

    private function teacher_is_busy(array $busy_map, $teacher_id, $day, $shift, $week_type)
    {
        $key = $day . '_' . $shift;
        $busy_types = $busy_map[$teacher_id][$key] ?? [];

        if (!$busy_types) {
            return false;
        }

        if ($week_type === 'every') {
            return true;
        }

        return in_array('every', $busy_types, true) || in_array($week_type, $busy_types, true);
    }

        private function get_valid_slots_for_teacher($teacher_id, $term_id, $class_id, $week_type = 'every', array $options = [])
    {
        $options = $this->sanitize_schedule_options($options);
        $availability = $this->get_teacher_availability_map($term_id);
        $busy = $this->get_teacher_busy_map($term_id, $class_id);
        $slots = [];

        foreach (array_keys(self::DAYS) as $day) {
            foreach (self::SHIFTS as $shift) {
                $key = $day . '_' . $shift;

                if (!$this->teacher_available_for_slot($teacher_id, $day, $shift, $availability, $options)) {
                    continue;
                }

                if ($this->teacher_is_busy($busy, $teacher_id, $day, $shift, $week_type)) {
                    continue;
                }

                $slots[] = $key;
            }
        }

        return $slots;
    }

    private function valid_slots_payload($teacher_id, $term_id, $class_id, array $options = [])
    {
        return [
            'every' => $this->get_valid_slots_for_teacher($teacher_id, $term_id, $class_id, 'every', $options),
            'odd'   => $this->get_valid_slots_for_teacher($teacher_id, $term_id, $class_id, 'odd', $options),
            'even'  => $this->get_valid_slots_for_teacher($teacher_id, $term_id, $class_id, 'even', $options),
        ];
    }

    private function get_class_lesson_requirements($class_id, $term_id, array $options = [])
    {
        $options = $this->sanitize_schedule_options($options);
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    l.id AS lesson_id,
                    l.lesson_name,
                    l.unit AS lesson_unit,
                    ul.user_id AS teacher_id,
                    u.display_name AS teacher_name,
                    SUM(ul.lesson_unit) AS teacher_units
                 FROM {$wpdb->prefix}hst_lessons l
                 INNER JOIN {$wpdb->prefix}hst_users_lessons ul
                    ON l.id = ul.lesson_id
                    AND ul.role = 'teacher'
                    AND ul.term_id = %d
                    AND ul.class_id = %d
                 INNER JOIN {$wpdb->users} u ON ul.user_id = u.ID
                 WHERE l.class_id = %d
                   AND TRIM(l.lesson_name) <> %s
                 GROUP BY l.id, ul.user_id
                 ORDER BY l.lesson_name ASC",
                $term_id,
                $class_id,
                $class_id,
                self::DISCIPLINE_LESSON_NAME
            )
        );

        $requirements = [];

        foreach ($rows as $row) {
            $units = max(1, (int) $row->teacher_units);

            while ($units >= 2) {
                $requirements[] = [
                    'class_id'     => (int) $class_id,
                    'class_name'   => $this->get_class_name($class_id),
                    'lesson_id'    => (int) $row->lesson_id,
                    'lesson_name'  => $row->lesson_name,
                    'teacher_id'   => (int) $row->teacher_id,
                    'teacher_name' => $row->teacher_name,
                    'unit_size'    => 2,
                    'week_type'    => 'every',
                    'valid_slots'  => $this->valid_slots_payload((int) $row->teacher_id, $term_id, $class_id, $options),
                ];
                $units -= 2;
            }

            if ($units === 1) {
                $requirements[] = [
                    'pool_uid'     => 'lesson-' . (int) $row->lesson_id . '-teacher-' . (int) $row->teacher_id . '-unit-' . count($requirements),
                    'class_id'     => (int) $class_id,
                    'class_name'   => $this->get_class_name($class_id),
                    'lesson_id'    => (int) $row->lesson_id,
                    'lesson_name'  => $row->lesson_name,
                    'teacher_id'   => (int) $row->teacher_id,
                    'teacher_name' => $row->teacher_name,
                    'unit_size'    => 1,
                    'week_type'    => 'odd',
                    'valid_slots'  => $this->valid_slots_payload((int) $row->teacher_id, $term_id, $class_id, $options),
                ];
            }
        }

        return $requirements;
    }

    
    private function get_schedule_requirements_for_classes(array $class_ids, $term_id, array $options = [])
    {
        $requirements = [];

        foreach ($class_ids as $class_id) {
            $class_requirements = $this->get_class_lesson_requirements((int) $class_id, $term_id, $options);
            foreach ($class_requirements as $item) {
                $requirements[] = $item;
            }
        }

        return $requirements;
    }

    private function auto_generate_for_classes(array $class_ids, $term_id, array $options = [])
    {
        $options = $this->sanitize_schedule_options($options);
        $class_ids = array_values(array_unique(array_filter(array_map('absint', $class_ids))));
        $requirements = $this->get_schedule_requirements_for_classes($class_ids, $term_id, $options);

        if (!$requirements) {
            return [
                'entries' => [],
                'warnings' => ['برای کلاس‌های انتخاب‌شده در این سال تحصیلی، درس قابل برنامه‌ریزی با معلم ثبت‌شده پیدا نشد.'],
                'checked_possibilities' => 0,
                'is_complete' => false,
                'class_summary' => [],
];
        }

        $availability = $this->get_teacher_availability_map($term_id);
        $warnings = [];
        $days = array_keys(self::DAYS);
        $slot_order = [];

        foreach ($days as $day) {
            foreach (self::SHIFTS as $shift) {
                $slot_order[] = $day . '_' . $shift;
            }
        }

        foreach ($requirements as $index => &$item) {
            $item['requirement_uid'] = 'global-req-' . $index . '-' . (int) $item['class_id'] . '-' . (int) $item['lesson_id'] . '-' . (int) $item['teacher_id'];
            $item['candidate_slots'] = [];
            $week_options = ((int) $item['unit_size'] === 2) ? ['every'] : ['odd', 'even'];

            foreach ($slot_order as $slot_key) {
                [$day, $shift] = explode('_', $slot_key);
                $shift = (int) $shift;

                if (!$this->teacher_available_for_slot((int) $item['teacher_id'], $day, $shift, $availability, $options)) {
                    continue;
                }

                foreach ($week_options as $week_type) {
                    $item['candidate_slots'][] = [
                        'day_of_week'  => $day,
                        'school_shift' => $shift,
                        'week_type'    => $week_type,
                    ];
                }
            }

            if (empty($item['candidate_slots'])) {
                $warnings[] = sprintf(
                    'برای کلاس «%s» درس «%s» با معلم «%s» هیچ زمان مجازی مطابق دسترسی معلم پیدا نشد.',
                    $item['class_name'] ?? ('کلاس ' . (int) $item['class_id']),
                    $item['lesson_name'],
                    $item['teacher_name']
                );
            }
        }
        unset($item);

        $target_score = array_sum(array_map(function ($item) {
            return (int) $item['unit_size'];
        }, $requirements));

        $max_checks = max(50000, (int) apply_filters('hst_schedule_global_generator_max_checks', 4000000, $term_id, $class_ids));
        $max_attempts = max(6, (int) apply_filters('hst_schedule_global_generator_attempts', 18, $term_id, $class_ids));
        $checks = 0;
        $best_entries = [];
        $best_units = -1;
        $best_soft_score = PHP_INT_MIN;

        $entry_units = function (array $entries) {
            $score = 0;
            foreach ($entries as $entry) {
                $score += ($entry['week_type'] === 'every') ? 2 : 1;
            }
            return $score;
        };

        $remaining_units = function (array $items) {
            $score = 0;
            foreach ($items as $item) {
                $score += (int) $item['unit_size'];
            }
            return $score;
        };

        $soft_score = function (array $entries) {
            $class_day = [];
            $teacher_day = [];
            $class_shift_load = [];
            $score = 0;

            foreach ($entries as $entry) {
                $unit = ($entry['week_type'] === 'every') ? 2 : 1;
                $class_key = (int) $entry['class_id'] . ':' . $entry['day_of_week'];
                $teacher_key = (int) $entry['teacher_id'] . ':' . $entry['day_of_week'];
                $slot_key = (int) $entry['class_id'] . ':' . $entry['day_of_week'] . ':' . (int) $entry['school_shift'];

                $class_day[$class_key] = ($class_day[$class_key] ?? 0) + $unit;
                $teacher_day[$teacher_key] = ($teacher_day[$teacher_key] ?? 0) + $unit;
                $class_shift_load[$slot_key] = ($class_shift_load[$slot_key] ?? 0) + $unit;
            }

            foreach ($class_day as $load) {
                $score -= abs(4 - $load);
            }

            foreach ($teacher_day as $load) {
                if ($load > 4) {
                    $score -= (($load - 4) * 2);
                }
            }

            foreach ($class_shift_load as $load) {
                if ($load === 2) {
                    $score += 1;
                }
            }

            return $score;
        };

        $can_place = function (array $item, array $slot, array $teacher_busy, array $class_occupied) use ($options) {
            $teacher_id = (int) $item['teacher_id'];
            $class_id = (int) $item['class_id'];
            $day = $slot['day_of_week'];
            $shift = (int) $slot['school_shift'];
            $week_type = $slot['week_type'];
            $slot_key = $day . '_' . $shift;

            // Hard guard: even if a candidate list was built before options changed,
            // blocked slots must never be accepted by the planner.
            if ($this->is_slot_blocked_by_options($day, $shift, $options)) {
                return false;
            }

            if ($this->teacher_is_busy($teacher_busy, $teacher_id, $day, $shift, $week_type)) {
                return false;
            }

            $class_types = $class_occupied[$class_id][$slot_key] ?? [];

            if ($week_type === 'every') {
                return empty($class_types);
            }

            if (in_array('every', $class_types, true) || in_array($week_type, $class_types, true)) {
                return false;
            }

            return count($class_types) < 2;
        };

        $feasible_slots = function (array $item, array $teacher_busy, array $class_occupied) use ($can_place) {
            $slots = [];
            foreach ($item['candidate_slots'] as $slot) {
                if ($can_place($item, $slot, $teacher_busy, $class_occupied)) {
                    $slots[] = $slot;
                }
            }
            return $slots;
        };

        $place_entry = function (array $item, array $slot, array $entries, array $teacher_busy, array $class_occupied) {
            $slot_key = $slot['day_of_week'] . '_' . (int) $slot['school_shift'];
            $teacher_id = (int) $item['teacher_id'];
            $class_id = (int) $item['class_id'];
            $week_type = $slot['week_type'];

            $entries[] = [
                'class_id'     => $class_id,
                'class_name'   => $item['class_name'] ?? '',
                'day_of_week'  => $slot['day_of_week'],
                'school_shift' => (int) $slot['school_shift'],
                'lesson_id'    => (int) $item['lesson_id'],
                'lesson_name'  => $item['lesson_name'],
                'teacher_id'   => $teacher_id,
                'teacher_name' => $item['teacher_name'],
                'week_type'    => $week_type,
                'unit_size'    => $week_type === 'every' ? 2 : 1,
                'valid_slots'  => $item['valid_slots'] ?? [],
            ];

            $teacher_busy[$teacher_id][$slot_key][] = $week_type;
            $class_occupied[$class_id][$slot_key][] = $week_type;

            return [$entries, $teacher_busy, $class_occupied];
        };

        $build_attempt_items = function (array $requirements, $attempt) {
            $items = $requirements;

            foreach ($items as &$item) {
                if ($attempt > 1) {
                    $seed = crc32((string) $item['requirement_uid'] . '-' . $attempt);
                    usort($item['candidate_slots'], function ($a, $b) use ($seed) {
                        $ak = crc32($a['day_of_week'] . '-' . $a['school_shift'] . '-' . $a['week_type'] . '-' . $seed);
                        $bk = crc32($b['day_of_week'] . '-' . $b['school_shift'] . '-' . $b['week_type'] . '-' . $seed);
                        return $ak <=> $bk;
                    });
                }
            }
            unset($item);

            usort($items, function ($a, $b) use ($attempt) {
                $a_count = count($a['candidate_slots']);
                $b_count = count($b['candidate_slots']);

                if ($attempt % 3 === 0 && (int) $a['unit_size'] !== (int) $b['unit_size']) {
                    return (int) $b['unit_size'] <=> (int) $a['unit_size'];
                }

                if ($a_count !== $b_count) {
                    return $a_count <=> $b_count;
                }

                if ((int) $a['unit_size'] !== (int) $b['unit_size']) {
                    return (int) $b['unit_size'] <=> (int) $a['unit_size'];
                }

                if ($attempt > 1) {
                    $av = crc32(($a['requirement_uid'] ?? '') . '-' . $attempt);
                    $bv = crc32(($b['requirement_uid'] ?? '') . '-' . $attempt);
                    return $av <=> $bv;
                }

                $class_compare = HST_Classes::compare_names($a['class_name'] ?? '', $b['class_name'] ?? '');
                if ($class_compare !== 0) {
                    return $class_compare;
                }

                return strnatcasecmp((string) ($a['lesson_name'] ?? ''), (string) ($b['lesson_name'] ?? ''));
            });

            return $items;
        };

        $pick_next = function (array $items, array $teacher_busy, array $class_occupied) use ($feasible_slots) {
            $best_index = null;
            $best_slots = null;
            $best_pressure = null;

            foreach ($items as $index => $item) {
                $slots = $feasible_slots($item, $teacher_busy, $class_occupied);
                $pressure = count($item['candidate_slots']) + ((int) $item['unit_size'] === 2 ? -1 : 0);

                if ($best_slots === null || count($slots) < count($best_slots) || (count($slots) === count($best_slots) && $pressure < $best_pressure)) {
                    $best_index = $index;
                    $best_slots = $slots;
                    $best_pressure = $pressure;
                }

                if ($best_slots !== null && count($best_slots) === 0) {
                    break;
                }
            }

            return [$best_index, $best_slots ?: []];
        };

        $sort_slots = function (array $slots, array $teacher_busy, array $class_occupied, array $entries, array $item, $attempt) use ($options) {
            $options = $this->sanitize_schedule_options($options);
            usort($slots, function ($a, $b) use ($teacher_busy, $class_occupied, $entries, $item, $attempt, $options) {
                $a_key = $a['day_of_week'] . '_' . (int) $a['school_shift'];
                $b_key = $b['day_of_week'] . '_' . (int) $b['school_shift'];
                $teacher_id = (int) $item['teacher_id'];
                $class_id = (int) $item['class_id'];

                $a_load = count($teacher_busy[$teacher_id][$a_key] ?? []) + count($class_occupied[$class_id][$a_key] ?? []);
                $b_load = count($teacher_busy[$teacher_id][$b_key] ?? []) + count($class_occupied[$class_id][$b_key] ?? []);

                $a_day_load = 0;
                $b_day_load = 0;
                foreach ($entries as $entry) {
                    if ((int) $entry['class_id'] === $class_id && $entry['day_of_week'] === $a['day_of_week']) {
                        $a_day_load += (($entry['week_type'] ?? 'every') === 'every') ? 2 : 1;
                    }
                    if ((int) $entry['class_id'] === $class_id && $entry['day_of_week'] === $b['day_of_week']) {
                        $b_day_load += (($entry['week_type'] ?? 'every') === 'every') ? 2 : 1;
                    }
                }

                if ($a_load !== $b_load) {
                    return $a_load <=> $b_load;
                }

                if ($a_day_load !== $b_day_load) {
                    return $a_day_load <=> $b_day_load;
                }

                $a_shift_score = $this->shift_preference_penalty((int) $a['school_shift'], $options);
                $b_shift_score = $this->shift_preference_penalty((int) $b['school_shift'], $options);

                if ($attempt % 4 === 1 && empty($options['prefer_early_shifts']) && (int) $a['school_shift'] !== (int) $b['school_shift']) {
                    return (int) $b['school_shift'] <=> (int) $a['school_shift'];
                }

                if ($a_shift_score !== $b_shift_score) {
                    return $a_shift_score <=> $b_shift_score;
                }

                return strcmp($a['day_of_week'], $b['day_of_week']);
            });

            return $slots;
        };

        $commit_best = function (array $entries) use (&$best_entries, &$best_units, &$best_soft_score, $entry_units, $soft_score) {
            $units = $entry_units($entries);
            $soft = $soft_score($entries);

            if ($units > $best_units || ($units === $best_units && $soft > $best_soft_score)) {
                $best_units = $units;
                $best_soft_score = $soft;
                $best_entries = $entries;
            }
        };

        $greedy_repair_pass = function (array $items) use ($feasible_slots, $place_entry, $sort_slots, $commit_best, $target_score, $entry_units) {
            $entries = [];
            $teacher_busy = [];
            $class_occupied = [];
            $progress = true;

            while ($items && $progress) {
                $progress = false;
                usort($items, function ($a, $b) use ($feasible_slots, $teacher_busy, $class_occupied) {
                    $ac = count($feasible_slots($a, $teacher_busy, $class_occupied));
                    $bc = count($feasible_slots($b, $teacher_busy, $class_occupied));
                    if ($ac !== $bc) {
                        return $ac <=> $bc;
                    }
                    return (int) $b['unit_size'] <=> (int) $a['unit_size'];
                });

                foreach ($items as $idx => $item) {
                    $slots = $feasible_slots($item, $teacher_busy, $class_occupied);
                    if (!$slots) {
                        continue;
                    }

                    $slots = $sort_slots($slots, $teacher_busy, $class_occupied, $entries, $item, 99);
                    [$entries, $teacher_busy, $class_occupied] = $place_entry($item, $slots[0], $entries, $teacher_busy, $class_occupied);
                    unset($items[$idx]);
                    $items = array_values($items);
                    $progress = true;
                    $commit_best($entries);

                    if ($entry_units($entries) >= $target_score) {
                        return $entries;
                    }

                    break;
                }
            }

            return $entries;
        };

        $attempt_budget = max(2000, (int) floor($max_checks / $max_attempts));
        $attempt_offset = max(0, (int) apply_filters('hst_schedule_global_generator_attempt_offset', 0, $term_id, $class_ids));

        for ($attempt_index = 0; $attempt_index < $max_attempts; $attempt_index++) {
            $attempt = $attempt_offset + $attempt_index;
            if ($checks >= $max_checks || $best_units >= $target_score) {
                break;
            }

            $items = $build_attempt_items($requirements, $attempt);
            $attempt_checks = 0;

            $search = function (array $items, array $entries, array $teacher_busy, array $class_occupied) use (
                &$search,
                &$checks,
                &$attempt_checks,
                $attempt_budget,
                $max_checks,
                $target_score,
                $entry_units,
                $remaining_units,
                $pick_next,
                $sort_slots,
                $place_entry,
                $commit_best,
                $attempt
            ) {
                $checks++;
                $attempt_checks++;
                $current_units = $entry_units($entries);
                $commit_best($entries);

                if ($current_units >= $target_score || empty($items) || $checks >= $max_checks || $attempt_checks >= $attempt_budget) {
                    return;
                }

                [$picked_index, $slots] = $pick_next($items, $teacher_busy, $class_occupied);
                if ($picked_index === null) {
                    return;
                }

                $item = $items[$picked_index];
                $next_items = $items;
                array_splice($next_items, $picked_index, 1);
                $slots = $sort_slots($slots, $teacher_busy, $class_occupied, $entries, $item, $attempt);

                foreach ($slots as $slot) {
                    if ($checks >= $max_checks || $attempt_checks >= $attempt_budget) {
                        return;
                    }

                    [$next_entries, $next_teacher_busy, $next_class_occupied] = $place_entry($item, $slot, $entries, $teacher_busy, $class_occupied);
                    $search($next_items, $next_entries, $next_teacher_busy, $next_class_occupied);

                    if ($entry_units($next_entries) >= $target_score) {
                        return;
                    }
                }

                if ($checks < $max_checks && $attempt_checks < $attempt_budget) {
                    $search($next_items, $entries, $teacher_busy, $class_occupied);
                }
            };

            $search($items, [], [], []);

            if ($best_units < $target_score) {
                $greedy_entries = $greedy_repair_pass($items);
                $commit_best($greedy_entries);
            }
        }

        if ($best_units < $target_score) {
            $warnings[] = ($checks >= $max_checks)
                ? 'برنامه‌ریز به سقف ایمن بررسی حالت‌ها رسید و بهترین برنامه ممکن تا این لحظه ساخته شد.'
                : 'همه استراتژی‌های برنامه‌ریز امتحان شد، اما با محدودیت‌های فعلی هنوز تعدادی درس بدون جای مناسب باقی ماند.';
        }

        $class_summary = [];
        foreach ($class_ids as $class_id) {
            $class_total = 0;
            foreach ($requirements as $item) {
                if ((int) $item['class_id'] === (int) $class_id) {
                    $class_total += (int) $item['unit_size'];
                }
            }

            $class_planned = 0;
            foreach ($best_entries as $entry) {
                if ((int) $entry['class_id'] === (int) $class_id) {
                    $class_planned += ($entry['week_type'] === 'every') ? 2 : 1;
                }
            }

            $class_summary[] = [
                'class_id' => (int) $class_id,
                'class_name' => $this->get_class_name($class_id),
                'planned_units' => $class_planned,
                'total_units' => $class_total,
                'is_complete' => $class_total > 0 && $class_planned >= $class_total,
            ];
        }
return [
            'entries' => array_values($best_entries),
            'warnings' => array_values(array_unique($warnings)),
'checked_possibilities' => $checks,
            'is_complete' => ($best_units >= $target_score),
            'class_summary' => $class_summary,
        ];
    }

    private function save_generated_entries_for_classes($term_id, array $class_ids, array $entries)
    {
        global $wpdb;
        $table = $this->schedule_table();
        $class_ids = array_values(array_unique(array_filter(array_map('absint', $class_ids))));

        if (!$class_ids) {
            return;
        }

        $this->start_transaction();

        try {
            foreach ($class_ids as $class_id) {
                $deleted = $wpdb->delete($table, ['class_id' => $class_id, 'term_id' => $term_id], ['%d', '%d']);

                if ($deleted === false) {
                    throw new Exception('پاک‌سازی برنامه قبلی انجام نشد.');
                }
            }

            foreach ($entries as $entry) {
                $inserted = $wpdb->insert(
                    $table,
                    [
                        'term_id'      => $term_id,
                        'class_id'     => (int) $entry['class_id'],
                        'day_of_week'  => $entry['day_of_week'],
                        'school_shift' => (int) $entry['school_shift'],
                        'lesson_id'    => (int) $entry['lesson_id'],
                        'teacher_id'   => (int) $entry['teacher_id'],
                        'week_type'    => $entry['week_type'],
                    ],
                    ['%d', '%d', '%s', '%d', '%d', '%d', '%s']
                );

                if ($inserted === false) {
                    throw new Exception('ذخیره برنامه سراسری انجام نشد.');
                }
            }

            $this->commit_transaction();
        } catch (Exception $e) {
            $this->rollback_transaction();
            throw $e;
        }
    }

                private function active_term_or_ajax_error()
    {
        $term = class_exists('HST_Terms') ? HST_Terms::active() : null;
        if (!$term || empty($term->id)) {
            wp_send_json_error(['message' => 'سال تحصیلی فعالی برای برنامه‌ریزی پیدا نشد.']);
        }

        return $term;
    }

    private function schedule_teacher_rows($term_id): array
    {
        global $wpdb;

        $cap_key = $wpdb->prefix . 'capabilities';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.ID AS id,
                        u.display_name,
                        phone.meta_value AS phone,
                        national.meta_value AS national_code,
                        personnel.meta_value AS personnel_code,
                        COUNT(DISTINCT c.id) AS class_count,
                        COUNT(DISTINCT l.id) AS lesson_count,
                        GROUP_CONCAT(DISTINCT c.class_name ORDER BY c.class_name SEPARATOR '، ') AS classes,
                        GROUP_CONCAT(DISTINCT l.lesson_name ORDER BY l.lesson_name SEPARATOR '، ') AS lessons
                 FROM {$wpdb->users} u
                 INNER JOIN {$wpdb->usermeta} rolemeta
                    ON rolemeta.user_id = u.ID
                   AND rolemeta.meta_key = %s
                   AND rolemeta.meta_value LIKE %s
                 LEFT JOIN {$wpdb->usermeta} phone
                    ON phone.user_id = u.ID AND phone.meta_key = 'phone'
                 LEFT JOIN {$wpdb->usermeta} national
                    ON national.user_id = u.ID AND national.meta_key = 'hst_national_code'
                 LEFT JOIN {$wpdb->usermeta} personnel
                    ON personnel.user_id = u.ID AND personnel.meta_key = 'hst_personnel_code'
                 LEFT JOIN {$wpdb->prefix}hst_users_classes uc
                    ON uc.user_id = u.ID
                   AND uc.role = 'teacher'
                   AND uc.term_id = %d
                 LEFT JOIN {$wpdb->prefix}hst_classes c ON c.id = uc.class_id
                 LEFT JOIN {$wpdb->prefix}hst_users_lessons ul
                    ON ul.user_id = u.ID
                   AND ul.role = 'teacher'
                   AND ul.term_id = %d
                 LEFT JOIN {$wpdb->prefix}hst_lessons l ON l.id = ul.lesson_id
                 GROUP BY u.ID
                 ORDER BY u.display_name ASC",
                $cap_key,
                '%"teacher"%',
                $term_id,
                $term_id
            )
        ) ?: [];

        return array_map(function ($row) {
            $class_names = preg_split('/\s*،\s*/u', (string) ($row->classes ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $class_names = HST_Classes::sort_names($class_names);

            return [
                'id'             => (int) $row->id,
                'display_name'   => (string) $row->display_name,
                'phone'          => (string) ($row->phone ?? ''),
                'national_code'  => (string) ($row->national_code ?? ''),
                'personnel_code' => (string) ($row->personnel_code ?? ''),
                'class_count'    => (int) ($row->class_count ?? 0),
                'lesson_count'   => (int) ($row->lesson_count ?? 0),
                'classes'        => implode('، ', $class_names),
                'lessons'        => (string) ($row->lessons ?? ''),
            ];
        }, $rows);
    }

    private function teacher_assignment_profile_payload(int $teacher_id, int $term_id): array
    {
        global $wpdb;

        $user = get_userdata($teacher_id);
        if (!$user || !in_array('teacher', (array) $user->roles, true)) {
            wp_send_json_error(['message' => 'معلم انتخاب‌شده پیدا نشد.']);
        }

        $lesson_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id,
                    l.lesson_name,
                    l.class_id,
                    c.class_name,
                    l.unit,
                    SUM(ul.lesson_unit) AS selected_unit
             FROM {$wpdb->prefix}hst_users_lessons ul
             INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = ul.lesson_id
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = l.class_id
             WHERE ul.user_id = %d
               AND ul.role = 'teacher'
               AND ul.term_id = %d
               AND TRIM(l.lesson_name) <> %s
             GROUP BY l.id, l.lesson_name, l.class_id, c.class_name, l.unit
             ORDER BY c.class_name ASC, l.lesson_name ASC",
            $teacher_id,
            $term_id,
            self::DISCIPLINE_LESSON_NAME
        )) ?: [];

        $lesson_rows = HST_Classes::sort_rows($lesson_rows, 'class_name', ['lesson_name']);

        $availability = array_map(function ($row) {
            return $row->day_of_week . '_' . (int) $row->school_shift;
        }, (array) $wpdb->get_results($wpdb->prepare(
            "SELECT day_of_week, school_shift
             FROM {$wpdb->prefix}hst_users_availability
             WHERE user_id = %d AND role = 'teacher' AND term_id = %d",
            $teacher_id,
            $term_id
        )));

        return [
            'teacher' => [
                'id' => $teacher_id,
                'display_name' => $user->display_name,
            ],
            'lessons' => array_map(function ($row) {
                return [
                    'id'            => (int) $row->id,
                    'lesson_name'   => (string) $row->lesson_name,
                    'class_id'      => (int) $row->class_id,
                    'class_name'    => (string) $row->class_name,
                    'unit'          => (int) $row->unit,
                    'selected_unit' => max(1, (int) $row->selected_unit),
                    'max_unit'      => max(1, (int) $row->unit),
                ];
            }, $lesson_rows),
            'availability' => array_values(array_unique($availability)),
        ];
    }

    public function assignment_context(): void
    {
        $this->authorize_ajax();

        $term = $this->active_term_or_ajax_error();
        $term_id = (int) $term->id;

        wp_send_json_success([
            'active_term' => [
                'id' => $term_id,
                'term_name' => (string) $term->term_name,
            ],
            'teachers' => $this->schedule_teacher_rows($term_id),
            'classes' => $this->get_classes(),
            'days' => self::DAYS,
            'shifts' => self::SHIFTS,
            'schedule_options' => $this->get_saved_schedule_options($term_id),
            'generation_status' => self::generation_status($term_id),
        ]);
    }

    public function teacher_profile(): void
    {
        $this->authorize_ajax();

        $term = $this->active_term_or_ajax_error();
        $teacher_id = absint($_POST['teacher_id'] ?? 0);

        if (!$teacher_id) {
            wp_send_json_error(['message' => 'معلم نامعتبر است.']);
        }

        wp_send_json_success($this->teacher_assignment_profile_payload($teacher_id, (int) $term->id));
    }

    private function lessons_for_teacher_payload(int $teacher_id, int $term_id): array
    {
        global $wpdb;

        $user = get_userdata($teacher_id);
        if (!$user || !in_array('teacher', (array) $user->roles, true)) {
            wp_send_json_error(['message' => 'معلم انتخاب‌شده پیدا نشد.']);
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id,
                    l.lesson_name,
                    l.class_id,
                    c.class_name,
                    l.unit,
                    COALESCE(SUM(CASE WHEN ul.user_id != %d THEN ul.lesson_unit ELSE 0 END), 0) AS used_by_others,
                    COALESCE(SUM(CASE WHEN ul.user_id = %d THEN ul.lesson_unit ELSE 0 END), 0) AS selected_unit
             FROM {$wpdb->prefix}hst_lessons l
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = l.class_id
             LEFT JOIN {$wpdb->prefix}hst_users_lessons ul
                ON ul.lesson_id = l.id
               AND ul.role = 'teacher'
               AND ul.term_id = %d
             WHERE TRIM(l.lesson_name) <> %s
             GROUP BY l.id, l.lesson_name, l.class_id, c.class_name, l.unit
             ORDER BY c.class_name ASC, l.lesson_name ASC",
            $teacher_id,
            $teacher_id,
            $term_id,
            self::DISCIPLINE_LESSON_NAME
        )) ?: [];
        $rows = HST_Classes::sort_rows($rows, 'class_name', ['lesson_name']);

        return array_map(function ($row) {
            $unit = max(1, (int) $row->unit);
            $used_by_others = max(0, (int) $row->used_by_others);
            $selected_unit = max(0, (int) $row->selected_unit);
            $max_unit = max(0, $unit - $used_by_others);

            return [
                'id'             => (int) $row->id,
                'lesson_name'    => (string) $row->lesson_name,
                'class_id'       => (int) $row->class_id,
                'class_name'     => (string) $row->class_name,
                'unit'           => $unit,
                'used_by_others' => $used_by_others,
                'selected_unit'  => $selected_unit,
                'max_unit'       => max($max_unit, $selected_unit),
            ];
        }, $rows);
    }

    public function lessons_for_teacher(): void
    {
        $this->authorize_ajax();

        $term = $this->active_term_or_ajax_error();
        $teacher_id = absint($_POST['teacher_id'] ?? 0);

        if (!$teacher_id) {
            wp_send_json_error(['message' => 'معلم نامعتبر است.']);
        }

        wp_send_json_success([
            'lessons' => $this->lessons_for_teacher_payload($teacher_id, (int) $term->id),
        ]);
    }

            private function split_lesson_units_for_storage(int $unit): array
    {
        $unit = max(1, min(4, $unit));

        if ($unit === 3) {
            return [1, 2];
        }

        if ($unit === 4) {
            return [2, 2];
        }

        return [$unit];
    }

    public function save_teacher_assignment(): void
    {
        $this->authorize_ajax();

        $term = $this->active_term_or_ajax_error();
        $term_id = (int) $term->id;
        $teacher_id = absint($_POST['teacher_id'] ?? 0);
        $lesson_units = wp_unslash($_POST['lesson_units'] ?? []);
        $availability = wp_unslash($_POST['availability'] ?? []);

        $user = get_userdata($teacher_id);
        if (!$teacher_id || !$user || !in_array('teacher', (array) $user->roles, true)) {
            wp_send_json_error(['message' => 'معلم انتخاب‌شده نامعتبر است.']);
        }

        if (!is_array($lesson_units)) {
            $lesson_units = [];
        }

        global $wpdb;

        $selected = [];
        foreach ($lesson_units as $lesson_id => $unit) {
            $lesson_id = absint($lesson_id);
            $unit = max(1, min(4, absint($unit)));

            if (!$lesson_id) {
                continue;
            }

            $lesson = $wpdb->get_row($wpdb->prepare(
                "SELECT id, class_id, unit, lesson_name FROM {$wpdb->prefix}hst_lessons WHERE id = %d",
                $lesson_id
            ));

            if (!$lesson) {
                wp_send_json_error(['message' => 'یکی از درس‌های انتخاب‌شده پیدا نشد.']);
            }

            if (trim((string) $lesson->lesson_name) === self::DISCIPLINE_LESSON_NAME) {
                wp_send_json_error(['message' => 'درس انضباط به دبیر تخصیص داده نمی‌شود و نمره آن فقط توسط مدیر ثبت می‌شود.']);
            }

            $used_by_others = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(lesson_unit), 0)
                 FROM {$wpdb->prefix}hst_users_lessons
                 WHERE lesson_id = %d
                   AND role = 'teacher'
                   AND term_id = %d
                   AND user_id != %d",
                $lesson_id,
                $term_id,
                $teacher_id
            ));

            if ($used_by_others + $unit > (int) $lesson->unit) {
                wp_send_json_error(['message' => 'واحد انتخاب‌شده برای یکی از درس‌ها بیشتر از ظرفیت باقی‌مانده آن درس است.']);
            }

            $selected[$lesson_id] = [
                'class_id' => (int) $lesson->class_id,
                'unit'     => $unit,
            ];
        }

        $class_ids = array_values(array_unique(array_map(function ($item) {
            return (int) $item['class_id'];
        }, $selected)));

        $this->start_transaction();

        try {
            $wpdb->delete(
                $wpdb->prefix . 'hst_users_classes',
                ['user_id' => $teacher_id, 'term_id' => $term_id, 'role' => 'teacher'],
                ['%d', '%d', '%s']
            );

            $wpdb->delete(
                $wpdb->prefix . 'hst_users_lessons',
                ['user_id' => $teacher_id, 'term_id' => $term_id, 'role' => 'teacher'],
                ['%d', '%d', '%s']
            );

            $wpdb->delete(
                $wpdb->prefix . 'hst_users_availability',
                ['user_id' => $teacher_id, 'term_id' => $term_id, 'role' => 'teacher'],
                ['%d', '%d', '%s']
            );

            foreach ($class_ids as $class_id) {
                $inserted = $wpdb->insert(
                    $wpdb->prefix . 'hst_users_classes',
                    [
                        'user_id'  => $teacher_id,
                        'class_id' => $class_id,
                        'term_id'  => $term_id,
                        'role'     => 'teacher',
                    ],
                    ['%d', '%d', '%d', '%s']
                );

                if ($inserted === false) {
                    throw new Exception('ثبت کلاس‌های معلم انجام نشد.');
                }
            }

            foreach ($selected as $lesson_id => $item) {
                foreach ($this->split_lesson_units_for_storage((int) $item['unit']) as $unit) {
                    $inserted = $wpdb->insert(
                        $wpdb->prefix . 'hst_users_lessons',
                        [
                            'user_id'     => $teacher_id,
                            'class_id'    => (int) $item['class_id'],
                            'lesson_id'   => (int) $lesson_id,
                            'lesson_unit' => (int) $unit,
                            'term_id'     => $term_id,
                            'role'        => 'teacher',
                        ],
                        ['%d', '%d', '%d', '%d', '%d', '%s']
                    );

                    if ($inserted === false) {
                        throw new Exception('ثبت درس‌های معلم انجام نشد.');
                    }
                }
            }

            $allowed_days = array_keys(self::DAYS);
            if (is_array($availability)) {
                foreach ($availability as $item) {
                    $parts = explode('_', sanitize_text_field((string) $item));
                    if (count($parts) !== 2) {
                        continue;
                    }

                    $day = sanitize_key($parts[0]);
                    $shift = absint($parts[1]);

                    if (!in_array($day, $allowed_days, true) || !in_array($shift, self::SHIFTS, true)) {
                        continue;
                    }

                    $inserted = $wpdb->insert(
                        $wpdb->prefix . 'hst_users_availability',
                        [
                            'user_id'      => $teacher_id,
                            'day_of_week'  => $day,
                            'school_shift' => $shift,
                            'term_id'      => $term_id,
                            'role'         => 'teacher',
                        ],
                        ['%d', '%s', '%d', '%d', '%s']
                    );

                    if ($inserted === false) {
                        throw new Exception('ثبت برنامه حضور معلم انجام نشد.');
                    }
                }
            }

            $this->commit_transaction();

            wp_send_json_success([
                'message' => 'تخصیص درس و برنامه حضور معلم ذخیره شد.',
                'profile' => $this->teacher_assignment_profile_payload($teacher_id, $term_id),
                'teachers' => $this->schedule_teacher_rows($term_id),
            ]);
        } catch (Exception $e) {
            $this->rollback_transaction();
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

        

    private function schedule_job_transient_key($token)
    {
        $token = sanitize_key($token);
        return 'hst_schedule_job_' . get_current_user_id() . '_' . $token;
    }

    private function schedule_entries_unit_total(array $entries)
    {
        $total = 0;

        foreach ($entries as $entry) {
            $total += (($entry['week_type'] ?? 'every') === 'every') ? 2 : 1;
        }

        return $total;
    }

    private function better_schedule_result(array $candidate, array $current)
    {
        if (empty($current)) {
            return true;
        }

        $candidate_units = $this->schedule_entries_unit_total($candidate['entries'] ?? []);
        $current_units = $this->schedule_entries_unit_total($current['entries'] ?? []);

        if ($candidate_units !== $current_units) {
            return $candidate_units > $current_units;
        }

        $candidate_complete = !empty($candidate['is_complete']);
        $current_complete = !empty($current['is_complete']);

        if ($candidate_complete !== $current_complete) {
            return $candidate_complete;
        }

        $candidate_checks = (int) ($candidate['checked_possibilities'] ?? 0);
        $current_checks = (int) ($current['checked_possibilities'] ?? 0);

        return $candidate_checks > $current_checks;
    }

    public function generate_all_schedules_start()
    {
        $this->authorize_ajax();

        $term_id = HST_Guard::post_int('term_id');
        $selected_class_id = HST_Guard::post_int('class_id');

        if (!$term_id) {
            wp_send_json_error(['message' => 'اول سال تحصیلی را انتخاب کنید.']);
        }

        if (!$this->term_exists($term_id)) {
            wp_send_json_error(['message' => 'سال تحصیلی انتخاب‌شده پیدا نشد.']);
        }

        $this->ensure_generation_ready($term_id);

        $schedule_options = $this->persist_schedule_options($term_id, $_POST['schedule_options'] ?? []);

        $classes = $this->get_classes();
        $class_ids = array_map(function ($class) {
            return (int) $class->id;
        }, $classes);

        if (!$class_ids) {
            wp_send_json_error(['message' => 'کلاسی برای برنامه‌ریزی پیدا نشد.']);
        }

        $token = wp_generate_password(20, false, false);
        $attempts_per_step = max(1, (int) apply_filters('hst_schedule_async_attempts_per_step', 3, $term_id, $class_ids));
        $total_steps = max(8, (int) apply_filters('hst_schedule_async_total_steps', 24, $term_id, $class_ids));
        $checks_per_step = max(12000, (int) apply_filters('hst_schedule_async_checks_per_step', 70000, $term_id, $class_ids));

        $state = [
            'token'             => $token,
            'term_id'           => $term_id,
            'selected_class_id' => $selected_class_id,
            'class_ids'         => $class_ids,
            'current_step'      => 0,
            'total_steps'       => $total_steps,
            'attempts_per_step' => $attempts_per_step,
            'checks_per_step'   => $checks_per_step,
            'best_result'       => [],
            'schedule_options'  => $schedule_options,
            'started_at'        => time(),
        ];

        set_transient($this->schedule_job_transient_key($token), $state, HOUR_IN_SECONDS);

        wp_send_json_success([
            'token' => $token,
            'total_steps' => $total_steps,
            'message' => 'موتور برنامه‌ریز آماده شد. تولید برنامه به‌صورت مرحله‌ای شروع می‌شود.',
        ]);
    }

    public function generate_all_schedules_step()
    {
        $this->authorize_ajax();

        $token = sanitize_key(wp_unslash($_POST['token'] ?? ''));
        if (!$token) {
            wp_send_json_error(['message' => 'شناسه عملیات نامعتبر است.']);
        }

        $key = $this->schedule_job_transient_key($token);
        $state = get_transient($key);

        if (empty($state) || !is_array($state)) {
            wp_send_json_error(['message' => 'عملیات برنامه‌ریزی پیدا نشد یا منقضی شده است. دوباره شروع کنید.']);
        }

        $term_id = (int) $state['term_id'];
        $class_ids = array_map('absint', $state['class_ids'] ?? []);
        $selected_class_id = (int) ($state['selected_class_id'] ?? 0);
        $current_step = (int) ($state['current_step'] ?? 0);
        $total_steps = max(1, (int) ($state['total_steps'] ?? 1));
        $attempts_per_step = max(1, (int) ($state['attempts_per_step'] ?? 2));
        $checks_per_step = max(8000, (int) ($state['checks_per_step'] ?? 45000));
        $attempt_offset = $current_step * $attempts_per_step;
        $schedule_options = $this->sanitize_schedule_options($state['schedule_options'] ?? []);

        $checks_filter = function () use ($checks_per_step) {
            return $checks_per_step;
        };
        $attempts_filter = function () use ($attempts_per_step) {
            return $attempts_per_step;
        };
        $offset_filter = function () use ($attempt_offset) {
            return $attempt_offset;
        };

        add_filter('hst_schedule_global_generator_max_checks', $checks_filter, 999);
        add_filter('hst_schedule_global_generator_attempts', $attempts_filter, 999);
        add_filter('hst_schedule_global_generator_attempt_offset', $offset_filter, 999);

        try {
            $result = $this->auto_generate_for_classes($class_ids, $term_id, $schedule_options);
        } catch (Exception $e) {
            remove_filter('hst_schedule_global_generator_max_checks', $checks_filter, 999);
            remove_filter('hst_schedule_global_generator_attempts', $attempts_filter, 999);
            remove_filter('hst_schedule_global_generator_attempt_offset', $offset_filter, 999);
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        remove_filter('hst_schedule_global_generator_max_checks', $checks_filter, 999);
        remove_filter('hst_schedule_global_generator_attempts', $attempts_filter, 999);
        remove_filter('hst_schedule_global_generator_attempt_offset', $offset_filter, 999);

        // The heavy generation above may have taken a while. If the transient
        // expired meanwhile, abort without persisting an invalid job state.
        if (false === get_transient($key)) {
            wp_send_json_error(['message' => 'عملیات برنامه‌ریزی منقضی شده است. دوباره شروع کنید.']);
        }

        if ($this->better_schedule_result($result, $state['best_result'] ?? [])) {
            $state['best_result'] = $result;
        }

        $state['current_step'] = $current_step + 1;
        $is_done = !empty($state['best_result']['is_complete']) || $state['current_step'] >= $total_steps;
        $progress = min(100, (int) floor(($state['current_step'] / $total_steps) * 100));

        if ($is_done) {
            $final = $state['best_result'] ?: $result;
            $this->save_generated_entries_for_classes($term_id, $class_ids, $final['entries'] ?? []);

            $final['message'] = !empty($final['is_complete'])
                ? 'برنامه همه کلاس‌ها کامل تولید و ذخیره شد.'
                : 'بهترین برنامه ممکن تا این مرحله تولید و ذخیره شد؛ چند مورد هنوز نیاز به اصلاح اطلاعات دارد.';

            $final['selected_schedule'] = ($selected_class_id && in_array($selected_class_id, $class_ids, true))
                ? $this->get_saved_schedule($selected_class_id, $term_id, $schedule_options)
                : [];

            $final['progress'] = 100;
            $final['is_done'] = true;

            delete_transient($key);
            wp_send_json_success($final);
        }

        set_transient($key, $state, HOUR_IN_SECONDS);

        wp_send_json_success([
            'is_done' => false,
            'progress' => $progress,
            'current_step' => $state['current_step'],
            'total_steps' => $total_steps,
            'best_units' => $this->schedule_entries_unit_total($state['best_result']['entries'] ?? []),
            'checked_possibilities' => (int) (($state['best_result']['checked_possibilities'] ?? 0) + ($result['checked_possibilities'] ?? 0)),
            'message' => 'برنامه‌ریز در حال بررسی حالت‌های مختلف است...',
        ]);
    }


    public function save_schedule_options()
    {
        $this->authorize_ajax();

        $term_id = HST_Guard::post_int('term_id');
        if (!$term_id && class_exists('HST_Terms')) {
            $term_id = (int) HST_Terms::active_id();
        }
        if (!$term_id) {
            wp_send_json_error(['message' => 'سال تحصیلی فعالی برای برنامه‌ریزی پیدا نشد.']);
        }

        if (!$this->term_exists($term_id)) {
            wp_send_json_error(['message' => 'سال تحصیلی انتخاب‌شده پیدا نشد.']);
        }

        $options = $this->persist_schedule_options($term_id, $_POST['schedule_options'] ?? []);

        wp_send_json_success([
            'message' => 'تنظیمات برنامه‌ریز ذخیره شد.',
            'schedule_options' => $options,
        ]);
    }

                /**
     * Classes a user is enrolled in for a term, by role. Read API used by
     * renderers instead of querying the schedule tables directly.
     *
     * @param int    $user_id
     * @param string $role 'teacher'|'student'
     * @param int    $term_id
     * @return array
     */
    public static function user_classes($user_id, $role, $term_id)
    {
        global $wpdb;
        $user_id = absint($user_id);
        $term_id = absint($term_id);
        if (!$user_id || !$term_id || !in_array($role, ['teacher', 'student'], true)) {
            return [];
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT c.id, c.class_name
                 FROM {$wpdb->prefix}hst_users_classes uc
                 INNER JOIN {$wpdb->prefix}hst_classes c ON uc.class_id = c.id
                 WHERE uc.user_id = %d AND uc.term_id = %d AND uc.role = %s
                 ORDER BY c.class_name ASC",
                $user_id,
                $term_id,
                $role
            )
        ) ?: [];

        return HST_Classes::sort_rows($rows);
    }

    /**
     * The saved weekly schedule rows for a class in a term, joined with lesson
     * and teacher names, ordered for display.
     *
     * @return array
     */
    public static function class_saved_schedule($class_id, $term_id)
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.day_of_week, s.school_shift, s.week_type, s.lesson_id, s.teacher_id,
                        l.lesson_name, u.display_name AS teacher_name
                 FROM {$wpdb->prefix}hst_schedules s
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON s.lesson_id = l.id
                 INNER JOIN {$wpdb->users} u ON s.teacher_id = u.ID
                 WHERE s.class_id = %d AND s.term_id = %d
                 ORDER BY FIELD(s.day_of_week, 'saturday','sunday','monday','tuesday','wednesday'),
                    s.school_shift,
                    FIELD(s.week_type, 'every','odd','even')",
                absint($class_id),
                absint($term_id)
            )
        ) ?: [];
    }

    /**
     * Every slot a given teacher personally teaches in a term, across all
     * classes. Used by the teacher's "my schedule" personal overview so they
     * can see, in one grid, which lesson/class they have on each day & period.
     */
    public static function teacher_saved_schedule($teacher_id, $term_id)
    {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.day_of_week, s.school_shift, s.week_type, s.lesson_id, s.class_id, s.teacher_id,
                        l.lesson_name, c.class_name, u.display_name AS teacher_name
                 FROM {$wpdb->prefix}hst_schedules s
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON s.lesson_id = l.id
                 INNER JOIN {$wpdb->prefix}hst_classes c ON s.class_id = c.id
                 INNER JOIN {$wpdb->users} u ON s.teacher_id = u.ID
                 WHERE s.teacher_id = %d AND s.term_id = %d
                 ORDER BY FIELD(s.day_of_week, 'saturday','sunday','monday','tuesday','wednesday'),
                    s.school_shift,
                    FIELD(s.week_type, 'every','odd','even')",
                absint($teacher_id),
                absint($term_id)
            )
        ) ?: [];
    }

    /**
     * Return the teachers who have at least one saved schedule row in a term.
     *
     * @param int $term_id
     * @return int[]
     */
    public static function teacher_ids_with_saved_schedule($term_id): array
    {
        global $wpdb;

        $term_id = absint($term_id);
        if (!$term_id) {
            return [];
        }

        $teacher_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT teacher_id
                 FROM {$wpdb->prefix}hst_schedules
                 WHERE term_id = %d
                   AND teacher_id > 0",
                $term_id
            )
        ) ?: [];

        return array_values(array_unique(array_map('intval', $teacher_ids)));
    }

    /**
     * Whether a term currently contains at least one downloadable schedule row.
     *
     * @param int $term_id
     */
    public static function term_has_saved_schedule($term_id): bool
    {
        global $wpdb;

        $term_id = absint($term_id);
        if (!$term_id) {
            return false;
        }

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1
                 FROM {$wpdb->prefix}hst_schedules
                 WHERE term_id = %d
                 LIMIT 1",
                $term_id
            )
        );
    }

    /**
     * Group flat schedule rows into a [shift][day][] grid for rendering.
     *
     * @param array $schedule_rows
     * @return array
     */
    public static function build_grid(array $schedule_rows)
    {
        $grid = [];
        foreach ($schedule_rows as $row) {
            $day = sanitize_key($row->day_of_week);
            $shift = absint($row->school_shift);
            if (!isset($grid[$shift])) {
                $grid[$shift] = [];
            }
            if (!isset($grid[$shift][$day])) {
                $grid[$shift][$day] = [];
            }
            $grid[$shift][$day][] = $row;
        }
        return $grid;
    }
}
