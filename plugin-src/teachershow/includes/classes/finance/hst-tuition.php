<?php

defined('ABSPATH') || exit;

class HST_Tuition
{
    private const META_INVOICE_ID = '_hst_tuition_invoice_id';
    private const CURRENCY_CODE = 'IRT';
    private const CURRENCY_LABEL = 'تومان';
    private const CURRENCY_SYMBOL = 'تومان';
    private const MAX_AMOUNT = 9999999999;
    private const MAX_BATCH_INVOICES = 500;


    public function __construct()
    {
        add_action('wp_ajax_hst_add_tuition_plan', [$this, 'ajax_add_plan']);
        add_action('wp_ajax_hst_toggle_tuition_plan_status', [$this, 'ajax_toggle_plan_status']);
        add_action('wp_ajax_hst_update_tuition_sms', [$this, 'ajax_update_tuition_sms']);
        add_action('wp_ajax_hst_tuition_sms_test', [$this, 'ajax_tuition_sms_test']);
        add_action('wp_ajax_hst_tuition_sms_estimate', [$this, 'ajax_tuition_sms_estimate']);
        add_action('wp_ajax_hst_update_tuition_plan', [$this, 'ajax_update_plan']);
        add_action('wp_ajax_hst_delete_tuition_plan', [$this, 'ajax_delete_plan']);
        add_action('wp_ajax_hst_tuition_plan_invoices', [$this, 'ajax_plan_invoices']);
        add_action('wp_ajax_hst_update_tuition_invoice_status', [$this, 'ajax_update_invoice_status']);
        add_action('wp_ajax_hst_reset_tuition_cash_payment', [$this, 'ajax_reset_cash_payment']);
        add_action('wp_ajax_hst_create_tuition_order', [$this, 'ajax_create_order']);
        add_action('wp_ajax_hst_tuition_gateways', [$this, 'ajax_payment_gateways']);
        add_filter('woocommerce_currencies', [$this, 'add_toman_currency']);
        add_filter('woocommerce_payment_gateways', [$this, 'register_school_cash_gateway']);
        $this->maybe_upgrade_invoice_payment_schema();
        $this->maybe_upgrade_tuition_sms_schema();
        $this->ensure_school_cash_gateway_enabled();
        add_filter('woocommerce_currency_symbol', [$this, 'toman_currency_symbol'], 10, 2);
        add_action('woocommerce_order_status_processing', [$this, 'mark_invoice_paid_by_order']);
        add_action('woocommerce_order_status_completed', [$this, 'mark_invoice_paid_by_order']);
        add_action('woocommerce_order_status_cancelled', [$this, 'sync_cancelled_order']);
        add_action('woocommerce_order_status_failed', [$this, 'sync_cancelled_order']);
        add_action('woocommerce_order_status_refunded', [$this, 'sync_cancelled_order']);
        add_filter('woocommerce_get_return_url', [$this, 'filter_return_url'], 20, 2);
    }

    private function maybe_upgrade_invoice_payment_schema(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'hst_tuition_invoices';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            return;
        }

        $has_method = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'payment_method'");
        if (!$has_method) {
            $wpdb->query("ALTER TABLE {$table} ADD payment_method varchar(64) NOT NULL DEFAULT '' AFTER wc_order_id");
        }

