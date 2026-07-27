<?php

defined('ABSPATH') || exit;

class HST_Scores
{
    private const PERIOD_TYPES = [
        'weekly'       => 'هفتگی',
        'monthly'      => 'ماهانه',
        'first_shift'  => 'نوبت اول',
        'second_shift' => 'نوبت دوم',
        'custom'       => 'اختصاصی',
    ];

    private const MAX_CUSTOM_SCORE_COUNT = 20;
    private const DISCIPLINE_LESSON_NAME = 'انضباط';
    private const MANAGER_DISCIPLINE_TEACHER_ID = 0;

    public function __construct()
    {
        add_action('wp_ajax_hst_save_score_period', [$this, 'ajax_save_score_period']);
        add_action('wp_ajax_hst_delete_score_period', [$this, 'ajax_delete_score_period']);
        add_action('wp_ajax_hst_toggle_score_period', [$this, 'ajax_toggle_score_period']);
        add_action('wp_ajax_hst_toggle_score_entry_access', [$this, 'ajax_toggle_score_entry_access']);
        add_action('wp_ajax_hst_score_audit_get_scores', [$this, 'ajax_score_audit_get_scores']);
        add_action('wp_ajax_hst_score_audit_save_scores', [$this, 'ajax_score_audit_save_scores']);
        add_action('wp_ajax_hst_score_audit_security_logs', [$this, 'ajax_score_audit_security_logs']);
        add_action('wp_ajax_hst_score_audit_send_reminder', [$this, 'ajax_score_audit_send_reminder']);
        add_action('wp_ajax_hst_score_audit_excel_report', [$this, 'ajax_score_audit_excel_report']);
        add_action('wp_ajax_hst_get_teacher_score_context', [$this, 'ajax_get_teacher_score_context']);
        add_action('wp_ajax_hst_get_monthly_scores', [$this, 'ajax_get_monthly_scores']);
        add_action('wp_ajax_hst_save_monthly_scores', [$this, 'ajax_save_monthly_scores']);
        add_action('wp_ajax_hst_get_gradebook', [$this, 'ajax_get_gradebook']);
        add_action('wp_ajax_hst_save_gradebook', [$this, 'ajax_save_gradebook']);
    }

    public static function period_types(): array
    {
        return self::PERIOD_TYPES;
    }


