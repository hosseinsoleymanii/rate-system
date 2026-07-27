<?php

defined('ABSPATH') || exit;

class HST_Scripts
{
    private array $frontend_scripts = [
        'hst_classes'          => ['hst-classes-script', 'academic/hst-classes.js'],
        'hst_terms'            => ['hst-terms-script', 'academic/hst-terms.js'],
        'hst_lessons'          => ['hst-lessons-script', 'academic/hst-lessons.js'],
        'hst_teachers'         => ['hst-teachers-script', 'users/hst-teachers.js'],
        'hst_students'         => ['hst-students-script', 'users/hst-students.js'],
        'hst_schedules'        => ['hst-schedules-script', 'schedules/hst-schedules.js'],
        'hst_periods'          => ['hst-periods-script', 'scores/hst-periods.js'],
        'hst_enter_scores'     => ['hst-enter-scores-script', 'scores/hst-enter-scores.js'],
        'hst_gradebook'        => ['hst-gradebook-script', 'scores/hst-gradebook.js'],
        'hst_score_audit'      => ['hst-score-audit-script', 'scores/hst-score-audit.js'],
        'hst_tuition'          => ['hst-tuition-script', 'finance/hst-tuition.js'],
        'hst_tuition_payments' => ['hst-tuition-payments-script', 'finance/hst-tuition.js'],
        'hst_notifications'    => ['hst-notifications-script', 'communication/hst-notifications.js'],
        'hst_import_users'     => ['hst-import-users-script', 'users/hst-import-users.js'],
        'hst_report_cards'     => ['hst-report-cards-script', 'users/hst-report-cards.js'],
        'hst_discipline'       => ['hst-discipline-script', 'tools/hst-discipline.js'],
        'hst_term_transfer'    => ['hst-term-transfer-script', 'academic/hst-term-transfer.js'],
        'hst_backup'           => ['hst-backup-script', 'tools/hst-backup.js'],
        'hst_assignments'      => ['hst-assignments-script', 'communication/hst-assignments.js'],
        'hst_attendance'       => ['hst-attendance-script', 'tools/hst-attendance.js'],
        'hst_exams'            => ['hst-exams-script', 'tools/hst-exams.js'],
    ];

