<?php
/**
 * Lesson management for TeacherShow.
 *
 * @package TeacherShow
 */

defined('ABSPATH') || exit;

class HST_Lessons
{
    private const ALLOWED_IMPORT_FILES = ['primary', 'middle', 'high_theory'];
    private const MAX_NAME_LENGTH = 100;
    private const MAX_IMPORT_BLOCKS = 80;
    private const MAX_IMPORT_ITEMS_PER_BLOCK = 40;

    public function __construct()
    {
        add_action('wp_ajax_hst_add_lesson', [$this, 'hst_add_lesson']);
        add_action('wp_ajax_hst_delete_lesson', [$this, 'hst_delete_lesson']);
        add_action('wp_ajax_hst_update_lesson', [$this, 'hst_update_lesson']);
        add_action('wp_ajax_hst_import_lessons_by_file', [$this, 'hst_import_lessons_by_file']);
    }

    private function authorize(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
    }

    private function lessons_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_lessons';
    }

    private function normalize_name($value): string
    {
        $name = sanitize_text_field(wp_unslash($value ?? ''));
        $name = preg_replace('/\s+/u', ' ', trim($name));
        return is_string($name) ? mb_substr($name, 0, self::MAX_NAME_LENGTH) : '';
    }

    private function normalize_unit($unit): int
    {
        $unit = absint(wp_unslash($unit ?: 1));
        return max(1, min(10, $unit));
    }

    private function table_exists(string $table): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }


    private static function table_exists_static(string $table): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function dependency_count_static(string $table, string $column, int $id): int
    {
        global $wpdb;

        if (!self::table_exists_static($table)) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = %d", $id));
    }

    private static function lesson_has_operational_data_static(int $lesson_id): bool
    {
        global $wpdb;

        $checks = [
            [$wpdb->prefix . 'hst_users_lessons', 'lesson_id'],
            [$wpdb->prefix . 'hst_schedules', 'lesson_id'],
            [$wpdb->prefix . 'hst_monthly_scores', 'lesson_id'],
            [$wpdb->prefix . 'hst_assignments', 'lesson_id'],
            [$wpdb->prefix . 'hst_exams', 'lesson_id'],
        ];

        foreach ($checks as [$table, $column]) {
            if (self::dependency_count_static($table, $column, $lesson_id) > 0) {
                return true;
            }
        }

        return false;
    }

    private function class_exists(int $class_id): bool
    {
        global $wpdb;
        $classes_table = $wpdb->prefix . 'hst_classes';
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$classes_table} WHERE id = %d LIMIT 1", $class_id));
    }

    private function lesson_exists(string $lesson_name, int $class_id, int $exclude_id = 0): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->lessons_table()} WHERE lesson_name = %s AND class_id = %d AND id != %d LIMIT 1",
                $lesson_name,
                $class_id,
                $exclude_id
            )
        );
    }

    private function dependency_count(string $table, string $column, int $id): int
    {
        global $wpdb;

        if (!$this->table_exists($table)) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = %d", $id));
    }

    private function lesson_has_operational_data(int $lesson_id): bool
    {
        global $wpdb;

        $checks = [
            [$wpdb->prefix . 'hst_users_lessons', 'lesson_id'],
            [$wpdb->prefix . 'hst_schedules', 'lesson_id'],
            [$wpdb->prefix . 'hst_monthly_scores', 'lesson_id'],
            [$wpdb->prefix . 'hst_assignments', 'lesson_id'],
            [$wpdb->prefix . 'hst_exams', 'lesson_id'],
        ];

        foreach ($checks as [$table, $column]) {
            if ($this->dependency_count($table, $column, $lesson_id) > 0) {
                return true;
            }
        }

        return false;
    }

    public function hst_add_lesson(): void
    {
        $this->authorize();

        $lesson_name = $this->normalize_name($_POST['lesson_name'] ?? '');
        $class_id = HST_Guard::post_int('class_id');
        $unit = $this->normalize_unit($_POST['unit'] ?? 1);

        if ($lesson_name === '' || $class_id < 1 || !$this->class_exists($class_id)) {
            HST_Guard::fail('نام درس و کلاس معتبر الزامی است.');
        }

        if ($this->lesson_exists($lesson_name, $class_id)) {
            HST_Guard::fail('این درس برای این کلاس قبلاً ثبت شده است.');
        }

        global $wpdb;
        $insert = $wpdb->insert(
            $this->lessons_table(),
            ['lesson_name' => $lesson_name, 'class_id' => $class_id, 'unit' => $unit],
            ['%s', '%d', '%d']
        );

        if ($insert === false) {
            HST_Guard::fail('ثبت درس انجام نشد.');
        }

        wp_send_json_success(['message' => 'درس با موفقیت ثبت شد.', 'id' => (int) $wpdb->insert_id]);
    }

    public function hst_delete_lesson(): void
    {
        $this->authorize();

        $id = HST_Guard::post_int('id');
        if (!$id) {
            HST_Guard::fail('شناسه درس نامعتبر است.');
        }

        global $wpdb;
        $lesson = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$this->lessons_table()} WHERE id = %d LIMIT 1", $id));
        if (!$lesson) {
            HST_Guard::fail('درس پیدا نشد.');
        }

        if ($this->lesson_has_operational_data($id)) {
            HST_Guard::fail('این درس دارای برنامه، نمره، تکلیف، آزمون یا ارتباط کاربری است و برای جلوگیری از حذف اطلاعات مهم، قابل حذف نیست.');
        }

        $deleted = $wpdb->delete($this->lessons_table(), ['id' => $id], ['%d']);

        if ($deleted === false || $deleted === 0) {
            HST_Guard::fail('حذف درس انجام نشد.');
        }

        wp_send_json_success(['message' => 'درس با موفقیت حذف شد.']);
    }

    public function hst_update_lesson(): void
    {
        $this->authorize();

        $id = HST_Guard::post_int('id');
        $lesson_name = $this->normalize_name($_POST['lesson_name'] ?? '');
        $unit = $this->normalize_unit($_POST['unit'] ?? 1);

        if ($id < 1 || $lesson_name === '') {
            HST_Guard::fail('داده درس نامعتبر است.');
        }

        global $wpdb;
        $lesson = $wpdb->get_row($wpdb->prepare("SELECT class_id FROM {$this->lessons_table()} WHERE id = %d", $id));
        if (!$lesson) {
            HST_Guard::fail('درس پیدا نشد.');
        }

        if ($this->lesson_exists($lesson_name, (int) $lesson->class_id, $id)) {
            HST_Guard::fail('این درس برای این کلاس قبلاً ثبت شده است.');
        }

        $updated = $wpdb->update(
            $this->lessons_table(),
            ['lesson_name' => $lesson_name, 'unit' => $unit],
            ['id' => $id],
            ['%s', '%d'],
            ['%d']
        );

        if ($updated === false) {
            HST_Guard::fail('ویرایش درس انجام نشد.');
        }

        wp_send_json_success(['message' => 'درس با موفقیت ویرایش شد.']);
    }

    public function hst_import_lessons_by_file(): void
    {
        $this->authorize();

        $file = sanitize_key(wp_unslash($_POST['file'] ?? ''));

        if (!in_array($file, self::ALLOWED_IMPORT_FILES, true)) {
            HST_Guard::fail('فایل انتخاب‌شده نامعتبر است.');
        }

        if (!class_exists('HST_Classes')) {
            HST_Guard::fail('سرویس کلاس‌ها در دسترس نیست.');
        }

        $class_result = HST_Classes::ensure_from_file($file);
        if (is_wp_error($class_result)) {
            HST_Guard::fail($class_result->get_error_message());
        }

        global $wpdb;
        $classes_table = $wpdb->prefix . 'hst_classes';
        $lessons_table = $this->lessons_table();
        $path = trailingslashit(HST_PATH) . "assets/js/lessons/{$file}.json";

        if (!is_readable($path)) {
            HST_Guard::fail('فایل ایمپورت پیدا نشد.');
        }

        $json = json_decode((string) file_get_contents($path), true);

        if (!is_array($json) || empty($json['lessons']) || !is_array($json['lessons'])) {
            HST_Guard::fail('ساختار فایل JSON نامعتبر است.');
        }

        $inserted = 0;
        $skipped = 0;
        $missing = 0;
        $blocks = array_slice($json['lessons'], 0, self::MAX_IMPORT_BLOCKS);

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                $missing++;
                continue;
            }

            $class_slug = sanitize_title($block['class_slug'] ?? '');
            if (!$class_slug || empty($block['items']) || !is_array($block['items'])) {
                $missing++;
                continue;
            }

            $class_id = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$classes_table} WHERE class_slug = %s LIMIT 1", $class_slug)
            );

            if (!$class_id) {
                $missing++;
                continue;
            }

            $items = array_slice($block['items'], 0, self::MAX_IMPORT_ITEMS_PER_BLOCK);
            foreach ($items as $lesson) {
                if (!is_array($lesson)) {
                    $skipped++;
                    continue;
                }

                $lesson_name = $this->normalize_name($lesson['lesson_name'] ?? '');
                $unit = $this->normalize_unit($lesson['unit'] ?? 1);

                if ($lesson_name === '' || $this->lesson_exists($lesson_name, $class_id)) {
                    $skipped++;
                    continue;
                }

                $result = $wpdb->insert(
                    $lessons_table,
                    ['lesson_name' => $lesson_name, 'class_id' => $class_id, 'unit' => $unit],
                    ['%s', '%d', '%d']
                );

                if ($result === false) {
                    HST_Guard::fail('ثبت خودکار یکی از دروس متوسطه دوم انجام نشد.');
                }

                $inserted++;
            }
        }

        wp_send_json_success([
            'message'         => sprintf(
                'دروس متوسطه دوم بررسی شدند؛ %1$d درس و %2$d کلاس لازم اضافه شد و %3$d درس از قبل موجود بود.',
                $inserted,
                (int) ($class_result['inserted'] ?? 0),
                $skipped
            ),
            'inserted'        => $inserted,
            'skipped'         => $skipped,
            'missing_classes' => $missing,
            'inserted_classes'=> (int) ($class_result['inserted'] ?? 0),
        ]);
    }

    /**
     * Fetch a lesson's class_id + unit by id, optionally constrained to a
     * given class. Returns the row object or null.
     *
     * @param int $lesson_id
     * @param int $class_id  0 = no class constraint.
     * @return object|null
     */
    public static function scope($lesson_id, $class_id = 0)
    {
        global $wpdb;
        $lesson_id = absint($lesson_id);
        if (!$lesson_id) {
            return null;
        }
        $class_id = absint($class_id);
        if ($class_id) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT class_id, unit FROM {$wpdb->prefix}hst_lessons WHERE id = %d AND class_id = %d",
                    $lesson_id,
                    $class_id
                )
            );
        }
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT class_id, unit FROM {$wpdb->prefix}hst_lessons WHERE id = %d",
                $lesson_id
            )
        );
    }

    /**
     * All lessons with their class name, ordered by grade, study field and insertion order.
     *
     * @param int $limit Optional cap (0 = no limit).
     * @return array
     */
    public static function all($limit = 0)
    {
        global $wpdb;
        $lessons = $wpdb->prefix . 'hst_lessons';
        $classes = $wpdb->prefix . 'hst_classes';
        $class_order = HST_Classes::sql_order_by('c.class_name', 'c.id');
        $sql = "SELECT l.id, l.lesson_name, l.class_id, l.unit, c.class_name
                FROM {$lessons} l
                INNER JOIN {$classes} c ON l.class_id = c.id
                ORDER BY {$class_order}, l.id ASC";
        if ($limit > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d", $limit);
        }
        $results = $wpdb->get_results($sql);
        $results = is_array($results) ? $results : [];

        foreach ($results as $row) {
            $blocked = self::lesson_has_operational_data_static((int) $row->id);
            $row->can_delete = $blocked ? 0 : 1;
            $row->delete_disabled_reason = $blocked ? 'این درس دارای برنامه، نمره، تکلیف، آزمون یا ارتباط کاربری است و قابل حذف نیست.' : '';
        }

        return $results;
    }
}
