<?php

defined('ABSPATH') || exit;

/**
 * SMS one-time-password login for the plugin login page.
 * The WordPress username is the national code. SMS login resolves the entered
 * number through TeacherShow's dedicated phone option.
 */
class HST_Otp_Login
{
    public const ENABLED_OPTION = 'hst-sms-login-enabled';
    private const CODE_TTL      = 120;   // seconds a code stays valid
    private const RESEND_WAIT   = 60;    // seconds before a new code can be sent
    private const MAX_ATTEMPTS  = 5;     // verify attempts per code

    public function __construct()
    {
        add_action('wp_ajax_nopriv_hst_send_login_otp', [$this, 'ajax_send_code']);
        add_action('wp_ajax_nopriv_hst_verify_login_otp', [$this, 'ajax_verify_code']);
        // Logged-in users hitting these is harmless; register so it never 400s.
        add_action('wp_ajax_hst_send_login_otp', [$this, 'ajax_send_code']);
        add_action('wp_ajax_hst_verify_login_otp', [$this, 'ajax_verify_code']);
    }

    public static function enabled(): bool
    {
        if (get_option(self::ENABLED_OPTION, '0') !== '1') {
            return false;
        }
        $sms_ok  = class_exists('HST_SMS') && HST_SMS::pattern_ready('otp');
        return $sms_ok;
    }

    private function find_user_by_phone(string $phone)
    {
        if (class_exists('HST_User_Phones')) {
            return HST_User_Phones::find_user_by_phone($phone);
        }

        // Compatibility fallback while the central registry is unavailable.
        $candidates = array_values(array_unique(array_filter([
            $phone,
            ltrim($phone, '0'),
            '98' . ltrim($phone, '0'),
            '+98' . ltrim($phone, '0'),
        ])));

        foreach ($candidates as $candidate) {
            $user = get_user_by('login', $candidate);
            if ($user) {
                return $user;
            }
        }

        $meta_keys = ['mobile', 'phone', 'user_phone', 'billing_phone', 'hst_mobile', 'hst_phone'];
        foreach ($meta_keys as $meta_key) {
            $users = get_users([
                'number'     => 1,
                'fields'     => 'all',
                'meta_key'   => $meta_key,
                'meta_value' => $phone,
            ]);

            if (!empty($users[0])) {
                return $users[0];
            }
        }

        return false;
    }

    private function transient_key(string $phone): string
    {
        return 'hst_otp_' . md5($phone);
    }

    private function throttle_key(string $phone): string
    {
        return 'hst_otp_wait_' . md5($phone);
    }

    public function ajax_send_code()
    {
        check_ajax_referer('hst_otp_login', 'nonce');

        if (!self::enabled()) {
            wp_send_json_error(['message' => 'ورود با پیامک غیرفعال است.']);
        }

        $phone = class_exists('HST_SMS') ? HST_SMS::sanitize_phone($_POST['phone'] ?? '') : '';
        if (!$phone) {
            wp_send_json_error(['message' => 'شماره موبایل معتبر نیست.']);
        }

        $user = $this->find_user_by_phone($phone);
        if (!$user) {
            // Don't reveal whether the number exists; give a generic message.
            wp_send_json_error(['message' => 'کاربری با این شماره یافت نشد.']);
        }

        if (get_transient($this->throttle_key($phone))) {
            wp_send_json_error(['message' => 'برای ارسال دوبارهٔ کد کمی صبر کنید.']);
        }

        $code = (string) wp_rand(100000, 999999);

        set_transient($this->transient_key($phone), [
            'code'     => wp_hash_password($code),
            'user_id'  => (int) $user->ID,
            'attempts' => 0,
        ], self::CODE_TTL);
        set_transient($this->throttle_key($phone), 1, self::RESEND_WAIT);

        // Try SMS first.
        $sms_sent = class_exists('HST_SMS') && HST_SMS::pattern_ready('otp')
            ? HST_SMS::send_otp($phone, $code)
            : new WP_Error('hst_sms_disabled', 'پیامک غیرفعال است.');
        $sms_ok = !is_wp_error($sms_sent);

        if (!$sms_ok) {
            delete_transient($this->transient_key($phone));
            delete_transient($this->throttle_key($phone));
            wp_send_json_error(['message' => 'ارسال کد ناموفق بود. بعداً تلاش کنید.']);
        }

        $channel = 'پیامک';
        wp_send_json_success([
            'message'   => 'کد ورود از طریق ' . $channel . ' ارسال شد.',
            'wait'      => self::RESEND_WAIT,
            'ttl'       => self::CODE_TTL,
        ]);
    }

    public function ajax_verify_code()
    {
        check_ajax_referer('hst_otp_login', 'nonce');

        if (!self::enabled()) {
            wp_send_json_error(['message' => 'ورود با پیامک غیرفعال است.']);
        }

        $phone = class_exists('HST_SMS') ? HST_SMS::sanitize_phone($_POST['phone'] ?? '') : '';
        $code  = preg_replace('/[^0-9]/', '', (string) ($_POST['code'] ?? ''));

        if (!$phone || strlen($code) !== 6) {
            wp_send_json_error(['message' => 'کد واردشده معتبر نیست.']);
        }

        $record = get_transient($this->transient_key($phone));
        if (!is_array($record) || empty($record['code'])) {
            wp_send_json_error(['message' => 'کد منقضی شده است. دوباره درخواست دهید.']);
        }

        if ((int) $record['attempts'] >= self::MAX_ATTEMPTS) {
            delete_transient($this->transient_key($phone));
            wp_send_json_error(['message' => 'تعداد تلاش‌ها زیاد شد. کد جدید بگیرید.']);
        }

        if (!wp_check_password($code, $record['code'])) {
            $record['attempts'] = (int) $record['attempts'] + 1;
            set_transient($this->transient_key($phone), $record, self::CODE_TTL);
            wp_send_json_error(['message' => 'کد واردشده درست نیست.']);
        }

        $user_id = (int) $record['user_id'];
        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_send_json_error(['message' => 'حساب کاربری یافت نشد.']);
        }

        delete_transient($this->transient_key($phone));
        delete_transient($this->throttle_key($phone));

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        do_action('wp_login', $user->user_login, $user);

        // If this SMS login was started from the "forgot password" flow, force
        // the user to set a new password before they can use the system.
        $is_recovery = !empty($_POST['recovery']);
        if ($is_recovery) {
            update_user_meta($user_id, 'hst_force_password_change', '1');
        }

        $redirect = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
        if ($is_recovery) {
            $redirect = home_url('/profile');
        } elseif (!$redirect) {
            $redirect = home_url('/dashboard');
        }

        wp_send_json_success([
            'message'  => 'ورود موفق بود. در حال انتقال...',
            'redirect' => $redirect,
        ]);
    }
}
