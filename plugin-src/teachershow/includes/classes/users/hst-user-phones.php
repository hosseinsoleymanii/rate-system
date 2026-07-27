<?php

defined('ABSPATH') || exit;

/**
 * Canonical identity and mobile-number storage for TeacherShow users.
 *
 * National code is the WordPress username for plugin-managed students and
 * teachers. Mobile numbers live in one dedicated, non-autoloaded option so
 * login by SMS and all communication modules read the same value.
 */
class HST_User_Phones
{
    public const OPTION_KEY = 'hst-user-phone-numbers';
    private const LEGACY_INDEX_OPTION = 'hst-user-phone-index-version';
    private const LEGACY_INDEX_VERSION = '1';
    private const MIGRATION_OPTION = 'hst-user-identity-storage-version';
    private const MIGRATION_CURSOR_OPTION = 'hst-user-identity-storage-cursor';
    private const MIGRATION_VERSION = '1';
    private const MIGRATION_BATCH_SIZE = 25;

    /** @var array<int,string>|null */
    private static $phones = null;

    /** @var array<string,int>|null */
    private static $owners = null;

    private static $batch_depth = 0;
    private static $dirty = false;
    private static $shutdown_registered = false;
    private static $legacy_index_primed = false;

    public function __construct()
    {
        add_action('init', [__CLASS__, 'migrate_legacy_users'], 7);
        add_action('deleted_user', [__CLASS__, 'delete'], 10, 1);
    }

    public static function normalize_phone($value): string
    {
        $value = self::english_digits((string) $value);
        $value = preg_replace('/[^0-9+]/', '', trim($value));
        $value = is_string($value) ? $value : '';

        if (strpos($value, '+98') === 0) {
            $value = '0' . substr($value, 3);
        } elseif (strpos($value, '0098') === 0) {
            $value = '0' . substr($value, 4);
        } elseif (strlen($value) === 12 && strpos($value, '98') === 0) {
            $value = '0' . substr($value, 2);
        } elseif (strlen($value) === 10 && strpos($value, '9') === 0) {
            $value = '0' . $value;
        }

        return preg_match('/^09[0-9]{9}$/', $value) ? $value : '';
    }

    public static function normalize_national_code($value): string
    {
        $value = self::english_digits((string) $value);
        $value = preg_replace('/[^0-9]/', '', $value);
        return is_string($value) && preg_match('/^[0-9]{10}$/', $value) ? $value : '';
    }

    /**
     * Keep a whole import request in memory and write the shared option once.
     * The shutdown fallback prevents losing the final map when WordPress ends
     * an AJAX response through wp_die().
     */
    public static function begin_batch(bool $prime_legacy = true): void
    {
        self::all();
        self::$batch_depth++;

        if (!self::$shutdown_registered) {
            self::$shutdown_registered = true;
            register_shutdown_function([__CLASS__, 'shutdown_flush']);
        }

        if ($prime_legacy) {
            self::prime_legacy_index();
        }
    }

    public static function end_batch(): void
    {
        if (self::$batch_depth > 0) {
            self::$batch_depth--;
        }

        if (self::$batch_depth === 0) {
            self::flush();
        }
    }

    public static function shutdown_flush(): void
    {
        self::$batch_depth = 0;
        self::flush();
    }

    public static function get(int $user_id, bool $migrate_legacy = true): string
    {
        if ($user_id < 1) {
            return '';
        }

        $phones = self::all();
        if (!empty($phones[$user_id])) {
            return $phones[$user_id];
        }

        if (!$migrate_legacy) {
            return '';
        }

        $phone = self::legacy_phone($user_id);
        if ($phone !== '') {
            $saved = self::set($user_id, $phone);
            if (!is_wp_error($saved)) {
                return $phone;
            }
        }

        return '';
    }

    /**
     * Save the canonical mobile number and keep the historical `phone` meta as
     * a compatibility mirror for old backups and third-party integrations.
     *
     * @return true|WP_Error
     */
    public static function set(int $user_id, $phone)
    {
        if ($user_id < 1 || !get_userdata($user_id)) {
            return new WP_Error('hst_phone_user_missing', 'کاربر موردنظر یافت نشد.');
        }

        $phone = self::normalize_phone($phone);
        if ($phone === '') {
            return new WP_Error('hst_phone_invalid', 'شماره موبایل باید با 09 شروع شود و 11 رقم باشد.');
        }

        // A batch has already loaded every historical phone with one query, so
        // a per-row legacy lookup would only recreate the old O(n²) bottleneck.
        $owner = self::owner($phone, self::$batch_depth === 0);
        if ($owner && $owner !== $user_id) {
            return new WP_Error('hst_phone_duplicate', 'این شماره موبایل قبلاً برای کاربر دیگری ثبت شده است.');
        }

        self::assign($user_id, $phone);
        update_user_meta($user_id, 'phone', $phone);

        return true;
    }

