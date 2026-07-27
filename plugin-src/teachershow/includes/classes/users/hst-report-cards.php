<?php

defined('ABSPATH') || exit;

/**
 * Data provider and sample preview renderer for the manager report-card workspace.
 */
class HST_Report_Cards
{
    private $report_student_summary_cache = [];
    private $report_class_average_cache = [];
    private $report_direct_class_average_cache = [];
    private $report_ranking_cache = [];
    private $report_period_cache = [];

    public function __construct()
    {
        add_action('wp_ajax_hst_get_report_card_preview', [$this, 'ajax_get_preview']);
        add_action('wp_ajax_hst_report_card_print_students', [$this, 'ajax_print_students']);
        add_action('wp_ajax_hst_report_card_print_data', [$this, 'ajax_print_data']);
    }

    private function report_school_name(): string
    {
        $school_name = '';
        if (class_exists('HST_Settings')) {
            $school_name = trim((string) HST_Settings::option('hst-home-school-name', ''));
        }

        if ($school_name === '' && class_exists('HST_Schedule_PDF')) {
            $candidate = trim((string) HST_Schedule_PDF::settings_header_text());
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
            if (!in_array($normalized, ['teachershow', 'teacher show', 'تیچرشو'], true)) {
                $school_name = $candidate;
            }
        }

        if ($school_name === '') {
            $candidate = trim((string) get_bloginfo('name'));
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
            if (!in_array($normalized, ['teachershow', 'teacher show', 'تیچرشو'], true)) {
                $school_name = $candidate;
            }
        }

        return $school_name !== '' ? $school_name : 'مدرسه';
    }

    public static function active_term()
    {
        return HST_Terms::active();
    }

