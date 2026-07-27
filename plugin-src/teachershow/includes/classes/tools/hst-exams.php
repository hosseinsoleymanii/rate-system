<?php

defined('ABSPATH') || exit;

class HST_Exams
{
    private const GENERAL_SETTINGS_OPTION = 'hst-exam-general-settings';

    private const DAYS = [
        'saturday'  => 'شنبه',
        'sunday'    => 'یکشنبه',
        'monday'    => 'دوشنبه',
        'tuesday'   => 'سه‌شنبه',
        'wednesday' => 'چهارشنبه',
        'thursday'  => 'پنجشنبه',
        'friday'    => 'جمعه',
    ];

    public function __construct()
    {
        add_action('wp_ajax_hst_exams_validate_date', [$this, 'ajax_validate_date']);
        add_action('wp_ajax_hst_exams_save', [$this, 'ajax_save_exam']);
        add_action('wp_ajax_hst_exams_create_builder', [$this, 'ajax_create_builder_exam']);
        add_action('wp_ajax_hst_exams_delete', [$this, 'ajax_delete_exam']);
        add_action('wp_ajax_hst_exams_save_general_settings', [$this, 'ajax_save_general_settings']);
        add_action('wp_ajax_hst_exams_student_start', [$this, 'ajax_student_start']);
        add_action('wp_ajax_hst_exams_student_save', [$this, 'ajax_student_save']);
        add_action('wp_ajax_hst_exams_student_track', [$this, 'ajax_student_track']);
        add_action('wp_ajax_hst_exams_student_submit', [$this, 'ajax_student_submit']);
    }

    public static function general_settings_defaults(): array
    {
        return [
            'negative_marking'  => 0,
            'instant_results'   => 0,
            'strict_time_limit' => 0,
            'auto_grading'      => 0,
            'max_attempts'      => 1,
        ];
    }

    public static function class_academic_profile($class_name): array
    {
        if (class_exists('HST_Classes')) {
            $profile = HST_Classes::academic_profile($class_name);
            return [
                'grade' => (string) ($profile['grade'] ?? ''),
                'major' => (string) ($profile['major'] ?? ''),
            ];
        }

        return ['grade' => '', 'major' => ''];
    }

    public static function general_settings(): array
    {
        $defaults = self::general_settings_defaults();
        $saved = get_option(self::GENERAL_SETTINGS_OPTION, []);
        if (!is_array($saved)) {
            return $defaults;
        }

        foreach (['negative_marking', 'instant_results', 'strict_time_limit', 'auto_grading'] as $key) {
            $defaults[$key] = !empty($saved[$key]) ? 1 : 0;
        }
        $defaults['max_attempts'] = max(1, min(10, absint($saved['max_attempts'] ?? 1)));

        return $defaults;
    }

    public function ajax_save_general_settings(): void
    {
        if (class_exists('HST_Guard')) {
            HST_Guard::verify_ajax('hst_manage_school');
        } else {
            check_ajax_referer('hst_nonce', 'nonce');
            if (!current_user_can('manage_options') && !current_user_can('hst_manage_school')) {
                wp_send_json_error(['message' => 'اجازه تغییر تنظیمات آزمون را ندارید.'], 403);
            }
        }

        $settings = self::general_settings_defaults();
        foreach (['negative_marking', 'instant_results', 'strict_time_limit', 'auto_grading'] as $key) {
            $settings[$key] = !empty($_POST[$key]) ? 1 : 0;
        }
        if (empty($settings['auto_grading']) && (!empty($settings['negative_marking']) || !empty($settings['instant_results']))) {
            wp_send_json_error([
                'message' => 'برای نمره منفی یا نمایش فوری نتیجه، ابتدا تصحیح خودکار را فعال کنید.',
            ], 422);
        }
        $max_attempts = absint(wp_unslash($_POST['max_attempts'] ?? 0));
        if ($max_attempts < 1 || $max_attempts > 10) {
            wp_send_json_error(['message' => 'حداکثر دفعات تلاش باید بین ۱ تا ۱۰ باشد.'], 422);
        }
        $settings['max_attempts'] = $max_attempts;

        update_option(self::GENERAL_SETTINGS_OPTION, $settings, false);
        $stored_settings = self::general_settings();
        if ($stored_settings !== $settings) {
            wp_send_json_error(['message' => 'ذخیره تنظیمات آزمون کامل نشد؛ دوباره تلاش کنید.'], 500);
        }

        wp_send_json_success([
            'message'  => 'تنظیمات عمومی آزمون با موفقیت ذخیره شد.',
            'settings' => $stored_settings,
        ]);
    }