    /**
     * Shortcodes that do not need a dedicated JavaScript file, but must still
     * load the shared frontend stylesheet and core UI scripts.
     */
    private array $frontend_style_only_shortcodes = [
        'hst_home',
        'hst_dashboard',
        'hst_login',
        'hst_scores',
        'hst_profile',
        'hst_my_schedule',
        'hst_my_teachers',
        'hst_plugin_settings',
        'hst_smart_analysis',
    ];

    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }

    private function asset_version(string $relative_path): ?int
    {
        $path = trailingslashit(HST_PATH) . ltrim($relative_path, '/');
        return file_exists($path) ? (int) filemtime($path) : null;
    }

    private function current_post_content(): string
    {
        if (!is_singular()) {
            return '';
        }

        global $post;
        return $post instanceof WP_Post ? (string) $post->post_content : '';
    }

    private function post_has_shortcode(string $shortcode): bool
    {
        $content = $this->current_post_content();
        return $content !== '' && has_shortcode($content, $shortcode);
    }

    private function is_hst_frontend_page(): bool
    {
        foreach (array_keys($this->frontend_scripts) as $shortcode) {
            if ($this->post_has_shortcode($shortcode)) {
                return true;
            }
        }

        foreach ($this->frontend_style_only_shortcodes as $shortcode) {
            if ($this->post_has_shortcode($shortcode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Static Aparat videos for the shared page-help modal.
     *
     * The video hash is intentionally centralised here so the temporary
     * placeholder can later be replaced per screen without touching the
     * templates or the modal JavaScript.
     */
    private function page_help_video_map(): array
    {
        $temporary_hash = '8Jdh5';

        return [
            'hst_home'             => ['title' => 'راهنمای صفحه اصلی', 'hash' => $temporary_hash],
            'hst_dashboard'        => ['title' => 'راهنمای داشبورد', 'hash' => $temporary_hash],
            'hst_login'            => ['title' => 'راهنمای ورود', 'hash' => $temporary_hash],
            'hst_classes'          => ['title' => 'راهنمای کلاس‌ها', 'hash' => $temporary_hash],
            'hst_lessons'          => ['title' => 'راهنمای درس‌ها', 'hash' => $temporary_hash],
            'hst_terms'            => ['title' => 'راهنمای سال‌های تحصیلی', 'hash' => $temporary_hash],
            'hst_teachers'         => ['title' => 'راهنمای معلمان', 'hash' => $temporary_hash],
            'hst_students'         => ['title' => 'راهنمای دانش‌آموزان', 'hash' => $temporary_hash],
            'hst_schedules'        => ['title' => 'راهنمای برنامه هفتگی', 'hash' => $temporary_hash],
            'hst_my_schedule'      => ['title' => 'راهنمای برنامه هفتگی من', 'hash' => $temporary_hash],
            'hst_profile'          => ['title' => 'راهنمای پروفایل', 'hash' => $temporary_hash],
            'hst_periods'          => ['title' => 'راهنمای دوره‌های نمره‌دهی', 'hash' => $temporary_hash],
            'hst_enter_scores'     => ['title' => 'راهنمای ثبت نمرات', 'hash' => $temporary_hash],
            'hst_gradebook'        => ['title' => 'راهنمای دفتر نمره', 'hash' => $temporary_hash],
            'hst_scores'           => ['title' => 'راهنمای نمرات', 'hash' => $temporary_hash],
            'hst_score_audit'      => ['title' => 'راهنمای امنیت و ممیزی نمرات', 'hash' => $temporary_hash],
            'hst_tuition'          => ['title' => 'راهنمای شهریه', 'hash' => $temporary_hash],
            'hst_tuition_payments' => ['title' => 'راهنمای پرداخت‌های شهریه', 'hash' => $temporary_hash],
            'hst_notifications'    => ['title' => 'راهنمای اطلاعیه‌ها', 'hash' => $temporary_hash],
            'hst_import_users'     => ['title' => 'راهنمای انتقال اطلاعات سیدا', 'hash' => $temporary_hash],
            'hst_discipline'       => ['title' => 'راهنمای انضباط', 'hash' => $temporary_hash],
            'hst_term_transfer'    => ['title' => 'راهنمای انتقال سال تحصیلی', 'hash' => $temporary_hash],
            'hst_backup'           => ['title' => 'راهنمای پشتیبان‌گیری', 'hash' => $temporary_hash],
            'hst_plugin_settings'  => ['title' => 'راهنمای تنظیمات افزونه', 'hash' => $temporary_hash],
            'hst_smart_analysis'   => ['title' => 'راهنمای تحلیل هوشمند', 'hash' => $temporary_hash],
            'hst_assignments'      => ['title' => 'راهنمای تکالیف', 'hash' => $temporary_hash],
            'hst_attendance'       => ['title' => 'راهنمای حضور و غیاب', 'hash' => $temporary_hash],
            'hst_exams'            => ['title' => 'راهنمای آزمون‌ها و بانک سؤال', 'hash' => $temporary_hash],
            'hst_my_teachers'      => ['title' => 'راهنمای معلم‌های من', 'hash' => $temporary_hash],
            'hst_report_cards'     => ['title' => 'راهنمای کارنامه‌ها', 'hash' => $temporary_hash],
        ];
    }

    private function current_page_help_video(): array
    {
        foreach ($this->page_help_video_map() as $shortcode => $video) {
            if (!$this->post_has_shortcode($shortcode)) {
                continue;
            }

            $hash = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($video['hash'] ?? ''));
            if ($hash === '') {
                return [
                    'shortcode' => $shortcode,
                    'title'     => sanitize_text_field((string) ($video['title'] ?? 'راهنما')),
                    'hash'      => '',
                    'embed_url' => '',
                ];
            }

            return [
                'shortcode' => $shortcode,
                'title'     => sanitize_text_field((string) ($video['title'] ?? 'راهنما')),
                'hash'      => $hash,
                'embed_url' => 'https://www.aparat.com/video/video/embed/videohash/' . rawurlencode($hash) . '/vt/frame',
            ];
        }

        return [];
    }

    private function get_classes(): array
    {
        $items = HST_Classes::all_by_name();
        if (!is_array($items)) {
            return [];
        }

        return array_map(
            static fn($item): array => [
                'id'         => absint($item->id ?? 0),
                'class_name' => sanitize_text_field($item->class_name ?? ''),
            ],
            $items
        );
    }

    private function enqueue_style_stack(): void
    {
        wp_enqueue_style(
            'hst-main-styles',
            HST_URL . 'assets/css/main.css',
            [],
            $this->asset_version('assets/css/main.css')
        );

    }

    private function enqueue_core_scripts(): void
    {
        wp_enqueue_script(
            'hst-modal-script',
            HST_URL . 'assets/js/core/hst-modal.js',
            ['jquery'],
            $this->asset_version('assets/js/core/hst-modal.js'),
            true
        );

        wp_enqueue_script(
            'hst-core-script',
            HST_URL . 'assets/js/core/hst-core.js',
            ['jquery', 'hst-modal-script'],
            $this->asset_version('assets/js/core/hst-core.js'),
            true
        );

        wp_enqueue_script(
            'hst-inline-filter-script',
            HST_URL . 'assets/js/core/hst-inline-filter.js',
            ['jquery', 'hst-core-script'],
            $this->asset_version('assets/js/core/hst-inline-filter.js'),
            true
        );

        wp_localize_script(
            'hst-core-script',
            'hst_ajax_obj',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('hst_nonce'),
                'classes'   => $this->get_classes(),
                'page_help' => $this->current_page_help_video(),
            ]
        );
    }



    private function school_manager_name(): string
    {
        foreach (['modir', 'administrator'] as $role) {
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
            $name = trim(
                (string) get_user_meta((int) $user->ID, 'first_name', true)
                . ' '
                . (string) get_user_meta((int) $user->ID, 'last_name', true)
            );

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

    /**
     * Enqueue the reusable print / PDF component (HSTPrint).
     *
     * Loads the jsPDF library, the embedded Vazir font, the Persian shaper and
     * the HSTPrint wrapper, then localises print config (school name, logo,
     * accent colour, paper/orientation, current Jalali date). Call this from
     * any page that needs printing or PDF export. Idempotent.
     */
    private function enqueue_print_component(): void
    {
        if (wp_script_is('hst-print-script', 'enqueued')) {
            return;
        }

        $ver = defined('HST_VERSION') ? HST_VERSION : '1.0';

        wp_enqueue_script('hst-jspdf', HST_URL . 'assets/lib/jspdf.umd.min.js', [], '2.5.1', true);
        wp_enqueue_script('hst-vazir-font', HST_URL . 'assets/lib/vazir-font.js', [], $ver, true);
        wp_enqueue_script('hst-persian-shaper', HST_URL . 'assets/lib/persian-shaper.js', [], $ver, true);
        wp_enqueue_script(
            'hst-qrcode',
            HST_URL . 'assets/lib/qrcode/qrcode-generator.js',
            [],
            $this->asset_version('assets/lib/qrcode/qrcode-generator.js'),
            true
        );

        wp_enqueue_script(
            'hst-print-script',
            HST_URL . 'assets/js/core/hst-print.js',
            ['hst-jspdf', 'hst-vazir-font', 'hst-persian-shaper', 'hst-qrcode'],
            $this->asset_version('assets/js/core/hst-print.js'),
            true
        );

        $accent = class_exists('HST_Settings') ? HST_Settings::fixed_accent_color() : '#334155';

        $logo_url = '';
        if (class_exists('HST_Settings')) {
            $logo_id = (int) HST_Settings::option('hst-home-logo-id', 0);
            if ($logo_id) {
                $logo_url = (string) wp_get_attachment_image_url($logo_id, 'medium');
            }
        }

        $orientation = class_exists('HST_Schedule_PDF') ? HST_Schedule_PDF::settings_orientation() : 'L';
        $paper = class_exists('HST_Schedule_PDF') ? HST_Schedule_PDF::settings_paper() : 'A4';
        $header_text = class_exists('HST_Schedule_PDF') ? HST_Schedule_PDF::settings_header_text() : get_bloginfo('name');

        wp_localize_script('hst-print-script', 'hstPrintConfig', [
            'accent'      => $accent,
            'schoolName'  => $header_text,
            'logoUrl'     => $logo_url,
            'managerName' => $this->school_manager_name(),
            'fontUrl'     => HST_URL . 'assets/font/Vazir.woff2',
            'orientation' => $orientation,
            'paper'       => $paper,
            'today'       => class_exists('HST_Date') ? HST_Date::today() : date_i18n('Y/m/d'),
        ]);
    }

    private function enqueue_script_for_shortcode(string $shortcode, string $handle, string $file, array $deps = ['jquery', 'hst-core-script']): void
    {
        if (!$this->post_has_shortcode($shortcode)) {
            return;
        }

        wp_enqueue_script(
            $handle,
            HST_URL . 'assets/js/' . $file,
            $deps,
            $this->asset_version('assets/js/' . $file),
            true
        );
    }

    public function enqueue_frontend_assets(): void
    {
        if (!$this->is_hst_frontend_page()) {
            return;
        }

        $this->enqueue_style_stack();
        $this->enqueue_core_scripts();

        $datepicker_shortcodes = [
            'hst_teachers',
            'hst_students',
            'hst_periods',
            'hst_tuition',
            'hst_notifications',
            'hst_discipline',
            'hst_assignments',
            'hst_attendance',
            'hst_exams',
        ];
        foreach ($datepicker_shortcodes as $shortcode) {
            if ($this->post_has_shortcode($shortcode)) {
                wp_enqueue_script(
                    'hst-jalali-datepicker-script',
                    HST_URL . 'assets/js/core/hst-jalali-datepicker.js',
                    ['jquery'],
                    $this->asset_version('assets/js/core/hst-jalali-datepicker.js'),
                    true
                );
                break;
            }
        }

        if (is_user_logged_in()) {
            wp_enqueue_script(
                'hst-profile-script',
                HST_URL . 'assets/js/core/hst-profile.js',
                ['jquery', 'hst-core-script'],
                $this->asset_version('assets/js/core/hst-profile.js'),
                true
            );

            if (class_exists('HST_Notifications')) {
                wp_enqueue_script(
                    'hst-header-notifications-script',
                    HST_URL . 'assets/js/core/hst-header-notifications.js',
                    ['jquery', 'hst-core-script'],
                    $this->asset_version('assets/js/core/hst-header-notifications.js'),
                    true
                );
            }
        }

        foreach (['hst_classes', 'hst_terms', 'hst_lessons', 'hst_teachers', 'hst_students', 'hst_score_audit'] as $shortcode) {
            if ($this->post_has_shortcode($shortcode)) {
                wp_enqueue_script(
                    'hst-list-filters-script',
                    HST_URL . 'assets/js/core/hst-list-filters.js',
                    ['jquery'],
                    $this->asset_version('assets/js/core/hst-list-filters.js'),
                    true
                );
                break;
            }
        }

        // The exam builder calls HSTPrint from its ready handler, so enqueue
        // the shared print/PDF stack before the page-specific exam script.
        if ($this->post_has_shortcode('hst_exams') || $this->post_has_shortcode('hst_report_cards')) {
            $this->enqueue_print_component();
        }

        foreach ($this->frontend_scripts as $shortcode => [$handle, $file]) {
            $deps = ['jquery', 'hst-core-script'];
            if (in_array($shortcode, ['hst_notifications', 'hst_assignments', 'hst_exams'], true)) {
                $deps[] = 'hst-jalali-datepicker-script';
            }
            if ($shortcode === 'hst_exams') {
                $deps[] = 'hst-print-script';
            } elseif ($shortcode === 'hst_report_cards') {
                $deps[] = 'hst-print-script';
            }
            $this->enqueue_script_for_shortcode($shortcode, $handle, $file, $deps);
        }

        $print_shortcodes = ['hst_schedules', 'hst_my_schedule', 'hst_teachers', 'hst_discipline', 'hst_tuition', 'hst_exams', 'hst_report_cards'];
        foreach ($print_shortcodes as $shortcode) {
            if ($this->post_has_shortcode($shortcode)) {
                $this->enqueue_print_component();
                break;
            }
        }

        // Only schedule screens need the schedule-specific click handler.
        foreach (['hst_schedules', 'hst_my_schedule', 'hst_teachers'] as $shortcode) {
            if ($this->post_has_shortcode($shortcode)) {
                wp_enqueue_script(
                    'hst-schedule-pdf-script',
                    HST_URL . 'assets/js/schedules/hst-schedule-pdf.js',
                    ['jquery', 'hst-core-script', 'hst-print-script'],
                    $this->asset_version('assets/js/schedules/hst-schedule-pdf.js'),
                    true
                );
                break;
            }
        }
    }

}