    public static function owner($phone, bool $search_legacy = true): int
    {
        $phone = self::normalize_phone($phone);
        if ($phone === '') {
            return 0;
        }

        self::all();
        if (!empty(self::$owners[$phone])) {
            return (int) self::$owners[$phone];
        }

        if (!$search_legacy) {
            return 0;
        }

        $legacy_user_id = self::legacy_owner($phone);
        if ($legacy_user_id) {
            self::assign($legacy_user_id, $phone);
            update_user_meta($legacy_user_id, 'phone', $phone);
        }

        return $legacy_user_id;
    }

    public static function find_user_by_phone($phone)
    {
        $user_id = self::owner($phone);
        return $user_id ? get_userdata($user_id) : false;
    }

    /**
     * Make national code the WordPress username without recreating the user.
     *
     * @return true|WP_Error
     */
    public static function sync_username(int $user_id, $national_code)
    {
        global $wpdb;

        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('hst_identity_user_missing', 'کاربر موردنظر یافت نشد.');
        }

        $national_code = self::normalize_national_code($national_code);
        if ($national_code === '') {
            return new WP_Error('hst_identity_invalid_national_code', 'کد ملی باید دقیقاً 10 رقم باشد.');
        }

        $owner = username_exists($national_code);
        if ($owner && (int) $owner !== $user_id) {
            return new WP_Error('hst_identity_duplicate_national_code', 'این کد ملی قبلاً به‌عنوان نام کاربری ثبت شده است.');
        }

        if ((string) $user->user_login !== $national_code) {
            $updated = $wpdb->update(
                $wpdb->users,
                [
                    'user_login'    => $national_code,
                    'user_nicename' => sanitize_title($national_code),
                ],
                ['ID' => $user_id],
                ['%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                return new WP_Error('hst_identity_update_failed', 'تغییر نام کاربری به کد ملی انجام نشد.');
            }

            clean_user_cache($user_id);
        }

        update_user_meta($user_id, 'hst_national_code', $national_code);
        return true;
    }

    public static function delete(int $user_id): void
    {
        $phones = self::all();
        if (!isset($phones[$user_id])) {
            return;
        }

        $old_phone = $phones[$user_id];
        unset($phones[$user_id]);
        if (isset(self::$owners[$old_phone]) && (int) self::$owners[$old_phone] === $user_id) {
            unset(self::$owners[$old_phone]);
        }
        self::store($phones);
    }

    /**
     * Migrate a bounded number of old users on each request. Earlier code tried
     * to migrate every user during init, which could make the first page request
     * exceed the PHP/web-server timeout.
     */
    public static function migrate_legacy_users(): void
    {
        global $wpdb;

        if ((string) get_option(self::MIGRATION_OPTION, '') === self::MIGRATION_VERSION) {
            return;
        }

        $cursor = absint(get_option(self::MIGRATION_CURSOR_OPTION, 0));
        $limit = self::MIGRATION_BATCH_SIZE + 1;
        $capability_key = $wpdb->prefix . 'capabilities';

        $sql = $wpdb->prepare(
            "SELECT DISTINCT u.ID
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} capabilities
                ON capabilities.user_id = u.ID
               AND capabilities.meta_key = %s
             WHERE u.ID > %d
               AND (capabilities.meta_value LIKE %s OR capabilities.meta_value LIKE %s)
             ORDER BY u.ID ASC
             LIMIT %d",
            $capability_key,
            $cursor,
            '%"student"%',
            '%"teacher"%',
            $limit
        );

        $user_ids = array_map('intval', $wpdb->get_col($sql) ?: []);
        $has_more = count($user_ids) > self::MIGRATION_BATCH_SIZE;
        $user_ids = array_slice($user_ids, 0, self::MIGRATION_BATCH_SIZE);

        self::begin_batch(true);

        foreach ($user_ids as $user_id) {
            $phone = self::legacy_phone($user_id);
            $owner = $phone !== '' ? self::owner($phone, false) : 0;
            if ($phone !== '' && (!$owner || $owner === $user_id)) {
                self::set($user_id, $phone);
            }

            $national_code = get_user_meta($user_id, 'hst_national_code', true);
            if ($national_code === '') {
                $national_code = get_user_meta($user_id, 'hst_student_code', true);
            }

            $national_code = self::migration_national_code($national_code);
            if ($national_code === '') {
                $user = get_userdata($user_id);
                $national_code = $user ? self::normalize_national_code($user->user_login) : '';
            }

            if ($national_code !== '') {
                self::sync_username($user_id, $national_code);
            }
        }

        self::end_batch();

        if ($has_more && !empty($user_ids)) {
            update_option(self::MIGRATION_CURSOR_OPTION, (int) end($user_ids), false);
            return;
        }

