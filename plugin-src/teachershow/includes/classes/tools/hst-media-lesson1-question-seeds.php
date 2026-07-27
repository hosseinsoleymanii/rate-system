<?php

defined('ABSPATH') || exit;

/**
 * Reviewed question bank for Grade 10 Thinking and Media Literacy, lesson one.
 *
 * Unlike the removed generic generators, this bank is a finite, manually
 * authored set whose answers and marking guides are traceable to the 1404
 * textbook. It is installed once per active term on one canonical Media Literacy
 * lesson; exam injection can transparently clone a selected question into a
 * compatible destination class when necessary.
 */
final class HST_Media_Lesson1_Question_Seeds
{
    private const SUBJECT = 'g10-media';
    private const CHAPTER = 'c1';
    private const TOPIC = 'c1-t1';
    private const BANK_ID = 'media-lesson1-1404';
    private const DATA_REVISION = '1404-02';
    private const EXPECTED_COUNT = 40;
    private const TYPES = ['multiple_choice', 'fill_blank', 'true_false', 'short_answer', 'essay'];
    private const DIFFICULTIES = ['easy', 'medium', 'hard', 'conceptual'];

    private static ?array $questions = null;

    public static function expected_count(): int
    {
        return self::EXPECTED_COUNT;
    }

    /**
     * @return array{matched:int,seeded:int,count:int,complete:bool,canonical_lesson_id:int}
     */
    public static function seed_scope(int $term_id, string $grade, string $major): array
    {
        global $wpdb;

        $result = [
            'matched' => 0,
            'seeded' => 0,
            'count' => self::EXPECTED_COUNT,
            'complete' => true,
            'canonical_lesson_id' => 0,
        ];

        if ($term_id < 1 || $grade !== 'tenth' || !in_array($major, ['experimental', 'math', 'humanities'], true)) {
            return $result;
        }

        $rows = $wpdb->get_results(
            "SELECT l.id, l.lesson_name, l.class_id, c.class_name
             FROM {$wpdb->prefix}hst_lessons l
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = l.class_id
             ORDER BY l.id ASC"
        );

        $matches = [];
        foreach ((array) $rows as $row) {
            $profile = class_exists('HST_Exams')
                ? HST_Exams::class_academic_profile((string) $row->class_name)
                : ['grade' => '', 'major' => ''];

            if (($profile['grade'] ?? '') !== $grade || ($profile['major'] ?? '') !== $major) {
                continue;
            }
            if (!self::is_media_literacy((string) $row->lesson_name)) {
                continue;
            }

            $matches[] = [
                'lesson_id' => (int) $row->id,
                'class_id' => (int) $row->class_id,
                'grade' => $grade,
                'major' => $major,
            ];
        }

        $result['matched'] = count($matches);
        if (!$matches) {
            $result['complete'] = false;
            return $result;
        }

        // One canonical copy keeps the visible bank free of per-class duplicates.
        $canonical = $matches[0];
        $result['canonical_lesson_id'] = (int) $canonical['lesson_id'];
        $seeded = self::seed_lesson($term_id, $canonical);
        if ($seeded < 0) {
            $result['complete'] = false;
        } else {
            $result['seeded'] = $seeded;
        }

        return $result;
    }

    /**
     * Return only a fully validated bank. An empty array deliberately blocks
     * partial or malformed seed data from reaching the database.
     */
    public static function questions(): array
    {
        if (self::$questions !== null) {
            return self::$questions;
        }

        $records = require HST_PATH . 'includes/data/hst-media-lesson1-question-bank.php';
        if (!is_array($records) || count($records) !== self::EXPECTED_COUNT) {
            self::$questions = [];
            return self::$questions;
        }

        $seen_ids = [];
        $seen_texts = [];
        $distribution = [];
        $validated = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                self::$questions = [];
                return self::$questions;
            }

            $id = sanitize_key((string) ($record['id'] ?? ''));
            $type = sanitize_key((string) ($record['type'] ?? ''));
            $difficulty = sanitize_key((string) ($record['difficulty'] ?? ''));
            $text = trim((string) ($record['text'] ?? ''));
            $score = (float) ($record['score'] ?? 0);
            $answer_data = is_array($record['answer_data'] ?? null) ? $record['answer_data'] : [];

