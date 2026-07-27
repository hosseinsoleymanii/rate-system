<?php

defined('ABSPATH') || exit;

class HST_Notifications
{
    private const TYPES = ['info', 'success', 'warning', 'danger'];
    private const AUDIENCES = ['all', 'roles', 'classes', 'users'];

    public function __construct()
    {
        add_action('wp_ajax_hst_add_notification', [$this, 'ajax_add_notification']);
        add_action('wp_ajax_hst_delete_notification', [$this, 'ajax_delete_notification']);
        add_action('wp_ajax_hst_toggle_notification', [$this, 'ajax_toggle_notification']);
        add_action('wp_ajax_hst_mark_notification_read', [$this, 'ajax_mark_read']);
        add_action('wp_ajax_hst_mark_all_notifications_read', [$this, 'ajax_mark_all_read']);
        add_action('wp_ajax_hst_get_header_notifications', [$this, 'ajax_get_header_notifications']);
        add_action('wp_ajax_hst_search_notification_users', [$this, 'ajax_search_notification_users']);
        add_action('wp_ajax_hst_notification_report', [$this, 'ajax_notification_report']);
        add_action('wp_ajax_hst_notification_sms_test', [$this, 'ajax_notification_sms_test']);
        add_action('wp_ajax_hst_update_notification_sms', [$this, 'ajax_update_notification_sms']);
        add_action('wp_ajax_hst_notification_sms_estimate', [$this, 'ajax_notification_sms_estimate']);

        // Ensure the `source` column exists on older installs.
        add_action('init', [$this, 'maybe_upgrade_schema']);
    }

    public function maybe_upgrade_schema()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_notifications';

        $columns = [
            'source'      => "ALTER TABLE {$table} ADD COLUMN source varchar(16) NOT NULL DEFAULT 'manual'",
            'sms_enabled' => "ALTER TABLE {$table} ADD COLUMN sms_enabled tinyint(1) NOT NULL DEFAULT 0",
            'sms_message' => "ALTER TABLE {$table} ADD COLUMN sms_message longtext NULL",
            'sms_sent_at' => "ALTER TABLE {$table} ADD COLUMN sms_sent_at datetime NULL DEFAULT NULL",
            'sms_result'  => "ALTER TABLE {$table} ADD COLUMN sms_result longtext NULL",
        ];

