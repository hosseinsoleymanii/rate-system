<?php
/**
 * Term management for TeacherShow.
 *
 * @package TeacherShow
 */

defined('ABSPATH') || exit;

class HST_Terms
{
    private const MAX_NAME_LENGTH = 80;

    public function __construct()
    {
        add_action('wp_ajax_hst_add_term', [$this, 'hst_add_term']);
        add_action('wp_ajax_hst_delete_term', [$this, 'hst_delete_term']);
        add_action('wp_ajax_hst_update_term', [$this, 'hst_update_term']);
        add_action('wp_ajax_hst_toggle_term_status', [$this, 'hst_toggle_term_status']);
    }

    private function authorize(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
    }

    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_terms';
    }

    private function normalize_name($value): string
    {
        $name = sanitize_text_field(wp_unslash($value ?? ''));
        $name = preg_replace('/\s+/u', ' ', trim($name));
        return is_string($name) ? mb_substr($name, 0, self::MAX_NAME_LENGTH) : '';
    }

    private function term_exists(int $id): bool
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

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = %d", $id));
    }

    private function term_has_operational_data(int $term_id): bool
    {
        global $wpdb;

        $checks = [
            [$wpdb->prefix . 'hst_users_classes', 'term_id'],
            [$wpdb->prefix . 'hst_users_lessons', 'term_id'],
            [$wpdb->prefix . 'hst_users_availability', 'term_id'],
            [$wpdb->prefix . 'hst_schedules', 'term_id'],
            [$wpdb->prefix . 'hst_monthly_scores', 'term_id'],
            [$wpdb->prefix . 'hst_score_periods', 'term_id'],
            [$wpdb->prefix . 'hst_attendance_records', 'term_id'],
            [$wpdb->prefix . 'hst_assignments', 'term_id'],
            [$wpdb->prefix . 'hst_exams', 'term_id'],
            [$wpdb->prefix . 'hst_exam_attempts', 'term_id'],
            [$wpdb->prefix . 'hst_tuition_invoices', 'term_id'],
        ];

        foreach ($checks as [$table, $column]) {
            if ($this->dependency_count($table, $column, $term_id) > 0) {
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

    private static function term_has_operational_data_static(int $term_id): bool
    {
        global $wpdb;

        $checks = [
            [$wpdb->prefix . 'hst_users_classes', 'term_id'],
            [$wpdb->prefix . 'hst_users_lessons', 'term_id'],
            [$wpdb->prefix . 'hst_users_availability', 'term_id'],
            [$wpdb->prefix . 'hst_schedules', 'term_id'],
            [$wpdb->prefix . 'hst_monthly_scores', 'term_id'],
            [$wpdb->prefix . 'hst_score_periods', 'term_id'],
            [$wpdb->prefix . 'hst_attendance_records', 'term_id'],
            [$wpdb->prefix . 'hst_assignments', 'term_id'],
            [$wpdb->prefix . 'hst_exams', 'term_id'],
            [$wpdb->prefix . 'hst_exam_attempts', 'term_id'],
            [$wpdb->prefix . 'hst_tuition_invoices', 'term_id'],
        ];

        foreach ($checks as [$table, $column]) {
            if (self::dependency_count_static($table, $column, $term_id) > 0) {
                return true;
            }
        }

        return false;
    }

    private static function attach_delete_state(array $terms): array
    {
        foreach ($terms as $term) {
            $blocked = self::term_has_operational_data_static((int) $term->id);
            $term->can_delete = $blocked ? 0 : 1;
            $term->delete_disabled_reason = $blocked ? 'این سال تحصیلی دارای داده عملیاتی است و قابل حذف نیست.' : '';
        }

        return $terms;
    }

    public function hst_add_term(): void
    {
        $this->authorize();

        $term_name = $this->normalize_name($_POST['term_name'] ?? '');
        if ($term_name === '') {
            HST_Guard::fail('نام سال تحصیلی الزامی است.');
        }

        global $wpdb;
        $table = $this->table();
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE term_name = %s LIMIT 1", $term_name));

        if ($exists) {
            HST_Guard::fail('این سال تحصیلی قبلاً ثبت شده است.');
        }

        $wpdb->query('START TRANSACTION');

        try {
            $deactivated = $wpdb->update(
                $table,
                ['is_active' => 0],
                ['is_active' => 1],
                ['%d'],
                ['%d']
            );

            if ($deactivated === false) {
                throw new RuntimeException('خطا در غیرفعال‌سازی سال‌های تحصیلی قبلی.');
            }

            $insert = $wpdb->insert(
                $table,
                ['term_name' => $term_name, 'is_active' => 1],
                ['%s', '%d']
            );

            if ($insert === false) {
                throw new RuntimeException('خطا در ثبت سال تحصیلی.');
            }

            $term_id = (int) $wpdb->insert_id;
            $wpdb->query('COMMIT');

            wp_send_json_success([
                'message' => 'سال تحصیلی با موفقیت ثبت و فعال شد.',
                'id'      => $term_id,
            ]);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            HST_Guard::fail($e->getMessage());
        }
    }

    public function hst_delete_term(): void
    {
        $this->authorize();

        $id = HST_Guard::post_int('id');
        if (!$id || !$this->term_exists($id)) {
            HST_Guard::fail('شناسه سال تحصیلی نامعتبر است.');
        }

        if ($this->term_has_operational_data($id)) {
            HST_Guard::fail('این سال تحصیلی دارای داده عملیاتی است و برای جلوگیری از حذف اطلاعات مهم، قابل حذف نیست.');
        }

        global $wpdb;
        $deleted = $wpdb->delete($this->table(), ['id' => $id], ['%d']);

        if ($deleted === false || $deleted === 0) {
            HST_Guard::fail('سال تحصیلی حذف نشد.');
        }

        wp_send_json_success(['message' => 'سال تحصیلی با موفقیت حذف شد.']);
    }

    public function hst_update_term(): void
    {
        $this->authorize();

        $id = HST_Guard::post_int('id');
        $term_name = $this->normalize_name($_POST['term_name'] ?? '');

        if ($id < 1 || $term_name === '' || !$this->term_exists($id)) {
            HST_Guard::fail('داده‌های سال تحصیلی کامل یا معتبر نیست.');
        }

        global $wpdb;
        $table = $this->table();
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE term_name = %s AND id != %d LIMIT 1",
                $term_name,
                $id
            )
        );

        if ($exists) {
            HST_Guard::fail('این نام سال تحصیلی قبلاً استفاده شده است.');
        }

        $updated = $wpdb->update(
            $table,
            ['term_name' => $term_name],
            ['id' => $id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            HST_Guard::fail('خطا در به‌روزرسانی سال تحصیلی.');
        }

        wp_send_json_success([
            'message' => 'سال تحصیلی با موفقیت ویرایش شد.',
            'name'    => $term_name,
        ]);
    }

    public function hst_toggle_term_status(): void
    {
        $this->authorize();

        $id = HST_Guard::post_int('id');
        $is_active = HST_Guard::post_int('is_active') === 1 ? 1 : 0;

        if ($id < 1 || !$this->term_exists($id)) {
            HST_Guard::fail('داده سال تحصیلی نامعتبر است.');
        }

        global $wpdb;
        $table = $this->table();

        $wpdb->query('START TRANSACTION');

        try {
            if ($is_active === 1) {
                $deactivated = $wpdb->update(
                    $table,
                    ['is_active' => 0],
                    ['is_active' => 1],
                    ['%d'],
                    ['%d']
                );

                if ($deactivated === false) {
                    throw new RuntimeException('تغییر وضعیت سال‌های تحصیلی قبلی انجام نشد.');
                }
            }

            $updated = $wpdb->update(
                $table,
                ['is_active' => $is_active],
                ['id' => $id],
                ['%d'],
                ['%d']
            );

            if ($updated === false) {
                throw new RuntimeException('تغییر وضعیت انجام نشد.');
            }

            // Never leave the school without an active term: if deactivating
            // left no active term, activate the most recently created one.
            $remaining_active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 1");
            if ($remaining_active === 0) {
                $latest_id = (int) $wpdb->get_var("SELECT id FROM {$table} ORDER BY id DESC LIMIT 1");
                if ($latest_id > 0) {
                    $wpdb->update($table, ['is_active' => 1], ['id' => $latest_id], ['%d'], ['%d']);
                }
            }

            $active_id = (int) $wpdb->get_var("SELECT id FROM {$table} WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
            $wpdb->query('COMMIT');

            if ($is_active === 1) {
                $message = 'سال تحصیلی فعال شد.';
            } elseif ($active_id === $id) {
                // The term was toggled off but auto-reactivated as the default.
                $message = 'حداقل یک سال تحصیلی باید فعال بماند؛ همین سال تحصیلی به‌عنوان سال تحصیلی فعال پیش‌فرض باقی ماند.';
            } elseif ($remaining_active === 0 && $active_id > 0) {
                $message = 'سال تحصیلی غیرفعال شد و آخرین سال تحصیلی به‌عنوان سال تحصیلی فعال پیش‌فرض انتخاب شد.';
            } else {
                $message = 'سال تحصیلی غیرفعال شد.';
            }

            wp_send_json_success([
                'message'   => $message,
                'id'        => $id,
                'is_active' => $is_active,
                'active_id' => $active_id,
            ]);
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            HST_Guard::fail($e->getMessage());
        }
    }

    /**
     * All terms (id, name, is_active), newest first.
     *
     * @param int $limit Optional cap (0 = no limit).
     * @return array
     */
    public static function all($limit = 0)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_terms';
        $sql = "SELECT id, term_name, is_active FROM {$table} ORDER BY id DESC";
        if ($limit > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d", $limit);
        }
        return self::attach_delete_state($wpdb->get_results($sql) ?: []);
    }

    /** Only active terms (id, name, is_active), newest first. */
    public static function active_all($limit = 0)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_terms';
        $sql = "SELECT id, term_name, is_active FROM {$table} WHERE is_active = 1 ORDER BY id DESC";
        if ($limit > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d", $limit);
        }
        return self::attach_delete_state($wpdb->get_results($sql) ?: []);
    }

    /** The active term row (id, term_name), or null. */
    public static function active()
    {
        global $wpdb;
        return $wpdb->get_row("SELECT id, term_name FROM {$wpdb->prefix}hst_terms WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    }

    /** The active term id (int), or 0. */
    public static function active_id()
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}hst_terms WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    }
}
