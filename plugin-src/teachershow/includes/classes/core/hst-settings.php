<?php

defined('ABSPATH') || exit;

class HST_Settings
{
    private const USE_PLUGIN_LOGIN_OPTION = 'hst-use-plugin-login';
    private const BIRTHDAY_SMS_OPTION = 'hst-birthday-sms-enabled';
    private const BIRTHDAY_TEMPLATE_OPTION = 'hst-birthday-template';
    private const HOME_SCHOOL_NAME_OPTION = 'hst-home-school-name';
    private const HOME_TAGLINE_OPTION = 'hst-home-tagline';
    private const HOME_LOGO_OPTION = 'hst-home-logo-id';
    private const HOME_FOOTER_NOTE_OPTION = 'hst-home-footer-note';
    private const SMS_ENABLED_OPTION = 'hst-sms-enabled';
    private const SMS_LOGIN_ENABLED_OPTION = 'hst-sms-login-enabled';
    private const SMS_API_KEY_OPTION = 'hst-sms-api-key';
    private const SMS_SENDER_OPTION = 'hst-sms-sender';
    private const SMS_OTP_PATTERN_CODE_OPTION = 'hst-sms-otp-pattern-code';
    private const SMS_OTP_PATTERN_VAR_OPTION = 'hst-sms-otp-pattern-var';
    private const PERMALINK_DEFAULTS_OPTION = 'hst-permalink-defaults-applied';
    private const HOME_FRONT_DEFAULT_REVISION_OPTION = 'hst-home-front-default-revision';
    private const HOME_FRONT_DEFAULT_REVISION = '1.0.184';

    public function __construct()
    {
        add_action('admin_init', [$this, 'hst_define_roles']);
        add_action('wp_ajax_hst_save_settings', [$this, 'ajax_save_settings']);
        add_filter('template_include', [$this, 'hst_change_template']);
        add_action('init', [$this, 'hst_create_plugin_pages']);
        add_action('init', [$this, 'hst_apply_home_front_default_once'], 100);
        add_action('init', [$this, 'hst_apply_permalink_defaults'], 99);
        add_filter('pre_get_document_title', [$this, 'hst_plugin_document_title'], 20);
        add_filter('login_redirect', [$this, 'hst_role_login_redirect'], 20, 3);
        add_filter('logout_redirect', [$this, 'hst_logout_redirect'], 20, 3);
        add_action('wp_login_failed', [$this, 'hst_login_failed_redirect'], 20);
        add_filter('authenticate', [$this, 'hst_authenticate_empty_check'], 30, 3);
        add_action('login_init', [$this, 'hst_redirect_default_login']);
        add_filter('show_admin_bar', [$this, 'hst_hide_admin_bar_for_school_roles'], 20);
        add_action('admin_head', [$this, 'hst_hide_admin_bar_styles']);
        add_action('wp_ajax_hst_sms_balance', [$this, 'ajax_sms_balance']);
    }

    /**
     * Use readable plugin page URLs on fresh/plain WordPress installations.
     *
     * This runs once and never replaces an existing custom permalink
     * structure. The late init priority ensures plugin pages and rewrite
     * endpoints are registered before the one-time rule flush.
     */
    public function hst_apply_permalink_defaults(): void
    {
        $current_structure = (string) get_option('permalink_structure', '');

        if ($current_structure !== '') {
            if ((string) get_option(self::PERMALINK_DEFAULTS_OPTION, '') !== '1') {
                update_option(self::PERMALINK_DEFAULTS_OPTION, '1', false);
            }
            return;
        }

        global $wp_rewrite;

        if ($wp_rewrite instanceof WP_Rewrite) {
            $wp_rewrite->set_permalink_structure('/%postname%/');
        }

        if ((string) get_option('permalink_structure', '') === '') {
            update_option('permalink_structure', '/%postname%/');
        }

        if ((string) get_option('permalink_structure', '') !== '') {
            flush_rewrite_rules(false);
            update_option(self::PERMALINK_DEFAULTS_OPTION, '1', false);
        }
    }