        $has_note = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'payment_note'");
        if (!$has_note) {
            $wpdb->query("ALTER TABLE {$table} ADD payment_note text NULL AFTER payment_method");
        }
    }


    private function maybe_upgrade_tuition_sms_schema(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'hst_tuition_plans';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            return;
        }

        $columns = [
            'sms_enabled' => "ALTER TABLE {$table} ADD sms_enabled tinyint(1) NOT NULL DEFAULT 0 AFTER source",
            'sms_message' => "ALTER TABLE {$table} ADD sms_message longtext NULL AFTER sms_enabled",
            'sms_sent_at' => "ALTER TABLE {$table} ADD sms_sent_at datetime NULL DEFAULT NULL AFTER sms_message",
            'sms_result'  => "ALTER TABLE {$table} ADD sms_result longtext NULL AFTER sms_sent_at",
        ];

        foreach ($columns as $column => $sql) {
            $has_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
            if (!$has_column) {
                $wpdb->query($sql);
            }
        }
    }

    private function ensure_school_cash_gateway_enabled(): void
    {
        $option_key = 'woocommerce_hst_school_cash_settings';
        $settings = get_option($option_key, null);

        if (!is_array($settings)) {
            update_option($option_key, [
                'enabled' => 'yes',
                'title' => 'پرداخت نقدی در مدرسه',
                'description' => 'پرداخت به صورت حضوری در مدرسه ثبت می‌شود و پس از تأیید مدیر، شهریه پرداخت‌شده محسوب خواهد شد.',
            ]);
            return;
        }

        $changed = false;
        if (!array_key_exists('enabled', $settings)) {
            $settings['enabled'] = 'yes';
            $changed = true;
        }
        if (empty($settings['title'])) {
            $settings['title'] = 'پرداخت نقدی در مدرسه';
            $changed = true;
        }
        if (empty($settings['description'])) {
            $settings['description'] = 'پرداخت به صورت حضوری در مدرسه ثبت می‌شود و پس از تأیید مدیر، شهریه پرداخت‌شده محسوب خواهد شد.';
            $changed = true;
        }

        if ($changed) {
            update_option($option_key, $settings);
        }
    }

    public function register_school_cash_gateway($gateways)
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return $gateways;
        }

        require_once HST_PATH . 'includes/classes/finance/hst-wc-gateway-school-cash.php';

        if (class_exists('HST_WC_Gateway_School_Cash')) {
            $gateways[] = 'HST_WC_Gateway_School_Cash';
        }

        return $gateways;
    }

    public static function is_woocommerce_active()
    {
        return class_exists('WooCommerce') && function_exists('wc_create_order');
    }

    /**
     * The list of payment gateways currently ENABLED in WooCommerce settings.
     * Returns [{id, title, description}] so the custom payment modal can offer
     * exactly the methods the school has configured — no WooCommerce checkout
     * page involved.
     *
     * @return array
     */
    public static function get_payment_gateways()
    {
        if (!self::is_woocommerce_active() || !function_exists('WC')) {
            return [];
        }

        $all = self::load_wc_gateways();

        $list = [];
        foreach ($all as $gateway) {
            if (!self::gateway_is_enabled($gateway)) {
                continue;
            }
            $list[] = [
                'id'          => $gateway->id,
                'title'       => wp_strip_all_tags($gateway->get_title()),
                'description' => wp_strip_all_tags($gateway->get_description()),
            ];
        }
        return $list;
    }

    /**
     * Return the full list of registered WC payment gateways, forcing
     * initialization. In an AJAX request WooCommerce often hasn't built its
     * gateway list yet (or built it empty before third-party gateway plugins
     * like ZarinPal registered theirs), so we explicitly (re)initialize the
     * WC_Payment_Gateways singleton before reading from it.
     *
     * @return array
     */
    private static function load_wc_gateways()
    {
        // Make sure the gateway-registration hooks have run.
        if (did_action('plugins_loaded') === 0) {
            return [];
        }

        $controller = null;

        // Preferred: the singleton, which we can force to (re)init.
        if (class_exists('WC_Payment_Gateways') && method_exists('WC_Payment_Gateways', 'instance')) {
            $controller = WC_Payment_Gateways::instance();
        } elseif (function_exists('WC') && WC() && method_exists(WC(), 'payment_gateways')) {
            $controller = WC()->payment_gateways();
        }

        if (!$controller) {
            return [];
        }

        $gateways = method_exists($controller, 'payment_gateways') ? $controller->payment_gateways() : [];

        // If empty, the singleton was likely built before gateway plugins
        // registered. Re-run init() to rebuild the list, then read again.
        if (empty($gateways) && method_exists($controller, 'init')) {
            $controller->init();
            $gateways = $controller->payment_gateways();
        }

        return is_array($gateways) ? $gateways : [];
    }

    /**
     * Robust "is this gateway enabled in settings?" check. Different gateways
     * (including third-party Iranian IPGs) expose the enabled flag slightly
     * differently, so we check the public property, the get_option('enabled'),
     * and finally is_available() as a fallback.
     */
    private static function gateway_is_enabled($gateway)
    {
        if (!is_object($gateway)) {
            return false;
        }
        // Public property (most core + well-behaved gateways).
        if (isset($gateway->enabled)) {
            return in_array($gateway->enabled, ['yes', true, 1, '1'], true);
        }
        // Settings option fallback.
        if (method_exists($gateway, 'get_option')) {
            $opt = $gateway->get_option('enabled');
            if ($opt !== null && $opt !== '') {
                return in_array($opt, ['yes', true, 1, '1'], true);
            }
        }
        // Last resort.
        if (method_exists($gateway, 'is_available')) {
            return (bool) $gateway->is_available();
        }
        return false;
    }

    public function ajax_payment_gateways()
    {
        $this->require_student_ajax();
        if (!self::is_woocommerce_active()) {
            wp_send_json_error(['message' => 'پرداخت آنلاین فعال نیست.']);
        }
        $gateways = self::get_payment_gateways();
        if (empty($gateways)) {
            // Diagnostic: tell the manager whether gateways exist but are all
            // disabled, vs none registered at all.
            $registered = count(self::load_wc_gateways());
            $msg = $registered > 0
                ? 'روش‌های پرداخت در ووکامرس ثبت شده‌اند اما هیچ‌کدام فعال (enabled) نیستند. لطفاً از «ووکامرس ← تنظیمات ← پرداخت‌ها» یک روش را فعال کنید.'
                : 'هیچ روش پرداختی در ووکامرس ثبت نشده است. ابتدا یک درگاه پرداخت نصب و فعال کنید.';
            wp_send_json_error(['message' => $msg]);
        }
        wp_send_json_success(['gateways' => $gateways]);
    }

    public function add_toman_currency($currencies)
    {
        if (is_array($currencies)) {
            $currencies[self::CURRENCY_CODE] = self::CURRENCY_LABEL;
        }

        return $currencies;
    }

    public function toman_currency_symbol($symbol, $currency)
    {
        return $currency === self::CURRENCY_CODE ? self::CURRENCY_SYMBOL : $symbol;
    }

    public static function format_toman($amount)
    {
        return number_format_i18n((float) $amount) . ' ' . self::CURRENCY_SYMBOL;
    }

    private function require_manager_ajax()
    {
        if (class_exists('HST_Guard')) {
            HST_Guard::verify_ajax('hst_manage_school');
            return;
        }

        check_ajax_referer('hst_nonce', 'nonce');
        if (!is_user_logged_in() || !(current_user_can('manage_options') || current_user_can('hst_manage_school'))) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز'], 403);
        }
    }

    private function require_student_ajax()
    {
        check_ajax_referer('hst_nonce', 'nonce');
        if (!is_user_logged_in() || !(current_user_can('hst_study') || $this->user_has_role(get_current_user_id(), 'student'))) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز'], 403);
        }
    }

    private function user_has_role($user_id, $role)
    {
        $user = get_userdata($user_id);
        return $user && in_array($role, (array) $user->roles, true);
    }

    public function get_active_term()
    {
        return HST_Terms::active();
    }

    public function get_classes()
    {
        return HST_Classes::all_by_name();
    }

    public function get_plans($term_id = 0)
    {
        global $wpdb;
        $term_id = absint($term_id);
        if (!$term_id) {
            $term = $this->get_active_term();
            $term_id = $term ? (int) $term->id : 0;
        }
        if (!$term_id) {
            return [];
        }

        $this->maybe_sync_overdue_invoices($term_id);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, c.class_name,
                COUNT(i.id) AS invoices_count,
                SUM(CASE WHEN i.status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN i.status = 'pending' THEN 1 ELSE 0 END) AS pending_count
             FROM {$wpdb->prefix}hst_tuition_plans p
             LEFT JOIN {$wpdb->prefix}hst_classes c ON c.id = p.class_id
             LEFT JOIN {$wpdb->prefix}hst_tuition_invoices i ON i.plan_id = p.id
             WHERE p.term_id = %d
             GROUP BY p.id
             ORDER BY p.id DESC",
            $term_id
        )) ?: [];
    }


    private function maybe_sync_overdue_invoices(int $term_id = 0): void
    {
        global $wpdb;

        $params = [current_time('Y-m-d')];
        $sql = "SELECT i.id
                FROM {$wpdb->prefix}hst_tuition_invoices i
                INNER JOIN {$wpdb->prefix}hst_tuition_plans p ON p.id = i.plan_id
                WHERE i.status = 'pending'
                  AND p.due_date IS NOT NULL
                  AND p.due_date <> ''
                  AND DATE(p.due_date) < %s";

        if ($term_id > 0) {
            $sql .= " AND i.term_id = %d";
            $params[] = $term_id;
        }

        $invoice_ids = $wpdb->get_col($wpdb->prepare($sql, $params)) ?: [];
        $invoice_ids = array_values(array_filter(array_map('absint', $invoice_ids)));
        if (!$invoice_ids) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($invoice_ids), '%d'));
        $update_sql = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}hst_tuition_invoices
             SET status = 'overdue', updated_at = %s
             WHERE id IN ({$placeholders})",
            array_merge([current_time('mysql')], $invoice_ids)
        );
        $wpdb->query($update_sql);
    }

    private function get_student_avatar_url(int $user_id, string $size = 'thumbnail'): string
    {
        $avatar_id = absint(get_user_meta($user_id, 'hst_profile_avatar_id', true));
        if (!$avatar_id) {
            return '';
        }

        $avatar_url = wp_get_attachment_image_url($avatar_id, $size);
        if (!$avatar_url && $size !== 'full') {
            $avatar_url = wp_get_attachment_image_url($avatar_id, 'full');
        }
        if (!$avatar_url) {
            $avatar_url = wp_get_attachment_url($avatar_id);
        }

        return $avatar_url ? (string) $avatar_url : '';
    }

    public function get_admin_context()
    {
        $active_term = $this->get_active_term();
        $term_id = $active_term ? (int) $active_term->id : 0;

        return [
            'active_term' => $active_term,
            'classes' => $this->get_classes(),
            'plans' => $term_id ? $this->get_plans($term_id) : [],
            'woocommerce_ready' => self::is_woocommerce_active(),
        ];
    }

    public function get_student_context($student_id)
    {
        global $wpdb;
        $active_term = $this->get_active_term();
        if (!$active_term) {
            return ['active_term' => null, 'invoices' => [], 'woocommerce_ready' => self::is_woocommerce_active()];
        }

        $this->maybe_sync_overdue_invoices((int) $active_term->id);

        $invoices = $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, p.title, p.description, p.due_date, p.class_id, c.class_name, t.term_name
             FROM {$wpdb->prefix}hst_tuition_invoices i
             INNER JOIN {$wpdb->prefix}hst_tuition_plans p ON p.id = i.plan_id
             INNER JOIN {$wpdb->prefix}hst_terms t ON t.id = i.term_id
             LEFT JOIN {$wpdb->prefix}hst_classes c ON c.id = p.class_id
             WHERE i.student_id = %d AND i.term_id = %d
             ORDER BY FIELD(i.status, 'pending','overdue','paid','cancelled'), p.due_date ASC, i.id DESC",
            $student_id,
            (int) $active_term->id
        )) ?: [];

        foreach ($invoices as $invoice) {
            $invoice->pay_url = $this->get_invoice_pay_url($invoice);
            $invoice->avatar_url = $this->get_student_avatar_url((int) $student_id, 'thumbnail');
            $invoice->status_label = $this->invoice_status_label((string) $invoice->status);
            $invoice->amount_text = self::format_toman((float) $invoice->amount);
        }

        return ['name', 'title', 'amount', 'due_date', 'school'];
    }

    public function ajax_add_plan()
    {
        $this->require_manager_ajax();
        $active_term = $this->get_active_term();
        if (!$active_term) {
            wp_send_json_error(['message' => 'ابتدا یک سال تحصیلی فعال تعریف کنید.']);
        }

        $title = $this->sanitize_limited_text($_POST['title'] ?? '', 120);
        $description = $this->sanitize_limited_textarea($_POST['description'] ?? '', 800);
        $amount = $this->normalize_amount($_POST['amount'] ?? 0);
        $class_id = absint(wp_unslash($_POST['class_id'] ?? 0));
        $due_date = sanitize_text_field(wp_unslash($_POST['due_date'] ?? ''));

        if (!$title || $amount <= 0) {
            wp_send_json_error(['message' => 'عنوان و مبلغ شهریه الزامی است.']);
        }
        if ($amount > self::MAX_AMOUNT) {
            wp_send_json_error(['message' => 'مبلغ شهریه بیش از حد مجاز است.']);
        }
        if ($class_id && !$this->class_exists($class_id)) {
            wp_send_json_error(['message' => 'کلاس انتخاب‌شده معتبر نیست.']);
        }

        if ($due_date && class_exists('HST_Date')) {
            $due_date = HST_Date::to_gregorian_date($due_date);
        }
        if ($due_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
            wp_send_json_error(['message' => 'تاریخ سررسید نامعتبر است.']);
        }

        global $wpdb;
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'hst_tuition_plans',
            [
                'term_id' => (int) $active_term->id,
                'class_id' => $class_id,
                'title' => $title,
                'description' => $description,
                'amount' => $amount,
                'due_date' => $due_date ?: '',
                'is_active' => 0,
                'created_by' => get_current_user_id(),
            ],
            ['%d','%d','%s','%s','%f','%s','%d','%d']
        );

        if (!$inserted) {
            wp_send_json_error(['message' => 'ثبت شهریه انجام نشد.']);
        }

        wp_send_json_success(['message' => 'شهریه با موفقیت تعریف شد.']);
    }

    public function ajax_update_plan()
    {
        $this->require_manager_ajax();

        $plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));
        if (!$plan_id) {
            wp_send_json_error(['message' => 'شناسه شهریه نامعتبر است.']);
        }

        $plan = $this->get_plan($plan_id);
        if (!$plan) {
            wp_send_json_error(['message' => 'شهریه پیدا نشد.']);
        }

        $has_paid_payment = $this->plan_paid_count($plan_id) > 0;

        $title = $this->sanitize_limited_text($_POST['title'] ?? '', 120);
        $description = $this->sanitize_limited_textarea($_POST['description'] ?? '', 800);
        $amount = $this->normalize_amount($_POST['amount'] ?? 0);
        $class_id = absint(wp_unslash($_POST['class_id'] ?? 0));
        $due_date = sanitize_text_field(wp_unslash($_POST['due_date'] ?? ''));

        if (!$title) {
            wp_send_json_error(['message' => 'نام شهریه الزامی است.']);
        }

        if (!$has_paid_payment) {
            if ($amount <= 0) {
                wp_send_json_error(['message' => 'مبلغ شهریه الزامی است.']);
            }
            if ($amount > self::MAX_AMOUNT) {
                wp_send_json_error(['message' => 'مبلغ شهریه بیش از حد مجاز است.']);
            }
            if ($class_id && !$this->class_exists($class_id)) {
                wp_send_json_error(['message' => 'کلاس انتخاب‌شده معتبر نیست.']);
            }
        }

        if ($due_date && class_exists('HST_Date')) {
            $due_date = HST_Date::to_gregorian_date($due_date);
        }
        if ($due_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
            wp_send_json_error(['message' => 'تاریخ سررسید نامعتبر است.']);
        }

        global $wpdb;

        if ($has_paid_payment) {
            $updated = $wpdb->update(
                $wpdb->prefix . 'hst_tuition_plans',
                [
                    'title' => $title,
                    'description' => $description,
                    'due_date' => $due_date ?: '',
                ],
                ['id' => $plan_id],
                ['%s','%s','%s'],
                ['%d']
            );

            if ($updated === false) {
                wp_send_json_error(['message' => 'ویرایش عنوان و تاریخ سررسید شهریه انجام نشد.']);
            }

            wp_send_json_success(['message' => 'عنوان و تاریخ سررسید شهریه ویرایش شد.']);
        }

        $candidate_plan = clone $plan;
        $candidate_plan->class_id = $class_id;
        if ((int) $plan->is_active === 1) {
            $candidate_students = $this->get_students_for_plan($candidate_plan);
            if (!$candidate_students) {
                wp_send_json_error(['message' => 'دانش‌آموزی برای این شهریه پیدا نشد.']);
            }
            if (count($candidate_students) > self::MAX_BATCH_INVOICES) {
                wp_send_json_error(['message' => 'تعداد دانش‌آموزان این عملیات بیش از حد مجاز است. لطفاً شهریه را برای یک کلاس مشخص بسازید.']);
            }
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'hst_tuition_plans',
            [
                'class_id' => $class_id,
                'title' => $title,
                'description' => $description,
                'amount' => $amount,
                'due_date' => $due_date ?: '',
            ],
            ['id' => $plan_id],
            ['%d','%s','%s','%f','%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => 'ویرایش شهریه انجام نشد.']);
        }

        $wpdb->delete($wpdb->prefix . 'hst_tuition_invoices', ['plan_id' => $plan_id], ['%d']);

        $invoice_result = ['created' => 0, 'skipped' => 0];
        $fresh_plan = $this->get_plan($plan_id);
        if ($fresh_plan && (int) $fresh_plan->is_active === 1) {
            $invoice_result = $this->create_invoices_for_plan($fresh_plan);
        }

        $message = (int) ($fresh_plan->is_active ?? 0) === 1
            ? 'صورتحساب ها ایجاد شد.'
            : 'شهریه ویرایش شد.';

        wp_send_json_success([
            'message' => $message,
            'created' => (int) $invoice_result['created'],
            'skipped' => (int) $invoice_result['skipped'],
        ]);
    }

    private function create_invoices_for_plan($plan): array
    {
        if (!$plan) {
            return ['created' => 0, 'skipped' => 0];
        }

        $students = $this->get_students_for_plan($plan);
        if (!$students) {
            wp_send_json_error(['message' => 'دانش‌آموزی برای این شهریه پیدا نشد.']);
        }
        if (count($students) > self::MAX_BATCH_INVOICES) {
            wp_send_json_error(['message' => 'تعداد دانش‌آموزان این عملیات بیش از حد مجاز است. لطفاً شهریه را برای یک کلاس مشخص بسازید.']);
        }

        global $wpdb;
        $created = 0;
        $skipped = 0;
        $plan_id = (int) $plan->id;

        foreach ($students as $student) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hst_tuition_invoices WHERE plan_id = %d AND student_id = %d LIMIT 1",
                $plan_id,
                (int) $student->ID
            ));
            if ($exists) {
                $skipped++;
                continue;
            }

            $ok = $wpdb->insert(
                $wpdb->prefix . 'hst_tuition_invoices',
                [
                    'plan_id' => $plan_id,
                    'term_id' => (int) $plan->term_id,
                    'student_id' => (int) $student->ID,
                    'amount' => (float) $plan->amount,
                    'status' => 'pending',
                    'wc_order_id' => 0,
                    'payment_method' => '',
                    'payment_note' => '',
                ],
                ['%d','%d','%d','%f','%s','%d','%s','%s']
            );
            if ($ok) {
                $created++;
                do_action('hst_tuition_invoice_created', [
                    'student_id' => (int) $student->ID,
                    'plan_id'    => $plan_id,
                    'created_by' => get_current_user_id(),
                ]);
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function tuition_sms_template($value = ''): string
    {
        return class_exists('HST_SMS')
            ? HST_SMS::message_template($value, 'tuition')
            : trim(wp_strip_all_tags((string) $value));
    }

    private function sanitize_sms_template_input($value): string
    {
        $value = trim(wp_strip_all_tags((string) $value));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 500);
        }
        return substr($value, 0, 500);
    }

    private function tuition_sms_context($plan, $user_id = 0): array
    {
        $user = $user_id ? get_userdata($user_id) : null;
        $name = $user ? ($user->display_name ?: $user->user_login) : 'کاربر';
        $school = class_exists('HST_Settings')
            ? (string) HST_Settings::option('hst-home-school-name', get_bloginfo('name'))
            : (string) get_bloginfo('name');
        $school = trim($school) !== '' ? $school : 'مدرسه';
        $due = !empty($plan->due_date)
            ? (class_exists('HST_Date') ? HST_Date::format($plan->due_date, 'Y/m/d') : (string) $plan->due_date)
            : 'بدون مهلت';

        return [
            'name'     => $name,
            'school'   => $school,
            'date'     => class_exists('HST_Date') ? HST_Date::today('Y/m/d') : date_i18n('Y/m/d'),
            'title'    => trim(wp_strip_all_tags((string) ($plan->title ?? ''))) ?: 'شهریه',
            'amount'   => self::format_toman((float) ($plan->amount ?? 0)),
            'due_date' => $due,
        ];
    }

    private function send_tuition_sms_once($plan_id)
    {
        global $wpdb;

        $plan_id = absint($plan_id);
        if (!$plan_id) {
            return null;
        }

        $plan = $this->get_plan($plan_id);
        if (!$plan || empty($plan->sms_enabled) || !empty($plan->sms_sent_at)) {
            return null;
        }

        if ((int) ($plan->is_active ?? 0) !== 1) {
            return [
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'errors' => ['شهریه هنوز فعال نیست؛ پیامک بعد از فعال‌سازی شهریه ارسال می‌شود.'],
                'not_sent' => true,
            ];
        }

        if (!class_exists('HST_SMS') || !HST_SMS::direct_ready()) {
            return [
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'errors' => ['ارسال مستقیم پیامک فعال یا پیکربندی نشده است.'],
                'not_sent' => true,
            ];
        }

        $recipient_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT student_id FROM {$wpdb->prefix}hst_tuition_invoices WHERE plan_id = %d AND status IN ('pending','overdue')",
            $plan_id
        )) ?: [];

        if (!$recipient_ids) {
            $recipient_ids = array_map(static function ($student) {
                return (int) ($student->ID ?? 0);
            }, $this->get_students_for_plan($plan));
        }

        $result = [
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach (array_values(array_unique(array_filter(array_map('absint', $recipient_ids)))) as $user_id) {
            $phone = HST_SMS::user_phone($user_id);
            if (!$phone) {
                $result['skipped']++;
                continue;
            }
            $context = $this->tuition_sms_context($plan, $user_id);
            $context['sms_template'] = $this->tuition_sms_template($plan->sms_message ?? '');
            $sent = HST_SMS::send_tuition($phone, $context);

            if (is_wp_error($sent)) {
                $result['failed']++;
                if (count($result['errors']) < 5) {
                    $result['errors'][] = $sent->get_error_message();
                }
                continue;
            }

            if ($sent === true) {
                $result['sent']++;
            } else {
                $result['failed']++;
            }
        }

        $wpdb->update(
            $wpdb->prefix . 'hst_tuition_plans',
            [
                'sms_sent_at' => $result['sent'] > 0 ? current_time('mysql') : null,
                'sms_result' => wp_json_encode($result, JSON_UNESCAPED_UNICODE),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $plan_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        return $result;
    }


    public function ajax_tuition_sms_estimate(): void
    {
        $this->require_manager_ajax();

        $plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));
        $template = $this->sanitize_sms_template_input(wp_unslash($_POST['message'] ?? ''));
        if (!$plan_id || $template === '') {
            wp_send_json_error(['message' => 'شهریه یا متن پیامک معتبر نیست.'], 400);
        }
        if (!class_exists('HST_SMS')) {
            wp_send_json_error(['message' => 'سرویس محاسبه پیامک در دسترس نیست.'], 500);
        }

        $plan = $this->get_plan($plan_id);
        if (!$plan) {
            wp_send_json_error(['message' => 'شهریه پیدا نشد.'], 404);
        }

        global $wpdb;
        $recipient_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT student_id FROM {$wpdb->prefix}hst_tuition_invoices WHERE plan_id = %d AND status IN ('pending','overdue')",
            $plan_id
        )) ?: [];
        if (!$recipient_ids) {
            $recipient_ids = array_map(static function ($student) {
                return (int) ($student->ID ?? 0);
            }, $this->get_students_for_plan($plan));
        }
        $recipient_ids = array_values(array_unique(array_filter(array_map('absint', $recipient_ids))));

        $items = [];
        $skipped = 0;
        foreach ($recipient_ids as $user_id) {
            $phone = HST_SMS::user_phone($user_id);
            if ($phone === '') {
                $skipped++;
                continue;
            }
            $items[] = [
                'phone' => $phone,
                'message' => HST_SMS::render_message($template, $this->tuition_sms_context($plan, $user_id)),
            ];
        }

        $estimate = HST_SMS::estimate_consumption($items, false);
        $estimate['target_count'] = count($recipient_ids);
        $estimate['skipped_count'] = $skipped;
        wp_send_json_success(['estimate' => $estimate]);
    }

    public function ajax_tuition_sms_test()
    {
        $this->require_manager_ajax();

        if (!class_exists('HST_SMS') || !HST_SMS::direct_ready()) {
            wp_send_json_error(['message' => 'تنظیمات پیامک شهریه کامل نیست؛ پیامک سامانه، توکن API و سرشماره را بررسی کنید.']);
        }

        $plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $message_template = $this->sanitize_sms_template_input(wp_unslash($_POST['message'] ?? ''));

        if (!$plan_id) {
            wp_send_json_error(['message' => 'شناسه شهریه نامعتبر است.']);
        }

        if ($phone === '') {
            wp_send_json_error(['message' => 'شماره دریافت‌کننده تست را وارد کنید.']);
        }
        if ($message_template === '') {
            wp_send_json_error(['message' => 'متن پیامک را وارد کنید.']);
        }

        $plan = $this->get_plan($plan_id);
        if (!$plan) {
            wp_send_json_error(['message' => 'شهریه پیدا نشد.']);
        }


        $current_user = wp_get_current_user();
        $user_id = ($current_user && $current_user->exists()) ? (int) $current_user->ID : 0;

        $context = $this->tuition_sms_context($plan, $user_id);
        $context['sms_template'] = $message_template;
        $sent = HST_SMS::send_tuition($phone, $context);
        if (is_wp_error($sent)) {
            wp_send_json_error(['message' => $sent->get_error_message()]);
        }

        if ($sent !== true) {
            wp_send_json_error(['message' => 'ارسال پیامک تست انجام نشد.']);
        }

        wp_send_json_success(['message' => 'پیامک تست شهریه با موفقیت ارسال شد.']);
    }

    public function ajax_update_tuition_sms()
    {
        $this->require_manager_ajax();

        $plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));
        $enabled = absint(wp_unslash($_POST['enabled'] ?? 0)) ? 1 : 0;

        if (!$plan_id) {
            wp_send_json_error(['message' => 'شناسه شهریه نامعتبر است.']);
        }

        $plan = $this->get_plan($plan_id);
        if (!$plan) {
            wp_send_json_error(['message' => 'شهریه پیدا نشد.']);
        }

        global $wpdb;

        if (!$enabled) {
            $updated = $wpdb->update(
                $wpdb->prefix . 'hst_tuition_plans',
                [
                    'sms_enabled' => 0,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $plan_id],
                ['%d', '%s'],
                ['%d']
            );

            if ($updated === false) {
                wp_send_json_error(['message' => 'غیرفعال‌سازی پیامک شهریه انجام نشد.']);
            }

            wp_send_json_success([
                'message' => 'پیامک شهریه غیرفعال شد.',
                'id' => $plan_id,
                'sms_enabled' => 0,
            ]);
        }

        if (!class_exists('HST_SMS') || !HST_SMS::direct_ready()) {
            wp_send_json_error(['message' => 'تنظیمات پیامک شهریه کامل نیست؛ پیامک سامانه، توکن API و سرشماره را بررسی کنید.']);
        }


        $message = $this->sanitize_sms_template_input(wp_unslash($_POST['message'] ?? ''));
        if ($message === '') {
            wp_send_json_error(['message' => 'متن پیامک نمی‌تواند خالی باشد.']);
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'hst_tuition_plans',
            [
                'sms_enabled' => 1,
                'sms_message' => $message,
                'updated_at'  => current_time('mysql'),
            ],
            ['id' => $plan_id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => 'فعال‌سازی پیامک شهریه انجام نشد.']);
        }

        $response_message = 'پیامک شهریه فعال شد.';
        $sms_result = null;

        $fresh_plan = $this->get_plan($plan_id);
        if ($fresh_plan && (int) ($fresh_plan->is_active ?? 0) === 1) {
            $sms_result = $this->send_tuition_sms_once($plan_id);
            if (is_array($sms_result) && empty($sms_result['not_sent'])) {
                $response_message .= sprintf(
                    ' پیامک شهریه: %d ارسال، %d ناموفق، %d بدون شماره.',
                    intval($sms_result['sent'] ?? 0),
                    intval($sms_result['failed'] ?? 0),
                    intval($sms_result['skipped'] ?? 0)
                );
            } elseif (is_array($sms_result) && !empty($sms_result['errors'])) {
                $response_message .= ' ' . implode(' ', array_slice(array_map('sanitize_text_field', $sms_result['errors']), 0, 1));
            }
        } else {
            $response_message .= ' بعد از فعال‌سازی شهریه ارسال می‌شود.';
        }

        wp_send_json_success([
            'message' => $response_message,
            'id' => $plan_id,
            'sms_enabled' => 1,
            'sms_message' => $message,
            'sms_sent' => is_array($sms_result) && intval($sms_result['sent'] ?? 0) > 0 ? 1 : 0,
            'sms' => $sms_result,
        ]);
    }

    public function ajax_toggle_plan_status()
    {
        $this->require_manager_ajax();

        $plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));
        $is_active = absint(wp_unslash($_POST['is_active'] ?? 0)) === 1 ? 1 : 0;

        if (!$plan_id) {
            wp_send_json_error(['message' => 'شناسه شهریه نامعتبر است.']);
        }

        $plan = $this->get_plan($plan_id);
        if (!$plan) {
            wp_send_json_error(['message' => 'شهریه پیدا نشد.']);
        }

        $this->maybe_sync_overdue_invoices((int) $plan->term_id);

        global $wpdb;

        $invoice_result = ['created' => 0, 'skipped' => 0];
        if ($is_active) {
            $invoice_result = $this->create_invoices_for_plan($plan);
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'hst_tuition_plans',
            ['is_active' => $is_active],
            ['id' => $plan_id],
            ['%d'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => 'تغییر وضعیت شهریه انجام نشد.']);
        }

        $message = $is_active
            ? 'صورتحساب ها ایجاد شد.'
            : 'شهریه غیرفعال شد.';

        $sms_result = null;
        if ($is_active) {
            $fresh_plan = $this->get_plan($plan_id);
            if ($fresh_plan && !empty($fresh_plan->sms_enabled) && empty($fresh_plan->sms_sent_at)) {
                $sms_result = $this->send_tuition_sms_once($plan_id);
            }
        }

        wp_send_json_success([
            'message' => $message,
            'is_active' => $is_active,
            'created' => (int) $invoice_result['created'],
            'skipped' => (int) $invoice_result['skipped'],
            'sms_sent' => is_array($sms_result) && intval($sms_result['sent'] ?? 0) > 0 ? 1 : 0,
            'sms' => $sms_result,
        ]);
    }

    private function plan_paid_count($plan_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hst_tuition_invoices WHERE plan_id = %d AND status = 'paid'",
            (int) $plan_id
        ));
    }

    public function ajax_delete_plan()
    {
        $this->require_manager_ajax();
        $plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));
        if (!$plan_id) {
            wp_send_json_error(['message' => 'شناسه نامعتبر است.']);
        }

        $plan = $this->get_plan($plan_id);
        if (!$plan) {
            wp_send_json_error(['message' => 'شهریه پیدا نشد.']);
        }

        $this->maybe_sync_overdue_invoices((int) $plan->term_id);

        global $wpdb;
        if ($this->plan_paid_count($plan_id) > 0) {
            wp_send_json_error(['message' => 'برای این شهریه پرداخت موفق ثبت شده و قابل حذف نیست.']);
        }

        $wpdb->query('START TRANSACTION');
        $invoices_deleted = $wpdb->delete($wpdb->prefix . 'hst_tuition_invoices', ['plan_id' => $plan_id], ['%d']);
        $deleted = $wpdb->delete($wpdb->prefix . 'hst_tuition_plans', ['id' => $plan_id], ['%d']);

        if ($invoices_deleted === false || !$deleted) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'حذف انجام نشد.']);
        }

        $wpdb->query('COMMIT');

        wp_send_json_success(['message' => 'شهریه حذف شد.']);
    }

    public function ajax_create_order()
    {
        $this->require_student_ajax();
        if (!self::is_woocommerce_active()) {
            wp_send_json_error(['message' => 'ووکامرس فعال نیست یا درست بارگذاری نشده است.']);
        }

        $invoice_id = absint(wp_unslash($_POST['invoice_id'] ?? 0));
        $gateway_id = sanitize_text_field(wp_unslash($_POST['gateway'] ?? ''));
        $student_id = get_current_user_id();
        $this->maybe_sync_overdue_invoices();
        $invoice = $this->get_invoice_for_student($invoice_id, $student_id);

        if (!$invoice) {
            wp_send_json_error(['message' => 'صورتحساب پیدا نشد.']);
        }
        if ($invoice->status === 'paid') {
            wp_send_json_error(['message' => 'این شهریه قبلاً پرداخت شده است.']);
        }
        if ($invoice->status !== 'pending') {
            wp_send_json_error(['message' => 'مهلت پرداخت این صورتحساب به پایان رسیده یا این صورتحساب در وضعیت قابل پرداخت نیست.']);
        }
        if ((float) $invoice->amount <= 0 || (float) $invoice->amount > self::MAX_AMOUNT) {
            wp_send_json_error(['message' => 'مبلغ صورتحساب معتبر نیست.']);
        }

        // Validate the chosen gateway against ALL registered gateways and keep
        // it only if enabled in settings (cart-independent, force-initialized).
        $all_gateways = self::load_wc_gateways();
        $gateway = ($gateway_id && isset($all_gateways[$gateway_id])) ? $all_gateways[$gateway_id] : null;
        if (!$gateway || !self::gateway_is_enabled($gateway)) {
            wp_send_json_error(['message' => 'روش پرداخت انتخاب‌شده معتبر نیست.']);
        }

        // Reuse an unpaid order if one exists; otherwise create a fresh one.
        $order = null;
        $order_id = absint($invoice->wc_order_id);
        if ($order_id && function_exists('wc_get_order')) {
            $existing = wc_get_order($order_id);
            if ($existing && !$existing->is_paid() && !in_array($existing->get_status(), ['cancelled', 'failed', 'refunded'], true)) {
                $order = $existing;
            }
        }

        if (!$order) {
            $order = wc_create_order(['customer_id' => $student_id]);
            if (is_wp_error($order)) {
                wp_send_json_error(['message' => 'ساخت سفارش ووکامرس انجام نشد.']);
            }
            if (method_exists($order, 'set_currency')) {
                $order->set_currency(self::CURRENCY_CODE);
            }
            $fee = new WC_Order_Item_Fee();
            $fee->set_name(sprintf('شهریه: %s', sanitize_text_field($invoice->title)));
            $fee->set_amount((float) $invoice->amount);
            $fee->set_total((float) $invoice->amount);
            $order->add_item($fee);
            $order->update_meta_data(self::META_INVOICE_ID, $invoice_id);
            $order->set_created_via('teachershow');
        }

        // Assign the chosen gateway and point the order's "return" at our own
        // tuition page, so after payment the student lands back on the tuition
        // screen (not WooCommerce's thank-you page).
        $order->set_payment_method($gateway);
        $order->update_meta_data('_hst_return_url', $this->tuition_return_url());
        $order->calculate_totals();
        $order->save();

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hst_tuition_invoices',
            ['wc_order_id' => $order->get_id(), 'updated_at' => current_time('mysql')],
            ['id' => $invoice_id],
            ['%d', '%s'],
            ['%d']
        );

        // Process payment THROUGH the chosen gateway directly (no checkout page).
        // Gateways return a redirect to their own payment URL (bank/IPG) or, for
        // offline methods, back to our return URL.
        $result = $gateway->process_payment($order->get_id());
        if (is_array($result) && (($result['result'] ?? '') === 'success') && !empty($result['redirect'])) {
            wp_send_json_success(['message' => 'در حال انتقال به درگاه پرداخت...', 'redirect' => $result['redirect']]);
        }

        // Fallback: if the gateway didn't give a redirect, send to our page.
        wp_send_json_success(['message' => 'در حال پردازش پرداخت...', 'redirect' => $this->tuition_return_url()]);
    }

    /**
     * The URL of the tuition page the student should return to after payment.
     * Falls back to the site home if the page can't be resolved.
     */
    private function tuition_return_url()
    {
        $url = home_url('/tuition-payments');
        if (function_exists('hst_get_page_url')) {
            $maybe = hst_get_page_url('tuition-payments');
            if ($maybe) {
                $url = $maybe;
            }
        }
        return add_query_arg('hst_paid', '1', $url);
    }

    public function mark_invoice_paid_by_order($order_id)
    {
        $this->sync_order_status($order_id, 'paid');
    }

    /**
     * Send teachershow tuition orders back to the tuition page after payment
     * (instead of WooCommerce's default thank-you page).
     */
    public function filter_return_url($url, $order)
    {
        if (!$order) {
            return $url;
        }
        $stored = $order->get_meta('_hst_return_url');
        if ($stored) {
            return $stored;
        }
        if ($order->get_created_via() === 'teachershow') {
            return $this->tuition_return_url();
        }
        return $url;
    }

    public function sync_cancelled_order($order_id)
    {
        $this->sync_order_status($order_id, 'pending');
    }

    private function sync_order_status($order_id, $status)
    {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $invoice_id = absint($order->get_meta(self::META_INVOICE_ID));
        if (!$invoice_id) {
            return;
        }

        $method = '';
        if ($status === 'paid' && method_exists($order, 'get_payment_method')) {
            $method = (string) $order->get_payment_method();
        }

        global $wpdb;
        $data = [
            'status' => $status,
            'paid_at' => $status === 'paid' ? current_time('mysql') : null,
            'updated_at' => current_time('mysql'),
        ];
        $formats = ['%s','%s','%s'];

        if ($status === 'paid') {
            $data['payment_method'] = $method ?: 'online';
            $formats[] = '%s';
        } else {
            $data['payment_method'] = '';
            $formats[] = '%s';
        }

        $wpdb->update(
            $wpdb->prefix . 'hst_tuition_invoices',
            $data,
            ['id' => $invoice_id],
            $formats,
            ['%d']
        );
    }

    private function normalize_amount($value)
    {
        if (is_array($value)) {
            return 0;
        }

        $value = is_string($value) ? wp_unslash($value) : (string) $value;
        $value = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $value = preg_replace('/[^0-9.]/', '', $value);

        return min(self::MAX_AMOUNT, max(0, (float) $value));
    }

    private function sanitize_limited_text($value, int $max_length): string
    {
        $text = sanitize_text_field(wp_unslash($value));
        return function_exists('mb_substr') ? mb_substr($text, 0, $max_length) : substr($text, 0, $max_length);
    }

    private function sanitize_limited_textarea($value, int $max_length): string
    {
        $text = sanitize_textarea_field(wp_unslash($value));
        return function_exists('mb_substr') ? mb_substr($text, 0, $max_length) : substr($text, 0, $max_length);
    }

    private function class_exists(int $class_id): bool
    {
        return HST_Classes::exists($class_id);
    }

    private function get_plan($plan_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hst_tuition_plans WHERE id = %d LIMIT 1",
            $plan_id
        ));
    }

    private function get_students_for_plan($plan)
    {
        global $wpdb;
        $where_class = '';
        $params = [(int) $plan->term_id];
        if ((int) $plan->class_id > 0) {
            $where_class = ' AND uc.class_id = %d';
            $params[] = (int) $plan->class_id;
        }

        $students = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT u.ID, u.display_name
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->prefix}hst_users_classes uc
                ON uc.user_id = u.ID
                AND uc.role = 'student'
                AND uc.term_id = %d
                {$where_class}",
            ...$params
        )) ?: [];

        return class_exists('HST_Students') ? HST_Students::sort_student_rows($students) : $students;
    }

    private function payment_method_label($method, $status = ''): string
    {
        $method = (string) $method;

        if ($status !== 'paid') {
            return '—';
        }

        if ($method === 'cash') {
            return 'نقدی';
        }

        if ($method === 'hst_school_cash') {
            return 'نقدی مدرسه';
        }

        if ($method !== '') {
            return 'آنلاین';
        }

        return 'آنلاین';
    }

    private function invoice_payment_url($invoice): string
    {
        if (in_array(($invoice->status ?? ''), ['paid', 'cancelled'], true)) {
            return '';
        }

        $url = $this->get_invoice_pay_url($invoice);
        if ($url) {
            return $url;
        }

        return add_query_arg(
            [
                'hst_invoice' => absint($invoice->id ?? 0),
                'hst_pay' => '1',
            ],
            $this->tuition_return_url()
        );
    }

    private function invoice_status_label($status): string
    {
        switch ((string) $status) {
            case 'paid':
                return 'پرداخت‌شده';
            case 'overdue':
                return 'سررسید گذشته';
            case 'cancelled':
                return 'لغوشده';
            default:
                return 'در انتظار پرداخت';
        }
    }

    public function ajax_plan_invoices()
    {
        $this->require_manager_ajax();

        $plan_id = absint(wp_unslash($_POST['plan_id'] ?? 0));
        $status_filter = sanitize_key(wp_unslash($_POST['status'] ?? ''));
        $method_filter = sanitize_key(wp_unslash($_POST['method'] ?? ''));
        $class_filter = absint(wp_unslash($_POST['class_id'] ?? 0));
        $search = $this->sanitize_limited_text($_POST['search'] ?? '', 80);

        if (!$plan_id) {
            wp_send_json_error(['message' => 'شناسه شهریه نامعتبر است.']);
        }

        $plan = $this->get_plan($plan_id);
        if (!$plan) {
            wp_send_json_error(['message' => 'شهریه پیدا نشد.']);
        }

        global $wpdb;
        $where = ["i.plan_id = %d"];
        $params = [$plan_id];

        if ($class_filter > 0 && (int) $plan->class_id === 0) {
            $where[] = "EXISTS (
                SELECT 1 FROM {$wpdb->prefix}hst_users_classes uc_filter
                WHERE uc_filter.user_id = i.student_id
                  AND uc_filter.term_id = i.term_id
                  AND uc_filter.role = 'student'
                  AND uc_filter.class_id = %d
            )";
            $params[] = $class_filter;
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(u.display_name LIKE %s
                OR u.user_login LIKE %s
                OR (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = i.student_id AND meta_key = 'first_name' LIMIT 1) LIKE %s
                OR (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = i.student_id AND meta_key = 'last_name' LIMIT 1) LIKE %s
                OR (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = i.student_id AND meta_key = 'hst_national_code' LIMIT 1) LIKE %s)";
            array_push($params, $like, $like, $like, $like, $like);
        }

        if ($status_filter === 'paid') {
            $where[] = "i.status = 'paid'";
        } elseif ($status_filter === 'unpaid') {
            $where[] = "i.status IN ('pending','overdue')";
        } elseif (in_array($status_filter, ['pending', 'overdue', 'cancelled'], true)) {
            $where[] = "i.status = %s";
            $params[] = $status_filter;
        }

        if ($method_filter === 'cash') {
            $where[] = "i.payment_method IN ('cash','hst_school_cash')";
        } elseif ($method_filter === 'online') {
            $where[] = "i.status = 'paid' AND i.payment_method NOT IN ('', 'cash', 'hst_school_cash')";
        } elseif ($method_filter === 'none') {
            $where[] = "(i.payment_method = '' OR i.payment_method IS NULL)";
        }

        $sql = "SELECT i.*, p.title AS plan_title, p.due_date, p.description, p.class_id AS plan_class_id,
                    c.class_name AS plan_class_name, t.term_name,
                    u.display_name AS student_name, u.user_login AS student_login,
                    (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = i.student_id AND meta_key = 'first_name' LIMIT 1) AS first_name,
                    (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = i.student_id AND meta_key = 'last_name' LIMIT 1) AS last_name,
                    (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = i.student_id AND meta_key = 'hst_national_code' LIMIT 1) AS national_code,
                    (SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = i.student_id AND meta_key = 'hst_parent_phone' LIMIT 1) AS parent_phone,
                    (
                        SELECT GROUP_CONCAT(DISTINCT c2.class_name ORDER BY c2.class_name SEPARATOR '، ')
                        FROM {$wpdb->prefix}hst_users_classes uc
                        INNER JOIN {$wpdb->prefix}hst_classes c2 ON c2.id = uc.class_id
                        WHERE uc.user_id = i.student_id AND uc.term_id = i.term_id AND uc.role = 'student'
                    ) AS enrolled_classes,
                    (
                        SELECT GROUP_CONCAT(DISTINCT uc.class_id ORDER BY uc.class_id SEPARATOR ',')
                        FROM {$wpdb->prefix}hst_users_classes uc
                        WHERE uc.user_id = i.student_id AND uc.term_id = i.term_id AND uc.role = 'student'
                    ) AS enrolled_class_ids
                FROM {$wpdb->prefix}hst_tuition_invoices i
                INNER JOIN {$wpdb->prefix}hst_tuition_plans p ON p.id = i.plan_id
                INNER JOIN {$wpdb->prefix}hst_terms t ON t.id = i.term_id
                LEFT JOIN {$wpdb->prefix}hst_classes c ON c.id = p.class_id
                LEFT JOIN {$wpdb->users} u ON u.ID = i.student_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY COALESCE(
                            NULLIF(c.class_name, ''),
                            (
                                SELECT MIN(csort.class_name)
                                FROM {$wpdb->prefix}hst_users_classes ucsort
                                INNER JOIN {$wpdb->prefix}hst_classes csort ON csort.id = ucsort.class_id
                                WHERE ucsort.user_id = i.student_id AND ucsort.term_id = i.term_id AND ucsort.role = 'student'
                            ),
                            ''
                         ) COLLATE utf8mb4_unicode_ci ASC,
                         COALESCE(NULLIF(TRIM((SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = i.student_id AND meta_key = 'last_name' LIMIT 1)), ''), u.display_name) COLLATE utf8mb4_unicode_ci ASC,
                         COALESCE(NULLIF(TRIM((SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = i.student_id AND meta_key = 'first_name' LIMIT 1)), ''), '') COLLATE utf8mb4_unicode_ci ASC,
                         u.display_name COLLATE utf8mb4_unicode_ci ASC,
                         i.id ASC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
        foreach ($rows as $row) {
            $class_names = preg_split('/\s*،\s*/u', (string) ($row->enrolled_classes ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $row->enrolled_classes = implode('، ', HST_Classes::sort_names($class_names));
        }
        usort($rows, static function ($left, $right): int {
            $left_classes = preg_split('/\s*،\s*/u', (string) ($left->enrolled_classes ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $right_classes = preg_split('/\s*،\s*/u', (string) ($right->enrolled_classes ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $left_class = (string) ($left->plan_class_name ?: ($left_classes[0] ?? ''));
            $right_class = (string) ($right->plan_class_name ?: ($right_classes[0] ?? ''));

            $class_compare = HST_Classes::compare_names($left_class, $right_class);
            if ($class_compare !== 0) {
                return $class_compare;
            }

            foreach (['last_name', 'first_name', 'student_name'] as $key) {
                $compare = strnatcasecmp((string) ($left->{$key} ?? ''), (string) ($right->{$key} ?? ''));
                if ($compare !== 0) {
                    return $compare;
                }
            }

            return (int) $left->id <=> (int) $right->id;
        });

        $items = [];
        $summary = ['total' => 0, 'paid' => 0, 'unpaid' => 0, 'cash' => 0, 'online' => 0];
        $class_options = [];

        foreach ($rows as $row) {
            $payment_url = in_array($row->status, ['paid', 'cancelled'], true) ? '' : $this->invoice_payment_url($row);
            $payment_method = $row->payment_method ?: '';
            $can_reset_cash = $row->status === 'paid' && (
                $payment_method === 'cash'
                || ($payment_method === 'hst_school_cash' && (int) $row->wc_order_id < 1)
            );
            $item = [
                'id' => (int) $row->id,
                'plan_id' => (int) $row->plan_id,
                'student_id' => (int) $row->student_id,
                'student_name' => $row->student_name ?: trim(($row->first_name ?: '') . ' ' . ($row->last_name ?: '')) ?: $row->student_login ?: '—',
                'student_first_name' => $row->first_name ?: '',
                'student_last_name' => $row->last_name ?: '',
                'student_login' => $row->student_login ?: '',
                'avatar_url' => $this->get_student_avatar_url((int) $row->student_id, 'thumbnail'),
                'avatar_full_url' => $this->get_student_avatar_url((int) $row->student_id, 'full'),
                'national_code' => $row->national_code ?: '',
                'parent_phone' => $row->parent_phone ?: '',
                'class_name' => $row->enrolled_classes ?: ($row->plan_class_name ?: 'عمومی'),
                'class_ids' => array_values(array_filter(array_map('absint', explode(',', (string) ($row->enrolled_class_ids ?? ''))))),
                'plan_title' => $row->plan_title ?: '',
                'term_name' => $row->term_name ?: '',
                'description' => $row->description ?: '',
                'due_date' => $row->due_date ? (class_exists('HST_Date') ? HST_Date::format($row->due_date, 'Y/m/d') : $row->due_date) : '',
                'amount' => (float) $row->amount,
                'amount_text' => self::format_toman((float) $row->amount),
                'status' => $row->status,
                'status_label' => $this->invoice_status_label($row->status),
                'payment_method' => $payment_method,
                'payment_method_label' => $this->payment_method_label($payment_method, $row->status),
                'can_reset_cash' => $can_reset_cash,
                'payment_note' => $row->payment_note ?: '',
                'paid_at' => $row->paid_at ? (class_exists('HST_Date') ? HST_Date::format($row->paid_at, 'Y/m/d H:i') : $row->paid_at) : '',
                'wc_order_id' => (int) $row->wc_order_id,
                'payment_url' => $payment_url,
                'qr_url' => $payment_url !== '' ? 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=14&data=' . rawurlencode($payment_url) : '',
            ];

            $summary['total']++;
            if ($row->status === 'paid') {
                $summary['paid']++;
                if (in_array($payment_method, ['cash', 'hst_school_cash'], true)) {
                    $summary['cash']++;
                } elseif ($payment_method !== '') {
                    $summary['online']++;
                }
            } elseif (in_array($row->status, ['pending', 'overdue'], true)) {
                $summary['unpaid']++;
            }

            $items[] = $item;

            if ((int) $plan->class_id === 0 && !empty($row->enrolled_classes)) {
                $class_names = array_filter(array_map('trim', explode('،', (string) $row->enrolled_classes)));
                foreach ($class_names as $class_name) {
                    if ($class_name !== '') {
                        $class_options[$class_name] = $class_name;
                    }
                }
            }
        }

        $class_filter_options = [];
        if ((int) $plan->class_id === 0) {
            $class_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT c.id, c.class_name
                 FROM {$wpdb->prefix}hst_tuition_invoices i
                 INNER JOIN {$wpdb->prefix}hst_users_classes uc
                    ON uc.user_id = i.student_id
                    AND uc.term_id = i.term_id
                    AND uc.role = 'student'
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = uc.class_id
                 WHERE i.plan_id = %d
                 ORDER BY c.class_name ASC",
                $plan_id
            )) ?: [];
            $class_rows = HST_Classes::sort_rows($class_rows);

            foreach ($class_rows as $class_row) {
                $class_filter_options[] = [
                    'id' => (int) $class_row->id,
                    'name' => (string) $class_row->class_name,
                ];
            }
        }

        wp_send_json_success([
            'plan' => [
                'id' => (int) $plan->id,
                'title' => $plan->title,
                'class_id' => (int) $plan->class_id,
                'amount_text' => self::format_toman((float) $plan->amount),
            ],
            'items' => $items,
            'summary' => $summary,
            'class_options' => $class_filter_options,
        ]);
    }

    public function ajax_update_invoice_status()
    {
        $this->require_manager_ajax();

        $invoice_id = absint(wp_unslash($_POST['invoice_id'] ?? 0));
        $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
        $note = $this->sanitize_limited_textarea($_POST['payment_note'] ?? '', 300);

        if (!$invoice_id) {
            wp_send_json_error(['message' => 'شناسه صورتحساب نامعتبر است.']);
        }

        if ($status !== 'paid') {
            wp_send_json_error(['message' => 'تغییر دستی فقط برای ثبت پرداخت نقدی مجاز است.']);
        }

        global $wpdb;

        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}hst_tuition_invoices WHERE id = %d LIMIT 1",
            $invoice_id
        ));
        if (!$invoice) {
            wp_send_json_error(['message' => 'صورتحساب پیدا نشد.']);
        }
        if (!in_array((string) $invoice->status, ['pending', 'overdue'], true)) {
            wp_send_json_error(['message' => 'ثبت نقدی فقط برای صورتحساب پرداخت‌نشده مجاز است.']);
        }

        $data = [
            'status' => 'paid',
            'updated_at' => current_time('mysql'),
            'paid_at' => current_time('mysql'),
            'payment_method' => 'cash',
            'payment_note' => $note,
        ];
        $formats = ['%s', '%s', '%s', '%s', '%s'];

        $updated = $wpdb->update(
            $wpdb->prefix . 'hst_tuition_invoices',
            $data,
            ['id' => $invoice_id, 'status' => (string) $invoice->status],
            $formats,
            ['%d', '%s']
        );

        if ($updated !== 1) {
            wp_send_json_error(['message' => 'تغییر وضعیت پرداخت انجام نشد؛ صفحه را تازه‌سازی و دوباره تلاش کنید.']);
        }

        wp_send_json_success(['message' => 'وضعیت پرداخت به‌روزرسانی شد.']);
    }

    public function ajax_reset_cash_payment()
    {
        $this->require_manager_ajax();

        $invoice_id = absint(wp_unslash($_POST['invoice_id'] ?? 0));
        if (!$invoice_id) {
            wp_send_json_error(['message' => 'شناسه صورتحساب نامعتبر است.']);
        }

        global $wpdb;

        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT i.id, i.status, i.payment_method, i.wc_order_id, p.due_date
             FROM {$wpdb->prefix}hst_tuition_invoices i
             INNER JOIN {$wpdb->prefix}hst_tuition_plans p ON p.id = i.plan_id
             WHERE i.id = %d
             LIMIT 1",
            $invoice_id
        ));

        if (!$invoice) {
            wp_send_json_error(['message' => 'صورتحساب پیدا نشد.']);
        }

        $is_manual_cash = $invoice->payment_method === 'cash'
            || ($invoice->payment_method === 'hst_school_cash' && (int) $invoice->wc_order_id < 1);
        if ($invoice->status !== 'paid' || !$is_manual_cash) {
            wp_send_json_error(['message' => 'فقط پرداخت نقدی ثبت‌شده به‌صورت دستی قابل بازنشانی است.']);
        }

        $today = current_time('Y-m-d');
        $reset_status = !empty($invoice->due_date) && (string) $invoice->due_date < $today
            ? 'overdue'
            : 'pending';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}hst_tuition_invoices
             SET status = %s,
                 paid_at = NULL,
                 payment_method = '',
                 payment_note = NULL,
                 updated_at = %s
             WHERE id = %d
               AND status = 'paid'
               AND (
                    payment_method = 'cash'
                    OR (payment_method = 'hst_school_cash' AND (wc_order_id IS NULL OR wc_order_id = 0))
               )",
            $reset_status,
            current_time('mysql'),
            $invoice_id
        ));

        if ($updated !== 1) {
            wp_send_json_error(['message' => 'بازنشانی پرداخت نقدی انجام نشد؛ صفحه را تازه‌سازی و دوباره تلاش کنید.']);
        }

        wp_send_json_success(['message' => 'ثبت نقدی با موفقیت بازنشانی شد.']);
    }

    private function get_invoice_for_student($invoice_id, $student_id)
    {
        global $wpdb;
        $this->maybe_sync_overdue_invoices();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT i.*, p.title, p.description, p.due_date, c.class_name
             FROM {$wpdb->prefix}hst_tuition_invoices i
             INNER JOIN {$wpdb->prefix}hst_tuition_plans p ON p.id = i.plan_id
             LEFT JOIN {$wpdb->prefix}hst_classes c ON c.id = p.class_id
             WHERE i.id = %d AND i.student_id = %d
             LIMIT 1",
            $invoice_id,
            $student_id
        ));
    }

    private function get_invoice_pay_url($invoice)
    {
        if (($invoice->status ?? '') !== 'pending') {
            return '';
        }
        $order_id = absint($invoice->wc_order_id ?? 0);
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order && !$order->is_paid() && !in_array($order->get_status(), ['cancelled','failed','refunded'], true)) {
                return $order->get_checkout_payment_url();
            }
        }
        return '';
    }
}
