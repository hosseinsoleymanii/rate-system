<?php

defined('ABSPATH') || exit;

/**
 * Panelchi SMS gateway.
 *
 * Login verification codes use POST /sms/pattern. Every other TeacherShow
 * message uses POST /sms/send so managers can write the exact SMS text.
 */
class HST_SMS
{
    private const MAX_MESSAGE_LENGTH = 500;
    private const DEFAULT_TEMPLATES = [
        'notification' => "{school} اطلاعیه‌ای با عنوان «{title}» منتشر کرد.\n{message}\nتاریخ: {date}",
        'tuition'      => "{name} عزیز، شهریه «{title}» به مبلغ {amount} برای شما ثبت شد.\nمهلت پرداخت: {due_date}\n{school}",
        'discipline'   => "ولی محترم دانش‌آموز {name}، یک مورد انضباطی برای فرزند شما ثبت شد.\nموضوع: {title} - تاریخ: {incident_date}\n{school}",
        'birthday'     => "{name} عزیز، تولدت مبارک! با آرزوی بهترین‌ها — {school}",
    ];
    private const MAX_ERROR_MESSAGES = 5;
    private const API_BASE = 'https://api.panelchi.com';
    private const WALLET_CACHE_KEY = 'hst_sms_wallet_summary';
    private const TARIFF_CACHE_KEY = 'hst_sms_tariff_summary';

    public const ENABLED_OPTION = 'hst-sms-enabled';
    public const API_KEY_OPTION = 'hst-sms-api-key';
    public const SENDER_OPTION = 'hst-sms-sender';


    public const OTP_PATTERN_CODE_OPTION = 'hst-sms-otp-pattern-code';
    public const OTP_PATTERN_VAR_OPTION = 'hst-sms-otp-pattern-var';

    public function __construct()
    {
        // Core class initialization is intentionally light. Feature-specific
        // estimate endpoints live beside their recipient queries, while this
        // class provides the shared Panelchi tariff and SMS-part calculator.
    }

    public static function enabled()
    {
        return get_option(self::ENABLED_OPTION, '0') === '1';
    }

    /** Shared credentials required by direct and pattern sending. */
    public static function direct_ready(): bool
    {
        return self::enabled()
            && trim((string) get_option(self::API_KEY_OPTION, '')) !== ''
            && trim((string) get_option(self::SENDER_OPTION, '')) !== '';
    }

    /** Only the login verification code is allowed to use a pattern. */
    public static function pattern_ready(string $type): bool
    {
        if ($type !== 'otp') {
            return false;
        }

        return self::direct_ready()
            && self::sanitize_pattern_identifier(get_option(self::OTP_PATTERN_CODE_OPTION, '')) !== '';
    }

    private static function school_name(): string
    {
        $school = class_exists('HST_Settings')
            ? (string) HST_Settings::option('hst-home-school-name', get_bloginfo('name'))
            : (string) get_bloginfo('name');

        return trim($school) !== '' ? $school : 'مدرسه';
    }

    public static function default_otp_pattern_var()
    {
        return 'OTP';
    }


    public static function default_template(string $type): string
    {
        return self::DEFAULT_TEMPLATES[$type] ?? '';
    }

