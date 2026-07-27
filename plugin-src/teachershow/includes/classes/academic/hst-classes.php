<?php
/**
 * Class management for TeacherShow.
 *
 * @package TeacherShow
 */

defined('ABSPATH') || exit;

class HST_Classes
{
    private const ALLOWED_IMPORT_FILES = ['primary', 'middle', 'high_theory'];
    private const MAX_NAME_LENGTH = 80;
    private const MAX_IMPORT_ITEMS = 80;

    public function __construct()
    {
        add_action('wp_ajax_hst_add_class', [$this, 'hst_add_class']);
        add_action('wp_ajax_hst_delete_class', [$this, 'hst_delete_class']);
        add_action('wp_ajax_hst_update_class', [$this, 'hst_update_class']);
        add_action('wp_ajax_hst_import_classes_by_file', [$this, 'hst_import_classes_by_file']);
    }

    private function authorize(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
    }

    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_classes';
    }

    private function normalize_name($value): string
    {
        $name = sanitize_text_field(wp_unslash($value ?? ''));
        $name = preg_replace('/\s+/u', ' ', trim($name));
        return is_string($name) ? mb_substr($name, 0, self::MAX_NAME_LENGTH) : '';
    }

    private function class_exists(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table()} WHERE id = %d LIMIT 1", $id));
    }

    private function table_exists(string $table): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private function dependency_count(string $table, string $column, int $id): int
    {
        global $wpdb;

        if (!$this->table_exists($table)) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = %d", $id)
        );
    }

    private function class_has_operational_data(int $class_id): bool
    {
        global $wpdb;

        $checks = [
            [$wpdb->prefix . 'hst_users_classes', 'class_id'],
            [$wpdb->prefix . 'hst_users_lessons', 'class_id'],
            [$wpdb->prefix . 'hst_lessons', 'class_id'],
            [$wpdb->prefix . 'hst_schedules', 'class_id'],
            [$wpdb->prefix . 'hst_attendance_records', 'class_id'],
            [$wpdb->prefix . 'hst_assignments', 'class_id'],
            [$wpdb->prefix . 'hst_exams', 'class_id'],
            [$wpdb->prefix . 'hst_exam_attempts', 'class_id'],
            [$wpdb->prefix . 'hst_tuition_plans', 'class_id'],
            [$wpdb->prefix . 'hst_tuition_invoices', 'class_id'],
        ];

        foreach ($checks as [$table, $column]) {
            if ($this->dependency_count($table, $column, $class_id) > 0) {
                return true;
            }
        }

        return false;
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

    private static function class_has_operational_data_static(int $class_id): bool
    {
        global $wpdb;

        $checks = [
            [$wpdb->prefix . 'hst_users_classes', 'class_id'],
            [$wpdb->prefix . 'hst_users_lessons', 'class_id'],
            [$wpdb->prefix . 'hst_lessons', 'class_id'],
            [$wpdb->prefix . 'hst_schedules', 'class_id'],
            [$wpdb->prefix . 'hst_attendance_records', 'class_id'],
            [$wpdb->prefix . 'hst_assignments', 'class_id'],
            [$wpdb->prefix . 'hst_exams', 'class_id'],
            [$wpdb->prefix . 'hst_exam_attempts', 'class_id'],
            [$wpdb->prefix . 'hst_tuition_plans', 'class_id'],
            [$wpdb->prefix . 'hst_tuition_invoices', 'class_id'],
        ];

        foreach ($checks as [$table, $column]) {
            if (self::dependency_count_static($table, $column, $class_id) > 0) {
                return true;
            }
        }

        return false;
    }

    private function unique_slug(string $name, int $exclude_id = 0): string
    {
        global $wpdb;

        $base = sanitize_title($name);
        $base = $base ?: 'class';
        $slug = $base;
        $i = 2;

        do {
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table()} WHERE class_slug = %s AND id != %d LIMIT 1",
                    $slug,
                    $exclude_id
                )
            );

            if (!$exists) {
                return $slug;
            }

            $slug = $base . '-' . $i++;
        } while ($i < 200);

        return $base . '-' . wp_generate_password(6, false, false);
    }

    public function hst_import_classes_by_file(): void
    {
        $this->authorize();

        $file = sanitize_key(wp_unslash($_POST['file'] ?? ''));
        $result = self::ensure_from_file($file);

        if (is_wp_error($result)) {
            HST_Guard::fail($result->get_error_message());
        }

        wp_send_json_success([
            'message'       => sprintf(
                'کلاس‌های متوسطه دوم بررسی شدند؛ %1$d کلاس اضافه شد و %2$d مورد از قبل موجود بود.',
                (int) ($result['inserted'] ?? 0),
                (int) ($result['skipped'] ?? 0)
            ),
            'inserted'      => (int) ($result['inserted'] ?? 0),
            'skipped'       => (int) ($result['skipped'] ?? 0),
            'updated_slugs' => (int) ($result['updated_slugs'] ?? 0),
        ]);
    }

    /**
     * Insert the classes declared in one bundled defaults file.
     *
     * Existing rows are reused by canonical slug or exact name, so running the
     * import repeatedly never creates duplicate classes. When a matching class
     * has an older generated slug, it is safely aligned with the canonical
     * bundled slug so its default lessons can be attached to the same row.
     *
     * @return array|WP_Error
     */
    public static function ensure_from_file(string $file)
    {
        $file = sanitize_key($file);

        if (!in_array($file, self::ALLOWED_IMPORT_FILES, true)) {
            return new WP_Error('hst_invalid_class_defaults', 'فایل انتخاب‌شده نامعتبر است.');
        }

        $path = trailingslashit(HST_PATH) . "assets/js/classes/{$file}.json";

        if (!is_readable($path)) {
            return new WP_Error('hst_missing_class_defaults', 'فایل کلاس‌های پیش‌فرض پیدا نشد.');
        }

        $json = json_decode((string) file_get_contents($path), true);

        if (!is_array($json) || empty($json['classes']) || !is_array($json['classes'])) {
            return new WP_Error('hst_invalid_class_defaults_json', 'ساختار فایل کلاس‌های پیش‌فرض نامعتبر است.');
        }

        $classes = array_slice($json['classes'], 0, self::MAX_IMPORT_ITEMS);

        global $wpdb;
        $table = $wpdb->prefix . 'hst_classes';
        $inserted = 0;
        $skipped = 0;
        $updated_slugs = 0;

        foreach ($classes as $class) {
            if (!is_array($class)) {
                $skipped++;
                continue;
            }

            $name = sanitize_text_field((string) ($class['class_name'] ?? ''));
            $name = preg_replace('/\s+/u', ' ', trim($name));
            $name = is_string($name) ? mb_substr($name, 0, self::MAX_NAME_LENGTH) : '';
            if ($name === '') {
                $skipped++;
                continue;
            }

            $slug = sanitize_title((string) ($class['class_slug'] ?? ''));
            if ($slug === '') {
                $skipped++;
                continue;
            }

            $slug_match = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$table} WHERE class_slug = %s LIMIT 1", $slug)
            );

            if ($slug_match) {
                $skipped++;
                continue;
            }

            $name_match = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$table} WHERE class_name = %s LIMIT 1", $name)
            );

            if ($name_match) {
                $updated = $wpdb->update(
                    $table,
                    ['class_slug' => $slug],
                    ['id' => $name_match],
                    ['%s'],
                    ['%d']
                );
                if ($updated === false) {
                    return new WP_Error('hst_update_class_slug_failed', 'هماهنگ‌سازی شناسه کلاس موجود انجام نشد.');
                }
                if ($updated > 0) {
                    $updated_slugs++;
                }
                $skipped++;
                continue;
            }

            $result = $wpdb->insert(
                $table,
                ['class_name' => $name, 'class_slug' => $slug],
                ['%s', '%s']
            );

            if ($result === false) {
                return new WP_Error('hst_insert_default_class_failed', 'ثبت خودکار یکی از کلاس‌های متوسطه دوم انجام نشد.');
            }

            $inserted++;
        }

        return [
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'updated_slugs' => $updated_slugs,
        ];
    }

    public function hst_add_class(): void
    {
        $this->authorize();

        $class_name = $this->normalize_name($_POST['class_name'] ?? '');

        if ($class_name === '') {
            HST_Guard::fail('نام کلاس الزامی است.');
        }

        global $wpdb;
        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$this->table()} WHERE class_name = %s LIMIT 1", $class_name)
        );

        if ($exists) {
            HST_Guard::fail('این کلاس قبلاً ثبت شده است.');
        }

        $insert = $wpdb->insert(
            $this->table(),
            ['class_name' => $class_name, 'class_slug' => $this->unique_slug($class_name)],
            ['%s', '%s']
        );

        if ($insert === false) {
            HST_Guard::fail('خطا در ثبت کلاس.');
        }

        wp_send_json_success(['message' => 'کلاس با موفقیت ثبت شد.', 'id' => (int) $wpdb->insert_id]);
    }

    public function hst_delete_class(): void
    {
        $this->authorize();

        $id = HST_Guard::post_int('id');
        if (!$id || !$this->class_exists($id)) {
            HST_Guard::fail('شناسه کلاس نامعتبر است.');
        }

        if ($this->class_has_operational_data($id)) {
            HST_Guard::fail('این کلاس دارای درس، کاربر یا داده عملیاتی است و برای جلوگیری از حذف اطلاعات مهم، قابل حذف نیست.');
        }

        global $wpdb;
        $deleted = $wpdb->delete($this->table(), ['id' => $id], ['%d']);

        if ($deleted === false || $deleted === 0) {
            HST_Guard::fail('حذف کلاس انجام نشد.');
        }

        wp_send_json_success(['message' => 'کلاس با موفقیت حذف شد.']);
    }

    public function hst_update_class(): void
    {
        $this->authorize();

        $id = HST_Guard::post_int('id');
        $name = $this->normalize_name($_POST['class_name'] ?? '');

        if (!$id || $name === '' || !$this->class_exists($id)) {
            HST_Guard::fail('اطلاعات کلاس کامل یا معتبر نیست.');
        }

        global $wpdb;
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table()} WHERE class_name = %s AND id != %d LIMIT 1",
                $name,
                $id
            )
        );

        if ($exists) {
            HST_Guard::fail('این نام قبلاً ثبت شده است.');
        }

        $updated = $wpdb->update(
            $this->table(),
            ['class_name' => $name, 'class_slug' => $this->unique_slug($name, $id)],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            HST_Guard::fail('ویرایش کلاس انجام نشد.');
        }

        wp_send_json_success(['message' => 'کلاس با موفقیت ویرایش شد.']);
    }

    /**
     * Normalize a class name for deterministic Persian-aware comparisons.
     */
    private static function normalize_sort_name($value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        $text = strtr($text, [
            'ي' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $text = preg_replace('/[\x{200c}\x{200f}\x{200e}\x{202a}-\x{202e}]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);

        return trim((string) $text);
    }

    /**
     * Detect the normalized academic grade and study field represented by a
     * class name. Numeric names such as 101, 111 and 121 are supported too.
     *
     * @return array{grade:string, major:string, grade_rank:int, major_rank:int}
     */
    public static function academic_profile($class_name): array
    {
        $text = self::normalize_sort_name($class_name);
        if ($text === '') {
            return ['grade' => '', 'major' => '', 'grade_rank' => 99, 'major_rank' => 99];
        }

        $grade = '';
        $grade_rank = 99;
        if (strpos($text, 'دوازدهم') !== false) {
            $grade = 'twelfth';
            $grade_rank = 12;
        } elseif (strpos($text, 'یازدهم') !== false) {
            $grade = 'eleventh';
            $grade_rank = 11;
        } elseif (strpos($text, 'دهم') !== false) {
            $grade = 'tenth';
            $grade_rank = 10;
        } elseif (preg_match('/(?:^|[^0-9])(12|11|10)[0-9]?(?:[^0-9]|$)/u', $text, $matches)) {
            $grade_rank = (int) $matches[1];
            $grade = [10 => 'tenth', 11 => 'eleventh', 12 => 'twelfth'][$grade_rank] ?? '';
        }

        $major = '';
        $major_rank = 99;
        if (strpos($text, 'ریاضی') !== false || strpos($text, 'فیزیک') !== false) {
            $major = 'math';
            $major_rank = 1;
        } elseif (strpos($text, 'تجربی') !== false) {
            $major = 'experimental';
            $major_rank = 2;
        } elseif (strpos($text, 'انسانی') !== false || strpos($text, 'ادبیات') !== false) {
            $major = 'humanities';
            $major_rank = 3;
        }

        return compact('grade', 'major', 'grade_rank', 'major_rank');
    }

    /** Compare two class names using the plugin-wide academic order. */
    public static function compare_names($left, $right): int
    {
        $left_name = self::normalize_sort_name($left);
        $right_name = self::normalize_sort_name($right);
        $left_profile = self::academic_profile($left_name);
        $right_profile = self::academic_profile($right_name);

        $grade_compare = $left_profile['grade_rank'] <=> $right_profile['grade_rank'];
        if ($grade_compare !== 0) {
            return $grade_compare;
        }

        $major_compare = $left_profile['major_rank'] <=> $right_profile['major_rank'];
        if ($major_compare !== 0) {
            return $major_compare;
        }

        return strnatcasecmp($left_name, $right_name);
    }

    /** Sort a simple list of class names and reset its numeric indexes. */
    public static function sort_names(array $names): array
    {
        usort($names, [self::class, 'compare_names']);
        return array_values($names);
    }

    /** Sort associative select options while preserving their IDs as keys. */
    public static function sort_options(array $options): array
    {
        uasort($options, [self::class, 'compare_names']);
        return $options;
    }

    /**
     * Sort rows containing a class name, optionally using secondary fields
     * after the class order. Rows may be arrays or objects.
     */
    public static function sort_rows(array $rows, string $class_key = 'class_name', array $secondary_keys = []): array
    {
        $read = static function ($row, string $key) {
            if (is_array($row)) {
                return $row[$key] ?? '';
            }
            if (is_object($row)) {
                return $row->{$key} ?? '';
            }
            return '';
        };

        usort($rows, static function ($left, $right) use ($read, $class_key, $secondary_keys): int {
            $class_compare = self::compare_names($read($left, $class_key), $read($right, $class_key));
            if ($class_compare !== 0) {
                return $class_compare;
            }

            foreach ($secondary_keys as $key) {
                $compare = strnatcasecmp(
                    self::normalize_sort_name($read($left, (string) $key)),
                    self::normalize_sort_name($read($right, (string) $key))
                );
                if ($compare !== 0) {
                    return $compare;
                }
            }

            return 0;
        });

        return array_values($rows);
    }

    /**
     * SQL ORDER BY fragment matching compare_names(). Identifiers are limited
     * to trusted alphanumeric aliases because this helper is only for internal
     * query construction.
     */
    public static function sql_order_by(string $class_column = 'class_name', string $id_column = ''): string
    {
        $valid_identifier = static function (string $identifier): bool {
            return (bool) preg_match('/^[A-Za-z0-9_.]+$/', $identifier);
        };

        if (!$valid_identifier($class_column)) {
            $class_column = 'class_name';
        }
        if ($id_column !== '' && !$valid_identifier($id_column)) {
            $id_column = '';
        }

        $grade_order = "CASE
            WHEN {$class_column} LIKE '%دوازدهم%'
              OR {$class_column} REGEXP '(^|[^0-9])12[0-9]?([^0-9]|$)'
              OR {$class_column} LIKE '%۱۲%'
              OR {$class_column} LIKE '%١٢%' THEN 12
            WHEN {$class_column} LIKE '%یازدهم%'
              OR {$class_column} LIKE '%يازدهم%'
              OR {$class_column} REGEXP '(^|[^0-9])11[0-9]?([^0-9]|$)'
              OR {$class_column} LIKE '%۱۱%'
              OR {$class_column} LIKE '%١١%' THEN 11
            WHEN {$class_column} LIKE '%دهم%'
              OR {$class_column} REGEXP '(^|[^0-9])10[0-9]?([^0-9]|$)'
              OR {$class_column} LIKE '%۱۰%'
              OR {$class_column} LIKE '%١٠%' THEN 10
            ELSE 99
        END ASC";

        $major_order = "CASE
            WHEN {$class_column} LIKE '%ریاضی%' OR {$class_column} LIKE '%رياض%' OR {$class_column} LIKE '%فیزیک%' THEN 1
            WHEN {$class_column} LIKE '%تجربی%' THEN 2
            WHEN {$class_column} LIKE '%انسانی%' OR {$class_column} LIKE '%ادبیات%' THEN 3
            ELSE 99
        END ASC";

        $parts = [$grade_order, $major_order, "{$class_column} ASC"];
        if ($id_column !== '') {
            $parts[] = "{$id_column} ASC";
        }

        return implode(', ', $parts);
    }

    /**
     * All classes (id + name), ordered by grade and then study field. Read API used by renderers and
     * other modules instead of querying the table directly.
     *
     * @param int $limit Optional cap (0 = no limit).
     * @return array
     */
    public static function all($limit = 0)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_classes';
        $order = self::sql_order_by('class_name', 'id');
        $sql = "SELECT id, class_name FROM {$table} ORDER BY {$order}";
        if ($limit > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d", $limit);
        }
        $results = $wpdb->get_results($sql);
        $results = is_array($results) ? $results : [];
        $results = self::sort_rows($results, 'class_name', ['id']);

        foreach ($results as $row) {
            $blocked = self::class_has_operational_data_static((int) $row->id);
            $row->can_delete = $blocked ? 0 : 1;
            $row->delete_disabled_reason = $blocked ? 'این کلاس دارای درس، کاربر یا داده عملیاتی است و قابل حذف نیست.' : '';
        }

        return $results;
    }

    /** Whether a class with the given id exists. */
    public static function exists($class_id)
    {
        global $wpdb;
        $class_id = absint($class_id);
        if (!$class_id) {
            return false;
        }
        return (bool) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$wpdb->prefix}hst_classes WHERE id = %d LIMIT 1", $class_id)
        );
    }

    /**
     * Backward-compatible read API for selectors and lists. Class names are
     * always returned in the same academic order as all().
     *
     * @return array
     */
    public static function all_by_name()
    {
        global $wpdb;
        $order = self::sql_order_by('class_name', 'id');
        $results = $wpdb->get_results(
            "SELECT id, class_name FROM {$wpdb->prefix}hst_classes ORDER BY {$order}"
        );
        $results = is_array($results) ? $results : [];

        return self::sort_rows($results, 'class_name', ['id']);
    }
}