    /**
     * Give every plugin page a stable, meaningful browser-tab title even when
     * the active theme does not provide title-tag support.
     */
    public function hst_plugin_document_title(string $title): string
    {
        if (!is_singular('page')) {
            return $title;
        }

        global $post;
        if (!$post instanceof WP_Post) {
            return $title;
        }

        foreach (self::plugin_page_definitions() as $slug => $definition) {
            if ((string) $post->post_name !== (string) $slug && !has_shortcode((string) $post->post_content, trim((string) $definition['shortcode'], '[]'))) {
                continue;
            }

            $page_title = (string) $definition['title'];
            $site_title = trim((string) get_bloginfo('name'));

            return $site_title !== '' ? $page_title . ' | ' . $site_title : $page_title;
        }

        return $title;
    }


    public function ajax_sms_balance()
    {
        if (!self::can_manage_school()) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز است.']);
        }

        check_ajax_referer('hst_nonce', 'nonce');

        if (!class_exists('HST_SMS')) {
            wp_send_json_error(['message' => 'کلاس پیامک در دسترس نیست.']);
        }

        HST_SMS::forget_wallet_cache();
        $summary = HST_SMS::cached_wallet_summary(300);

        if (is_wp_error($summary)) {
            wp_send_json_error(['message' => $summary->get_error_message()]);
        }

        $available_balance = isset($summary['available_balance']) ? (string) $summary['available_balance'] : '';

