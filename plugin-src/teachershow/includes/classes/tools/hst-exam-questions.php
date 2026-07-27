<?php

defined('ABSPATH') || exit;

/**
 * Question-bank persistence and manager actions.
 *
 * Questions belong to the active academic term and to the class behind the
 * selected lesson. Keeping class_id on the record makes later exam assembly
 * deterministic while grade/major remain searchable snapshots.
 */
class HST_Exam_Questions
{
    private const TYPES = [
        'multiple_choice' => 'تستی',
        'fill_blank'      => 'جای خالی',
        'true_false'      => 'درست / نادرست',
        'short_answer'    => 'کوتاه پاسخ',
        'essay'           => 'تشریحی',
    ];

    private const DIFFICULTIES = [
        'easy'       => 'آسان',
        'medium'     => 'متوسط',
        'hard'       => 'سخت',
        'conceptual' => 'مفهومی',
    ];

    public function __construct()
    {
        add_action('wp_ajax_hst_exam_question_blueprint_save', [$this, 'ajax_save_blueprint']);
        add_action('wp_ajax_hst_exam_question_save', [$this, 'ajax_save']);
        add_action('wp_ajax_hst_exam_question_delete', [$this, 'ajax_delete']);
        add_action('wp_ajax_hst_exam_questions_transfer', [$this, 'ajax_transfer']);
        add_action('wp_ajax_hst_exam_questions_paper_data', [$this, 'ajax_exam_paper_data']);
        add_action('init', [$this, 'maybe_remove_legacy_generated_question_banks'], 34);
        add_action('init', [$this, 'maybe_resize_fill_blank_questions'], 35);
    }

    public static function types(): array
    {
        return self::TYPES;
    }

    public static function difficulties(): array
    {
        return self::DIFFICULTIES;
    }

    /**
     * Resize each visible fill-blank marker to the exact character count of
     * its corresponding answer. Unicode format marks such as the Persian
     * half-space are ignored because they do not occupy a written position.
     */
    public static function fit_blank_placeholders(string $text, array $answers): string
    {
        $answers = array_values(array_filter(array_map(static function ($answer): string {
            return is_scalar($answer) ? trim((string) $answer) : '';
        }, $answers), static fn(string $answer): bool => $answer !== ''));

        if ($text === '' || !$answers) {
            return $text;
        }

        $answer_index = 0;
        $result = preg_replace_callback('/_{2,}|\.{3,}|…{2,}|ـ{3,}/u', static function (array $match) use (&$answer_index, $answers): string {
            if (!isset($answers[$answer_index])) {
                return $match[0];
            }

            $answer = $answers[$answer_index++];
            $plain = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($answer) : strip_tags($answer);
            $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plain = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u', '', $plain) ?? $plain;
            $plain = preg_replace('/\s+/u', ' ', trim($plain)) ?? trim($plain);

            if (function_exists('grapheme_strlen')) {
                $length = grapheme_strlen($plain);
            } elseif (preg_match_all('/\X/u', $plain, $graphemes) !== false) {
                $length = count($graphemes[0]);
            } elseif (function_exists('mb_strlen')) {
                $length = mb_strlen($plain, 'UTF-8');
            } else {
                $length = strlen($plain);
            }

            return str_repeat('_', max(1, (int) $length));
        }, $text);

        return is_string($result) ? $result : $text;
    }

    /**
     * One-time migration for previously stored manual and system questions.
     */
    public function maybe_resize_fill_blank_questions(): void
    {
        global $wpdb;

        $revision = '1.0.111';
        if ((string) get_option('hst_fill_blank_width_revision', '') === $revision) {
            return;
        }

        $table = $this->questions_table();
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        $rows = $wpdb->get_results("SELECT id, question_text, answer_data FROM {$table} WHERE question_type = 'fill_blank' ORDER BY id ASC");
        foreach ((array) $rows as $row) {
            $answer_data = json_decode((string) $row->answer_data, true);
            $answers = is_array($answer_data) && isset($answer_data['answers']) && is_array($answer_data['answers'])
                ? $answer_data['answers']
                : [];
            if (!$answers) {
                continue;
            }

            $current = (string) $row->question_text;
            $resized = self::fit_blank_placeholders($current, $answers);
            if ($resized !== $current) {
                $wpdb->update(
                    $table,
                    ['question_text' => $resized, 'updated_at' => current_time('mysql')],
                    ['id' => (int) $row->id],
                    ['%s', '%s'],
                    ['%d']
                );
            }
        }

        update_option('hst_fill_blank_width_revision', $revision, false);
    }