        delete_option(self::MIGRATION_CURSOR_OPTION);
        update_option(self::MIGRATION_OPTION, self::MIGRATION_VERSION, false);
    }

    /** @return array<int,string> */
    private static function all(): array
    {
        if (is_array(self::$phones)) {
            return self::$phones;
        }

        $stored = get_option(self::OPTION_KEY, []);
        $phones = [];
        $owners = [];
        foreach (is_array($stored) ? $stored : [] as $user_id => $phone) {
            $user_id = absint($user_id);
            $phone = self::normalize_phone($phone);
            if ($user_id && $phone !== '') {
                $phones[$user_id] = $phone;
                if (!isset($owners[$phone])) {
                    $owners[$phone] = $user_id;
                }
            }
        }

        self::$phones = $phones;
        self::$owners = $owners;
        return self::$phones;
    }

    private static function assign(int $user_id, string $phone): void
    {
        $phones = self::all();
        $old_phone = (string) ($phones[$user_id] ?? '');

        if ($old_phone !== '' && $old_phone !== $phone && isset(self::$owners[$old_phone])
            && (int) self::$owners[$old_phone] === $user_id) {
            unset(self::$owners[$old_phone]);
        }

        $phones[$user_id] = $phone;
        self::$owners[$phone] = $user_id;
        self::store($phones);
    }

    /** @param array<int,string> $phones */
    private static function store(array $phones): void
    {
        ksort($phones, SORT_NUMERIC);
        self::$phones = $phones;

        if (self::$batch_depth > 0) {
            self::$dirty = true;
            return;
        }

        self::persist();
    }

    private static function flush(): void
    {
        if (!self::$dirty || !is_array(self::$phones)) {
            return;
        }

        self::persist();
    }

    private static function persist(): void
    {
        $phones = is_array(self::$phones) ? self::$phones : [];
        self::$dirty = false;

        if (get_option(self::OPTION_KEY, null) === null) {
            add_option(self::OPTION_KEY, $phones, '', false);
            return;
        }

        update_option(self::OPTION_KEY, $phones, false);
    }

    /**
     * Build the legacy reverse index with one SQL query instead of up to seven
     * database searches for every imported row.
     */
    private static function prime_legacy_index(): void
    {
        global $wpdb;

        if (self::$legacy_index_primed) {
            return;
        }
        self::$legacy_index_primed = true;
        self::all();

        if ((string) get_option(self::LEGACY_INDEX_OPTION, '') === self::LEGACY_INDEX_VERSION
            && get_option(self::OPTION_KEY, null) !== null) {
            return;
        }

        $meta_keys = ['phone', 'mobile', 'user_phone', 'billing_phone', 'hst_mobile', 'hst_phone'];
        $quoted_keys = implode(', ', array_fill(0, count($meta_keys), '%s'));
        $sql = $wpdb->prepare(
            "SELECT user_id, meta_value
             FROM {$wpdb->usermeta}
             WHERE meta_key IN ({$quoted_keys})
               AND meta_value <> ''
             ORDER BY umeta_id ASC",
            ...$meta_keys
        );

        $phones = self::$phones;
        $changed = false;
        foreach ($wpdb->get_results($sql) ?: [] as $row) {
            $user_id = absint($row->user_id ?? 0);
            $phone = self::normalize_phone($row->meta_value ?? '');
            if (!$user_id || $phone === '' || isset($phones[$user_id]) || isset(self::$owners[$phone])) {
                continue;
            }

            $phones[$user_id] = $phone;
            self::$owners[$phone] = $user_id;
            $changed = true;
        }

        if ($changed || get_option(self::OPTION_KEY, null) === null) {
            self::store($phones);
        }

        update_option(self::LEGACY_INDEX_OPTION, self::LEGACY_INDEX_VERSION, false);
    }

    private static function legacy_phone(int $user_id): string
    {
        $meta_keys = ['phone', 'mobile', 'user_phone', 'billing_phone', 'hst_mobile', 'hst_phone'];
        foreach ($meta_keys as $meta_key) {
            $phone = self::normalize_phone(get_user_meta($user_id, $meta_key, true));
            if ($phone !== '') {
                return $phone;
            }
        }

        $user = get_userdata($user_id);
        return $user ? self::normalize_phone($user->user_login) : '';
    }

    private static function legacy_owner(string $phone): int
    {
        $login_candidates = array_values(array_unique([
            $phone,
            ltrim($phone, '0'),
            '98' . ltrim($phone, '0'),
            '+98' . ltrim($phone, '0'),
        ]));

        foreach ($login_candidates as $candidate) {
            $user = get_user_by('login', $candidate);
            if ($user) {
                return (int) $user->ID;
            }
        }

        foreach (['phone', 'mobile', 'user_phone', 'billing_phone', 'hst_mobile', 'hst_phone'] as $meta_key) {
            $users = get_users([
                'number'     => 1,
                'fields'     => 'ID',
                'meta_key'   => $meta_key,
                'meta_value' => $phone,
            ]);
            if (!empty($users[0])) {
                return (int) $users[0];
            }
        }

        return 0;
    }

    private static function english_digits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private static function migration_national_code($value): string
    {
        $value = self::english_digits((string) $value);
        $digits = preg_replace('/[^0-9]/', '', $value);
        $digits = is_string($digits) ? $digits : '';

        // Earlier Sida imports could lose one or two zeroes at the beginning.
        if (strlen($digits) === 8 || strlen($digits) === 9) {
            $digits = str_pad($digits, 10, '0', STR_PAD_LEFT);
        }

        return self::normalize_national_code($digits);
    }
}