        foreach ($columns as $column => $sql) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SHOW COLUMNS FROM {$table} LIKE %s",
                $column
            ));
            if ($exists !== $column) {
                $wpdb->query($sql);
            }
        }
    }

    private function authorize_ajax($manager = false)
    {
        if ($manager && class_exists('HST_Guard')) {
            HST_Guard::verify_ajax('hst_manage_school');
            return;
        }

        check_ajax_referer('hst_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'لطفاً وارد حساب کاربری شوید.'], 401);
        }

        if ($manager && !current_user_can('manage_options') && !current_user_can('hst_manage_school')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز'], 403);
        }
    }

    private function normalize_datetime($value)
    {
        $raw = sanitize_text_field(wp_unslash($value));
        if ($raw === '') {
            return null;
        }

        $date = class_exists('HST_Date') ? HST_Date::to_gregorian_datetime($raw) : null;
        if (!$date) {
            $timestamp = strtotime($raw);
            $date = $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
        }

        return ($date && strtotime($date) !== false) ? $date : false;
    }

    private function validate_audience_targets($audience, array $role_targets, array $class_targets, array $user_targets)
    {
        if ($audience === 'roles' && empty($role_targets)) {
            wp_send_json_error(['message' => 'برای مخاطب نقش‌ها، حداقل یک نقش انتخاب کنید.'], 400);
        }

        if ($audience === 'classes' && empty($class_targets)) {
            wp_send_json_error(['message' => 'برای مخاطب کلاس‌ها، حداقل یک کلاس انتخاب کنید.'], 400);
        }

        if ($audience === 'users' && empty($user_targets)) {
            wp_send_json_error(['message' => 'برای مخاطب کاربران، حداقل یک کاربر انتخاب کنید.'], 400);
        }
    }

    private function current_user_can_view_notice($notice_id)
    {
        foreach ($this->get_visible_notifications(get_current_user_id(), 200) as $notice) {
            if ((int) $notice->id === (int) $notice_id) {
                return true;
            }
        }
        return false;
    }

    private function table($name)
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    private function normalize_ids($value)
    {
        $value = wp_unslash($value);
        if (!is_array($value)) {
            $value = $value === '' || $value === null ? [] : explode(',', (string) $value);
        }

        return array_values(array_unique(array_filter(array_map('absint', $value))));
    }

    private function normalize_roles($value)
    {
        $value = wp_unslash($value);
        if (!is_array($value)) {
            $value = $value === '' || $value === null ? [] : explode(',', (string) $value);
        }

        $allowed = ['administrator', 'modir', 'teacher', 'student'];
        return array_values(array_unique(array_intersect(array_map('sanitize_key', $value), $allowed)));
    }

    private function decode_list($value)
    {
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function get_active_term_id()
    {
        return HST_Terms::active_id();
    }

    public function get_classes()
    {
        return HST_Classes::all_by_name();
    }

    public function get_users_by_role($role)
    {
        $users = get_users([
            'role'    => $role,
            'fields'  => ['ID', 'display_name'],
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ]);

        return is_array($users) ? $users : [];
    }

    public function get_user_class_ids($user_id)
    {
        global $wpdb;
        $term_id = $this->get_active_term_id();
        if (!$term_id) {
            return [];
        }

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT class_id FROM {$wpdb->prefix}hst_users_classes WHERE user_id = %d AND term_id = %d",
            $user_id,
            $term_id
        )) ?: []);
    }

    private function notification_matches_user($notice, $user_id)
    {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        if ($notice->audience === 'all') {
            return true;
        }

        if ($notice->audience === 'roles') {
            return (bool) array_intersect((array) $user->roles, $this->decode_list($notice->role_targets));
        }

        if ($notice->audience === 'classes') {
            return (bool) array_intersect($this->get_user_class_ids($user_id), array_map('intval', $this->decode_list($notice->class_targets)));
        }

        if ($notice->audience === 'users') {
            return in_array((int) $user_id, array_map('intval', $this->decode_list($notice->user_targets)), true);
        }

        return false;
    }

    public function get_visible_notifications($user_id = 0, $limit = 60)
    {
        global $wpdb;
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) {
            return [];
        }

        $now = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT n.*, r.read_at
             FROM {$this->table('hst_notifications')} n
             LEFT JOIN {$this->table('hst_notification_reads')} r
                ON r.notification_id = n.id AND r.user_id = %d
             WHERE n.is_active = 1
                AND (n.publish_at IS NULL OR n.publish_at = '0000-00-00 00:00:00' OR n.publish_at <= %s)
                AND (n.expire_at IS NULL OR n.expire_at = '0000-00-00 00:00:00' OR n.expire_at >= %s)
             ORDER BY n.created_at DESC
             LIMIT %d",
            $user_id,
            $now,
            $now,
            max(1, absint($limit))
        )) ?: [];

        return array_values(array_filter($rows, function ($notice) use ($user_id) {
            return $this->notification_matches_user($notice, $user_id);
        }));
    }

    public function get_admin_notifications()
    {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table('hst_notifications')} ORDER BY created_at DESC LIMIT 200") ?: [];
    }

    public function unread_count($user_id = 0)
    {
        $count = 0;
        foreach ($this->get_visible_notifications($user_id ?: get_current_user_id(), 100) as $notice) {
            if (empty($notice->read_at)) {
                $count++;
            }
        }
        return $count;
    }


    public function get_header_notifications($user_id = 0, $limit = 5)
    {
        $items = [];
        foreach ($this->get_visible_notifications($user_id ?: get_current_user_id(), max(1, absint($limit))) as $notice) {
            $avatar_review = class_exists('HST_Avatar_Approval')
                ? HST_Avatar_Approval::notification_context($notice)
                : [];

            $items[] = [
                'id'            => (int) $notice->id,
                'title'         => (string) $notice->title,
                'message'       => wp_trim_words(wp_strip_all_tags((string) $notice->message), 18, '...'),
                'notice_type'   => (string) $notice->notice_type,
                'link_url'      => (string) $notice->link_url,
                'is_read'       => !empty($notice->read_at),
                'created_at'    => (string) $notice->created_at,
                'avatar_review' => $avatar_review,
            ];
        }

        return $items;
    }

    public function get_header_context($user_id = 0)
    {
        $user_id = absint($user_id ?: get_current_user_id());

        if (!$user_id) {
            return [
                'items'        => [],
                'unread_count' => 0,
                'archive_url'  => home_url('/notifications/'),
            ];
        }

        return [
            'items'        => $this->get_header_notifications($user_id),
            'unread_count' => $this->unread_count($user_id),
            'archive_url'  => home_url('/notifications/'),
        ];
    }

    public function get_context()
    {
        $user_id = get_current_user_id();
        return [
            'is_manager'    => current_user_can('manage_options') || current_user_can('hst_manage_school'),
            'active_term_id' => $this->get_active_term_id(),
            'classes'       => $this->get_classes(),
            'teachers'      => $this->get_users_by_role('teacher'),
            'students'      => $this->get_users_by_role('student'),
            'admin_notices' => current_user_can('manage_options') || current_user_can('hst_manage_school') ? $this->get_admin_notifications() : [],
            'my_notices'    => $this->get_visible_notifications($user_id),
            'unread_count'  => $this->unread_count($user_id),
        ];
    }

    private function get_notification_recipient_ids($audience, array $role_targets = [], array $class_targets = [], array $user_targets = [])
    {
        global $wpdb;

        $audience = in_array($audience, self::AUDIENCES, true) ? $audience : 'all';
        $ids = [];

        if ($audience === 'all') {
            $users = get_users([
                'role__in' => ['administrator', 'modir', 'teacher', 'student'],
                'fields' => ['ID'],
            ]);
            foreach ((array) $users as $user) {
                $ids[] = (int) $user->ID;
            }
        }

        if ($audience === 'roles') {
            $roles = $this->normalize_roles($role_targets);
            if ($roles) {
                $users = get_users([
                    'role__in' => $roles,
                    'fields' => ['ID'],
                ]);
                foreach ((array) $users as $user) {
                    $ids[] = (int) $user->ID;
                }
            }
        }

        if ($audience === 'classes') {
            $class_ids = $this->normalize_ids($class_targets);
            if ($class_ids) {
                $active_term_id = class_exists('HST_Terms') ? (int) HST_Terms::active_id() : 0;
                $placeholders = implode(',', array_fill(0, count($class_ids), '%d'));
                $sql = "SELECT DISTINCT user_id FROM {$wpdb->prefix}hst_users_classes WHERE class_id IN ($placeholders) AND term_id = %d";
                $params = array_merge($class_ids, [$active_term_id]);
                $ids = array_merge($ids, array_map('intval', $wpdb->get_col($wpdb->prepare($sql, ...$params)) ?: []));
            }
        }

        if ($audience === 'users') {
            $ids = array_merge($ids, $this->normalize_ids($user_targets));
        }

        return array_values(array_unique(array_filter(array_map('absint', $ids))));
    }

    private function role_labels(): array
    {
        return [
            'administrator' => 'مدیرکل سایت',
            'modir'         => 'مدیر مدرسه',
            'teacher'       => 'معلم',
            'student'       => 'دانش‌آموز',
        ];
    }

    private function user_initials(string $first_name, string $last_name, string $display_name): string
    {
        $first_char = static function (string $value): string {
            $value = trim($value);
            if ($value === '') {
                return '';
            }
            return function_exists('mb_substr')
                ? mb_substr($value, 0, 1, 'UTF-8')
                : substr($value, 0, 1);
        };

        $parts = preg_split('/\s+/u', trim($display_name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $first_char($first_name) ?: $first_char((string) ($parts[0] ?? ''));
        $last = $first_char($last_name);
        if ($last === '' && count($parts) > 1) {
            $last = $first_char((string) $parts[count($parts) - 1]);
        }

        $initials = array_values(array_filter([$first, $last], static function (string $value): bool {
            return $value !== '';
        }));

        return $initials ? implode("\u{00A0}", $initials) : '؟';
    }

    private function user_report_classes(int $user_id): array
    {
        global $wpdb;

        $active_term_id = class_exists('HST_Terms') ? (int) HST_Terms::active_id() : 0;
        $term_sql = $active_term_id ? $wpdb->prepare(' AND uc.term_id = %d', $active_term_id) : '';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT c.id, c.class_name
             FROM {$wpdb->prefix}hst_users_classes uc
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = uc.class_id
             WHERE uc.user_id = %d {$term_sql}
             ORDER BY c.class_name ASC",
            $user_id
        ));

        $rows = HST_Classes::sort_rows((array) $rows);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->class_name,
            ];
        }

        return $items;
    }

    private function notification_report_rows($notice): array
    {
        global $wpdb;

        $role_targets = $this->decode_list($notice->role_targets ?? '');
        $class_targets = array_map('absint', $this->decode_list($notice->class_targets ?? ''));
        $user_targets = array_map('absint', $this->decode_list($notice->user_targets ?? ''));

        $recipient_ids = $this->get_notification_recipient_ids(
            $notice->audience ?? 'all',
            $role_targets,
            $class_targets,
            $user_targets
        );

        $read_rows = [];
        if ($recipient_ids) {
            $placeholders = implode(',', array_fill(0, count($recipient_ids), '%d'));
            $params = array_merge([(int) $notice->id], $recipient_ids);
            $read_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, read_at FROM {$this->table('hst_notification_reads')}
                 WHERE notification_id = %d AND user_id IN ({$placeholders})",
                ...$params
            ), OBJECT_K) ?: [];
        }

        $role_labels = $this->role_labels();
        $items = [];
        $role_options = [];
        $class_options = [];

        foreach ($recipient_ids as $user_id) {
            $user = get_userdata((int) $user_id);
            if (!$user) {
                continue;
            }

            $roles = [];
            $role_names = [];
            foreach ((array) $user->roles as $role) {
                if (!isset($role_labels[$role])) {
                    continue;
                }
                $roles[] = $role;
                $role_names[] = $role_labels[$role];
                $role_options[$role] = $role_labels[$role];
            }

            $classes = $this->user_report_classes((int) $user_id);
            foreach ($classes as $class) {
                $class_options[$class['id']] = $class['name'];
            }

            $read_at = isset($read_rows[$user_id]) ? (string) $read_rows[$user_id]->read_at : '';
            $avatar_id = (int) get_user_meta((int) $user_id, 'hst_profile_avatar_id', true);
            $avatar_url = $avatar_id ? (string) wp_get_attachment_image_url($avatar_id, 'thumbnail') : '';
            $last_name = trim((string) get_user_meta((int) $user_id, 'last_name', true));
            $first_name = trim((string) get_user_meta((int) $user_id, 'first_name', true));
            $display_name = $user->display_name ?: trim($first_name . ' ' . $last_name) ?: $user->user_login;
            $primary_role = $roles[0] ?? '';
            $primary_class_name = $classes[0]['name'] ?? '';

            $items[] = [
                'id' => (int) $user_id,
                'name' => $display_name,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'initials' => $this->user_initials($first_name, $last_name, $display_name),
                'phone' => class_exists('HST_SMS') ? HST_SMS::user_phone((int) $user_id) : '',
                'avatar_url' => $avatar_url,
                'roles' => $roles,
                'role_label' => $role_names ? implode('، ', $role_names) : '—',
                'primary_role' => $primary_role,
                'sort_name' => $last_name !== '' ? $last_name : $display_name,
                'classes' => $classes,
                'class_label' => $classes ? implode('، ', wp_list_pluck($classes, 'name')) : '—',
                'primary_class_name' => $primary_class_name,
                'is_read' => $read_at !== '',
                'read_at' => $read_at && class_exists('HST_Date') ? HST_Date::format($read_at, 'Y/m/d H:i') : ($read_at ?: ''),
            ];
        }

        $role_order = [
            'administrator' => 1,
            'modir' => 2,
            'teacher' => 3,
            'student' => 4,
        ];

        usort($items, static function ($a, $b) use ($role_order) {
            $role_a = $a['primary_role'] ?? '';
            $role_b = $b['primary_role'] ?? '';
            $role_cmp = ($role_order[$role_a] ?? 99) <=> ($role_order[$role_b] ?? 99);
            if ($role_cmp !== 0) {
                return $role_cmp;
            }

            if ($role_a === 'student' && $role_b === 'student') {
                $class_cmp = HST_Classes::compare_names(
                    (string) ($a['primary_class_name'] ?? ''),
                    (string) ($b['primary_class_name'] ?? '')
                );
                if ($class_cmp !== 0) {
                    return $class_cmp;
                }
            }

            $name_cmp = strnatcasecmp((string) ($a['sort_name'] ?? ''), (string) ($b['sort_name'] ?? ''));
            if ($name_cmp !== 0) {
                return $name_cmp;
            }

            return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        asort($role_options, SORT_NATURAL | SORT_FLAG_CASE);
        $class_options = HST_Classes::sort_options($class_options);

        $read_count = count(array_filter($items, static fn($item) => !empty($item['is_read'])));

        return [
            'items' => array_values($items),
            'summary' => [
                'total' => count($items),
                'read' => $read_count,
                'unread' => count($items) - $read_count,
            ],
            'filters' => [
                'roles' => array_map(
                    static fn($key, $label) => ['id' => $key, 'name' => $label],
                    array_keys($role_options),
                    array_values($role_options)
                ),
                'classes' => array_map(
                    static fn($key, $label) => ['id' => (int) $key, 'name' => $label],
                    array_keys($class_options),
                    array_values($class_options)
                ),
            ],
        ];
    }

    private static function merge_auto_user_notification(array $args, array $row_data)
    {
        global $wpdb;

        $targets = array_values(array_unique(array_filter(array_map('absint', (array) ($args['user_targets'] ?? [])))));
        if (($row_data['source'] ?? '') !== 'auto' || ($row_data['audience'] ?? '') !== 'users' || empty($targets)) {
            return false;
        }

        $table = $wpdb->prefix . 'hst_notifications';
        $day_start = date_i18n('Y-m-d 00:00:00', current_time('timestamp'));

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_targets FROM {$table}
             WHERE source = 'auto'
               AND audience = 'users'
               AND title = %s
               AND message = %s
               AND notice_type = %s
               AND link_url = %s
               AND created_at >= %s
             ORDER BY id DESC
             LIMIT 1",
            $row_data['title'],
            $row_data['message'],
            $row_data['notice_type'],
            $row_data['link_url'],
            $day_start
        ));

        if (!$existing) {
            return false;
        }

        $old_targets = json_decode((string) $existing->user_targets, true);
        $old_targets = is_array($old_targets) ? array_map('absint', $old_targets) : [];
        $merged = array_values(array_unique(array_merge($old_targets, $targets)));

        $wpdb->update(
            $table,
            [
                'user_targets' => wp_json_encode($merged, JSON_UNESCAPED_UNICODE),
                'is_active' => !empty($row_data['is_active']) ? 1 : 0,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => (int) $existing->id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        return (int) $existing->id;
    }

    public static function sms_template_vars(): array
    {
        return [
            '{name}'    => 'نام گیرنده',
            '{title}'   => 'عنوان اطلاعیه',
            '{message}' => 'متن اطلاعیه',
            '{type}'    => 'نوع اطلاعیه',
            '{date}'    => 'تاریخ ارسال',
            '{school}'  => 'نام مدرسه',
        ];
    }

    private function notification_type_label($type): string
    {
        $labels = [
            'info'    => 'اطلاعیه',
            'success' => 'خبر خوب',
            'warning' => 'هشدار',
            'danger'  => 'مهم',
        ];

        return $labels[$type] ?? 'اطلاعیه';
    }

    private function notification_sms_template($value = ''): string
    {
        return class_exists('HST_SMS')
            ? HST_SMS::message_template($value, 'notification')
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

    public function ajax_notification_sms_estimate(): void
    {
        $this->authorize_ajax(true);

        $notice_id = absint(wp_unslash($_POST['id'] ?? 0));
        $template = $this->sanitize_sms_template_input(wp_unslash($_POST['message'] ?? ''));
        if (!$notice_id || $template === '') {
            wp_send_json_error(['message' => 'اطلاعیه یا متن پیامک معتبر نیست.'], 400);
        }
        if (!class_exists('HST_SMS')) {
            wp_send_json_error(['message' => 'سرویس محاسبه پیامک در دسترس نیست.'], 500);
        }

        global $wpdb;
        $table = $this->table('hst_notifications');
        $notice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $notice_id));
        if (!$notice) {
            wp_send_json_error(['message' => 'اطلاعیه پیدا نشد.'], 404);
        }

        $recipient_ids = array_values(array_unique(array_filter(array_map('absint', $this->get_notification_recipient_ids(
            $notice->audience,
            $this->decode_list($notice->role_targets),
            array_map('absint', $this->decode_list($notice->class_targets)),
            array_map('absint', $this->decode_list($notice->user_targets))
        )))));

        $school = class_exists('HST_Settings')
            ? (string) HST_Settings::option('hst-home-school-name', get_bloginfo('name'))
            : (string) get_bloginfo('name');
        $school = trim($school) !== '' ? $school : 'مدرسه';
        $today = class_exists('HST_Date') ? HST_Date::today('Y/m/d') : date_i18n('Y/m/d');
        $items = [];
        $skipped = 0;

        foreach ($recipient_ids as $user_id) {
            $phone = HST_SMS::user_phone($user_id);
            if ($phone === '') {
                $skipped++;
                continue;
            }
            $user = get_userdata($user_id);
            $context = [
                'name'    => $user ? ($user->display_name ?: $user->user_login) : 'کاربر',
                'title'   => trim((string) $notice->title) ?: 'اطلاعیه',
                'message' => wp_strip_all_tags((string) $notice->message),
                'type'    => $this->notification_type_label((string) $notice->notice_type),
                'school'  => $school,
                'date'    => $today,
            ];
            $items[] = [
                'phone' => $phone,
                'message' => HST_SMS::render_message($template, $context),
            ];
        }

        $estimate = HST_SMS::estimate_consumption($items, false);
        $estimate['target_count'] = count($recipient_ids);
        $estimate['skipped_count'] = $skipped;
        wp_send_json_success(['estimate' => $estimate]);
    }

    private function send_notification_sms_once($notice_id)
    {
        global $wpdb;

        $notice_id = absint($notice_id);
        if (!$notice_id) {
            return null;
        }

        $table = $this->table('hst_notifications');
        $notice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $notice_id));
        if (!$notice || empty($notice->sms_enabled) || !empty($notice->sms_sent_at)) {
            return null;
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

        if (!empty($notice->publish_at) && $notice->publish_at !== '0000-00-00 00:00:00' && strtotime($notice->publish_at) > current_time('timestamp')) {
            return [
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'errors' => ['زمان انتشار اطلاعیه هنوز نرسیده است؛ پیامک ارسال نشد.'],
                'not_sent' => true,
            ];
        }

        $recipient_ids = $this->get_notification_recipient_ids(
            $notice->audience,
            $this->decode_list($notice->role_targets),
            array_map('absint', $this->decode_list($notice->class_targets)),
            array_map('absint', $this->decode_list($notice->user_targets))
        );

        $result = [
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $school = class_exists('HST_Settings')
            ? (string) HST_Settings::option('hst-home-school-name', get_bloginfo('name'))
            : (string) get_bloginfo('name');
        $school = trim($school) !== '' ? $school : 'مدرسه';
        $today = class_exists('HST_Date') ? HST_Date::today('Y/m/d') : date_i18n('Y/m/d');
        $template = $this->notification_sms_template($notice->sms_message ?? '');

        foreach (array_values(array_unique(array_filter(array_map('absint', $recipient_ids)))) as $user_id) {
            $phone = HST_SMS::user_phone($user_id);
            if (!$phone) {
                $result['skipped']++;
                continue;
            }

            $user = get_userdata($user_id);
            $sent = HST_SMS::send_notification($phone, [
                'name'         => $user ? ($user->display_name ?: $user->user_login) : 'کاربر',
                'title'        => trim((string) $notice->title) ?: 'اطلاعیه',
                'message'      => wp_strip_all_tags((string) $notice->message),
                'type'         => $this->notification_type_label((string) $notice->notice_type),
                'school'       => $school,
                'date'         => $today,
                'sms_template' => $template,
            ]);
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
            $table,
            [
                'sms_sent_at' => $result['sent'] > 0 ? current_time('mysql') : null,
                'sms_result' => wp_json_encode($result, JSON_UNESCAPED_UNICODE),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $notice_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        return $result;
    }

    public static function create_notification(array $args)
    {
        global $wpdb;

        $has_explicit_active_state = array_key_exists('is_active', $args);

        $defaults = [
            'title'        => '',
            'message'      => '',
            'notice_type'  => 'info',
            'audience'     => 'all',
            'role_targets' => [],
            'class_targets'=> [],
            'user_targets' => [],
            'link_url'     => '',
            'publish_at'   => null,
            'expire_at'    => null,
            'is_active'    => 0,
            'created_by'   => get_current_user_id(),
            'source'       => 'manual',
            'send_sms'     => 0,
            'sms_message'   => '',
            'merge_auto'    => true,
        ];
        $args = wp_parse_args($args, $defaults);

        // Automatic notifications are published immediately unless their caller
        // deliberately provides an explicit active state. Manual notifications
        // keep their existing inactive-by-default workflow.
        if (!$has_explicit_active_state && ($args['source'] ?? 'manual') === 'auto') {
            $args['is_active'] = 1;
        }

        $types = self::TYPES;
        $audiences = self::AUDIENCES;

        $title = mb_substr(sanitize_text_field($args['title']), 0, 160);
        $message = mb_substr(wp_kses_post($args['message']), 0, 5000);

        // Replace global template variables ({school}, {date}). Per-user vars
        // like {name} aren't supported here because a notification is a single
        // shared message rather than one per recipient.
        $school = class_exists('HST_Settings') ? (string) HST_Settings::option('hst-home-school-name', get_bloginfo('name')) : get_bloginfo('name');
        $today  = class_exists('HST_Date') ? HST_Date::today('Y/m/d') : date_i18n('Y/m/d');
        $repl = ['{school}' => $school, '{date}' => $today];
        $title = strtr($title, $repl);
        $message = strtr($message, $repl);

        if ($title === '' || $message === '') {
            return false;
        }

        $notice_type = in_array($args['notice_type'], $types, true) ? $args['notice_type'] : 'info';
        $audience = in_array($args['audience'], $audiences, true) ? $args['audience'] : 'all';
        $publish_at = $args['publish_at'] ? (new self())->normalize_datetime($args['publish_at']) : null;
        $expire_at = $args['expire_at'] ? (new self())->normalize_datetime($args['expire_at']) : null;
        if ($publish_at === false || $expire_at === false) {
            return false;
        }
        if ($publish_at && $expire_at && strtotime($expire_at) <= strtotime($publish_at)) {
            return false;
        }
        $encode = static function ($items) {
            return wp_json_encode(array_values((array) $items), JSON_UNESCAPED_UNICODE);
        };

        $sms_message = '';
        if (!empty($args['send_sms'])) {
            $instance = new self();
            $sms_message = $instance->sanitize_sms_template_input($args['sms_message'] ?? '');
            if ($sms_message === '') {
                $sms_message = $instance->notification_sms_template('');
            }
        }

        $row_data = [
            'title'         => $title,
            'message'       => $message,
            'notice_type'   => $notice_type,
            'audience'      => $audience,
            'role_targets'  => $encode($args['role_targets']),
            'class_targets' => $encode(array_map('absint', (array) $args['class_targets'])),
            'user_targets'  => $encode(array_map('absint', (array) $args['user_targets'])),
            'link_url'      => esc_url_raw($args['link_url']),
            'publish_at'    => $publish_at,
            'expire_at'     => $expire_at,
            'is_active'     => absint($args['is_active']) ? 1 : 0,
            'created_by'    => absint($args['created_by']),
            'source'        => in_array($args['source'], ['manual', 'auto'], true) ? $args['source'] : 'manual',
            'sms_enabled'   => !empty($args['send_sms']) ? 1 : 0,
            'sms_message'   => $sms_message,
            'sms_sent_at'   => null,
            'sms_result'    => null,
            'created_at'    => current_time('mysql'),
        ];

        $merged_id = !empty($args['merge_auto']) ? self::merge_auto_user_notification($args, $row_data) : false;
        if ($merged_id) {
            return $merged_id;
        }

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'hst_notifications',
            $row_data,
            ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%d','%s','%s','%s','%s']
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }




    public function ajax_update_notification_sms()
    {
        $this->authorize_ajax(true);

        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        $enabled = absint($_POST['enabled'] ?? 0) ? 1 : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'شناسه اطلاعیه نامعتبر است.'], 400);
        }

        $table = $this->table('hst_notifications');
        $notice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        if (!$notice) {
            wp_send_json_error(['message' => 'اطلاعیه پیدا نشد.'], 404);
        }


        if (!$enabled) {
            $updated = $wpdb->update(
                $table,
                [
                    'sms_enabled' => 0,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $id],
                ['%d', '%s'],
                ['%d']
            );

            if ($updated === false) {
                wp_send_json_error(['message' => 'غیرفعال‌سازی پیامک انجام نشد.'], 400);
            }

            wp_send_json_success([
                'message' => 'پیامک اطلاعیه غیرفعال شد.',
                'id' => $id,
                'sms_enabled' => 0,
            ]);
        }

        if (!class_exists('HST_SMS') || !HST_SMS::direct_ready()) {
            wp_send_json_error(['message' => 'ارسال مستقیم پیامک فعال یا پیکربندی نشده است.'], 400);
        }

        $message_template = $this->sanitize_sms_template_input(wp_unslash($_POST['message'] ?? ''));
        if ($message_template === '') {
            wp_send_json_error(['message' => 'متن پیامک نمی‌تواند خالی باشد.'], 400);
        }

        $updated = $wpdb->update(
            $table,
            [
                'sms_enabled' => 1,
                'sms_message' => $message_template,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => 'ذخیره تنظیمات پیامک انجام نشد.'], 400);
        }

        $response_message = 'پیامک اطلاعیه فعال شد.';
        $sms_result = null;

        $notice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        if ($notice && (int) $notice->is_active === 1) {
            $sms_result = $this->send_notification_sms_once($id);
            if (is_array($sms_result) && empty($sms_result['not_sent'])) {
                $response_message .= sprintf(
                    ' پیامک اطلاعیه: %d ارسال، %d ناموفق، %d بدون شماره.',
                    intval($sms_result['sent'] ?? 0),
                    intval($sms_result['failed'] ?? 0),
                    intval($sms_result['skipped'] ?? 0)
                );
            } elseif (is_array($sms_result) && !empty($sms_result['errors'])) {
                $response_message .= ' ' . implode(' ', array_slice(array_map('sanitize_text_field', $sms_result['errors']), 0, 1));
            }
        }

        wp_send_json_success([
            'message' => $response_message,
            'id' => $id,
            'sms_enabled' => 1,
            'sms_message' => $message_template,
            'sms_sent' => is_array($sms_result) && intval($sms_result['sent'] ?? 0) > 0 ? 1 : 0,
            'sms' => $sms_result,
        ]);
    }

    public function ajax_notification_sms_test()
    {
        $this->authorize_ajax(true);

        if (!class_exists('HST_SMS') || !HST_SMS::direct_ready()) {
            wp_send_json_error(['message' => 'ارسال مستقیم پیامک فعال یا پیکربندی نشده است.'], 400);
        }

        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $id = absint($_POST['id'] ?? 0);
        $message_template = $this->sanitize_sms_template_input(wp_unslash($_POST['message'] ?? ''));

        if ($phone === '') {
            wp_send_json_error(['message' => 'شماره دریافت‌کننده تست را وارد کنید.'], 400);
        }
        if ($message_template === '') {
            wp_send_json_error(['message' => 'متن پیامک را وارد کنید.'], 400);
        }

        global $wpdb;
        $notice = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table('hst_notifications')} WHERE id = %d", $id)) : null;
        $current_user = wp_get_current_user();
        $name = $current_user && $current_user->exists()
            ? ($current_user->display_name ?: $current_user->user_login)
            : 'کاربر نمونه';

        $school = class_exists('HST_Settings')
            ? (string) HST_Settings::option('hst-home-school-name', get_bloginfo('name'))
            : (string) get_bloginfo('name');
        $school = trim($school) !== '' ? $school : 'مدرسه';
        $today = class_exists('HST_Date') ? HST_Date::today('Y/m/d') : date_i18n('Y/m/d');


        $sent = HST_SMS::send_notification($phone, [
            'name'         => $name,
            'title'        => $notice ? (string) $notice->title : 'عنوان نمونه اطلاعیه',
            'message'      => $notice ? wp_strip_all_tags((string) $notice->message) : 'متن نمونه اطلاعیه',
            'type'         => $notice ? $this->notification_type_label((string) $notice->notice_type) : 'اطلاعیه',
            'school'       => $school,
            'date'         => $today,
            'sms_template' => $message_template,
        ]);
        if (is_wp_error($sent)) {
            wp_send_json_error(['message' => $sent->get_error_message()], 400);
        }

        if ($sent !== true) {
            wp_send_json_error(['message' => 'ارسال پیامک تست انجام نشد.'], 400);
        }

        wp_send_json_success(['message' => 'پیامک تست با موفقیت ارسال شد.']);
    }

    public function ajax_notification_report()
    {
        $this->authorize_ajax(true);

        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => 'شناسه اطلاعیه نامعتبر است.'], 400);
        }

        $notice = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('hst_notifications')} WHERE id = %d",
            $id
        ));

        if (!$notice) {
            wp_send_json_error(['message' => 'اطلاعیه پیدا نشد.'], 404);
        }

        $report = $this->notification_report_rows($notice);
        wp_send_json_success([
            'notice' => [
                'id' => (int) $notice->id,
                'title' => (string) $notice->title,
                'audience' => (string) $notice->audience,
                'source' => (string) ($notice->source ?? 'manual'),
            ],
            'items' => $report['items'],
            'summary' => $report['summary'],
            'filters' => $report['filters'],
        ]);
    }

    public function ajax_search_notification_users()
    {
        $this->authorize_ajax(true);

        $query = sanitize_text_field(wp_unslash($_POST['query'] ?? ''));
        if (mb_strlen($query) < 2) {
            wp_send_json_success(['items' => []]);
        }

        $roles = ['administrator', 'modir', 'teacher', 'student'];
        $users = get_users([
            'role__in'        => $roles,
            'search'          => '*' . esc_attr($query) . '*',
            'search_columns'  => ['user_login', 'user_nicename', 'user_email', 'display_name'],
            'number'          => 20,
            'orderby'         => 'display_name',
            'order'           => 'ASC',
            'fields'          => ['ID', 'display_name', 'user_login'],
        ]);

        $role_labels = [
            'administrator' => 'مدیرکل سایت',
            'modir'         => 'مدیر مدرسه',
            'teacher'       => 'معلم',
            'student'       => 'دانش‌آموز',
        ];

        $items = [];
        foreach ((array) $users as $user) {
            $user_data = get_userdata($user->ID);
            if (!$user_data) {
                continue;
            }

            $labels = [];
            foreach ((array) $user_data->roles as $role) {
                if (isset($role_labels[$role])) {
                    $labels[] = $role_labels[$role];
                }
            }

            $items[] = [
                'id'       => (int) $user->ID,
                'name'     => (string) $user->display_name,
                'phone'    => class_exists('HST_SMS') ? HST_SMS::user_phone((int) $user->ID) : '',
                'roles'    => implode('، ', $labels),
            ];
        }

        wp_send_json_success(['items' => $items]);
    }

    public function ajax_add_notification()
    {
        $this->authorize_ajax(true);

        $audience = sanitize_key(wp_unslash($_POST['audience'] ?? 'all'));
        if (!in_array($audience, self::AUDIENCES, true)) {
            $audience = 'all';
        }

        $publish_at = sanitize_text_field(wp_unslash($_POST['publish_at'] ?? ''));
        $expire_at = sanitize_text_field(wp_unslash($_POST['expire_at'] ?? ''));

        $role_targets = $this->normalize_roles($_POST['role_targets'] ?? []);
        $class_targets = $this->normalize_ids($_POST['class_targets'] ?? []);
        $user_targets = $this->normalize_ids($_POST['user_targets'] ?? []);
        $this->validate_audience_targets($audience, $role_targets, $class_targets, $user_targets);

        $publish_check = $this->normalize_datetime($publish_at);
        $expire_check = $this->normalize_datetime($expire_at);
        if ($publish_check === false || $expire_check === false) {
            wp_send_json_error(['message' => 'تاریخ انتشار یا انقضا معتبر نیست.'], 400);
        }
        if ($publish_check && $expire_check && strtotime($expire_check) <= strtotime($publish_check)) {
            wp_send_json_error(['message' => 'تاریخ انقضا باید بعد از تاریخ انتشار باشد.'], 400);
        }

        $notification_args = [
            'title'         => wp_unslash($_POST['title'] ?? ''),
            'message'       => wp_unslash($_POST['message'] ?? ''),
            'notice_type'   => sanitize_key(wp_unslash($_POST['notice_type'] ?? 'info')),
            'audience'      => $audience,
            'role_targets'  => $role_targets,
            'class_targets' => $class_targets,
            'user_targets'  => $user_targets,
            'link_url'      => wp_unslash($_POST['link_url'] ?? ''),
            'publish_at'    => $publish_at ?: null,
            'expire_at'     => $expire_at ?: null,
            'is_active'     => isset($_POST['is_active']) ? absint($_POST['is_active']) : 0,
            'send_sms'      => 0,
            'sms_message'    => '',
        ];

        $inserted = self::create_notification($notification_args);

        if (!$inserted) {
            wp_send_json_error(['message' => 'ثبت اطلاعیه انجام نشد.']);
        }

        wp_send_json_success(['message' => 'اطلاعیه با موفقیت ثبت شد.', 'id' => (int) $inserted]);
    }

    public function ajax_delete_notification()
    {
        $this->authorize_ajax(true);
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => 'شناسه نامعتبر است.']);
        }
        $wpdb->delete($this->table('hst_notification_reads'), ['notification_id' => $id], ['%d']);
        $deleted = $wpdb->delete($this->table('hst_notifications'), ['id' => $id], ['%d']);
        if ($deleted === false) {
            wp_send_json_error(['message' => 'حذف اطلاعیه انجام نشد.']);
        }
        wp_send_json_success(['message' => 'اطلاعیه حذف شد.']);
    }    public function ajax_toggle_notification()
    {
        $this->authorize_ajax(true);
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        $is_active = absint($_POST['is_active'] ?? 0) ? 1 : 0;
        if (!$id) {
            wp_send_json_error(['message' => 'شناسه نامعتبر است.']);
        }

        $updated = $wpdb->update(
            $this->table('hst_notifications'),
            ['is_active' => $is_active, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%d','%s'],
            ['%d']
        );
        if ($updated === false) {
            wp_send_json_error(['message' => 'تغییر وضعیت انجام نشد.']);
        }

        $message = 'وضعیت اطلاعیه تغییر کرد.';
        $sms_result = null;
        if ($is_active) {
            $sms_result = $this->send_notification_sms_once($id);
            if (is_array($sms_result) && empty($sms_result['not_sent'])) {
                $message .= sprintf(
                    ' پیامک اطلاعیه: %d ارسال، %d ناموفق، %d بدون شماره.',
                    intval($sms_result['sent'] ?? 0),
                    intval($sms_result['failed'] ?? 0),
                    intval($sms_result['skipped'] ?? 0)
                );
            } elseif (is_array($sms_result) && !empty($sms_result['errors'])) {
                $message .= ' ' . implode(' ', array_slice(array_map('sanitize_text_field', $sms_result['errors']), 0, 1));
            }
        }

        wp_send_json_success(['message' => $message, 'sms' => $sms_result]);
    }

    public function ajax_mark_read()
    {
        $this->authorize_ajax(false);
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => 'شناسه نامعتبر است.']);
        }
        if (!$this->current_user_can_view_notice($id)) {
            wp_send_json_error(['message' => 'این اطلاعیه برای حساب شما قابل مشاهده نیست.'], 403);
        }
        $wpdb->replace(
            $this->table('hst_notification_reads'),
            ['notification_id' => $id, 'user_id' => get_current_user_id(), 'read_at' => current_time('mysql')],
            ['%d','%d','%s']
        );
        wp_send_json_success(['message' => 'خوانده شد.']);
    }

    public function ajax_mark_all_read()
    {
        $this->authorize_ajax(false);
        global $wpdb;
        foreach ($this->get_visible_notifications(get_current_user_id(), 200) as $notice) {
            $wpdb->replace(
                $this->table('hst_notification_reads'),
                ['notification_id' => (int) $notice->id, 'user_id' => get_current_user_id(), 'read_at' => current_time('mysql')],
                ['%d','%d','%s']
            );
        }
        wp_send_json_success(['message' => 'همه اطلاعیه‌ها خوانده شدند.']);
    }

    public function ajax_get_header_notifications()
    {
        $this->authorize_ajax(false);

        wp_send_json_success($this->get_header_context(get_current_user_id()));
    }

}