    /**
     * Active weekly, monthly, and custom score periods available for report cards.
     */
    public static function monthly_issue_periods($term_id): array
    {
        global $wpdb;

        $term_id = absint($term_id);
        if (!$term_id) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, period_key, period_name, period_type, score_count, sort_order
                 FROM {$wpdb->prefix}hst_score_periods
                 WHERE term_id = %d
                   AND is_active = 1
                   AND period_type IN ('weekly', 'monthly', 'custom')
                 ORDER BY sort_order ASC, id ASC",
                $term_id
            )
        ) ?: [];

        $type_labels = class_exists('HST_Scores') ? HST_Scores::period_types() : [
            'weekly'  => 'هفتگی',
            'monthly' => 'ماهانه',
            'custom'  => 'اختصاصی',
        ];

        foreach ($rows as $row) {
            $row->period_type_label = $type_labels[$row->period_type] ?? '';
        }

        return $rows;
    }

    private function report_period_type_label(string $period_type): string
    {
        $labels = [
            'weekly'  => 'هفتگی',
            'monthly' => 'ماهانه',
            'custom'  => 'اختصاصی',
        ];

        $period_type = sanitize_key($period_type);
        return $labels[$period_type] ?? 'اختصاصی';
    }

    /** @return array{year:int,month:int}|null */
    private function report_period_jalali_year_month(string $date): ?array
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
    private function report_period_months(array $period): array
    {
        $start = $this->report_period_jalali_year_month((string) ($period['start_date'] ?? ''));
        $end = $this->report_period_jalali_year_month((string) ($period['end_date'] ?? ''));
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
                'year'  => $year,
                'month' => $month,
                'label' => $names[$month] ?? '',
            ];
        }

        // Very long ranges can repeat the same month name. Add the Jalali year
        // only in that uncommon case so column headings remain unambiguous.
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

    private function report_join_month_labels(array $months): string
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

    /**
     * Score columns used by the shared weekly/monthly/custom report renderer.
     * Custom-period columns are named from the inclusive Jalali month range.
     * When there are fewer score slots than months, contiguous months are
     * distributed across the available columns so no month disappears.
     *
     * @return array<int,array{key:string,label:string}>
     */
    private function report_score_columns(array $period): array
    {
        $type = sanitize_key((string) ($period['type'] ?? 'weekly'));
        if ($type !== 'custom') {
            $type_label = trim((string) ($period['type_label'] ?? ''));
            if ($type_label === '') {
                $type_label = $this->report_period_type_label($type);
            }
            return [['key' => 'score_1', 'label' => 'نمره ' . $type_label]];
        }

        $score_count = max(1, min(20, absint($period['score_count'] ?? 1)));
        $months = $this->report_period_months($period);
        if (empty($months)) {
            $columns = [];
            for ($index = 1; $index <= $score_count; $index++) {
                $columns[] = [
                    'key' => 'score_' . $index,
                    'label' => $score_count === 1 ? 'نمره اختصاصی' : 'نمره ' . number_format_i18n($index),
                ];
            }
            return $columns;
        }

        $month_count = count($months);
        $columns = [];
        if ($score_count <= $month_count) {
            $base_size = intdiv($month_count, $score_count);
            $remainder = $month_count % $score_count;
            $offset = 0;
            for ($index = 1; $index <= $score_count; $index++) {
                $group_size = $base_size + ($index <= $remainder ? 1 : 0);
                $group = array_slice($months, $offset, $group_size);
                $offset += $group_size;
                $columns[] = [
                    'key' => 'score_' . $index,
                    'label' => 'نمره ' . $this->report_join_month_labels($group),
                ];
            }
            return $columns;
        }

        // More score slots than months: keep every heading tied to its month
        // and number repeated slots within that month instead of falling back
        // to unrelated generic labels.
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
                $columns[] = ['key' => 'score_' . $score_index, 'label' => trim($label)];
            }
        }

        return $columns;
    }

    /**
     * Month-based x-axis definition for custom-period analytical charts.
     *
     * Each point contains one or more score keys. When a custom period has
     * fewer score slots than months, the same score slot is repeated across
     * the contiguous months it represents. When it has more slots than
     * months, the slots assigned to the same month are averaged for that point.
     *
     * @return array{labels:array<int,string>,keys_by_point:array<int,array<int,string>>}
     */
    private function report_custom_chart_axis(array $period): array
    {
        $score_count = max(1, min(20, absint($period['score_count'] ?? 1)));
        $months = $this->report_period_months($period);

        if (empty($months)) {
            $columns = $this->report_score_columns($period);
            return [
                'labels' => array_map(static function (array $column): string {
                    $label = trim((string) ($column['label'] ?? ''));
                    return preg_replace('/^نمره\s+/u', '', $label) ?: $label;
                }, $columns),
                'keys_by_point' => array_map(static function (array $column): array {
                    return [sanitize_key((string) ($column['key'] ?? 'score_1'))];
                }, $columns),
            ];
        }

        $labels = array_values(array_map(static function (array $month): string {
            return trim((string) ($month['label'] ?? ''));
        }, $months));
        $month_count = count($labels);
        $keys_by_point = array_fill(0, $month_count, []);

        if ($score_count <= $month_count) {
            $base_size = intdiv($month_count, $score_count);
            $remainder = $month_count % $score_count;
            $month_offset = 0;
            for ($score_index = 1; $score_index <= $score_count; $score_index++) {
                $group_size = $base_size + ($score_index <= $remainder ? 1 : 0);
                $score_key = 'score_' . $score_index;
                for ($group_index = 0; $group_index < $group_size; $group_index++) {
                    $point_index = $month_offset + $group_index;
                    if (isset($keys_by_point[$point_index])) {
                        $keys_by_point[$point_index][] = $score_key;
                    }
                }
                $month_offset += $group_size;
            }
        } else {
            $base_slots = intdiv($score_count, $month_count);
            $remainder = $score_count % $month_count;
            $score_index = 1;
            for ($month_index = 0; $month_index < $month_count; $month_index++) {
                $slots = $base_slots + ($month_index < $remainder ? 1 : 0);
                for ($slot = 0; $slot < $slots; $slot++, $score_index++) {
                    $keys_by_point[$month_index][] = 'score_' . $score_index;
                }
            }
        }

        return [
            'labels' => $labels,
            'keys_by_point' => $keys_by_point,
        ];
    }

    /** @return array<string,mixed> */
    private function report_period_definition(int $term_id, string $period_key): array
    {
        global $wpdb;

        $period_key = sanitize_key($period_key);
        $cache_key = $term_id . ':' . $period_key;
        if (isset($this->report_period_cache[$cache_key])) {
            return $this->report_period_cache[$cache_key];
        }

        $period = $term_id && $period_key !== ''
            ? $wpdb->get_row($wpdb->prepare(
                "SELECT id, period_key, period_name, period_type, score_count
                 FROM {$wpdb->prefix}hst_score_periods
                 WHERE term_id = %d AND period_key = %s AND is_active = 1
                 LIMIT 1",
                $term_id,
                $period_key
            ))
            : null;

        $period_type = sanitize_key((string) ($period->period_type ?? ''));
        if (!in_array($period_type, ['weekly', 'monthly', 'custom'], true)) {
            $definition = [];
        } else {
            $score_count = $period_type === 'custom'
                ? max(1, min(20, absint($period->score_count ?? 1)))
                : 1;
            $score_keys = [];
            for ($index = 1; $index <= $score_count; $index++) {
                $score_keys[] = 'score_' . $index;
            }

            $definition = [
                'id'          => absint($period->id ?? 0),
                'key'         => $period_key,
                'name'        => trim((string) ($period->period_name ?? '')),
                'type'        => $period_type,
                'type_label'  => $this->report_period_type_label($period_type),
                'score_count' => $score_count,
                'score_keys'  => $score_keys,
            ];
        }

        $this->report_period_cache[$cache_key] = $definition;
        return $definition;
    }


    /**
     * Classes that can be used by the report-card print workflow.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function print_classes($term_id): array
    {
        global $wpdb;

        $term_id = absint($term_id);
        if (!$term_id) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.class_name, COUNT(DISTINCT uc.user_id) AS student_count
                 FROM {$wpdb->prefix}hst_classes c
                 INNER JOIN {$wpdb->prefix}hst_users_classes uc
                    ON uc.class_id = c.id AND uc.term_id = %d AND uc.role = 'student'
                 GROUP BY c.id, c.class_name",
                $term_id
            )
        ) ?: [];

        if (class_exists('HST_Classes')) {
            $rows = HST_Classes::sort_rows($rows, 'class_name', ['id']);
        }

        $grade_labels = [
            'tenth'    => 'دهم',
            'eleventh' => 'یازدهم',
            'twelfth'  => 'دوازدهم',
            'other'    => 'سایر',
        ];
        $major_labels = [
            'math'         => 'ریاضی و فیزیک',
            'experimental' => 'علوم تجربی',
            'humanities'   => 'ادبیات و علوم انسانی',
            'other'        => 'سایر',
        ];

        $items = [];
        foreach ($rows as $row) {
            $profile = class_exists('HST_Classes')
                ? HST_Classes::academic_profile((string) ($row->class_name ?? ''))
                : ['grade' => '', 'major' => ''];
            $grade = (string) ($profile['grade'] ?? '');
            $major = (string) ($profile['major'] ?? '');
            if ($grade === '') {
                $grade = 'other';
            }
            if ($major === '') {
                $major = 'other';
            }

            $items[] = [
                'id'            => absint($row->id ?? 0),
                'name'          => trim((string) ($row->class_name ?? '')),
                'grade'         => $grade,
                'grade_label'   => $grade_labels[$grade] ?? 'سایر',
                'major'         => $major,
                'major_label'   => $major_labels[$major] ?? 'سایر',
                'student_count' => max(0, (int) ($row->student_count ?? 0)),
            ];
        }

        return array_values(array_filter($items, static function (array $item): bool {
            return !empty($item['id']) && $item['name'] !== '' && $item['student_count'] > 0;
        }));
    }

    public function ajax_print_students(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
        if (!class_exists('HST_Roles') || !HST_Roles::can_access_screen('report_cards')) {
            HST_Guard::fail('دسترسی به چاپ کارنامه برای شما مجاز نیست.', 403);
        }

        $period_id = HST_Guard::post_int('period_id');
        $scope = $this->print_period_scope($period_id);
        if (is_wp_error($scope)) {
            HST_Guard::fail($scope->get_error_message());
        }

        $class_ids = HST_Guard::post_id_list('class_ids');
        $allowed_classes = array_column(self::print_classes((int) $scope['term']->id), null, 'id');
        $class_ids = array_values(array_filter($class_ids, static function (int $class_id) use ($allowed_classes): bool {
            return isset($allowed_classes[$class_id]);
        }));
        if (empty($class_ids)) {
            HST_Guard::fail('کلاس معتبری برای چاپ انتخاب نشده است.');
        }

        $students = $this->students_for_classes(
            (int) $scope['term']->id,
            $class_ids,
            0,
            (string) ($scope['period']->period_key ?? '')
        );
        $ready_count = count(array_filter($students, static function (array $student): bool {
            return !empty($student['report_ready']);
        }));

        wp_send_json_success([
            'students'         => $students,
            'count'            => count($students),
            'ready_count'      => $ready_count,
            'incomplete_count' => max(0, count($students) - $ready_count),
            'all_ready'        => !empty($students) && $ready_count === count($students),
        ]);
    }

    public function ajax_print_data(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
        if (!class_exists('HST_Roles') || !HST_Roles::can_access_screen('report_cards')) {
            HST_Guard::fail('دسترسی به چاپ کارنامه برای شما مجاز نیست.', 403);
        }

        $period_id = HST_Guard::post_int('period_id');
        $scope = $this->print_period_scope($period_id);
        if (is_wp_error($scope)) {
            HST_Guard::fail($scope->get_error_message());
        }

        $mode = HST_Guard::post_text('mode', 'class');
        $show_chart = HST_Guard::post_int('show_chart') === 1;
        $duplex = !$show_chart && HST_Guard::post_int('duplex') === 1;
        $class_ids = HST_Guard::post_id_list('class_ids');
        $student_id = HST_Guard::post_int('student_id');
        $student_class_id = HST_Guard::post_int('student_class_id');

        $allowed_classes = array_column(self::print_classes((int) $scope['term']->id), null, 'id');
        $class_ids = array_values(array_filter($class_ids, static function (int $class_id) use ($allowed_classes): bool {
            return isset($allowed_classes[$class_id]);
        }));

        if ($mode === 'individual') {
            if (!$student_id || !$student_class_id || !isset($allowed_classes[$student_class_id])) {
                HST_Guard::fail('دانش‌آموز انتخاب‌شده برای چاپ معتبر نیست.');
            }
            $students = $this->students_for_classes(
                (int) $scope['term']->id,
                [$student_class_id],
                $student_id,
                (string) ($scope['period']->period_key ?? '')
            );
        } else {
            if (empty($class_ids)) {
                HST_Guard::fail('کلاس معتبری برای چاپ انتخاب نشده است.');
            }
            $students = $this->students_for_classes(
                (int) $scope['term']->id,
                $class_ids,
                0,
                (string) ($scope['period']->period_key ?? '')
            );
        }

        if (empty($students)) {
            HST_Guard::fail('دانش‌آموزی برای چاپ کارنامه پیدا نشد.');
        }

        $incomplete_students = array_values(array_filter($students, static function (array $student): bool {
            return empty($student['report_ready']);
        }));
        if (!empty($incomplete_students)) {
            if ($mode === 'individual') {
                HST_Guard::fail('تا زمانی که همه نمرات این دانش‌آموز تعیین تکلیف نشده باشد، دریافت کارنامه امکان‌پذیر نیست.');
            }
            HST_Guard::fail(
                number_format_i18n(count($incomplete_students))
                . ' دانش‌آموز هنوز نمره تعیین‌تکلیف‌نشده دارد؛ دریافت کارنامه کلاس امکان‌پذیر نیست.'
            );
        }

        $subject_limit = $duplex ? 0 : 12;
        $cards = [];
        foreach ($students as $student) {
            $card = $this->build_print_card_data(
                $scope['term'],
                $scope['period'],
                (int) $student['id'],
                (int) $student['class_id'],
                $subject_limit
            );
            if (!is_wp_error($card)) {
                $cards[] = $card;
            }
        }

        if (empty($cards)) {
            HST_Guard::fail('اطلاعات لازم برای ساخت کارنامه‌ها کامل نیست.');
        }

        $period_type = sanitize_key((string) ($scope['period']->period_type ?? 'weekly'));
        $period_type_label = $this->report_period_type_label($period_type);
        $period_name = trim((string) ($scope['period']->period_name ?? ''));
        if ($period_name === '') {
            $period_name = 'کارنامه ' . $period_type_label;
        }
        $suffix = $mode === 'individual'
            ? sanitize_file_name((string) ($cards[0]['student']['name'] ?? 'دانش‌آموز'))
            : sanitize_file_name($period_name);

        wp_send_json_success([
            'html'              => $this->render_print_pages_html($cards, $duplex),
            'filename'          => 'کارنامه-' . ($suffix !== '' ? $suffix : $period_type_label) . '.pdf',
            'message'           => count($cards) . ' کارنامه برای خروجی آماده شد.',
            'period_type'       => $period_type,
            'period_type_label' => $period_type_label,
            'period_name'       => $period_name,
        ]);
    }

    /** @return array{term:object,period:object}|WP_Error */
    private function print_period_scope(int $period_id)
    {
        global $wpdb;

        $term = self::active_term();
        $term_id = absint($term->id ?? 0);
        if (!$term_id) {
            return new WP_Error('hst_report_print_no_term', 'سال تحصیلی فعالی برای چاپ کارنامه وجود ندارد.');
        }

        $period = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, period_key, period_name, period_type, score_count, start_date, end_date
                 FROM {$wpdb->prefix}hst_score_periods
                 WHERE id = %d AND term_id = %d AND is_active = 1
                 LIMIT 1",
                $period_id,
                $term_id
            )
        );
        $period_type = sanitize_key((string) ($period->period_type ?? ''));
        if (!$period || !in_array($period_type, ['weekly', 'monthly', 'custom'], true)) {
            return new WP_Error('hst_report_print_invalid_period', 'چاپ کارنامه فقط برای دوره‌های هفتگی، ماهانه و اختصاصی فعال است.');
        }

        return ['term' => $term, 'period' => $period];
    }

    /**
     * @param int[] $class_ids
     * @return array<int,array<string,mixed>>
     */
    private function students_for_classes(
        int $term_id,
        array $class_ids,
        int $only_student_id = 0,
        string $period_key = ''
    ): array {
        global $wpdb;

        $class_ids = array_values(array_unique(array_filter(array_map('absint', $class_ids))));
        if (!$term_id || empty($class_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($class_ids), '%d'));
        $params = array_merge([$term_id], $class_ids);
        $student_sql = '';
        if ($only_student_id) {
            $student_sql = ' AND uc.user_id = %d';
            $params[] = $only_student_id;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT uc.user_id, uc.class_id, c.class_name
             FROM {$wpdb->prefix}hst_users_classes uc
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = uc.class_id
             INNER JOIN {$wpdb->users} u ON u.ID = uc.user_id
             WHERE uc.term_id = %d
               AND uc.role = 'student'
               AND uc.class_id IN ({$placeholders})
               {$student_sql}
             GROUP BY uc.user_id, uc.class_id, c.class_name",
            ...$params
        )) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $user_id = absint($row->user_id ?? 0);
            $user = $user_id ? get_userdata($user_id) : null;
            if (!$user) {
                continue;
            }

            $first_name = trim((string) get_user_meta($user_id, 'first_name', true));
            $last_name = trim((string) get_user_meta($user_id, 'last_name', true));
            $name = trim($first_name . ' ' . $last_name);
            if ($name === '') {
                $name = trim((string) $user->display_name);
            }
            $national_code = trim((string) get_user_meta($user_id, 'hst_national_code', true));
            if ($national_code === '') {
                $national_code = trim((string) get_user_meta($user_id, 'hst_student_code', true));
            }
            if ($national_code === '') {
                $national_code = trim((string) $user->user_login);
            }

            $avatar_id = absint(get_user_meta($user_id, 'hst_profile_avatar_id', true));
            if (!$avatar_id && class_exists('HST_Avatar_Approval')) {
                $avatar_id = absint(HST_Avatar_Approval::display_avatar_id($user_id, $user_id));
            }
            $avatar_url = $avatar_id ? (string) wp_get_attachment_image_url($avatar_id, 'thumbnail') : '';

            $items[] = [
                'id'            => $user_id,
                'class_id'      => absint($row->class_id ?? 0),
                'name'          => $name ?: 'دانش‌آموز',
                'father_name'   => trim((string) get_user_meta($user_id, 'hst_father_name', true)),
                'national_code' => $national_code,
                'class_name'    => trim((string) ($row->class_name ?? '')),
                'avatar_url'    => $avatar_url,
                'initials'      => $this->user_initials($first_name, $last_name, $name),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $class_compare = class_exists('HST_Classes')
                ? HST_Classes::compare_names($left['class_name'], $right['class_name'])
                : strnatcasecmp($left['class_name'], $right['class_name']);
            if ($class_compare !== 0) {
                return $class_compare;
            }
            return strnatcasecmp($left['name'], $right['name']);
        });

        $items = array_values($items);
        $period_key = sanitize_key($period_key);
        if ($period_key !== '' && !empty($items)) {
            $items = $this->attach_report_readiness($items, $term_id, $period_key);
        }

        return $items;
    }

    private function report_summary_cache_key(int $term_id, string $period_key, int $class_id, int $student_id): string
    {
        return implode(':', [$term_id, sanitize_key($period_key), $class_id, $student_id]);
    }

    /**
     * Attach score-completion state in batches. A report-period score is considered
     * determined when it has a numeric score, an excused absence, or an
     * unexcused absence. Missing rows and present rows without a score remain
     * incomplete and block report-card delivery.
     *
     * @param array<int,array<string,mixed>> $students
     * @return array<int,array<string,mixed>>
     */
    private function attach_report_readiness(array $students, int $term_id, string $period_key): array
    {
        global $wpdb;

        $period_key = sanitize_key($period_key);
        if (!$term_id || $period_key === '' || empty($students)) {
            return $students;
        }

        $period_definition = $this->report_period_definition($term_id, $period_key);
        $required_score_keys = array_values(array_filter((array) ($period_definition['score_keys'] ?? [])));
        if (empty($required_score_keys)) {
            return $students;
        }

        $uncached = [];
        foreach ($students as $student) {
            $student_id = absint($student['id'] ?? 0);
            $class_id = absint($student['class_id'] ?? 0);
            if (!$student_id || !$class_id) {
                continue;
            }
            $key = $this->report_summary_cache_key($term_id, $period_key, $class_id, $student_id);
            if (!isset($this->report_student_summary_cache[$key])) {
                $uncached[] = ['id' => $student_id, 'class_id' => $class_id];
            }
        }

        if (!empty($uncached)) {
            $student_ids = array_values(array_unique(array_column($uncached, 'id')));
            $class_ids = array_values(array_unique(array_column($uncached, 'class_id')));
            $class_placeholders = implode(',', array_fill(0, count($class_ids), '%d'));
            $student_placeholders = implode(',', array_fill(0, count($student_ids), '%d'));

            $assignment_params = array_merge([$term_id], $class_ids, $student_ids);
            $assignment_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ul.user_id, ul.class_id, ul.lesson_id, ul.role, l.lesson_name
                 FROM {$wpdb->prefix}hst_users_lessons ul
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = ul.lesson_id
                 WHERE ul.term_id = %d
                   AND ul.class_id IN ({$class_placeholders})
                   AND (ul.role = 'teacher' OR (ul.role = 'student' AND ul.user_id IN ({$student_placeholders})))
                 GROUP BY ul.user_id, ul.class_id, ul.lesson_id, ul.role, l.lesson_name
                 ORDER BY ul.lesson_id ASC",
                ...$assignment_params
            )) ?: [];

            $specific_lessons = [];
            $class_lessons = [];
            foreach ($assignment_rows as $row) {
                $class_id = absint($row->class_id ?? 0);
                $lesson_id = absint($row->lesson_id ?? 0);
                if (!$class_id || !$lesson_id) {
                    continue;
                }
                $lesson = [
                    'lesson_id' => $lesson_id,
                    'title'     => trim((string) ($row->lesson_name ?? 'درس')),
                ];
                if ((string) ($row->role ?? '') === 'student') {
                    $student_id = absint($row->user_id ?? 0);
                    if ($student_id) {
                        $specific_lessons[$student_id . ':' . $class_id][$lesson_id] = $lesson;
                    }
                } else {
                    $class_lessons[$class_id][$lesson_id] = $lesson;
                }
            }

            $discipline_params = array_merge($class_ids, ['انضباط']);
            $discipline_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id AS lesson_id, class_id, lesson_name
                 FROM {$wpdb->prefix}hst_lessons
                 WHERE class_id IN ({$class_placeholders})
                   AND TRIM(lesson_name) = %s",
                ...$discipline_params
            )) ?: [];
            foreach ($discipline_rows as $row) {
                $discipline_class_id = absint($row->class_id ?? 0);
                $discipline_lesson_id = absint($row->lesson_id ?? 0);
                if ($discipline_class_id && $discipline_lesson_id) {
                    $class_lessons[$discipline_class_id][$discipline_lesson_id] = [
                        'lesson_id' => $discipline_lesson_id,
                        'title'     => trim((string) ($row->lesson_name ?? 'انضباط')),
                    ];
                }
            }

            $score_key_placeholders = implode(',', array_fill(0, count($required_score_keys), '%s'));
            $score_params = array_merge([$term_id, $period_key], $required_score_keys, $class_ids, $student_ids);
            $score_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ms.id, ms.student_id, ms.class_id, ms.lesson_id, ms.score_key, ms.score,
                        ms.is_present, ms.absence_excused, l.lesson_name,
                        COALESCE(ms.updated_at, ms.created_at) AS sort_date
                 FROM {$wpdb->prefix}hst_monthly_scores ms
                 LEFT JOIN {$wpdb->prefix}hst_lessons l ON l.id = ms.lesson_id
                 WHERE ms.term_id = %d
                   AND ms.month_key = %s
                   AND ms.score_key IN ({$score_key_placeholders})
                   AND (TRIM(COALESCE(l.lesson_name, '')) <> 'انضباط' OR ms.teacher_id = 0)
                   AND ms.class_id IN ({$class_placeholders})
                   AND ms.student_id IN ({$student_placeholders})
                 ORDER BY sort_date DESC, ms.id DESC",
                ...$score_params
            )) ?: [];

            $score_map = [];
            $score_lessons = [];
            foreach ($score_rows as $row) {
                $student_id = absint($row->student_id ?? 0);
                $class_id = absint($row->class_id ?? 0);
                $lesson_id = absint($row->lesson_id ?? 0);
                if (!$student_id || !$class_id || !$lesson_id) {
                    continue;
                }
                $row_score_key = sanitize_key((string) ($row->score_key ?? 'score_1'));
                $score_key = $student_id . ':' . $class_id . ':' . $lesson_id . ':' . $row_score_key;
                if (!isset($score_map[$score_key])) {
                    $score_map[$score_key] = $row;
                }
                $score_lessons[$class_id][$lesson_id] = [
                    'lesson_id' => $lesson_id,
                    'title'     => trim((string) ($row->lesson_name ?? 'درس')),
                ];
            }

            foreach ($uncached as $student) {
                $student_id = (int) $student['id'];
                $class_id = (int) $student['class_id'];
                $pair_key = $student_id . ':' . $class_id;
                $lessons = $specific_lessons[$pair_key] ?? [];
                if (empty($lessons)) {
                    $lessons = $class_lessons[$class_id] ?? [];
                }
                if (empty($lessons)) {
                    $lessons = $score_lessons[$class_id] ?? [];
                }

                // انضباط is a class-level lesson with no teacher assignment. It
                // must be required for every student even when that student has
                // a specific/elective lesson list that overrides class lessons.
                foreach ((array) ($class_lessons[$class_id] ?? []) as $class_lesson_id => $class_lesson) {
                    if (trim((string) ($class_lesson['title'] ?? '')) === 'انضباط') {
                        $lessons[$class_lesson_id] = $class_lesson;
                    }
                }

                ksort($lessons, SORT_NUMERIC);
                $lessons = array_values($lessons);

                $student_rows = [];
                foreach ($lessons as $lesson) {
                    $lesson_id = absint($lesson['lesson_id'] ?? 0);
                    foreach ($required_score_keys as $required_score_key) {
                        $lookup = $student_id . ':' . $class_id . ':' . $lesson_id . ':' . $required_score_key;
                        if (isset($score_map[$lookup])) {
                            $student_rows[$lesson_id][$required_score_key] = $score_map[$lookup];
                        }
                    }
                }
                $summary = $this->build_report_student_summary($lessons, $student_rows, $required_score_keys);
                $cache_key = $this->report_summary_cache_key($term_id, $period_key, $class_id, $student_id);
                $this->report_student_summary_cache[$cache_key] = $summary;
            }
        }

        foreach ($students as &$student) {
            $student_id = absint($student['id'] ?? 0);
            $class_id = absint($student['class_id'] ?? 0);
            $key = $this->report_summary_cache_key($term_id, $period_key, $class_id, $student_id);
            $summary = $this->report_student_summary_cache[$key] ?? [
                'ready' => false,
                'required' => 0,
                'completed' => 0,
                'missing' => 0,
                'average' => null,
            ];
            $student['report_ready'] = !empty($summary['ready']);
            $student['required_scores'] = (int) ($summary['required'] ?? 0);
            $student['completed_scores'] = (int) ($summary['completed'] ?? 0);
            $student['missing_scores'] = (int) ($summary['missing'] ?? 0);
            $student['report_average'] = $summary['average'] ?? null;
            $student['readiness_message'] = !empty($summary['ready'])
                ? 'کارنامه آماده دریافت است.'
                : ((int) ($summary['required'] ?? 0) === 0
                    ? 'برای این دانش‌آموز درسی تعریف نشده است.'
                    : number_format_i18n((int) ($summary['missing'] ?? 0)) . ' نمره هنوز تعیین تکلیف نشده است.');
        }
        unset($student);

        return $students;
    }

    /**
     * @param array<int,array{lesson_id:int,title:string}> $lessons
     * @param array<int,object> $score_rows Indexed by lesson id.
     * @return array<string,mixed>
     */
    private function build_report_student_summary(array $lessons, array $score_rows, array $required_score_keys): array
    {
        $states = [];
        $completed = 0;
        $average_total = 0.0;
        $average_count = 0;
        $required_score_keys = array_values(array_unique(array_filter(array_map('sanitize_key', $required_score_keys))));
        $component_totals = array_fill_keys($required_score_keys, 0.0);
        $component_counts = array_fill_keys($required_score_keys, 0);

        foreach ($lessons as $lesson) {
            $lesson_id = absint($lesson['lesson_id'] ?? 0);
            if (!$lesson_id) {
                continue;
            }

            $lesson_rows = (array) ($score_rows[$lesson_id] ?? []);
            $component_states = [];
            $lesson_total = 0.0;
            $lesson_count = 0;
            $lesson_completed = 0;
            $component_absences = [];

            foreach ($required_score_keys as $score_key) {
                $row = $lesson_rows[$score_key] ?? null;
                $component = [
                    'determined' => false,
                    'score'      => null,
                    'absence'    => '',
                    'included'   => false,
                ];

                if ($row) {
                    $is_present = (int) ($row->is_present ?? 1) === 1;
                    if (!$is_present) {
                        $component['determined'] = true;
                        $component['absence'] = (int) ($row->absence_excused ?? 0) === 1 ? 'excused' : 'unexcused';
                        if ($component['absence'] === 'unexcused') {
                            $component['score'] = 0.0;
                            $component['included'] = true;
                        }
                    } elseif ($row->score !== null && $row->score !== '') {
                        $component['determined'] = true;
                        $component['score'] = round((float) $row->score, 2);
                        $component['included'] = true;
                    }
                }

                if ($component['determined']) {
                    $completed++;
                    $lesson_completed++;
                }
                if ($component['included']) {
                    $lesson_total += (float) $component['score'];
                    $lesson_count++;
                    $component_totals[$score_key] += (float) $component['score'];
                    $component_counts[$score_key]++;
                }
                if ($component['absence'] !== '') {
                    $component_absences[] = $component['absence'];
                }
                $component_states[$score_key] = $component;
            }

            $lesson_determined = !empty($required_score_keys) && $lesson_completed === count($required_score_keys);
            $lesson_score = $lesson_count > 0 ? round($lesson_total / $lesson_count, 2) : null;
            $lesson_absence = '';
            if ($lesson_determined && count($component_absences) === count($required_score_keys)) {
                $unique_absences = array_values(array_unique($component_absences));
                if (count($unique_absences) === 1) {
                    $lesson_absence = (string) $unique_absences[0];
                }
            }

            $state = [
                'determined' => $lesson_determined,
                'score'      => $lesson_score,
                'absence'    => $lesson_absence,
                'included'   => $lesson_count > 0,
                'components' => $component_states,
            ];

            if ($state['included']) {
                $average_total += (float) $state['score'];
                $average_count++;
            }
            $states[$lesson_id] = $state;
        }

        $component_averages = [];
        foreach ($required_score_keys as $score_key) {
            $count = (int) ($component_counts[$score_key] ?? 0);
            $component_averages[$score_key] = $count > 0
                ? round(((float) ($component_totals[$score_key] ?? 0.0)) / $count, 2)
                : null;
        }

        $lesson_count = count($states);
        $required = $lesson_count * count($required_score_keys);
        return [
            'lessons'   => array_values(array_filter($lessons, static function (array $lesson): bool {
                return absint($lesson['lesson_id'] ?? 0) > 0;
            })),
            'states'    => $states,
            'ready'     => $required > 0 && $completed === $required,
            'required'  => $required,
            'completed' => $completed,
            'missing'   => max(0, $required - $completed),
            'average'   => $average_count > 0 ? round($average_total / $average_count, 2) : null,
            'component_averages' => $component_averages,
        ];
    }

    /** @return array<string,mixed> */
    private function report_student_summary(int $term_id, string $period_key, int $class_id, int $student_id): array
    {
        $key = $this->report_summary_cache_key($term_id, $period_key, $class_id, $student_id);
        if (!isset($this->report_student_summary_cache[$key])) {
            $this->attach_report_readiness([
                ['id' => $student_id, 'class_id' => $class_id],
            ], $term_id, $period_key);
        }

        return $this->report_student_summary_cache[$key] ?? [
            'lessons' => [],
            'states' => [],
            'ready' => false,
            'required' => 0,
            'completed' => 0,
            'missing' => 0,
            'average' => null,
            'component_averages' => [],
        ];
    }

    /** @return array<int,?float> */
    private function report_class_average_map(int $term_id, string $period_key, int $class_id): array
    {
        $cache_key = implode(':', [$term_id, sanitize_key($period_key), $class_id]);
        if (isset($this->report_class_average_cache[$cache_key])) {
            return $this->report_class_average_cache[$cache_key];
        }

        $students = $this->students_for_classes($term_id, [$class_id]);
        $students = $this->attach_report_readiness($students, $term_id, $period_key);
        $totals = [];
        $counts = [];
        foreach ($students as $student) {
            $summary = $this->report_student_summary(
                $term_id,
                $period_key,
                $class_id,
                absint($student['id'] ?? 0)
            );
            foreach ((array) ($summary['states'] ?? []) as $lesson_id => $state) {
                if (empty($state['determined']) || empty($state['included'])) {
                    continue;
                }
                $lesson_id = absint($lesson_id);
                $totals[$lesson_id] = ($totals[$lesson_id] ?? 0.0) + (float) ($state['score'] ?? 0.0);
                $counts[$lesson_id] = ($counts[$lesson_id] ?? 0) + 1;
            }
        }

        $averages = [];
        foreach ($totals as $lesson_id => $total) {
            $count = (int) ($counts[$lesson_id] ?? 0);
            $averages[$lesson_id] = $count > 0 ? round($total / $count, 2) : null;
        }

        // The stored score rows are the authoritative source for chart class
        // averages. This is especially important for monthly periods: a
        // student's current lesson-assignment graph or report readiness may be
        // incomplete even though a valid monthly score has already been saved.
        // Direct averages therefore replace summary-derived values whenever
        // they exist; summary values only remain as a fallback for lessons with
        // no direct rows at all.
        $direct_averages = $this->report_direct_class_average_map($term_id, $period_key, $class_id);
        foreach ($direct_averages as $lesson_id => $average) {
            if ($average !== null && $average !== '') {
                $averages[absint($lesson_id)] = (float) $average;
            }
        }

        $this->report_class_average_cache[$cache_key] = $averages;
        return $averages;
    }

    /**
     * Aggregate the latest stored score rows directly, independent of the
     * current teacher/student lesson-assignment graph.
     *
     * @return array<int,?float>
     */
    private function report_direct_class_average_map(int $term_id, string $period_key, int $class_id): array
    {
        global $wpdb;

        $period_key = sanitize_key($period_key);
        $cache_key = implode(':', [$term_id, $period_key, $class_id]);
        if (isset($this->report_direct_class_average_cache[$cache_key])) {
            return $this->report_direct_class_average_cache[$cache_key];
        }

        $definition = $this->report_period_definition($term_id, $period_key);
        $score_keys = array_values(array_filter(array_map('sanitize_key', (array) ($definition['score_keys'] ?? []))));
        if (!$term_id || !$class_id || $period_key === '' || empty($score_keys)) {
            return $this->report_direct_class_average_cache[$cache_key] = [];
        }

        $score_key_placeholders = implode(',', array_fill(0, count($score_keys), '%s'));
        $params = array_merge([$term_id, $period_key], $score_keys, [$class_id]);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ms.id, ms.student_id, ms.lesson_id, ms.score_key, ms.score,
                    ms.is_present, ms.absence_excused,
                    COALESCE(ms.updated_at, ms.created_at) AS sort_date
             FROM {$wpdb->prefix}hst_monthly_scores ms
             LEFT JOIN {$wpdb->prefix}hst_lessons l ON l.id = ms.lesson_id
             WHERE ms.term_id = %d
               AND ms.month_key = %s
               AND ms.score_key IN ({$score_key_placeholders})
               AND ms.class_id = %d
               AND (TRIM(COALESCE(l.lesson_name, '')) <> 'انضباط' OR ms.teacher_id = 0)
             ORDER BY sort_date DESC, ms.id DESC",
            ...$params
        )) ?: [];

        // Keep only the latest row for every student/lesson/component.
        $latest = [];
        foreach ($rows as $row) {
            $student_id = absint($row->student_id ?? 0);
            $lesson_id = absint($row->lesson_id ?? 0);
            $score_key = sanitize_key((string) ($row->score_key ?? ''));
            if (!$student_id || !$lesson_id || !in_array($score_key, $score_keys, true)) {
                continue;
            }
            $row_key = $student_id . ':' . $lesson_id . ':' . $score_key;
            if (!isset($latest[$row_key])) {
                $latest[$row_key] = $row;
            }
        }

        $student_lessons = [];
        foreach ($latest as $row) {
            $student_id = absint($row->student_id ?? 0);
            $lesson_id = absint($row->lesson_id ?? 0);
            $score_key = sanitize_key((string) ($row->score_key ?? ''));
            $student_lessons[$student_id . ':' . $lesson_id][$score_key] = $row;
        }

        $totals = [];
        $counts = [];
        foreach ($student_lessons as $student_lesson_key => $component_rows) {
            $parts = explode(':', $student_lesson_key);
            $lesson_id = absint($parts[1] ?? 0);
            if (!$lesson_id) {
                continue;
            }

            $determined = 0;
            $included_scores = [];
            foreach ($score_keys as $score_key) {
                $row = $component_rows[$score_key] ?? null;
                if (!$row) {
                    continue;
                }

                $is_present = (int) ($row->is_present ?? 1) === 1;
                if (!$is_present) {
                    $determined++;
                    if ((int) ($row->absence_excused ?? 0) !== 1) {
                        $included_scores[] = 0.0;
                    }
                    continue;
                }

                if ($row->score !== null && $row->score !== '') {
                    $determined++;
                    $included_scores[] = (float) $row->score;
                }
            }

            if ($determined !== count($score_keys) || empty($included_scores)) {
                continue;
            }

            $student_lesson_average = array_sum($included_scores) / count($included_scores);
            $totals[$lesson_id] = ($totals[$lesson_id] ?? 0.0) + $student_lesson_average;
            $counts[$lesson_id] = ($counts[$lesson_id] ?? 0) + 1;
        }

        $averages = [];
        foreach ($totals as $lesson_id => $total) {
            $count = (int) ($counts[$lesson_id] ?? 0);
            if ($count > 0) {
                $averages[$lesson_id] = round($total / $count, 2);
            }
        }

        $this->report_direct_class_average_cache[$cache_key] = $averages;
        return $averages;
    }

    /** @return array<string,mixed>|WP_Error */
    private function build_print_card_data(object $term, object $period, int $student_id, int $class_id, int $subject_limit)
    {
        global $wpdb;

        $user = $student_id ? get_userdata($student_id) : null;
        if (!$user || !$class_id) {
            return new WP_Error('hst_report_print_student_invalid', 'اطلاعات دانش‌آموز برای چاپ کامل نیست.');
        }

        $class_name = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT class_name FROM {$wpdb->prefix}hst_classes WHERE id = %d LIMIT 1",
            $class_id
        ));
        $first_name = trim((string) get_user_meta($student_id, 'first_name', true));
        $last_name = trim((string) get_user_meta($student_id, 'last_name', true));
        $student_name = trim($first_name . ' ' . $last_name);
        if ($student_name === '') {
            $student_name = trim((string) $user->display_name);
        }

        $avatar_id = absint(get_user_meta($student_id, 'hst_profile_avatar_id', true));
        if (!$avatar_id && class_exists('HST_Avatar_Approval')) {
            $avatar_id = absint(HST_Avatar_Approval::display_avatar_id($student_id, $student_id));
        }
        $avatar_url = $avatar_id ? (string) wp_get_attachment_image_url($avatar_id, 'medium') : '';

        $student_code = trim((string) get_user_meta($student_id, 'hst_national_code', true));
        if ($student_code === '') {
            $student_code = trim((string) get_user_meta($student_id, 'hst_student_code', true));
        }
        if ($student_code === '') {
            $student_code = trim((string) $user->user_login);
        }

        $term_id = absint($term->id ?? 0);
        $period_key = (string) ($period->period_key ?? '');
        $summary = $this->report_student_summary($term_id, $period_key, $class_id, $student_id);
        if (empty($summary['ready'])) {
            return new WP_Error('hst_report_print_scores_incomplete', 'همه نمرات دانش‌آموز تعیین تکلیف نشده است.');
        }

        $subjects = $this->actual_subject_rows(
            $term_id,
            $period_key,
            $class_id,
            $student_id,
            $subject_limit
        );
        $class_ranking = $this->actual_ranking($term_id, $period_key, $class_id);
        $school_ranking = $this->actual_ranking($term_id, $period_key, 0);

        $school_name = $this->report_school_name();

        $logo_url = '';
        if (class_exists('HST_Settings')) {
            $logo_id = absint(HST_Settings::option('hst-home-logo-id', 0));
            $logo_url = $logo_id ? (string) wp_get_attachment_image_url($logo_id, 'medium') : '';
        }

        $tracking_code = strtoupper(substr(hash_hmac('sha256', implode('|', [
            absint($term->id ?? 0),
            absint($period->id ?? 0),
            $student_id,
            $class_id,
        ]), wp_salt('auth')), 0, 10));
        $qr_payload = add_query_arg([
            'report_card_section' => 'monthly',
            'period_id'           => absint($period->id ?? 0),
            'student_id'          => $student_id,
            'tracking'            => $tracking_code,
        ], home_url('/report-cards/'));

        return [
            'school' => [
                'name'     => $school_name ?: 'مدرسه',
                'logo_url' => $logo_url,
                'manager'  => $this->school_manager_name(),
            ],
            'term' => [
                'id'   => absint($term->id ?? 0),
                'name' => trim((string) ($term->term_name ?? '')),
            ],
            'period' => [
                'id'         => absint($period->id ?? 0),
                'key'        => (string) ($period->period_key ?? ''),
                'name'       => trim((string) ($period->period_name ?? '')),
                'type'        => sanitize_key((string) ($period->period_type ?? 'weekly')),
                'type_label'  => $this->report_period_type_label((string) ($period->period_type ?? 'weekly')),
                'score_count' => max(1, absint($period->score_count ?? 1)),
                'start_date' => trim((string) ($period->start_date ?? '')),
                'end_date'   => trim((string) ($period->end_date ?? '')),
            ],
            'student' => [
                'id'          => $student_id,
                'name'        => $student_name ?: 'دانش‌آموز',
                'first_name'  => $first_name,
                'last_name'   => $last_name,
                'father_name' => trim((string) get_user_meta($student_id, 'hst_father_name', true)),
                'code'        => $student_code,
                'avatar_url'  => $avatar_url,
                'initials'    => $this->user_initials($first_name, $last_name, $student_name),
            ],
            'class' => [
                'id'   => $class_id,
                'name' => $class_name ?: 'کلاس ثبت‌شده',
            ],
            'subjects'      => $subjects,
            'class_top'     => array_slice($class_ranking['top'], 0, 3),
            'school_top'    => array_slice($school_ranking['top'], 0, 3),
            'class_rank'    => $this->ranking_for_student($class_ranking, $student_id),
            'school_rank'   => $this->ranking_for_student($school_ranking, $student_id),
            'average'       => $summary['average'] ?? null,
            'component_averages' => (array) ($summary['component_averages'] ?? []),
            'tracking_code' => $tracking_code,
            'qr_payload'    => $qr_payload,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function actual_subject_rows(int $term_id, string $period_key, int $class_id, int $student_id, int $limit): array
    {
        $summary = $this->report_student_summary($term_id, $period_key, $class_id, $student_id);
        $average_map = $this->report_class_average_map($term_id, $period_key, $class_id);
        $items = [];

        $lessons = (array) ($summary['lessons'] ?? []);
        $discipline = null;
        $regular_lessons = [];
        foreach ($lessons as $lesson) {
            if (trim((string) ($lesson['title'] ?? '')) === 'انضباط') {
                $discipline = $lesson;
            } else {
                $regular_lessons[] = $lesson;
            }
        }
        if ($limit > 0) {
            $regular_limit = $discipline ? max(0, $limit - 1) : $limit;
            $selected_lessons = array_slice($regular_lessons, 0, $regular_limit);
        } else {
            // A zero limit means that no lesson may be removed. This is used by
            // chart-free two-up printing, where the released chart area belongs
            // to the complete lesson table rather than to an empty placeholder.
            $selected_lessons = $regular_lessons;
        }
        if ($discipline) {
            $selected_lessons[] = $discipline;
        }

        foreach ($selected_lessons as $lesson) {
            $lesson_id = absint($lesson['lesson_id'] ?? 0);
            $state = (array) (($summary['states'] ?? [])[$lesson_id] ?? []);
            $items[] = [
                'lesson_id'     => $lesson_id,
                'title'         => trim((string) ($lesson['title'] ?? 'درس')),
                'score'         => $state['score'] ?? null,
                'class_average' => $average_map[$lesson_id] ?? null,
                'absence'       => (string) ($state['absence'] ?? ''),
                'determined'    => !empty($state['determined']),
                'components'    => (array) ($state['components'] ?? []),
            ];
        }

        return $items;
    }

    /** @return array{rows:array<int,array<string,mixed>>,top:array<int,array<string,mixed>>,total:int} */
    private function actual_ranking(int $term_id, string $period_key, int $class_id): array
    {
        $cache_key = implode(':', [$term_id, sanitize_key($period_key), $class_id]);
        if (isset($this->report_ranking_cache[$cache_key])) {
            return $this->report_ranking_cache[$cache_key];
        }

        $class_ids = $class_id
            ? [$class_id]
            : array_values(array_filter(array_map(static function (array $item): int {
                return absint($item['id'] ?? 0);
            }, self::print_classes($term_id))));
        if (empty($class_ids)) {
            return ['rows' => [], 'top' => [], 'total' => 0];
        }

        $students = $this->students_for_classes($term_id, $class_ids);
        $students = $this->attach_report_readiness($students, $term_id, $period_key);
        $ranked = [];
        foreach ($students as $student) {
            if (empty($student['report_ready']) || $student['report_average'] === null) {
                continue;
            }
            $ranked[] = [
                'student_id' => absint($student['id'] ?? 0),
                'name'       => trim((string) ($student['name'] ?? 'دانش‌آموز')),
                'score'      => round((float) $student['report_average'], 2),
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            $score_compare = ((float) $right['score']) <=> ((float) $left['score']);
            return $score_compare !== 0
                ? $score_compare
                : ((int) $left['student_id'] <=> (int) $right['student_id']);
        });
        foreach ($ranked as $index => &$row) {
            $row['position'] = $index + 1;
        }
        unset($row);

        $result = [
            'rows'  => $ranked,
            'top'   => array_map(static function (array $row): array {
                return ['name' => $row['name'], 'score' => $row['score']];
            }, array_slice($ranked, 0, 3)),
            'total' => count($ranked),
        ];
        $this->report_ranking_cache[$cache_key] = $result;
        return $result;
    }

    /** @return array{position:int,total:int,score:?float} */
    private function ranking_for_student(array $ranking, int $student_id): array
    {
        foreach ((array) ($ranking['rows'] ?? []) as $row) {
            if (absint($row['student_id'] ?? 0) === $student_id) {
                return [
                    'position' => (int) ($row['position'] ?? 0),
                    'total'    => (int) ($ranking['total'] ?? 0),
                    'score'    => isset($row['score']) ? (float) $row['score'] : null,
                ];
            }
        }

        return [
            'position' => 0,
            'total'    => (int) ($ranking['total'] ?? 0),
            'score'    => null,
        ];
    }

    public function ajax_get_preview(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');

        if (!class_exists('HST_Roles') || !HST_Roles::can_access_screen('report_cards')) {
            HST_Guard::fail('دسترسی به پیش‌نمایش کارنامه برای شما مجاز نیست.', 403);
        }

        $period_id = HST_Guard::post_int('period_id');
        if (!$period_id) {
            HST_Guard::fail('دوره کارنامه مشخص نشده است.');
        }

        $show_chart = HST_Guard::post_int('show_chart') === 1;
        $duplex = !$show_chart && HST_Guard::post_int('duplex') === 1;
        $preview_count = $duplex ? 2 : 1;
        $subject_limit = $duplex ? 0 : 12;
        $previews = [];
        $excluded_students = [];

        for ($index = 0; $index < $preview_count; $index++) {
            $preview = $this->build_preview_data($period_id, $excluded_students, $subject_limit);

            // A school may have only one student. In that case the second half of
            // the two-up preview intentionally repeats the available student.
            if (is_wp_error($preview) && $index > 0) {
                $preview = $this->build_preview_data($period_id, [], $subject_limit);
            }

            if (is_wp_error($preview)) {
                HST_Guard::fail($preview->get_error_message());
            }

            $previews[] = $preview;
            $student_id = absint($preview['student']['id'] ?? 0);
            if ($student_id) {
                $excluded_students[] = $student_id;
            }
        }

        wp_send_json_success([
            'html'              => $this->render_preview_html($previews, $duplex),
            'duplex'            => $duplex,
            'period_name'       => (string) ($previews[0]['period']['name'] ?? ''),
            'period_type'       => (string) ($previews[0]['period']['type'] ?? 'weekly'),
            'period_type_label' => (string) ($previews[0]['period']['type_label'] ?? 'هفتگی'),
        ]);
    }

    /**
     * Picks real identity, class, school and lesson information from the active
     * term. Analytical values are intentionally generated as complete sample
     * data so the template can always be reviewed before real cards are issued.
     *
     * @param int[] $excluded_student_ids
     * @return array|WP_Error
     */
    private function build_preview_data(int $period_id, array $excluded_student_ids = [], int $subject_limit = 12)
    {
        global $wpdb;

        $term = self::active_term();
        $term_id = absint($term->id ?? 0);
        if (!$term_id) {
            return new WP_Error('hst_report_preview_no_term', 'سال تحصیلی فعالی برای پیش‌نمایش کارنامه وجود ندارد.');
        }

        $period = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, period_key, period_name, period_type, score_count, start_date, end_date
                 FROM {$wpdb->prefix}hst_score_periods
                 WHERE id = %d AND term_id = %d AND is_active = 1
                 LIMIT 1",
                $period_id,
                $term_id
            )
        );

        $period_type = sanitize_key((string) ($period->period_type ?? ''));
        if (!$period || !in_array($period_type, ['weekly', 'monthly', 'custom'], true)) {
            return new WP_Error('hst_report_preview_invalid_period', 'پیش‌نمایش کارنامه فقط برای دوره‌های هفتگی، ماهانه و اختصاصی فعال است.');
        }

        $candidates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT uc.user_id AS student_id, uc.class_id
                 FROM {$wpdb->prefix}hst_users_classes uc
                 INNER JOIN {$wpdb->users} u ON u.ID = uc.user_id
                 WHERE uc.term_id = %d AND uc.role = 'student'
                 GROUP BY uc.user_id, uc.class_id
                 ORDER BY uc.user_id ASC, uc.class_id ASC",
                $term_id
            )
        ) ?: [];

        if (!empty($excluded_student_ids)) {
            $excluded_lookup = array_fill_keys(array_map('absint', $excluded_student_ids), true);
            $candidates = array_values(array_filter($candidates, static function ($row) use ($excluded_lookup): bool {
                return empty($excluded_lookup[absint($row->student_id ?? 0)]);
            }));
        }

        if (empty($candidates)) {
            return new WP_Error('hst_report_preview_no_student', 'در سال تحصیلی فعال دانش‌آموزی برای پیش‌نمایش کارنامه پیدا نشد.');
        }

        $candidate = $candidates[wp_rand(0, count($candidates) - 1)];
        $student_id = absint($candidate->student_id ?? 0);
        $class_id = absint($candidate->class_id ?? 0);
        $student_user = $student_id ? get_userdata($student_id) : null;
        if (!$student_user || !$class_id) {
            return new WP_Error('hst_report_preview_student_invalid', 'اطلاعات دانش‌آموز منتخب کامل نیست.');
        }

        $class_name = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT class_name FROM {$wpdb->prefix}hst_classes WHERE id = %d LIMIT 1",
                $class_id
            )
        );

        $first_name = trim((string) get_user_meta($student_id, 'first_name', true));
        $last_name = trim((string) get_user_meta($student_id, 'last_name', true));
        $student_name = trim($first_name . ' ' . $last_name);
        if ($student_name === '') {
            $student_name = trim((string) $student_user->display_name);
        }

        $avatar_id = absint(get_user_meta($student_id, 'hst_profile_avatar_id', true));
        if (!$avatar_id && class_exists('HST_Avatar_Approval')) {
            $avatar_id = absint(HST_Avatar_Approval::display_avatar_id($student_id, $student_id));
        }
        $avatar_url = $avatar_id ? (string) wp_get_attachment_image_url($avatar_id, 'thumbnail') : '';

        $student_code = trim((string) get_user_meta($student_id, 'hst_national_code', true));
        if ($student_code === '') {
            $student_code = trim((string) get_user_meta($student_id, 'hst_student_code', true));
        }
        if ($student_code === '') {
            $student_code = trim((string) $student_user->user_login);
        }

        $lesson_rows = $this->preview_lessons($term_id, $class_id, $student_id, $subject_limit);
        $preview_score_count = sanitize_key((string) ($period->period_type ?? 'weekly')) === 'custom'
            ? max(1, min(20, absint($period->score_count ?? 1)))
            : 1;
        $subjects = [];
        $preview_component_totals = [];
        $preview_component_counts = [];
        for ($component_index = 1; $component_index <= $preview_score_count; $component_index++) {
            $component_key = 'score_' . $component_index;
            $preview_component_totals[$component_key] = 0.0;
            $preview_component_counts[$component_key] = 0;
        }
        foreach ($lesson_rows as $lesson) {
            $components = [];
            $lesson_total = 0.0;
            for ($component_index = 1; $component_index <= $preview_score_count; $component_index++) {
                $component_key = 'score_' . $component_index;
                $component_score = round($this->sample_score(), 2);
                $components[$component_key] = [
                    'determined' => true,
                    'score'      => $component_score,
                    'absence'    => '',
                    'included'   => true,
                ];
                $lesson_total += $component_score;
                $preview_component_totals[$component_key] += $component_score;
                $preview_component_counts[$component_key]++;
            }
            $score = round($lesson_total / $preview_score_count, 2);
            $average = max(0.0, min(20.0, $score - (wp_rand(3, 8) / 4)));
            $subjects[] = [
                'lesson_id'     => absint($lesson['lesson_id'] ?? 0),
                'title'         => (string) ($lesson['title'] ?? 'درس نمونه'),
                'score'         => $score,
                'class_average' => round($average, 2),
                'components'    => $components,
            ];
        }

        $preview_component_averages = [];
        foreach ($preview_component_totals as $component_key => $total) {
            $count = (int) ($preview_component_counts[$component_key] ?? 0);
            $preview_component_averages[$component_key] = $count > 0 ? round($total / $count, 2) : null;
        }

        $sample_scores = array_values(array_filter(array_map(static function (array $subject) {
            return isset($subject['score']) && is_numeric($subject['score']) ? (float) $subject['score'] : null;
        }, $subjects), static function ($score): bool {
            return $score !== null;
        }));
        $sample_average = !empty($sample_scores)
            ? round(array_sum($sample_scores) / count($sample_scores), 2)
            : null;

        $class_total = max(1, (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id)
                 FROM {$wpdb->prefix}hst_users_classes
                 WHERE term_id = %d AND class_id = %d AND role = 'student'",
                $term_id,
                $class_id
            )
        ));
        $school_total = max(1, (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id)
                 FROM {$wpdb->prefix}hst_users_classes
                 WHERE term_id = %d AND role = 'student'",
                $term_id
            )
        ));

        $school_name = $this->report_school_name();

        $logo_url = '';
        if (class_exists('HST_Settings')) {
            $logo_id = absint(HST_Settings::option('hst-home-logo-id', 0));
            $logo_url = $logo_id ? (string) wp_get_attachment_image_url($logo_id, 'medium') : '';
        }

        $tracking_code = strtoupper(substr(hash('sha256', implode('|', [
            $term_id,
            (int) $period->id,
            $student_id,
            $class_id,
            wp_rand(1000, 9999),
        ])), 0, 10));

        $qr_payload = add_query_arg([
            'report_card_section' => 'monthly',
            'period_id'           => (int) $period->id,
            'student_id'          => $student_id,
            'tracking'            => $tracking_code,
        ], home_url('/report-cards/'));
        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=14&data=' . rawurlencode($qr_payload);

        return [
            'school' => [
                'name'     => $school_name ?: 'مدرسه',
                'logo_url' => $logo_url,
                'manager'  => $this->school_manager_name(),
            ],
            'term' => [
                'id'   => $term_id,
                'name' => trim((string) ($term->term_name ?? '')),
            ],
            'period' => [
                'id'         => (int) $period->id,
                'key'        => (string) $period->period_key,
                'name'       => trim((string) $period->period_name),
                'type'        => sanitize_key((string) ($period->period_type ?? 'weekly')),
                'type_label'  => $this->report_period_type_label((string) ($period->period_type ?? 'weekly')),
                'score_count' => max(1, absint($period->score_count ?? 1)),
                'start_date' => trim((string) $period->start_date),
                'end_date'   => trim((string) $period->end_date),
            ],
            'student' => [
                'id'          => $student_id,
                'name'        => $student_name ?: 'دانش‌آموز',
                'first_name'  => $first_name,
                'last_name'   => $last_name,
                'father_name' => trim((string) get_user_meta($student_id, 'hst_father_name', true)),
                'code'        => $student_code,
                'avatar_url'  => $avatar_url,
                'initials'    => $this->user_initials($first_name, $last_name, $student_name),
            ],
            'class' => [
                'id'   => $class_id,
                'name' => $class_name ?: 'کلاس ثبت‌شده',
            ],
            'subjects'      => $subjects,
            'class_top'     => $this->sample_top_students($term_id, $class_id, 3, $student_name),
            'school_top'    => $this->sample_top_students($term_id, 0, 3, $student_name),
            'class_rank'    => $this->sample_rank($class_total),
            'school_rank'   => $this->sample_rank($school_total),
            'average'       => $sample_average,
            'component_averages' => $preview_component_averages,
            'tracking_code' => $tracking_code,
            'qr_payload'    => $qr_payload,
            'qr_url'        => $qr_url,
        ];
    }

    /**
     * Returns real lesson titles, prioritising the selected student's class.
     */
    private function preview_lessons(int $term_id, int $class_id, int $student_id, int $limit): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT l.id AS lesson_id, l.lesson_name
                 FROM {$wpdb->prefix}hst_users_lessons ul
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = ul.lesson_id
                 WHERE ul.user_id = %d
                   AND ul.class_id = %d
                   AND ul.term_id = %d
                 ORDER BY l.id ASC",
                $student_id,
                $class_id,
                $term_id
            )
        ) ?: [];

        $unlimited = $limit <= 0;
        if ($unlimited || count($rows) < $limit) {
            $class_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT l.id AS lesson_id, l.lesson_name
                     FROM {$wpdb->prefix}hst_users_lessons ul
                     INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = ul.lesson_id
                     WHERE ul.class_id = %d AND ul.term_id = %d
                     ORDER BY l.id ASC",
                    $class_id,
                    $term_id
                )
            ) ?: [];
            $rows = array_merge($rows, $class_rows);
        }

        $discipline_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id AS lesson_id, lesson_name
             FROM {$wpdb->prefix}hst_lessons
             WHERE class_id = %d AND TRIM(lesson_name) = %s
             LIMIT 1",
            $class_id,
            'انضباط'
        ));
        if ($discipline_row) {
            $rows[] = $discipline_row;
        }

        if ((!$unlimited && count($rows) < $limit) || ($unlimited && empty($rows))) {
            $rows = array_merge($rows, $wpdb->get_results(
                "SELECT id AS lesson_id, lesson_name FROM {$wpdb->prefix}hst_lessons ORDER BY id ASC LIMIT 40"
            ) ?: []);
        }

        $unique = [];
        foreach ($rows as $row) {
            $lesson_id = absint($row->lesson_id ?? 0);
            $title = trim((string) ($row->lesson_name ?? ''));
            if ($lesson_id && $title !== '') {
                $unique[$lesson_id] = ['lesson_id' => $lesson_id, 'title' => $title];
            }
        }

        if (empty($unique)) {
            $fallback = ['فارسی', 'نگارش', 'دین و زندگی', 'عربی', 'زبان انگلیسی', 'ریاضی', 'فیزیک', 'شیمی', 'جغرافیا', 'آمادگی دفاعی', 'تفکر و سواد رسانه‌ای'];
            foreach ($fallback as $index => $title) {
                $unique[-($index + 1)] = ['lesson_id' => 0, 'title' => $title];
            }
        }

        $discipline = null;
        foreach ($unique as $key => $lesson) {
            if (trim((string) ($lesson['title'] ?? '')) === 'انضباط') {
                $discipline = $lesson;
                unset($unique[$key]);
            }
        }
        $items = $unlimited
            ? array_values($unique)
            : array_slice(array_values($unique), 0, max(0, $limit - 1));
        $items[] = $discipline ?: ['lesson_id' => 0, 'title' => 'انضباط'];
        return $items;
    }

    private function sample_score(): float
    {
        return round(wp_rand(32, 80) / 4, 2);
    }

    private function sample_rank(int $total): array
    {
        $total = max(1, $total);
        $upper = min($total, max(1, (int) ceil($total * 0.25)));
        return [
            'position' => wp_rand(1, $upper),
            'total'    => $total,
            'score'    => $this->sample_score(),
        ];
    }

    private function sample_top_students(int $term_id, int $class_id, int $limit, string $fallback_name): array
    {
        global $wpdb;

        $params = [$term_id];
        $class_sql = '';
        if ($class_id) {
            $class_sql = ' AND uc.class_id = %d';
            $params[] = $class_id;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT uc.user_id
             FROM {$wpdb->prefix}hst_users_classes uc
             INNER JOIN {$wpdb->users} u ON u.ID = uc.user_id
             WHERE uc.term_id = %d AND uc.role = 'student' {$class_sql}
             ORDER BY RAND()
             LIMIT 12",
            ...$params
        )) ?: [];

        $names = [];
        foreach ($rows as $row) {
            $user_id = absint($row->user_id ?? 0);
            $user = $user_id ? get_userdata($user_id) : null;
            if (!$user) {
                continue;
            }
            $name = trim(
                (string) get_user_meta($user_id, 'first_name', true)
                . ' '
                . (string) get_user_meta($user_id, 'last_name', true)
            );
            if ($name === '') {
                $name = trim((string) $user->display_name);
            }
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        if (empty($names) && $fallback_name !== '') {
            $names[] = $fallback_name;
        }
        while (count($names) < $limit) {
            $names[] = 'دانش‌آموز نمونه ' . number_format_i18n(count($names) + 1);
        }

        $top = [];
        $base = wp_rand(76, 80) / 4;
        foreach (array_slice($names, 0, $limit) as $index => $name) {
            $top[] = [
                'name'  => $name,
                'score' => max(16.0, round($base - ($index * 0.25), 2)),
            ];
        }
        return $top;
    }

    private function school_manager_name(): string
    {
        foreach (['modir', 'administrator'] as $role) {
            $users = get_users([
                'role'    => $role,
                'orderby' => 'ID',
                'order'   => 'ASC',
                'number'  => 20,
                'fields'  => ['ID', 'display_name'],
            ]);

            foreach ($users as $user) {
                $name = trim(
                    (string) get_user_meta((int) $user->ID, 'first_name', true)
                    . ' '
                    . (string) get_user_meta((int) $user->ID, 'last_name', true)
                );
                if ($name === '') {
                    $name = trim((string) ($user->display_name ?? ''));
                }
                if ($name === '') {
                    continue;
                }

                $normalized = function_exists('mb_strtolower')
                    ? mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?: $name, 'UTF-8')
                    : strtolower($name);
                $is_generic_role_name = in_array($normalized, [
                    'مدیر مدرسه',
                    'مدير مدرسه',
                    'مدیر تیچرشو',
                    'مدير تيچرشو',
                    'teachershow manager',
                    'manager teachershow',
                ], true) || preg_match('/^(?:مدیر|مدير)\s+(?:مدرسه|آموزشگاه)(?:\s+.+)?$/u', $normalized);

                if ($is_generic_role_name) {
                    continue;
                }

                return $name;
            }
        }

        return 'مدیر مدرسه';
    }

    private function user_initials(string $first, string $last, string $display_name): string
    {
        $initials = [];
        if ($first !== '') {
            $initials[] = mb_substr($first, 0, 1);
        }
        if ($last !== '') {
            $initials[] = mb_substr($last, 0, 1);
        }
        if (empty($initials)) {
            $parts = preg_split('/\s+/u', trim($display_name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (!empty($parts)) {
                $initials[] = mb_substr((string) $parts[0], 0, 1);
                if (count($parts) > 1) {
                    $initials[] = mb_substr((string) end($parts), 0, 1);
                }
            }
        }
        return $initials ? implode("\u{00A0}", $initials) : '؟';
    }

    private function render_preview_html(array $previews, bool $duplex): string
    {
        return $this->render_print_pages_html($previews, $duplex);
    }

    /**
     * Renders one real A4 page per card, or two cards per page when duplex is
     * active. Preview and downloadable PDF intentionally share this exact HTML
     * renderer so visual changes can no longer drift between the two paths.
     */
    private function render_print_pages_html(array $cards, bool $duplex): string
    {
        $cards = array_values($cards);
        if (empty($cards)) {
            return '';
        }

        $pages = array_chunk($cards, $duplex ? 2 : 1);
        ob_start();
        ?>
        <div class="hst-report-print-pages" data-hst-report-print-pages>
            <?php foreach ($pages as $page_cards) : ?>
                <div class="hst-report-preview-page<?php echo $duplex ? ' is-duplex' : ''; ?>" data-hst-report-preview-page>
                    <?php foreach ($page_cards as $index => $card) : ?>
                        <?php echo $this->render_preview_card((array) $card, $duplex); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php if ($duplex && $index === 0 && count($page_cards) > 1) : ?>
                            <div class="hst-print-cut-line hst-report-preview-cut-line" aria-hidden="true"></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_preview_card(array $data, bool $compact = false): string
    {
        if (!function_exists('hst_icon') && defined('HST_PATH')) {
            include_once HST_PATH . 'templates/user/common/hst-icons.php';
        }

        $school = (array) ($data['school'] ?? []);
        $term = (array) ($data['term'] ?? []);
        $period = (array) ($data['period'] ?? []);
        $student = (array) ($data['student'] ?? []);
        $class = (array) ($data['class'] ?? []);
        $subjects = (array) ($data['subjects'] ?? []);
        $dense_subjects = $compact && count($subjects) >= 14;
        $class_top = (array) ($data['class_top'] ?? []);
        $school_top = (array) ($data['school_top'] ?? []);
        $class_rank = (array) ($data['class_rank'] ?? []);
        $school_rank = (array) ($data['school_rank'] ?? []);
        $average = isset($data['average']) && is_numeric($data['average'])
            ? round((float) $data['average'], 2)
            : null;
        $component_averages = (array) ($data['component_averages'] ?? []);
        $score_columns = $this->report_score_columns($period);
        $score_column_count = max(1, count($score_columns));
        $has_multiple_score_columns = $score_column_count > 1;
        $has_many_score_columns = $score_column_count > 4;
        $score_column_width = $compact
            ? ($score_column_count <= 2 ? 46 : ($score_column_count <= 4 ? 34 : 26))
            : ($score_column_count <= 2 ? 72 : ($score_column_count <= 4 ? 54 : 38));

        $rank_text = static function (array $rank): string {
            $position = absint($rank['position'] ?? 0);
            $total = absint($rank['total'] ?? 0);
            if (!$position || !$total) {
                return 'ثبت نشده';
            }
            return number_format_i18n($position) . ' از ' . number_format_i18n($total);
        };

        $average_text = $average === null ? 'محاسبه نمی‌شود' : hst_format_grade($average);

        // Descriptive performance bands are shared by lesson scores and the
        // overall average. Absence and missing-score states remain explicit.
        $performance_status = static function ($value): string {
            if (!is_numeric($value)) {
                return 'ثبت نشده';
            }

            $numeric = (float) $value;
            if ($numeric >= 18) {
                return 'خیلی خوب';
            }
            if ($numeric >= 15) {
                return 'خوب';
            }
            if ($numeric >= 10) {
                return 'درحدانتظار';
            }
            return 'نیاز به تلاش بیشتر';
        };

        $top_table = static function (array $rows): string {
            $rows = array_values(array_slice($rows, 0, 3));
            while (count($rows) < 3) {
                $rows[] = ['name' => '', 'score' => null];
            }

            $html = '<table><thead><tr><th>ردیف</th><th>نام و نام خانوادگی</th><th>معدل</th></tr></thead><tbody>';
            foreach ($rows as $index => $row) {
                $name = trim((string) ($row['name'] ?? ''));
                $score = $row['score'] ?? null;
                $html .= '<tr><td>' . ($name !== '' ? esc_html(number_format_i18n($index + 1)) : '') . '</td><td>'
                    . esc_html($name) . '</td><td>'
                    . ($score === null ? '' : esc_html(hst_format_grade($score))) . '</td></tr>';
            }
            return $html . '</tbody></table>';
        };

        $avatar_html = !empty($student['avatar_url'])
            ? '<img class="hst-report-preview-avatar" src="' . esc_url((string) $student['avatar_url']) . '" alt="تصویر ' . esc_attr((string) ($student['name'] ?? 'دانش‌آموز')) . '">'
            : '<span class="hst-report-preview-avatar hst-report-preview-avatar--initials" aria-hidden="true">' . esc_html((string) ($student['initials'] ?? '؟')) . '</span>';

        $logo_html = !empty($school['logo_url'])
            ? '<img src="' . esc_url((string) $school['logo_url']) . '" alt="لوگوی ' . esc_attr((string) ($school['name'] ?? 'مدرسه')) . '">'
            : '<span class="hst-report-preview-brand__mark">' . hst_icon('students') . '</span>';

        $period_type = sanitize_key((string) ($period['type'] ?? 'weekly'));
        if (!in_array($period_type, ['weekly', 'monthly', 'custom'], true)) {
            $period_type = 'weekly';
        }
        $period_type_label = trim((string) ($period['type_label'] ?? ''));
        if ($period_type_label === '') {
            $period_type_label = $this->report_period_type_label($period_type);
        }
        $report_card_heading = 'کارنامه تحصیلی ' . $period_type_label . ' دانش‌آموز';

        $period_meta = [];
        if (!empty($period['name'])) {
            $period_meta[] = (string) $period['name'];
        }
        if (!empty($term['name'])) {
            $period_meta[] = 'سال تحصیلی ' . (string) $term['name'];
        }

        ob_start();
        ?>
        <article class="hst-report-preview-sheet<?php echo $compact ? ' is-compact' : ''; ?><?php echo $dense_subjects ? ' has-dense-subjects' : ''; ?><?php echo $has_multiple_score_columns ? ' has-multi-score-columns' : ''; ?><?php echo $has_many_score_columns ? ' has-many-score-columns' : ''; ?>" data-hst-report-preview-sheet data-score-column-count="<?php echo esc_attr($score_column_count); ?>">
            <header class="hst-report-preview-head">
                <div class="hst-report-preview-qr">
                    <span class="hst-report-preview-qr__link" aria-label="کد دریافت نسخه دیجیتال کارنامه">
                        <span class="hst-report-preview-qr__code" data-hst-report-preview-qr data-qr-payload="<?php echo esc_attr((string) ($data['qr_payload'] ?? '')); ?>">
                            <?php if (!empty($data['qr_url'])) : ?><img src="<?php echo esc_url((string) $data['qr_url']); ?>" alt="کد دریافت نسخه دیجیتال"><?php endif; ?>
                        </span>
                    </span>
                    <small>دریافت نسخه دیجیتال</small>
                </div>

                <div class="hst-report-preview-brand">
                    <?php echo $logo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <h2><?php echo esc_html((string) ($school['name'] ?? 'مدرسه')); ?></h2>
                    <p><?php echo esc_html($report_card_heading); ?></p>
                    <small><?php echo esc_html(implode(' • ', $period_meta)); ?></small>
                </div>

                <div class="hst-report-preview-student">
                    <?php echo $avatar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <div class="hst-report-preview-student__info">
                        <strong><?php echo esc_html((string) ($student['name'] ?? 'دانش‌آموز')); ?></strong>
                        <span>نام پدر: <?php echo esc_html((string) ($student['father_name'] ?: 'ثبت نشده')); ?></span>
                        <span>کلاس: <?php echo esc_html((string) ($class['name'] ?? 'ثبت نشده')); ?></span>
                        <span class="hst-report-preview-student__national-code"><span>کد ملی:</span><b><?php echo esc_html((string) ($student['code'] ?: 'ثبت نشده')); ?></b></span>
                    </div>
                </div>
            </header>

            <section class="hst-report-preview-ranks" aria-label="رتبه‌های دانش‌آموز">
                <div class="hst-report-preview-rank hst-report-preview-rank--school">
                    <span class="hst-report-preview-rank__icon"><?php echo hst_icon('award'); ?></span>
                    <div><small>رتبه در مدرسه</small><strong><?php echo esc_html($rank_text($school_rank)); ?></strong></div>
                </div>
                <div class="hst-report-preview-rank hst-report-preview-rank--class">
                    <span class="hst-report-preview-rank__icon"><?php echo hst_icon('scores'); ?></span>
                    <div><small>رتبه در کلاس</small><strong><?php echo esc_html($rank_text($class_rank)); ?></strong></div>
                </div>
                <div class="hst-report-preview-rank hst-report-preview-rank--average">
                    <span class="hst-report-preview-rank__icon"><?php echo hst_icon('report'); ?></span>
                    <div><small>معدل</small><strong><?php echo esc_html($average_text); ?></strong></div>
                </div>
            </section>

            <section class="hst-report-preview-content" data-hst-report-preview-content>
                <div class="hst-report-preview-scores">
                    <h3>نمرات دروس</h3>
                    <div class="hst-report-preview-table-wrap">
                        <table class="hst-report-preview-table" data-hst-report-score-columns="<?php echo esc_attr($score_column_count); ?>">
                            <colgroup>
                                <col class="hst-report-preview-col hst-report-preview-col--index">
                                <col class="hst-report-preview-col hst-report-preview-col--subject">
                                <?php foreach ($score_columns as $score_column) : ?>
                                    <col class="hst-report-preview-col hst-report-preview-col--score" style="width:<?php echo esc_attr($score_column_width); ?>px">
                                <?php endforeach; ?>
                                <col class="hst-report-preview-col hst-report-preview-col--status">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th class="hst-report-preview-cell hst-report-preview-cell--index">ردیف</th>
                                    <th class="hst-report-preview-cell hst-report-preview-cell--subject">عنوان درس / گروه</th>
                                    <?php foreach ($score_columns as $score_column) : ?>
                                        <th class="hst-report-preview-cell hst-report-preview-cell--score"><?php echo esc_html((string) ($score_column['label'] ?? 'نمره')); ?></th>
                                    <?php endforeach; ?>
                                    <th class="hst-report-preview-cell hst-report-preview-cell--status">وضعیت</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($subjects as $index => $subject) :
                                $score = $subject['score'] ?? null;
                                $absence = sanitize_key((string) ($subject['absence'] ?? ''));
                                $score_attr = $score === null ? '' : hst_format_grade($score, false);
                                $components = (array) ($subject['components'] ?? []);
                                if ($absence === 'excused') {
                                    $status_text = 'محاسبه نمی‌شود';
                                } elseif ($absence === 'unexcused') {
                                    $status_text = $performance_status(0);
                                } elseif ($score === null) {
                                    $status_text = 'ثبت نشده';
                                } else {
                                    $status_text = $performance_status($score);
                                }
                            ?>
                                <tr
                                    class="<?php echo $score !== null && (float) $score < 10 ? 'is-low' : ''; ?>"
                                    data-hst-report-preview-score-row
                                    data-score="<?php echo esc_attr($score_attr); ?>"
                                    data-absence="<?php echo esc_attr($absence); ?>"
                                >
                                    <td class="hst-report-preview-cell hst-report-preview-cell--index"><?php echo esc_html(number_format_i18n($index + 1)); ?></td>
                                    <td class="hst-report-preview-cell hst-report-preview-cell--subject"><?php echo esc_html((string) ($subject['title'] ?? '')); ?></td>
                                    <?php foreach ($score_columns as $score_column) :
                                        $score_key = sanitize_key((string) ($score_column['key'] ?? 'score_1'));
                                        $component = (array) ($components[$score_key] ?? []);
                                        if (empty($component) && $score_column_count === 1) {
                                            $component = [
                                                'score' => $score,
                                                'absence' => $absence,
                                                'determined' => $score !== null || $absence !== '',
                                            ];
                                        }
                                        $component_score = $component['score'] ?? null;
                                        $component_absence = sanitize_key((string) ($component['absence'] ?? ''));
                                        $component_attr = $component_score === null ? '' : hst_format_grade($component_score, false);
                                        if ($component_absence === 'excused') {
                                            $component_text = 'غیبت موجه';
                                        } elseif ($component_absence === 'unexcused') {
                                            $component_text = hst_format_grade(0);
                                            $component_attr = hst_format_grade(0, false);
                                        } elseif ($component_score === null) {
                                            $component_text = '—';
                                        } else {
                                            $component_text = hst_format_grade($component_score);
                                        }
                                        $component_low = $component_absence === 'unexcused'
                                            || ($component_score !== null && (float) $component_score < 10);
                                    ?>
                                        <td
                                            class="hst-report-preview-cell hst-report-preview-cell--score<?php echo $component_low ? ' is-low' : ''; ?>"
                                            data-hst-report-preview-component-score
                                            data-score="<?php echo esc_attr($component_attr); ?>"
                                            data-absence="<?php echo esc_attr($component_absence); ?>"
                                        ><?php echo esc_html($component_text); ?></td>
                                    <?php endforeach; ?>
                                    <td class="hst-report-preview-cell hst-report-preview-cell--status" data-hst-report-preview-status><?php echo esc_html($status_text); ?></td>
                                </tr>
                            <?php endforeach; ?>
                                <tr
                                    class="hst-report-preview-average-row<?php echo $average !== null && $average < 10 ? ' is-low' : ''; ?>"
                                    data-hst-report-preview-average-row
                                    data-average="<?php echo esc_attr($average === null ? '' : hst_format_grade($average, false)); ?>"
                                >
                                    <td class="hst-report-preview-cell hst-report-preview-cell--index hst-report-preview-average-index" aria-hidden="true"></td>
                                    <td class="hst-report-preview-cell hst-report-preview-cell--subject hst-report-preview-average-label">معدل کارنامه</td>
                                    <?php foreach ($score_columns as $score_column) :
                                        $score_key = sanitize_key((string) ($score_column['key'] ?? 'score_1'));
                                        $component_average = $component_averages[$score_key] ?? null;
                                        if ($component_average === null && $score_column_count === 1) {
                                            $component_average = $average;
                                        }
                                        $component_average_attr = $component_average === null ? '' : hst_format_grade($component_average, false);
                                    ?>
                                        <td
                                            class="hst-report-preview-cell hst-report-preview-cell--score hst-report-preview-average-score<?php echo $component_average !== null && (float) $component_average < 10 ? ' is-low' : ''; ?>"
                                            data-hst-report-preview-component-average-score
                                            data-average="<?php echo esc_attr($component_average_attr); ?>"
                                        ><?php echo esc_html($component_average === null ? 'محاسبه نمی‌شود' : hst_format_grade($component_average)); ?></td>
                                    <?php endforeach; ?>
                                    <td class="hst-report-preview-cell hst-report-preview-cell--status hst-report-preview-average-status" data-hst-report-preview-average-status><?php echo esc_html($average === null ? 'تعیین نشده' : $performance_status($average)); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <aside class="hst-report-preview-aside" data-hst-report-preview-aside>
                    <section class="hst-report-preview-top hst-report-preview-top--class" data-hst-report-preview-class-top>
                        <h3><?php echo hst_icon('award'); ?><span>نفرات برتر کلاس</span></h3>
                        <?php echo $top_table($class_top); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </section>

                    <section class="hst-report-preview-top hst-report-preview-top--school" data-hst-report-preview-school-top>
                        <h3><?php echo hst_icon('students'); ?><span>نفرات برتر مدرسه</span></h3>
                        <?php echo $top_table($school_top); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </section>

                    <section class="hst-report-preview-message" data-hst-report-preview-manager-message>
                        <h3><?php echo hst_icon('sms'); ?><span>پیام مدیر</span></h3>
                        <p data-hst-report-preview-manager-message-text>دانش‌آموز عزیز، تلاش مستمر و مسئولیت‌پذیری تو ارزشمند است. با همین پشتکار مسیر پیشرفت را ادامه بده.</p>
                        <footer>
                            <strong><?php echo esc_html((string) ($school['manager'] ?? 'مدیر مدرسه')); ?></strong>
                        </footer>
                    </section>
                </aside>
            </section>

            <section class="hst-report-preview-chart<?php echo ($period['type'] ?? '') === 'custom' ? ' is-custom-period-chart' : ''; ?>" data-hst-report-preview-chart>
                <?php if (($period['type'] ?? '') === 'custom') : ?>
                    <h3>نمودار روند ماهانه دروس</h3>
                    <div class="hst-report-preview-chart__canvas hst-report-preview-chart__canvas--custom">
                        <?php echo $this->render_custom_chart_svg($period, $subjects); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php echo $this->render_custom_chart_legend($subjects); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                <?php else : ?>
                    <h3>نمودار تحلیلی پیشرفت تحصیلی</h3>
                    <div class="hst-report-preview-chart__canvas">
                        <div class="hst-report-preview-chart__legend-bar" aria-label="راهنمای نمودار">
                            <span class="hst-report-preview-chart__legend-item hst-report-preview-chart__legend-item--student">
                                <span class="hst-report-preview-chart__legend-key" aria-hidden="true">
                                    <svg class="hst-report-preview-chart__legend-svg" viewBox="0 0 28 12" focusable="false" aria-hidden="true">
                                        <line class="hst-report-preview-chart__legend-student-line" x1="1" y1="6" x2="27" y2="6"></line>
                                        <circle class="hst-report-preview-chart__legend-student-dot" cx="14" cy="6" r="4"></circle>
                                    </svg>
                                </span>
                                <span>نمره دانش‌آموز</span>
                            </span>
                            <span class="hst-report-preview-chart__legend-item hst-report-preview-chart__legend-item--average">
                                <span class="hst-report-preview-chart__legend-key" aria-hidden="true">
                                    <svg class="hst-report-preview-chart__legend-svg" viewBox="0 0 28 12" focusable="false" aria-hidden="true">
                                        <line class="hst-report-preview-chart__legend-average-line" x1="1" y1="6" x2="27" y2="6"></line>
                                    </svg>
                                </span>
                                <span>میانگین کلاس</span>
                            </span>
                        </div>
                        <?php echo $this->render_chart_svg($subjects); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="hst-report-preview-footer">
                <div class="hst-report-preview-footer__note">
                    <span>این کارنامه پس از مهر و امضای مدیر مدرسه اعتبار دارد.</span>
                </div>
                <div class="hst-report-preview-manager-area">
                    <div class="hst-report-preview-manager-box"><span>مهر مدرسه</span></div>
                    <div class="hst-report-preview-manager-box"><span>امضای مدیر</span><strong><?php echo esc_html((string) ($school['manager'] ?? 'مدیر مدرسه')); ?></strong></div>
                </div>
            </footer>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    /** @return string[] */
    private function report_chart_palette(): array
    {
        return [
            '#2563eb', '#ef4444', '#10b981', '#f59e0b',
            '#8b5cf6', '#ec4899', '#14b8a6', '#f97316',
            '#06b6d4', '#6366f1', '#84cc16', '#0284c7',
        ];
    }

    private function render_custom_chart_legend(array $subjects): string
    {
        $items = array_slice(array_values($subjects), 0, 12);
        if (empty($items)) {
            return '';
        }

        $palette = $this->report_chart_palette();
        $legend = [];
        foreach ($items as $index => $item) {
            $title = trim((string) ($item['title'] ?? 'درس'));
            $color = $palette[$index % count($palette)];
            $legend[] = '<span class="hst-report-preview-chart__legend-item hst-report-preview-chart__legend-item--subject" style="--hst-report-series-color:' . esc_attr($color) . '">'
                . '<span class="hst-report-preview-chart__legend-key" aria-hidden="true"></span>'
                . '<span>' . esc_html($title) . '</span>'
                . '</span>';
        }

        return '<div class="hst-report-preview-chart__legend-bar hst-report-preview-chart__legend-bar--subjects" aria-label="راهنمای نمودار دروس">'
            . implode('', $legend)
            . '</div>';
    }

    private function render_custom_chart_svg(array $period, array $subjects): string
    {
        $items = array_slice(array_values($subjects), 0, 12);
        $axis = $this->report_custom_chart_axis($period);
        $labels = array_values((array) ($axis['labels'] ?? []));
        $keys_by_point = array_values((array) ($axis['keys_by_point'] ?? []));
        if (empty($items) || empty($labels) || count($labels) !== count($keys_by_point)) {
            return '';
        }

        $left = 74.0;
        $right = 906.0;
        $top = 18.0;
        $bottom = 194.0;
        $point_count = count($labels);
        $step = $point_count > 1 ? (($right - $left) / ($point_count - 1)) : 0.0;
        $palette = $this->report_chart_palette();

        $point_y = static function ($score) use ($top, $bottom): float {
            $value = max(0.0, min(20.0, (float) $score));
            return $bottom - (($value / 20.0) * ($bottom - $top));
        };
        $path = static function (array $points): string {
            $parts = [];
            foreach ($points as $index => $point) {
                $parts[] = ($index === 0 ? 'M' : 'L') . round((float) $point[0], 2) . ' ' . round((float) $point[1], 2);
            }
            return implode(' ', $parts);
        };

        $grid_values = [20, 15, 10, 5, 0];
        $grid_lines = [];
        $y_labels = [];
        foreach ($grid_values as $value) {
            $y = $point_y($value);
            $grid_lines[] = 'M' . $left . ' ' . round($y, 2) . 'H' . $right;
            $y_labels[] = '<text x="48" y="' . esc_attr((string) round($y, 2)) . '">' . esc_html(number_format_i18n($value)) . '</text>';
        }

        $x_labels = [];
        foreach ($labels as $index => $label) {
            $x = $point_count > 1 ? $left + ($step * $index) : (($left + $right) / 2);
            $x_labels[] = '<text x="' . esc_attr((string) round($x, 2)) . '" y="218" text-anchor="middle" direction="rtl">' . esc_html((string) $label) . '</text>';
        }

        $series_markup = [];
        $dot_markup = [];
        foreach ($items as $series_index => $item) {
            $components = (array) ($item['components'] ?? []);
            $segments = [];
            $segment = [];
            $series_dots = [];

            foreach ($keys_by_point as $point_index => $score_keys) {
                $scores = [];
                foreach ((array) $score_keys as $score_key) {
                    $component = (array) ($components[sanitize_key((string) $score_key)] ?? []);
                    if (!empty($component['included']) && isset($component['score']) && is_numeric($component['score'])) {
                        $scores[] = (float) $component['score'];
                    }
                }

                if (empty($scores)) {
                    if (!empty($segment)) {
                        $segments[] = $segment;
                        $segment = [];
                    }
                    continue;
                }

                $score = array_sum($scores) / count($scores);
                $x = $point_count > 1 ? $left + ($step * $point_index) : (($left + $right) / 2);
                $y = $point_y($score);
                $segment[] = [$x, $y];
                $series_dots[] = '<circle cx="' . esc_attr((string) round($x, 2)) . '" cy="' . esc_attr((string) round($y, 2)) . '" r="3.2"/>';
            }
            if (!empty($segment)) {
                $segments[] = $segment;
            }

            $color = $palette[$series_index % count($palette)];
            $title = trim((string) ($item['title'] ?? 'درس'));
            foreach ($segments as $points) {
                if (count($points) === 1) {
                    continue;
                }
                $series_markup[] = '<path class="hst-report-preview-chart__subject-series" style="stroke:' . esc_attr($color) . '" d="' . esc_attr($path($points)) . '"><title>' . esc_html($title) . '</title></path>';
            }
            if (!empty($series_dots)) {
                $dot_markup[] = '<g class="hst-report-preview-chart__subject-dots" style="fill:' . esc_attr($color) . '">' . implode('', $series_dots) . '</g>';
            }
        }

        return '<svg class="hst-report-preview-chart__custom-svg" viewBox="0 0 960 244" role="img" aria-label="نمودار روند ماهانه نمرات دروس">'
            . '<g class="hst-report-preview-chart__grid"><path d="' . esc_attr(implode('', $grid_lines)) . '"/><path d="M' . $left . ' ' . $top . 'V' . $bottom . 'M' . $left . ' ' . $bottom . 'H' . $right . '"/></g>'
            . '<g class="hst-report-preview-chart__labels hst-report-preview-chart__labels--y">' . implode('', $y_labels) . '</g>'
            . '<g class="hst-report-preview-chart__labels hst-report-preview-chart__labels--x hst-report-preview-chart__labels--months">' . implode('', $x_labels) . '</g>'
            . '<g class="hst-report-preview-chart__subject-series-group">' . implode('', $series_markup) . '</g>'
            . '<g class="hst-report-preview-chart__subject-dots-group">' . implode('', $dot_markup) . '</g>'
            . '</svg>';
    }

    private function render_chart_svg(array $subjects): string
    {
        $items = array_slice(array_values($subjects), 0, 12);
        if (empty($items)) {
            return '';
        }

        $left = 128.0;
        $right = 832.0;
        $top = 18.0;
        $bottom = 180.0;
        $count = count($items);
        $step = $count > 1 ? (($right - $left) / ($count - 1)) : 0;
        $student_segments = [];
        $average_segments = [];
        $student_dots = [];
        $x_labels = [];
        $student_segment = [];
        $average_segment = [];

        $point_y = static function ($score) use ($top, $bottom): float {
            $value = max(0.0, min(20.0, (float) $score));
            return $bottom - (($value / 20.0) * ($bottom - $top));
        };

        $flush_segment = static function (array &$segment, array &$segments): void {
            if (!empty($segment)) {
                $segments[] = $segment;
                $segment = [];
            }
        };

        foreach ($items as $index => $item) {
            $x = $count > 1 ? $left + ($step * $index) : (($left + $right) / 2);
            $has_score = array_key_exists('score', $item) && $item['score'] !== null && $item['score'] !== '';
            $has_average = array_key_exists('class_average', $item) && $item['class_average'] !== null && $item['class_average'] !== '';

            if ($has_score) {
                $score = max(0.0, min(20.0, (float) $item['score']));
                $y = $point_y($score);
                $student_segment[] = [$x, $y];
                $student_dots[] = '<circle cx="' . esc_attr((string) round($x, 2)) . '" cy="' . esc_attr((string) round($y, 2)) . '" r="5"/>';
            } else {
                $flush_segment($student_segment, $student_segments);
            }

            if ($has_average) {
                $average_score = max(0.0, min(20.0, (float) $item['class_average']));
                $average_y = $point_y($average_score);

                // Keep the real class-average value unchanged, but separate its
                // visual path slightly when the displayed student and class
                // averages are identical. This prevents the dashed average line
                // from sitting directly on top of the solid student line.
                if ($has_score && abs(round($score, 2) - round($average_score, 2)) < 0.00001) {
                    $average_y -= 4.0;
                }

                $average_segment[] = [$x, $average_y];
            } else {
                $flush_segment($average_segment, $average_segments);
            }

            $label = trim((string) ($item['title'] ?? ''));
            $label_length = function_exists('mb_strlen')
                ? mb_strlen($label, 'UTF-8')
                : strlen($label);
            $label_font_size = $label_length > 36 ? 7.0 : ($label_length > 28 ? 8.0 : ($label_length > 20 ? 9.0 : 10.0));
            $max_text_length = $count >= 10 ? 112 : ($count >= 7 ? 138 : 180);
            $estimated_text_length = $label_length * $label_font_size * 0.66;
            $text_length_attributes = $estimated_text_length > $max_text_length
                ? ' textLength="' . esc_attr((string) $max_text_length) . '" lengthAdjust="spacingAndGlyphs"'
                : '';
            $label_x = round($x, 2);
            $anchor = 'middle';
            $x_labels[] = '<text x="' . esc_attr((string) $label_x) . '" y="210" text-anchor="' . esc_attr($anchor) . '" transform="rotate(-42 ' . esc_attr((string) $label_x) . ' 210)" direction="rtl" style="font-size:' . esc_attr((string) $label_font_size) . 'px"' . $text_length_attributes . '><title>' . esc_html($label) . '</title>' . esc_html($label) . '</text>';
        }
        $flush_segment($student_segment, $student_segments);
        $flush_segment($average_segment, $average_segments);

        $path = static function (array $points): string {
            $parts = [];
            foreach ($points as $index => $point) {
                $parts[] = ($index === 0 ? 'M' : 'L') . round((float) $point[0], 2) . ' ' . round((float) $point[1], 2);
            }
            return implode(' ', $parts);
        };

        $student_paths = [];
        foreach ($student_segments as $segment) {
            $student_paths[] = '<path class="hst-report-preview-chart__student" d="' . esc_attr($path($segment)) . '"/>';
        }
        $average_paths = [];
        foreach ($average_segments as $segment) {
            $average_paths[] = '<path class="hst-report-preview-chart__average" d="' . esc_attr($path($segment)) . '"/>';
        }

        $grid_values = [20, 15, 10, 5, 0];
        $grid_lines = [];
        $y_labels = [];
        foreach ($grid_values as $value) {
            $y = $point_y($value);
            $grid_lines[] = 'M' . $left . ' ' . round($y, 2) . 'H' . $right;
            $y_labels[] = '<text x="48" y="' . esc_attr((string) round($y, 2)) . '">' . esc_html(number_format_i18n($value)) . '</text>';
        }

        return '<svg viewBox="0 0 960 292" role="img" aria-label="نمودار مقایسه‌ای نمرات دانش‌آموز و میانگین کلاس">'
            . '<g class="hst-report-preview-chart__grid"><path d="' . esc_attr(implode('', $grid_lines)) . '"/><path d="M' . $left . ' ' . $top . 'V' . $bottom . 'M' . $left . ' ' . $bottom . 'H' . $right . '"/></g>'
            . '<g class="hst-report-preview-chart__labels hst-report-preview-chart__labels--y">' . implode('', $y_labels) . '</g>'
            . '<g class="hst-report-preview-chart__labels hst-report-preview-chart__labels--x">' . implode('', $x_labels) . '</g>'
            . implode('', $student_paths)
            // Render the dashed class-average path after the solid student
            // path. When both series share the same values, the previous SVG
            // paint order hid the average completely beneath the student line.
            . implode('', $average_paths)
            . '<g class="hst-report-preview-chart__dots">' . implode('', $student_dots) . '</g>'
            . '</svg>';
    }

}