    /**
     * Return a safe editable template. Legacy JSON pattern configuration is
     * automatically replaced by the new direct-SMS default.
     */
    public static function message_template($value, string $type): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return self::default_template($type);
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded) && isset($decoded['vars'])) {
            return self::default_template($type);
        }

        return self::normalize_message($value);
    }

    /**
     * Sanitize a Panelchi pattern slug.
     */
    public static function sanitize_pattern_identifier($value)
    {
        $value = trim((string) $value);
        $value = preg_replace('/[^A-Za-z0-9_-]/', '', $value);

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 160);
        }

        return substr($value, 0, 160);
    }

    /**
     * Sanitize a Panelchi pattern variable name while preserving its case.
     * Pattern variable keys are dynamic and may be case-sensitive, therefore
     * values such as "code" must not be silently converted to "OTP".
     */
    public static function sanitize_pattern_variable($value, $fallback = 'message')
    {
        $value = trim((string) $value);
        $value = trim($value, "{}%٪/\\ \t\n\r\0\x0B");
        $value = preg_replace('/[^A-Za-z0-9_-]/', '', $value);

        if ($value === '') {
            $value = (string) $fallback;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 40);
        }

        return substr($value, 0, 40);
    }

    private static function sanitize_pattern_payload_key($key)
    {
        // Panelchi expects bare variable names in the JSON object. Example:
        // pattern text: %name%  |  API payload: {"variables":{"name":"علی"}}
        return self::sanitize_pattern_variable($key, '');
    }

    private static function context_template(array $context, string $type): string
    {
        $template = $context['sms_template'] ?? $context['template'] ?? '';
        unset($context['sms_template'], $context['template']);
        return self::message_template($template, $type);
    }

    public static function send_notification($phone, array $context)
    {
        $context = wp_parse_args($context, [
            'name'    => 'کاربر',
            'title'   => 'اطلاعیه',
            'message' => '',
            'type'    => 'اطلاعیه',
            'date'    => function_exists('date_i18n') ? date_i18n('Y/m/d') : date('Y/m/d'),
            'school'  => self::school_name(),
        ]);
        $template = self::context_template($context, 'notification');
        return self::send($phone, self::render_message($template, $context), 'اطلاعیه');
    }

    public static function send_tuition($phone, array $context)
    {
        $context = wp_parse_args($context, [
            'name'     => 'دانش‌آموز',
            'title'    => 'شهریه',
            'amount'   => '۰ تومان',
            'due_date' => 'بدون مهلت',
            'date'     => function_exists('date_i18n') ? date_i18n('Y/m/d') : date('Y/m/d'),
            'school'   => self::school_name(),
        ]);
        $template = self::context_template($context, 'tuition');
        return self::send($phone, self::render_message($template, $context), 'شهریه');
    }

    public static function send_discipline($phone, array $context)
    {
        $context = wp_parse_args($context, [
            'name'          => 'دانش‌آموز',
            'title'         => 'مورد انضباطی',
            'type'          => 'مورد انضباطی',
            'severity'      => '',
            'description'   => '',
            'incident_date' => function_exists('date_i18n') ? date_i18n('Y/m/d') : date('Y/m/d'),
            'date'          => function_exists('date_i18n') ? date_i18n('Y/m/d') : date('Y/m/d'),
            'school'        => self::school_name(),
        ]);
        $template = self::context_template($context, 'discipline');
        return self::send($phone, self::render_message($template, $context), 'مورد انضباطی');
    }

    public static function send_birthday($phone, array $context)
    {
        $context = wp_parse_args($context, [
            'name'   => 'کاربر',
            'school' => self::school_name(),
        ]);
        $template = self::context_template($context, 'birthday');
        return self::send($phone, self::render_message($template, $context), 'تبریک تولد');
    }

    /**
     * Normalize an Iranian mobile number to local format (09xxxxxxxxx).
     */
    public static function sanitize_phone($phone)
    {
        if (class_exists('HST_User_Phones')) {
            return HST_User_Phones::normalize_phone($phone);
        }

        $phone = trim((string) $phone);
        $phone = str_replace([' ', '-', '(', ')'], '', $phone);

        if (strpos($phone, '+98') === 0) {
            $phone = '0' . substr($phone, 3);
        } elseif (strpos($phone, '0098') === 0) {
            $phone = '0' . substr($phone, 4);
        }

        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($phone) === 10 && strpos($phone, '9') === 0) {
            $phone = '0' . $phone;
        }

        if (strlen($phone) === 12 && strpos($phone, '98') === 0) {
            $phone = '0' . substr($phone, 2);
        }

        return preg_match('/^09[0-9]{9}$/', $phone) ? $phone : '';
    }

    /**
     * Pattern sending uses the documented E.164 recipient format (+98...).
     */
    private static function api_recipient($phone)
    {
        $phone = self::sanitize_phone($phone);
        if ($phone === '') {
            return '';
        }

        return '+98' . substr($phone, 1);
    }

    public static function user_phone($user_id)
    {
        if (class_exists('HST_User_Phones')) {
            return HST_User_Phones::get(absint($user_id));
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return '';
        }

        // Compatibility fallback for installations loading this class before
        // the central TeacherShow phone registry.
        $candidates = [
            get_user_meta($user_id, 'phone', true),
            get_user_meta($user_id, 'mobile', true),
            get_user_meta($user_id, 'billing_phone', true),
            $user->user_login,
        ];

        foreach ($candidates as $candidate) {
            $phone = self::sanitize_phone($candidate);
            if ($phone !== '') {
                return $phone;
            }
        }

        return '';
    }

    public static function render_message($template, array $context = [])
    {
        $template = wp_strip_all_tags((string) $template);

        $replacements = [];
        foreach ($context as $key => $value) {
            $key = sanitize_key($key);
            if ($key === '' || in_array($key, ['sms_template', 'template'], true)) {
                continue;
            }
            $value = wp_strip_all_tags((string) $value);
            $replacements['{' . $key . '}'] = $value;
            $replacements['%' . $key . '%'] = $value;
        }

        $message = trim(strtr($template, $replacements));

        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
        }

        return substr($message, 0, self::MAX_MESSAGE_LENGTH);
    }

    private static function normalize_message($message)
    {
        $message = trim(wp_strip_all_tags((string) $message));

        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
        }

        return substr($message, 0, self::MAX_MESSAGE_LENGTH);
    }

    public static function send_otp($phone, $code)
    {
        $phone = self::sanitize_phone($phone);
        $code = preg_replace('/[^0-9]/', '', (string) $code);

        if (!$phone || !$code) {
            return new WP_Error('hst_sms_invalid_payload', 'شماره موبایل یا کد تأیید نامعتبر است.');
        }

        $pattern = self::sanitize_pattern_identifier(get_option(self::OTP_PATTERN_CODE_OPTION, ''));
        $variable = self::sanitize_pattern_variable(
            get_option(self::OTP_PATTERN_VAR_OPTION, self::default_otp_pattern_var()),
            self::default_otp_pattern_var()
        );

        return self::send_pattern($phone, $pattern, [$variable => $code], 'کد تأیید');
    }

    public static function send($phone, $message, string $label = '')
    {
        return self::send_direct($phone, $message);
    }

    private static function send_direct($phone, $message)
    {
        if (!self::enabled()) {
            return new WP_Error('hst_sms_disabled', 'سرویس پیامک سامانه غیرفعال است.');
        }

        $token = trim((string) get_option(self::API_KEY_OPTION, ''));
        $sender = trim((string) get_option(self::SENDER_OPTION, ''));
        $recipient = self::sanitize_phone($phone);
        $message = self::normalize_message($message);

        if (!$token || !$sender) {
            return new WP_Error('hst_sms_missing_config', 'توکن API یا سرشماره Panelchi تنظیم نشده است.');
        }
        if ($recipient === '') {
            return new WP_Error('hst_sms_invalid_payload', 'شماره موبایل نامعتبر است.');
        }
        if ($message === '') {
            return new WP_Error('hst_sms_empty_message', 'متن پیامک نمی‌تواند خالی باشد.');
        }

        return self::remote_json(self::API_BASE . '/sms/send', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json; charset=utf-8',
                'Accept'        => 'application/json',
                'User-Agent'    => 'TeacherShow/' . (defined('HST_VERSION') ? HST_VERSION : '1.0.74'),
            ],
            'body' => wp_json_encode([
                'message'      => $message,
                'recipients'   => [$recipient],
                'sourceNumber' => $sender,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private static function send_pattern(string $phone, string $pattern, array $variables, string $label = '')
    {
        if (!self::enabled()) {
            return new WP_Error('hst_sms_disabled', 'سرویس پیامک سامانه غیرفعال است.');
        }

        $token = trim((string) get_option(self::API_KEY_OPTION, ''));
        $sender = trim((string) get_option(self::SENDER_OPTION, ''));
        $recipient = self::api_recipient($phone);
        $pattern = self::sanitize_pattern_identifier($pattern);

        if (!$token || !$sender) {
            return new WP_Error('hst_sms_missing_config', 'توکن API یا سرشماره Panelchi تنظیم نشده است.');
        }

        if (!$recipient) {
            return new WP_Error('hst_sms_invalid_payload', 'شماره موبایل نامعتبر است.');
        }

        if (!$pattern) {
            return new WP_Error(
                'hst_sms_missing_pattern',
                ($label ? 'اسلاگ پترن ' . $label : 'اسلاگ پترن پیامک') . ' تنظیم نشده است.'
            );
        }

        $clean_variables = [];
        foreach ($variables as $key => $value) {
            $key = self::sanitize_pattern_payload_key($key);
            $value = self::normalize_message($value);
            if ($key !== '' && $value !== '') {
                $clean_variables[$key] = $value;
            }
        }

        if (!$clean_variables) {
            return new WP_Error('hst_sms_invalid_payload', 'متغیرهای پترن پیامک معتبر نیستند.');
        }

        return self::remote_json(self::API_BASE . '/sms/pattern', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json; charset=utf-8',
                'Accept'        => 'application/json',
                'User-Agent'    => 'TeacherShow/' . (defined('HST_VERSION') ? HST_VERSION : '1.0.74'),
            ],
            'body' => wp_json_encode([
                'pattern'      => $pattern,
                'variables'    => $clean_variables,
                'recipient'    => $recipient,
                'sourceNumber' => $sender,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public static function forget_wallet_cache(): void
    {
        delete_transient(self::WALLET_CACHE_KEY);
        delete_transient(self::TARIFF_CACHE_KEY);
    }

    public static function forget_tariff_cache(): void
    {
        delete_transient(self::TARIFF_CACHE_KEY);
    }

    /**
     * Fetch the authenticated user's current Panelchi plan, base tariff and
     * textual coefficients. The endpoint is documented as GET /plan/tariff.
     */
    public static function tariff_summary()
    {
        $token = trim((string) get_option(self::API_KEY_OPTION, ''));
        if ($token === '') {
            return new WP_Error('hst_sms_missing_token', 'توکن API Panelchi تنظیم نشده است.');
        }

        $response = wp_remote_request(self::API_BASE . '/plan/tariff', [
            'timeout' => 20,
            'method'  => 'GET',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'User-Agent'    => 'TeacherShow/' . (defined('HST_VERSION') ? HST_VERSION : '1.0.232'),
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            return self::panelchi_error($code, $body, 'دریافت تعرفه پیامک');
        }

        $data = is_array($body) && isset($body['data']) && is_array($body['data']) ? $body['data'] : null;
        if (!is_array($data)) {
            return new WP_Error('hst_sms_tariff_invalid', 'پاسخ تعرفه Panelchi معتبر نیست.');
        }

        $plan = is_array($data['plan'] ?? null) ? $data['plan'] : [];
        $tariff = is_array($data['tariff'] ?? null) ? $data['tariff'] : [];
        $rows = is_array($data['textsCoefficients'] ?? null) ? $data['textsCoefficients'] : [];
        $sender = trim((string) get_option(self::SENDER_OPTION, ''));
        $selected = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = preg_replace('/\D+/u', '', (string) ($row['title'] ?? ''));
            $sender_digits = preg_replace('/\D+/u', '', $sender);
            if ($sender_digits !== '' && $title === $sender_digits) {
                $selected = $row;
                break;
            }
        }
        if (!$selected && isset($rows[0]) && is_array($rows[0])) {
            $selected = $rows[0];
        }

        return [
            'plan_title'    => sanitize_text_field((string) ($plan['title'] ?? '')),
            'tariff_title'  => sanitize_text_field((string) ($tariff['title'] ?? '')),
            'tariff_price'  => self::numeric_value($tariff['price'] ?? 0, 0.0),
            'tariff_slug'   => sanitize_key((string) ($tariff['slug'] ?? '')),
            'source_number' => sanitize_text_field($sender),
            'coefficients'  => [
                'PERSIAN'   => self::numeric_value($selected['PERSIAN'] ?? 1, 1.0),
                'ENGLISH'   => self::numeric_value($selected['ENGLISH'] ?? 1, 1.0),
                'MCI'       => self::numeric_value($selected['MCI'] ?? 1, 1.0),
                'MTN'       => self::numeric_value($selected['MTN'] ?? 1, 1.0),
                'OTHER'     => 1.0,
                'WEBSERVICE'=> self::numeric_value($selected['WEBSERVICE'] ?? 1, 1.0),
                'PATTERN'   => self::numeric_value($selected['PATTERN'] ?? 1, 1.0),
            ],
        ];
    }

    public static function cached_tariff_summary(int $ttl = 1800)
    {
        $cached = get_transient(self::TARIFF_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $summary = self::tariff_summary();
        if (!is_wp_error($summary)) {
            set_transient(self::TARIFF_CACHE_KEY, $summary, max(300, $ttl));
        }

        return $summary;
    }

    /**
     * Calculate SMS segmentation using GSM 03.38 for Latin-only content and
     * UCS-2/UTF-16 units for Persian or other Unicode content.
     */
    public static function message_parts($message): array
    {
        $message = str_replace(["\r\n", "\r"], "\n", (string) $message);
        if ($message === '') {
            return [
                'encoding' => 'unicode',
                'language' => 'PERSIAN',
                'length' => 0,
                'parts' => 0,
                'single_limit' => 70,
                'multipart_limit' => 67,
                'remaining' => 70,
            ];
        }

        $gsm_length = self::gsm_septet_length($message);
        if ($gsm_length !== null) {
            $single = 160;
            $multi = 153;
            $parts = $gsm_length <= $single ? 1 : (int) ceil($gsm_length / $multi);
            $capacity = $parts === 1 ? $single : $parts * $multi;
            return [
                'encoding' => 'gsm',
                'language' => 'ENGLISH',
                'length' => $gsm_length,
                'parts' => $parts,
                'single_limit' => $single,
                'multipart_limit' => $multi,
                'remaining' => max(0, $capacity - $gsm_length),
            ];
        }

        $length = self::utf16_units($message);
        $single = 70;
        $multi = 67;
        $parts = $length <= $single ? 1 : (int) ceil($length / $multi);
        $capacity = $parts === 1 ? $single : $parts * $multi;
        return [
            'encoding' => 'unicode',
            'language' => 'PERSIAN',
            'length' => $length,
            'parts' => $parts,
            'single_limit' => $single,
            'multipart_limit' => $multi,
            'remaining' => max(0, $capacity - $length),
        ];
    }

    /**
     * Estimate aggregate consumption for fully rendered recipient messages.
     * Each item should contain `message` and may contain `phone`.
     */
    public static function estimate_consumption(array $items, bool $pattern = false): array
    {
        $tariff = self::cached_tariff_summary();
        $tariff_available = !is_wp_error($tariff) && is_array($tariff);
        $coefficients = $tariff_available ? (array) ($tariff['coefficients'] ?? []) : [];
        $channel_key = $pattern ? 'PATTERN' : 'WEBSERVICE';
        $channel_coefficient = self::numeric_value($coefficients[$channel_key] ?? 1, 1.0);

        $valid = 0;
        $raw_parts = 0;
        $estimated_units = 0.0;
        $min_parts = null;
        $max_parts = 0;
        $languages = [];
        $representative = self::message_parts('');

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $message = (string) ($item['message'] ?? '');
            $phone = self::sanitize_phone($item['phone'] ?? '');
            if ($message === '' || $phone === '') {
                continue;
            }

            $parts = self::message_parts($message);
            if ($valid === 0) {
                $representative = $parts;
            }
            $valid++;
            $raw_parts += (int) $parts['parts'];
            $min_parts = $min_parts === null ? (int) $parts['parts'] : min($min_parts, (int) $parts['parts']);
            $max_parts = max($max_parts, (int) $parts['parts']);
            $languages[(string) $parts['language']] = true;

            $language_coefficient = self::numeric_value($coefficients[$parts['language']] ?? 1, 1.0);
            $operator = self::phone_operator($phone);
            $operator_coefficient = self::numeric_value($coefficients[$operator] ?? 1, 1.0);
            $estimated_units += (int) $parts['parts'] * $language_coefficient * $operator_coefficient * $channel_coefficient;
        }

        $base_price = $tariff_available ? self::numeric_value($tariff['tariff_price'] ?? 0, 0.0) : 0.0;

        return [
            'recipient_count' => $valid,
            'raw_parts' => $raw_parts,
            'estimated_units' => round($estimated_units, 2),
            'min_parts' => $min_parts ?? 0,
            'max_parts' => $max_parts,
            'mixed_lengths' => $min_parts !== null && $min_parts !== $max_parts,
            'language' => count($languages) > 1 ? 'MIXED' : (array_key_first($languages) ?: (string) $representative['language']),
            'preview' => $representative,
            'tariff_available' => $tariff_available,
            'tariff_error' => is_wp_error($tariff) ? $tariff->get_error_message() : '',
            'plan_title' => $tariff_available ? (string) ($tariff['plan_title'] ?? '') : '',
            'tariff_title' => $tariff_available ? (string) ($tariff['tariff_title'] ?? '') : '',
            'tariff_price' => $base_price,
            'estimated_price' => $base_price > 0 ? round($estimated_units * $base_price, 2) : 0,
        ];
    }

    private static function numeric_value($value, float $fallback = 1.0): float
    {
        if (is_string($value)) {
            $value = strtr(trim($value), [
                '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
                '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
                '،'=>'.', ','=>'',
            ]);
        }
        return is_numeric($value) ? max(0.0, (float) $value) : $fallback;
    }

    private static function phone_operator(string $phone): string
    {
        $phone = self::sanitize_phone($phone);
        if ($phone === '') {
            return 'OTHER';
        }

        if (preg_match('/^09(?:1[0-9]|90|91|92|93|94)/', $phone)) {
            return 'MCI';
        }
        if (preg_match('/^09(?:0[1-5]|30|33|3[5-9])/', $phone)) {
            return 'MTN';
        }
        return 'OTHER';
    }

    private static function gsm_septet_length(string $message): ?int
    {
        $basic = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
        $extended = '^{}\\[~]|€';
        $length = 0;

        $characters = preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            return null;
        }
        foreach ($characters as $character) {
            $in_basic = function_exists('mb_strpos')
                ? mb_strpos($basic, $character, 0, 'UTF-8') !== false
                : strpos($basic, $character) !== false;
            if ($in_basic) {
                $length++;
                continue;
            }
            $in_extended = function_exists('mb_strpos')
                ? mb_strpos($extended, $character, 0, 'UTF-8') !== false
                : strpos($extended, $character) !== false;
            if ($in_extended) {
                $length += 2;
                continue;
            }
            return null;
        }
        return $length;
    }

    private static function utf16_units(string $message): int
    {
        if (function_exists('mb_convert_encoding')) {
            return (int) (strlen(mb_convert_encoding($message, 'UTF-16BE', 'UTF-8')) / 2);
        }
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($message, 'UTF-8');
        }
        preg_match_all('/./us', $message, $matches);
        return count($matches[0] ?? []);
    }

    public static function cached_wallet_summary(int $ttl = 300)
    {
        $cached = get_transient(self::WALLET_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $summary = self::wallet_summary();
        if (!is_wp_error($summary)) {
            set_transient(self::WALLET_CACHE_KEY, $summary, max(60, $ttl));
        }

        return $summary;
    }

    public static function account_info()
    {
        $token = trim((string) get_option(self::API_KEY_OPTION, ''));

        if (!$token) {
            return new WP_Error('hst_sms_missing_token', 'توکن API Panelchi تنظیم نشده است.');
        }

        $response = wp_remote_request(self::API_BASE . '/account/info', [
            'timeout' => 20,
            'method'  => 'GET',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            return self::panelchi_error($code, $body, 'دریافت اطلاعات حساب پیامکی');
        }

        if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
            return new WP_Error('hst_sms_account_info_invalid', 'پاسخ اطلاعات حساب Panelchi معتبر نیست.');
        }

        return $body['data'];
    }

    public static function wallet_summary()
    {
        $data = self::account_info();
        if (is_wp_error($data)) {
            return $data;
        }

        $wallet = is_array($data['wallet'] ?? null) ? $data['wallet'] : [];
        $account = is_array($data['accountInfo'] ?? null) ? $data['accountInfo'] : [];
        $plan = is_array($account['plan'] ?? null) ? $account['plan'] : [];

        return [
            'available_balance' => (string) ($wallet['availableBalance'] ?? ''),
            'locked_balance'    => (string) ($wallet['lockedBalance'] ?? ''),
            'total_balance'     => (string) ($wallet['totalBalance'] ?? ''),
            'unit'              => (string) ($wallet['unit'] ?? ''),
            'username'          => (string) ($account['username'] ?? ''),
            'full_name'         => (string) ($account['fullName'] ?? ''),
            'status'            => (string) ($account['status'] ?? ''),
            'plan_title'        => (string) ($plan['title'] ?? ''),
        ];
    }

    private static function remote_json($url, array $args)
    {
        $response = wp_remote_request($url, wp_parse_args($args, [
            'timeout' => 20,
            'method'  => 'POST',
        ]));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            return self::panelchi_error($code, $body, 'ارسال پیامک');
        }

        $status = is_array($body) ? strtoupper((string) ($body['data']['status'] ?? '')) : '';
        if (in_array($status, ['REJECTED', 'CANCELED', 'FAILED', 'NO_BALANCE'], true)) {
            return new WP_Error(
                'hst_sms_rejected',
                'درخواست پیامک توسط Panelchi پذیرفته نشد. وضعیت: ' . $status
            );
        }

        return true;
    }

    private static function panelchi_error(int $http_code, $body, string $action)
    {
        $title = '';
        $detail = '';
        $domain_code = '';
        $extra = [];
        $request_id = '';

        if (is_array($body)) {
            $title = wp_strip_all_tags((string) ($body['title'] ?? ''));
            $detail = wp_strip_all_tags((string) ($body['detail'] ?? ''));
            $domain_code = isset($body['code']) ? (string) $body['code'] : '';
            $request_id = wp_strip_all_tags((string) ($body['meta']['requestId'] ?? $body['requestId'] ?? ''));

            if (isset($body['extra']) && is_array($body['extra'])) {
                foreach (array_slice($body['extra'], 0, self::MAX_ERROR_MESSAGES) as $item) {
                    $item = wp_strip_all_tags((string) $item);
                    if ($item !== '') {
                        $extra[] = $item;
                    }
                }
            }
        }

        $parts = [$action . ' با خطای Panelchi مواجه شد.', 'HTTP ' . $http_code];
        if ($domain_code !== '') {
            $parts[] = 'کد ' . $domain_code;
        }
        if ($detail !== '') {
            $parts[] = $detail;
        } elseif ($title !== '') {
            $parts[] = $title;
        }
        if ($extra) {
            $parts[] = implode('؛ ', $extra);
        }
        if ($request_id !== '') {
            $parts[] = 'شناسه درخواست: ' . $request_id;
        }

        return new WP_Error('hst_sms_panelchi_error', implode(' - ', $parts));
    }
}
