<?php

defined('ABSPATH') || exit;

/**
 * Backup module for TeacherShow.
 *
 * Current policy:
 * - automatic backup is always enabled,
 * - one automatic backup is kept for each day of the current month,
 * - when a new month starts, previous-month backup files are deleted,
 * - no manual deletion UI/action is exposed,
 * - restore accepts TeacherShow JSON backup files.
 */
class HST_Backup
{
    const CRON_HOOK = 'hst_backup_cron';
    const FORMAT = 2;
    const DAILY_STATE_OPTION = 'hst_backup_daily_state';
    const DAILY_LOCK_OPTION = 'hst_backup_daily_lock';
    const DAILY_RETRY_SECONDS = 900;

    const TABLES = [
        'hst_terms', 'hst_classes', 'hst_lessons', 'hst_schedules',
        'hst_users_classes', 'hst_users_lessons', 'hst_users_availability',
        'hst_score_periods', 'hst_monthly_scores', 'hst_gradebook',
        'hst_attendance_records', 'hst_discipline', 'hst_exams',
        'hst_exam_questions', 'hst_exam_question_items', 'hst_exam_attempts',
        'hst_assignments', 'hst_assignment_submissions',
        'hst_tuition_plans', 'hst_tuition_invoices',
        'hst_notifications', 'hst_notification_reads',
    ];

    const USER_META_KEYS = [
        'phone', 'hst_parent_phone', 'hst_birthdate', 'hst_father_name',
        'hst_mother_name', 'hst_mother_phone', 'hst_national_code',
        'hst_teacher_bio', 'hst_graduated', 'hst_graduated_at',
        'hst_profile_avatar_id', 'hst_avatar_status',
    ];

    public function __construct()
    {
        add_action('wp_ajax_hst_backup_create', [$this, 'ajax_create']);
        add_action('wp_ajax_hst_backup_list', [$this, 'ajax_list']);
        add_action('wp_ajax_hst_backup_restore', [$this, 'ajax_restore']);
        add_action('wp_ajax_hst_backup_restore_step', [$this, 'ajax_restore_step']);
        add_action('wp_ajax_hst_backup_download', [$this, 'handle_download']);
        add_action(self::CRON_HOOK, [$this, 'run_scheduled']);
        add_action('init', [$this, 'ensure_daily_backup'], 30);

        $this->maybe_reschedule();
    }

    private function backup_dir(): string
    {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'teachershow-backups';

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        @file_put_contents($dir . '/.htaccess', "Deny from all\n");
        @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");

        return $dir;
    }

    public function maybe_reschedule(): void
    {
        $event = function_exists('wp_get_scheduled_event')
            ? wp_get_scheduled_event(self::CRON_HOOK)
            : null;

        // Earlier releases used a fixed 24-hour recurrence. Replace it with a
        // single event calculated in the site's timezone so the intended
        // calendar day cannot drift around midnight.
        if ($event && !empty($event->schedule)) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            $event = null;
        }

        if (!$event && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event($this->next_daily_run_timestamp(), self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        } else {
            $ts = wp_next_scheduled(self::CRON_HOOK);
            if ($ts) {
                wp_unschedule_event($ts, self::CRON_HOOK);
            }
        }

        delete_option(self::DAILY_STATE_OPTION);
        delete_option(self::DAILY_LOCK_OPTION);
    }

    public function run_scheduled(): void
    {
        try {
            $this->ensure_daily_backup(true);
        } finally {
            $this->schedule_next_daily_run();
        }
    }

    private function site_timezone(): DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        $timezone = (string) get_option('timezone_string', '');
        if ($timezone !== '') {
            try {
                return new DateTimeZone($timezone);
            } catch (Exception $e) {
                // Fall through to UTC for malformed legacy settings.
            }
        }