        wp_send_json_success([
            'message' => 'موجودی پیامک بروزرسانی شد.',
            'display_balance' => $available_balance === '' ? '—' : number_format_i18n((float) $available_balance),
            'balance' => $summary,
        ]);
    }

    public function hst_define_roles()
    {
        $roles = [
            'modir'   => 'مدیر مدرسه',
            'hst_vice_edu'  => 'معاون آموزشی',
            'hst_vice_exec' => 'معاون اجرایی',
            'teacher' => 'معلم',
            'student' => 'دانش آموز',
        ];

        foreach ($roles as $role => $label) {
            if (!get_role($role)) {
                add_role($role, __($label, 'teacher-show'), ['read' => true]);
            }
        }

        $caps_by_role = [
            'administrator' => ['hst_manage_school', 'hst_manage_academic', 'hst_teach', 'hst_study'],
            'modir'         => ['hst_manage_school', 'hst_manage_academic'],
            // Vice-principals act with manager-level capabilities for AJAX, but
            // the dashboard menu + page access map (HST_Roles) restrict which
            // screens each one actually sees and can open.
            'hst_vice_edu'  => ['hst_manage_school', 'hst_manage_academic', 'hst_vice_edu'],
            'hst_vice_exec' => ['hst_manage_school', 'hst_manage_academic', 'hst_vice_exec', 'list_users', 'create_users', 'edit_users', 'delete_users'],
            'teacher'       => ['hst_teach'],
            'student'       => ['hst_study'],
        ];

        foreach ($caps_by_role as $role_name => $caps) {
            $role_object = get_role($role_name);
            if (!$role_object) {
                continue;
            }
            foreach ($caps as $cap) {
                $role_object->add_cap($cap);
            }
        }
    }

    public function ajax_save_settings(): void
    {
        if (class_exists('HST_Guard')) {
            HST_Guard::verify_ajax('hst_manage_school');
        } else {
            check_ajax_referer('hst_nonce', 'nonce');
            if (!self::can_manage_school()) {
                wp_send_json_error(['message' => 'دسترسی غیرمجاز است.'], 403);
            }
        }

        $checkbox_options = [
            self::SMS_ENABLED_OPTION,
            self::SMS_LOGIN_ENABLED_OPTION,
            self::BIRTHDAY_SMS_OPTION,
            'hst-notify-assignment-created',
            'hst-notify-assignment-submitted',
            'hst-notify-exam-created',
            'hst-notify-grade-registered',
            'hst-notify-tuition-created',
            'hst-notify-avatar-reviewed',
            'hst-pwa-enabled',
        ];

        foreach ($checkbox_options as $option_name) {
            update_option($option_name, isset($_POST[$option_name]) ? '1' : '0');
        }

        if (get_option(self::USE_PLUGIN_LOGIN_OPTION, '0') === '1') {
            self::ensure_plugin_login_page();
        }

        update_option(
            self::HOME_SCHOOL_NAME_OPTION,
            self::sanitize_limited_text(wp_unslash($_POST[self::HOME_SCHOOL_NAME_OPTION] ?? ''), 120)
        );
        update_option(
            self::HOME_TAGLINE_OPTION,
            self::sanitize_limited_text(wp_unslash($_POST[self::HOME_TAGLINE_OPTION] ?? ''), 300)
        );
        update_option(
            self::HOME_FOOTER_NOTE_OPTION,
            self::sanitize_limited_text(wp_unslash($_POST[self::HOME_FOOTER_NOTE_OPTION] ?? ''), 160)
        );
        update_option(
            self::HOME_LOGO_OPTION,
            absint(wp_unslash($_POST[self::HOME_LOGO_OPTION] ?? 0))
        );

        $birthday_default = class_exists('HST_Birthday')
            ? HST_Birthday::default_template()
            : '{name} عزیز، تولدت مبارک!';
        $birthday_template = trim(
            sanitize_textarea_field(
                wp_unslash($_POST[self::BIRTHDAY_TEMPLATE_OPTION] ?? $birthday_default)
            )
        );
        if (function_exists('mb_substr')) {
            $birthday_template = mb_substr($birthday_template, 0, 500);
        } else {
            $birthday_template = substr($birthday_template, 0, 500);
        }
        update_option(
            self::BIRTHDAY_TEMPLATE_OPTION,
            $birthday_template !== '' ? $birthday_template : $birthday_default
        );

        $sms_api_key = self::sanitize_limited_text(
            wp_unslash($_POST[self::SMS_API_KEY_OPTION] ?? ''),
            300
        );
        $sms_sender = self::sanitize_limited_text(
            wp_unslash($_POST[self::SMS_SENDER_OPTION] ?? ''),
            80
        );
        $sms_otp_pattern_code = self::sanitize_sms_pattern_identifier(
            wp_unslash($_POST[self::SMS_OTP_PATTERN_CODE_OPTION] ?? '')
        );
        $default_pattern_var = class_exists('HST_SMS') ? HST_SMS::default_otp_pattern_var() : 'OTP';
        $sms_otp_pattern_var = self::sanitize_sms_pattern_variable(
            wp_unslash($_POST[self::SMS_OTP_PATTERN_VAR_OPTION] ?? $default_pattern_var),
            $default_pattern_var
        );

        update_option(self::SMS_API_KEY_OPTION, $sms_api_key);
        update_option(self::SMS_SENDER_OPTION, $sms_sender);
        update_option(self::SMS_OTP_PATTERN_CODE_OPTION, $sms_otp_pattern_code);
        update_option(self::SMS_OTP_PATTERN_VAR_OPTION, $sms_otp_pattern_var ?: $default_pattern_var);

        wp_send_json_success([
            'message' => 'تنظیمات با موفقیت ذخیره شد.',
        ]);
    }


    private static function sanitize_sms_pattern_identifier($value): string
    {
        return class_exists('HST_SMS')
            ? HST_SMS::sanitize_pattern_identifier($value)
            : preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) $value));
    }

    private static function sanitize_sms_pattern_variable($value, string $fallback = 'message'): string
    {
        return class_exists('HST_SMS')
            ? HST_SMS::sanitize_pattern_variable($value, $fallback)
            : (preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) $value)) ?: $fallback);
    }

    private static function sanitize_limited_text($value, $max_length)
    {
        $value = trim(sanitize_text_field((string) $value));
        $max_length = max(1, absint($max_length));

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max_length);
        }

        return substr($value, 0, $max_length);
    }




    public static function option($name, $default = '')
    {
        return get_option($name, $default);
    }

    public static function enabled($name, $default = '1')
    {
        return self::option($name, $default) === '1';
    }


    public static function fixed_accent_color(): string
    {
        return '#334155';
    }

    public static function shell_mode_class(): string
    {
        return 'hst-shell--app';
    }

    
    public static function can_manage_school()
    {
        return current_user_can('manage_options') || current_user_can('hst_manage_school');
    }

    public function hst_hide_admin_bar_for_school_roles($show)
    {
        if (!is_user_logged_in()) {
            return $show;
        }

        $user = wp_get_current_user();
        $roles = (array) ($user->roles ?? []);
        $hide_for_roles = ['modir', 'hst_vice_edu', 'hst_vice_exec', 'teacher', 'student'];

        return array_intersect($hide_for_roles, $roles) ? false : $show;
    }

    private function current_user_should_hide_admin_bar()
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $user = wp_get_current_user();
        $roles = (array) ($user->roles ?? []);

        return (bool) array_intersect(['modir', 'hst_vice_edu', 'hst_vice_exec', 'teacher', 'student'], $roles);
    }

    public function hst_hide_admin_bar_styles()
    {
        if (!$this->current_user_should_hide_admin_bar()) {
            return;
        }

        echo '<style id="hst-hide-wp-admin-bar">#wpadminbar{display:none;}html.wp-toolbar{padding-top:0;}</style>';
    }

    public static function setting_keys()
    {
        return [
            'birthday_sms_enabled' => self::BIRTHDAY_SMS_OPTION,
            'birthday_template'    => self::BIRTHDAY_TEMPLATE_OPTION,
            'home_school_name'     => self::HOME_SCHOOL_NAME_OPTION,
            'home_tagline'         => self::HOME_TAGLINE_OPTION,
            'home_logo'            => self::HOME_LOGO_OPTION,
            'home_footer_note'     => self::HOME_FOOTER_NOTE_OPTION,
            'sms_enabled'          => self::SMS_ENABLED_OPTION,
            'sms_login_enabled'    => self::SMS_LOGIN_ENABLED_OPTION,
            'sms_api_key'          => self::SMS_API_KEY_OPTION,
            'sms_sender'           => self::SMS_SENDER_OPTION,
            'sms_otp_pattern_code' => self::SMS_OTP_PATTERN_CODE_OPTION,
            'sms_otp_pattern_var'  => self::SMS_OTP_PATTERN_VAR_OPTION,
        ];
    }

    private function page_has_shortcode(array $shortcodes)
    {
        if (!is_singular()) {
            return false;
        }

        global $post;
        if (!$post instanceof WP_Post) {
            return false;
        }

        foreach ($shortcodes as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                return true;
            }
        }

        return false;
    }

    public function hst_change_template($template)
    {
        if (!is_singular()) {
            return $template;
        }

        $hst_shortcodes = [
            'hst_home',
            'hst_login',
            'hst_dashboard',
            'hst_classes',
            'hst_lessons',
            'hst_terms',
            'hst_teachers',
            'hst_students',
            'hst_schedules',
            'hst_my_schedule',
            'hst_profile',
            'hst_periods',
            'hst_enter_scores',
            'hst_gradebook',
            'hst_scores',
            'hst_score_audit',
            'hst_tuition',
            'hst_tuition_payments',
            'hst_notifications',
            'hst_import_users',
            'hst_discipline',
            'hst_term_transfer',
            'hst_backup',
            'hst_plugin_settings',
            'hst_smart_analysis',
            'hst_assignments',
            'hst_attendance',
            'hst_exams',
            'hst_my_teachers',
            'hst_report_cards'
        ];

        $blank_template = trailingslashit(HST_PATH) . 'templates/blank-template.php';

        return $this->page_has_shortcode($hst_shortcodes) && file_exists($blank_template)
            ? $blank_template
            : $template;
    }


    public static function ensure_plugin_login_page(): int
    {
        $page = get_page_by_path('login');
        if ($page instanceof WP_Post) {
            if (strpos((string) $page->post_content, '[hst_login]') === false && current_user_can('hst_manage_school')) {
                wp_update_post([
                    'ID'           => $page->ID,
                    'post_content' => '[hst_login]',
                ]);
            }
            return (int) $page->ID;
        }

        $author = get_current_user_id();
        if (!$author) {
            $admins = get_users([
                'role'    => 'administrator',
                'number'  => 1,
                'fields'  => ['ID'],
                'orderby' => 'ID',
                'order'   => 'ASC',
            ]);
            $author = !empty($admins) ? (int) $admins[0]->ID : 1;
        }

        return (int) wp_insert_post([
            'post_title'   => 'ورود به سامانه',
            'post_name'    => 'login',
            'post_content' => '[hst_login]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => $author,
        ]);
    }



    public static function login_page_url($redirect_to = '')
    {
        // Prefer the plugin's own styled login page when enabled.
        if (self::enabled(self::USE_PLUGIN_LOGIN_OPTION, '0')) {
            self::ensure_plugin_login_page();
            $url = home_url('/login');
            if ($redirect_to) {
                $url = add_query_arg('redirect_to', rawurlencode($redirect_to), $url);
            }
            return $url;
        }

        return wp_login_url($redirect_to ?: home_url('/dashboard/'));
    }

    /**
     * When the plugin login page is enabled, send the default wp-login.php
     * screen to our /login page. POST submissions, logout, password flows and
     * admin re-auth are left untouched so nothing breaks.
     */
    public function hst_redirect_default_login()
    {
        if (!self::enabled(self::USE_PLUGIN_LOGIN_OPTION, '0')) {
            return;
        }

        // Only redirect plain GET requests.
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }

        // Leave WordPress's own flows (logout, registration, password reset, interim login) alone.
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : 'login';
        $allowed_actions = ['logout', 'postpass', 'register', 'lostpassword', 'retrievepassword', 'resetpass', 'rp', 'confirmaction'];
        if ($action !== 'login' || in_array($action, $allowed_actions, true)) {
            return;
        }

        // Don't hijack interim/re-auth popups inside wp-admin.
        if (!empty($_REQUEST['interim-login']) || !empty($_REQUEST['reauth'])) {
            return;
        }

        // Make sure our /login page actually exists to avoid a dead end.
        self::ensure_plugin_login_page();

        $url = home_url('/login');

        if (!empty($_REQUEST['redirect_to'])) {
            $url = add_query_arg('redirect_to', rawurlencode(esc_url_raw(wp_unslash($_REQUEST['redirect_to']))), $url);
        }
        if (isset($_GET['loggedout']) && $_GET['loggedout'] === 'true') {
            $url = add_query_arg('login', 'loggedout', $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    /**
     * When using the plugin login page, send failed logins back to it with an
     * error flag instead of the default wp-login.php screen.
     */
    public function hst_login_failed_redirect($username)
    {
        if (!self::enabled(self::USE_PLUGIN_LOGIN_OPTION, '0')) {
            return;
        }

        // Never interfere with programmatic logins.
        if (wp_doing_ajax() || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        // Only intercept logins that originated from a front-end form (not wp-admin).
        $referrer = wp_get_referer();
        if ($referrer && strpos($referrer, 'wp-login.php') !== false) {
            return;
        }

        $redirect_to = isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';
        $url = add_query_arg('login', 'failed', home_url('/login'));
        if ($redirect_to) {
            $url = add_query_arg('redirect_to', rawurlencode($redirect_to), $url);
        }

        wp_safe_redirect($url);
        exit;
    }

    /**
     * Flag empty username/password submissions from the plugin login page.
     */
    public function hst_authenticate_empty_check($user, $username, $password)
    {
        if (!self::enabled(self::USE_PLUGIN_LOGIN_OPTION, '0')) {
            return $user;
        }

        if (wp_doing_ajax() || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $user;
        }

        $referrer = wp_get_referer();
        if ($referrer && strpos($referrer, 'wp-login.php') !== false) {
            return $user;
        }

        if (($username === '' || $password === '') && !empty($_POST)) {
            $redirect_to = isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';
            $url = add_query_arg('login', 'empty', home_url('/login'));
            if ($redirect_to) {
                $url = add_query_arg('redirect_to', rawurlencode($redirect_to), $url);
            }
            wp_safe_redirect($url);
            exit;
        }

        return $user;
    }

    public function hst_logout_redirect($redirect_to, $requested_redirect_to, $user)
    {
        return self::login_page_url();
    }

    public function hst_role_login_redirect($redirect_to, $requested_redirect_to, $user)
    {
        if (!$user instanceof WP_User || empty($user->roles)) {
            return $redirect_to;
        }

        $school_roles = ['modir', 'hst_vice_edu', 'hst_vice_exec', 'teacher', 'student'];

        return array_intersect($school_roles, (array) $user->roles)
            ? home_url('/dashboard/')
            : $redirect_to;
    }

    public static function plugin_page_definitions(): array
    {
        return [
            'home'         => ['title' => 'صفحه اصلی سامانه', 'shortcode' => '[hst_home]'],
            'login'        => ['title' => 'ورود به سامانه', 'shortcode' => '[hst_login]'],
            'dashboard'    => ['title' => 'داشبورد تیچرشو', 'shortcode' => '[hst_dashboard]'],
            'classes'      => ['title' => 'کلاس‌ها', 'shortcode' => '[hst_classes]'],
            'lessons'      => ['title' => 'درس‌ها', 'shortcode' => '[hst_lessons]'],
            'terms'        => ['title' => 'سال‌های تحصیلی', 'shortcode' => '[hst_terms]'],
            'teachers'     => ['title' => 'معلمان', 'shortcode' => '[hst_teachers]'],
            'students'     => ['title' => 'دانش‌آموزان', 'shortcode' => '[hst_students]'],
            'schedules'    => ['title' => 'برنامه هفتگی', 'shortcode' => '[hst_schedules]'],
            'my-schedule'  => ['title' => 'برنامه من', 'shortcode' => '[hst_my_schedule]'],
            'profile'      => ['title' => 'پروفایل', 'shortcode' => '[hst_profile]'],
            'periods' => ['title' => 'دوره‌ها', 'shortcode' => '[hst_periods]'],
            'enter-scores' => ['title' => 'نمرهٔ دوره‌ای', 'shortcode' => '[hst_enter_scores]'],
            'gradebook' => ['title' => 'دفتر نمره', 'shortcode' => '[hst_gradebook]'],
            'scores'       => ['title' => 'نمرات', 'shortcode' => '[hst_scores]'],
            'score-audit'  => ['title' => 'ثبت نمره', 'shortcode' => '[hst_score_audit]'],
            'tuition'     => ['title' => 'شهریه', 'shortcode' => '[hst_tuition]'],
            'tuition-payments' => ['title' => 'شهریه', 'shortcode' => '[hst_tuition_payments]'],
            'notifications' => ['title' => 'اطلاعیه‌ها', 'shortcode' => '[hst_notifications]'],
            'import-users' => ['title' => 'انتقال از سیدا', 'shortcode' => '[hst_import_users]'],
            'discipline' => ['title' => 'موارد انضباطی', 'shortcode' => '[hst_discipline]'],
            'term-transfer' => ['title' => 'انتقال سال تحصیلی', 'shortcode' => '[hst_term_transfer]'],
            'backup' => ['title' => 'پشتیبان‌گیری', 'shortcode' => '[hst_backup]'],
            'plugin-settings' => ['title' => 'تنظیمات سامانه', 'shortcode' => '[hst_plugin_settings]'],
            'smart-analysis' => ['title' => 'تحلیل هوشمند', 'shortcode' => '[hst_smart_analysis]'],
            'assignments' => ['title' => 'تکالیف', 'shortcode' => '[hst_assignments]'],
            'attendance' => ['title' => 'حضور و غیاب', 'shortcode' => '[hst_attendance]'],
            'exams' => ['title' => 'آزمون‌ها', 'shortcode' => '[hst_exams]'],
            'my-teachers' => ['title' => 'معلم‌های من', 'shortcode' => '[hst_my_teachers]'],
            'report-cards' => ['title' => 'کارنامه‌ها', 'shortcode' => '[hst_report_cards]'],
        ];
    }

    public static function plugin_page_title(string $slug, string $fallback = ''): string
    {
        $slug = trim($slug, '/');
        $pages = self::plugin_page_definitions();

        return isset($pages[$slug]['title'])
            ? (string) $pages[$slug]['title']
            : $fallback;
    }

    /**
     * Build the primary heading used by manager pages from the same label
     * that appears on the manager dashboard tile.
     */
    public static function management_page_title(string $slug, string $fallback = ''): string
    {
        $title = trim(self::plugin_page_title($slug, $fallback));
        if ($title === '') {
            return 'مدیریت';
        }

        return strpos($title, 'مدیریت ') === 0 ? $title : 'مدیریت ' . $title;
    }

    public function hst_create_plugin_pages()
    {
        $pages = self::plugin_page_definitions();

        foreach ($pages as $slug => $data) {
            $page = get_page_by_path($slug);

            if ($page instanceof WP_Post) {
                $updates = ['ID' => $page->ID];
                if ((string) $page->post_title !== (string) $data['title']) {
                    $updates['post_title'] = $data['title'];
                }
                if (strpos($page->post_content, $data['shortcode']) === false) {
                    $updates['post_content'] = $data['shortcode'];
                }
                if (count($updates) > 1) {
                    wp_update_post($updates);
                }
                continue;
            }

            wp_insert_post([
                'post_title'   => $data['title'],
                'post_name'    => $slug,
                'post_content' => $data['shortcode'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => get_current_user_id() ?: 1,
            ]);
        }

    }

    /**
     * Apply the new default once for existing installations upgrading to this
     * revision. It intentionally does not keep overriding a later manual
     * WordPress front-page choice.
     */
    public function hst_apply_home_front_default_once(): void
    {
        if ((string) get_option(self::HOME_FRONT_DEFAULT_REVISION_OPTION, '') === self::HOME_FRONT_DEFAULT_REVISION) {
            return;
        }

        self::hst_activate_home_defaults();
    }

    /**
     * Activation defaults for the prepared landing page and global branding.
     */
    public static function hst_activate_home_defaults(): void
    {
        self::apply_front_page_setting(true);
        update_option(self::HOME_FRONT_DEFAULT_REVISION_OPTION, self::HOME_FRONT_DEFAULT_REVISION, false);
    }

    /**
     * Point WordPress' front page at the plugin landing page (slug "home"),
     * or revert to the latest-posts view if it was previously ours.
     */
    public static function apply_front_page_setting($use_as_front)
    {
        $home = get_page_by_path('home');
        $home_id = $home instanceof WP_Post ? (int) $home->ID : 0;

        if ($use_as_front) {
            if (!$home_id) {
                $home_id = (int) wp_insert_post([
                    'post_title'   => 'صفحه اصلی سامانه',
                    'post_name'    => 'home',
                    'post_content' => '[hst_home]',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_author'  => get_current_user_id() ?: 1,
                ]);
            }

            if ($home_id) {
                update_option('show_on_front', 'page');
                update_option('page_on_front', $home_id);
            }
            return;
        }

        // Reverting: only touch settings if WE were the configured front page.
        if ($home_id && (int) get_option('page_on_front') === $home_id) {
            update_option('show_on_front', 'posts');
            update_option('page_on_front', 0);
        }
    }
}