    /**
     * Remove every built-in question bank that was shipped for Persian (1),
     * Media Literacy and Economics. User-created questions are preserved.
     *
     * The cleanup also removes exam-item links to those generated records and
     * deletes the old seed markers so no obsolete state remains after update.
     */
    public function maybe_remove_legacy_generated_question_banks(): void
    {
        global $wpdb;

        $revision = '1.0.142';
        if ((string) get_option('hst_generated_question_banks_removed', '') === $revision) {
            return;
        }

        $questions_table = $this->questions_table();
        $items_table = $this->items_table();
        $questions_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $questions_table));
        if ($questions_exists !== $questions_table) {
            return;
        }

        $items_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $items_table));
        $subjects = ['g10-persian1', 'g10-media', 'g10-economics'];
        $placeholders = implode(',', array_fill(0, count($subjects), '%s'));

        $wpdb->query('START TRANSACTION');

        if ($items_exists === $items_table) {
            $delete_items_sql = "
                DELETE qi
                FROM {$items_table} qi
                INNER JOIN {$questions_table} q ON q.id = qi.question_id
                WHERE q.created_by = 0
                  AND q.curriculum_subject IN ({$placeholders})
            ";
            $deleted_items = $wpdb->query($wpdb->prepare($delete_items_sql, $subjects));
            if ($deleted_items === false) {
                $wpdb->query('ROLLBACK');
                return;
            }
        }

        $delete_questions_sql = "
            DELETE FROM {$questions_table}
            WHERE created_by = 0
              AND curriculum_subject IN ({$placeholders})
        ";
        $deleted_questions = $wpdb->query($wpdb->prepare($delete_questions_sql, $subjects));
        if ($deleted_questions === false) {
            $wpdb->query('ROLLBACK');
            return;
        }

        $deleted_options = $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE 'hst_p1_seed_%'
                OR option_name LIKE 'hst_media_seed_%'
                OR option_name LIKE 'hst_economics_seed_%'"
        );
        if ($deleted_options === false) {
            $wpdb->query('ROLLBACK');
            return;
        }

        $wpdb->query('COMMIT');
        update_option('hst_generated_question_banks_removed', $revision, false);
    }

    public static function empty_context(): array
    {
        return [
            'questions'    => [],
            'lessons'      => [],
            'exams'        => [],
            'curriculum'   => class_exists('HST_Exam_Curriculum') ? HST_Exam_Curriculum::public_catalog() : ['source' => [], 'grades' => []],
            'blueprint'    => [],
            'types'        => self::TYPES,
            'difficulties' => self::DIFFICULTIES,
            'stats'        => [
                'total'       => 0,
                'easy_medium' => 0,
                'advanced'    => 0,
            ],
        ];
    }

    private function questions_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_exam_questions';
    }

    private function items_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_exam_question_items';
    }

    private function exams_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_exams';
    }

    private function authorize_ajax(): void
    {
        check_ajax_referer('hst_nonce', 'nonce');

        if (!is_user_logged_in() || (!current_user_can('manage_options') && !current_user_can('hst_manage_school'))) {
            wp_send_json_error(['message' => 'شما اجازه مدیریت بانک سؤال را ندارید.'], 403);
        }
    }

    private function active_term_id(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}hst_terms WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    }

    private function posted_array(string $key): array
    {
        $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : [];
        $values = is_array($raw) ? $raw : json_decode((string) $raw, true);
        return is_array($values)
            ? array_values(array_filter(array_unique(array_map('sanitize_key', $values))))
            : [];
    }

    private function normalize_blueprint(array $blueprint): array
    {
        $has_modern_units = isset($blueprint['units']) || isset($blueprint['chapters']) || isset($blueprint['sections']);
        if (!$has_modern_units && ($blueprint['subject'] ?? '') === 'g10-persian1') {
            $legacy = sanitize_key((string) ($blueprint['chapter'] ?? ''));
            if ($legacy === 'c1') {
                $blueprint['units'] = ['s1', 's2'];
                $blueprint['topics'] = ['s1-t1', 's2-t1'];
                $blueprint['chapter'] = '';
            } elseif (preg_match('/^c([2-9])$/', $legacy, $match)) {
                $new_unit = 'c' . ((int) $match[1] - 1);
                $blueprint['units'] = [$new_unit];
                $catalog = class_exists('HST_Exam_Curriculum') ? HST_Exam_Curriculum::catalog() : [];
                $entry = $catalog[(string) ($blueprint['grade'] ?? '')]['majors'][(string) ($blueprint['major'] ?? '')]['subjects']['g10-persian1'] ?? [];
                $blueprint['topics'] = class_exists('HST_Exam_Curriculum')
                    ? HST_Exam_Curriculum::unit_topic_ids((array) $entry, $new_unit)
                    : [];
                $blueprint['chapter'] = '';
            }
        }

        $units = [];
        foreach (['units', 'chapters', 'sections'] as $key) {
            foreach ((array) ($blueprint[$key] ?? []) as $unit) {
                $unit = sanitize_key((string) $unit);
                if ($unit !== '') {
                    $units[] = $unit;
                }
            }
        }
        $legacy_chapter = sanitize_key((string) ($blueprint['chapter'] ?? ''));
        if ($legacy_chapter !== '') {
            $units[] = $legacy_chapter;
        }
        $units = array_values(array_unique($units));
        $blueprint['units'] = $units;
        $blueprint['chapters'] = array_values(array_filter($units, static fn($unit) => strpos($unit, 'c') === 0));
        $blueprint['sections'] = array_values(array_filter($units, static fn($unit) => strpos($unit, 's') === 0));
        $blueprint['chapter'] = $units[0] ?? '';
        $blueprint['topics'] = array_values(array_filter(array_unique(array_map('sanitize_key', (array) ($blueprint['topics'] ?? [])))));

        if (($blueprint['subject'] ?? '') === 'g10-persian1'
            && ($blueprint['structure_revision'] ?? '') !== '1404-04'
            && class_exists('HST_Exam_Curriculum')) {
            $catalog = HST_Exam_Curriculum::catalog();
            $entry = $catalog[(string) ($blueprint['grade'] ?? '')]['majors'][(string) ($blueprint['major'] ?? '')]['subjects']['g10-persian1'] ?? [];
            $migrated_topics = [];
            foreach ($units as $unit_id) {
                array_push($migrated_topics, ...HST_Exam_Curriculum::unit_topic_ids((array) $entry, $unit_id));
            }
            $blueprint['topics'] = array_values(array_unique(array_filter($migrated_topics)));
            $blueprint['structure_revision'] = '1404-04';
        }

        return $blueprint;
    }

    private function lesson_profile(int $lesson_id): ?array
    {
        global $wpdb;

        if ($lesson_id < 1) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT l.id, l.lesson_name, l.class_id, c.class_name
                 FROM {$wpdb->prefix}hst_lessons l
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = l.class_id
                 WHERE l.id = %d
                 LIMIT 1",
                $lesson_id
            )
        );

        if (!$row) {
            return null;
        }

        $profile = class_exists('HST_Exams')
            ? HST_Exams::class_academic_profile((string) $row->class_name)
            : ['grade' => '', 'major' => ''];

        if (!in_array($profile['grade'], ['tenth', 'eleventh', 'twelfth'], true)
            || !in_array($profile['major'], ['experimental', 'math', 'humanities'], true)) {
            return null;
        }

        return [
            'lesson_id'   => (int) $row->id,
            'lesson_name' => (string) $row->lesson_name,
            'class_id'    => (int) $row->class_id,
            'class_name'  => (string) $row->class_name,
            'grade'       => (string) $profile['grade'],
            'major'       => (string) $profile['major'],
        ];
    }

    private function normalize_number($value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        if (class_exists('HST_Date')) {
            $value = HST_Date::en_digits($value);
        }

        return str_replace(['٫', '٬', '،', ','], ['.', '', '.', '.'], trim($value));
    }

    private function clean_text($value, int $max = 1000): string
    {
        $value = sanitize_text_field(wp_unslash(is_scalar($value) ? (string) $value : ''));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }

    private function decode_answer_payload(string $type)
    {
        $raw = isset($_POST['answer_data']) ? wp_unslash($_POST['answer_data']) : '';
        $payload = json_decode((string) $raw, true);
        if (!is_array($payload)) {
            return new WP_Error('invalid_answer', 'اطلاعات پاسخ صحیح نیست.');
        }

        if ($type === 'multiple_choice') {
            $choices = isset($payload['choices']) && is_array($payload['choices']) ? array_values($payload['choices']) : [];
            if (count($choices) !== 4) {
                return new WP_Error('invalid_choices', 'برای سؤال تستی باید هر چهار گزینه وارد شود.');
            }
            $choices = array_map(fn($choice) => $this->clean_text($choice, 1000), $choices);
            if (in_array('', $choices, true)) {
                return new WP_Error('empty_choice', 'متن همه گزینه‌ها را وارد کنید.');
            }
            $correct = isset($payload['correct_index']) ? (int) $payload['correct_index'] : -1;
            if ($correct < 0 || $correct > 3) {
                return new WP_Error('invalid_correct', 'گزینه صحیح را مشخص کنید.');
            }
            return ['choices' => $choices, 'correct_index' => $correct];
        }

        if ($type === 'true_false') {
            $correct = isset($payload['correct']) ? (string) $payload['correct'] : '';
            if (!in_array($correct, ['true', 'false'], true)) {
                return new WP_Error('invalid_correct', 'پاسخ درست یا نادرست را انتخاب کنید.');
            }
            return ['correct' => $correct];
        }

        if ($type === 'fill_blank') {
            $answers = isset($payload['answers']) && is_array($payload['answers']) ? array_values($payload['answers']) : [];
            $answers = array_map(fn($answer) => $this->clean_text($answer, 500), array_slice($answers, 0, 20));
            if (!$answers || in_array('', $answers, true)) {
                return new WP_Error('invalid_blanks', 'حداقل یک جای‌خالی بسازید و کلید پاسخ آن را وارد کنید.');
            }
            return ['answers' => $answers];
        }

        if ($type === 'short_answer') {
            $answer = $this->clean_text($payload['answer'] ?? '', 1000);
            if ($answer === '') {
                return new WP_Error('empty_answer', 'پاسخ کوتاه مورد انتظار را وارد کنید.');
            }
            return ['answer' => $answer];
        }

        $guide = $this->clean_text($payload['guide'] ?? '', 5000);
        if ($guide === '') {
            return new WP_Error('empty_guide', 'پاسخ نمونه یا راهنمای بارم‌بندی را وارد کنید.');
        }
        return ['guide' => $guide];
    }

    private function question_preview(string $html): string
    {
        $valid_html = function_exists('wp_check_invalid_utf8')
            ? wp_check_invalid_utf8($html, true)
            : $html;
        $stripped = wp_strip_all_tags($valid_html);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/[\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u', '', $decoded) ?? $decoded;
        $normalized = preg_replace('/\s+/u', ' ', $decoded);
        if ($normalized === null) {
            $normalized = preg_replace('/\s+/', ' ', $decoded);
        }
        $plain = trim((string) $normalized);
        if ($plain === '') {
            if (stripos($valid_html, '<img') !== false) {
                return 'سؤال تصویری';
            }
            if (stripos($valid_html, '<table') !== false) {
                return 'سؤال دارای جدول';
            }
            $plain = trim($decoded);
        }
        if (function_exists('mb_strlen') && mb_strlen($plain) > 110) {
            return mb_substr($plain, 0, 107) . '…';
        }
        if (strlen($plain) > 110) {
            return substr($plain, 0, 107) . '…';
        }
        return $plain;
    }

    public function context(int $term_id): array
    {
        global $wpdb;

        $context = self::empty_context();
        $questions_table = $this->questions_table();
        $items_table = $this->items_table();
        $exams_table = $this->exams_table();
        if ($term_id < 1) {
            return $context;
        }

        $saved_blueprint = get_user_meta(get_current_user_id(), 'hst_exam_question_bank_blueprint', true);
        $active_blueprint = [];
        if (is_array($saved_blueprint) && (int) ($saved_blueprint['term_id'] ?? 0) === $term_id) {
            $active_blueprint = $this->normalize_blueprint($saved_blueprint);
            if ($active_blueprint !== $saved_blueprint) {
                update_user_meta(get_current_user_id(), 'hst_exam_question_bank_blueprint', $active_blueprint);
            }
            $context['blueprint'] = $active_blueprint;
        }

        if (($active_blueprint['subject'] ?? '') === 'g10-economics'
            && in_array('c1-t1', (array) ($active_blueprint['topics'] ?? []), true)
            && class_exists('HST_Economics_Lesson1_Question_Seeds')) {
            HST_Economics_Lesson1_Question_Seeds::seed_scope(
                $term_id,
                (string) ($active_blueprint['grade'] ?? ''),
                (string) ($active_blueprint['major'] ?? '')
            );
        }

        if (($active_blueprint['subject'] ?? '') === 'g10-media'
            && in_array('c1-t1', (array) ($active_blueprint['topics'] ?? []), true)
            && class_exists('HST_Media_Lesson1_Question_Seeds')) {
            HST_Media_Lesson1_Question_Seeds::seed_scope(
                $term_id,
                (string) ($active_blueprint['grade'] ?? ''),
                (string) ($active_blueprint['major'] ?? '')
            );
        }

        $lesson_rows = $wpdb->get_results(
            "SELECT l.id, l.lesson_name, l.class_id, c.class_name
             FROM {$wpdb->prefix}hst_lessons l
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = l.class_id
             ORDER BY c.class_name ASC, l.lesson_name ASC"
        ) ?: [];
        $lesson_rows = HST_Classes::sort_rows($lesson_rows, 'class_name', ['lesson_name']);

        foreach ($lesson_rows as $lesson) {
            $profile = class_exists('HST_Exams')
                ? HST_Exams::class_academic_profile((string) $lesson->class_name)
                : ['grade' => '', 'major' => ''];
            if (!in_array($profile['grade'], ['tenth', 'eleventh', 'twelfth'], true)
                || !in_array($profile['major'], ['experimental', 'math', 'humanities'], true)) {
                continue;
            }
            $context['lessons'][] = [
                'id'         => (int) $lesson->id,
                'name'       => (string) $lesson->lesson_name,
                'class_id'   => (int) $lesson->class_id,
                'class_name' => (string) $lesson->class_name,
                'grade'      => (string) $profile['grade'],
                'major'      => (string) $profile['major'],
            ];
        }

        $question_where = ['q.term_id = %d'];
        $question_params = [$term_id];
        if (!empty($active_blueprint['subject'])) {
            $question_where[] = 'q.grade = %s';
            $question_params[] = (string) ($active_blueprint['grade'] ?? '');
            $question_where[] = 'q.major = %s';
            $question_params[] = (string) ($active_blueprint['major'] ?? '');
            $question_where[] = 'q.curriculum_subject = %s';
            $question_params[] = (string) ($active_blueprint['subject'] ?? '');
            $active_units = array_values(array_filter(array_map('sanitize_key', (array) ($active_blueprint['units'] ?? []))));
            if ($active_units) {
                $question_where[] = 'q.curriculum_chapter IN (' . implode(',', array_fill(0, count($active_units), '%s')) . ')';
                array_push($question_params, ...$active_units);
            }

            $topic_where = [];
            foreach ((array) ($active_blueprint['topics'] ?? []) as $topic) {
                $topic = sanitize_key((string) $topic);
                if ($topic === '') {
                    continue;
                }
                $topic_where[] = 'q.curriculum_topics LIKE %s';
                $question_params[] = '%' . $wpdb->esc_like('"' . $topic . '"') . '%';
            }
            if ($topic_where) {
                $question_where[] = '(' . implode(' OR ', $topic_where) . ')';
            }
        } else {
            // The question list is only meaningful after a curriculum scope is selected.
            $question_where[] = '1 = 0';
        }

        $question_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.*, l.lesson_name, c.class_name, u.display_name,
                        (SELECT COUNT(*) FROM {$items_table} qi WHERE qi.question_id = q.id) AS usage_count
                 FROM {$questions_table} q
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = q.lesson_id
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = q.class_id
                 LEFT JOIN {$wpdb->users} u ON u.ID = q.created_by
                 WHERE " . implode(' AND ', $question_where) . "
                 ORDER BY q.id DESC",
                $question_params
            )
        );

        $seen_built_in_questions = [];
        foreach ($question_rows as $question) {
            $answer_data = json_decode((string) $question->answer_data, true);
            if (is_array($answer_data) && ($answer_data['bank_id'] ?? '') === 'economics-lesson1-1404') {
                $semantic_key = hash('sha256', implode('|', [
                    (string) $question->curriculum_subject,
                    (string) $question->curriculum_chapter,
                    (string) $question->curriculum_topics,
                    (string) $question->question_type,
                    (string) $question->difficulty,
                    (string) $question->question_text,
                    (string) $question->answer_data,
                ]));
                if (isset($seen_built_in_questions[$semantic_key])) {
                    continue;
                }
                $seen_built_in_questions[$semantic_key] = true;
            }
            $curriculum_topics = json_decode((string) ($question->curriculum_topics ?? ''), true);
            $context['questions'][] = [
                'id'               => (int) $question->id,
                'lesson_id'        => (int) $question->lesson_id,
                'class_id'         => (int) $question->class_id,
                'lesson_name'      => (string) $question->lesson_name,
                'class_name'       => (string) $question->class_name,
                'grade'            => (string) $question->grade,
                'major'            => (string) $question->major,
                'curriculum_subject' => (string) ($question->curriculum_subject ?? ''),
                'curriculum_chapter' => (string) ($question->curriculum_chapter ?? ''),
                'curriculum_topics'  => is_array($curriculum_topics) ? array_values($curriculum_topics) : [],
                'question_type'    => (string) $question->question_type,
                'question_type_label' => self::TYPES[$question->question_type] ?? (string) $question->question_type,
                'difficulty'       => (string) $question->difficulty,
                'difficulty_label' => self::DIFFICULTIES[$question->difficulty] ?? (string) $question->difficulty,
                'score'            => (float) $question->score,
                'question_text'    => (string) $question->question_text,
                'question_preview' => $this->question_preview((string) $question->question_text),
                'answer_data'      => is_array($answer_data) ? $answer_data : [],
                'created_by'       => (int) $question->created_by,
                'creator_name'     => (string) ($question->display_name ?: 'مدیر سامانه'),
                'usage_count'      => (int) $question->usage_count,
                'created_at'       => (string) $question->created_at,
            ];

            $context['stats']['total']++;
            if (in_array($question->difficulty, ['easy', 'medium'], true)) {
                $context['stats']['easy_medium']++;
            } else {
                $context['stats']['advanced']++;
            }
        }

        $exam_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.id, e.title, e.class_id, e.lesson_id, e.exam_date, e.duration_minutes,
                        e.grade, e.major, e.exam_type, e.teacher_id,
                        l.lesson_name, c.class_name, u.display_name AS teacher_name
                 FROM {$exams_table} e
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = e.lesson_id
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = e.class_id
                 LEFT JOIN {$wpdb->users} u ON u.ID = e.teacher_id
                 WHERE e.term_id = %d AND e.status <> 'cancelled'
                 ORDER BY e.exam_date DESC, e.id DESC",
                $term_id
            )
        );

        foreach ($exam_rows as $exam) {
            $context['exams'][] = [
                'id'          => (int) $exam->id,
                'title'       => (string) $exam->title,
                'lesson_id'   => (int) $exam->lesson_id,
                'class_id'    => (int) $exam->class_id,
                'lesson_name' => (string) $exam->lesson_name,
                'class_name'  => (string) $exam->class_name,
                'exam_date'   => (string) $exam->exam_date,
                'duration_minutes' => (int) $exam->duration_minutes,
                'grade'         => (string) $exam->grade,
                'major'         => (string) $exam->major,
                'exam_type'     => (string) $exam->exam_type,
                'teacher_id'    => (int) $exam->teacher_id,
                'teacher_name'  => (string) ($exam->teacher_name ?: 'مدیر سامانه'),
            ];
        }

        return $context;
    }

    public function ajax_save_blueprint(): void
    {
        $this->authorize_ajax();

        $term_id = $this->active_term_id();
        if ($term_id < 1 || !class_exists('HST_Exam_Curriculum')) {
            wp_send_json_error(['message' => 'اطلاعات سال تحصیلی یا کاتالوگ بودجه‌بندی در دسترس نیست.']);
        }

        $grade = isset($_POST['grade']) ? sanitize_key(wp_unslash($_POST['grade'])) : '';
        $major = isset($_POST['major']) ? sanitize_key(wp_unslash($_POST['major'])) : '';
        $subject = isset($_POST['subject']) ? sanitize_key(wp_unslash($_POST['subject'])) : '';
        $units = $this->posted_array('units');
        if (!$units) {
            $legacy_chapter = isset($_POST['chapter']) ? sanitize_key(wp_unslash($_POST['chapter'])) : '';
            if ($legacy_chapter !== '') {
                $units = [$legacy_chapter];
            }
        }
        $topics = HST_Exam_Curriculum::validate_selection($grade, $major, $subject, $this->posted_array('topics'));

        $catalog = HST_Exam_Curriculum::catalog();
        $subject_entry = $catalog[$grade]['majors'][$major]['subjects'][$subject] ?? null;
        if (!$subject_entry || !$topics || !$units) {
            wp_send_json_error(['message' => 'پایه، رشته، درس و حداقل یک فصل، درس یا بخش معتبر را انتخاب کنید.']);
        }

        $valid_units = [];
        $valid_topics = [];
        $chapter_ids = [];
        $section_ids = [];
        foreach (HST_Exam_Curriculum::subject_units((array) $subject_entry) as $unit_entry) {
            $unit_id = sanitize_key((string) ($unit_entry['id'] ?? ''));
            if ($unit_id === '' || !in_array($unit_id, $units, true)) {
                continue;
            }
            $unit_topics = array_values(array_intersect($topics, array_column((array) ($unit_entry['topics'] ?? []), 'id')));
            if (!$unit_topics) {
                continue;
            }
            $valid_units[] = $unit_id;
            array_push($valid_topics, ...$unit_topics);
            if (($unit_entry['kind'] ?? 'chapter') === 'section') {
                $section_ids[] = $unit_id;
            } else {
                $chapter_ids[] = $unit_id;
            }
        }
        $units = array_values(array_unique($valid_units));
        $topics = array_values(array_unique($valid_topics));
        if (!$units || !$topics) {
            wp_send_json_error(['message' => 'فصل‌ها، درس‌ها و بخش‌های انتخابی با درس انتخاب‌شده هماهنگ نیستند.']);
        }

        $blueprint = [
            'term_id'       => $term_id,
            'grade'         => $grade,
            'major'         => $major,
            'subject'       => $subject,
            'subject_title' => (string) $subject_entry['title'],
            'units'         => $units,
            'chapters'      => array_values(array_unique($chapter_ids)),
            'sections'      => array_values(array_unique($section_ids)),
            'chapter'       => $units[0], // Backward compatibility for older clients.
            'topics'        => $topics,
            'structure_revision' => $subject === 'g10-persian1'
                ? '1404-04'
                : ($subject === 'g10-media'
                    ? '1404-02'
                    : ($subject === 'g10-economics' ? '1404-03' : '')),
            'updated_at'    => current_time('mysql'),
        ];
        $reload = false;
        $message = 'بودجه‌بندی آزمون ذخیره شد.';
        if ($subject === 'g10-economics' && in_array('c1-t1', $topics, true)) {
            if (!class_exists('HST_Economics_Lesson1_Question_Seeds')) {
                wp_send_json_error(['message' => 'کلاس آماده‌سازی بانک سؤال درس اول اقتصاد در دسترس نیست.']);
            }
            $seed_result = HST_Economics_Lesson1_Question_Seeds::seed_scope($term_id, $grade, $major);
            if (empty($seed_result['complete'])) {
                $error_message = empty($seed_result['matched'])
                    ? 'برای پایۀ دهم انسانی، درس اقتصاد در کلاس‌های سال تحصیلی فعال پیدا نشد.'
                    : 'بانک سؤال درس اول اقتصاد کامل آماده نشد؛ لطفاً ساختار پایگاه داده را بررسی کنید.';
                wp_send_json_error(['message' => $error_message]);
            }
            $reload = true;
            $message = 'بودجه‌بندی ذخیره و بانک سؤال درس اول اقتصاد آماده شد.';
        }

        if ($subject === 'g10-media' && in_array('c1-t1', $topics, true)) {
            if (!class_exists('HST_Media_Lesson1_Question_Seeds')) {
                wp_send_json_error(['message' => 'کلاس آماده‌سازی بانک سؤال درس اول تفکر و سواد رسانه‌ای در دسترس نیست.']);
            }
            $seed_result = HST_Media_Lesson1_Question_Seeds::seed_scope($term_id, $grade, $major);
            if (empty($seed_result['complete'])) {
                $error_message = empty($seed_result['matched'])
                    ? 'برای پایۀ دهم و رشتۀ انتخاب‌شده، درس تفکر و سواد رسانه‌ای در کلاس‌های سال تحصیلی فعال پیدا نشد.'
                    : 'بانک سؤال درس اول تفکر و سواد رسانه‌ای کامل آماده نشد؛ لطفاً ساختار پایگاه داده را بررسی کنید.';
                wp_send_json_error(['message' => $error_message]);
            }
            $reload = true;
            $message = 'بودجه‌بندی ذخیره و بانک سؤال درس اول تفکر و سواد رسانه‌ای آماده شد.';
        }

        update_user_meta(get_current_user_id(), 'hst_exam_question_bank_blueprint', $blueprint);

        wp_send_json_success([
            'message'   => $message,
            'blueprint' => $blueprint,
            'reload'    => $reload,
        ]);
    }

    public function ajax_save(): void
    {
        global $wpdb;

        $this->authorize_ajax();
        $questions_table = $this->questions_table();
        $term_id = $this->active_term_id();
        if ($term_id < 1) {
            wp_send_json_error(['message' => 'سال تحصیلی فعال پیدا نشد.']);
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $lesson_id = isset($_POST['lesson_id']) ? absint($_POST['lesson_id']) : 0;
        $lesson = $this->lesson_profile($lesson_id);
        $type = isset($_POST['question_type']) ? sanitize_key(wp_unslash($_POST['question_type'])) : '';
        $difficulty = isset($_POST['difficulty']) ? sanitize_key(wp_unslash($_POST['difficulty'])) : '';
        $score_text = $this->normalize_number($_POST['score'] ?? '');
        $raw_question = isset($_POST['question_text']) ? wp_unslash($_POST['question_text']) : '';
        $question_text = wp_kses_post((string) $raw_question);

        if (!$lesson) {
            wp_send_json_error(['message' => 'درس، پایه و رشته انتخابی معتبر نیست.']);
        }
        if (!isset(self::TYPES[$type])) {
            wp_send_json_error(['message' => 'قالب سؤال معتبر نیست.']);
        }
        if (!isset(self::DIFFICULTIES[$difficulty])) {
            wp_send_json_error(['message' => 'سطح سؤال معتبر نیست.']);
        }
        if (!is_numeric($score_text) || (float) $score_text < 0.25 || (float) $score_text > 100) {
            wp_send_json_error(['message' => 'بارم نمره باید بین ۰٫۲۵ تا ۱۰۰ باشد.']);
        }
        $plain_question = trim(wp_strip_all_tags($question_text));
        if ($plain_question === '' && stripos($question_text, '<img') === false && stripos($question_text, '<table') === false) {
            wp_send_json_error(['message' => 'متن یا صورت سؤال را وارد کنید.']);
        }
        if (strlen($question_text) > 30000) {
            wp_send_json_error(['message' => 'متن سؤال بیش از حد مجاز است.']);
        }

        $answer_data = $this->decode_answer_payload($type);
        if (is_wp_error($answer_data)) {
            wp_send_json_error(['message' => $answer_data->get_error_message()]);
        }
        if ($type === 'fill_blank') {
            $question_text = self::fit_blank_placeholders($question_text, (array) ($answer_data['answers'] ?? []));
        }

        $curriculum_subject = isset($_POST['curriculum_subject']) ? sanitize_key(wp_unslash($_POST['curriculum_subject'])) : '';
        $curriculum_chapter = isset($_POST['curriculum_chapter']) ? sanitize_key(wp_unslash($_POST['curriculum_chapter'])) : '';
        $raw_curriculum_topics = isset($_POST['curriculum_topics']) ? wp_unslash($_POST['curriculum_topics']) : [];
        $curriculum_topics = is_array($raw_curriculum_topics) ? $raw_curriculum_topics : json_decode((string) $raw_curriculum_topics, true);
        $curriculum_topics = is_array($curriculum_topics) ? $curriculum_topics : [];

        if ($curriculum_subject !== '') {
            if (!class_exists('HST_Exam_Curriculum')) {
                wp_send_json_error(['message' => 'کاتالوگ سرفصل‌های رسمی در دسترس نیست.']);
            }
            $curriculum_topics = HST_Exam_Curriculum::validate_selection(
                (string) $lesson['grade'],
                (string) $lesson['major'],
                $curriculum_subject,
                $curriculum_topics
            );
            $curriculum_entry = HST_Exam_Curriculum::catalog()[$lesson['grade']]['majors'][$lesson['major']]['subjects'][$curriculum_subject] ?? null;
            $valid_chapter_topics = $curriculum_entry
                ? HST_Exam_Curriculum::unit_topic_ids((array) $curriculum_entry, $curriculum_chapter)
                : [];
            $curriculum_topics = array_values(array_intersect($curriculum_topics, $valid_chapter_topics));
            if (!$curriculum_entry || !$valid_chapter_topics || !$curriculum_topics) {
                wp_send_json_error(['message' => 'سرفصل رسمی انتخاب‌شده برای این سؤال معتبر نیست.']);
            }
        } else {
            $curriculum_chapter = '';
            $curriculum_topics = [];
        }

        $data = [
            'term_id'       => $term_id,
            'class_id'      => $lesson['class_id'],
            'lesson_id'     => $lesson['lesson_id'],
            'created_by'    => get_current_user_id(),
            'grade'         => $lesson['grade'],
            'major'         => $lesson['major'],
            'curriculum_subject' => $curriculum_subject,
            'curriculum_chapter' => $curriculum_chapter,
            'curriculum_topics'  => wp_json_encode($curriculum_topics, JSON_UNESCAPED_UNICODE),
            'question_type' => $type,
            'difficulty'    => $difficulty,
            'score'         => hst_format_grade($score_text, false),
            'question_text' => $question_text,
            'answer_data'   => wp_json_encode($answer_data, JSON_UNESCAPED_UNICODE),
        ];

        if ($id > 0) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$questions_table} WHERE id = %d AND term_id = %d",
                $id,
                $term_id
            ));
            if (!$exists) {
                wp_send_json_error(['message' => 'سؤال موردنظر پیدا نشد.'], 404);
            }
            $started_usage = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$this->items_table()} qi
                 INNER JOIN {$wpdb->prefix}hst_exam_attempts ea ON ea.exam_id = qi.exam_id
                 WHERE qi.question_id = %d",
                $id
            ));
            if ($started_usage > 0) {
                wp_send_json_error([
                    'message' => 'این سؤال در آزمونی استفاده شده که پاسخ‌گویی آن آغاز شده و دیگر قابل ویرایش نیست.',
                ], 409);
            }
            unset($data['term_id'], $data['created_by']);
            $data['updated_at'] = current_time('mysql');
            $result = $wpdb->update($questions_table, $data, ['id' => $id], null, ['%d']);
            $message = 'سؤال با موفقیت ویرایش شد.';
        } else {
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert($questions_table, $data);
            $id = (int) $wpdb->insert_id;
            $message = 'سؤال جدید به بانک سؤال افزوده شد.';
        }

        if ($result === false) {
            wp_send_json_error(['message' => 'ذخیره سؤال انجام نشد. لطفاً دوباره تلاش کنید.']);
        }

        wp_send_json_success(['message' => $message, 'id' => $id]);
    }

    public function ajax_delete(): void
    {
        global $wpdb;

        $this->authorize_ajax();
        $questions_table = $this->questions_table();
        $items_table = $this->items_table();
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $term_id = $this->active_term_id();
        if ($id < 1 || $term_id < 1) {
            wp_send_json_error(['message' => 'سؤال معتبر نیست.']);
        }

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$questions_table} WHERE id = %d AND term_id = %d",
            $id,
            $term_id
        ));
        if (!$exists) {
            wp_send_json_error(['message' => 'سؤال موردنظر پیدا نشد.'], 404);
        }

        $usage = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$items_table} WHERE question_id = %d",
            $id
        ));
        if ($usage > 0) {
            wp_send_json_error(['message' => 'این سؤال در یک یا چند آزمون استفاده شده و قابل حذف نیست.']);
        }

        if ($wpdb->delete($questions_table, ['id' => $id], ['%d']) === false) {
            wp_send_json_error(['message' => 'حذف سؤال انجام نشد.']);
        }
        wp_send_json_success(['message' => 'سؤال از بانک سؤال حذف شد.']);
    }

    private function subject_match_key(string $value): string
    {
        if (class_exists('HST_Date')) {
            $value = HST_Date::en_digits($value);
        }
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = str_replace(['ي', 'ك', 'ۀ', 'ة', "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}"], ['ی', 'ک', 'ه', 'ه', ' ', ' ', ' ', ' '], $value);
        $value = preg_replace('/[()（）\[\]۰-۹0-9،,:؛\-–—]/u', ' ', $value) ?? $value;
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if (str_contains($value, 'تعلیمات دینی') || str_contains($value, 'دینی اخلاق و قرآن') || str_contains($value, 'دین و زندگی')) {
            return 'دین و زندگی';
        }
        if (str_contains($value, 'زبان خارجی') || str_contains($value, 'زبان انگلیسی') || str_contains($value, 'انگلیسی')) {
            return 'انگلیسی';
        }
        if (str_contains($value, 'هویت اجتماعی') || str_contains($value, 'علوم اجتماعی')) {
            return 'هویت اجتماعی';
        }
        if (str_contains($value, 'جغرافیای عمومی و استان شناسی') || $value === 'استان شناسی') {
            return 'استان شناسی';
        }
        if ($value === 'درس انتخابی' || str_contains($value, 'تفکر و سواد رسانه ای') || $value === 'هنر' || str_contains($value, 'کارگاه کارآفرینی و تولید')) {
            return 'درس انتخابی';
        }

        return $value;
    }

    private function subjects_match(string $lesson_name, string $subject_title): bool
    {
        $lesson = $this->subject_match_key($lesson_name);
        $subject = $this->subject_match_key($subject_title);
        if ($lesson === '' || $subject === '') {
            return false;
        }

        $lesson_compact = preg_replace('/\s+/u', '', $lesson) ?? $lesson;
        $subject_compact = preg_replace('/\s+/u', '', $subject) ?? $subject;
        return str_contains($lesson, $subject)
            || str_contains($subject, $lesson)
            || str_contains($lesson_compact, $subject_compact)
            || str_contains($subject_compact, $lesson_compact);
    }

    /**
     * Resolve a bank question into the destination exam scope.
     *
     * Built-in banks are seeded once for every matching class. A manager may
     * therefore select a semantically identical question from another class.
     * Reuse its destination-class counterpart when possible and otherwise
     * create a scoped copy, so exam items always reference the correct lesson
     * and class without losing the selected content.
     */
    private function destination_question_id(object $source, object $exam, string $questions_table): int
    {
        global $wpdb;

        if ((int) $source->class_id === (int) $exam->class_id
            && (int) $source->lesson_id === (int) $exam->lesson_id) {
            return (int) $source->id;
        }

        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$questions_table}
             WHERE term_id = %d AND class_id = %d AND lesson_id = %d
               AND curriculum_subject = %s AND curriculum_chapter = %s
               AND curriculum_topics = %s AND question_type = %s
               AND difficulty = %s AND question_text = %s AND answer_data = %s
             ORDER BY id ASC LIMIT 1",
            (int) $source->term_id,
            (int) $exam->class_id,
            (int) $exam->lesson_id,
            (string) $source->curriculum_subject,
            (string) $source->curriculum_chapter,
            (string) $source->curriculum_topics,
            (string) $source->question_type,
            (string) $source->difficulty,
            (string) $source->question_text,
            (string) $source->answer_data
        ));
        if ($existing_id > 0) {
            return $existing_id;
        }

        $inserted = $wpdb->insert($questions_table, [
            'term_id'             => (int) $source->term_id,
            'class_id'            => (int) $exam->class_id,
            'lesson_id'           => (int) $exam->lesson_id,
            'created_by'          => (int) $source->created_by,
            'grade'               => (string) $exam->grade,
            'major'               => (string) $exam->major,
            'curriculum_subject'  => (string) $source->curriculum_subject,
            'curriculum_chapter'  => (string) $source->curriculum_chapter,
            'curriculum_topics'   => (string) $source->curriculum_topics,
            'question_type'       => (string) $source->question_type,
            'difficulty'          => (string) $source->difficulty,
            'score'               => hst_format_grade((float) $source->score, false),
            'question_text'       => (string) $source->question_text,
            'answer_data'         => (string) $source->answer_data,
            'created_at'          => current_time('mysql'),
        ], ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        return $inserted === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Return the persisted question paper and answer guide for one exam.
     *
     * This is used by the manager exam list so the download action always
     * reflects the final ordered questions and their exam-specific scores.
     */
    public function ajax_exam_paper_data(): void
    {
        global $wpdb;

        check_ajax_referer('hst_nonce', 'nonce');

        if (!is_user_logged_in() || (!current_user_can('manage_options') && !current_user_can('hst_manage_school') && !current_user_can('hst_teach'))) {
            wp_send_json_error(['message' => 'اجازه دریافت نمونه سؤال این آزمون را ندارید.'], 403);
        }

        $exam_id = isset($_POST['exam_id']) ? absint(wp_unslash($_POST['exam_id'])) : 0;
        if ($exam_id < 1) {
            wp_send_json_error(['message' => 'آزمون معتبر نیست.']);
        }

        $exams_table = $this->exams_table();
        $items_table = $this->items_table();
        $questions_table = $this->questions_table();

        $exam = $wpdb->get_row($wpdb->prepare(
            "SELECT e.id, e.title, e.description, e.teacher_id, e.term_id, e.class_id, e.lesson_id,
                    e.grade, e.major, e.exam_type, e.delivery_mode, e.duration_minutes, e.question_count,
                    e.start_date, e.end_date, e.start_time, e.end_time, e.attempt_limit, e.result_visibility,
                    e.randomize_questions, e.randomize_options, e.record_exit_time, e.ip_restriction, e.ai_review, e.status,
                    COALESCE(e.start_date, e.exam_date) AS paper_date,
                    c.class_name, l.lesson_name, u.display_name AS teacher_name
             FROM {$exams_table} e
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = e.class_id
             INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = e.lesson_id
             INNER JOIN {$wpdb->users} u ON u.ID = e.teacher_id
             WHERE e.id = %d AND e.status <> 'cancelled'
             LIMIT 1",
            $exam_id
        ));

        if (!$exam) {
            wp_send_json_error(['message' => 'آزمون موردنظر پیدا نشد.']);
        }

        $is_manager = current_user_can('manage_options') || current_user_can('hst_manage_school');
        if (!$is_manager && (int) $exam->teacher_id !== get_current_user_id()) {
            wp_send_json_error(['message' => 'اجازه دریافت فایل‌های این آزمون را ندارید.'], 403);
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.sort_order, i.score AS item_score,
                    q.question_type, q.difficulty, q.question_text, q.answer_data
             FROM {$items_table} i
             INNER JOIN {$questions_table} q ON q.id = i.question_id
             WHERE i.exam_id = %d
             ORDER BY i.sort_order ASC, i.id ASC",
            $exam_id
        ));

        if (!$rows) {
            wp_send_json_error(['message' => 'برای این آزمون هنوز سؤالی ثبت نشده است.']);
        }

        $questions = [];
        foreach ($rows as $index => $row) {
            $answer_data = json_decode((string) $row->answer_data, true);
            if (!is_array($answer_data)) {
                $answer_data = [];
            }

            $questions[] = [
                'number'        => $index + 1,
                'score'         => (float) $row->item_score,
                'question_type' => sanitize_key((string) $row->question_type),
                'difficulty'    => sanitize_key((string) $row->difficulty),
                'question_text' => (string) $row->question_text,
                'answer_data'   => $answer_data,
            ];
        }

        $paper_date = (string) ($exam->paper_date ?? '');
        if ($paper_date !== '' && class_exists('HST_Date')) {
            $paper_date = HST_Date::format($paper_date, 'Y/m/d', '—');
        }
        $general_settings = class_exists('HST_Exams')
            ? HST_Exams::general_settings()
            : [
                'negative_marking'  => 0,
                'auto_grading'      => 0,
                'strict_time_limit' => 0,
            ];

        wp_send_json_success([
            'exam' => [
                'id'          => (int) $exam->id,
                'title'       => (string) $exam->title,
                'lessonName'  => (string) $exam->lesson_name,
                'className'   => (string) $exam->class_name,
                'examDate'    => $paper_date,
                'duration'    => (int) $exam->duration_minutes,
                'teacherName' => (string) $exam->teacher_name,
                'grade'       => (string) $exam->grade,
                'major'       => (string) $exam->major,
                'examType'          => (string) $exam->exam_type,
                'deliveryMode'      => (string) $exam->delivery_mode,
                'description'       => (string) ($exam->description ?? ''),
                'questionCount'     => (int) $exam->question_count,
                'startDate'         => (string) ($exam->start_date ?? ''),
                'endDate'           => (string) ($exam->end_date ?? ''),
                'startTime'         => substr((string) ($exam->start_time ?? ''), 0, 5),
                'endTime'           => substr((string) ($exam->end_time ?? ''), 0, 5),
                'attemptLimit'      => (int) $exam->attempt_limit,
                'resultVisibility'  => (string) $exam->result_visibility,
                'negativeMarking'   => !empty($general_settings['negative_marking']) ? 1 : 0,
                'autoGrading'       => !empty($general_settings['auto_grading']) ? 1 : 0,
                'strictTimeLimit'   => !empty($general_settings['strict_time_limit']) ? 1 : 0,
                'randomizeQuestions'=> (int) $exam->randomize_questions,
                'randomizeOptions'  => (int) $exam->randomize_options,
                'recordExitTime'    => (int) $exam->record_exit_time,
                'ipRestriction'     => (int) $exam->ip_restriction,
                'status'            => (string) $exam->status,
            ],
            'questions' => $questions,
        ]);
    }

    public function ajax_transfer(): void
    {
        global $wpdb;

        $this->authorize_ajax();
        $questions_table = $this->questions_table();
        $items_table = $this->items_table();
        $exams_table = $this->exams_table();
        $term_id = $this->active_term_id();
        $exam_id = isset($_POST['exam_id']) ? absint($_POST['exam_id']) : 0;
        $raw_ids = isset($_POST['question_ids']) ? wp_unslash($_POST['question_ids']) : '';
        $ids = is_array($raw_ids) ? $raw_ids : json_decode((string) $raw_ids, true);
        $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('absint', $ids)))) : [];
        $ids = array_slice($ids, 0, 500);

        $raw_scores = isset($_POST['question_scores']) ? wp_unslash($_POST['question_scores']) : [];
        $posted_scores = is_array($raw_scores) ? $raw_scores : json_decode((string) $raw_scores, true);
        $posted_scores = is_array($posted_scores) ? $posted_scores : [];

        if ($term_id < 1 || $exam_id < 1 || !$ids) {
            wp_send_json_error(['message' => 'آزمون و حداقل یک سؤال را انتخاب کنید.']);
        }

        $exam = $wpdb->get_row($wpdb->prepare(
            "SELECT e.id, e.title, e.term_id, e.class_id, e.lesson_id, e.grade, e.major,
                    l.lesson_name, c.class_name
             FROM {$exams_table} e
             INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = e.lesson_id
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = e.class_id
             WHERE e.id = %d AND e.term_id = %d AND e.status <> 'cancelled' LIMIT 1",
            $exam_id,
            $term_id
        ));
        if (!$exam) {
            wp_send_json_error(['message' => 'آزمون مقصد معتبر نیست.']);
        }
        $attempt_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hst_exam_attempts WHERE exam_id = %d",
            $exam_id
        ));
        if ($attempt_count > 0) {
            wp_send_json_error([
                'message' => 'پس از شروع پاسخ‌گویی دانش‌آموزان، فهرست سؤال‌های آزمون قابل تغییر نیست.',
            ], 409);
        }

        $saved_blueprint = get_user_meta(get_current_user_id(), 'hst_exam_question_bank_blueprint', true);
        $blueprint = is_array($saved_blueprint) ? $this->normalize_blueprint($saved_blueprint) : [];
        if ((int) ($blueprint['term_id'] ?? 0) === $term_id && !empty($blueprint['subject'])) {
            $catalog = class_exists('HST_Exam_Curriculum') ? HST_Exam_Curriculum::catalog() : [];
            $subject_entry = $catalog[(string) ($blueprint['grade'] ?? '')]['majors'][(string) ($blueprint['major'] ?? '')]['subjects'][(string) $blueprint['subject']] ?? [];
            $subject_title = (string) ($subject_entry['title'] ?? ($blueprint['subject_title'] ?? ''));
            if ((string) $exam->grade !== (string) ($blueprint['grade'] ?? '')
                || (string) $exam->major !== (string) ($blueprint['major'] ?? '')
                || !$this->subjects_match((string) $exam->lesson_name, $subject_title)) {
                wp_send_json_error(['message' => 'آزمون مقصد با پایه، رشته و درس بودجه‌بندی‌شده هماهنگ نیست.']);
            }
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $question_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, term_id, class_id, lesson_id, created_by, grade, major,
                    curriculum_subject, curriculum_chapter, curriculum_topics,
                    question_type, difficulty, score, question_text, answer_data
             FROM {$questions_table}
             WHERE term_id = %d AND id IN ({$placeholders})",
            array_merge([$term_id], $ids)
        ));
        $source_map = [];
        foreach ((array) $question_rows as $row) {
            $source_map[(int) $row->id] = $row;
        }
        if (count($source_map) !== count($ids)) {
            wp_send_json_error(['message' => 'یک یا چند سؤال انتخابی در بانک سؤال پیدا نشد.']);
        }

        foreach ($ids as $question_id) {
            $source = $source_map[$question_id];
            if ((string) $source->grade !== (string) $exam->grade
                || (string) $source->major !== (string) $exam->major) {
                wp_send_json_error(['message' => 'همه سؤال‌های انتخابی باید با پایه و رشته آزمون مقصد هماهنگ باشند.']);
            }
            if (!empty($blueprint['subject'])
                && (string) $source->curriculum_subject !== (string) $blueprint['subject']) {
                wp_send_json_error(['message' => 'یک یا چند سؤال خارج از درس بودجه‌بندی‌شده انتخاب شده است.']);
            }
        }

        $wpdb->query('START TRANSACTION');
        try {
            $resolved = [];
            $resolved_scores = [];
            $seen_target_ids = [];
            foreach ($ids as $source_id) {
                $source = $source_map[$source_id];
                $score = isset($posted_scores[(string) $source_id])
                    ? (float) $posted_scores[(string) $source_id]
                    : (float) $source->score;
                if ($score < 0.25 || $score > 100) {
                    throw new InvalidArgumentException('invalid_score');
                }

                $target_id = $this->destination_question_id($source, $exam, $questions_table);
                if ($target_id < 1) {
                    throw new RuntimeException('question_scope_failed');
                }
                // Avoid inserting the same semantic question twice when the
                // bank happens to contain per-class copies of one seed item.
                if (isset($seen_target_ids[$target_id])) {
                    continue;
                }
                $seen_target_ids[$target_id] = true;
                $resolved[] = $target_id;
                $resolved_scores[$target_id] = hst_format_grade($score, false);
            }

            if (!$resolved) {
                throw new RuntimeException('empty_resolved_questions');
            }

            $sort_order = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(MAX(sort_order), 0) FROM {$items_table} WHERE exam_id = %d",
                $exam_id
            ));
            $added = 0;
            $updated = 0;

            foreach ($resolved as $question_id) {
                $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$items_table} WHERE exam_id = %d AND question_id = %d LIMIT 1",
                    $exam_id,
                    $question_id
                ));
                $sort_order++;
                if ($existing_id > 0) {
                    $result = $wpdb->update(
                        $items_table,
                        ['sort_order' => $sort_order, 'score' => $resolved_scores[$question_id]],
                        ['id' => $existing_id],
                        ['%d', '%s'],
                        ['%d']
                    );
                    if ($result === false) {
                        throw new RuntimeException('update_failed');
                    }
                    $updated++;
                    continue;
                }

                $inserted = $wpdb->insert($items_table, [
                    'exam_id'     => $exam_id,
                    'question_id' => $question_id,
                    'sort_order'  => $sort_order,
                    'score'       => $resolved_scores[$question_id],
                    'created_at'  => current_time('mysql'),
                ], ['%d', '%d', '%d', '%s', '%s']);
                if ($inserted === false) {
                    throw new RuntimeException('insert_failed');
                }
                $added++;
            }

            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$items_table} WHERE exam_id = %d",
                $exam_id
            ));
            $total_score = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(score), 0) FROM {$items_table} WHERE exam_id = %d",
                $exam_id
            ));
            $exam_updated = $wpdb->update($exams_table, [
                'question_count' => $total,
                'updated_at'     => current_time('mysql'),
            ], ['id' => $exam_id], ['%d', '%s'], ['%d']);
            if ($exam_updated === false) {
                throw new RuntimeException('exam_update_failed');
            }

            $wpdb->query('COMMIT');
        } catch (InvalidArgumentException $error) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'بارم یکی از سؤال‌ها خارج از محدوده مجاز است.']);
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'ثبت نهایی سؤال‌ها در آزمون انجام نشد. لطفاً دوباره تلاش کنید.']);
        }

        wp_send_json_success([
            'message' => sprintf('آزمون «%s» با %d سؤال انتخابی ثبت نهایی شد.', (string) $exam->title, count($resolved)),
            'added'   => $added,
            'updated' => $updated,
            'total'   => $total,
            'total_score' => hst_format_grade($total_score, false),
            'exam_id' => $exam_id,
        ]);
    }

}