    private function authorize_ajax(string $capability = 'read'): void
    {
        if (class_exists('HST_Guard')) {
            HST_Guard::verify_ajax($capability);
            return;
        }

        check_ajax_referer('hst_nonce', 'nonce');

        if (!is_user_logged_in() || !current_user_can($capability)) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز است.'], 403);
        }
    }

    private function fail(string $message, int $status_code = 400): void
    {
        if (class_exists('HST_Guard')) {
            HST_Guard::fail($message, $status_code);
        }

        wp_send_json_error(['message' => $message], $status_code);
    }

    private function post_int(string $key): int
    {
        return class_exists('HST_Guard') ? HST_Guard::post_int($key) : absint(wp_unslash($_POST[$key] ?? 0));
    }

    private function post_key(string $key): string
    {
        return sanitize_key(wp_unslash($_POST[$key] ?? ''));
    }

    /**
     * Accept score matrices as either the legacy nested POST array or a JSON
     * string. JSON prevents PHP's max_input_vars limit from truncating custom
     * periods that contain several score slots for every student.
     */
    private function posted_scores_payload(): ?array
    {
        $raw = $_POST['scores'] ?? [];
        if (is_array($raw)) {
            return wp_unslash($raw);
        }

        $raw = trim((string) wp_unslash($raw));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function user_can_manage_scores(): bool
    {
        return current_user_can('manage_options') || current_user_can('hst_manage_school');
    }

    private function user_can_set_discipline_scores(): bool
    {
        if (class_exists('HST_Roles')) {
            return HST_Roles::is_full_manager();
        }

        $user = wp_get_current_user();
        return current_user_can('manage_options') || ($user && in_array('modir', (array) $user->roles, true));
    }

    private function discipline_lesson_meta(int $class_id, int $lesson_id): ?array
    {
        global $wpdb;

        if ($class_id < 1 || $lesson_id < 1) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT l.id AS lesson_id, l.class_id, l.lesson_name, c.class_name
             FROM {$wpdb->prefix}hst_lessons l
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = l.class_id
             WHERE l.id = %d AND l.class_id = %d AND TRIM(l.lesson_name) = %s
             LIMIT 1",
            $lesson_id,
            $class_id,
            self::DISCIPLINE_LESSON_NAME
        ));

        if (!$row) {
            return null;
        }

        return [
            'teacher_id'  => self::MANAGER_DISCIPLINE_TEACHER_ID,
            'teacher_name'=> 'مدیر مدرسه',
            'class_id'    => (int) $row->class_id,
            'lesson_id'   => (int) $row->lesson_id,
            'class_name'  => (string) $row->class_name,
            'lesson_name' => (string) $row->lesson_name,
            'manager_only'=> 1,
        ];
    }

    private function is_discipline_lesson(int $lesson_id, int $class_id = 0): bool
    {
        global $wpdb;

        if ($lesson_id < 1) {
            return false;
        }

        $where = 'id = %d AND TRIM(lesson_name) = %s';
        $args = [$lesson_id, self::DISCIPLINE_LESSON_NAME];
        if ($class_id > 0) {
            $where .= ' AND class_id = %d';
            $args[] = $class_id;
        }

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hst_lessons WHERE {$where} LIMIT 1",
            ...$args
        ));
    }

    private function get_class_students(int $term_id, int $class_id): array
    {
        global $wpdb;

        if ($term_id < 1 || $class_id < 1) {
            return [];
        }

        $students = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT u.ID, u.display_name,
                (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = u.ID AND meta_key = 'last_name' LIMIT 1) AS last_name
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->prefix}hst_users_classes sc
                ON sc.user_id = u.ID
                AND sc.role = 'student'
                AND sc.term_id = %d
                AND sc.class_id = %d",
            $term_id,
            $class_id
        )) ?: [];

        return class_exists('HST_Students') ? HST_Students::sort_student_rows($students) : $students;
    }

    private function user_can_teach(): bool
    {
        return current_user_can('hst_teach') || current_user_can('teacher') || $this->user_can_manage_scores();
    }

    private function term_exists(int $term_id): bool
    {
        global $wpdb;

        if ($term_id <= 0) {
            return false;
        }

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hst_terms WHERE id = %d LIMIT 1",
                $term_id
            )
        );
    }

    private function normalize_score_value(string $raw_score): ?float
    {
        $normalized = trim(str_replace(['،', ','], '.', $raw_score));

        if ($normalized === '') {
            return null;
        }

        if (!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/', $normalized)) {
            $this->fail('فرمت نمره نامعتبر است. نمره باید عددی بین ۰ تا ۲۰ باشد.');
        }

        $score = round((float) $normalized, 2);

        if ($score < 0 || $score > 20) {
            $this->fail('نمره باید بین ۰ تا ۲۰ باشد.');
        }

        return $score;
    }

    public function get_active_term()
    {
        return HST_Terms::active();
    }

    private function score_periods_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_score_periods';
    }

    private function table_exists(string $table): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    private function generate_period_key(int $term_id): string
    {
        global $wpdb;
        $table = $this->score_periods_table();

        do {
            $key = 'period_' . strtolower(wp_generate_password(8, false, false));
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE term_id = %d AND period_key = %s LIMIT 1",
                $term_id,
                $key
            ));
        } while ($exists);

        return $key;
    }

    private function normalize_period_type(string $type): string
    {
        $type = sanitize_key($type);
        return isset(self::PERIOD_TYPES[$type]) ? $type : 'custom';
    }

    private function normalize_period_date($value): string
    {
        $value = sanitize_text_field(wp_unslash($value ?? ''));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 32);
        }
        return substr($value, 0, 32);
    }


    /**
     * Ensure score periods are available for the requested term.
     *
     * Existing installations may still have their periods in the legacy
     * hst_score_months table, so the migration must run lazily before any
     * period query. This method existed in the previous version and is also
     * used by the public period APIs.
     */
    public function ensure_term_periods($term_id): void
    {
        $term_id = absint($term_id);
        if (!$term_id || !$this->term_exists($term_id)) {
            return;
        }

        $this->migrate_legacy_months_to_periods($term_id);
    }


    private function normalize_period_score_count(string $period_type, $value): int
    {
        $period_type = $this->normalize_period_type($period_type);

        if ($period_type === 'first_shift') {
            return 2;
        }
        if ($period_type === 'second_shift') {
            return 4;
        }
        if ($period_type === 'weekly' || $period_type === 'monthly') {
            return 1;
        }

        return max(1, min(self::MAX_CUSTOM_SCORE_COUNT, absint($value)));
    }

    private function term_has_period_type(int $term_id, string $period_type, int $exclude_id = 0): bool
    {
        global $wpdb;

        $period_type = $this->normalize_period_type($period_type);
        $table = $this->score_periods_table();
        $where = 'term_id = %d AND period_type = %s';
        $args = [$term_id, $period_type];
        if ($exclude_id > 0) {
            $where .= ' AND id <> %d';
            $args[] = $exclude_id;
        }

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE {$where} LIMIT 1",
            ...$args
        ));
    }

    private function get_period_by_key(int $term_id, string $period_key)
    {
        global $wpdb;

        $period_key = sanitize_key($period_key);
        if ($term_id <= 0 || $period_key === '') {
            return null;
        }

        $table = $this->score_periods_table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, term_id, period_key, period_name, period_type, score_count, start_date, end_date, sort_order, is_active
             FROM {$table}
             WHERE term_id = %d AND period_key = %s
             LIMIT 1",
            $term_id,
            $period_key
        ));
    }

    private function first_shift_period_for(int $term_id, $period = null)
    {
        global $wpdb;

        $before_sort = is_object($period) ? (int) ($period->sort_order ?? PHP_INT_MAX) : PHP_INT_MAX;
        $before_id = is_object($period) ? (int) ($period->id ?? PHP_INT_MAX) : PHP_INT_MAX;

        $table = $this->score_periods_table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, term_id, period_key, period_name, period_type, score_count, sort_order, is_active
             FROM {$table}
             WHERE term_id = %d
                AND period_type = 'first_shift'
                AND (sort_order < %d OR (sort_order = %d AND id < %d))
             ORDER BY sort_order DESC, id DESC
             LIMIT 1",
            $term_id,
            $before_sort,
            $before_sort,
            $before_id
        ));

        if ($row) {
            return $row;
        }

        $table = $this->score_periods_table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, term_id, period_key, period_name, period_type, score_count, sort_order, is_active
             FROM {$table}
             WHERE term_id = %d AND period_type = 'first_shift'
             ORDER BY sort_order ASC, id ASC
             LIMIT 1",
            $term_id
        ));
    }

    /** @return array{year:int,month:int}|null */
    private function score_period_jalali_year_month(string $date): ?array
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        if (class_exists('HST_Date')) {
            $date = HST_Date::en_digits($date);
        } else {
            $date = strtr($date, [
                '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
                '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            ]);
        }

        $date = str_replace(['-', '.', ' '], '/', $date);
        if (!preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})/', $date, $matches)) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        if ($year >= 1700 && class_exists('HST_Date')) {
            [$year, $month] = HST_Date::gregorian_to_jalali($year, $month, $day);
        }

        if ($year < 1200 || $month < 1 || $month > 12) {
            return null;
        }

        return ['year' => $year, 'month' => $month];
    }

    /** @return array<int,array{year:int,month:int,label:string}> */
    private function score_period_months($period): array
    {
        if (!is_object($period)) {
            return [];
        }

        $start = $this->score_period_jalali_year_month((string) ($period->start_date ?? ''));
        $end = $this->score_period_jalali_year_month((string) ($period->end_date ?? ''));
        if (!$start || !$end) {
            return [];
        }

        $start_index = ($start['year'] * 12) + $start['month'] - 1;
        $end_index = ($end['year'] * 12) + $end['month'] - 1;
        if ($end_index < $start_index || ($end_index - $start_index) > 59) {
            return [];
        }

        $names = [
            1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
            5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
            9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
        ];
        $months = [];
        for ($cursor = $start_index; $cursor <= $end_index; $cursor++) {
            $year = intdiv($cursor, 12);
            $month = ($cursor % 12) + 1;
            $months[] = [
                'year' => $year,
                'month' => $month,
                'label' => $names[$month] ?? '',
            ];
        }

        $labels = array_column($months, 'label');
        if (count($labels) !== count(array_unique($labels))) {
            foreach ($months as &$month) {
                $month['label'] .= ' ' . (class_exists('HST_Date')
                    ? HST_Date::fa_digits((string) $month['year'])
                    : (string) $month['year']);
            }
            unset($month);
        }

        return $months;
    }

    private function join_score_period_month_labels(array $months): string
    {
        $labels = array_values(array_filter(array_map(static function (array $month): string {
            return trim((string) ($month['label'] ?? ''));
        }, $months)));
        $count = count($labels);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $labels[0];
        }
        if ($count === 2) {
            return $labels[0] . ' و ' . $labels[1];
        }

        $last = array_pop($labels);
        return implode('، ', $labels) . ' و ' . $last;
    }

    /** @return array<int,string> Labels keyed from 1 to score_count. */
    private function custom_period_score_labels($period, int $score_count): array
    {
        $score_count = max(1, min(self::MAX_CUSTOM_SCORE_COUNT, $score_count));
        $months = $this->score_period_months($period);
        if (!$months) {
            $labels = [];
            for ($index = 1; $index <= $score_count; $index++) {
                $labels[$index] = $score_count === 1 ? 'نمره اختصاصی' : 'نمره ' . number_format_i18n($index);
            }
            return $labels;
        }

        $month_count = count($months);
        $labels = [];
        if ($score_count <= $month_count) {
            $base_size = intdiv($month_count, $score_count);
            $remainder = $month_count % $score_count;
            $offset = 0;
            for ($index = 1; $index <= $score_count; $index++) {
                $group_size = $base_size + ($index <= $remainder ? 1 : 0);
                $group = array_slice($months, $offset, $group_size);
                $offset += $group_size;
                $labels[$index] = 'نمره ' . $this->join_score_period_month_labels($group);
            }
            return $labels;
        }

        $base_slots = intdiv($score_count, $month_count);
        $remainder = $score_count % $month_count;
        $score_index = 1;
        foreach ($months as $month_index => $month) {
            $slots = $base_slots + ($month_index < $remainder ? 1 : 0);
            for ($slot = 1; $slot <= $slots; $slot++, $score_index++) {
                $label = 'نمره ' . (string) ($month['label'] ?? '');
                if ($slots > 1) {
                    $label = 'نمره ' . number_format_i18n($slot) . ' ' . (string) ($month['label'] ?? '');
                }
                $labels[$score_index] = trim($label);
            }
        }

        return $labels;
    }

    private function period_score_slots(int $term_id, string $period_key): array
    {
        $period = $this->get_period_by_key($term_id, $period_key);
        if (!$period) {
            return [];
        }

        $type = $this->normalize_period_type((string) $period->period_type);
        $current_key = sanitize_key((string) $period->period_key);

        if ($type === 'first_shift') {
            return [
                ['key' => 'continuous_1', 'label' => 'نمره مستمر اول', 'editable' => true, 'source_period_key' => $current_key, 'source_score_key' => 'continuous_1'],
                ['key' => 'final_1', 'label' => 'نمره پایانی اول', 'editable' => true, 'source_period_key' => $current_key, 'source_score_key' => 'final_1'],
            ];
        }

        if ($type === 'second_shift') {
            $first_period = $this->first_shift_period_for($term_id, $period);
            $first_key = $first_period ? sanitize_key((string) $first_period->period_key) : '';

            return [
                ['key' => 'continuous_1', 'label' => 'نمره مستمر اول', 'editable' => false, 'source_period_key' => $first_key, 'source_score_key' => 'continuous_1'],
                ['key' => 'final_1', 'label' => 'نمره پایانی اول', 'editable' => false, 'source_period_key' => $first_key, 'source_score_key' => 'final_1'],
                ['key' => 'continuous_2', 'label' => 'نمره مستمر دوم', 'editable' => true, 'source_period_key' => $current_key, 'source_score_key' => 'continuous_2'],
                ['key' => 'final_2', 'label' => 'نمره پایانی دوم', 'editable' => true, 'source_period_key' => $current_key, 'source_score_key' => 'final_2'],
            ];
        }

        $count = $this->normalize_period_score_count($type, $period->score_count ?? 1);
        $custom_labels = $type === 'custom' ? $this->custom_period_score_labels($period, $count) : [];
        $slots = [];
        for ($i = 1; $i <= $count; $i++) {
            $key = 'score_' . $i;
            $label = $type === 'custom' ? (string) ($custom_labels[$i] ?? ('نمره ' . number_format_i18n($i))) : 'نمره';
            $slots[] = [
                'key' => $key,
                'label' => $label,
                'editable' => true,
                'source_period_key' => $current_key,
                'source_score_key' => $key,
            ];
        }

        return $slots;
    }

    private function editable_period_score_slots(int $term_id, string $period_key): array
    {
        return array_values(array_filter(
            $this->period_score_slots($term_id, $period_key),
            static fn(array $slot): bool => !empty($slot['editable'])
        ));
    }

    private function score_records_for_slots(
        int $term_id,
        int $class_id,
        int $lesson_id,
        int $teacher_id,
        array $student_ids,
        array $slots
    ): array {
        global $wpdb;

        $student_ids = array_values(array_filter(array_map('absint', $student_ids)));
        if (!$student_ids || !$slots) {
            return [];
        }

        $pair_sql = [];
        $args = [$term_id, $class_id, $lesson_id];
        $manager_discipline = $teacher_id === self::MANAGER_DISCIPLINE_TEACHER_ID
            && $this->is_discipline_lesson($lesson_id, $class_id);
        foreach ($slots as $slot) {
            $period_key = sanitize_key((string) ($slot['source_period_key'] ?? ''));
            $score_key = sanitize_key((string) ($slot['source_score_key'] ?? ''));
            if ($period_key === '' || $score_key === '') {
                continue;
            }

            // Editable scores belong to the selected teacher. Read-only scores
            // inherited from نوبت اول must remain visible even if the teacher
            // assignment changed before نوبت دوم.
            if (!empty($slot['editable']) || $manager_discipline) {
                $pair_sql[] = '(month_key = %s AND score_key = %s AND teacher_id = %d)';
                $args[] = $period_key;
                $args[] = $score_key;
                $args[] = $teacher_id;
            } else {
                $pair_sql[] = '(month_key = %s AND score_key = %s)';
                $args[] = $period_key;
                $args[] = $score_key;
            }
        }
        if (!$pair_sql) {
            return [];
        }

        $student_placeholders = implode(',', array_fill(0, count($student_ids), '%d'));
        foreach ($student_ids as $student_id) {
            $args[] = $student_id;
        }

        $query = "SELECT id, student_id, month_key, score_key, score, description, is_present, absence_excused
                  FROM {$wpdb->prefix}hst_monthly_scores
                  WHERE term_id = %d
                    AND class_id = %d
                    AND lesson_id = %d
                    AND (" . implode(' OR ', $pair_sql) . ")
                    AND student_id IN ({$student_placeholders})
                  ORDER BY COALESCE(updated_at, created_at) DESC, id DESC";
        $rows = $wpdb->get_results($wpdb->prepare($query, ...$args), ARRAY_A) ?: [];

        $source_index = [];
        foreach ($rows as $row) {
            $sid = (int) ($row['student_id'] ?? 0);
            $source = sanitize_key((string) ($row['month_key'] ?? '')) . ':' . sanitize_key((string) ($row['score_key'] ?? ''));
            if ($sid > 0 && !isset($source_index[$sid][$source])) {
                $source_index[$sid][$source] = [
                    'score' => $row['score'],
                    'description' => (string) ($row['description'] ?? ''),
                    'is_present' => (int) ($row['is_present'] ?? 1),
                    'absence_excused' => isset($row['absence_excused']) ? (int) $row['absence_excused'] : 0,
                ];
            }
        }

        $result = [];
        foreach ($student_ids as $student_id) {
            foreach ($slots as $slot) {
                $source = sanitize_key((string) ($slot['source_period_key'] ?? '')) . ':' . sanitize_key((string) ($slot['source_score_key'] ?? ''));
                $item = $source_index[$student_id][$source] ?? [
                    'score' => null,
                    'description' => '',
                    'is_present' => 1,
                    'absence_excused' => 0,
                ];
                $item['editable'] = !empty($slot['editable']);
                $result[$student_id][(string) $slot['key']] = $item;
            }
        }

        return $result;
    }

    private function count_registered_score_records(
        int $term_id,
        string $period_key,
        int $class_id = 0,
        int $lesson_id = 0,
        ?int $teacher_id = null
    ): int {
        global $wpdb;

        $slots = $this->period_score_slots($term_id, $period_key);
        $pair_sql = [];
        $args = [$term_id];
        $manager_discipline = $teacher_id === self::MANAGER_DISCIPLINE_TEACHER_ID
            && $lesson_id > 0
            && $this->is_discipline_lesson($lesson_id, $class_id);
        foreach ($slots as $slot) {
            $source_period = sanitize_key((string) ($slot['source_period_key'] ?? ''));
            $source_score = sanitize_key((string) ($slot['source_score_key'] ?? ''));
            if ($source_period === '' || $source_score === '') {
                continue;
            }

            if ($teacher_id !== null && (!empty($slot['editable']) || $manager_discipline)) {
                $pair_sql[] = '(month_key = %s AND score_key = %s AND teacher_id = %d)';
                $args[] = $source_period;
                $args[] = $source_score;
                $args[] = $teacher_id;
            } else {
                $pair_sql[] = '(month_key = %s AND score_key = %s)';
                $args[] = $source_period;
                $args[] = $source_score;
            }
        }
        if (!$pair_sql) {
            return 0;
        }

        $where = ['term_id = %d', '(' . implode(' OR ', $pair_sql) . ')', '(score IS NOT NULL OR is_present = 0)'];
        if ($class_id > 0) {
            $where[] = 'class_id = %d';
            $args[] = $class_id;
        }
        if ($lesson_id > 0) {
            $where[] = 'lesson_id = %d';
            $args[] = $lesson_id;
        }

        // In a teacher-specific audit, inherited first-term scores can come
        // from another teacher and must count only once per student/slot. In
        // the global period report, teacher_id remains part of the logical key.
        $distinct = $teacher_id !== null
            ? "CONCAT(student_id, ':', month_key, ':', score_key)"
            : "CONCAT(teacher_id, ':', student_id, ':', month_key, ':', score_key)";

        $query = "SELECT COUNT(DISTINCT {$distinct}) FROM {$wpdb->prefix}hst_monthly_scores WHERE " . implode(' AND ', $where);
        return (int) $wpdb->get_var($wpdb->prepare($query, ...$args));
    }


    private function migrate_legacy_months_to_periods(int $term_id): void
    {
        global $wpdb;

        $periods_table = $this->score_periods_table();
        if (!$this->table_exists($periods_table)) {
            return;
        }

        $has_periods = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$periods_table} WHERE term_id = %d",
            $term_id
        ));
        if ($has_periods > 0) {
            return;
        }

        $legacy_table = $wpdb->prefix . 'hst_' . 'score_months';
        if (!$this->table_exists($legacy_table)) {
            return;
        }

        $legacy_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT month_key, month_label, is_active, sort_order, created_at, updated_at
             FROM {$legacy_table}
             WHERE term_id = %d
             ORDER BY sort_order ASC, id ASC",
            $term_id
        )) ?: [];

        foreach ($legacy_rows as $row) {
            $period_key = sanitize_key((string) $row->month_key);
            if ($period_key === '') {
                $period_key = $this->generate_period_key($term_id);
            }

            $wpdb->insert(
                $periods_table,
                [
                    'term_id'       => $term_id,
                    'period_key'    => $period_key,
                    'period_name'   => sanitize_text_field((string) $row->month_label),
                    'period_type'   => 'custom',
                    'score_count'   => 1,
                    'start_date'    => '',
                    'end_date'      => '',
                    'deadline_date' => '',
                    'description'   => '',
                    'is_active'     => absint($row->is_active),
                    'sort_order'    => absint($row->sort_order),
                    'created_at'    => $row->created_at ?: current_time('mysql'),
                    'updated_at'    => $row->updated_at ?: null,
                ],
                ['%d','%s','%s','%s','%d','%s','%s','%s','%s','%d','%d','%s','%s']
            );
        }
    }

    public function get_term_periods($term_id, $active_only = false)
    {
        global $wpdb;

        $term_id = absint($term_id);
        if (!$term_id) {
            return [];
        }

        $this->ensure_term_periods($term_id);

        $table = $this->score_periods_table();
        if (!$this->table_exists($table)) {
            return [];
        }

        $where = 'WHERE term_id = %d';
        if ($active_only) {
            $where .= ' AND is_active = 1';
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, term_id, period_key, period_name, period_type, score_count, start_date, end_date, deadline_date, description, is_active, sort_order, created_at, updated_at
                 FROM {$table}
                 {$where}
                 ORDER BY sort_order ASC, id ASC",
                $term_id
            )
        ) ?: [];

        $types = self::PERIOD_TYPES;
        foreach ($rows as $row) {
            $row->period_type = $this->normalize_period_type((string) $row->period_type);
            $row->score_count = $this->normalize_period_score_count($row->period_type, $row->score_count ?? 1);
            $row->period_label = $row->period_name;
            $row->period_type_label = $types[$row->period_type] ?? 'اختصاصی';
            $row->score_slots = $this->period_score_slots($term_id, (string) $row->period_key);

            // Backward-compatible aliases for score templates/data storage.
            $row->month_key = $row->period_key;
            $row->month_label = $row->period_name;
        }

        return $rows;
    }

    public function get_term_months($term_id, $active_only = false)
    {
        return $this->get_term_periods($term_id, $active_only);
    }

    private function period_exists(int $term_id, string $period_key): bool
    {
        global $wpdb;

        $period_key = sanitize_key($period_key);
        if (!$term_id || $period_key === '') {
            return false;
        }

        $this->ensure_term_periods($term_id);
        $table = $this->score_periods_table();
        if (!$this->table_exists($table)) {
            return false;
        }

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE term_id = %d AND period_key = %s LIMIT 1",
            $term_id,
            $period_key
        ));
    }


    private function period_key_from_request(): string
    {
        $key = sanitize_key(wp_unslash($_POST['period_key'] ?? ''));
        if ($key === '') {
            $key = $this->post_key('month_key');
        }
        return $key;
    }


    private function score_entry_access_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_score_entry_access';
    }

    private function score_entry_access_table_exists(): bool
    {
        return $this->table_exists($this->score_entry_access_table());
    }

    private function score_entry_access_enabled(int $teacher_id, int $term_id, int $class_id, int $lesson_id, string $period_key): bool
    {
        global $wpdb;

        $period_key = sanitize_key($period_key);
        if (!$teacher_id || !$term_id || !$class_id || !$lesson_id || $period_key === '') {
            return false;
        }

        if (!$this->score_entry_access_table_exists()) {
            return true;
        }

        $table = $this->score_entry_access_table();
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT is_enabled
             FROM {$table}
             WHERE term_id = %d
                AND class_id = %d
                AND lesson_id = %d
                AND teacher_id = %d
                AND period_key = %s
             LIMIT 1",
            $term_id,
            $class_id,
            $lesson_id,
            $teacher_id,
            $period_key
        ));

        return $value === null ? true : ((int) $value === 1);
    }

    private function set_score_entry_access(int $teacher_id, int $term_id, int $class_id, int $lesson_id, string $period_key, bool $enabled): bool
    {
        global $wpdb;

        $period_key = sanitize_key($period_key);
        if (!$teacher_id || !$term_id || !$class_id || !$lesson_id || $period_key === '') {
            return false;
        }

        if (!$this->score_entry_access_table_exists() && class_exists('HST_Tables')) {
            (new HST_Tables())->hst_score_entry_access_table();
        }

        if (!$this->score_entry_access_table_exists()) {
            return false;
        }

        $table = $this->score_entry_access_table();
        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id
             FROM {$table}
             WHERE term_id = %d
                AND class_id = %d
                AND lesson_id = %d
                AND teacher_id = %d
                AND period_key = %s
             LIMIT 1",
            $term_id,
            $class_id,
            $lesson_id,
            $teacher_id,
            $period_key
        ));

        $now = current_time('mysql');
        $data = [
            'term_id'    => $term_id,
            'class_id'   => $class_id,
            'lesson_id'  => $lesson_id,
            'teacher_id' => $teacher_id,
            'period_key' => $period_key,
            'is_enabled' => $enabled ? 1 : 0,
            'updated_at' => $now,
        ];
        $formats = ['%d', '%d', '%d', '%d', '%s', '%d', '%s'];

        if ($this->score_entry_access_has_lock_columns()) {
            if ($enabled) {
                $data['unlocked_at'] = $now;
                $formats[] = '%s';
            } else {
                $data['locked_at'] = $now;
                $data['lock_source'] = 'manager';
                $formats[] = '%s';
                $formats[] = '%s';
            }
        }

        if ($existing_id) {
            return $wpdb->update(
                $table,
                $data,
                ['id' => $existing_id],
                $formats,
                ['%d']
            ) !== false;
        }

        $data['created_at'] = $now;
        $formats[] = '%s';

        return $wpdb->insert(
            $table,
            $data,
            $formats
        ) !== false;
    }

    private function score_entry_access_summary(int $teacher_id, int $term_id, int $class_id, int $lesson_id, array $period_keys): array
    {
        $period_keys = array_values(array_unique(array_filter(array_map('sanitize_key', $period_keys))));

        if (!$teacher_id || !$term_id || !$class_id || !$lesson_id || empty($period_keys)) {
            return [
                'status'  => 'inactive',
                'label'   => 'دوره‌ای انتخاب نشده',
                'checked' => false,
                'enabled' => 0,
                'total'   => count($period_keys),
            ];
        }

        $enabled = 0;
        foreach ($period_keys as $period_key) {
            if ($this->score_entry_access_enabled($teacher_id, $term_id, $class_id, $lesson_id, $period_key)) {
                $enabled++;
            }
        }

        $total = count($period_keys);
        if ($enabled === $total) {
            $status = 'active';
            $label = 'دسترسی ثبت نمره فعال';
        } elseif ($enabled > 0) {
            $status = 'partial';
            $label = 'دسترسی ثبت نمره بخشی فعال';
        } else {
            $status = 'inactive';
            $label = 'دسترسی ثبت نمره غیرفعال';
        }

        return [
            'status'  => $status,
            'label'   => $label,
            'checked' => $enabled > 0,
            'enabled' => $enabled,
            'total'   => $total,
        ];
    }

    private function expected_score_slots(int $term_id): int
    {
        global $wpdb;

        $assignments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tl.user_id AS teacher_id, tl.class_id, tl.lesson_id
                 FROM {$wpdb->prefix}hst_users_lessons tl
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = tl.lesson_id
                 WHERE tl.term_id = %d
                   AND tl.role = 'teacher'
                   AND TRIM(l.lesson_name) <> %s
                 GROUP BY tl.user_id, tl.class_id, tl.lesson_id",
                $term_id,
                self::DISCIPLINE_LESSON_NAME
            )
        ) ?: [];

        $expected = 0;
        foreach ($assignments as $assignment) {
            $expected += (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT sl.user_id)
                     FROM {$wpdb->prefix}hst_users_lessons sl
                     INNER JOIN {$wpdb->prefix}hst_users_classes sc
                        ON sc.user_id = sl.user_id
                        AND sc.term_id = sl.term_id
                        AND sc.class_id = sl.class_id
                        AND sc.role = 'student'
                     WHERE sl.term_id = %d
                        AND sl.role = 'student'
                        AND sl.class_id = %d
                        AND sl.lesson_id = %d",
                    $term_id,
                    (int) $assignment->class_id,
                    (int) $assignment->lesson_id
                )
            );
        }

        $discipline_classes = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT class_id
             FROM {$wpdb->prefix}hst_lessons
             WHERE TRIM(lesson_name) = %s",
            self::DISCIPLINE_LESSON_NAME
        )) ?: [];

        foreach ($discipline_classes as $class_id) {
            $expected += (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id)
                 FROM {$wpdb->prefix}hst_users_classes
                 WHERE term_id = %d AND class_id = %d AND role = 'student'",
                $term_id,
                (int) $class_id
            ));
        }

        return $expected;
    }

    private function registered_score_slots(int $term_id, string $period_key): int
    {
        return $this->count_registered_score_records($term_id, $period_key);
    }



    private function score_period_delete_dependency_count(int $term_id, string $period_key): int
    {
        global $wpdb;

        $period_key = sanitize_key($period_key);
        if (!$term_id || $period_key === '') {
            return 0;
        }

        $count = 0;

        $monthly_table = $wpdb->prefix . 'hst_monthly_scores';
        if ($this->table_exists($monthly_table)) {
            $count += (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$monthly_table} WHERE term_id = %d AND month_key = %s",
                $term_id,
                $period_key
            ));
        }

        $gradebook_table = $wpdb->prefix . 'hst_gradebook';
        if ($this->table_exists($gradebook_table)) {
            $count += (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$gradebook_table} WHERE term_id = %d AND month_key = %s",
                $term_id,
                $period_key
            ));
        }

        return $count;
    }

    public function get_score_periods_context(): array
    {
        $active_term = $this->get_active_term();

        if (!$active_term) {
            return [
                'active_term' => null,
                'periods' => [],
                'types' => self::PERIOD_TYPES,
                'summary' => ['total' => 0, 'active' => 0, 'inactive' => 0, 'complete' => 0],
            ];
        }

        $term_id = (int) $active_term->id;
        $periods = $this->get_term_periods($term_id, false);
        $assignment_student_slots = $this->expected_score_slots($term_id);

        $active = 0;
        $complete = 0;

        foreach ($periods as $period) {
            if ((int) $period->is_active === 1) {
                $active++;
            }

            $slot_count = count($this->period_score_slots($term_id, (string) $period->period_key));
            $expected = $assignment_student_slots * $slot_count;
            $registered = $this->registered_score_slots($term_id, (string) $period->period_key);
            $percent = $expected > 0 ? min(100, round(($registered / $expected) * 100)) : 0;

            $delete_block_count = $this->score_period_delete_dependency_count($term_id, (string) $period->period_key);

            $period->registered_scores = $registered;
            $period->expected_scores = $expected;
            $period->completion_percent = $percent;
            $period->completion_status = $percent >= 100 ? 'complete' : ($percent > 0 ? 'partial' : 'missing');
            $period->delete_block_count = $delete_block_count;
            $period->can_delete = $delete_block_count > 0 ? 0 : 1;
            $period->delete_disabled_reason = $delete_block_count > 0 ? 'برای این دوره نمره ثبت شده است و قابل حذف نیست.' : '';

            if ($period->completion_status === 'complete') {
                $complete++;
            }
        }

        $total = count($periods);
        $inactive = max(0, $total - $active);

        return [
            'active_term' => $active_term,
            'periods' => $periods,
            'types' => self::PERIOD_TYPES,
            'summary' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'complete' => $complete,
            ],
        ];
    }


    public function ajax_save_score_period()
    {
        $this->authorize_ajax('read');

        if (!$this->user_can_manage_scores()) {
            $this->fail('دسترسی غیرمجاز است.', 403);
        }

        $active_term = $this->get_active_term();
        if (!$active_term) {
            $this->fail('سال تحصیلی فعالی وجود ندارد.');
        }

        $term_id = (int) $active_term->id;
        $id = $this->post_int('id');
        $period_name = sanitize_text_field(wp_unslash($_POST['period_name'] ?? ''));
        $period_type = $this->normalize_period_type((string) wp_unslash($_POST['period_type'] ?? 'custom'));
        $raw_score_count = absint(wp_unslash($_POST['score_count'] ?? 1));
        if ($period_type === 'custom' && ($raw_score_count < 1 || $raw_score_count > self::MAX_CUSTOM_SCORE_COUNT)) {
            $this->fail('تعداد نمره‌های دوره اختصاصی باید بین ۱ تا ۲۰ باشد.');
        }
        $score_count = $this->normalize_period_score_count($period_type, $raw_score_count);
        $start_date = $this->normalize_period_date($_POST['start_date'] ?? '');
        $end_date = $this->normalize_period_date($_POST['end_date'] ?? '');
        $deadline_date = $this->normalize_period_date($_POST['deadline_date'] ?? '');
        $description = sanitize_textarea_field((string) wp_unslash($_POST['description'] ?? ''));

        if (function_exists('mb_substr')) {
            $period_name = mb_substr($period_name, 0, 120);
            $description = mb_substr($description, 0, 1000);
        } else {
            $period_name = substr($period_name, 0, 120);
            $description = substr($description, 0, 1000);
        }

        if ($period_name === '') {
            $this->fail('نام دوره الزامی است.');
        }
        if ($start_date === '' || $end_date === '' || $deadline_date === '') {
            $this->fail('تاریخ شروع، تاریخ پایان و مهلت ثبت نمره الزامی است.');
        }
        if ($period_type === 'second_shift' && !$this->term_has_period_type($term_id, 'first_shift', $id)) {
            $this->fail('برای تعریف دوره نوبت دوم، ابتدا باید یک دوره نوبت اول ایجاد شود.');
        }

        global $wpdb;
        $table = $this->score_periods_table();

        if ($id > 0) {
            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT id, period_key, period_type, score_count FROM {$table} WHERE id = %d AND term_id = %d LIMIT 1",
                $id,
                $term_id
            ));
            if (!$current) {
                $this->fail('دوره انتخاب‌شده پیدا نشد.');
            }

            $current_type = $this->normalize_period_type((string) $current->period_type);
            $current_count = $this->normalize_period_score_count($current_type, $current->score_count ?? 1);
            if (
                $current_type === 'first_shift'
                && $period_type !== 'first_shift'
                && $this->term_has_period_type($term_id, 'second_shift')
                && !$this->term_has_period_type($term_id, 'first_shift', $id)
            ) {
                $this->fail('تا زمانی که دوره نوبت دوم وجود دارد، آخرین دوره نوبت اول قابل تغییر نوع نیست.');
            }
            $has_scores = $this->score_period_delete_dependency_count($term_id, (string) $current->period_key) > 0;
            if ($has_scores && ($current_type !== $period_type || $current_count !== $score_count)) {
                $this->fail('برای این دوره نمره ثبت شده است؛ نوع دوره یا تعداد نمره‌ها قابل تغییر نیست.');
            }

            $updated = $wpdb->update(
                $table,
                [
                    'period_name'   => $period_name,
                    'period_type'   => $period_type,
                    'score_count'   => $score_count,
                    'start_date'    => $start_date,
                    'end_date'      => $end_date,
                    'deadline_date' => $deadline_date,
                    'description'   => $description,
                    'updated_at'    => current_time('mysql'),
                ],
                ['id' => $id, 'term_id' => $term_id],
                ['%s','%s','%d','%s','%s','%s','%s','%s'],
                ['%d','%d']
            );

            if ($updated === false) {
                $this->fail('ویرایش دوره انجام نشد.');
            }

            wp_send_json_success(['message' => 'دوره با موفقیت ویرایش شد.']);
        }

        $sort_order = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$table} WHERE term_id = %d",
            $term_id
        ));

        $inserted = $wpdb->insert(
            $table,
            [
                'term_id'       => $term_id,
                'period_key'    => $this->generate_period_key($term_id),
                'period_name'   => $period_name,
                'period_type'   => $period_type,
                'score_count'   => $score_count,
                'start_date'    => $start_date,
                'end_date'      => $end_date,
                'deadline_date' => $deadline_date,
                'description'   => $description,
                'is_active'     => 0,
                'sort_order'    => $sort_order,
                'created_at'    => current_time('mysql'),
            ],
            ['%d','%s','%s','%s','%d','%s','%s','%s','%s','%d','%d','%s']
        );

        if ($inserted === false) {
            $this->fail('افزودن دوره انجام نشد.');
        }

        wp_send_json_success(['message' => 'دوره با موفقیت افزوده شد.']);
    }


    public function ajax_delete_score_period()
    {
        $this->authorize_ajax('read');

        if (!$this->user_can_manage_scores()) {
            $this->fail('دسترسی غیرمجاز است.', 403);
        }

        $active_term = $this->get_active_term();
        if (!$active_term) {
            $this->fail('سال تحصیلی فعالی وجود ندارد.');
        }

        $id = $this->post_int('id');
        if (!$id) {
            $this->fail('شناسه دوره نامعتبر است.');
        }

        global $wpdb;
        $table = $this->score_periods_table();
        $period = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND term_id = %d",
            $id,
            (int) $active_term->id
        ));

        if (!$period) {
            $this->fail('دوره پیدا نشد.');
        }

        if (
            $this->normalize_period_type((string) $period->period_type) === 'first_shift'
            && $this->term_has_period_type((int) $active_term->id, 'second_shift')
            && !$this->term_has_period_type((int) $active_term->id, 'first_shift', $id)
        ) {
            $this->fail('تا زمانی که دوره نوبت دوم وجود دارد، آخرین دوره نوبت اول قابل حذف نیست.');
        }

        $score_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hst_monthly_scores WHERE term_id = %d AND month_key = %s",
            (int) $active_term->id,
            (string) $period->period_key
        ));
        $gradebook_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hst_gradebook WHERE term_id = %d AND month_key = %s",
            (int) $active_term->id,
            (string) $period->period_key
        ));

        if (($score_count + $gradebook_count) > 0) {
            $this->fail('برای این دوره نمره ثبت شده است و امکان حذف آن وجود ندارد.');
        }

        $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);
        if ($deleted === false) {
            $this->fail('حذف دوره انجام نشد.');
        }

        wp_send_json_success(['message' => 'دوره حذف شد.']);
    }

    public function ajax_toggle_score_period()
    {
        $this->authorize_ajax('read');

        if (!$this->user_can_manage_scores()) {
            $this->fail('دسترسی غیرمجاز است.', 403);
        }

        $id = $this->post_int('id');
        $is_active = !empty(wp_unslash($_POST['is_active'] ?? 0)) ? 1 : 0;

        if (!$id) {
            $this->fail('شناسه دوره نامعتبر است.');
        }

        global $wpdb;
        $updated = $wpdb->update(
            $this->score_periods_table(),
            ['is_active' => $is_active, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%d','%s'],
            ['%d']
        );

        if ($updated === false) {
            $this->fail('تغییر وضعیت دوره انجام نشد.');
        }

        wp_send_json_success([
            'message' => $is_active ? 'دوره فعال شد.' : 'دوره غیرفعال شد.',
        ]);
    }

    private function get_teacher_classes($teacher_id, $term_id)
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT c.id, c.class_name
                 FROM {$wpdb->prefix}hst_users_classes tc
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = tc.class_id
                 WHERE tc.user_id = %d
                    AND tc.term_id = %d
                    AND tc.role = 'teacher'
                 ORDER BY c.class_name ASC",
                $teacher_id,
                $term_id
            )
        ) ?: [];

        return HST_Classes::sort_rows($rows);
    }

    private function get_teacher_lessons_for_class($teacher_id, $term_id, $class_id)
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT l.id, l.lesson_name, l.unit
                 FROM {$wpdb->prefix}hst_users_lessons tl
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = tl.lesson_id
                 WHERE tl.user_id = %d
                    AND tl.term_id = %d
                    AND tl.class_id = %d
                    AND tl.role = 'teacher'
                    AND TRIM(l.lesson_name) <> %s
                 ORDER BY l.lesson_name ASC",
                $teacher_id,
                $term_id,
                $class_id,
                self::DISCIPLINE_LESSON_NAME
            )
        ) ?: [];
    }

    private function get_common_students($teacher_id, $term_id, $class_id, $lesson_id)
    {
        global $wpdb;

        $students = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT u.ID, u.display_name,
                    (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = u.ID AND meta_key = 'last_name' LIMIT 1) AS last_name
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
                 INNER JOIN {$wpdb->prefix}hst_users_classes tc
                    ON tc.user_id = %d
                    AND tc.role = 'teacher'
                    AND tc.term_id = sc.term_id
                    AND tc.class_id = sc.class_id
                 INNER JOIN {$wpdb->prefix}hst_users_lessons tl
                    ON tl.user_id = %d
                    AND tl.role = 'teacher'
                    AND tl.term_id = sc.term_id
                    AND tl.class_id = sc.class_id
                    AND tl.lesson_id = sl.lesson_id",
                $term_id,
                $class_id,
                $lesson_id,
                $teacher_id,
                $teacher_id
            )
        ) ?: [];

        return class_exists('HST_Students') ? HST_Students::sort_student_rows($students) : $students;
    }

    /**
     * Add an `avatar_url` field to each student object (thumbnail of their
     * public/approved profile image), so client-rendered lists can show it
     * consistently with the rest of the plugin. Empty string when none.
     *
     * @param array $students
     * @return array
     */
    private function attach_avatars($students)
    {
        foreach ((array) $students as $student) {
            $user_id = (int) ($student->ID ?? 0);
            $attachment_id = 0;
            if ($user_id) {
                if (class_exists('HST_Avatar_Approval')) {
                    $attachment_id = (int) HST_Avatar_Approval::display_avatar_id($user_id, get_current_user_id());
                } else {
                    $attachment_id = (int) get_user_meta($user_id, 'hst_profile_avatar_id', true);
                }
            }
            $student->avatar_url = $attachment_id
                ? (string) wp_get_attachment_image_url($attachment_id, 'thumbnail')
                : '';
        }
        return $students;
    }

    private function teacher_can_score($teacher_id, $term_id, $class_id, $lesson_id)
    {
        global $wpdb;

        if ($this->is_discipline_lesson((int) $lesson_id, (int) $class_id)) {
            return false;
        }

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$wpdb->prefix}hst_users_lessons
                 WHERE user_id = %d
                    AND term_id = %d
                    AND class_id = %d
                    AND lesson_id = %d
                    AND role = 'teacher'
                 LIMIT 1",
                $teacher_id,
                $term_id,
                $class_id,
                $lesson_id
            )
        );
    }


    public function ajax_get_teacher_score_context()
    {
        $this->authorize_ajax('read');

        if (!$this->user_can_teach()) {
            $this->fail('این بخش مخصوص معلمان است.', 403);
        }

        $teacher_id = get_current_user_id();
        $active_term = $this->get_active_term();

        if (!$active_term) {
            $this->fail('سال تحصیلی فعالی برای ثبت نمره وجود ندارد.');
        }

        $class_id = $this->post_int('class_id');
        $lesson_id = $this->post_int('lesson_id');

        $classes = $this->get_teacher_classes($teacher_id, (int) $active_term->id);
        $lessons = $class_id ? $this->get_teacher_lessons_for_class($teacher_id, (int) $active_term->id, $class_id) : [];
        $students = ($class_id && $lesson_id && $this->teacher_can_score($teacher_id, (int) $active_term->id, $class_id, $lesson_id))
            ? $this->attach_avatars($this->get_common_students($teacher_id, (int) $active_term->id, $class_id, $lesson_id))
            : [];

        wp_send_json_success([
            'term'     => $active_term,
            'periods'  => $this->get_term_periods((int) $active_term->id, false),
            'classes'  => $classes,
            'lessons'  => $lessons,
            'students' => $students,
        ]);
    }

    public function ajax_get_monthly_scores()
    {
        $this->authorize_ajax('read');

        $teacher_id = get_current_user_id();
        $active_term = $this->get_active_term();
        $class_id = $this->post_int('class_id');
        $lesson_id = $this->post_int('lesson_id');
        $period_key = $this->period_key_from_request();

        if (!$active_term || !$class_id || !$lesson_id || !$this->period_exists((int) $active_term->id, $period_key)) {
            $this->fail('اطلاعات ناقص است.');
        }

        $term_id = (int) $active_term->id;
        if (!$this->teacher_can_score($teacher_id, $term_id, $class_id, $lesson_id)) {
            $this->fail('شما برای این درس اجازه ثبت نمره ندارید.', 403);
        }

        $students = $this->attach_avatars($this->get_common_students($teacher_id, $term_id, $class_id, $lesson_id));
        $student_ids = array_map(static fn($student) => (int) ($student->ID ?? 0), $students);
        $slots = $this->period_score_slots($term_id, $period_key);
        $scores = $this->score_records_for_slots($term_id, $class_id, $lesson_id, $teacher_id, $student_ids, $slots);

        $suggestions = [];
        foreach ($students as $student) {
            $student_id = (int) ($student->ID ?? 0);
            if (!$student_id) {
                continue;
            }
            $average = self::gradebook_average($term_id, $class_id, $lesson_id, $teacher_id, $student_id, $period_key);
            if ($average !== null) {
                $suggestions[$student_id] = $average;
            }
        }

        $period = $this->get_period_by_key($term_id, $period_key);
        $access_enabled = $this->score_entry_access_enabled($teacher_id, $term_id, $class_id, $lesson_id, $period_key);

        wp_send_json_success([
            'students'         => $students,
            'scores'           => $scores,
            'slots'            => $slots,
            'suggestions'      => $suggestions,
            'period'           => $period,
            'access_enabled'   => $access_enabled,
            'period_is_active' => $access_enabled,
            'month_is_active'  => $access_enabled,
        ]);
    }

    public function ajax_save_monthly_scores()
    {
        $this->authorize_ajax('read');

        if (!$this->user_can_teach()) {
            $this->fail('این بخش مخصوص معلمان است.', 403);
        }

        $teacher_id = get_current_user_id();
        $active_term = $this->get_active_term();
        $class_id = $this->post_int('class_id');
        $lesson_id = $this->post_int('lesson_id');
        $period_key = $this->period_key_from_request();
        $scores = $this->posted_scores_payload();

        if (!$active_term || !$class_id || !$lesson_id || !$this->period_exists((int) $active_term->id, $period_key) || !is_array($scores)) {
            $this->fail('اطلاعات ثبت نمره ناقص است.');
        }
        if (count($scores) > 250) {
            $this->fail('تعداد دانش‌آموزان ارسالی بیش از حد مجاز است.');
        }

        $term_id = (int) $active_term->id;
        if (!$this->score_entry_access_enabled($teacher_id, $term_id, $class_id, $lesson_id, $period_key)) {
            $this->fail('دسترسی ثبت نمره برای این دبیر غیرفعال است.');
        }
        if (!$this->teacher_can_score($teacher_id, $term_id, $class_id, $lesson_id)) {
            $this->fail('شما برای این کلاس و درس اجازه ثبت نمره ندارید.', 403);
        }

        $editable_slots = $this->editable_period_score_slots($term_id, $period_key);
        $editable_map = [];
        foreach ($editable_slots as $slot) {
            $editable_map[(string) $slot['key']] = $slot;
        }
        if (!$editable_map) {
            $this->fail('برای این دوره نمره قابل ثبت تعریف نشده است.');
        }

        $students = $this->get_common_students($teacher_id, $term_id, $class_id, $lesson_id);
        $allowed_student_ids = array_map(static fn($student) => (int) $student->ID, $students);

        global $wpdb;
        $table = $wpdb->prefix . 'hst_monthly_scores';
        $has_audit_columns = $this->monthly_scores_has_audit_columns();
        $saved = 0;
        $newly_graded = [];

        foreach ($scores as $student_id => $student_items) {
            $student_id = absint($student_id);
            if (!$student_id || !in_array($student_id, $allowed_student_ids, true) || !is_array($student_items)) {
                continue;
            }

            if (array_key_exists('score', $student_items) || array_key_exists('present', $student_items)) {
                $first_key = (string) array_key_first($editable_map);
                $student_items = [$first_key => $student_items];
            }

            foreach ($student_items as $slot_key => $item) {
                $slot_key = sanitize_key((string) $slot_key);
                if (!isset($editable_map[$slot_key]) || !is_array($item)) {
                    continue;
                }

                $slot = $editable_map[$slot_key];
                $score_key = sanitize_key((string) $slot['source_score_key']);
                $is_present = array_key_exists('present', $item) ? (!empty($item['present']) ? 1 : 0) : 1;
                $absence_excused = $is_present ? null : (!empty($item['absence_excused']) ? 1 : 0);
                $raw_score = isset($item['score']) ? trim((string) $item['score']) : '';
                $description = isset($item['description']) ? sanitize_textarea_field((string) $item['description']) : '';

                if (function_exists('mb_strlen') && mb_strlen($description) > 500) {
                    $description = mb_substr($description, 0, 500);
                } elseif (strlen($description) > 500) {
                    $description = substr($description, 0, 500);
                }

                $scope = [
                    'term_id' => $term_id,
                    'class_id' => $class_id,
                    'lesson_id' => $lesson_id,
                    'teacher_id' => $teacher_id,
                    'student_id' => $student_id,
                    'month_key' => $period_key,
                    'score_key' => $score_key,
                ];
                $scope_formats = ['%d','%d','%d','%d','%d','%s','%s'];

                if ($is_present === 1 && $raw_score === '') {
                    $wpdb->delete($table, $scope, $scope_formats);
                    continue;
                }

                $score = $is_present ? $this->normalize_score_value($raw_score) : null;
                $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table}
                     WHERE term_id = %d AND class_id = %d AND lesson_id = %d AND teacher_id = %d
                        AND student_id = %d AND month_key = %s AND score_key = %s
                     LIMIT 1",
                    $term_id,
                    $class_id,
                    $lesson_id,
                    $teacher_id,
                    $student_id,
                    $period_key,
                    $score_key
                ));

                $now = current_time('mysql');
                if ($existing_id > 0) {
                    $payload = [
                        'score' => $score,
                        'is_present' => $is_present,
                        'absence_excused' => $absence_excused,
                        'description' => $description,
                        'updated_at' => $now,
                    ];
                    $formats = ['%f','%d','%d','%s','%s'];
                    if ($has_audit_columns) {
                        $payload['teacher_updated_at'] = $now;
                        $formats[] = '%s';
                    }
                    $result = $wpdb->update($table, $payload, ['id' => $existing_id], $formats, ['%d']);
                } else {
                    $payload = array_merge($scope, [
                        'score' => $score,
                        'is_present' => $is_present,
                        'absence_excused' => $absence_excused,
                        'description' => $description,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $formats = array_merge($scope_formats, ['%f','%d','%d','%s','%s','%s']);
                    if ($has_audit_columns) {
                        $payload['teacher_created_at'] = $now;
                        $formats[] = '%s';
                    }
                    $result = $wpdb->insert($table, $payload, $formats);
                    if ($result !== false) {
                        $newly_graded[] = $student_id;
                    }
                }

                if ($result === false) {
                    $this->fail('ذخیره‌سازی نمره انجام نشد. لطفاً دوباره تلاش کنید.');
                }
                $saved++;
            }
        }

        if ($newly_graded) {
            $lesson_name = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT lesson_name FROM {$wpdb->prefix}hst_lessons WHERE id = %d",
                $lesson_id
            ));
            foreach (array_unique($newly_graded) as $student_id) {
                do_action('hst_grade_registered', [
                    'student_id' => $student_id,
                    'lesson_name' => $lesson_name,
                    'teacher_id' => $teacher_id,
                ]);
            }
        }

        wp_send_json_success([
            'message' => sprintf('%d مورد با موفقیت ذخیره شد', $saved),
            'saved' => $saved,
        ]);
    }

    public function get_student_scores_context($student_id, array $filters = [])
    {
        $student_id = absint($student_id);
        $active_term = $this->get_active_term();

        if (!$student_id || !$active_term) {
            return [
                'active_term' => $active_term,
                'months'      => [],
                'lessons'     => [],
                'classes'     => [],
                'rows'        => [],
                'averages'    => [
                    'total' => 0,
                    'avg'   => null,
                    'best'  => null,
                    'low'   => null,
                ],
                'filters'     => [
                    'month_key' => '',
                    'lesson_id' => 0,
                    'class_id'  => 0,
                ],
            ];
        }

        $term_id = (int) $active_term->id;
        $this->ensure_term_periods($term_id);

        $month_key = sanitize_key($filters['month_key'] ?? '');
        $lesson_id = absint($filters['lesson_id'] ?? 0);
        $class_id = absint($filters['class_id'] ?? 0);

        if ($month_key && !$this->period_exists($term_id, $month_key)) {
            $month_key = '';
        }

        global $wpdb;

        $where = [
            $wpdb->prepare('ms.student_id = %d', $student_id),
            $wpdb->prepare('ms.term_id = %d', $term_id),
        ];

        if ($month_key) {
            $where[] = $wpdb->prepare('ms.month_key = %s', $month_key);
        }

        if ($lesson_id) {
            $where[] = $wpdb->prepare('ms.lesson_id = %d', $lesson_id);
        }

        if ($class_id) {
            $where[] = $wpdb->prepare('ms.class_id = %d', $class_id);
        }

        $where_sql = implode(' AND ', $where);
        $class_order = HST_Classes::sql_order_by('c.class_name', 'c.id');

        $rows = $wpdb->get_results(
            "SELECT
                ms.id,
                ms.month_key,
                ms.score_key,
                ms.score,
                ms.is_present,
                ms.absence_excused,
                ms.description,
                ms.updated_at,
                ms.created_at,
                c.class_name,
                l.lesson_name,
                CASE
                    WHEN ms.teacher_id = 0 AND TRIM(l.lesson_name) = 'انضباط' THEN 'مدیر مدرسه'
                    ELSE COALESCE(u.display_name, '—')
                END AS teacher_name,
                sp.period_name AS period_label,
                sp.period_type,
                sp.score_count,
                sp.sort_order
             FROM {$wpdb->prefix}hst_monthly_scores ms
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = ms.class_id
             INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = ms.lesson_id
             LEFT JOIN {$wpdb->users} u ON u.ID = ms.teacher_id
             LEFT JOIN {$wpdb->prefix}hst_score_periods sp
                ON sp.term_id = ms.term_id
                AND sp.period_key = ms.month_key
             WHERE {$where_sql}
               AND (TRIM(l.lesson_name) <> 'انضباط' OR ms.teacher_id = 0)
             ORDER BY COALESCE(sp.sort_order, 99) ASC, {$class_order}, l.lesson_name ASC,
                CASE
                    WHEN ms.score_key = 'continuous_1' THEN 1
                    WHEN ms.score_key = 'final_1' THEN 2
                    WHEN ms.score_key = 'continuous_2' THEN 3
                    WHEN ms.score_key = 'final_2' THEN 4
                    WHEN ms.score_key LIKE 'score_%' THEN 10 + CAST(SUBSTRING_INDEX(ms.score_key, '_', -1) AS UNSIGNED)
                    ELSE 999
                END ASC"
        ) ?: [];

        $months = $this->get_term_months($term_id, false);

        $lessons = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT l.id, l.lesson_name
                 FROM {$wpdb->prefix}hst_monthly_scores ms
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = ms.lesson_id
                 WHERE ms.student_id = %d AND ms.term_id = %d
                 ORDER BY l.lesson_name ASC",
                $student_id,
                $term_id
            )
        ) ?: [];

        $classes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT c.id, c.class_name
                 FROM {$wpdb->prefix}hst_monthly_scores ms
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = ms.class_id
                 WHERE ms.student_id = %d AND ms.term_id = %d
                 ORDER BY c.class_name ASC",
                $student_id,
                $term_id
            )
        ) ?: [];
        $classes = HST_Classes::sort_rows($classes);

        $score_values = [];
        foreach ($rows as $row) {
            $row->score_label = 'نمره';
            $slots = $this->period_score_slots($term_id, (string) $row->month_key);
            foreach ($slots as $slot) {
                if (sanitize_key((string) ($slot['source_period_key'] ?? '')) === sanitize_key((string) $row->month_key)
                    && sanitize_key((string) ($slot['source_score_key'] ?? '')) === sanitize_key((string) $row->score_key)) {
                    $row->score_label = (string) $slot['label'];
                    break;
                }
            }

            if ((int) ($row->is_present ?? 1) === 1 && $row->score !== null && $row->score !== '') {
                $score_values[] = (float) $row->score;
            }
        }
        $total = count($rows);
        $avg = $score_values ? round(array_sum($score_values) / count($score_values), 2) : null;

        return [
            'active_term' => $active_term,
            'periods'     => $months,
            'months'      => $months,
            'lessons'     => $lessons,
            'classes'     => $classes,
            'rows'        => $rows,
            'averages'    => [
                'total' => $total,
                'avg'   => $avg,
                'best'  => $score_values ? max($score_values) : null,
                'low'   => $score_values ? min($score_values) : null,
            ],
            'filters'     => [
                'month_key' => $month_key,
                'lesson_id' => $lesson_id,
                'class_id'  => $class_id,
            ],
        ];
    }

    public function get_admin_score_audit_context(array $filters = [])
    {
        $active_term = $this->get_active_term();

        $empty = [
            'active_term'    => $active_term,
            'periods'        => [],
            'months'         => [],
            'selected_period' => '',
            'selected_month' => '',
            'selected_period_label' => '',
            'requires_period' => true,
            'filters'        => [
                'teacher_id'     => 0,
                'teacher_search' => '',
                'class_id'       => 0,
                'lesson_id'      => 0,
                'status'         => '',
            ],
            'teachers'       => [],
            'classes'        => [],
            'lessons'        => [],
            'summary'        => [
                'total'       => 0,
                'registered'  => 0,
                'remaining'   => 0,
                'no_students' => 0,
            ],
            'rows'           => [],
            'details'        => [],
        ];

        if (!$active_term) {
            return $empty;
        }

        $term_id = (int) $active_term->id;
        $months = $this->get_term_months($term_id, false);
        $period_keys = array_values(array_filter(array_map(static function ($period) {
            return sanitize_key((string) ($period->period_key ?? $period->month_key ?? ''));
        }, $months)));

        $selected_month = sanitize_key($filters['month_key'] ?? '');
        if ($selected_month && !$this->period_exists($term_id, $selected_month)) {
            $selected_month = '';
        }

        $selected_period_label = '';
        if ($selected_month !== '') {
            foreach ($months as $period) {
                $key = sanitize_key((string) ($period->period_key ?? $period->month_key ?? ''));
                if ($key === $selected_month) {
                    $selected_period_label = (string) ($period->period_name ?? $period->month_label ?? $selected_month);
                    break;
                }
            }
        }

        $selected_period_keys = $selected_month ? [$selected_month] : [];
        $selected_slots = $selected_month ? $this->period_score_slots($term_id, $selected_month) : [];
        $period_count = count($selected_slots);

        $teacher_id = absint($filters['teacher_id'] ?? 0);
        $teacher_search = sanitize_text_field((string) ($filters['teacher_search'] ?? ''));
        if (function_exists('mb_substr')) {
            $teacher_search = mb_substr($teacher_search, 0, 80);
        } else {
            $teacher_search = substr($teacher_search, 0, 80);
        }

        $class_id = 0;
        $lesson_id = 0;
        $status = sanitize_key($filters['status'] ?? '');
        $allowed_statuses = ['registered', 'remaining'];

        if (!in_array($status, $allowed_statuses, true)) {
            $status = '';
        }

        if ($selected_month === '') {
            $empty['periods'] = $months;
            $empty['months'] = $months;
            $empty['selected_period'] = '';
            $empty['selected_month'] = '';
            $empty['selected_period_label'] = '';
            $empty['requires_period'] = true;
            $empty['filters']['teacher_id'] = $teacher_id;
            $empty['filters']['teacher_search'] = $teacher_search;
            $empty['filters']['status'] = $status;
            return $empty;
        }

        global $wpdb;

        $teacher_filter = $teacher_id ? $wpdb->prepare('AND tl.user_id = %d', $teacher_id) : '';
        $teacher_search_filter = $teacher_search !== '' ? $wpdb->prepare('AND u.display_name LIKE %s', '%' . $wpdb->esc_like($teacher_search) . '%') : '';
        $class_filter = '';
        $lesson_filter = '';

        $assignments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    tl.user_id AS teacher_id,
                    tl.class_id,
                    tl.lesson_id,
                    u.display_name AS teacher_name,
                    c.class_name,
                    l.lesson_name
                 FROM {$wpdb->prefix}hst_users_lessons tl
                 INNER JOIN {$wpdb->users} u ON u.ID = tl.user_id
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = tl.class_id
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = tl.lesson_id
                 WHERE tl.term_id = %d
                    AND tl.role = 'teacher'
                    AND TRIM(l.lesson_name) <> %s
                    {$teacher_filter}
                    {$teacher_search_filter}
                    {$class_filter}
                    {$lesson_filter}
                 GROUP BY tl.user_id, tl.class_id, tl.lesson_id, u.display_name, c.class_name, l.lesson_name
                 ORDER BY u.display_name ASC, c.class_name ASC, l.lesson_name ASC",
                $term_id,
                self::DISCIPLINE_LESSON_NAME
            )
        ) ?: [];

        if ($this->user_can_set_discipline_scores()) {
            $discipline_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    %d AS teacher_id,
                    l.class_id,
                    l.id AS lesson_id,
                    %s AS teacher_name,
                    c.class_name,
                    l.lesson_name,
                    1 AS manager_only
                 FROM {$wpdb->prefix}hst_lessons l
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = l.class_id
                 WHERE TRIM(l.lesson_name) = %s
                   AND EXISTS (
                       SELECT 1
                       FROM {$wpdb->prefix}hst_users_classes uc
                       WHERE uc.term_id = %d AND uc.class_id = l.class_id
                   )
                 ORDER BY c.class_name ASC",
                self::MANAGER_DISCIPLINE_TEACHER_ID,
                'مدیر مدرسه',
                self::DISCIPLINE_LESSON_NAME,
                $term_id
            )) ?: [];
            $assignments = array_merge($assignments, $discipline_rows);
        }

        usort($assignments, static function ($left, $right): int {
            $teacher_compare = strnatcasecmp((string) $left->teacher_name, (string) $right->teacher_name);
            if ($teacher_compare !== 0) {
                return $teacher_compare;
            }

            $class_compare = HST_Classes::compare_names($left->class_name, $right->class_name);
            if ($class_compare !== 0) {
                return $class_compare;
            }

            return strnatcasecmp((string) $left->lesson_name, (string) $right->lesson_name);
        });

        $rows = [];
        $summary = [
            'total'       => 0,
            'registered'  => 0,
            'remaining'   => 0,
            'no_students' => 0,
        ];

        foreach ($assignments as $assignment) {
            $manager_only = !empty($assignment->manager_only);
            if ($manager_only) {
                $students_count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT user_id)
                     FROM {$wpdb->prefix}hst_users_classes
                     WHERE term_id = %d AND class_id = %d AND role = 'student'",
                    $term_id,
                    (int) $assignment->class_id
                ));
            } else {
                $students_count = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(DISTINCT sl.user_id)
                         FROM {$wpdb->prefix}hst_users_lessons sl
                         INNER JOIN {$wpdb->prefix}hst_users_classes sc
                            ON sc.user_id = sl.user_id
                            AND sc.term_id = sl.term_id
                            AND sc.class_id = sl.class_id
                            AND sc.role = 'student'
                         WHERE sl.term_id = %d
                            AND sl.role = 'student'
                            AND sl.class_id = %d
                            AND sl.lesson_id = %d",
                        $term_id,
                        (int) $assignment->class_id,
                        (int) $assignment->lesson_id
                    )
                );
            }

            $expected = $students_count * $period_count;
            $registered = $selected_month
                ? $this->count_registered_score_records(
                    $term_id,
                    $selected_month,
                    (int) $assignment->class_id,
                    (int) $assignment->lesson_id,
                    (int) $assignment->teacher_id
                )
                : 0;

            $registered = min($registered, $expected);
            $missing_count = max(0, $expected - $registered);

            if ($students_count === 0 || $period_count === 0) {
                $row_status = 'no_students';
            } elseif ($missing_count === 0) {
                $row_status = 'registered';
            } else {
                $row_status = 'remaining';
            }

            if ($status === 'registered' && $row_status !== 'registered') {
                continue;
            }

            if ($status === 'remaining' && $row_status !== 'remaining') {
                continue;
            }

            $summary['total']++;
            $summary[$row_status]++;

            if ($manager_only) {
                $access_summary = [
                    'status'  => 'manager',
                    'label'   => 'فقط مدیر مدرسه',
                    'checked' => true,
                    'enabled' => count($selected_period_keys),
                    'total'   => count($selected_period_keys),
                ];
            } else {
                $access_summary = $this->score_entry_access_summary(
                    (int) $assignment->teacher_id,
                    $term_id,
                    (int) $assignment->class_id,
                    (int) $assignment->lesson_id,
                    $selected_period_keys
                );
            }

            $access_status = $access_summary['status'];
            $access_label = $access_summary['label'];

            $assignment->expected_students = $expected;
            $assignment->student_count = $students_count;
            $assignment->period_count = $period_count;
            $assignment->registered_scores = $registered;
            $assignment->missing_scores = $missing_count;
            $assignment->status = $row_status;
            $assignment->completion_percent = $expected > 0 ? round(($registered / $expected) * 100) : 0;
            $assignment->access_status = $access_status;
            $assignment->access_label = $access_label;
            $assignment->access_checked = !empty($access_summary['checked']) ? 1 : 0;
            $assignment->access_enabled_count = (int) ($access_summary['enabled'] ?? 0);
            $assignment->access_total_count = (int) ($access_summary['total'] ?? 0);

            $rows[] = $assignment;
        }

        $teachers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT u.ID, u.display_name
                 FROM {$wpdb->prefix}hst_users_lessons tl
                 INNER JOIN {$wpdb->users} u ON u.ID = tl.user_id
                 WHERE tl.term_id = %d AND tl.role = 'teacher'
                 ORDER BY u.display_name ASC",
                $term_id
            )
        ) ?: [];

        $classes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT c.id, c.class_name
                 FROM {$wpdb->prefix}hst_users_lessons tl
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = tl.class_id
                 WHERE tl.term_id = %d AND tl.role = 'teacher'
                 ORDER BY c.class_name ASC",
                $term_id
            )
        ) ?: [];
        $classes = HST_Classes::sort_rows($classes);

        $lessons = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT l.id, l.lesson_name
                 FROM {$wpdb->prefix}hst_users_lessons tl
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = tl.lesson_id
                 WHERE tl.term_id = %d AND tl.role = 'teacher'
                 ORDER BY l.lesson_name ASC",
                $term_id
            )
        ) ?: [];

        return [
            'active_term'    => $active_term,
            'periods'        => $months,
            'months'         => $months,
            'selected_period' => $selected_month,
            'selected_month' => $selected_month,
            'selected_period_label' => $selected_period_label,
            'requires_period' => false,
            'filters'        => [
                'teacher_id'     => $teacher_id,
                'teacher_search' => $teacher_search,
                'class_id'       => $class_id,
                'lesson_id'      => $lesson_id,
                'status'         => $status,
            ],
            'teachers'       => $teachers,
            'classes'        => [],
            'lessons'        => [],
            'summary'        => $summary,
            'rows'           => $rows,
            'details'        => [],
        ];
    }


    private function table_column_exists(string $table, string $column): bool
    {
        global $wpdb;

        if ($table === '' || $column === '' || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }

        return (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column));
    }

    private function monthly_scores_has_audit_columns(): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_monthly_scores';

        return $this->table_column_exists($table, 'teacher_created_at')
            && $this->table_column_exists($table, 'teacher_updated_at')
            && $this->table_column_exists($table, 'admin_created_at')
            && $this->table_column_exists($table, 'admin_updated_at');
    }

    private function score_entry_access_has_lock_columns(): bool
    {
        $table = $this->score_entry_access_table();

        return $this->table_column_exists($table, 'locked_at')
            && $this->table_column_exists($table, 'unlocked_at')
            && $this->table_column_exists($table, 'lock_source');
    }

    private function selected_period_label_from_key(int $term_id, string $period_key): string
    {
        $period_key = sanitize_key($period_key);
        if ($period_key === '') {
            return '';
        }

        foreach ($this->get_term_periods($term_id, false) as $period) {
            $key = sanitize_key((string) ($period->period_key ?? $period->month_key ?? ''));
            if ($key === $period_key) {
                return (string) ($period->period_name ?? $period->month_label ?? $period_key);
            }
        }

        return $period_key;
    }

    private function score_audit_assignment_meta(int $term_id, int $teacher_id, int $class_id, int $lesson_id): ?array
    {
        global $wpdb;

        if ($teacher_id === self::MANAGER_DISCIPLINE_TEACHER_ID) {
            if (!$this->user_can_set_discipline_scores()) {
                return null;
            }
            return $this->discipline_lesson_meta($class_id, $lesson_id);
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    tl.user_id AS teacher_id,
                    tl.class_id,
                    tl.lesson_id,
                    u.display_name AS teacher_name,
                    c.class_name,
                    l.lesson_name
                 FROM {$wpdb->prefix}hst_users_lessons tl
                 INNER JOIN {$wpdb->users} u ON u.ID = tl.user_id
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = tl.class_id
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = tl.lesson_id
                 WHERE tl.term_id = %d
                    AND tl.role = 'teacher'
                    AND tl.user_id = %d
                    AND tl.class_id = %d
                    AND tl.lesson_id = %d
                 LIMIT 1",
                $term_id,
                $teacher_id,
                $class_id,
                $lesson_id
            )
        );

        return $row ? (array) $row : null;
    }

    private function enrich_score_audit_students(array $students): array
    {
        foreach ($students as $student) {
            $student_id = (int) ($student->ID ?? 0);
            if (!$student_id) {
                continue;
            }

            $first_name = (string) get_user_meta($student_id, 'first_name', true);
            $last_name = (string) get_user_meta($student_id, 'last_name', true);
            $display_name = trim($first_name . ' ' . $last_name);
            if ($display_name === '') {
                $display_name = (string) ($student->display_name ?? '');
            }

            $student->display_name = $display_name;
            $student->first_name = $first_name;
            $student->last_name = $last_name;
            $student->national_code = (string) get_user_meta($student_id, 'hst_national_code', true);
            $student->father_name = (string) get_user_meta($student_id, 'hst_father_name', true);
        }

        return $students;
    }

    private function score_audit_students_payload(int $teacher_id, int $term_id, int $class_id, int $lesson_id): array
    {
        $students = ($teacher_id === self::MANAGER_DISCIPLINE_TEACHER_ID && $this->is_discipline_lesson($lesson_id, $class_id))
            ? $this->get_class_students($term_id, $class_id)
            : $this->get_common_students($teacher_id, $term_id, $class_id, $lesson_id);
        $students = $this->attach_avatars($students);

        return $this->enrich_score_audit_students((array) $students);
    }

    private function score_audit_scores_payload(int $term_id, int $teacher_id, int $class_id, int $lesson_id, string $period_key): array
    {
        $students = $this->score_audit_students_payload($teacher_id, $term_id, $class_id, $lesson_id);
        $student_ids = array_map(static fn($student) => (int) ($student->ID ?? 0), $students);
        return $this->score_records_for_slots(
            $term_id,
            $class_id,
            $lesson_id,
            $teacher_id,
            $student_ids,
            $this->period_score_slots($term_id, $period_key)
        );
    }

    private function score_audit_assignment_summary(int $term_id, int $teacher_id, int $class_id, int $lesson_id, string $period_key): array
    {
        $students = $this->score_audit_students_payload($teacher_id, $term_id, $class_id, $lesson_id);
        $slot_count = count($this->period_score_slots($term_id, $period_key));
        $expected = count($students) * $slot_count;
        $registered = $this->count_registered_score_records($term_id, $period_key, $class_id, $lesson_id, $teacher_id);
        $registered = min($registered, $expected);
        $missing = max(0, $expected - $registered);
        $status = count($students) === 0 ? 'no_students' : ($missing === 0 ? 'registered' : 'remaining');

        return [
            'expected'   => $expected,
            'registered' => $registered,
            'missing'    => $missing,
            'status'     => $status,
            'percent'    => $expected > 0 ? round(($registered / $expected) * 100) : 0,
        ];
    }

    private function score_audit_request_context(): array
    {
        $this->authorize_ajax('read');

        if (!$this->user_can_manage_scores()) {
            $this->fail('شما اجازه مدیریت ثبت نمره را ندارید.', 403);
        }

        $active_term = $this->get_active_term();
        $teacher_id = $this->post_int('teacher_id');
        $class_id = $this->post_int('class_id');
        $lesson_id = $this->post_int('lesson_id');
        $period_key = $this->period_key_from_request();

        if (!$active_term || !$class_id || !$lesson_id || !$this->period_exists((int) $active_term->id, $period_key)) {
            $this->fail('اطلاعات ثبت نمره ناقص است.');
        }

        $term_id = (int) $active_term->id;
        $manager_discipline = $teacher_id === self::MANAGER_DISCIPLINE_TEACHER_ID
            && $this->is_discipline_lesson($lesson_id, $class_id);
        $meta = $this->score_audit_assignment_meta($term_id, $teacher_id, $class_id, $lesson_id);
        if (!$meta || (!$manager_discipline && !$this->teacher_can_score($teacher_id, $term_id, $class_id, $lesson_id))) {
            $this->fail($manager_discipline ? 'ثبت نمره انضباط فقط برای مدیر مدرسه مجاز است.' : 'دسترسی دبیر به این کلاس و درس معتبر نیست.', 403);
        }

        $meta['period_key'] = $period_key;
        $meta['period_label'] = $this->selected_period_label_from_key($term_id, $period_key);
        $slots = $this->period_score_slots($term_id, $period_key);

        return [
            'term_id'    => $term_id,
            'teacher_id' => $teacher_id,
            'class_id'   => $class_id,
            'lesson_id'  => $lesson_id,
            'period_key' => $period_key,
            'slots'      => $slots,
            'meta'       => $meta,
            'manager_only' => !empty($meta['manager_only']),
        ];
    }


    /**
     * Export-only student query. Unlike the interactive modal payload it does
     * not attach avatars, which keeps large Excel reports fast and memory-safe.
     */
    private function score_audit_export_students(int $teacher_id, int $term_id, int $class_id, int $lesson_id): array
    {
        global $wpdb;

        if ($teacher_id === self::MANAGER_DISCIPLINE_TEACHER_ID && $this->is_discipline_lesson($lesson_id, $class_id)) {
            $students = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT
                    u.ID,
                    u.display_name,
                    COALESCE(fn.meta_value, '') AS first_name,
                    COALESCE(ln.meta_value, '') AS last_name,
                    COALESCE(nc.meta_value, '') AS national_code,
                    COALESCE(father.meta_value, '') AS father_name
                 FROM {$wpdb->users} u
                 INNER JOIN {$wpdb->prefix}hst_users_classes sc
                    ON sc.user_id = u.ID
                    AND sc.role = 'student'
                    AND sc.term_id = %d
                    AND sc.class_id = %d
                 LEFT JOIN {$wpdb->usermeta} fn ON fn.user_id = u.ID AND fn.meta_key = 'first_name'
                 LEFT JOIN {$wpdb->usermeta} ln ON ln.user_id = u.ID AND ln.meta_key = 'last_name'
                 LEFT JOIN {$wpdb->usermeta} nc ON nc.user_id = u.ID AND nc.meta_key = 'hst_national_code'
                 LEFT JOIN {$wpdb->usermeta} father ON father.user_id = u.ID AND father.meta_key = 'hst_father_name'",
                $term_id,
                $class_id
            )) ?: [];
        } else {
            $students = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT
                    u.ID,
                    u.display_name,
                    COALESCE(fn.meta_value, '') AS first_name,
                    COALESCE(ln.meta_value, '') AS last_name,
                    COALESCE(nc.meta_value, '') AS national_code,
                    COALESCE(father.meta_value, '') AS father_name
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
                 INNER JOIN {$wpdb->prefix}hst_users_classes tc
                    ON tc.user_id = %d
                    AND tc.role = 'teacher'
                    AND tc.term_id = sc.term_id
                    AND tc.class_id = sc.class_id
                 INNER JOIN {$wpdb->prefix}hst_users_lessons tl
                    ON tl.user_id = %d
                    AND tl.role = 'teacher'
                    AND tl.term_id = sc.term_id
                    AND tl.class_id = sc.class_id
                    AND tl.lesson_id = sl.lesson_id
                 LEFT JOIN {$wpdb->usermeta} fn ON fn.user_id = u.ID AND fn.meta_key = 'first_name'
                 LEFT JOIN {$wpdb->usermeta} ln ON ln.user_id = u.ID AND ln.meta_key = 'last_name'
                 LEFT JOIN {$wpdb->usermeta} nc ON nc.user_id = u.ID AND nc.meta_key = 'hst_national_code'
                 LEFT JOIN {$wpdb->usermeta} father ON father.user_id = u.ID AND father.meta_key = 'hst_father_name'",
                $term_id,
                $class_id,
                $lesson_id,
                $teacher_id,
                $teacher_id
            )
        ) ?: [];
        }

        foreach ($students as $student) {
            $full_name = trim((string) ($student->first_name ?? '') . ' ' . (string) ($student->last_name ?? ''));
            if ($full_name !== '') {
                $student->display_name = $full_name;
            }
        }

        return class_exists('HST_Students') ? HST_Students::sort_student_rows($students) : $students;
    }

    private function score_audit_export_completion_status(int $student_count, int $registered, int $expected): string
    {
        if ($student_count === 0 || $expected === 0) {
            return 'بدون دانش‌آموز';
        }
        if ($registered === 0) {
            return 'ثبت نشده';
        }
        if ($registered >= $expected) {
            return 'کامل';
        }
        return 'ناقص';
    }

    private function score_audit_excel_payload(string $period_key): array
    {
        $active_term = $this->get_active_term();
        if (!$active_term) {
            $this->fail('سال تحصیلی فعالی وجود ندارد.');
        }

        $term_id = (int) $active_term->id;
        $period_key = sanitize_key($period_key);
        if ($period_key === '' || !$this->period_exists($term_id, $period_key)) {
            $this->fail('دوره انتخاب‌شده معتبر نیست.');
        }

        $context = $this->get_admin_score_audit_context(['month_key' => $period_key]);
        $assignments = (array) ($context['rows'] ?? []);
        $slots = $this->period_score_slots($term_id, $period_key);
        $period_label = (string) ($context['selected_period_label'] ?? $this->selected_period_label_from_key($term_id, $period_key));
        $term_label = (string) ($active_term->term_name ?? '');

        $teacher_headers = [
            'ردیف', 'مسئول ثبت', 'کلاس', 'درس', 'سال تحصیلی', 'دوره', 'تعداد دانش‌آموز',
            'تعداد مؤلفه نمره', 'تعداد نمره عددی', 'تعداد غیبت ثبت‌شده',
            'تعداد مورد انتظار', 'تعداد ثبت‌شده', 'تعداد باقی‌مانده',
            'درصد تکمیل', 'وضعیت نمره', 'وضعیت ثبت', 'دسترسی ثبت نمره',
        ];

        $student_headers = [
            'ردیف', 'نام دانش‌آموز', 'کد ملی', 'نام پدر', 'کلاس', 'درس', 'مسئول ثبت', 'سال تحصیلی', 'دوره',
            'وضعیت نمره', 'وضعیت ثبت', 'تعداد مؤلفه نمره', 'تعداد نمره عددی',
            'تعداد غیبت ثبت‌شده', 'تعداد ثبت‌شده', 'تعداد باقی‌مانده', 'درصد تکمیل',
        ];

        foreach ($slots as $slot) {
            $label = trim((string) ($slot['label'] ?? 'نمره')) ?: 'نمره';
            $student_headers[] = $label;
            $student_headers[] = 'حضور و غیاب ' . $label;
            $student_headers[] = 'نوع غیبت ' . $label;
            $student_headers[] = 'توضیحات ' . $label;
        }

        $teacher_rows = [];
        $student_rows = [];
        $teacher_index = 0;
        $student_index = 0;

        foreach ($assignments as $assignment) {
            $teacher_id = (int) ($assignment->teacher_id ?? 0);
            $class_id = (int) ($assignment->class_id ?? 0);
            $lesson_id = (int) ($assignment->lesson_id ?? 0);
            $manager_only = !empty($assignment->manager_only);
            if ((!$teacher_id && !$manager_only) || !$class_id || !$lesson_id) {
                continue;
            }

            $students = $this->score_audit_export_students($teacher_id, $term_id, $class_id, $lesson_id);
            $student_ids = array_map(static fn($student): int => (int) ($student->ID ?? 0), $students);
            $scores = $this->score_records_for_slots($term_id, $class_id, $lesson_id, $teacher_id, $student_ids, $slots);

            $numeric_total = 0;
            $absence_total = 0;
            $registered_total = 0;
            $student_count = count($students);
            $expected_total = $student_count * count($slots);

            foreach ($students as $student) {
                $student_id = (int) ($student->ID ?? 0);
                $student_scores = $scores[$student_id] ?? [];
                $numeric_count = 0;
                $absence_count = 0;
                $registered_count = 0;
                $slot_cells = [];

                foreach ($slots as $slot) {
                    $slot_key = (string) ($slot['key'] ?? '');
                    $item = (array) ($student_scores[$slot_key] ?? []);
                    $raw_score = $item['score'] ?? null;
                    $has_numeric_score = $raw_score !== null && $raw_score !== '';
                    $is_present = (int) ($item['is_present'] ?? 1) !== 0;
                    $is_registered = $has_numeric_score || !$is_present;

                    if ($has_numeric_score) {
                        $numeric_count++;
                    }
                    if (!$is_present) {
                        $absence_count++;
                    }
                    if ($is_registered) {
                        $registered_count++;
                    }

                    $slot_cells[] = $has_numeric_score ? (float) $raw_score : '';
                    $slot_cells[] = $is_present ? 'حاضر' : 'غایب';
                    $slot_cells[] = $is_present ? '—' : ((int) ($item['absence_excused'] ?? 0) === 1 ? 'موجه' : 'غیرموجه');
                    $slot_cells[] = (string) ($item['description'] ?? '');
                }

                $numeric_total += $numeric_count;
                $absence_total += $absence_count;
                $registered_total += $registered_count;
                $missing_count = max(0, count($slots) - $registered_count);
                $completion_percent = count($slots) > 0 ? round(($registered_count / count($slots)) * 100) : 0;
                $student_status = $registered_count === 0 ? 'ثبت نشده' : ($registered_count >= count($slots) ? 'کامل' : 'ناقص');

                $student_index++;
                $base_row = [
                    $student_index,
                    (string) ($student->display_name ?? ''),
                    (string) ($student->national_code ?? ''),
                    (string) ($student->father_name ?? ''),
                    (string) ($assignment->class_name ?? ''),
                    (string) ($assignment->lesson_name ?? ''),
                    (string) ($assignment->teacher_name ?? ''),
                    $term_label,
                    $period_label,
                    $numeric_count > 0 ? 'دارای نمره' : 'بدون نمره',
                    $student_status,
                    count($slots),
                    $numeric_count,
                    $absence_count,
                    $registered_count,
                    $missing_count,
                    $completion_percent,
                ];
                $student_rows[] = array_merge($base_row, $slot_cells);
            }

            $missing_total = max(0, $expected_total - $registered_total);
            $completion_percent = $expected_total > 0 ? round(($registered_total / $expected_total) * 100) : 0;
            $completion_status = $this->score_audit_export_completion_status($student_count, $registered_total, $expected_total);
            $access_label = $manager_only ? 'فقط مدیر مدرسه' : (!empty($assignment->access_checked) ? 'فعال' : 'غیرفعال');

            $teacher_index++;
            $teacher_rows[] = [
                $teacher_index,
                (string) ($assignment->teacher_name ?? ''),
                (string) ($assignment->class_name ?? ''),
                (string) ($assignment->lesson_name ?? ''),
                $term_label,
                $period_label,
                $student_count,
                count($slots),
                $numeric_total,
                $absence_total,
                $expected_total,
                $registered_total,
                $missing_total,
                $completion_percent,
                $numeric_total > 0 ? 'دارای نمره' : 'بدون نمره',
                $completion_status,
                $access_label,
            ];
        }

        $safe_period = sanitize_file_name($period_label !== '' ? $period_label : $period_key);
        if ($safe_period === '') {
            $safe_period = $period_key;
        }

        return [
            'period_key'       => $period_key,
            'period_label'     => $period_label,
            'term_label'       => $term_label,
            'teacher_headers'  => $teacher_headers,
            'teacher_rows'     => $teacher_rows,
            'student_headers'  => $student_headers,
            'student_rows'     => $student_rows,
            'filename'         => 'گزارش-ثبت-نمره-' . $safe_period . '.xlsx',
        ];
    }

    public function ajax_score_audit_excel_report(): void
    {
        $this->authorize_ajax('read');

        if (!$this->user_can_manage_scores()) {
            $this->fail('شما اجازه دریافت گزارش ثبت نمره را ندارید.', 403);
        }

        $period_key = $this->post_key('period_key');
        wp_send_json_success($this->score_audit_excel_payload($period_key));
    }

    public function ajax_score_audit_get_scores()
    {
        $context = $this->score_audit_request_context();

        wp_send_json_success([
            'meta'     => $context['meta'],
            'students' => $this->score_audit_students_payload($context['teacher_id'], $context['term_id'], $context['class_id'], $context['lesson_id']),
            'scores'   => $this->score_audit_scores_payload($context['term_id'], $context['teacher_id'], $context['class_id'], $context['lesson_id'], $context['period_key']),
            'slots'    => $context['slots'],
            'summary'  => $this->score_audit_assignment_summary($context['term_id'], $context['teacher_id'], $context['class_id'], $context['lesson_id'], $context['period_key']),
        ]);
    }

    public function ajax_score_audit_save_scores()
    {
        $context = $this->score_audit_request_context();
        $scores = $this->posted_scores_payload();

        if (!is_array($scores)) {
            $this->fail('اطلاعات نمره‌ها معتبر نیست.');
        }
        if (count($scores) > 250) {
            $this->fail('تعداد دانش‌آموزان ارسالی بیش از حد مجاز است.');
        }

        $editable_map = [];
        foreach ($context['slots'] as $slot) {
            if (!empty($slot['editable'])) {
                $editable_map[(string) $slot['key']] = $slot;
            }
        }

        $students = $this->score_audit_students_payload($context['teacher_id'], $context['term_id'], $context['class_id'], $context['lesson_id']);
        $allowed_student_ids = array_map(static fn($student) => (int) ($student->ID ?? 0), $students);

        global $wpdb;
        $table = $wpdb->prefix . 'hst_monthly_scores';
        $saved = 0;

        foreach ($scores as $student_id => $student_items) {
            $student_id = absint($student_id);
            if (!$student_id || !in_array($student_id, $allowed_student_ids, true) || !is_array($student_items)) {
                continue;
            }

            if (array_key_exists('score', $student_items) || array_key_exists('present', $student_items)) {
                $first_key = (string) array_key_first($editable_map);
                $student_items = [$first_key => $student_items];
            }

            foreach ($student_items as $slot_key => $item) {
                $slot_key = sanitize_key((string) $slot_key);
                if (!isset($editable_map[$slot_key]) || !is_array($item)) {
                    continue;
                }

                $slot = $editable_map[$slot_key];
                $score_key = sanitize_key((string) $slot['source_score_key']);
                $is_present = array_key_exists('present', $item) ? (!empty($item['present']) ? 1 : 0) : 1;
                $absence_excused = $is_present ? null : (!empty($item['absence_excused']) ? 1 : 0);
                $raw_score = isset($item['score']) ? trim((string) $item['score']) : '';
                $description = isset($item['description']) ? sanitize_textarea_field((string) $item['description']) : '';

                if (function_exists('mb_strlen') && mb_strlen($description) > 500) {
                    $description = mb_substr($description, 0, 500);
                } elseif (strlen($description) > 500) {
                    $description = substr($description, 0, 500);
                }

                $scope = [
                    'term_id' => $context['term_id'],
                    'class_id' => $context['class_id'],
                    'lesson_id' => $context['lesson_id'],
                    'teacher_id' => $context['teacher_id'],
                    'student_id' => $student_id,
                    'month_key' => $context['period_key'],
                    'score_key' => $score_key,
                ];
                $scope_formats = ['%d','%d','%d','%d','%d','%s','%s'];

                if ($is_present === 1 && $raw_score === '') {
                    $wpdb->delete($table, $scope, $scope_formats);
                    continue;
                }

                $score = $is_present ? $this->normalize_score_value($raw_score) : null;
                $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table}
                     WHERE term_id = %d AND class_id = %d AND lesson_id = %d AND teacher_id = %d
                        AND student_id = %d AND month_key = %s AND score_key = %s
                     LIMIT 1",
                    $context['term_id'],
                    $context['class_id'],
                    $context['lesson_id'],
                    $context['teacher_id'],
                    $student_id,
                    $context['period_key'],
                    $score_key
                ));

                $now = current_time('mysql');
                if ($existing_id > 0) {
                    $result = $wpdb->update(
                        $table,
                        [
                            'score' => $score,
                            'is_present' => $is_present,
                            'absence_excused' => $absence_excused,
                            'description' => $description,
                            'updated_at' => $now,
                            'admin_updated_at' => $now,
                        ],
                        ['id' => $existing_id],
                        ['%f','%d','%d','%s','%s','%s'],
                        ['%d']
                    );
                } else {
                    $payload = array_merge($scope, [
                        'score' => $score,
                        'is_present' => $is_present,
                        'absence_excused' => $absence_excused,
                        'description' => $description,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'admin_created_at' => $now,
                    ]);
                    $formats = array_merge($scope_formats, ['%f','%d','%d','%s','%s','%s','%s']);
                    $result = $wpdb->insert($table, $payload, $formats);
                }

                if ($result === false) {
                    $this->fail('ذخیره نمرات توسط مدیر انجام نشد.');
                }
                $saved++;
            }
        }

        wp_send_json_success([
            'message' => 'نمرات با موفقیت ذخیره شد.',
            'saved'   => $saved,
            'scores'  => $this->score_audit_scores_payload($context['term_id'], $context['teacher_id'], $context['class_id'], $context['lesson_id'], $context['period_key']),
            'slots'   => $context['slots'],
            'summary' => $this->score_audit_assignment_summary($context['term_id'], $context['teacher_id'], $context['class_id'], $context['lesson_id'], $context['period_key']),
        ]);
    }

    private function score_audit_format_log_time($value, string $empty = 'ثبت نشده'): string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return $empty;
        }

        return class_exists('HST_Date')
            ? HST_Date::format($value, 'Y/m/d H:i', $empty)
            : wp_date('Y/m/d H:i', strtotime($value));
    }

    private function score_audit_security_logs_payload(array $context): array
    {
        global $wpdb;

        $summary = $this->score_audit_assignment_summary($context['term_id'], $context['teacher_id'], $context['class_id'], $context['lesson_id'], $context['period_key']);
        $has_audit_columns = $this->monthly_scores_has_audit_columns();

        if ($has_audit_columns) {
            $log_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT
                        MIN(teacher_created_at) AS teacher_first_registered_at,
                        MAX(teacher_updated_at) AS teacher_last_updated_at,
                        MIN(admin_created_at) AS admin_first_registered_at,
                        MAX(admin_updated_at) AS admin_last_updated_at
                     FROM {$wpdb->prefix}hst_monthly_scores
                     WHERE term_id = %d
                        AND class_id = %d
                        AND lesson_id = %d
                        AND teacher_id = %d
                        AND month_key = %s",
                    $context['term_id'],
                    $context['class_id'],
                    $context['lesson_id'],
                    $context['teacher_id'],
                    $context['period_key']
                )
            );
        } else {
            $log_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT
                        MIN(created_at) AS teacher_first_registered_at,
                        MAX(updated_at) AS teacher_last_updated_at,
                        NULL AS admin_first_registered_at,
                        NULL AS admin_last_updated_at
                     FROM {$wpdb->prefix}hst_monthly_scores
                     WHERE term_id = %d
                        AND class_id = %d
                        AND lesson_id = %d
                        AND teacher_id = %d
                        AND month_key = %s",
                    $context['term_id'],
                    $context['class_id'],
                    $context['lesson_id'],
                    $context['teacher_id'],
                    $context['period_key']
                )
            );
        }

        $lock_fields = $this->score_entry_access_has_lock_columns()
            ? 'is_enabled, updated_at, locked_at, unlocked_at, lock_source'
            : 'is_enabled, updated_at';

        $lock_row = null;
        if (empty($context['manager_only'])) {
            $lock_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT {$lock_fields}
                     FROM {$wpdb->prefix}hst_score_entry_access
                     WHERE term_id = %d
                        AND class_id = %d
                        AND lesson_id = %d
                        AND teacher_id = %d
                        AND period_key = %s
                     LIMIT 1",
                    $context['term_id'],
                    $context['class_id'],
                    $context['lesson_id'],
                    $context['teacher_id'],
                    $context['period_key']
                )
            );
        }

        $locked_at = '';
        $unlocked_at = '';
        $lock_source = '';
        if ($lock_row) {
            if ($this->score_entry_access_has_lock_columns()) {
                $locked_at = (string) ($lock_row->locked_at ?? '');
                $unlocked_at = (string) ($lock_row->unlocked_at ?? '');
                $lock_source = (string) ($lock_row->lock_source ?? '');
            } elseif ((int) ($lock_row->is_enabled ?? 1) === 0) {
                $locked_at = (string) ($lock_row->updated_at ?? '');
                $lock_source = 'manager';
            } else {
                $unlocked_at = (string) ($lock_row->updated_at ?? '');
                $lock_source = 'manager';
            }
        }

        return [
            'meta' => $context['meta'],
            'summary' => $summary,
            'teacher' => [
                'first_registered_at' => $this->score_audit_format_log_time($log_row->teacher_first_registered_at ?? '', 'ثبت نشده'),
                'last_updated_at'     => $this->score_audit_format_log_time($log_row->teacher_last_updated_at ?? '', 'بدون ویرایش'),
            ],
            'admin' => [
                'first_registered_at' => $this->score_audit_format_log_time($log_row->admin_first_registered_at ?? '', 'ثبت نشده'),
                'last_updated_at'     => $this->score_audit_format_log_time($log_row->admin_last_updated_at ?? '', 'بدون ویرایش'),
            ],
            'lock' => [
                'locked_at'   => $this->score_audit_format_log_time($locked_at, 'ثبت نشده'),
                'unlocked_at' => $this->score_audit_format_log_time($unlocked_at, 'ثبت نشده'),
                'source'      => $lock_source === 'auto' ? 'اتوماتیک' : ($lock_source === 'manager' ? 'مدیریتی' : 'ثبت نشده'),
                'is_enabled'  => $lock_row ? (int) ($lock_row->is_enabled ?? 1) : 1,
            ],
        ];
    }

    public function ajax_score_audit_security_logs()
    {
        $context = $this->score_audit_request_context();

        wp_send_json_success($this->score_audit_security_logs_payload($context));
    }

    /**
     * Send an internal score-entry reminder to the teacher assigned to this
     * class/lesson/period. The request context is validated by the same guard
     * used by the score audit actions, so client-provided labels and counts are
     * never trusted.
     */
    public function ajax_score_audit_send_reminder()
    {
        $context = $this->score_audit_request_context();
        if (!empty($context['manager_only'])) {
            $this->fail('نمره انضباط مستقیماً توسط مدیر ثبت می‌شود و یادآوری دبیر ندارد.');
        }
        $summary = $this->score_audit_assignment_summary(
            $context['term_id'],
            $context['teacher_id'],
            $context['class_id'],
            $context['lesson_id'],
            $context['period_key']
        );

        $expected = absint($summary['expected'] ?? 0);
        $missing = absint($summary['missing'] ?? 0);

        if ($expected <= 0) {
            $this->fail('برای این کلاس و درس نمره‌ای جهت ثبت وجود ندارد.');
        }

        if ($missing <= 0) {
            $this->fail('تمام نمرات این مورد ثبت شده‌اند و نیازی به ارسال یادآوری نیست.');
        }

        $access = $this->score_entry_access_summary(
            $context['teacher_id'],
            $context['term_id'],
            $context['class_id'],
            $context['lesson_id'],
            [$context['period_key']]
        );

        if (empty($access['checked'])) {
            $this->fail('ابتدا دسترسی ثبت نمره این دبیر را فعال کنید.');
        }

        if (!class_exists('HST_Notify') || !method_exists('HST_Notify', 'send_score_entry_reminder')) {
            $this->fail('سرویس اطلاع‌رسانی در دسترس نیست.', 500);
        }

        $notification_id = HST_Notify::send_score_entry_reminder([
            'teacher_id'  => $context['teacher_id'],
            'class_name'  => (string) ($context['meta']['class_name'] ?? ''),
            'lesson_name' => (string) ($context['meta']['lesson_name'] ?? ''),
            'period_label'=> (string) ($context['meta']['period_label'] ?? ''),
            'expected'    => $expected,
            'missing'     => $missing,
            'created_by'  => get_current_user_id(),
        ]);

        if (!$notification_id) {
            $this->fail('ارسال یادآوری ثبت نمره انجام نشد.', 500);
        }

        wp_send_json_success([
            'message'         => sprintf(
                'یادآوری ثبت نمره برای دبیر «%s» ارسال شد.',
                (string) ($context['meta']['teacher_name'] ?? 'انتخاب‌شده')
            ),
            'notification_id' => absint($notification_id),
        ]);
    }


    public function ajax_toggle_score_entry_access()
    {
        $this->authorize_ajax('read');

        if (!$this->user_can_manage_scores()) {
            $this->fail('دسترسی غیرمجاز است.', 403);
        }

        $active_term = $this->get_active_term();
        $term_id = $active_term ? (int) $active_term->id : 0;
        $teacher_id = $this->post_int('teacher_id');
        $class_id = $this->post_int('class_id');
        $lesson_id = $this->post_int('lesson_id');
        $period_key = $this->period_key_from_request();
        $enabled_value = $_POST['is_enabled'] ?? ($_POST['enabled'] ?? 0);
        $enabled = (int) $enabled_value === 1;

        if (!$term_id || !$teacher_id || !$class_id || !$lesson_id) {
            $this->fail('اطلاعات دسترسی ثبت نمره ناقص است.');
        }

        if ($this->is_discipline_lesson($lesson_id, $class_id)) {
            $this->fail('درس انضباط فقط توسط مدیر ثبت می‌شود و دسترسی دبیر ندارد.');
        }

        if (!$this->teacher_can_score($teacher_id, $term_id, $class_id, $lesson_id)) {
            $this->fail('این دبیر برای کلاس و درس انتخاب‌شده تعریف نشده است.');
        }

        $period_keys = [];
        if ($period_key !== '') {
            if (!$this->period_exists($term_id, $period_key)) {
                $this->fail('دوره انتخاب‌شده معتبر نیست.');
            }
            $period_keys = [$period_key];
        } else {
            $period_keys = array_values(array_filter(array_map(static function ($period) {
                return sanitize_key((string) ($period->period_key ?? $period->month_key ?? ''));
            }, $this->get_term_periods($term_id, false))));
        }

        if (empty($period_keys)) {
            $this->fail('دوره‌ای برای اعمال دسترسی وجود ندارد.');
        }

        foreach ($period_keys as $key) {
            $this->set_score_entry_access($teacher_id, $term_id, $class_id, $lesson_id, $key, $enabled);
        }

        $summary = $this->score_entry_access_summary($teacher_id, $term_id, $class_id, $lesson_id, $period_keys);

        wp_send_json_success([
            'message' => $enabled ? 'دسترسی ثبت نمره فعال شد.' : 'دسترسی ثبت نمره غیرفعال شد.',
            'enabled' => $enabled ? 1 : 0,
            'access'  => $summary,
        ]);
    }

    // =====================================================================
    //  GRADEBOOK — a teacher's working scores (several per student/month),
    //  whose monthly average is suggested when entering monthly scores.
    // =====================================================================

    private const GRADEBOOK_MAX = 8;

    /** Average of a student's gradebook scores for a month (2 decimals) or null. */
    public static function gradebook_average($term_id, $class_id, $lesson_id, $teacher_id, $student_id, $month_key)
    {
        global $wpdb;
        $vals = $wpdb->get_col($wpdb->prepare(
            "SELECT score FROM {$wpdb->prefix}hst_gradebook
             WHERE term_id=%d AND class_id=%d AND lesson_id=%d AND teacher_id=%d AND student_id=%d AND month_key=%s",
            (int) $term_id, (int) $class_id, (int) $lesson_id, (int) $teacher_id, (int) $student_id, $month_key
        ));
        if (empty($vals)) {
            return null;
        }
        $sum = 0.0;
        foreach ($vals as $v) { $sum += (float) $v; }
        return round($sum / count($vals), 2);
    }

    public function ajax_get_gradebook()
    {
        $this->authorize_ajax('read');
        if (!$this->user_can_teach()) {
            $this->fail('این بخش مخصوص معلمان است.', 403);
        }
        $teacher_id = get_current_user_id();
        $active_term = $this->get_active_term();
        $class_id = $this->post_int('class_id');
        $lesson_id = $this->post_int('lesson_id');
        $month_key = $this->period_key_from_request();

        if (!$active_term || !$class_id || !$lesson_id || !$this->period_exists((int) $active_term->id, $month_key)) {
            $this->fail('اطلاعات ناقص است.');
        }
        $term_id = (int) $active_term->id;
        if (!$this->teacher_can_score($teacher_id, $term_id, $class_id, $lesson_id)) {
            $this->fail('شما برای این درس دسترسی ندارید.', 403);
        }

        $students = $this->attach_avatars($this->get_common_students($teacher_id, $term_id, $class_id, $lesson_id));

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, student_id, title, score FROM {$wpdb->prefix}hst_gradebook
             WHERE term_id=%d AND class_id=%d AND lesson_id=%d AND teacher_id=%d AND month_key=%s
             ORDER BY id ASC",
            $term_id, $class_id, $lesson_id, $teacher_id, $month_key
        ), ARRAY_A) ?: [];

        $byStudent = [];
        foreach ($rows as $r) {
            $sid = (int) $r['student_id'];
            $byStudent[$sid][] = ['id' => (int) $r['id'], 'title' => $r['title'], 'score' => $r['score']];
        }

        $access_enabled = $this->score_entry_access_enabled($teacher_id, $term_id, $class_id, $lesson_id, $month_key);

        wp_send_json_success([
            'students'        => $students,
            'entries'         => $byStudent,
            'max_per_student' => self::GRADEBOOK_MAX,
            'months'          => $this->get_term_months($term_id, false),
            'access_enabled'  => $access_enabled,
            'period_is_active' => $access_enabled,
            'month_is_active'  => $access_enabled,
        ]);
    }

    public function ajax_save_gradebook()
    {
        $this->authorize_ajax('read');
        if (!$this->user_can_teach()) {
            $this->fail('این بخش مخصوص معلمان است.', 403);
        }
        $teacher_id = get_current_user_id();
        $active_term = $this->get_active_term();
        $class_id = $this->post_int('class_id');
        $lesson_id = $this->post_int('lesson_id');
        $month_key = $this->period_key_from_request();
        $entries = wp_unslash($_POST['entries'] ?? []);

        if (!$active_term || !$class_id || !$lesson_id || !$this->period_exists((int) $active_term->id, $month_key) || !is_array($entries)) {
            $this->fail('اطلاعات ناقص است.');
        }
        $term_id = (int) $active_term->id;
        if (!$this->score_entry_access_enabled($teacher_id, $term_id, $class_id, $lesson_id, $month_key)) {
            $this->fail('دسترسی ثبت نمره برای این دبیر غیرفعال است.');
        }
        if (!$this->teacher_can_score($teacher_id, $term_id, $class_id, $lesson_id)) {
            $this->fail('شما برای این درس دسترسی ندارید.', 403);
        }

        $students = $this->get_common_students($teacher_id, $term_id, $class_id, $lesson_id);
        $allowed = array_map(static fn($s) => (int) $s->ID, $students);

        global $wpdb;
        $table = $wpdb->prefix . 'hst_gradebook';

        foreach ($entries as $student_id => $list) {
            $student_id = absint($student_id);
            if (!$student_id || !in_array($student_id, $allowed, true) || !is_array($list)) {
                continue;
            }
            // Replace all of this student's gradebook rows for the month.
            $wpdb->delete($table, [
                'term_id' => $term_id, 'class_id' => $class_id, 'lesson_id' => $lesson_id,
                'teacher_id' => $teacher_id, 'student_id' => $student_id, 'month_key' => $month_key,
            ], ['%d','%d','%d','%d','%d','%s']);

            $count = 0;
            foreach ($list as $item) {
                if ($count >= self::GRADEBOOK_MAX || !is_array($item)) {
                    break;
                }
                $raw = isset($item['score']) ? trim((string) $item['score']) : '';
                if ($raw === '') {
                    continue;
                }
                $score = $this->normalize_score_value($raw);
                $title = isset($item['title']) ? sanitize_text_field((string) $item['title']) : '';
                if (mb_strlen($title) > 120) {
                    $title = mb_substr($title, 0, 120);
                }
                $wpdb->insert($table, [
                    'term_id' => $term_id, 'class_id' => $class_id, 'lesson_id' => $lesson_id,
                    'teacher_id' => $teacher_id, 'student_id' => $student_id, 'month_key' => $month_key,
                    'title' => $title, 'score' => $score, 'created_at' => current_time('mysql'),
                ], ['%d','%d','%d','%d','%d','%s','%s','%f','%s']);
                $count++;
            }
        }

        wp_send_json_success(['message' => 'دفتر نمره ذخیره شد.']);
    }
}