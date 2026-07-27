<?php

defined('ABSPATH') || exit;

/**
 * School-wide discipline records (موارد انضباطی).
 *
 * Managers can log any number of discipline items for students — violations,
 * warnings, praise, absences, or late arrivals — and notify a student's parent by SMS directly from the
 * same screen. The parent's phone is read from the student's hst_parent_phone
 * meta (falling back to their own phone).
 */
class HST_Discipline
{
    const PARENT_PHONE_META = 'hst_parent_phone';

    const TYPES = [
        'violation' => 'تخلف',
        'warning'   => 'اخطار',
        'praise'    => 'تشویق',
        'absence'   => 'غیبت',
        'late'      => 'تأخیر',
    ];

    private const CALCULATION_SETTINGS_OPTION = 'hst-discipline-calculation-settings';

    const SEVERITIES = [
        'low'    => 'کم',
        'medium' => 'متوسط',
        'high'   => 'زیاد',
    ];

    public function __construct()
    {
        $this->maybe_upgrade_discipline_sms_schema();
        add_action('init', [$this, 'maybe_render_public_sheet']);
        add_action('wp_ajax_hst_discipline_search_students', [$this, 'ajax_search_students']);
        add_action('wp_ajax_hst_discipline_list', [$this, 'ajax_list']);
        add_action('wp_ajax_hst_discipline_save', [$this, 'ajax_save']);
        add_action('wp_ajax_hst_discipline_print_book', [$this, 'ajax_print_book']);
        add_action('wp_ajax_hst_discipline_delete', [$this, 'ajax_delete']);
        add_action('wp_ajax_hst_update_discipline_sms', [$this, 'ajax_update_discipline_sms']);
        add_action('wp_ajax_hst_discipline_sms_test', [$this, 'ajax_discipline_sms_test']);
        add_action('wp_ajax_hst_discipline_sms_estimate', [$this, 'ajax_discipline_sms_estimate']);
        add_action('wp_ajax_hst_discipline_calculation_settings_save', [$this, 'ajax_save_calculation_settings']);
    }

    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hst_discipline';
    }

    public static function calculation_defaults(): array
    {
        return [
            'violation' => ['conduct' => -1.0, 'attendance' => 0.0],
            'warning'   => ['conduct' => -0.5, 'attendance' => 0.0],
            'praise'    => ['conduct' => 0.25, 'attendance' => 0.0],
            'absence'   => ['conduct' => -0.5, 'attendance' => -2.0],
            'late'      => ['conduct' => -0.25, 'attendance' => -0.5],
        ];
    }

    public static function calculation_settings(): array
    {
        $defaults = self::calculation_defaults();
        $saved = get_option(self::CALCULATION_SETTINGS_OPTION, []);
        if (!is_array($saved)) {
            return $defaults;
        }

        foreach ($defaults as $type => $effects) {
            $row = isset($saved[$type]) && is_array($saved[$type]) ? $saved[$type] : [];
            $conduct = isset($row['conduct']) && is_numeric($row['conduct'])
                ? (float) $row['conduct']
                : (float) $effects['conduct'];
            $attendance = isset($row['attendance']) && is_numeric($row['attendance'])
                ? (float) $row['attendance']
                : (float) $effects['attendance'];

            $defaults[$type] = [
                'conduct'    => round(self::clamp_float($conduct, -20.0, 20.0), 2),
                'attendance' => in_array($type, ['absence', 'late'], true)
                    ? round(self::clamp_float($attendance, -100.0, 100.0), 2)
                    : 0.0,
            ];
        }

        return $defaults;
    }

    private static function clamp_float(float $value, float $minimum, float $maximum): float
    {
        return max($minimum, min($maximum, $value));
    }

    public function ajax_save_calculation_settings(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');

        $raw = isset($_POST['effects']) && is_array($_POST['effects'])
            ? wp_unslash($_POST['effects'])
            : [];
        $settings = self::calculation_defaults();

        foreach ($settings as $type => $defaults) {
            $row = isset($raw[$type]) && is_array($raw[$type]) ? $raw[$type] : [];
            $conduct = isset($row['conduct']) && is_numeric($row['conduct'])
                ? (float) $row['conduct']
                : (float) $defaults['conduct'];
            $attendance = isset($row['attendance']) && is_numeric($row['attendance'])
                ? (float) $row['attendance']
                : (float) $defaults['attendance'];

            $settings[$type] = [
                'conduct'    => round(self::clamp_float($conduct, -20.0, 20.0), 2),
                'attendance' => in_array($type, ['absence', 'late'], true)
                    ? round(self::clamp_float($attendance, -100.0, 100.0), 2)
                    : 0.0,
            ];
        }

        update_option(self::CALCULATION_SETTINGS_OPTION, $settings, false);

        wp_send_json_success([
            'message'  => 'تنظیمات محاسبه کارنامه انضباطی ذخیره شد.',
            'settings' => $settings,
        ]);
    }


    private function maybe_upgrade_discipline_sms_schema(): void
    {
        global $wpdb;

        $table = $this->table();
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            return;
        }

        $columns = [
            'sms_enabled' => "ALTER TABLE {$table} ADD sms_enabled tinyint(1) NOT NULL DEFAULT 0 AFTER parent_notified",
            'sms_message' => "ALTER TABLE {$table} ADD sms_message longtext NULL AFTER sms_enabled",
            'sms_result'  => "ALTER TABLE {$table} ADD sms_result longtext NULL AFTER notified_at",
        ];

        foreach ($columns as $column => $sql) {
            $has_column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
            if (!$has_column) {
                $wpdb->query($sql);
            }
        }

        $type_column = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'type'");
        $type_definition = is_object($type_column) ? (string) ($type_column->Type ?? '') : '';
        if (strpos($type_definition, "'absence'") === false || strpos($type_definition, "'late'") === false) {
            $wpdb->query("ALTER TABLE {$table} MODIFY type enum('violation','warning','praise','absence','late') NOT NULL DEFAULT 'violation'");
        }
    }

    private function avatar_original_url(int $avatar_id): string
    {
        if (!$avatar_id) {
            return '';
        }

        $url = (string) wp_get_attachment_url($avatar_id);
        if ($url === '') {
            $url = $this->avatar_original_url($avatar_id);
        }

        return $url;
    }

    private function public_sheet_token(int $student_id): string
    {
        return substr(hash_hmac('sha256', 'discipline-sheet|' . $student_id . '|' . get_current_blog_id(), wp_salt('auth')), 0, 16);
    }

    private function public_sheet_url(int $student_id): string
    {
        return home_url('/?hstd_pdf=' . $student_id . '-' . $this->public_sheet_token($student_id));
    }

    private function qr_image_url(string $payload, int $size = 260): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=' . absint($size) . 'x' . absint($size) . '&margin=14&data=' . rawurlencode($payload);
    }


    private function school_manager_name(): string
    {
        $roles = ['modir', 'administrator'];

        foreach ($roles as $role) {
            $users = get_users([
                'role'    => $role,
                'number'  => 1,
                'orderby' => 'ID',
                'order'   => 'ASC',
                'fields'  => ['ID', 'display_name'],
            ]);

            if (empty($users)) {
                continue;
            }

            $user = $users[0];
            $name = trim((string) get_user_meta((int) $user->ID, 'first_name', true) . ' ' . (string) get_user_meta((int) $user->ID, 'last_name', true));
            if ($name === '') {
                $name = trim((string) ($user->display_name ?? ''));
            }
            if ($name !== '') {
                $normalized = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
                if (in_array($normalized, ['مدیر تیچرشو', 'مدير تيچرشو', 'teachershow manager', 'manager teachershow'], true)) {
                    return 'مدیر مدرسه';
                }
                return $name;
            }
        }

        return 'مدیر مدرسه';
    }
    public function maybe_render_public_sheet(): void
    {
        $is_pdf_download = !empty($_GET['hstd_pdf']);
        $is_preview = !empty($_GET['hstd']);

        if (!$is_pdf_download && !$is_preview) {
            return;
        }

        $raw = sanitize_text_field(wp_unslash((string) ($is_pdf_download ? $_GET['hstd_pdf'] : $_GET['hstd'])));
        if (!preg_match('/^(\d+)-([a-f0-9]{16})$/i', $raw, $m)) {
            status_header(403);
            wp_die('لینک دفتر انضباطی معتبر نیست.');
        }

        $student_id = absint($m[1]);
        $token = strtolower((string) $m[2]);
        if (!$student_id || !hash_equals($this->public_sheet_token($student_id), $token)) {
            status_header(403);
            wp_die('لینک دفتر انضباطی معتبر نیست.');
        }

        $blocks = $this->discipline_book_blocks($student_id);
        if (empty($blocks)) {
            status_header(404);
            wp_die('دفتر انضباطی این دانش‌آموز پیدا نشد.');
        }

        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));

        if ($is_pdf_download) {
            echo $this->discipline_book_pdf_download_html($blocks); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            echo $this->discipline_book_html($blocks, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        exit;
    }

    private function discipline_book_pdf_download_html(array $blocks): string
    {
        $student_name = !empty($blocks[0]['student_name']) ? (string) $blocks[0]['student_name'] : 'دانش‌آموز';
        $filename_subject = sanitize_file_name($student_name);
        $filename = 'دفتر-انضباطی' . ($filename_subject !== '' ? '-' . $filename_subject : '') . '.pdf';

        $accent = class_exists('HST_Settings') ? HST_Settings::fixed_accent_color() : '#334155';

        $logo_url = '';
        if (class_exists('HST_Settings')) {
            $logo_id = (int) HST_Settings::option('hst-home-logo-id', 0);
            if ($logo_id) {
                $logo_url = (string) wp_get_attachment_image_url($logo_id, 'medium');
            }
        }

        $config = [
            'accent'      => $accent,
            'schoolName'  => get_bloginfo('name'),
            'logoUrl'     => $logo_url,
            'managerName' => $this->school_manager_name(),
            'fontUrl'     => HST_URL . 'assets/font/Vazir.woff2',
            'orientation' => 'P',
            'paper'       => 'A4',
            'today'       => class_exists('HST_Date') ? HST_Date::today() : date_i18n('Y/m/d'),
        ];

        $payload = [
            'blocks'       => $blocks,
            'title'        => 'دفتر انضباطی ' . $student_name,
            'filename'     => $filename,
            'fallbackHtml' => $this->discipline_book_html($blocks, true),
        ];

        return HST_Print_Document::download_page([
            'title'       => 'دانلود دفتر انضباطی',
            'message'     => 'فایل PDF دفتر انضباطی در حال آماده‌سازی و دانلود است.',
            'config'      => $config,
            'payload'     => $payload,
            'payload_key' => 'hstDisciplinePdfPayload',
            'method'      => 'disciplineBookPdf',
            'scripts'     => [
                HST_URL . 'assets/lib/jspdf.umd.min.js',
                HST_URL . 'assets/lib/vazir-font.js',
                HST_URL . 'assets/lib/persian-shaper.js',
                HST_URL . 'assets/lib/qrcode/qrcode-generator.js',
                HST_URL . 'assets/js/core/hst-print.js',
            ],
        ]);
    }



    private function user_initials(int $user_id, string $display_name = ''): string
    {
        $first_name = $user_id > 0 ? trim((string) get_user_meta($user_id, 'first_name', true)) : '';
        $last_name = $user_id > 0 ? trim((string) get_user_meta($user_id, 'last_name', true)) : '';

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
        $first_initial = $first_char($first_name) ?: $first_char((string) ($parts[0] ?? ''));
        $last_initial = $first_char($last_name);
        if ($last_initial === '' && count($parts) >= 2) {
            $last_initial = $first_char((string) $parts[count($parts) - 1]);
        }

        $initials = array_values(array_filter(
            [$first_initial, $last_initial],
            static function (string $initial): bool {
                return $initial !== '';
            }
        ));

        return $initials ? implode("\u{00A0}", $initials) : '؟';
    }


    /** Search students by name for the picker. */
    public function ajax_search_students(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
        $query = HST_Guard::post_text('query');
        if (mb_strlen($query) < 2) {
            wp_send_json_success(['items' => []]);
        }

        $users = get_users([
            'role'    => 'student',
            'search'  => '*' . esc_attr($query) . '*',
            'search_columns' => ['display_name', 'user_login', 'user_nicename'],
            'number'  => 15,
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ]);

        $items = [];
        foreach ($users as $u) {
            $avatar_id = absint(get_user_meta($u->ID, 'hst_profile_avatar_id', true));
            if (!$avatar_id && class_exists('HST_Avatar_Approval')) {
                $avatar_id = (int) HST_Avatar_Approval::display_avatar_id((int) $u->ID, (int) $u->ID);
            }

            $avatar_url = $avatar_id ? $this->avatar_original_url($avatar_id) : '';
            $name = (string) $u->display_name;
            $initial = $this->user_initials((int) $u->ID, $name);

            $items[] = [
                'id'         => (int) $u->ID,
                'name'       => $name,
                'phone'      => class_exists('HST_SMS') ? HST_SMS::user_phone((int) $u->ID) : '',
                'avatar_url' => $avatar_url,
                'initial'    => $initial,
            ];
        }
        wp_send_json_success(['items' => $items]);
    }

    /** List records, newest first, with optional type/student filters + stats. */
    public function ajax_list(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
        global $wpdb;
        $t = $this->table();

        $type = HST_Guard::post_text('type');
        $query = HST_Guard::post_text('query');

        $where = '1=1';
        $params = [];
        if (isset(self::TYPES[$type])) {
            $where .= ' AND d.type = %s';
            $params[] = $type;
        }
        if ($query !== '') {
            $like = '%' . $wpdb->esc_like($query) . '%';
            $where .= ' AND (u.display_name LIKE %s OR d.title LIKE %s OR d.description LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT d.*, u.display_name AS student_name
                FROM {$t} d
                INNER JOIN {$wpdb->users} u ON u.ID = d.student_id
                WHERE {$where}
                ORDER BY d.created_at DESC
                LIMIT 300";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);
        $rows = $rows ?: [];

        $items = [];
        foreach ($rows as $r) {
            $student_id = (int) $r->student_id;
            $parent_phone = get_user_meta($student_id, self::PARENT_PHONE_META, true);
            $avatar_id = absint(get_user_meta($student_id, 'hst_profile_avatar_id', true));
            if (!$avatar_id && class_exists('HST_Avatar_Approval')) {
                $avatar_id = (int) HST_Avatar_Approval::display_avatar_id($student_id, $student_id);
            }
            $avatar_url = $avatar_id ? $this->avatar_original_url($avatar_id) : '';
            $student_name = (string) $r->student_name;
            $initial = $this->user_initials($student_id, $student_name);

            $items[] = [
                'id'              => (int) $r->id,
                'student_id'      => $student_id,
                'student_name'    => $student_name,
                'avatar_url'      => $avatar_url,
                'initial'         => $initial ?: '؟',
                'type'            => $r->type,
                'type_label'      => self::TYPES[$r->type] ?? $r->type,
                'severity'        => $r->severity,
                'severity_label'  => self::SEVERITIES[$r->severity] ?? $r->severity,
                'title'           => $r->title,
                'description'     => $r->description,
                'incident_date'   => $this->fa_date($r->incident_date ?: $r->created_at),
                'parent_notified' => (int) $r->parent_notified,
                'sms_enabled'     => (int) ($r->sms_enabled ?? 0),
                'sms_message'     => (string) ($r->sms_message ?? ''),
                'has_parent_phone' => $parent_phone ? 1 : 0,
            ];
        }

        // quick stats
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}");
        $violations = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE type = %s", 'violation'));
        $warnings = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE type = %s", 'warning'));
        $praises = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE type = %s", 'praise'));
        $absences = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE type = %s", 'absence'));
        $lates = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE type = %s", 'late'));
        $notified = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE parent_notified = 1");

        wp_send_json_success([
            'items' => $items,
            'stats' => [
                'total'      => $total,
                'violations' => $violations,
                'warnings'   => $warnings,
                'praises'    => $praises,
                'absences'   => $absences,
                'lates'      => $lates,
                'notified'   => $notified,
            ],
        ]);
    }

    /** Create a discipline record. */
    public function ajax_save(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
        global $wpdb;

        $id = HST_Guard::post_int('id');
        $student_id = HST_Guard::post_int('student_id');
        $student_ids_raw = $_POST['student_ids'] ?? '';
        $student_ids = [];

        if (is_array($student_ids_raw)) {
            $student_ids = array_map('absint', wp_unslash($student_ids_raw));
        } else {
            $student_ids = array_map('absint', preg_split('/[،,\s]+/u', (string) wp_unslash($student_ids_raw)));
        }

        if ($student_id) {
            array_unshift($student_ids, $student_id);
        }

        $student_ids = array_values(array_unique(array_filter($student_ids)));
        $type = HST_Guard::post_text('type');
        $severity = HST_Guard::post_text('severity');
        $title = HST_Guard::post_text('title');
        $description = HST_Guard::post_textarea('description');
        $incident_date = HST_Guard::post_text('incident_date');

        if (empty($student_ids)) {
            HST_Guard::fail('حداقل یک دانش‌آموز را انتخاب کنید.');
        }

        foreach ($student_ids as $sid) {
            if (!$sid || !get_user_by('id', $sid)) {
                HST_Guard::fail('یکی از دانش‌آموزهای انتخاب‌شده معتبر نیست.');
            }
        }

        $student_id = (int) $student_ids[0];
        if ($title === '') {
            HST_Guard::fail('عنوان مورد را وارد کنید.');
        }
        if (!isset(self::TYPES[$type])) {
            $type = 'violation';
        }
        if (!isset(self::SEVERITIES[$severity])) {
            $severity = 'medium';
        }

        // Convert a Jalali date (Y/m/d) to Gregorian for storage, if provided.
        $greg_date = null;
        if ($incident_date !== '' && class_exists('HST_Date') && method_exists('HST_Date', 'to_gregorian_date')) {
            $greg_date = HST_Date::to_gregorian_date($incident_date) ?: null;
        }

        $term_id = class_exists('HST_Terms') ? (int) HST_Terms::active_id() : 0;
        if (!$id && !$term_id) {
            HST_Guard::fail('برای ثبت مورد انضباطی ابتدا یک سال تحصیلی فعال تعریف کنید.');
        }
        if ($id && !$term_id) {
            $term_id = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT term_id FROM {$this->table()} WHERE id = %d LIMIT 1", $id)
            );
        }

        $data = [
            'student_id'     => $student_id,
            'term_id'        => $term_id,
            'type'           => $type,
            'severity'       => $severity,
            'title'          => mb_substr($title, 0, 160),
            'description'    => $description,
            'incident_date'  => $greg_date,
            'recorded_by'    => get_current_user_id(),
        ];
        $formats = ['%d','%d','%s','%s','%s','%s','%s','%d'];

        if ($id) {
            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table()} WHERE id = %d LIMIT 1", $id));
            if (!$exists) {
                HST_Guard::fail('مورد برای ویرایش پیدا نشد.');
            }

            $updated = $wpdb->update($this->table(), $data, ['id' => $id], $formats, ['%d']);
            if ($updated === false) {
                HST_Guard::fail('بروزرسانی مورد ناموفق بود.');
            }

            wp_send_json_success(['message' => 'مورد انضباطی بروزرسانی شد.', 'id' => $id]);
        }

        $data['created_at'] = current_time('mysql');
        $formats[] = '%s';

        $inserted = 0;
        $first_insert_id = 0;

        foreach ($student_ids as $sid) {
            $row_data = $data;
            $row_data['student_id'] = (int) $sid;
            $wpdb->insert($this->table(), $row_data, $formats);

            if ($wpdb->insert_id) {
                $inserted++;
                if (!$first_insert_id) {
                    $first_insert_id = (int) $wpdb->insert_id;
                }
            }
        }

        if (!$inserted) {
            HST_Guard::fail('ثبت مورد ناموفق بود.');
        }

        wp_send_json_success([
            'message' => $inserted > 1 ? sprintf('مورد انضباطی برای %d دانش‌آموز ثبت شد.', $inserted) : 'مورد انضباطی ثبت شد.',
            'id'      => $first_insert_id,
            'count'   => $inserted,
        ]);
    }


    /** Build and return the printable discipline book for all students. */
    public function ajax_print_book(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');

        $student_id = HST_Guard::post_int('student_id');
        $blocks = $this->discipline_book_blocks($student_id);
        if (empty($blocks)) {
            HST_Guard::fail('دانش‌آموزی برای چاپ دفتر انضباطی پیدا نشد.');
        }

        $single_name = $student_id && !empty($blocks[0]['student_name']) ? (string) $blocks[0]['student_name'] : '';
        $title = $single_name !== '' ? 'دفتر انضباطی ' . $single_name : 'دفتر انضباطی دانش‌آموزان';

        wp_send_json_success([
            'title'    => $title,
            'filename' => $single_name !== '' ? 'دفتر-انضباطی-' . sanitize_file_name($single_name) . '.pdf' : 'دفتر-انضباطی-دانش‌آموزان.pdf',
            'blocks'   => $blocks,
            'html'     => $this->discipline_book_html($blocks),
        ]);
    }

    private function discipline_book_blocks(int $only_student_id = 0): array
    {
        $student_query = [
            'role'    => 'student',
            'number'  => -1,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => ['ID', 'display_name', 'user_login'],
        ];

        if ($only_student_id > 0) {
            $student_query['include'] = [$only_student_id];
        }

        $students = get_users($student_query);

        if (empty($students)) {
            return [];
        }

        global $wpdb;
        $active_term_id = class_exists('HST_Terms') ? (int) HST_Terms::active_id() : 0;
        $active_term_name = '';
        if ($active_term_id) {
            $active_term_name = (string) $wpdb->get_var(
                $wpdb->prepare("SELECT term_name FROM {$wpdb->prefix}hst_terms WHERE id = %d", $active_term_id)
            );
        }

        $student_class_map = [];
        if ($active_term_id) {
            $class_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT uc.user_id, c.class_name
                 FROM {$wpdb->prefix}hst_users_classes uc
                 INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = uc.class_id
                 WHERE uc.role = 'student' AND uc.term_id = %d
                 ORDER BY c.class_name ASC",
                $active_term_id
            )) ?: [];

            foreach ($class_rows as $class_row) {
                $sid = (int) $class_row->user_id;
                if (!isset($student_class_map[$sid])) {
                    $student_class_map[$sid] = [];
                }
                $student_class_map[$sid][] = (string) $class_row->class_name;
            }

            foreach ($student_class_map as $student_id => $class_names) {
                $student_class_map[$student_id] = HST_Classes::sort_names($class_names);
            }
        }

        // The complete discipline book belongs to the active academic term and
        // must include its students even when none of them has a discipline
        // record yet. Keep single-student historical print links compatible.
        if ($only_student_id === 0) {
            if (!$active_term_id || empty($student_class_map)) {
                return [];
            }

            $students = array_values(array_filter(
                $students,
                static function ($student) use ($student_class_map): bool {
                    return isset($student_class_map[(int) $student->ID]);
                }
            ));

            if (empty($students)) {
                return [];
            }
        }

        $normalize_sort = static function (string $value): string {
            $value = str_replace(['ي', 'ك', 'ۀ', 'ة'], ['ی', 'ک', 'ه', 'ه'], $value);
            $value = preg_replace('/[\x{200c}\x{200f}\x{200e}]/u', '', $value);
            $value = preg_replace('/\s+/u', ' ', $value);
            return trim((string) $value);
        };

        usort($students, static function ($a, $b) use ($student_class_map, $normalize_sort): int {
            $a_id = (int) $a->ID;
            $b_id = (int) $b->ID;

            $a_class = $normalize_sort((string) (($student_class_map[$a_id][0] ?? '')));
            $b_class = $normalize_sort((string) (($student_class_map[$b_id][0] ?? '')));

            $class_cmp = HST_Classes::compare_names($a_class, $b_class);
            if ($class_cmp !== 0) {
                return $class_cmp;
            }

            $a_last = $normalize_sort((string) get_user_meta($a_id, 'last_name', true));
            $b_last = $normalize_sort((string) get_user_meta($b_id, 'last_name', true));
            $last_cmp = strnatcmp($a_last, $b_last);
            if ($last_cmp !== 0) {
                return $last_cmp;
            }

            $a_first = $normalize_sort((string) get_user_meta($a_id, 'first_name', true));
            $b_first = $normalize_sort((string) get_user_meta($b_id, 'first_name', true));
            $first_cmp = strnatcmp($a_first, $b_first);
            if ($first_cmp !== 0) {
                return $first_cmp;
            }

            return strnatcmp($normalize_sort((string) $a->display_name), $normalize_sort((string) $b->display_name));
        });

        $records_by_student = $this->discipline_records_by_student();
        $blocks = [];

        foreach ($students as $student) {
            $student_id = (int) $student->ID;
            $first = trim((string) get_user_meta($student_id, 'first_name', true));
            $last = trim((string) get_user_meta($student_id, 'last_name', true));
            $name = trim($first . ' ' . $last);
            if ($name === '') {
                $name = (string) $student->display_name;
            }

            $phone = class_exists('HST_SMS') ? HST_SMS::user_phone($student_id) : '';

            $father_phone = (string) get_user_meta($student_id, 'hst_father_phone', true);
            if ($father_phone === '') {
                $father_phone = (string) get_user_meta($student_id, 'hst_parent_phone', true);
            }
            $mother_phone = (string) get_user_meta($student_id, 'hst_mother_phone', true);

            $class_names = $student_class_map[$student_id] ?? [];

            $avatar_id = absint(get_user_meta($student_id, 'hst_profile_avatar_id', true));
            if (!$avatar_id && class_exists('HST_Avatar_Approval')) {
                $avatar_id = (int) HST_Avatar_Approval::display_avatar_id($student_id, $student_id);
            }
            $avatar_url = $avatar_id ? $this->avatar_original_url($avatar_id) : '';

            $records = $records_by_student[$student_id] ?? [];
            $summary = $this->discipline_student_summary($student_id, $records);

            $blocks[] = [
                'student_id'     => $student_id,
                'student_name'   => $name,
                'initial'        => $this->user_initials($student_id, $name),
                'avatar_url'     => $avatar_url,
                'download_url'   => $this->public_sheet_url($student_id),
                'qr_url'         => $this->qr_image_url($this->public_sheet_url($student_id), 260),
                'term_name'      => $active_term_name,
                'academic_year'  => $active_term_name !== '' ? 'سال تحصیلی ' . $active_term_name : '',
                'national_code'  => (string) (get_user_meta($student_id, 'hst_student_code', true) ?: get_user_meta($student_id, 'hst_national_code', true)),
                'father_name'    => (string) get_user_meta($student_id, 'hst_father_name', true),
                'phone'          => $phone,
                'father_phone'   => $father_phone,
                'mother_phone'   => $mother_phone,
                'birthdate'      => (string) get_user_meta($student_id, 'hst_birthdate', true),
                'classes'        => implode('، ', array_filter(array_map('strval', $class_names))),
                'records'        => $records,
                'summary'        => $summary,
                'manager_name'   => $this->school_manager_name(),
            ];
        }

        return $blocks;
    }

    private function discipline_records_by_student(): array
    {
        global $wpdb;
        $table = $this->table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        $rows = $wpdb->get_results(
            "SELECT *
             FROM {$table}
             ORDER BY incident_date DESC, created_at DESC, id DESC"
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $sid = (int) $row->student_id;
            if (!isset($out[$sid])) {
                $out[$sid] = [];
            }

            $out[$sid][] = [
                'date'         => $this->fa_date($row->incident_date ?: $row->created_at),
                'type'         => self::TYPES[$row->type] ?? (string) $row->type,
                'type_key'     => (string) $row->type,
                'severity'     => self::SEVERITIES[$row->severity] ?? (string) $row->severity,
                'severity_key' => (string) $row->severity,
                'title'        => (string) $row->title,
                'description'  => (string) $row->description,
            ];
        }

        return $out;
    }



    private function discipline_student_summary(int $student_id, array $records = []): array
    {
        unset($student_id);

        $counts = array_fill_keys(array_keys(self::TYPES), 0);
        foreach ($records as $record) {
            $type_key = sanitize_key((string) ($record['type_key'] ?? ''));
            if (array_key_exists($type_key, $counts)) {
                $counts[$type_key]++;
            }
        }

        $effects = self::calculation_settings();
        $discipline_average = 20.0;
        $attendance_percentage = 100.0;

        foreach ($counts as $type => $count) {
            $row = $effects[$type] ?? ['conduct' => 0.0, 'attendance' => 0.0];
            $discipline_average += $count * (float) ($row['conduct'] ?? 0.0);
            if (in_array($type, ['absence', 'late'], true)) {
                $attendance_percentage += $count * (float) ($row['attendance'] ?? 0.0);
            }
        }

        $discipline_average = round(self::clamp_float($discipline_average, 0.0, 20.0), 2);
        $attendance_percentage = round(self::clamp_float($attendance_percentage, 0.0, 100.0), 1);

        if ($attendance_percentage >= 95) {
            $attendance_label = 'بسیار منظم';
            $attendance_tone = 'success';
        } elseif ($attendance_percentage >= 90) {
            $attendance_label = 'منظم';
            $attendance_tone = 'success';
        } elseif ($attendance_percentage >= 80) {
            $attendance_label = 'قابل قبول';
            $attendance_tone = 'warning';
        } elseif ($attendance_percentage >= 70) {
            $attendance_label = 'نیازمند توجه';
            $attendance_tone = 'warning';
        } else {
            $attendance_label = 'نامنظم';
            $attendance_tone = 'danger';
        }

        $items = [
            ['key' => 'violation', 'label' => 'تخلف', 'count' => (int) $counts['violation'], 'color' => '#ef4444'],
            ['key' => 'warning', 'label' => 'اخطار', 'count' => (int) $counts['warning'], 'color' => '#f59e0b'],
            ['key' => 'praise', 'label' => 'تشویق', 'count' => (int) $counts['praise'], 'color' => '#10b981'],
            ['key' => 'absence', 'label' => 'غیبت', 'count' => (int) $counts['absence'], 'color' => '#64748b'],
            ['key' => 'late', 'label' => 'تأخیر', 'count' => (int) $counts['late'], 'color' => '#3b82f6'],
        ];

        return [
            'items'                    => $items,
            'total'                    => (int) array_sum($counts),
            'discipline_total'         => (int) ($counts['violation'] + $counts['warning'] + $counts['praise']),
            'discipline_average'       => $discipline_average,
            'attendance_percentage'    => $attendance_percentage,
            'attendance_label'         => $attendance_label,
            'attendance_tone'          => $attendance_tone,
            'violation_count'          => (int) $counts['violation'],
            'warning_count'            => (int) $counts['warning'],
            'praise_count'             => (int) $counts['praise'],
            'absence_count'            => (int) $counts['absence'],
            'late_count'               => (int) $counts['late'],
            'calculation_settings'     => $effects,
        ];
    }

    private function discipline_book_html(array $blocks, bool $public_view = false): string
    {
        $placeholder = '................';
        $display_value = static function ($value) use ($placeholder): string {
            $value = trim((string) ($value ?? ''));
            return $value !== '' ? $value : $placeholder;
        };
        $accent = class_exists('HST_Settings') ? HST_Settings::fixed_accent_color() : '#334155';
        $manager_name = $this->school_manager_name();

        if (!function_exists('hst_icon') && defined('HST_PATH')) {
            include_once HST_PATH . 'templates/user/common/hst-icons.php';
        }

        $build_chart_style = static function (array $items, int $total): string {
            if ($total <= 0) {
                return 'background:conic-gradient(#e2e8f0 0deg 360deg)';
            }

            $parts = [];
            $cursor = 0.0;
            foreach ($items as $item) {
                $count = max(0, (int) ($item['count'] ?? 0));
                if ($count <= 0) {
                    continue;
                }

                $color = sanitize_hex_color((string) ($item['color'] ?? '')) ?: '#cbd5e1';
                $start = round(($cursor / $total) * 360, 2);
                $cursor += $count;
                $end = round(($cursor / $total) * 360, 2);
                $parts[] = $color . ' ' . $start . 'deg ' . $end . 'deg';
            }

            if (empty($parts)) {
                $parts[] = '#e2e8f0 0deg 360deg';
            }

            return 'background:conic-gradient(' . implode(', ', $parts) . ')';
        };

        $css = '<style>
            :root{--hst-print-accent:' . esc_html($accent) . ';--hst-print-ink:#172033;--hst-print-subtle:#475569;--hst-print-muted:#64748b;--hst-print-faint:#94a3b8;--hst-print-border:#cbd5e1;--hst-print-border-soft:#e2e8f0;--hst-print-soft:#f8fafc;--hst-print-surface:#fff;--hst-print-success:#10b981;--hst-print-danger-bg:#fef2f2;--hst-print-danger-border:#fecaca;--hst-print-warning-bg:#fffbeb;--hst-print-warning-border:#fde68a;--hst-print-success-bg:#ecfdf5;--hst-print-success-border:#bbf7d0}
            @page{size:A4 portrait;margin:8mm}
            *{box-sizing:border-box}
            body{margin:0;font-family:Tahoma,Arial,sans-serif;direction:rtl;color:var(--hst-print-ink);background:var(--hst-print-surface)}
            .disc-page{page-break-after:always;display:grid;grid-template-rows:1fr 1fr;gap:8mm;min-height:280mm}
            .disc-card{border:1px solid var(--hst-print-border);border-radius:14px;padding:10px;display:flex;flex-direction:column;gap:8px;overflow:hidden}
            .disc-head{display:grid;grid-template-columns:72px 1fr 178px;gap:10px;align-items:center;border-bottom:2px solid var(--hst-print-accent);padding-bottom:8px;direction:ltr}
            .disc-head>*{direction:rtl}
            .disc-qrbox{display:grid;justify-items:center;gap:3px;font-size:8px;color:var(--hst-print-muted);text-align:center}
            .disc-qrbox img{width:58px;height:58px;display:block;border:1px solid var(--hst-print-border);border-radius:6px;padding:3px;background:var(--hst-print-surface)}
            .disc-school{text-align:center;display:grid;gap:3px;justify-items:center}
            .disc-school-logo{width:26px;height:26px;border-radius:8px;object-fit:contain}
            .disc-school-title{font-weight:900;font-size:15px;color:var(--hst-print-accent)}
            .disc-school-subtitle{font-weight:700;font-size:10px;color:var(--hst-print-subtle)}
            .disc-school-year{font-weight:700;font-size:8px;color:var(--hst-print-muted)}
            .disc-student{display:grid;grid-template-columns:42px minmax(0,1fr);gap:8px;align-items:center}
            .disc-avatar{width:42px;height:56px;border-radius:9px;border:1px solid var(--hst-print-border);object-fit:contain;background:var(--hst-print-surface);display:grid;place-items:center;color:var(--hst-print-muted);font-weight:800;font-size:18px;box-sizing:border-box;padding:2px;overflow:hidden;clip-path:inset(0 round 9px)}
            .disc-name{font-weight:900;font-size:12px;color:var(--hst-print-ink);margin-bottom:2px}
            .disc-student-lines{display:grid;gap:2px;font-size:8.5px;color:var(--hst-print-muted)}
            .disc-public-actions{position:sticky;top:0;z-index:5;display:flex;gap:8px;justify-content:center;padding:10px;background:var(--hst-print-soft);border-bottom:1px solid var(--hst-print-border-soft)}
            .disc-public-actions button{border:0;border-radius:10px;padding:8px 14px;font-family:inherit;font-weight:700;cursor:pointer}
            .disc-public-actions .print{background:var(--hst-print-success);color:var(--hst-print-surface)}
            .disc-public-actions .close{background:var(--hst-print-border-soft);color:var(--hst-print-accent)}
            .disc-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:4px;font-size:10px;color:var(--hst-print-subtle)}
            .disc-meta span{border:1px solid var(--hst-print-border);border-radius:8px;padding:4px;background:var(--hst-print-soft);display:flex;align-items:center;gap:4px;direction:rtl;text-align:right}
            .disc-meta b{font-weight:700;white-space:nowrap}
            .disc-metrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
            .disc-metric{min-height:50px;border:1px solid var(--hst-print-border);border-radius:12px;padding:7px 9px;display:grid;grid-template-columns:1fr 24px;gap:8px;align-items:center;background:var(--hst-print-soft)}
            .disc-metric-copy{display:grid;gap:3px;text-align:right}
            .disc-metric-copy span{font-size:8px;color:var(--hst-print-muted);font-weight:700}
            .disc-metric-copy strong{font-size:11px;color:var(--hst-print-accent);font-weight:900}
            .disc-metric-copy small{font-size:8px;font-weight:700}
            .ico{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
            .disc-metric--attendance .ico{color:#10b981}
            .disc-metric--attendance.is-warning .ico{color:#d97706}
            .disc-metric--attendance.is-danger .ico{color:#dc2626}
            .disc-metric--attendance.is-muted .ico{color:#64748b}
            .disc-metric--score .ico{color:var(--hst-print-accent)}
            .disc-summary{display:grid;grid-template-columns:1fr 116px;gap:8px;align-items:center;border:1px solid var(--hst-print-border);border-radius:12px;padding:7px;background:linear-gradient(135deg,#fbfdff 0%,#f8fafc 100%)}
            .disc-summary-chart-wrap{display:grid;justify-items:center;gap:5px}
            .disc-summary-chart{width:72px;height:72px;border-radius:50%;position:relative;display:grid;place-items:center}
            .disc-summary-chart::before{content:"";position:absolute;inset:14px;background:var(--hst-print-surface);border-radius:50%;box-shadow:inset 0 0 0 1px var(--hst-print-border)}
            .disc-summary-chart strong{position:relative;z-index:1;font-size:13px;color:var(--hst-print-accent)}
            .disc-summary-chart small{position:relative;z-index:1;font-size:8px;color:var(--hst-print-muted);margin-top:18px}
            .disc-summary-title{font-size:8px;font-weight:700;color:var(--hst-print-muted)}
            .disc-summary-legend{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:4px}
            .disc-legend-item{display:grid;grid-template-columns:10px 1fr auto;align-items:center;gap:4px;border:1px solid var(--hst-print-border);border-radius:8px;padding:4px 5px;background:var(--hst-print-surface);font-size:8px;color:var(--hst-print-subtle)}
            .disc-legend-dot{width:10px;height:10px;border-radius:50%;background:var(--disc-item-color,#cbd5e1)}
            .disc-legend-value{font-weight:800;color:var(--hst-print-accent)}
            table{width:100%;border-collapse:separate;border-spacing:0 3px;font-size:8.7px;table-layout:fixed}
            th,td{border:1px solid var(--hst-print-border);padding:4px;text-align:center;vertical-align:middle;word-break:break-word}
            th{height:28px;padding:0 4px;background:var(--hst-print-accent);color:var(--hst-print-surface);font-weight:700;line-height:1;vertical-align:middle}
            .disc-col-index{width:7%}.disc-col-date{width:14%}.disc-col-type,.disc-col-severity{width:12%}.disc-col-title{width:20%}
            tr.disc-row--violation td{background:var(--hst-print-danger-bg)}
            tr.disc-row--warning td{background:var(--hst-print-warning-bg)}
            tr.disc-row--praise td{background:var(--hst-print-success-bg)}
            tr.disc-row--absence td{background:#f8fafc}
            tr.disc-row--late td{background:#eff6ff}
            .desc{text-align:right}
            .disc-footer{margin-top:8px;padding-top:7px;border-top:1px solid var(--hst-print-border);display:grid;grid-template-columns:1fr 158px;gap:10px;align-items:end}
            .disc-footer-note{display:grid;gap:4px;font-size:8px;color:var(--hst-print-muted)}
            .disc-manager-area{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
            .disc-manager-box{border:1px solid var(--hst-print-border);border-radius:10px;min-height:56px;padding:6px;display:grid;place-items:center;text-align:center;background:var(--hst-print-surface)}
            .disc-manager-box span{font-size:8px;font-weight:700;color:var(--hst-print-muted)}
            .disc-manager-box strong{font-size:8px;color:var(--hst-print-accent)}
            .empty{color:var(--hst-print-faint)}
            @media print{
                :root{--hst-print-accent:#27313b;--hst-print-ink:#111418;--hst-print-subtle:#252a31;--hst-print-muted:#4b5560;--hst-print-faint:#70767c;--hst-print-border:#8f979e;--hst-print-border-soft:#b7bdc3;--hst-print-soft:#f3f3f3;--hst-print-surface:#fff;--hst-print-danger-bg:#e2e2e2;--hst-print-warning-bg:#ededed;--hst-print-success-bg:#f7f7f7}
                *{text-shadow:none!important;box-shadow:none!important}
                .disc-public-actions{display:none!important}
                .disc-card,.disc-meta span,.disc-metric,.disc-summary,.disc-legend-item,.disc-manager-box,td{background:#fff!important;color:#111418!important;border-color:#8f979e!important}
                th{background:#e2e5e8!important;color:#111418!important;border-color:#777d84!important}
                .disc-metric--attendance .ico,.disc-metric--attendance.is-warning .ico,.disc-metric--attendance.is-danger .ico,.disc-metric--attendance.is-muted .ico,.disc-metric--score .ico{color:#20252a!important}
                .disc-summary{background:#f3f3f3!important}
                .disc-page:last-child{page-break-after:auto}
            }
        </style>';

        $cards = [];
        foreach ($blocks as $block) {
            $records = array_slice((array) ($block['records'] ?? []), 0, 6);
            $blank_count = max(2, 6 - count($records));
            $rows = '';

            $i = 1;
            foreach ($records as $record) {
                $type_key = preg_replace('/[^a-z_\-]/i', '', (string) ($record['type_key'] ?? ''));
                $row_class = in_array($type_key, ['violation', 'warning', 'praise', 'absence', 'late'], true) ? ' class="disc-row--' . esc_attr($type_key) . '"' : '';
                $rows .= '<tr' . $row_class . '><td>' . esc_html($i++) . '</td><td>' . esc_html($record['date'] ?? '') . '</td><td>' . esc_html($record['type'] ?? '') . '</td><td>' . esc_html($record['severity'] ?? '') . '</td><td>' . esc_html($record['title'] ?? '') . '</td><td class="desc">' . esc_html($record['description'] ?? '') . '</td></tr>';
            }
            for ($b = 0; $b < $blank_count; $b++) {
                $rows .= '<tr><td>' . esc_html($i++) . '</td><td></td><td></td><td></td><td></td><td></td></tr>';
            }

            $summary = (array) ($block['summary'] ?? []);
            $items = array_values(array_filter((array) ($summary['items'] ?? []), static fn($item): bool => is_array($item)));
            $summary_total = (int) ($summary['total'] ?? 0);
            $legend_html = '';
            foreach ($items as $item) {
                $color = sanitize_hex_color((string) ($item['color'] ?? '')) ?: '#cbd5e1';
                $legend_html .= '<div class="disc-legend-item" style="--disc-item-color:' . esc_attr($color) . ';"><span class="disc-legend-dot"></span><span>' . esc_html((string) ($item['label'] ?? '')) . '</span><span class="disc-legend-value">' . esc_html(number_format_i18n((int) ($item['count'] ?? 0))) . '</span></div>';
            }

            $attendance_percentage = $summary['attendance_percentage'] ?? null;
            $attendance_label = (string) ($summary['attendance_label'] ?? 'بدون داده');
            $attendance_tone = sanitize_key((string) ($summary['attendance_tone'] ?? 'muted'));
            $attendance_value = esc_html($attendance_label);
            if ($attendance_percentage !== null) {
                $attendance_percentage_text = number_format_i18n(
                    (float) $attendance_percentage,
                    ((float) $attendance_percentage === floor((float) $attendance_percentage)) ? 0 : 1
                );
                $attendance_value .= ' <bdi dir="ltr">' . esc_html($attendance_percentage_text) . '٪</bdi>';
            }
            $discipline_average = $summary['discipline_average'] ?? null;
            $discipline_average_value = $discipline_average === null
                ? 'ثبت نشده'
                : hst_format_grade($discipline_average);

            $attendance_icon = function_exists('hst_icon') ? hst_icon('attendance') : '';
            $score_icon = function_exists('hst_icon') ? hst_icon('scores') : '';

            $avatar = !empty($block['avatar_url'])
                ? '<img class="disc-avatar" src="' . esc_url($block['avatar_url']) . '" alt="">'
                : '<div class="disc-avatar">' . esc_html($block['initial'] ?: '؟') . '</div>';
            $qr = !empty($block['qr_url'])
                ? '<img src="' . esc_url($block['qr_url']) . '" alt="QR">'
                : '';
            $school_logo = '';
            if (class_exists('HST_Settings')) {
                $logo_id = (int) HST_Settings::option('hst-home-logo-id', 0);
                $school_logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'thumbnail') : '';
                if ($school_logo_url) {
                    $school_logo = '<img class="disc-school-logo" src="' . esc_url($school_logo_url) . '" alt="">';
                }
            }

            $cards[] = '<section class="disc-card">
                <div class="disc-head">
                    <div class="disc-qrbox"><a href="' . esc_url($block['download_url'] ?? '') . '">' . $qr . '</a><span>دریافت نسخه دیجیتال</span></div>
                    <div class="disc-school">' . $school_logo . '<div class="disc-school-title">' . esc_html(get_bloginfo('name')) . '</div><div class="disc-school-subtitle">دفتر انضباطی و کارنامه انضباطی</div><div class="disc-school-subtitle">خلاصه موارد انضباطی، غیبت و تأخیر</div><div class="disc-school-year">' . esc_html($block['academic_year'] ?? '') . '</div></div>
                    <div class="disc-student">' . $avatar . '<div><div class="disc-name">' . esc_html($block['student_name'] ?? '') . '</div><div class="disc-student-lines"><span>کد ملی: ' . esc_html($display_value($block['national_code'] ?? '')) . '</span><span>کلاس: ' . esc_html($display_value($block['classes'] ?? '')) . '</span></div></div></div>
                </div>
                <div class="disc-meta">
                    <span><b>نام پدر</b><em>' . esc_html($display_value($block['father_name'] ?? '')) . '</em></span>
                    <span><b>موبایل دانش‌آموز</b><em>' . esc_html($display_value($block['phone'] ?? '')) . '</em></span>
                    <span><b>تاریخ تولد</b><em>' . esc_html($display_value($block['birthdate'] ?? '')) . '</em></span>
                    <span><b>موبایل پدر</b><em>' . esc_html($display_value($block['father_phone'] ?? '')) . '</em></span>
                    <span><b>موبایل مادر</b><em>' . esc_html($display_value($block['mother_phone'] ?? '')) . '</em></span>
                    <span><b>سال تحصیلی</b><em>' . esc_html($display_value($block['term_name'] ?? '')) . '</em></span>
                </div>
                <div class="disc-metrics">
                    <div class="disc-metric disc-metric--attendance is-' . esc_attr($attendance_tone) . '"><div class="disc-metric-copy"><span>وضعیت حضور و غیاب</span><strong>' . $attendance_value . '</strong></div>' . $attendance_icon . '</div>
                    <div class="disc-metric disc-metric--score"><div class="disc-metric-copy"><span>معدل کل انضباط</span><strong>' . esc_html($discipline_average_value) . '</strong></div>' . $score_icon . '</div>
                </div>
                <div class="disc-summary">
                    <div class="disc-summary-chart-wrap">
                        <div class="disc-summary-chart" style="' . esc_attr($build_chart_style($items, $summary_total)) . '"><strong>' . esc_html(number_format_i18n($summary_total)) . '</strong><small>جمع ثبت‌ها</small></div>
                        <div class="disc-summary-title">نمودار خلاصه موارد انضباطی، غیبت و تأخیر</div>
                    </div>
                    <div class="disc-summary-legend">' . $legend_html . '</div>
                </div>
                <table><thead><tr><th class="disc-col-index">ردیف</th><th class="disc-col-date">تاریخ</th><th class="disc-col-type">نوع</th><th class="disc-col-severity">شدت</th><th class="disc-col-title">عنوان</th><th>توضیحات</th></tr></thead><tbody>' . $rows . '</tbody></table>
                <div class="disc-footer">
                    <div class="disc-footer-note">
                        <span>غیبت ثبت‌شده: ' . esc_html(number_format_i18n((int) ($summary['absence_count'] ?? 0))) . ' | تأخیر ثبت‌شده: ' . esc_html(number_format_i18n((int) ($summary['late_count'] ?? 0))) . '</span>
                        <span>این بخش برای تأیید مدیر مدرسه و استفاده به عنوان کارنامه انضباطی آماده شده است.</span>
                    </div>
                    <div class="disc-manager-area">
                        <div class="disc-manager-box"><span>مهر مدرسه</span></div>
                        <div class="disc-manager-box"><span>امضای مدیر</span><strong>' . esc_html($block['manager_name'] ?? $manager_name) . '</strong></div>
                    </div>
                </div>
            </section>';
        }

        $pages = '';
        for ($i = 0; $i < count($cards); $i += 2) {
            $pages .= '<div class="disc-page">' . $cards[$i] . ($cards[$i + 1] ?? '<section class="disc-card"></section>') . '</div>';
        }

        $actions = $public_view ? '<div class="disc-public-actions"><button class="print" onclick="window.print()">چاپ / ذخیره PDF</button><button class="close" onclick="window.close()">بستن</button></div>' : '';
        return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>دفتر انضباطی دانش‌آموزان</title>' . $css . '</head><body>' . $actions . $pages . '</body></html>';
    }

    public function ajax_delete(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
        global $wpdb;
        $id = HST_Guard::post_int('id');
        if (!$id) {
            HST_Guard::fail('شناسه نامعتبر است.');
        }
        $wpdb->delete($this->table(), ['id' => $id], ['%d']);
        wp_send_json_success(['message' => 'مورد حذف شد.']);
    }


    private function parent_phone_for_student(int $student_id): string
    {
        $parent_phone = get_user_meta($student_id, self::PARENT_PHONE_META, true);
        if (!$parent_phone) {
            $parent_phone = class_exists('HST_SMS') ? HST_SMS::user_phone($student_id) : '';
        }

        $normalized = HST_Guard::normalize_mobile($parent_phone);
        return HST_Guard::is_valid_iran_mobile($normalized) ? $normalized : '';
    }

    private function discipline_sms_template($value = ''): string
    {
        return class_exists('HST_SMS')
            ? HST_SMS::message_template($value, 'discipline')
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


    private function discipline_sms_context($row): array
    {
        $student_name = get_the_author_meta('display_name', (int) $row->student_id);
        if ($student_name === '') {
            $student_name = 'دانش‌آموز';
        }
        $school = class_exists('HST_Settings')
            ? (string) HST_Settings::option('hst-home-school-name', get_bloginfo('name'))
            : (string) get_bloginfo('name');
        $school = trim($school) !== '' ? $school : 'مدرسه';

        return [
            'name'          => $student_name,
            'school'        => $school,
            'date'          => class_exists('HST_Date') ? HST_Date::today('Y/m/d') : date_i18n('Y/m/d'),
            'title'         => wp_strip_all_tags((string) ($row->title ?? 'مورد انضباطی')),
            'type'          => self::TYPES[$row->type] ?? (string) $row->type,
            'severity'      => self::SEVERITIES[$row->severity] ?? (string) $row->severity,
            'description'   => wp_strip_all_tags((string) ($row->description ?? '')),
            'incident_date' => $this->fa_date($row->incident_date ?: ($row->created_at ?? current_time('mysql'))),
        ];
    }

    private function send_discipline_sms_once(int $id)
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id));
        if (!$row || empty($row->sms_enabled) || !empty($row->parent_notified)) {
            return null;
        }

        if (!class_exists('HST_SMS') || !HST_SMS::direct_ready()) {
            return [
                'sent' => 0,
                'failed' => 1,
                'skipped' => 0,
                'errors' => ['ارسال مستقیم پیامک فعال یا پیکربندی نشده است.'],
                'not_sent' => true,
            ];
        }

        $phone = $this->parent_phone_for_student((int) $row->student_id);
        if (!$phone) {
            return [
                'sent' => 0,
                'failed' => 0,
                'skipped' => 1,
                'errors' => ['شمارهٔ معتبری برای اولیای این دانش‌آموز ثبت نشده است.'],
                'not_sent' => true,
            ];
        }

        $result = [
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
        $context = $this->discipline_sms_context($row);
        $context['sms_template'] = $this->discipline_sms_template($row->sms_message ?? '');
        $sent = HST_SMS::send_discipline($phone, $context);
        if (is_wp_error($sent)) {
            $result['failed'] = 1;
            $result['errors'][] = $sent->get_error_message();
        } elseif ($sent === true) {
            $result['sent'] = 1;
        } else {
            $result['failed'] = 1;
        }

        $wpdb->update(
            $this->table(),
            [
                'parent_notified' => $result['sent'] > 0 ? 1 : 0,
                'notified_at' => $result['sent'] > 0 ? current_time('mysql') : null,
                'sms_result' => wp_json_encode($result, JSON_UNESCAPED_UNICODE),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%d', '%s', '%s', '%s'],
            ['%d']
        );

        return $result;
    }

    public function ajax_discipline_sms_estimate(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');

        $id = absint(wp_unslash($_POST['id'] ?? 0));
        $template = $this->sanitize_sms_template_input(wp_unslash($_POST['message'] ?? ''));
        if (!$id || $template === '') {
            HST_Guard::fail('مورد انضباطی یا متن پیامک معتبر نیست.');
        }
        if (!class_exists('HST_SMS')) {
            HST_Guard::fail('سرویس محاسبه پیامک در دسترس نیست.');
        }

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id));
        if (!$row) {
            HST_Guard::fail('مورد انضباطی پیدا نشد.');
        }

        $phone = $this->parent_phone_for_student((int) $row->student_id);
        $items = [];
        $skipped = 0;
        if ($phone !== '') {
            $items[] = [
                'phone' => $phone,
                'message' => HST_SMS::render_message($template, $this->discipline_sms_context($row)),
            ];
        } else {
            $skipped = 1;
        }

        $estimate = HST_SMS::estimate_consumption($items, false);
        $estimate['target_count'] = 1;
        $estimate['skipped_count'] = $skipped;
        wp_send_json_success(['estimate' => $estimate]);
    }

    public function ajax_discipline_sms_test(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');

        if (!class_exists('HST_SMS') || !HST_SMS::direct_ready()) {
            HST_Guard::fail('ارسال مستقیم پیامک فعال یا پیکربندی نشده است.');
        }

        global $wpdb;

        $id = HST_Guard::post_int('id');
        $phone = HST_Guard::post_text('phone');
        $message_template = $this->sanitize_sms_template_input(wp_unslash($_POST['message'] ?? ''));
        if (!$id) {
            HST_Guard::fail('شناسه مورد انضباطی نامعتبر است.');
        }

        if ($phone === '') {
            HST_Guard::fail('شماره دریافت‌کننده تست را وارد کنید.');
        }
        if ($message_template === '') {
            HST_Guard::fail('متن پیامک را وارد کنید.');
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id));
        if (!$row) {
            HST_Guard::fail('مورد یافت نشد.');
        }
        $context = $this->discipline_sms_context($row);
        $context['sms_template'] = $message_template;
        $sent = HST_SMS::send_discipline($phone, $context);
        if (is_wp_error($sent)) {
            HST_Guard::fail($sent->get_error_message());
        }

        if ($sent !== true) {
            HST_Guard::fail('ارسال پیامک تست انجام نشد.');
        }

        wp_send_json_success(['message' => 'پیامک تست انضباطی با موفقیت ارسال شد.']);
    }

    public function ajax_update_discipline_sms(): void
    {
        HST_Guard::verify_ajax('hst_manage_school');
        global $wpdb;

        $id = HST_Guard::post_int('id');
        $enabled = HST_Guard::post_int('enabled') ? 1 : 0;
        if (!$id) {
            HST_Guard::fail('شناسه مورد انضباطی نامعتبر است.');
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id));
        if (!$row) {
            HST_Guard::fail('مورد یافت نشد.');
        }

        if (!$enabled) {
            $updated = $wpdb->update(
                $this->table(),
                [
                    'sms_enabled' => 0,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $id],
                ['%d', '%s'],
                ['%d']
            );

            if ($updated === false) {
                HST_Guard::fail('غیرفعال‌سازی پیامک مورد انضباطی انجام نشد.');
            }

            wp_send_json_success([
                'message' => 'پیامک مورد انضباطی غیرفعال شد.',
                'id' => $id,
                'sms_enabled' => 0,
            ]);
        }

        if (!class_exists('HST_SMS') || !HST_SMS::direct_ready()) {
            HST_Guard::fail('ارسال مستقیم پیامک فعال یا پیکربندی نشده است.');
        }
        $message = $this->sanitize_sms_template_input(wp_unslash($_POST['message'] ?? ''));
        if ($message === '') {
            HST_Guard::fail('متن پیامک نمی‌تواند خالی باشد.');
        }

        $updated = $wpdb->update(
            $this->table(),
            [
                'sms_enabled' => 1,
                'sms_message' => $message,
                'updated_at'  => current_time('mysql'),
            ],
            ['id' => $id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            HST_Guard::fail('فعال‌سازی پیامک مورد انضباطی انجام نشد.');
        }

        $sms_result = $this->send_discipline_sms_once($id);
        $response_message = 'پیامک مورد انضباطی فعال شد.';

        if (is_array($sms_result) && empty($sms_result['not_sent'])) {
            $response_message .= sprintf(
                ' نتیجه ارسال: %d ارسال، %d ناموفق، %d بدون شماره.',
                intval($sms_result['sent'] ?? 0),
                intval($sms_result['failed'] ?? 0),
                intval($sms_result['skipped'] ?? 0)
            );
        } elseif (is_array($sms_result) && !empty($sms_result['errors'])) {
            $response_message .= ' ' . implode(' ', array_slice(array_map('sanitize_text_field', $sms_result['errors']), 0, 1));
        }

        wp_send_json_success([
            'message' => $response_message,
            'id' => $id,
            'sms_enabled' => 1,
            'sms_message' => $message,
            'sms_sent' => is_array($sms_result) && intval($sms_result['sent'] ?? 0) > 0 ? 1 : 0,
            'sms' => $sms_result,
        ]);
    }    private function fa_date($value): string
    {
        if (empty($value)) {
            return '';
        }
        if (class_exists('HST_Date')) {
            return HST_Date::format($value, 'Y/m/d', '');
        }
        $ts = strtotime($value);
        return $ts ? date_i18n('Y/m/d', $ts) : (string) $value;
    }
}