            if ($id === '' || isset($seen_ids[$id]) || $text === '' || isset($seen_texts[$text])) {
                self::$questions = [];
                return self::$questions;
            }
            if (!in_array($type, self::TYPES, true) || !in_array($difficulty, self::DIFFICULTIES, true)) {
                self::$questions = [];
                return self::$questions;
            }
            if ($score < 0.25 || $score > 100 || !self::valid_answer($type, $answer_data)) {
                self::$questions = [];
                return self::$questions;
            }
            if (($record['chapter'] ?? '') !== self::CHAPTER || ($record['topic'] ?? '') !== self::TOPIC) {
                self::$questions = [];
                return self::$questions;
            }
            if (($answer_data['bank_id'] ?? '') !== self::BANK_ID
                || ($answer_data['bank_revision'] ?? '') !== self::DATA_REVISION
                || trim((string) ($answer_data['source_book'] ?? '')) === ''
                || empty($answer_data['source_pages'])
                || trim((string) ($answer_data['source_basis'] ?? '')) === '') {
                self::$questions = [];
                return self::$questions;
            }

            if ($type === 'fill_blank' && class_exists('HST_Exam_Questions')) {
                $text = HST_Exam_Questions::fit_blank_placeholders(
                    $text,
                    (array) ($answer_data['answers'] ?? [])
                );
            }