        return new DateTimeZone('UTC');
    }

    private function next_daily_run_timestamp(): int
    {
        $now = new DateTimeImmutable('now', $this->site_timezone());
        $next = $now->setTime(2, 0, 0);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }

        return $next->getTimestamp();
    }

    private function schedule_next_daily_run(): void
    {
        $scheduled = wp_next_scheduled(self::CRON_HOOK);
        if ($scheduled && $scheduled > time()) {
            return;
        }

        if ($scheduled) {
            wp_unschedule_event($scheduled, self::CRON_HOOK);
        }

        wp_schedule_single_event($this->next_daily_run_timestamp(), self::CRON_HOOK);
    }

    private function acquire_daily_lock(): string
    {
        $now = time();
        $existing = get_option(self::DAILY_LOCK_OPTION, []);
        $existingTime = is_array($existing) ? (int) ($existing['created_at'] ?? 0) : 0;

        if ($existingTime > 0 && $existingTime > ($now - self::DAILY_RETRY_SECONDS)) {
            return '';
        }

        if ($existing !== false && $existing !== []) {
            delete_option(self::DAILY_LOCK_OPTION);
        }

        $token = function_exists('wp_generate_uuid4')
            ? wp_generate_uuid4()
            : uniqid('hst-backup-', true);
        $created = add_option(
            self::DAILY_LOCK_OPTION,
            ['token' => $token, 'created_at' => $now],
            '',
            false
        );

        return $created ? $token : '';
    }

    private function release_daily_lock(string $token): void
    {
        $existing = get_option(self::DAILY_LOCK_OPTION, []);
        if (is_array($existing) && hash_equals((string) ($existing['token'] ?? ''), $token)) {
            delete_option(self::DAILY_LOCK_OPTION);
        }
    }

    public function ensure_daily_backup(bool $forceCheck = false): bool
    {
        $today = $this->today_key();
        $now = time();
        $state = get_option(self::DAILY_STATE_OPTION, []);
        $stateDay = is_array($state) ? (string) ($state['day'] ?? '') : '';
        $stateStatus = is_array($state) ? (string) ($state['status'] ?? '') : '';
        $lastCheck = is_array($state) ? (int) ($state['checked_at'] ?? 0) : 0;

        if (!$forceCheck && $stateDay === $today) {
            if ($stateStatus === 'success') {
                return true;
            }
            if ($lastCheck > ($now - self::DAILY_RETRY_SECONDS)) {
                return false;
            }
        }

        $token = $this->acquire_daily_lock();
        if ($token === '') {
            return false;
        }

        try {
            update_option(self::DAILY_STATE_OPTION, [
                'day' => $today,
                'status' => 'checking',
                'checked_at' => $now,
            ], false);

            $this->delete_old_month_backups();
            $exists = $this->has_auto_backup_for_today();
            if (!$exists) {
                $exists = $this->write_backup_file('auto') !== null;
            }

            update_option(self::DAILY_STATE_OPTION, [
                'day' => $today,
                'status' => $exists ? 'success' : 'failed',
                'checked_at' => time(),
            ], false);

            return $exists;
        } catch (Throwable $e) {
            update_option(self::DAILY_STATE_OPTION, [
                'day' => $today,
                'status' => 'failed',
                'checked_at' => time(),
            ], false);
            error_log(sprintf('HST daily backup failed: %s', $e->getMessage()));
            return false;
        } finally {
            $this->release_daily_lock($token);
        }
    }

    private function jalali_parts(?int $timestamp = null): array
    {
        // HST_Date::format() and wp_date() both expect a real Unix timestamp
        // and apply the WordPress timezone themselves. current_time('timestamp')
        // already contains the offset and caused it to be applied twice.
        $timestamp = $timestamp ?? time();

        if (class_exists('HST_Date')) {
            $formatted = HST_Date::en_digits(HST_Date::format($timestamp, 'Y-m-d', ''));
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $formatted, $m)) {
                return [(int) $m[1], (int) $m[2], (int) $m[3]];
            }
        }

        return [(int) wp_date('Y', $timestamp), (int) wp_date('m', $timestamp), (int) wp_date('d', $timestamp)];
    }

    private function jalali_key(?int $timestamp = null, string $format = 'Ymd'): string
    {
        [$year, $month, $day] = $this->jalali_parts($timestamp);

        if ($format === 'Y-m') {
            return sprintf('%04d-%02d', $year, $month);
        }

        if ($format === 'Y-m-d') {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return sprintf('%04d%02d%02d', $year, $month, $day);
    }

    private function jalali_month_days(int $year, int $month): int
    {
        if ($month >= 1 && $month <= 6) {
            return 31;
        }

        if ($month >= 7 && $month <= 11) {
            return 30;
        }

        $leapRemainders = [1, 5, 9, 13, 17, 22, 26, 30];
        return in_array(($year + 12) % 33, $leapRemainders, true) ? 30 : 29;
    }

    private function current_month_key(): string
    {
        return $this->jalali_key(null, 'Y-m');
    }

    private function today_key(): string
    {
        return $this->jalali_key(null, 'Ymd');
    }

    private function backup_jalali_date_from_name(string $name): string
    {
        if (!preg_match('/^teachershow-(?:manual|auto)-(\d{4})(\d{2})(\d{2})-\d{6}\.json$/', $name, $m)) {
            return '';
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];

        // New files are named with Jalali dates. Older files from v140 used
        // Gregorian dates; convert those to Jalali so they are not treated as
        // stale merely because the filename is from the previous implementation.
        if ($year > 1700 && class_exists('HST_Date')) {
            [$jy, $jm, $jd] = HST_Date::gregorian_to_jalali($year, $month, $day);
            return sprintf('%04d%02d%02d', $jy, $jm, $jd);
        }

        return sprintf('%04d%02d%02d', $year, $month, $day);
    }

    private function backup_month_from_name(string $name): string
    {
        $date = $this->backup_jalali_date_from_name($name);
        return $date ? substr($date, 0, 4) . '-' . substr($date, 4, 2) : '';
    }

    private function backup_day_from_name(string $name): string
    {
        return $this->backup_jalali_date_from_name($name);
    }

    private function delete_old_month_backups(): void
    {
        $current = $this->current_month_key();
        foreach ($this->list_files(false) as $file) {
            $month = $this->backup_month_from_name($file['name']);
            if ($month && $month !== $current) {
                @unlink($this->backup_dir() . '/' . $file['name']);
            }
        }
    }

    private function has_auto_backup_for_today(): bool
    {
        $today = $this->today_key();
        foreach ($this->list_files(false) as $file) {
            $createdDay = $this->jalali_key((int) ($file['created'] ?? 0), 'Ymd');
            if (
                strpos($file['name'], 'teachershow-auto-') === 0
                && $this->backup_day_from_name($file['name']) === $today
                && $createdDay === $today
            ) {
                return true;
            }
        }
        return false;
    }

    private function plugin_tables(): array
    {
        global $wpdb;

        $tables = array_fill_keys(self::TABLES, true);
        $like = $wpdb->esc_like($wpdb->prefix . 'hst_') . '%';
        $found = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like)) ?: [];

        foreach ($found as $table) {
            $bare = preg_replace('/^' . preg_quote($wpdb->prefix, '/') . '/', '', $table);
            if (is_string($bare) && preg_match('/^hst_[a-z0-9_]+$/', $bare)) {
                $tables[$bare] = true;
            }
        }

        return array_keys($tables);
    }

    private function collect_related_user_ids(): array
    {
        global $wpdb;

        $ids = [];
        $users = get_users([
            'fields' => 'ID',
            'number' => -1,
            'role__in' => ['student', 'teacher', 'modir', 'administrator'],
        ]);

        foreach ($users as $uid) {
            $ids[(int) $uid] = true;
        }

        foreach ($this->plugin_tables() as $bare) {
            $table = $wpdb->prefix . $bare;
            $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0) ?: [];
            foreach (['user_id', 'student_id', 'teacher_id'] as $column) {
                if (in_array($column, $columns, true)) {
                    foreach ($wpdb->get_col("SELECT DISTINCT `{$column}` FROM `{$table}` WHERE `{$column}` > 0") ?: [] as $uid) {
                        $ids[(int) $uid] = true;
                    }
                }
            }
        }

        return array_keys($ids);
    }

    private function collect_attachment_files(int $attachment_id, string $main_path): array
    {
        $uploads = wp_upload_dir();
        $base_dir = trailingslashit($uploads['basedir']);
        $files = [];

        $add_file = static function (string $path) use (&$files, $base_dir): void {
            if (!$path || !file_exists($path) || !is_readable($path)) {
                return;
            }

            $real = realpath($path);
            $base = realpath($base_dir);
            if (!$real || !$base || strpos($real, $base) !== 0) {
                return;
            }

            $relative = ltrim(str_replace('\\', '/', substr($real, strlen($base))), '/');
            if (isset($files[$relative])) {
                return;
            }

            $content = file_get_contents($real);
            if ($content === false) {
                return;
            }

            $files[$relative] = [
                'relative_path' => $relative,
                'size' => filesize($real),
                'content_base64' => base64_encode($content),
            ];
        };

        $add_file($main_path);

        $meta = wp_get_attachment_metadata($attachment_id);
        if (is_array($meta) && !empty($meta['file'])) {
            $main_relative = ltrim(str_replace('\\', '/', (string) $meta['file']), '/');
            $main_dir = dirname($base_dir . $main_relative);

            foreach ((array) ($meta['sizes'] ?? []) as $size) {
                if (!empty($size['file'])) {
                    $add_file(trailingslashit($main_dir) . $size['file']);
                }
            }
        }

        return array_values($files);
    }

    private function collect_media(array $user_meta): array
    {
        $media = [];

        foreach ($user_meta as $meta) {
            $attachment_id = isset($meta['hst_profile_avatar_id']) ? (int) $meta['hst_profile_avatar_id'] : 0;
            if (!$attachment_id || isset($media[$attachment_id])) {
                continue;
            }

            $path = get_attached_file($attachment_id);
            if (!$path || !file_exists($path) || !is_readable($path)) {
                continue;
            }

            $files = $this->collect_attachment_files($attachment_id, $path);
            if (!$files) {
                continue;
            }

            $post = get_post($attachment_id);
            $relative = _wp_relative_upload_path($path);

            $media[$attachment_id] = [
                'id' => $attachment_id,
                'post' => $post ? (array) $post : [],
                'meta' => get_post_meta($attachment_id),
                'relative_path' => $relative,
                'mime_type' => get_post_mime_type($attachment_id),
                'files' => $files,
                // Backward-compatible copy of the original file content.
                'content_base64' => $files[0]['content_base64'] ?? '',
            ];
        }

        return array_values($media);
    }

    private function collect_options(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value, autoload FROM {$wpdb->options}
             WHERE option_name LIKE 'hst\\_%' OR option_name LIKE 'hst-%'",
            ARRAY_A
        );

        $options = [];
        foreach ($rows ?: [] as $row) {
            if (in_array((string) $row['option_name'], [self::DAILY_LOCK_OPTION, self::DAILY_STATE_OPTION], true)) {
                continue;
            }
            $options[$row['option_name']] = [
                'value' => maybe_unserialize($row['option_value']),
                'autoload' => $row['autoload'],
            ];
        }

        return $options;
    }

    private function collect_woocommerce_orders(array $tables): array
    {
        global $wpdb;

        $order_ids = [];
        if (!empty($tables['hst_tuition_invoices'])) {
            foreach ((array) $tables['hst_tuition_invoices'] as $invoice) {
                $order_id = isset($invoice['order_id']) ? (int) $invoice['order_id'] : 0;
                if ($order_id > 0) {
                    $order_ids[$order_id] = true;
                }
            }
        }

        if (!$order_ids) {
            return [];
        }

        $ids = implode(',', array_map('intval', array_keys($order_ids)));
        $orders = [
            'posts' => $wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE ID IN ({$ids})", ARRAY_A) ?: [],
            'postmeta' => $wpdb->get_results("SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ({$ids})", ARRAY_A) ?: [],
            'order_items' => [],
            'order_itemmeta' => [],
        ];

        $items_table = $wpdb->prefix . 'woocommerce_order_items';
        $itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $items_table)) === $items_table) {
            $orders['order_items'] = $wpdb->get_results("SELECT * FROM {$items_table} WHERE order_id IN ({$ids})", ARRAY_A) ?: [];
            $item_ids = array_filter(array_map('intval', wp_list_pluck($orders['order_items'], 'order_item_id')));
            if ($item_ids && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $itemmeta_table)) === $itemmeta_table) {
                $item_ids_sql = implode(',', $item_ids);
                $orders['order_itemmeta'] = $wpdb->get_results("SELECT * FROM {$itemmeta_table} WHERE order_item_id IN ({$item_ids_sql})", ARRAY_A) ?: [];
            }
        }

        return $orders;
    }

    public function build_payload(): array
    {
        global $wpdb;

        $tables = [];
        foreach ($this->plugin_tables() as $bare) {
            $table = $wpdb->prefix . $bare;
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists !== $table) {
                continue;
            }
            $tables[$bare] = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A) ?: [];
        }

        $user_ids = $this->collect_related_user_ids();
        $users = [];
        $user_meta = [];

        foreach ($user_ids as $uid) {
            $user = get_userdata((int) $uid);
            if (!$user) {
                continue;
            }

            $users[$uid] = [
                'ID' => (int) $uid,
                'user_login' => $user->user_login,
                'user_pass' => $user->user_pass,
                'user_nicename' => $user->user_nicename,
                'user_email' => $user->user_email,
                'user_url' => $user->user_url,
                'user_registered' => $user->user_registered,
                'user_activation_key' => $user->user_activation_key,
                'user_status' => (int) $user->user_status,
                'display_name' => $user->display_name,
                'roles' => array_values((array) $user->roles),
            ];

            $meta = [];
            foreach (get_user_meta((int) $uid) as $key => $values) {
                if (strpos($key, 'hst_') === 0 || in_array($key, self::USER_META_KEYS, true) || in_array($key, ['first_name', 'last_name', 'nickname', 'phone'], true)) {
                    $meta[$key] = array_map('maybe_unserialize', (array) $values);
                }
            }
            $user_meta[$uid] = $meta;
        }

        return [
            'meta' => [
                'format' => self::FORMAT,
                'plugin' => 'teachershow',
                'site' => home_url(),
                'created_at' => current_time('mysql'),
                'db_prefix' => $wpdb->prefix,
                'jalali_date' => $this->jalali_key(null, 'Y-m-d'),
                'jalali_month' => $this->current_month_key(),
                'month' => $this->current_month_key(),
            ],
            'tables' => $tables,
            'users' => array_values($users),
            'user_meta' => $user_meta,
            'media' => $this->collect_media($user_meta),
            'options' => $this->collect_options(),
            'external' => [
                'woocommerce_orders' => $this->collect_woocommerce_orders($tables),
            ],
        ];
    }

    private function write_backup_file(string $kind): ?string
    {
        $this->delete_old_month_backups();

        $payload = $this->build_payload();
        $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }

        $timestamp = time();
        $name = sprintf(
            'teachershow-%s-%s-%s.json',
            $kind,
            $this->jalali_key($timestamp, 'Ymd'),
            wp_date('His', $timestamp)
        );
        $path = $this->backup_dir() . '/' . $name;

        return file_put_contents($path, $json) === false ? null : $name;
    }

    private function list_files(bool $current_month_only = true): array
    {
        $dir = $this->backup_dir();
        $current = $this->current_month_key();
        $files = [];

        foreach (glob($dir . '/teachershow-*.json') ?: [] as $path) {
            $name = basename($path);
            $dateKey = $this->backup_day_from_name($name);

            if (!$dateKey) {
                $dateKey = $this->jalali_key((int) filemtime($path), 'Ymd');
            }

            $month = substr($dateKey, 0, 4) . '-' . substr($dateKey, 4, 2);
            if ($current_month_only && $month !== $current) {
                continue;
            }

            $day = (int) substr($dateKey, 6, 2);
            $files[] = [
                'name' => $name,
                'size' => (int) filesize($path),
                'created' => (int) filemtime($path),
                'jalali_date' => sprintf('%s/%s/%s', substr($dateKey, 0, 4), substr($dateKey, 4, 2), substr($dateKey, 6, 2)),
                'day' => $day,
                'week' => max(1, min(5, (int) ceil($day / 7))),
            ];
        }

        usort($files, static fn($a, $b) => $b['created'] <=> $a['created']);
        return $files;
    }

    private function current_month_days(): int
    {
        [$year, $month] = array_map('intval', explode('-', $this->current_month_key()));
        return $this->jalali_month_days($year, $month);
    }

    public function ajax_create(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');

        $name = $this->write_backup_file('manual');
        if (!$name) {
            HST_Guard::fail('ساخت فایل پشتیبان ناموفق بود.');
        }

        wp_send_json_success([
            'message' => 'پشتیبان با موفقیت ساخته شد.',
            'download_url' => $this->download_url($name),
            'name' => $name,
        ]);
    }

    public function ajax_list(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
        $this->delete_old_month_backups();

        $items = [];
        foreach ($this->list_files(true) as $file) {
            $items[] = [
                'name' => $file['name'],
                'size' => size_format($file['size'], 1),
                'created' => HST_Date::fa_digits($file['jalali_date']) . ' - ' . wp_date('H:i', $file['created']),
                'day' => $file['day'],
                'week' => $file['week'],
                'is_auto' => strpos($file['name'], 'teachershow-auto-') === 0 ? 1 : 0,
                'download_url' => $this->download_url($file['name']),
            ];
        }

        wp_send_json_success([
            'items' => $items,
            'current_month' => $this->current_month_key(),
            'current_month_days' => $this->current_month_days(),
        ]);
    }

    public function ajax_restore(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');

        if (
            empty($_FILES['backup_file']['tmp_name'])
            || !is_uploaded_file($_FILES['backup_file']['tmp_name'])
            || (!empty($_FILES['backup_file']['error']) && (int) $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK)
        ) {
            HST_Guard::fail('فایل پشتیبان دریافت نشد.');
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $raw = file_get_contents($_FILES['backup_file']['tmp_name']);
        if ($raw === false || trim($raw) === '') {
            HST_Guard::fail('فایل پشتیبان خالی یا نامعتبر است.');
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['meta']['plugin']) || $payload['meta']['plugin'] !== 'teachershow') {
            HST_Guard::fail('ساختار فایل پشتیبان معتبر نیست.');
        }

        $format = isset($payload['meta']['format']) ? (int) $payload['meta']['format'] : 0;
        if ($format < 1 || $format > self::FORMAT) {
            HST_Guard::fail('نسخه ساختار فایل پشتیبان با این نسخه افزونه سازگار نیست.');
        }

        if (class_exists('HST_Tables')) {
            HST_Tables::hst_activate();
        }

        $this->cleanup_stale_restore_files();

        $jobId = function_exists('wp_generate_uuid4')
            ? str_replace('-', '', wp_generate_uuid4())
            : str_replace('.', '', uniqid('hstrestore', true));
        $jobId = preg_replace('/[^a-z0-9]/i', '', (string) $jobId);
        if (!$jobId) {
            HST_Guard::fail('امکان ایجاد عملیات بازیابی وجود ندارد.', 500);
        }

        $path = trailingslashit($this->backup_dir()) . '.restore-' . $jobId . '.json';
        if (file_put_contents($path, $raw, LOCK_EX) === false) {
            HST_Guard::fail('امکان آماده‌سازی فایل پشتیبان روی سرور وجود ندارد.', 500);
        }

        $tableNames = $this->restore_table_names($payload);
        $state = [
            'user_id' => get_current_user_id(),
            'file' => $path,
            'stage' => 'users',
            'offset' => 0,
            'table_index' => 0,
            'table_offset' => 0,
            'table_truncated' => false,
            'external_part' => 0,
            'processed' => 0,
            'total' => $this->restore_total_units($payload, $tableNames),
            'table_names' => $tableNames,
            'stats' => [
                'tables' => 0,
                'rows' => 0,
                'users' => 0,
                'media' => 0,
                'options' => 0,
                'external' => 0,
            ],
            'created_at' => time(),
            'updated_at' => time(),
        ];

        if (!$this->save_restore_job($jobId, $state)) {
            @unlink($path);
            HST_Guard::fail('امکان ذخیره وضعیت عملیات بازیابی وجود ندارد.', 500);
        }

        wp_send_json_success([
            'job_id' => $jobId,
            'progress' => 1,
            'message' => 'فایل پشتیبان بررسی شد؛ بازیابی مرحله‌ای آغاز می‌شود.',
        ]);
    }

    public function ajax_restore_step(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');

        $jobId = isset($_POST['job_id'])
            ? preg_replace('/[^a-z0-9]/i', '', sanitize_text_field(wp_unslash($_POST['job_id'])))
            : '';
        if (!$jobId) {
            HST_Guard::fail('شناسه عملیات بازیابی نامعتبر است.');
        }

        $state = $this->load_restore_job($jobId);
        if (!$state || (int) ($state['user_id'] ?? 0) !== get_current_user_id()) {
            HST_Guard::fail('عملیات بازیابی یافت نشد یا منقضی شده است.', 404);
        }

        $path = isset($state['file']) ? (string) $state['file'] : '';
        $base = realpath($this->backup_dir());
        $real = $path !== '' ? realpath($path) : false;
        if (!$base || !$real || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0 || !is_readable($real)) {
            $this->cleanup_restore_job($jobId, $state);
            HST_Guard::fail('فایل موقت بازیابی در دسترس نیست.', 410);
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(20);
        }

        $raw = file_get_contents($real);
        $payload = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($payload) || ($payload['meta']['plugin'] ?? '') !== 'teachershow') {
            $this->cleanup_restore_job($jobId, $state);
            HST_Guard::fail('فایل موقت بازیابی نامعتبر شده است.', 410);
        }

        try {
            $result = $this->process_restore_job_step($state, $payload);
        } catch (Throwable $e) {
            error_log(sprintf(
                'HST backup restore failed at stage %s: %s',
                (string) ($state['stage'] ?? 'unknown'),
                $e->getMessage()
            ));
            HST_Guard::fail('بازیابی در مرحله «' . $this->restore_stage_label((string) ($state['stage'] ?? '')) . '» متوقف شد. لطفاً دوباره تلاش کنید.', 500);
        }

        if (!empty($result['done'])) {
            $stats = (array) ($state['stats'] ?? []);
            $this->cleanup_restore_job($jobId, $state);
            delete_option(self::DAILY_LOCK_OPTION);
            delete_option(self::DAILY_STATE_OPTION);

            wp_send_json_success([
                'done' => true,
                'progress' => 100,
                'message' => 'پشتیبان با موفقیت اعمال شد.',
                'stats' => $stats,
            ]);
        }

        $state['updated_at'] = time();
        if (!$this->save_restore_job($jobId, $state)) {
            HST_Guard::fail('ذخیره پیشرفت عملیات بازیابی ناموفق بود.', 500);
        }

        wp_send_json_success([
            'done' => false,
            'progress' => $this->restore_job_progress($state),
            'message' => (string) ($result['message'] ?? 'در حال بازیابی اطلاعات...'),
            'stats' => (array) ($state['stats'] ?? []),
        ]);
    }

    private function process_restore_job_step(array &$state, array $payload): array
    {
        for ($guard = 0; $guard < 12; $guard++) {
            $stage = (string) ($state['stage'] ?? 'users');

            if ($stage === 'users') {
                $rows = array_values((array) ($payload['users'] ?? []));
                if ((int) $state['offset'] >= count($rows)) {
                    $state['stage'] = 'user_meta';
                    $state['offset'] = 0;
                    continue;
                }
                $count = $this->restore_users_chunk($rows, $state);
                return ['done' => false, 'message' => sprintf('در حال بازگردانی کاربران؛ %s مورد پردازش شد.', number_format_i18n($count))];
            }

            if ($stage === 'user_meta') {
                $rows = (array) ($payload['user_meta'] ?? []);
                if ((int) $state['offset'] >= count($rows)) {
                    $state['stage'] = 'media';
                    $state['offset'] = 0;
                    continue;
                }
                $count = $this->restore_user_meta_chunk($rows, $state);
                return ['done' => false, 'message' => sprintf('در حال بازگردانی مشخصات کاربران؛ %s فیلد پردازش شد.', number_format_i18n($count))];
            }

            if ($stage === 'media') {
                $rows = array_values((array) ($payload['media'] ?? []));
                if ((int) $state['offset'] >= count($rows)) {
                    $state['stage'] = 'options';
                    $state['offset'] = 0;
                    continue;
                }
                $count = $this->restore_media_chunk($rows, $state);
                return ['done' => false, 'message' => sprintf('در حال بازگردانی رسانه‌ها؛ %s مورد پردازش شد.', number_format_i18n($count))];
            }

            if ($stage === 'options') {
                $rows = $this->filtered_restore_options($payload);
                if ((int) $state['offset'] >= count($rows)) {
                    $state['stage'] = 'tables';
                    $state['offset'] = 0;
                    continue;
                }
                $count = $this->restore_options_chunk($rows, $state);
                return ['done' => false, 'message' => sprintf('در حال بازگردانی تنظیمات؛ %s مورد پردازش شد.', number_format_i18n($count))];
            }

            if ($stage === 'tables') {
                $worked = $this->restore_tables_chunk($payload, $state);
                if (!$worked) {
                    $state['stage'] = 'external';
                    $state['offset'] = 0;
                    continue;
                }
                return ['done' => false, 'message' => 'در حال بازگردانی اطلاعات جداول افزونه...'];
            }

            if ($stage === 'external') {
                $worked = $this->restore_external_chunk($payload, $state);
                if (!$worked) {
                    $state['stage'] = 'finalize';
                    $state['offset'] = 0;
                    continue;
                }
                return ['done' => false, 'message' => 'در حال بازگردانی اطلاعات وابسته...'];
            }

            if ($stage === 'finalize') {
                $state['processed'] = max((int) ($state['processed'] ?? 0), (int) ($state['total'] ?? 1));
                return ['done' => true, 'message' => 'پشتیبان با موفقیت اعمال شد.'];
            }

            throw new RuntimeException('مرحله بازیابی ناشناخته است.');
        }

        throw new RuntimeException('تغییر مرحله بازیابی کامل نشد.');
    }

    private function restore_users_chunk(array $rows, array &$state): int
    {
        global $wpdb;

        $offset = (int) ($state['offset'] ?? 0);
        $chunk = array_slice($rows, $offset, 20);
        $processed = 0;

        foreach ($chunk as $row) {
            $processed++;
            $state['processed']++;

            if (!is_array($row) || empty($row['ID']) || empty($row['user_login'])) {
                continue;
            }

            $uid = (int) $row['ID'];
            $data = [
                'ID' => $uid,
                'user_login' => (string) $row['user_login'],
                'user_pass' => (string) ($row['user_pass'] ?? wp_generate_password(24, true)),
                'user_nicename' => (string) ($row['user_nicename'] ?? $row['user_login']),
                'user_email' => (string) ($row['user_email'] ?? ''),
                'user_url' => (string) ($row['user_url'] ?? ''),
                'user_registered' => (string) ($row['user_registered'] ?? current_time('mysql')),
                'user_activation_key' => (string) ($row['user_activation_key'] ?? ''),
                'user_status' => (int) ($row['user_status'] ?? 0),
                'display_name' => (string) ($row['display_name'] ?? $row['user_login']),
            ];

            $result = get_userdata($uid)
                ? $wpdb->update($wpdb->users, $data, ['ID' => $uid])
                : $wpdb->insert($wpdb->users, $data);
            if ($result === false) {
                throw new RuntimeException('ذخیره کاربر ناموفق بود: ' . $wpdb->last_error);
            }

            clean_user_cache($uid);
            $user = new WP_User($uid);
            $roles = array_values(array_filter((array) ($row['roles'] ?? [])));
            if ($roles) {
                foreach ((array) $user->roles as $role) {
                    $user->remove_role($role);
                }
                foreach ($roles as $role) {
                    $user->add_role((string) $role);
                }
            }

            $state['stats']['users']++;
        }

        $state['offset'] = $offset + count($chunk);
        return $processed;
    }

    private function restore_user_meta_chunk(array $rows, array &$state): int
    {
        global $wpdb;

        $offset = (int) ($state['offset'] ?? 0);
        $slice = array_slice($rows, $offset, 12, true);
        $inserts = [];
        $processedKeys = 0;
        $processedUserIds = [];

        foreach ($slice as $uid => $meta) {
            $uid = (int) $uid;
            if (!$uid || !is_array($meta)) {
                continue;
            }
            $processedUserIds[] = $uid;

            $keys = [];
            foreach ($meta as $key => $values) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $keys[] = $key;
                $processedKeys++;

                foreach ((array) $values as $value) {
                    $serialized = maybe_serialize($value);
                    $inserts[] = [
                        'user_id' => $uid,
                        'meta_key' => $key,
                        'meta_value' => $serialized === null ? '' : $serialized,
                    ];
                }
            }

            if ($keys) {
                $placeholders = implode(',', array_fill(0, count($keys), '%s'));
                $args = array_merge([$uid], $keys);
                $sql = $wpdb->prepare(
                    "DELETE FROM `{$wpdb->usermeta}` WHERE user_id = %d AND meta_key IN ({$placeholders})",
                    $args
                );
                if ($wpdb->query($sql) === false) {
                    throw new RuntimeException('پاک‌سازی مشخصات کاربر ناموفق بود: ' . $wpdb->last_error);
                }
            }
        }

        if ($inserts) {
            $this->bulk_write_rows($wpdb->usermeta, $inserts, 'INSERT');
        }
        foreach (array_unique($processedUserIds) as $uid) {
            wp_cache_delete((int) $uid, 'user_meta');
        }

        $state['processed'] += $processedKeys;
        $state['offset'] = $offset + count($slice);
        return $processedKeys;
    }

    private function restore_media_chunk(array $rows, array &$state): int
    {
        global $wpdb;

        $offset = (int) ($state['offset'] ?? 0);
        $chunk = array_slice($rows, $offset, 1);
        $uploads = wp_upload_dir();
        $processed = 0;

        foreach ($chunk as $item) {
            $processed++;
            $state['processed']++;

            if (!is_array($item)) {
                continue;
            }

            $id = isset($item['id']) ? (int) $item['id'] : 0;
            $relative = isset($item['relative_path']) ? ltrim((string) $item['relative_path'], '/') : '';
            if (!$id || !$relative || strpos($relative, '..') !== false) {
                continue;
            }

            $files = (array) ($item['files'] ?? []);
            if (!$files && !empty($item['content_base64'])) {
                $files[] = [
                    'relative_path' => $relative,
                    'content_base64' => $item['content_base64'],
                ];
            }

            $restoredAny = false;
            foreach ($files as $file) {
                $fileRelative = isset($file['relative_path']) ? ltrim((string) $file['relative_path'], '/') : '';
                if (!$fileRelative || strpos($fileRelative, '..') !== false) {
                    continue;
                }
                $content = isset($file['content_base64']) ? base64_decode((string) $file['content_base64'], true) : false;
                if ($content === false) {
                    continue;
                }

                $path = trailingslashit($uploads['basedir']) . $fileRelative;
                wp_mkdir_p(dirname($path));
                if (file_put_contents($path, $content, LOCK_EX) !== false) {
                    $restoredAny = true;
                }
            }

            if (!$restoredAny) {
                continue;
            }

            $post = (array) ($item['post'] ?? []);
            if ($post) {
                $post['ID'] = $id;
                $post['post_type'] = 'attachment';
                $result = get_post($id)
                    ? $wpdb->update($wpdb->posts, $post, ['ID' => $id])
                    : $wpdb->insert($wpdb->posts, $post);
                if ($result === false) {
                    throw new RuntimeException('ذخیره رسانه ناموفق بود: ' . $wpdb->last_error);
                }
            }

            delete_post_meta($id, '_wp_attached_file');
            add_post_meta($id, '_wp_attached_file', $relative);

            foreach ((array) ($item['meta'] ?? []) as $key => $values) {
                if ($key === '_wp_attached_file' || !is_string($key) || $key === '') {
                    continue;
                }
                delete_post_meta($id, $key);
                foreach ((array) $values as $value) {
                    add_post_meta($id, $key, maybe_unserialize($value));
                }
            }

            $state['stats']['media']++;
        }

        $state['offset'] = $offset + count($chunk);
        return $processed;
    }

    private function restore_options_chunk(array $rows, array &$state): int
    {
        $offset = (int) ($state['offset'] ?? 0);
        $chunk = array_slice($rows, $offset, 30);

        foreach ($chunk as $item) {
            $name = (string) ($item['name'] ?? '');
            $option = $item['option'] ?? null;
            $value = is_array($option) && array_key_exists('value', $option) ? $option['value'] : $option;
            update_option($name, $value, false);
            $state['stats']['options']++;
            $state['processed']++;
        }

        $state['offset'] = $offset + count($chunk);
        return count($chunk);
    }

    private function restore_tables_chunk(array $payload, array &$state): bool
    {
        global $wpdb;

        $names = array_values((array) ($state['table_names'] ?? []));
        while ((int) ($state['table_index'] ?? 0) < count($names)) {
            $index = (int) $state['table_index'];
            $bare = (string) $names[$index];
            $table = $wpdb->prefix . $bare;
            $rows = array_values((array) ($payload['tables'][$bare] ?? []));

            if (empty($state['table_truncated'])) {
                if ($wpdb->query("TRUNCATE TABLE `{$table}`") === false) {
                    throw new RuntimeException('پاک‌سازی جدول ' . $bare . ' ناموفق بود: ' . $wpdb->last_error);
                }
                $state['table_truncated'] = true;
                $state['processed']++;
            }

            $offset = (int) ($state['table_offset'] ?? 0);
            if ($offset >= count($rows)) {
                $state['stats']['tables']++;
                $state['table_index'] = $index + 1;
                $state['table_offset'] = 0;
                $state['table_truncated'] = false;
                continue;
            }

            $chunk = array_slice($rows, $offset, 250);
            $written = $this->bulk_write_rows($table, $chunk, 'REPLACE');
            $state['stats']['rows'] += $written;
            $state['processed'] += count($chunk);
            $state['table_offset'] = $offset + count($chunk);

            if ((int) $state['table_offset'] >= count($rows)) {
                $state['stats']['tables']++;
                $state['table_index'] = $index + 1;
                $state['table_offset'] = 0;
                $state['table_truncated'] = false;
            }

            return true;
        }

        return false;
    }

    private function restore_external_chunk(array $payload, array &$state): bool
    {
        global $wpdb;

        $parts = $this->restore_external_parts($payload);
        while ((int) ($state['external_part'] ?? 0) < count($parts)) {
            $partIndex = (int) $state['external_part'];
            $part = $parts[$partIndex];
            $rows = array_values((array) ($part['rows'] ?? []));
            $offset = (int) ($state['offset'] ?? 0);

            if ($offset >= count($rows)) {
                $state['external_part'] = $partIndex + 1;
                $state['offset'] = 0;
                continue;
            }

            $chunk = array_slice($rows, $offset, 150);
            $table = (string) ($part['table'] ?? '');
            if ($table !== '' && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
                $written = $this->bulk_write_rows($table, $chunk, 'REPLACE');
                $state['stats']['external'] += $written;
            }

            $state['processed'] += count($chunk);
            $state['offset'] = $offset + count($chunk);
            if ((int) $state['offset'] >= count($rows)) {
                $state['external_part'] = $partIndex + 1;
                $state['offset'] = 0;
            }
            return true;
        }

        return false;
    }

    private function bulk_write_rows(string $table, array $rows, string $verb = 'REPLACE'): int
    {
        global $wpdb;

        $verb = strtoupper($verb) === 'INSERT' ? 'INSERT' : 'REPLACE';
        $safeTable = str_replace('`', '``', $table);
        $groups = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !$row) {
                continue;
            }

            $columns = array_values(array_filter(array_keys($row), static function ($column) {
                return is_string($column) && preg_match('/^[a-zA-Z0-9_]+$/', $column);
            }));
            if (!$columns) {
                continue;
            }

            $signature = implode('|', $columns);
            if (!isset($groups[$signature])) {
                $groups[$signature] = ['columns' => $columns, 'rows' => []];
            }
            $groups[$signature]['rows'][] = $row;
        }

        $written = 0;
        foreach ($groups as $group) {
            $columns = $group['columns'];
            $columnSql = implode(',', array_map(static function ($column) {
                return '`' . str_replace('`', '``', $column) . '`';
            }, $columns));
            $valueSql = [];
            $args = [];

            foreach ($group['rows'] as $row) {
                $placeholders = [];
                foreach ($columns as $column) {
                    $value = array_key_exists($column, $row) ? $row[$column] : null;
                    if ($value === null) {
                        $placeholders[] = 'NULL';
                        continue;
                    }
                    if (is_bool($value)) {
                        $value = $value ? '1' : '0';
                    } elseif (is_array($value) || is_object($value)) {
                        $value = maybe_serialize($value);
                    }
                    $placeholders[] = '%s';
                    $args[] = (string) $value;
                }
                $valueSql[] = '(' . implode(',', $placeholders) . ')';
            }

            if (!$valueSql) {
                continue;
            }

            $query = "{$verb} INTO `{$safeTable}` ({$columnSql}) VALUES " . implode(',', $valueSql);
            if ($args) {
                $query = $wpdb->prepare($query, $args);
            }
            $result = $wpdb->query($query);
            if ($result === false) {
                throw new RuntimeException('نوشتن اطلاعات پایگاه داده ناموفق بود: ' . $wpdb->last_error);
            }
            $written += count($group['rows']);
        }

        return $written;
    }

    private function filtered_restore_options(array $payload): array
    {
        $rows = [];
        foreach ((array) ($payload['options'] ?? []) as $name => $option) {
            $name = (string) $name;
            if (!preg_match('/^hst[_-]/', $name)) {
                continue;
            }
            if (in_array($name, [self::DAILY_LOCK_OPTION, self::DAILY_STATE_OPTION], true)) {
                continue;
            }
            $rows[] = ['name' => $name, 'option' => $option];
        }
        return $rows;
    }

    private function restore_table_names(array $payload): array
    {
        global $wpdb;

        $names = [];
        foreach ((array) ($payload['tables'] ?? []) as $bare => $rows) {
            $bare = (string) $bare;
            if (!preg_match('/^hst_[a-z0-9_]+$/', $bare)) {
                continue;
            }
            $table = $wpdb->prefix . $bare;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
                $names[] = $bare;
            }
        }
        return $names;
    }

    private function restore_external_parts(array $payload): array
    {
        global $wpdb;

        $orders = (array) ($payload['external']['woocommerce_orders'] ?? []);
        return [
            ['table' => $wpdb->posts, 'rows' => (array) ($orders['posts'] ?? [])],
            ['table' => $wpdb->postmeta, 'rows' => (array) ($orders['postmeta'] ?? [])],
            ['table' => $wpdb->prefix . 'woocommerce_order_items', 'rows' => (array) ($orders['order_items'] ?? [])],
            ['table' => $wpdb->prefix . 'woocommerce_order_itemmeta', 'rows' => (array) ($orders['order_itemmeta'] ?? [])],
        ];
    }

    private function restore_total_units(array $payload, array $tableNames): int
    {
        $total = count((array) ($payload['users'] ?? []));

        foreach ((array) ($payload['user_meta'] ?? []) as $meta) {
            foreach ((array) $meta as $key => $values) {
                if (is_string($key) && $key !== '') {
                    $total++;
                }
            }
        }

        $total += count((array) ($payload['media'] ?? []));
        $total += count($this->filtered_restore_options($payload));

        foreach ($tableNames as $bare) {
            $total++;
            $total += count((array) ($payload['tables'][$bare] ?? []));
        }

        foreach ($this->restore_external_parts($payload) as $part) {
            $total += count((array) ($part['rows'] ?? []));
        }

        return max(1, $total);
    }

    private function restore_job_progress(array $state): int
    {
        $total = max(1, (int) ($state['total'] ?? 1));
        $processed = max(0, (int) ($state['processed'] ?? 0));
        if ($processed >= $total) {
            return 99;
        }
        return max(1, min(99, (int) floor(($processed / $total) * 100)));
    }

    private function restore_stage_label(string $stage): string
    {
        $labels = [
            'users' => 'کاربران',
            'user_meta' => 'مشخصات کاربران',
            'media' => 'رسانه‌ها',
            'options' => 'تنظیمات',
            'tables' => 'جداول افزونه',
            'external' => 'اطلاعات وابسته',
            'finalize' => 'نهایی‌سازی',
        ];
        return $labels[$stage] ?? 'بازیابی اطلاعات';
    }

    private function restore_job_key(string $jobId): string
    {
        return 'hst_backup_restore_job_' . md5($jobId);
    }

    private function load_restore_job(string $jobId): array
    {
        $state = get_transient($this->restore_job_key($jobId));
        return is_array($state) ? $state : [];
    }

    private function save_restore_job(string $jobId, array $state): bool
    {
        return set_transient($this->restore_job_key($jobId), $state, 2 * HOUR_IN_SECONDS);
    }

    private function cleanup_restore_job(string $jobId, array $state): void
    {
        $path = isset($state['file']) ? (string) $state['file'] : '';
        if ($path && file_exists($path)) {
            @unlink($path);
        }
        delete_transient($this->restore_job_key($jobId));
    }

    private function cleanup_stale_restore_files(): void
    {
        $cutoff = time() - (6 * HOUR_IN_SECONDS);
        foreach (glob(trailingslashit($this->backup_dir()) . '.restore-*.json') ?: [] as $path) {
            $modified = @filemtime($path);
            if ($modified && $modified < $cutoff) {
                @unlink($path);
            }
        }
    }


    public function handle_download(): void
    {
        if (!is_user_logged_in() || (!current_user_can('hst_manage_school') && !current_user_can('manage_options'))) {
            wp_die('شما اجازهٔ دانلود فایل پشتیبان را ندارید.', 'دسترسی غیرمجاز', ['response' => 403]);
        }

        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'hst_nonce')) {
            wp_die('پیوند دانلود نامعتبر یا منقضی شده است. لطفاً صفحه را تازه کنید و دوباره تلاش کنید.', 'پیوند نامعتبر', ['response' => 403]);
        }

        $name = $this->sanitize_name(isset($_GET['file']) ? rawurldecode(wp_unslash($_GET['file'])) : '');
        $path = $this->backup_dir() . '/' . $name;

        if (!$name || !file_exists($path)) {
            wp_die('فایل یافت نشد', 'خطا', ['response' => 404]);
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    private function download_url(string $name): string
    {
        return add_query_arg(
            [
                'action' => 'hst_backup_download',
                'file' => rawurlencode($name),
                'nonce' => wp_create_nonce('hst_nonce'),
            ],
            admin_url('admin-ajax.php')
        );
    }

    private function sanitize_name(string $name): string
    {
        $name = basename($name);
        return preg_match('/^teachershow-(manual|auto)-\d{8}-\d{6}\.json$/', $name) ? $name : '';
    }

}