    private function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_exams';
    }

    private function attempts_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_exam_attempts';
    }

    private function questions_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_exam_questions';
    }

    private function question_items_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_exam_question_items';
    }

    private function require_student_ajax(): int
    {
        check_ajax_referer('hst_nonce', 'nonce');
        $user = wp_get_current_user();
        if (!$user || !$user->ID || (!current_user_can('hst_study') && !in_array('student', (array) $user->roles, true))) {
            wp_send_json_error(['message' => 'این عملیات فقط برای دانش‌آموز در دسترس است.'], 403);
        }
        return (int) $user->ID;
    }

    private function client_ip_hash(): string
    {
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }

    private function student_exam(int $exam_id, int $student_id)
    {
        global $wpdb;
        if ($exam_id < 1 || $student_id < 1) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, c.class_name, l.lesson_name, u.display_name AS teacher_name
             FROM {$this->table()} e
             INNER JOIN {$wpdb->prefix}hst_users_classes uc
                     ON uc.class_id = e.class_id
                    AND uc.term_id = e.term_id
                    AND uc.user_id = %d
                    AND uc.role = 'student'
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = e.class_id
             INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = e.lesson_id
             INNER JOIN {$wpdb->users} u ON u.ID = e.teacher_id
             WHERE e.id = %d
               AND e.delivery_mode = 'online'
               AND e.status <> 'cancelled'
             LIMIT 1",
            $student_id,
            $exam_id
        ));
    }

    private function exam_datetime(object $exam, string $edge): ?DateTimeImmutable
    {
        $date_key = $edge === 'start' ? 'start_date' : 'end_date';
        $time_key = $edge === 'start' ? 'start_time' : 'end_time';
        $date = (string) ($exam->{$date_key} ?? $exam->exam_date ?? '');
        $time = substr((string) ($exam->{$time_key} ?? ($edge === 'start' ? '00:00' : '23:59')), 0, 5);
        if ($date === '' || $date === '0000-00-00') {
            return null;
        }
        try {
            return new DateTimeImmutable($date . ' ' . $time, wp_timezone());
        } catch (Exception $exception) {
            return null;
        }
    }

    private function exam_window(object $exam): array
    {
        $now = current_datetime();
        $start = $this->exam_datetime($exam, 'start');
        $end = $this->exam_datetime($exam, 'end');
        if (!$start || !$end) {
            return ['key' => 'invalid', 'label' => 'زمان آزمون نامعتبر است.', 'start' => $start, 'end' => $end];
        }
        if ($now < $start) {
            return ['key' => 'waiting', 'label' => 'زمان شروع آزمون نرسیده است.', 'start' => $start, 'end' => $end];
        }
        if ($now >= $end) {
            return ['key' => 'ended', 'label' => 'زمان برگزاری آزمون پایان یافته است.', 'start' => $start, 'end' => $end];
        }
        return ['key' => 'active', 'label' => 'آزمون در حال برگزاری است.', 'start' => $start, 'end' => $end];
    }

    private function attempt_question_rows(int $exam_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT i.question_id, i.sort_order, i.score AS item_score,
                    q.question_type, q.difficulty, q.question_text, q.answer_data
             FROM {$this->question_items_table()} i
             INNER JOIN {$this->questions_table()} q ON q.id = i.question_id
             WHERE i.exam_id = %d
             ORDER BY i.sort_order ASC, i.id ASC",
            $exam_id
        )) ?: [];
    }

    private function decode_id_list($value, bool $allow_zero = false): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $ids = array_map('absint', $decoded);
        $ids = array_filter($ids, static function (int $id) use ($allow_zero): bool {
            return $allow_zero ? $id >= 0 : $id > 0;
        });
        return array_values(array_unique($ids));
    }

    private function decode_assoc($value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function shuffled_values(array $values): array
    {
        $values = array_values($values);
        if (count($values) > 1) {
            shuffle($values);
        }
        return $values;
    }

    private function create_attempt_payload(object $exam, object $attempt, array $rows): array
    {
        $row_map = [];
        foreach ($rows as $row) {
            $row_map[(int) $row->question_id] = $row;
        }

        $order = $this->decode_id_list($attempt->question_order ?? '');
        if (!$order) {
            $order = array_keys($row_map);
        }
        $option_orders = $this->decode_assoc($attempt->option_orders ?? '');
        $answers = $this->decode_assoc($attempt->answers ?? '');
        $questions = [];

        foreach ($order as $question_id) {
            if (!isset($row_map[$question_id])) {
                continue;
            }
            $row = $row_map[$question_id];
            $answer_data = $this->decode_assoc($row->answer_data ?? '');
            $type = sanitize_key((string) $row->question_type);
            $question = [
                'id'            => $question_id,
                'number'        => count($questions) + 1,
                'type'          => $type,
                'difficulty'    => sanitize_key((string) $row->difficulty),
                'score'         => (float) $row->item_score,
                'questionText'  => (string) $row->question_text,
                'choices'       => [],
                'blankCount'    => 0,
                'answer'        => $answers[(string) $question_id] ?? null,
            ];

            if ($type === 'multiple_choice') {
                $choices = array_values(array_map('strval', array_slice((array) ($answer_data['choices'] ?? []), 0, 4)));
                // Choice index zero is valid and must survive decoding.
                $choice_order = $this->decode_id_list($option_orders[(string) $question_id] ?? [], true);
                if (count($choice_order) !== count($choices)) {
                    $choice_order = array_keys($choices);
                }
                foreach ($choice_order as $choice_index) {
                    if (array_key_exists($choice_index, $choices)) {
                        $question['choices'][] = [
                            'key'  => (string) $choice_index,
                            'text' => $choices[$choice_index],
                        ];
                    }
                }
            } elseif ($type === 'fill_blank') {
                $question['blankCount'] = max(1, count((array) ($answer_data['answers'] ?? [])));
            }

            $questions[] = $question;
        }

        $started_at = new DateTimeImmutable((string) $attempt->started_at, wp_timezone());
        $duration_end = $started_at->modify('+' . max(1, (int) $exam->duration_minutes) . ' minutes');
        $window_end = $this->exam_datetime($exam, 'end');
        $effective_end = $window_end && $window_end < $duration_end ? $window_end : $duration_end;
        $remaining = max(0, $effective_end->getTimestamp() - current_datetime()->getTimestamp());

        return [
            'exam' => [
                'id'                => (int) $exam->id,
                'title'             => (string) $exam->title,
                'lessonName'        => (string) $exam->lesson_name,
                'className'         => (string) $exam->class_name,
                'duration'          => (int) $exam->duration_minutes,
                'attemptLimit'      => (int) $exam->attempt_limit,
                'attemptNumber'     => (int) $attempt->attempt_number,
                'resultVisibility'  => (string) $exam->result_visibility,
                'recordExitTime'    => (int) $exam->record_exit_time,
                'ipRestriction'     => (int) $exam->ip_restriction,
                'strictTimeLimit'   => !empty(self::general_settings()['strict_time_limit']) ? 1 : 0,
            ],
            'attemptId'       => (int) $attempt->id,
            'remainingSeconds'=> $remaining,
            'questions'       => $questions,
        ];
    }

    private function enforce_attempt_ip(object $exam, object $attempt): void
    {
        if (empty($exam->ip_restriction)) {
            return;
        }
        $current_hash = $this->client_ip_hash();
        if (!hash_equals((string) $attempt->ip_hash, $current_hash)) {
            wp_send_json_error(['message' => 'ادامه این آزمون فقط از همان اتصال اینترنتی آغازشده امکان‌پذیر است.'], 403);
        }
    }

    private function posted_answers(): array
    {
        $raw = json_decode((string) wp_unslash($_POST['answers'] ?? ''), true);
        return is_array($raw) ? $raw : [];
    }

    private function clean_student_answer($value, string $type)
    {
        if ($type === 'multiple_choice') {
            return is_numeric($value) ? max(0, min(3, (int) $value)) : null;
        }
        if ($type === 'true_false') {
            return in_array((string) $value, ['true', 'false'], true) ? (string) $value : '';
        }
        if ($type === 'fill_blank') {
            return array_values(array_map(static function ($item) {
                return mb_substr(sanitize_text_field((string) $item), 0, 500);
            }, array_slice(is_array($value) ? $value : [], 0, 20)));
        }
        return mb_substr(sanitize_textarea_field((string) $value), 0, 5000);
    }

    private function sanitize_attempt_answers(array $posted, array $rows): array
    {
        $types = [];
        foreach ($rows as $row) {
            $types[(string) ((int) $row->question_id)] = sanitize_key((string) $row->question_type);
        }
        $answers = [];
        foreach ($posted as $question_id => $value) {
            $key = (string) absint($question_id);
            if ($key === '0' || !isset($types[$key])) {
                continue;
            }
            $answers[$key] = $this->clean_student_answer($value, $types[$key]);
        }
        return $answers;
    }

    private function normalized_answer_text($value): string
    {
        $text = strtr((string) $value, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه']);
        $text = preg_replace('/[\x{200c}\x{200e}\x{200f}]/u', ' ', $text);
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $text)), 'UTF-8');
    }

    private function grade_attempt(array $rows, array $answers): array
    {
        $settings = self::general_settings();
        $earned = 0.0;
        $max_score = 0.0;
        $manual_pending = 0;

        foreach ($rows as $row) {
            $question_id = (string) ((int) $row->question_id);
            $type = sanitize_key((string) $row->question_type);
            $score = max(0.0, (float) $row->item_score);
            $max_score += $score;
            if ($type === 'essay') {
                $manual_pending++;
                continue;
            }

            $answer_data = $this->decode_assoc($row->answer_data ?? '');
            $answer = $answers[$question_id] ?? null;
            $answered = false;
            $correct = false;
            if ($type === 'multiple_choice') {
                $answered = is_numeric($answer);
                $correct = $answered && (int) $answer === (int) ($answer_data['correct_index'] ?? -1);
            } elseif ($type === 'true_false') {
                $answered = in_array((string) $answer, ['true', 'false'], true);
                $correct = $answered && (string) $answer === (string) ($answer_data['correct'] ?? '');
            } elseif ($type === 'fill_blank') {
                $expected = array_values((array) ($answer_data['answers'] ?? []));
                $actual = is_array($answer) ? array_values($answer) : [];
                $answered = $actual && count(array_filter($actual, static fn($item) => trim((string) $item) !== '')) === count($actual);
                $correct = $answered && count($expected) === count($actual);
                if ($correct) {
                    foreach ($expected as $index => $value) {
                        if ($this->normalized_answer_text($value) !== $this->normalized_answer_text($actual[$index] ?? '')) {
                            $correct = false;
                            break;
                        }
                    }
                }
            } else {
                $answered = trim((string) $answer) !== '';
                $correct = $answered
                    && $this->normalized_answer_text($answer) === $this->normalized_answer_text($answer_data['answer'] ?? '');
            }

            if ($correct) {
                $earned += $score;
            } elseif ($answered && $type === 'multiple_choice' && !empty($settings['negative_marking'])) {
                $earned -= $score / 3;
            }
        }

        return [
            'score'          => max(0.0, round($earned, 2)),
            'max_score'      => round($max_score, 2),
            'manual_pending' => $manual_pending,
        ];
    }

    private function result_is_visible(object $exam): bool
    {
        $mode = (string) ($exam->result_visibility ?? 'after_end');
        if ($mode === 'after_submit') {
            return true;
        }
        if ($mode !== 'after_end') {
            return false;
        }
        $end = $this->exam_datetime($exam, 'end');
        return $end && current_datetime() >= $end;
    }

    public function active_term()
    {
        return HST_Terms::active();
    }

    private function current_user_role()
    {
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return '';
        }
        if (current_user_can('manage_options') || current_user_can('hst_manage_school')) {
            return 'manager';
        }
        if (current_user_can('hst_teach') || in_array('teacher', (array) $user->roles, true)) {
            return 'teacher';
        }
        if (current_user_can('hst_study') || in_array('student', (array) $user->roles, true)) {
            return 'student';
        }
        return '';
    }

    private function require_teacher_ajax()
    {
        if (class_exists('HST_Guard')) {
            check_ajax_referer('hst_nonce', 'nonce');
        } else {
            check_ajax_referer('hst_nonce', 'nonce');
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز'], 403);
        }

        // Only teachers may create/modify exams. A school manager belongs to no
        // class or lesson, so cannot define exams (they only view reports).
        $role = $this->current_user_role();
        if ($role !== 'teacher') {
            wp_send_json_error(['message' => 'فقط معلمان می‌توانند آزمون ثبت یا ویرایش کنند.'], 403);
        }
    }

    private function post_text($key, $default = '', $limit = 191)
    {
        $value = class_exists('HST_Guard') ? HST_Guard::post_text($key, $default) : sanitize_text_field(wp_unslash($_POST[$key] ?? $default));
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    private function post_textarea($key, $default = '', $limit = 1200)
    {
        $value = class_exists('HST_Guard') ? HST_Guard::post_textarea($key, $default) : sanitize_textarea_field(wp_unslash($_POST[$key] ?? $default));
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    private function post_int($key, $default = 0)
    {
        return class_exists('HST_Guard') ? HST_Guard::post_int($key, $default) : absint(wp_unslash($_POST[$key] ?? $default));
    }

    private function post_bool($key)
    {
        return !empty($_POST[$key]) ? 1 : 0;
    }

    private function normalize_time($value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            return '';
        }
        return $value . ':00';
    }

    private function require_exam_builder_ajax()
    {
        check_ajax_referer('hst_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز'], 403);
        }

        if (!current_user_can('manage_options') && !current_user_can('hst_manage_school') && !current_user_can('hst_teach')) {
            wp_send_json_error(['message' => 'اجازه ایجاد آزمون را ندارید.'], 403);
        }
    }

    private function require_exam_manage_ajax()
    {
        check_ajax_referer('hst_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز'], 403);
        }

        if (!current_user_can('manage_options') && !current_user_can('hst_manage_school') && !current_user_can('hst_teach')) {
            wp_send_json_error(['message' => 'اجازه مدیریت آزمون را ندارید.'], 403);
        }
    }

    private function normalize_exam_date($date_raw)
    {
        $date_raw = trim((string) $date_raw);
        if ($date_raw === '') {
            return '';
        }

        $date = class_exists('HST_Date') ? HST_Date::to_gregorian_date($date_raw) : $date_raw;
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));
        return checkdate($month, $day, $year) ? $date : '';
    }

    private function lesson_belongs_to_class($class_id, $lesson_id)
    {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}hst_lessons WHERE id = %d AND class_id = %d LIMIT 1",
                $lesson_id,
                $class_id
            )
        );
    }

    private function class_exists_in_term($class_id, $term_id)
    {
        global $wpdb;

        if (current_user_can('manage_options') || current_user_can('hst_manage_school')) {
            return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}hst_classes WHERE id = %d LIMIT 1", $class_id));
        }

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}hst_users_classes WHERE user_id = %d AND class_id = %d AND term_id = %d AND role = 'teacher' LIMIT 1",
                get_current_user_id(),
                $class_id,
                $term_id
            )
        );
    }

    private function class_matches_academic_selection($class_id, $grade, $major): bool
    {
        global $wpdb;
        $class_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT class_name FROM {$wpdb->prefix}hst_classes WHERE id = %d LIMIT 1",
                $class_id
            )
        );
        if (!is_string($class_name) || $class_name === '') {
            return false;
        }

        $profile = self::class_academic_profile($class_name);
        return $profile['grade'] === $grade && $profile['major'] === $major;
    }

    public static function day_key_from_date($date)
    {
        $ts = strtotime((string) $date);
        if (!$ts) {
            return '';
        }
        $map = [
            6 => 'saturday',
            0 => 'sunday',
            1 => 'monday',
            2 => 'tuesday',
            3 => 'wednesday',
            4 => 'thursday',
            5 => 'friday',
        ];
        return $map[(int) gmdate('w', $ts)] ?? '';
    }

    private function day_label($key)
    {
        return self::DAYS[$key] ?? $key;
    }

    public function teacher_scope($teacher_id = 0, $term_id = 0)
    {
        global $wpdb;
        $teacher_id = $teacher_id ?: get_current_user_id();
        $term_id = $term_id ?: (int) ($this->active_term()->id ?? 0);

        if (!$teacher_id || !$term_id) {
            return ['classes' => [], 'lessons' => []];
        }

        if (current_user_can('manage_options') || current_user_can('hst_manage_school')) {
            $classes = HST_Classes::all_by_name();
            $lessons = $wpdb->get_results(
                "SELECT l.id, l.lesson_name, l.class_id, c.class_name
                 FROM {$wpdb->prefix}hst_lessons l
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = l.class_id
                 ORDER BY c.class_name ASC, l.lesson_name ASC"
            ) ?: [];
            $lessons = HST_Classes::sort_rows($lessons, 'class_name', ['lesson_name']);
            return ['classes' => $classes, 'lessons' => $lessons];
        }

        $classes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT c.id, c.class_name
                 FROM {$wpdb->prefix}hst_users_classes uc
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = uc.class_id
                 WHERE uc.user_id = %d AND uc.term_id = %d AND uc.role = 'teacher'
                 ORDER BY c.class_name ASC",
                $teacher_id,
                $term_id
            )
        ) ?: [];

        $lessons = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT l.id, l.lesson_name, l.class_id, c.class_name
                 FROM {$wpdb->prefix}hst_users_lessons ul
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = ul.lesson_id
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = l.class_id
                 WHERE ul.user_id = %d AND ul.term_id = %d AND ul.role = 'teacher'
                 ORDER BY c.class_name ASC, l.lesson_name ASC",
                $teacher_id,
                $term_id
            )
        ) ?: [];

        $classes = HST_Classes::sort_rows($classes);
        $lessons = HST_Classes::sort_rows($lessons, 'class_name', ['lesson_name']);

        return ['classes' => $classes, 'lessons' => $lessons];
    }

    private function lesson_allowed_for_teacher($teacher_id, $term_id, $class_id, $lesson_id)
    {
        if (current_user_can('manage_options') || current_user_can('hst_manage_school')) {
            return true;
        }

        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1
                 FROM {$wpdb->prefix}hst_users_lessons ul
                 WHERE ul.user_id = %d
                    AND ul.term_id = %d
                    AND ul.class_id = %d
                    AND ul.lesson_id = %d
                    AND ul.role = 'teacher'
                 LIMIT 1",
                $teacher_id,
                $term_id,
                $class_id,
                $lesson_id
            )
        );
    }

    private function scheduled_shifts_for_teacher_lesson($teacher_id, $term_id, $class_id, $lesson_id, $day_key)
    {
        global $wpdb;

        $teacher_filter = (current_user_can('manage_options') || current_user_can('hst_manage_school')) ? '' : $wpdb->prepare(' AND teacher_id = %d ', $teacher_id);

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT school_shift, week_type
                 FROM {$wpdb->prefix}hst_schedules
                 WHERE term_id = %d
                    AND class_id = %d
                    AND lesson_id = %d
                    AND day_of_week = %s
                    {$teacher_filter}
                 ORDER BY school_shift ASC",
                $term_id,
                $class_id,
                $lesson_id,
                $day_key
            )
        ) ?: [];
    }

    private function exam_conflicts($term_id, $class_id, $exam_date, $exclude_id = 0)
    {
        global $wpdb;
        $table = $this->table();

        $exclude = $exclude_id ? $wpdb->prepare(' AND e.id != %d ', $exclude_id) : '';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.*, l.lesson_name, u.display_name AS teacher_name
                 FROM {$table} e
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = e.lesson_id
                 INNER JOIN {$wpdb->users} u ON u.ID = e.teacher_id
                 WHERE e.term_id = %d
                    AND e.class_id = %d
                    AND e.exam_date = %s
                    AND e.status != 'cancelled'
                    {$exclude}
                 ORDER BY e.school_shift ASC",
                $term_id,
                $class_id,
                $exam_date
            )
        ) ?: [];
    }

    public function validate_exam_slot($teacher_id, $term_id, $class_id, $lesson_id, $exam_date, $school_shift = 0, $exclude_id = 0)
    {
        $errors = [];
        $warnings = [];
        $allowed_shifts = [];

        if ($exam_date < current_time('Y-m-d')) {
            $errors[] = 'تاریخ آزمون نمی‌تواند قبل از تاریخ امروز باشد.';
            return compact('errors', 'warnings', 'allowed_shifts');
        }

        $day_key = self::day_key_from_date($exam_date);
        if (!$day_key || !isset(self::DAYS[$day_key])) {
            $errors[] = 'تاریخ انتخاب‌شده در بازه آموزشی شنبه تا چهارشنبه نیست.';
            return compact('errors', 'warnings', 'allowed_shifts', 'day_key');
        }

        if (!$this->class_exists_in_term($class_id, $term_id)) {
            $errors[] = 'کلاس انتخاب‌شده در سال تحصیلی فعال معتبر نیست.';
        }

        if (!$this->lesson_belongs_to_class($class_id, $lesson_id)) {
            $errors[] = 'درس انتخاب‌شده با کلاس انتخاب‌شده هماهنگ نیست.';
        }

        if (!$this->lesson_allowed_for_teacher($teacher_id, $term_id, $class_id, $lesson_id)) {
            $errors[] = 'این کلاس/درس در محدوده تدریس شما در سال تحصیلی فعال نیست.';
        }

        $scheduled = $this->scheduled_shifts_for_teacher_lesson($teacher_id, $term_id, $class_id, $lesson_id, $day_key);
        foreach ($scheduled as $row) {
            $allowed_shifts[(int) $row->school_shift] = [
                'shift' => (int) $row->school_shift,
                'label' => 'زنگ ' . (int) $row->school_shift . ($row->week_type !== 'every' ? ' - هفته ' . ($row->week_type === 'odd' ? 'فرد' : 'زوج') : ''),
                'week_type' => $row->week_type,
            ];
        }

        if (empty($allowed_shifts)) {
            $errors[] = 'طبق برنامه هفتگی، این معلم/درس/کلاس در این روز زنگی ندارد؛ لطفاً تاریخی انتخاب کنید که با برنامه هفتگی هماهنگ باشد.';
        } elseif ($school_shift && !isset($allowed_shifts[(int) $school_shift])) {
            $errors[] = 'زنگ انتخاب‌شده با برنامه هفتگی این درس هماهنگ نیست.';
        }

        $conflicts = $this->exam_conflicts($term_id, $class_id, $exam_date, $exclude_id);
        if ($conflicts) {
            $names = [];
            foreach ($conflicts as $conflict) {
                $names[] = sprintf('%s با %s در زنگ %d', $conflict->lesson_name, $conflict->teacher_name, (int) $conflict->school_shift);
            }
            $warnings[] = 'در همین روز برای این کلاس آزمون ثبت شده است: ' . implode('، ', $names) . '. ممکن است به دانش‌آموزان فشار بیاید.';
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'allowed_shifts' => array_values($allowed_shifts),
            'day_key' => $day_key,
            'day_label' => $this->day_label($day_key),
            'conflicts' => $conflicts,
        ];
    }

    public function ajax_validate_date()
    {
        $this->require_teacher_ajax();

        $term = $this->active_term();
        if (!$term) {
            wp_send_json_error(['message' => 'سال تحصیلی فعالی یافت نشد.']);
        }

        $teacher_id = get_current_user_id();
        $class_id = $this->post_int('class_id');
        $lesson_id = $this->post_int('lesson_id');
        $exam_date = $this->normalize_exam_date($this->post_text('exam_date', '', 32));

        if (!$class_id || !$lesson_id || !$exam_date) {
            wp_send_json_error(['message' => 'کلاس، درس و تاریخ آزمون الزامی است.']);
        }

        $result = $this->validate_exam_slot($teacher_id, (int) $term->id, $class_id, $lesson_id, $exam_date);

        wp_send_json_success([
            'is_valid' => empty($result['errors']),
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'allowed_shifts' => $result['allowed_shifts'],
            'day_label' => $result['day_label'] ?? '',
            'exam_date' => $exam_date,
        ]);
    }

    public function ajax_save_exam()
    {
        $this->require_teacher_ajax();

        $term = $this->active_term();
        if (!$term) {
            wp_send_json_error(['message' => 'سال تحصیلی فعالی یافت نشد.']);
        }

        $teacher_id = get_current_user_id();
        $id = $this->post_int('id');
        $class_id = $this->post_int('class_id');
        $lesson_id = $this->post_int('lesson_id');
        $school_shift = $this->post_int('school_shift');
        $title = $this->post_text('title', '', 120);
        $description = $this->post_textarea('description', '', 1200);
        $exam_date = $this->normalize_exam_date($this->post_text('exam_date', '', 32));
        $duration_minutes = max(15, min(240, $this->post_int('duration_minutes', 45)));
        $location = $this->post_text('location', '', 120);
        $status = sanitize_key(wp_unslash($_POST['status'] ?? 'scheduled'));
        if (!in_array($status, ['scheduled', 'done', 'cancelled'], true)) {
            $status = 'scheduled';
        }

        if (!$class_id || !$lesson_id || !$school_shift || !$title || !$exam_date) {
            wp_send_json_error(['message' => 'عنوان، کلاس، درس، تاریخ و زنگ آزمون الزامی است.']);
        }

        $existing_owner = 0;
        if ($id) {
            global $wpdb;
            $table = $this->table();
            $existing_owner = (int) $wpdb->get_var($wpdb->prepare("SELECT teacher_id FROM {$table} WHERE id = %d", $id));
            if (!$existing_owner || ($existing_owner !== $teacher_id && !current_user_can('manage_options') && !current_user_can('hst_manage_school'))) {
                wp_send_json_error(['message' => 'اجازه ویرایش این آزمون را ندارید.'], 403);
            }
        }

        $result = $this->validate_exam_slot($teacher_id, (int) $term->id, $class_id, $lesson_id, $exam_date, $school_shift, $id);
        if (!empty($result['errors'])) {
            wp_send_json_error(['message' => implode(' ', $result['errors']), 'warnings' => $result['warnings']]);
        }

        global $wpdb;
        $data = [
            'term_id' => (int) $term->id,
            'class_id' => $class_id,
            'lesson_id' => $lesson_id,
            'teacher_id' => $id && $existing_owner ? $existing_owner : $teacher_id,
            'title' => $title,
            'description' => $description,
            'exam_date' => $exam_date,
            'day_of_week' => $result['day_key'],
            'school_shift' => $school_shift,
            'duration_minutes' => $duration_minutes,
            'location' => $location,
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ];
        $format = ['%d','%d','%d','%d','%s','%s','%s','%s','%d','%d','%s','%s','%s'];

        if ($id) {
            $ok = $wpdb->update($this->table(), $data, ['id' => $id], $format, ['%d']);
            $is_new_exam = false;
        } else {
            unset($data['updated_at']);
            array_pop($format);
            $ok = $wpdb->insert($this->table(), $data, $format);
            $id = (int) $wpdb->insert_id;
            $is_new_exam = true;
        }

        if ($ok === false) {
            wp_send_json_error(['message' => 'ذخیره آزمون انجام نشد.']);
        }

        if (!empty($is_new_exam) && $status === 'scheduled') {
            do_action('hst_exam_created', [
                'exam_id'    => $id,
                'class_id'   => $class_id,
                'title'      => $title,
                'teacher_id' => $teacher_id,
            ]);
        }

        wp_send_json_success([
            'message' => 'آزمون با موفقیت ذخیره شد.',
            'id' => $id,
            'warnings' => $result['warnings'],
        ]);
    }

    public function ajax_create_builder_exam()
    {
        $this->require_exam_builder_ajax();

        $term = $this->active_term();
        if (!$term) {
            wp_send_json_error(['message' => 'سال تحصیلی فعالی یافت نشد.']);
        }

        $id = $this->post_int('id');
        $title = $this->post_text('title', '', 120);
        $grade = $this->post_text('grade', '', 64);
        $major = $this->post_text('major', '', 120);
        $class_id = $this->post_int('class_id');
        $lesson_id = $this->post_int('lesson_id');
        $exam_type = sanitize_key(wp_unslash($_POST['exam_type'] ?? ''));
        $delivery_mode = sanitize_key(wp_unslash($_POST['delivery_mode'] ?? ''));
        $duration_minutes = $this->post_int('duration_minutes', 90);
        $question_count = $this->post_int('question_count', 20);
        $start_date = $this->normalize_exam_date($this->post_text('start_date', '', 32));
        $end_date = $this->normalize_exam_date($this->post_text('end_date', '', 32));
        $start_time = $this->normalize_time($this->post_text('start_time', '', 8));
        $end_time = $this->normalize_time($this->post_text('end_time', '', 8));
        $general_settings = self::general_settings();
        $attempt_limit = $this->post_int('attempt_limit', (int) $general_settings['max_attempts']);
        $default_result_visibility = !empty($general_settings['instant_results']) ? 'after_submit' : 'after_end';
        $result_visibility = sanitize_key(wp_unslash($_POST['result_visibility'] ?? $default_result_visibility));

        $grades = ['tenth', 'eleventh', 'twelfth'];
        $majors = ['experimental', 'math', 'humanities'];
        $exam_types = ['continuous', 'midterm', 'final_first', 'final_second', 'quiz'];
        $delivery_modes = ['in_person', 'online'];
        $result_modes = ['after_submit', 'after_end'];

        if ($title === '' || $grade === '' || $major === '' || !$class_id || !$lesson_id) {
            wp_send_json_error(['message' => 'اطلاعات شناسنامه آزمون را کامل کنید.']);
        }
        if (!in_array($grade, $grades, true) || !in_array($major, $majors, true)) {
            wp_send_json_error(['message' => 'پایه یا رشته تحصیلی معتبر نیست.']);
        }
        if (!in_array($exam_type, $exam_types, true) || !in_array($delivery_mode, $delivery_modes, true)) {
            wp_send_json_error(['message' => 'نوع یا شیوه آزمون معتبر نیست.']);
        }
        if (!$this->lesson_belongs_to_class($class_id, $lesson_id)) {
            wp_send_json_error(['message' => 'درس انتخاب‌شده به کلاس موردنظر تعلق ندارد.']);
        }
        if (!$this->class_exists_in_term($class_id, (int) $term->id)) {
            wp_send_json_error(['message' => 'کلاس انتخاب‌شده در دسترس نیست.'], 403);
        }
        if (!$this->class_matches_academic_selection($class_id, $grade, $major)) {
            wp_send_json_error(['message' => 'کلاس انتخاب‌شده با پایه و رشته تحصیلی مطابقت ندارد.']);
        }
        if (!current_user_can('manage_options') && !current_user_can('hst_manage_school')
            && !$this->lesson_allowed_for_teacher(get_current_user_id(), (int) $term->id, $class_id, $lesson_id)) {
            wp_send_json_error(['message' => 'درس انتخاب‌شده در محدوده تدریس شما نیست.'], 403);
        }
        if ($duration_minutes < 1 || $duration_minutes > 600 || $question_count < 1 || $question_count > 500) {
            wp_send_json_error(['message' => 'مدت آزمون یا تعداد سؤالات معتبر نیست.']);
        }
        if (!$start_date || !$end_date || !$start_time || !$end_time) {
            wp_send_json_error(['message' => 'تاریخ و ساعت شروع و پایان آزمون را کامل کنید.']);
        }
        $today = current_time('Y-m-d');
        if ($start_date < $today || $end_date < $today) {
            wp_send_json_error(['message' => 'تاریخ شروع و پایان آزمون نمی‌تواند قبل از تاریخ امروز باشد.']);
        }
        if ($attempt_limit < 1 || $attempt_limit > 10) {
            wp_send_json_error(['message' => 'تعداد دفعات مجاز شرکت باید بین ۱ تا ۱۰ باشد.']);
        }
        if ($delivery_mode === 'online' && !in_array($result_visibility, $result_modes, true)) {
            wp_send_json_error(['message' => 'زمان نمایش نتیجه آزمون معتبر نیست.'], 422);
        }
        if ($delivery_mode === 'online'
            && $result_visibility === 'after_submit'
            && empty($general_settings['auto_grading'])) {
            wp_send_json_error([
                'message' => 'برای نمایش نتیجه بلافاصله پس از ثبت پاسخ، تصحیح خودکار را در تنظیمات آزمون فعال کنید.',
            ], 422);
        }

        try {
            $timezone = wp_timezone();
            $start_at = new DateTimeImmutable($start_date . ' ' . $start_time, $timezone);
            $end_at = new DateTimeImmutable($end_date . ' ' . $end_time, $timezone);
        } catch (Exception $exception) {
            wp_send_json_error(['message' => 'تاریخ یا ساعت آزمون معتبر نیست.']);
        }
        $now = current_datetime();
        $now = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);
        if (!$id && $start_at < $now) {
            wp_send_json_error(['message' => 'زمان شروع آزمون نمی‌تواند قبل از زمان فعلی باشد.']);
        }
        if ($end_at <= $start_at) {
            wp_send_json_error(['message' => 'زمان پایان آزمون باید بعد از زمان شروع باشد.']);
        }

        $day_key = self::day_key_from_date($start_date);
        if ($day_key === '') {
            wp_send_json_error(['message' => 'تاریخ شروع آزمون معتبر نیست.']);
        }

        global $wpdb;
        $table = $this->table();
        $existing = null;
        if ($id) {
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, teacher_id, term_id, status FROM {$table} WHERE id = %d LIMIT 1",
                    $id
                )
            );
            if (!$existing || (int) $existing->term_id !== (int) $term->id) {
                wp_send_json_error(['message' => 'آزمون موردنظر پیدا نشد.'], 404);
            }
            if ((int) $existing->teacher_id !== get_current_user_id()
                && !current_user_can('manage_options')
                && !current_user_can('hst_manage_school')) {
                wp_send_json_error(['message' => 'اجازه ویرایش این آزمون را ندارید.'], 403);
            }
            $attempt_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->attempts_table()} WHERE exam_id = %d",
                    $id
                )
            );
            if ($attempt_count > 0) {
                wp_send_json_error([
                    'message' => 'پس از شروع شرکت دانش‌آموزان، تنظیمات آزمون قابل تغییر نیست.',
                ], 409);
            }
        }

        $owner_id = $existing ? (int) $existing->teacher_id : get_current_user_id();
        $is_online_exam = $delivery_mode === 'online';
        if (!$is_online_exam) {
            $attempt_limit = 1;
            $result_visibility = 'manual';
        }
        $data = [
            'term_id' => (int) $term->id,
            'class_id' => $class_id,
            'lesson_id' => $lesson_id,
            'teacher_id' => $owner_id,
            'title' => $title,
            'description' => '',
            'exam_date' => $start_date,
            'day_of_week' => $day_key,
            'school_shift' => 1,
            'duration_minutes' => $duration_minutes,
            'location' => '',
            'grade' => $grade,
            'major' => $major,
            'exam_type' => $exam_type,
            'delivery_mode' => $delivery_mode,
            'question_count' => $question_count,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'attempt_limit' => $attempt_limit,
            'result_visibility' => $result_visibility,
            'randomize_questions' => $is_online_exam ? $this->post_bool('randomize_questions') : 0,
            'randomize_options' => $is_online_exam ? $this->post_bool('randomize_options') : 0,
            'record_exit_time' => $is_online_exam ? $this->post_bool('record_exit_time') : 0,
            'ip_restriction' => $is_online_exam ? $this->post_bool('ip_restriction') : 0,
            'ai_review' => 0,
            'status' => $existing ? (string) $existing->status : 'scheduled',
        ];
        $format = [
            '%d','%d','%d','%d','%s','%s','%s','%s','%d','%d','%s',
            '%s','%s','%s','%s','%d','%s','%s','%s','%s','%d','%s',
            '%d','%d','%d','%d','%d','%s'
        ];

        if ($existing) {
            $data['updated_at'] = current_time('mysql');
            $format[] = '%s';
            $saved = $wpdb->update($this->table(), $data, ['id' => $id], $format, ['%d']);
            $exam_id = $id;
        } else {
            $saved = $wpdb->insert($this->table(), $data, $format);
            $exam_id = (int) $wpdb->insert_id;
        }

        if ($saved === false) {
            wp_send_json_error(['message' => $existing ? 'ویرایش آزمون انجام نشد.' : 'ثبت آزمون انجام نشد.']);
        }

        if (!$existing) {
            do_action('hst_exam_created', [
                'exam_id' => $exam_id,
                'class_id' => $class_id,
                'title' => $title,
                'teacher_id' => $owner_id,
            ]);
        }

        wp_send_json_success([
            'message' => $existing ? 'آزمون با موفقیت ویرایش شد.' : 'آزمون با موفقیت ایجاد شد.',
            'id' => $exam_id,
            'updated' => (bool) $existing,
        ]);
    }

    public function ajax_delete_exam()
    {
        $this->require_exam_manage_ajax();
        $id = $this->post_int('id');
        if (!$id) {
            wp_send_json_error(['message' => 'شناسه آزمون نامعتبر است.']);
        }

        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT teacher_id FROM {$table} WHERE id = %d", $id));
        if (!$row || ((int) $row->teacher_id !== get_current_user_id() && !current_user_can('manage_options') && !current_user_can('hst_manage_school'))) {
            wp_send_json_error(['message' => 'اجازه حذف این آزمون را ندارید.'], 403);
        }

        $wpdb->query('START TRANSACTION');
        $attempts_deleted = $wpdb->delete($this->attempts_table(), ['exam_id' => $id], ['%d']);
        $items_deleted = $wpdb->delete($this->question_items_table(), ['exam_id' => $id], ['%d']);
        $deleted = $wpdb->delete($this->table(), ['id' => $id], ['%d']);
        if (!$deleted) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'حذف آزمون انجام نشد.']);
        }
        if ($attempts_deleted === false || $items_deleted === false) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'پاک‌سازی اطلاعات وابسته آزمون کامل نشد.']);
        }
        $wpdb->query('COMMIT');
        wp_send_json_success(['message' => 'آزمون حذف شد.']);
    }

        public function ajax_student_start(): void
    {
        global $wpdb;
        $student_id = $this->require_student_ajax();
        $exam_id = $this->post_int('exam_id');
        $exam = $this->student_exam($exam_id, $student_id);
        if (!$exam) {
            wp_send_json_error(['message' => 'آزمون غیرحضوری موردنظر برای شما در دسترس نیست.'], 404);
        }

        $window = $this->exam_window($exam);
        if ($window['key'] !== 'active') {
            wp_send_json_error(['message' => $window['label']], 409);
        }

        $rows = $this->attempt_question_rows($exam_id);
        if (!$rows) {
            wp_send_json_error(['message' => 'هنوز سؤالی برای این آزمون ثبت نشده است.'], 409);
        }

        $attempts_table = $this->attempts_table();
        $attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$attempts_table}
             WHERE exam_id = %d AND student_id = %d AND status = 'in_progress'
             ORDER BY attempt_number DESC LIMIT 1",
            $exam_id,
            $student_id
        ));

        if ($attempt) {
            $this->enforce_attempt_ip($exam, $attempt);
            $payload = $this->create_attempt_payload($exam, $attempt, $rows);
            if (!empty(self::general_settings()['strict_time_limit']) && (int) $payload['remainingSeconds'] < 1) {
                $wpdb->update(
                    $attempts_table,
                    ['status' => 'expired', 'last_activity_at' => current_time('mysql')],
                    ['id' => (int) $attempt->id],
                    ['%s', '%s'],
                    ['%d']
                );
                $attempt = null;
            } else {
                wp_send_json_success($payload);
            }
        }

        $attempt_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$attempts_table} WHERE exam_id = %d AND student_id = %d",
            $exam_id,
            $student_id
        ));
        if ($attempt_count >= max(1, (int) $exam->attempt_limit)) {
            wp_send_json_error(['message' => 'تعداد دفعات مجاز شرکت در این آزمون به پایان رسیده است.'], 409);
        }

        $ip_hash = $this->client_ip_hash();
        if (!empty($exam->ip_restriction)) {
            $bound_ip = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT ip_hash FROM {$attempts_table}
                 WHERE exam_id = %d AND student_id = %d AND ip_hash <> ''
                 ORDER BY attempt_number ASC LIMIT 1",
                $exam_id,
                $student_id
            ));
            if ($bound_ip !== '' && !hash_equals($bound_ip, $ip_hash)) {
                wp_send_json_error(['message' => 'شرکت مجدد در این آزمون فقط با همان اتصال اینترنتی نخست امکان‌پذیر است.'], 403);
            }
        }

        $question_order = array_map(static fn($row) => (int) $row->question_id, $rows);
        if (!empty($exam->randomize_questions)) {
            $question_order = $this->shuffled_values($question_order);
        }
        $option_orders = [];
        foreach ($rows as $row) {
            if ((string) $row->question_type !== 'multiple_choice') {
                continue;
            }
            $answer_data = $this->decode_assoc($row->answer_data ?? '');
            $choices = array_slice((array) ($answer_data['choices'] ?? []), 0, 4);
            $choice_order = array_keys($choices);
            if (!empty($exam->randomize_options)) {
                $choice_order = $this->shuffled_values($choice_order);
            }
            $option_orders[(string) ((int) $row->question_id)] = $choice_order;
        }

        $inserted = $wpdb->insert($attempts_table, [
            'exam_id'          => $exam_id,
            'term_id'          => (int) $exam->term_id,
            'class_id'         => (int) $exam->class_id,
            'student_id'       => $student_id,
            'attempt_number'   => $attempt_count + 1,
            'status'           => 'in_progress',
            'question_order'   => wp_json_encode($question_order),
            'option_orders'    => wp_json_encode($option_orders),
            'answers'          => wp_json_encode((object) []),
            'max_score'        => array_reduce($rows, static fn($sum, $row) => $sum + max(0, (float) $row->item_score), 0.0),
            'manual_pending'   => 0,
            'ip_hash'          => $ip_hash,
            'exit_count'       => 0,
            'exit_log'         => wp_json_encode([]),
            'started_at'       => current_time('mysql'),
            'last_activity_at' => current_time('mysql'),
        ], ['%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%d', '%s', '%s', '%s']);

        if ($inserted === false) {
            wp_send_json_error(['message' => 'شروع آزمون ثبت نشد؛ دوباره تلاش کنید.'], 500);
        }

        $attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$attempts_table} WHERE id = %d LIMIT 1",
            (int) $wpdb->insert_id
        ));
        wp_send_json_success($this->create_attempt_payload($exam, $attempt, $rows));
    }

    public function ajax_student_save(): void
    {
        global $wpdb;
        $student_id = $this->require_student_ajax();
        $attempt_id = $this->post_int('attempt_id');
        $attempts_table = $this->attempts_table();
        $attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$attempts_table}
             WHERE id = %d AND student_id = %d AND status = 'in_progress' LIMIT 1",
            $attempt_id,
            $student_id
        ));
        if (!$attempt) {
            wp_send_json_error(['message' => 'تلاش فعال آزمون پیدا نشد.'], 404);
        }
        $exam = $this->student_exam((int) $attempt->exam_id, $student_id);
        if (!$exam) {
            wp_send_json_error(['message' => 'آزمون در دسترس نیست.'], 404);
        }
        $this->enforce_attempt_ip($exam, $attempt);

        $rows = $this->attempt_question_rows((int) $exam->id);
        $payload = $this->create_attempt_payload($exam, $attempt, $rows);
        if (!empty(self::general_settings()['strict_time_limit']) && (int) $payload['remainingSeconds'] < 1) {
            $wpdb->update(
                $attempts_table,
                ['status' => 'expired', 'last_activity_at' => current_time('mysql')],
                ['id' => $attempt_id],
                ['%s', '%s'],
                ['%d']
            );
            wp_send_json_error(['message' => 'زمان این تلاش به پایان رسیده است.', 'expired' => true], 409);
        }

        $answers = array_replace(
            $this->decode_assoc($attempt->answers ?? ''),
            $this->sanitize_attempt_answers($this->posted_answers(), $rows)
        );
        $saved = $wpdb->update(
            $attempts_table,
            ['answers' => wp_json_encode($answers), 'last_activity_at' => current_time('mysql')],
            ['id' => $attempt_id],
            ['%s', '%s'],
            ['%d']
        );
        if ($saved === false) {
            wp_send_json_error(['message' => 'ذخیره پاسخ‌ها انجام نشد.'], 500);
        }
        wp_send_json_success(['message' => 'پاسخ‌ها ذخیره شد.']);
    }

    public function ajax_student_track(): void
    {
        global $wpdb;
        $student_id = $this->require_student_ajax();
        $attempt_id = $this->post_int('attempt_id');
        $event = sanitize_key(wp_unslash($_POST['event'] ?? ''));
        if (!in_array($event, ['hidden', 'visible', 'pagehide'], true)) {
            wp_send_json_error(['message' => 'رویداد خروج معتبر نیست.'], 422);
        }

        $attempts_table = $this->attempts_table();
        $attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$attempts_table}
             WHERE id = %d AND student_id = %d AND status = 'in_progress' LIMIT 1",
            $attempt_id,
            $student_id
        ));
        if (!$attempt) {
            wp_send_json_success(['tracked' => false]);
        }
        $exam = $this->student_exam((int) $attempt->exam_id, $student_id);
        if (!$exam || empty($exam->record_exit_time)) {
            wp_send_json_success(['tracked' => false]);
        }
        $this->enforce_attempt_ip($exam, $attempt);

        $events = $this->decode_assoc($attempt->exit_log ?? '');
        $is_list = $events === [] || array_keys($events) === range(0, count($events) - 1);
        if (!$is_list) {
            $events = [];
        }
        $events[] = ['event' => $event, 'at' => current_time('mysql')];
        $events = array_slice($events, -100);
        $increments_exit = in_array($event, ['hidden', 'pagehide'], true);
        $wpdb->update(
            $attempts_table,
            [
                'exit_log'         => wp_json_encode($events),
                'exit_count'       => (int) $attempt->exit_count + ($increments_exit ? 1 : 0),
                'last_activity_at' => current_time('mysql'),
            ],
            ['id' => $attempt_id],
            ['%s', '%d', '%s'],
            ['%d']
        );
        wp_send_json_success(['tracked' => true]);
    }

    public function ajax_student_submit(): void
    {
        global $wpdb;
        $student_id = $this->require_student_ajax();
        $attempt_id = $this->post_int('attempt_id');
        $attempts_table = $this->attempts_table();
        $attempt = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$attempts_table}
             WHERE id = %d AND student_id = %d AND status = 'in_progress' LIMIT 1",
            $attempt_id,
            $student_id
        ));
        if (!$attempt) {
            wp_send_json_error(['message' => 'تلاش فعال آزمون پیدا نشد.'], 404);
        }
        $exam = $this->student_exam((int) $attempt->exam_id, $student_id);
        if (!$exam) {
            wp_send_json_error(['message' => 'آزمون در دسترس نیست.'], 404);
        }
        $this->enforce_attempt_ip($exam, $attempt);

        $rows = $this->attempt_question_rows((int) $exam->id);
        $answers = array_replace(
            $this->decode_assoc($attempt->answers ?? ''),
            $this->sanitize_attempt_answers($this->posted_answers(), $rows)
        );
        $grade = $this->grade_attempt($rows, $answers);
        $auto_grading = !empty(self::general_settings()['auto_grading']);
        $data = [
            'status'           => 'submitted',
            'answers'          => wp_json_encode($answers),
            'score'            => $auto_grading ? $grade['score'] : null,
            'max_score'        => $grade['max_score'],
            'manual_pending'   => $auto_grading ? $grade['manual_pending'] : count($rows),
            'last_activity_at' => current_time('mysql'),
            'submitted_at'     => current_time('mysql'),
        ];
        $saved = $wpdb->update(
            $attempts_table,
            $data,
            ['id' => $attempt_id],
            ['%s', '%s', '%f', '%f', '%d', '%s', '%s'],
            ['%d']
        );
        if ($saved === false) {
            wp_send_json_error(['message' => 'ثبت نهایی پاسخ‌ها انجام نشد.'], 500);
        }

        $result_visible = $auto_grading && $this->result_is_visible($exam);
        wp_send_json_success([
            'message' => 'پاسخ‌های آزمون با موفقیت ثبت شد.',
            'resultVisible' => $result_visible,
            'result' => $result_visible ? [
                'score'         => $grade['score'],
                'maxScore'      => $grade['max_score'],
                'manualPending' => $grade['manual_pending'],
            ] : null,
        ]);
    }

    /**
     * Persist elapsed scheduled exams as completed using the WordPress site timezone.
     * This keeps the management status, filters and reports consistent without
     * requiring a manual status change.
     */
    private function sync_elapsed_exam_statuses(int $term_id): void
    {
        if ($term_id <= 0) {
            return;
        }

        global $wpdb;
        $table = $this->table();
        $now = current_time('mysql');

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET status = 'done', updated_at = %s
                 WHERE term_id = %d
                   AND status = 'scheduled'
                   AND CONCAT(
                       IF(end_date IS NULL OR end_date = '0000-00-00', exam_date, end_date),
                       ' ',
                       IF(end_time IS NULL OR end_time = '', '23:59:59', end_time)
                   ) <= %s",
                $now,
                $term_id,
                $now
            )
        );
    }

    public function student_exams(int $student_id = 0, int $term_id = 0): array
    {
        global $wpdb;
        $student_id = $student_id ?: get_current_user_id();
        $term_id = $term_id ?: (int) ($this->active_term()->id ?? 0);
        if ($student_id < 1 || $term_id < 1) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT e.*, c.class_name, l.lesson_name, u.display_name AS teacher_name,
                    (SELECT COUNT(*) FROM {$this->question_items_table()} qi WHERE qi.exam_id = e.id) AS actual_question_count
             FROM {$this->table()} e
             INNER JOIN {$wpdb->prefix}hst_users_classes uc
                     ON uc.class_id = e.class_id
                    AND uc.term_id = e.term_id
                    AND uc.user_id = %d
                    AND uc.role = 'student'
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = e.class_id
             INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = e.lesson_id
             INNER JOIN {$wpdb->users} u ON u.ID = e.teacher_id
             WHERE e.term_id = %d
               AND e.delivery_mode = 'online'
               AND e.status <> 'cancelled'
             ORDER BY COALESCE(e.start_date, e.exam_date) ASC, COALESCE(e.start_time, '00:00:00') ASC",
            $student_id,
            $term_id
        )) ?: [];

        $items = [];
        foreach ($rows as $exam) {
            $attempts = $wpdb->get_results($wpdb->prepare(
                "SELECT id, attempt_number, status, score, max_score, manual_pending, exit_count, started_at, submitted_at
                 FROM {$this->attempts_table()}
                 WHERE exam_id = %d AND student_id = %d
                 ORDER BY attempt_number DESC",
                (int) $exam->id,
                $student_id
            )) ?: [];
            $window = $this->exam_window($exam);
            $active_attempt = null;
            $latest_submitted = null;
            foreach ($attempts as $attempt) {
                if (!$active_attempt && (string) $attempt->status === 'in_progress') {
                    $active_attempt = $attempt;
                }
                if (!$latest_submitted && (string) $attempt->status === 'submitted') {
                    $latest_submitted = $attempt;
                }
            }
            $attempt_count = count($attempts);
            $attempt_limit = max(1, (int) $exam->attempt_limit);
            $can_start = (int) $exam->actual_question_count > 0
                && $window['key'] === 'active'
                && ($active_attempt || $attempt_count < $attempt_limit);
            $result = null;
            if ($latest_submitted && $latest_submitted->score !== null && $this->result_is_visible($exam)) {
                $result = [
                    'score'          => (float) $latest_submitted->score,
                    'max_score'      => (float) $latest_submitted->max_score,
                    'manual_pending' => (int) $latest_submitted->manual_pending,
                ];
            }

            $items[] = [
                'exam'              => $exam,
                'window'            => $window['key'],
                'window_label'      => $window['label'],
                'attempt_count'     => $attempt_count,
                'attempt_limit'     => $attempt_limit,
                'active_attempt_id' => $active_attempt ? (int) $active_attempt->id : 0,
                'can_start'         => (bool) $can_start,
                'result'            => $result,
            ];
        }
        return $items;
    }

    public function teacher_exams($teacher_id = 0, $term_id = 0)
    {
        global $wpdb;
        $table = $this->table();
        $teacher_id = $teacher_id ?: get_current_user_id();
        $term_id = $term_id ?: (int) ($this->active_term()->id ?? 0);
        if (!$teacher_id || !$term_id) {
            return [];
        }

        $this->sync_elapsed_exam_statuses((int) $term_id);

        $teacher_condition = (current_user_can('manage_options') || current_user_can('hst_manage_school')) ? '' : $wpdb->prepare(' AND e.teacher_id = %d ', $teacher_id);

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.*, c.class_name, l.lesson_name, u.display_name AS teacher_name
                 FROM {$table} e
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = e.class_id
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = e.lesson_id
                 INNER JOIN {$wpdb->users} u ON u.ID = e.teacher_id
                 WHERE e.term_id = %d {$teacher_condition}
                 ORDER BY e.exam_date ASC, e.school_shift ASC",
                $term_id
            )
        ) ?: [];
    }

    /**
     * All exams for a term, regardless of teacher. Used for the manager's
     * read-only report view (a manager belongs to no class/lesson).
     */
    public function all_exams($term_id = 0)
    {
        global $wpdb;
        $table = $this->table();
        $term_id = $term_id ?: (int) ($this->active_term()->id ?? 0);
        if (!$term_id) {
            return [];
        }

        $this->sync_elapsed_exam_statuses((int) $term_id);

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.*, c.class_name, l.lesson_name, u.display_name AS teacher_name,
                        (SELECT COUNT(DISTINCT uc.user_id)
                         FROM {$wpdb->prefix}hst_users_classes uc
                         WHERE uc.class_id = e.class_id
                           AND uc.term_id = e.term_id
                           AND uc.role = 'student') AS eligible_participants,
                        (SELECT COUNT(DISTINCT ea.student_id)
                         FROM {$this->attempts_table()} ea
                         WHERE ea.exam_id = e.id) AS participant_count,
                        (SELECT COALESCE(SUM(ea.exit_count), 0)
                         FROM {$this->attempts_table()} ea
                         WHERE ea.exam_id = e.id) AS exit_event_count,
                        (SELECT COUNT(*)
                         FROM {$this->question_items_table()} qi
                         WHERE qi.exam_id = e.id) AS actual_question_count
                 FROM {$table} e
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = e.class_id
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = e.lesson_id
                 INNER JOIN {$wpdb->users} u ON u.ID = e.teacher_id
                 WHERE e.term_id = %d
                 ORDER BY COALESCE(e.start_date, e.exam_date) DESC, COALESCE(e.start_time, '00:00:00') DESC",
                $term_id
            )
        ) ?: [];
    }
}