            $seen_ids[$id] = true;
            $seen_texts[$text] = true;
            $distribution[$type][$difficulty] = ($distribution[$type][$difficulty] ?? 0) + 1;
            $validated[] = [
                'id' => $id,
                'chapter' => self::CHAPTER,
                'topic' => self::TOPIC,
                'type' => $type,
                'difficulty' => $difficulty,
                'score' => $score,
                'text' => $text,
                'answer_data' => $answer_data,
            ];
        }

        foreach (self::TYPES as $type) {
            foreach (self::DIFFICULTIES as $difficulty) {
                if (($distribution[$type][$difficulty] ?? 0) !== 2) {
                    self::$questions = [];
                    return self::$questions;
                }
            }
        }

        self::$questions = $validated;
        return self::$questions;
    }

    private static function valid_answer(string $type, array $data): bool
    {
        if ($type === 'multiple_choice') {
            $choices = array_values((array) ($data['choices'] ?? []));
            $correct = isset($data['correct_index']) ? (int) $data['correct_index'] : -1;
            return count($choices) === 4
                && count(array_filter($choices, static fn($choice): bool => trim((string) $choice) !== '')) === 4
                && $correct >= 0
                && $correct < 4;
        }

        if ($type === 'fill_blank') {
            $answers = array_values((array) ($data['answers'] ?? []));
            return !empty($answers)
                && count(array_filter($answers, static fn($answer): bool => trim((string) $answer) !== '')) === count($answers);
        }

        if ($type === 'true_false') {
            return in_array((string) ($data['correct'] ?? ''), ['true', 'false'], true);
        }

        if ($type === 'short_answer') {
            return trim((string) ($data['answer'] ?? '')) !== '';
        }

        return trim((string) ($data['guide'] ?? '')) !== '';
    }

    private static function seed_lesson(int $term_id, array $lesson): int
    {
        global $wpdb;

        $lesson_id = (int) ($lesson['lesson_id'] ?? 0);
        $class_id = (int) ($lesson['class_id'] ?? 0);
        if ($lesson_id < 1 || $class_id < 1) {
            return -1;
        }

        $questions = self::questions();
        if (count($questions) !== self::EXPECTED_COUNT) {
            return -1;
        }

        $checksum = hash('sha256', wp_json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $option_key = sprintf(
            'hst_media_l1_bank_%s_%d_%s',
            str_replace('-', '_', self::DATA_REVISION),
            $term_id,
            sanitize_key((string) ($lesson['major'] ?? ''))
        );
        $marker = get_option($option_key, []);

        $table = $wpdb->prefix . 'hst_exam_questions';
        $items_table = $wpdb->prefix . 'hst_exam_question_items';
        $bank_like = '%"bank_id":"' . self::BANK_ID . '"%';

        $existing_ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE term_id = %d AND lesson_id = %d AND created_by = 0
               AND curriculum_subject = %s AND curriculum_chapter = %s
               AND curriculum_topics LIKE %s AND answer_data LIKE %s
             ORDER BY id ASC",
            $term_id,
            $lesson_id,
            self::SUBJECT,
            self::CHAPTER,
            '%' . $wpdb->esc_like('"' . self::TOPIC . '"') . '%',
            $bank_like
        )));

        if (is_array($marker)
            && (int) ($marker['count'] ?? 0) === self::EXPECTED_COUNT
            && hash_equals((string) ($marker['checksum'] ?? ''), $checksum)
            && count($existing_ids) === self::EXPECTED_COUNT) {
            return 0;
        }

        $preserve_ids = count($existing_ids) === self::EXPECTED_COUNT;
        if (!$preserve_ids && $existing_ids) {
            $placeholders = implode(',', array_fill(0, count($existing_ids), '%d'));
            $usage_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$items_table} WHERE question_id IN ({$placeholders})",
                $existing_ids
            ));
            if ($usage_count > 0) {
                return -1;
            }
        }

        $wpdb->query('START TRANSACTION');
        if (!$preserve_ids && $existing_ids) {
            $placeholders = implode(',', array_fill(0, count($existing_ids), '%d'));
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE id IN ({$placeholders})",
                $existing_ids
            ));
            if ($deleted === false) {
                $wpdb->query('ROLLBACK');
                return -1;
            }
        }

        $created_at = current_time('mysql');
        $processed = 0;
        foreach ($questions as $index => $question) {
            $data = [
                'term_id' => $term_id,
                'class_id' => $class_id,
                'lesson_id' => $lesson_id,
                'created_by' => 0,
                'grade' => (string) $lesson['grade'],
                'major' => (string) $lesson['major'],
                'curriculum_subject' => self::SUBJECT,
                'curriculum_chapter' => self::CHAPTER,
                'curriculum_topics' => wp_json_encode([self::TOPIC], JSON_UNESCAPED_UNICODE),
                'question_type' => (string) $question['type'],
                'difficulty' => (string) $question['difficulty'],
                'score' => (float) $question['score'],
                'question_text' => (string) $question['text'],
                'answer_data' => wp_json_encode($question['answer_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $created_at,
                'updated_at' => $preserve_ids ? $created_at : null,
            ];

            if ($preserve_ids) {
                $written = $wpdb->update(
                    $table,
                    $data,
                    ['id' => $existing_ids[$index]],
                    ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s'],
                    ['%d']
                );
            } else {
                unset($data['updated_at']);
                $written = $wpdb->insert(
                    $table,
                    $data,
                    ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s']
                );
            }

            if ($written === false) {
                $wpdb->query('ROLLBACK');
                return -1;
            }
            $processed++;
        }

        if ($processed !== self::EXPECTED_COUNT) {
            $wpdb->query('ROLLBACK');
            return -1;
        }

        $wpdb->query('COMMIT');
        update_option($option_key, [
            'count' => self::EXPECTED_COUNT,
            'revision' => self::DATA_REVISION,
            'checksum' => $checksum,
            'canonical_lesson_id' => $lesson_id,
            'canonical_class_id' => $class_id,
            'seeded_at' => $created_at,
        ], false);

        return $processed;
    }

    private static function is_media_literacy(string $name): bool
    {
        if (class_exists('HST_Date')) {
            $name = HST_Date::en_digits($name);
        }
        $name = str_replace(['ي', 'ك', 'ۀ', 'ة', "\u{200C}"], ['ی', 'ک', 'ه', 'ه', ' '], $name);
        $name = preg_replace('/[\x{200D}\x{200E}\x{200F}\x{202A}-\x{202E}\(\)\[\]،,:؛\-–—]/u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return (bool) preg_match('/^(?:(?:تفکر\s+و\s+)?سواد\s+رسانه\s*ای|درس\s+انتخابی)$/u', $name);
    }
}
