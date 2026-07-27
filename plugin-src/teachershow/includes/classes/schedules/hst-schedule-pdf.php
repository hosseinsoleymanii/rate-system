<?php

defined('ABSPATH') || exit;

/**
 * Weekly-schedule PDF / print system, built on mPDF.
 *
 * Renders a class or teacher weekly schedule grid to a downloadable PDF.
 * mPDF is loaded from the plugin's bundled vendor/ directory (installed via
 * Composer). Persian/RTL is handled by registering the bundled Vazir font and
 * enabling mPDF's autoArabic/RTL handling.
 *
 * Custom output settings (page orientation, paper size, school header text,
 * and logo) come from HST_Settings.
 *
 * Everything is defensive: if mPDF is not installed, AJAX handlers return a
 * clear error instead of fataling.
 */
class HST_Schedule_PDF
{
    public function __construct()
    {
        add_action('init', [$this, 'maybe_render_public_schedule_pdf']);
        add_action('wp_ajax_hst_schedule_pdf', [$this, 'ajax_schedule_pdf']);
    }

    private function public_schedule_token(string $type, int $entity_id, int $term_id): string
    {
        return substr(hash_hmac('sha256', 'schedule-pdf|' . $type . '|' . $entity_id . '|' . $term_id . '|' . get_current_blog_id(), wp_salt('auth')), 0, 16);
    }

    private function public_schedule_pdf_url(string $type, int $entity_id, int $term_id): string
    {
        $type = in_array($type, ['teacher', 'class'], true) ? $type : 'teacher';
        return home_url('/?hst_schedule_pdf=' . rawurlencode($type . '-' . $entity_id . '-' . $term_id . '-' . $this->public_schedule_token($type, $entity_id, $term_id)));
    }

    private function qr_image_url(string $payload, int $size = 260): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=' . absint($size) . 'x' . absint($size) . '&margin=14&data=' . rawurlencode($payload);
    }

    public function maybe_render_public_schedule_pdf(): void
    {
        if (empty($_GET['hst_schedule_pdf'])) {
            return;
        }

        $raw = sanitize_text_field(wp_unslash((string) $_GET['hst_schedule_pdf']));
        if (!preg_match('/^(teacher|class)-(\d+)-(\d+)-([a-f0-9]{16})$/i', $raw, $m)) {
            status_header(403);
            wp_die('لینک برنامه هفتگی معتبر نیست.');
        }

        $type = strtolower((string) $m[1]);
        $entity_id = absint($m[2]);
        $term_id = absint($m[3]);
        $token = strtolower((string) $m[4]);

        if (!$entity_id || !$term_id || !hash_equals($this->public_schedule_token($type, $entity_id, $term_id), $token)) {
            status_header(403);
            wp_die('لینک برنامه هفتگی معتبر نیست.');
        }

        try {
            $data = $type === 'teacher'
                ? $this->build_teacher_data($entity_id, $term_id)
                : $this->build_class_data($entity_id, $term_id);

            if (empty($data['rows'])) {
                status_header(404);
                wp_die('برای این برنامه، داده‌ای پیدا نشد.');
            }

            $blocks = [$this->grid_block($data)];
        } catch (\Throwable $e) {
            status_header(500);
            wp_die('خطا در آماده‌سازی برنامه هفتگی.');
        }

        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        echo $this->schedule_pdf_download_html($data, $blocks); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private function schedule_pdf_download_html(array $data, array $blocks): string
    {
        $title = (string) ($data['title'] ?? 'برنامه هفتگی');
        $name = !empty($data['teacher_name']) ? (string) $data['teacher_name'] : $title;
        $filename_subject = sanitize_file_name($name ?: '');
        $filename = 'برنامه-هفتگی' . ($filename_subject !== '' ? '-' . $filename_subject : '') . '.pdf';

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
            'schoolName'  => self::settings_header_text(),
            'logoUrl'     => $logo_url,
            'fontUrl'     => HST_URL . 'assets/font/Vazir.woff2',
            'orientation' => 'P',
            'paper'       => 'A4',
            'today'       => class_exists('HST_Date') ? HST_Date::today() : date_i18n('Y/m/d'),
        ];

        $payload = [
            'blocks'       => $blocks,
            'title'        => $title,
            'filename'     => $filename,
            'fallbackHtml' => $this->build_print_document($data),
        ];

        return HST_Print_Document::download_page([
            'title'       => 'دانلود برنامه هفتگی',
            'message'     => 'فایل PDF برنامه هفتگی در حال آماده‌سازی و دانلود است.',
            'config'      => $config,
            'payload'     => $payload,
            'payload_key' => 'hstSchedulePdfPayload',
            'method'      => 'gridPdf',
            'scripts'     => [
                HST_URL . 'assets/lib/jspdf.umd.min.js',
                HST_URL . 'assets/lib/vazir-font.js',
                HST_URL . 'assets/lib/persian-shaper.js',
                HST_URL . 'assets/lib/qrcode/qrcode-generator.js',
                HST_URL . 'assets/js/core/hst-print.js',
            ],
        ]);
    }


    // ---------------------------------------------------------------------
    //  AJAX entry point
    // ---------------------------------------------------------------------

    public function ajax_schedule_pdf()
    {
        if (class_exists('HST_Guard')) {
            HST_Guard::verify_ajax('read');
        } elseif (!is_user_logged_in() || !check_ajax_referer('hst_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز.']);
        }

        $type     = sanitize_key($_POST['schedule_type'] ?? 'class');
        $class_id = absint($_POST['class_id'] ?? 0);
        $teacher_id = absint($_POST['teacher_id'] ?? 0);
        $term_id  = absint($_POST['term_id'] ?? 0);

        if (!$term_id && class_exists('HST_Terms')) {
            $term_id = (int) HST_Terms::active_id();
        }

        try {
            if ($type === 'all_teachers') {
                $data = $this->build_all_teachers_data($term_id);
            } elseif ($type === 'all_classes') {
                $data = $this->build_all_classes_data($term_id);
            } elseif ($type === 'teacher') {
                $data = $this->build_teacher_data($teacher_id, $term_id);
            } else {
                $data = $this->build_class_data($class_id, $term_id);
            }
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'خطا در آماده‌سازی دادهٔ برنامه: ' . $e->getMessage()]);
        }

        // Build structured grid blocks (head + rows) that the client renders to
        // PDF with jsPDF — fully device-independent, no browser print dialog.
        $blocks = [];
        if (in_array($type, ['all_teachers', 'all_classes'], true)) {
            foreach (($data['blocks'] ?? []) as $block) {
                $blocks[] = $this->grid_block($block);
            }
        } elseif (!empty($data['rows'])) {
            $blocks[] = $this->grid_block($data);
        }

        if (empty($blocks)) {
            wp_send_json_error(['message' => 'برای این انتخاب، برنامه‌ای ثبت نشده است.']);
        }

        wp_send_json_success([
            'title'  => $data['title'] ?? 'برنامه هفتگی',
            'blocks' => $blocks,
            // Keep the print-ready HTML too, as a fallback for browsers where
            // the PDF library failed to load.
            'html'   => $type === 'all_teachers'
                ? $this->build_print_document($data)
                : $this->build_print_document($data),
        ]);
    }

    // ---------------------------------------------------------------------
    //  Data builders
    // ---------------------------------------------------------------------

    private function build_class_data($class_id, $term_id): array
    {
        if (!$class_id || !$term_id) {
            return ['rows' => []];
        }
        $rows = HST_Schedules::class_saved_schedule($class_id, $term_id);

        global $wpdb;
        $class_name = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT class_name FROM {$wpdb->prefix}hst_classes WHERE id = %d", $class_id)
        );
        $term_name = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT term_name FROM {$wpdb->prefix}hst_terms WHERE id = %d", $term_id)
        );

        $download_url = $class_id && $term_id ? $this->public_schedule_pdf_url('class', (int) $class_id, (int) $term_id) : '';

        return [
            'rows'         => $rows,
            'title'        => 'برنامه هفتگی کلاس ' . $class_name,
            'subtitle'     => $term_name ? ('سال تحصیلی ' . $term_name) : '',
            'mode'         => 'class',
            'term_id'      => (int) $term_id,
            'entity_id'    => (int) $class_id,
            'download_url' => $download_url,
            'qr_url'       => $download_url ? $this->qr_image_url($download_url, 260) : '',
        ];
    }

    private function build_teacher_data($teacher_id, $term_id): array
    {
        if (!$teacher_id || !$term_id) {
            return ['rows' => []];
        }

        global $wpdb;
        // Teacher schedule across all their classes in this term.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.day_of_week, s.school_shift, s.week_type, s.lesson_id, s.teacher_id,
                        l.lesson_name, c.class_name,
                        u.display_name AS teacher_name
                 FROM {$wpdb->prefix}hst_schedules s
                 INNER JOIN {$wpdb->prefix}hst_lessons l ON s.lesson_id = l.id
                 INNER JOIN {$wpdb->prefix}hst_classes c ON s.class_id = c.id
                 INNER JOIN {$wpdb->users} u ON s.teacher_id = u.ID
                 WHERE s.teacher_id = %d AND s.term_id = %d
                 ORDER BY FIELD(s.day_of_week, 'saturday','sunday','monday','tuesday','wednesday'),
                    s.school_shift,
                    FIELD(s.week_type, 'every','odd','even')",
                $teacher_id,
                $term_id
            )
        ) ?: [];

        $teacher = get_userdata($teacher_id);
        $teacher_name = $teacher ? ($teacher->display_name ?: $teacher->user_login) : '';
        $term_name = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT term_name FROM {$wpdb->prefix}hst_terms WHERE id = %d", $term_id)
        );

        // Teacher avatar (data URL so it embeds cleanly in the client PDF).
        $avatar_url = '';
        $avatar_id = absint(get_user_meta($teacher_id, 'hst_profile_avatar_id', true));
        if (!$avatar_id && class_exists('HST_Avatar_Approval')) {
            $avatar_id = (int) HST_Avatar_Approval::display_avatar_id($teacher_id, $teacher_id);
        }
        if ($avatar_id) {
            $avatar_url = (string) wp_get_attachment_url($avatar_id);
            if ($avatar_url === '') {
                $avatar_url = (string) wp_get_attachment_image_url($avatar_id, 'full');
            }
        }

        $download_url = $teacher_id && $term_id ? $this->public_schedule_pdf_url('teacher', (int) $teacher_id, (int) $term_id) : '';

        return [
            'rows'         => $rows,
            'title'        => 'برنامه هفتگی',
            'teacher_name' => 'آقای ' . $teacher_name,
            'avatar_url'   => $avatar_url,
            'initial'      => $this->user_initials((int) $teacher_id, $teacher_name),
            'subtitle'     => $term_name ? ('سال تحصیلی ' . $term_name) : '',
            'mode'         => 'teacher',
            'term_id'      => (int) $term_id,
            'entity_id'    => (int) $teacher_id,
            'download_url' => $download_url,
            'qr_url'       => $download_url ? $this->qr_image_url($download_url, 260) : '',
        ];
    }

    /**
     * Build a combined document for every teacher who has a saved schedule in
     * this term. Each teacher gets their own weekly grid on its own page.
     */
    private function build_all_teachers_data($term_id): array
    {
        if (!$term_id) {
            return ['bodies' => []];
        }

        global $wpdb;
        // Every teacher that has at least one scheduled lesson this term.
        $teacher_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT s.teacher_id
                 FROM {$wpdb->prefix}hst_schedules s
                 INNER JOIN {$wpdb->users} u ON s.teacher_id = u.ID
                 WHERE s.term_id = %d
                 ORDER BY u.display_name ASC",
                $term_id
            )
        ) ?: [];

        $term_name = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT term_name FROM {$wpdb->prefix}hst_terms WHERE id = %d", $term_id)
        );

        $bodies = [];
        $blocks = [];
        foreach ($teacher_ids as $tid) {
            $teacher_data = $this->build_teacher_data((int) $tid, $term_id);
            if (empty($teacher_data['rows'])) {
                continue;
            }
            $bodies[] = $this->build_html($teacher_data);
            $blocks[] = $teacher_data;
        }

        return [
            'bodies'   => $bodies,
            'blocks'   => $blocks,
            'title'    => 'برنامه هفتگی همه معلمان',
            'subtitle' => $term_name ? ('سال تحصیلی ' . $term_name) : '',
            'mode'     => 'all_teachers',
        ];
    }

    /**
     * Build a combined document for every class that has a saved schedule in
     * this term. Each class gets its own weekly grid in the generated PDF.
     */
    private function build_all_classes_data($term_id): array
    {
        if (!$term_id) {
            return ['bodies' => []];
        }

        global $wpdb;

        $class_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT s.class_id AS id, c.class_name
                 FROM {$wpdb->prefix}hst_schedules s
                 INNER JOIN {$wpdb->prefix}hst_classes c ON s.class_id = c.id
                 WHERE s.term_id = %d
                 ORDER BY c.class_name ASC",
                $term_id
            )
        ) ?: [];
        $class_rows = HST_Classes::sort_rows($class_rows);
        $class_ids = array_map(static fn($row) => (int) $row->id, $class_rows);

        $term_name = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT term_name FROM {$wpdb->prefix}hst_terms WHERE id = %d", $term_id)
        );

        $bodies = [];
        $blocks = [];

        foreach ($class_ids as $class_id) {
            $class_data = $this->build_class_data((int) $class_id, $term_id);
            if (empty($class_data['rows'])) {
                continue;
            }

            // This document is class-based, so the PDF client must not reserve
            // space for the teacher avatar/profile header.
            $class_data['hide_identity'] = true;
            $class_data['block_mode'] = 'class';

            $bodies[] = $this->build_html($class_data);
            $blocks[] = $class_data;
        }

        return [
            'bodies'   => $bodies,
            'blocks'   => $blocks,
            'title'    => 'برنامه هفتگی همه کلاس‌ها',
            'subtitle' => $term_name ? ('سال تحصیلی ' . $term_name) : '',
            'mode'     => 'all_classes',
        ];
    }

    // ---------------------------------------------------------------------
    //  PDF rendering
    // ---------------------------------------------------------------------

    /**
     * Wrap the schedule grid HTML in a complete, self-contained print document.
     * Uses the site's bundled Vazir web-font (so Persian renders perfectly in
     * the browser) and an @page rule driven by the saved orientation/size.
     */
    private function build_print_document(array $data): string
    {
        $orientation = self::settings_orientation() === 'P' ? 'portrait' : 'landscape';
        $paper       = self::settings_paper(); // A4 | A5
        if (!empty($data['bodies'])) {
            // Combine each teacher's grid, page-breaking between teachers.
            $parts = [];
            $last  = count($data['bodies']) - 1;
            foreach ($data['bodies'] as $i => $b) {
                $class = $i < $last ? ' class="hst-print-page-break"' : '';
                $parts[] = '<div' . $class . '>' . $b . '</div>';
            }
            $body = implode("\n", $parts);
        } else {
            $body = $this->build_html($data);
        }
        $title       = esc_html($data['title']);

        // Embed the Vazir font inline (base64) so it is fully available before
        // the page paints. On iOS Safari, a late-swapping web-font caused the
        // PDF text layer to be written twice (doubled glyphs on extract); an
        // inline font with font-display:block avoids that double render.
        $font_face = '';
        $font_path = defined('HST_PATH') ? HST_PATH . 'assets/font/Vazir.woff2' : '';
        if ($font_path && file_exists($font_path)) {
            $b64 = base64_encode((string) file_get_contents($font_path));
            if ($b64 !== '') {
                $font_face = "@font-face{font-family:'Vazir';"
                    . "src:url(data:font/woff2;base64,{$b64}) format('woff2');"
                    . "font-weight:normal;font-style:normal;font-display:block;}";
            }
        }

        ob_start();
        ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $title; ?></title>
<style>
    <?php echo $font_face; ?>
    @page {
        size: <?php echo esc_html($paper . ' ' . $orientation); ?>;
        margin: 10mm;
    }
    * { box-sizing: border-box; }
    html, body {
        margin: 0;
        padding: 0;
        font-family: 'Vazir', Tahoma, Arial, sans-serif;
        -webkit-text-size-adjust: 100%;
        text-size-adjust: 100%;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        direction: rtl;
    }
    body { padding: 12px; }
    .hst-print-page-break { page-break-after: always; break-after: page; }
    @media print {
        body { padding: 0; color: #111418; }
        * { text-shadow: none !important; box-shadow: none !important; }
        table, th, td { border-color: #777d84 !important; }
        th, [class*="head"], [class*="header"] { background: #e2e5e8 !important; color: #111418 !important; }
        td, [class*="cell"], [class*="card"] { color: #111418 !important; }
        [class*="soft"], [class*="week"] { background: #f3f3f3 !important; color: #111418 !important; }
    }
</style>
</head>
<body>
    <?php echo $body; ?>
    <script>
        // Go straight to the browser's native print / "Save as PDF" dialog.
        // Wait for the inline font to be ready so glyphs render correctly.
        function hstPrint() { window.focus(); window.print(); }
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () { hstPrint(); });
        } else {
            window.addEventListener('load', hstPrint);
        }
    </script>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Build the printable HTML (header + weekly grid). Inline CSS only, since
     * mPDF supports a limited CSS subset.
     */
    private function build_html(array $data): string
    {
        $days   = HST_Schedules::days();          // saturday => شنبه, ...
        $shifts = [1, 2, 3, 4];
        $grid   = $this->grid_by_day_shift($data['rows'], $data['mode']);

        $school = esc_html(self::settings_header_text());
        $logo   = self::settings_logo_html();
        $title  = esc_html($data['title']);
        $subtitle = esc_html($data['subtitle']);
        $today  = class_exists('HST_Date') ? HST_Date::today() : date_i18n('Y/m/d');

        $shift_labels = [1 => 'زنگ اول', 2 => 'زنگ دوم', 3 => 'زنگ سوم', 4 => 'زنگ چهارم'];
        $accent = class_exists('HST_Settings') ? HST_Settings::fixed_accent_color() : '#334155';

        ob_start();
        ?>
        <style>
            :root {
                --hst-print-accent: <?php echo esc_html($accent); ?>;
                --hst-print-ink: #1b2733;
                --hst-print-muted: #64748b;
                --hst-print-subtle: #475569;
                --hst-print-faint: #94a3b8;
                --hst-print-border: #cbd5e1;
                --hst-print-soft: #f1f5f9;
                --hst-print-surface: #ffffff;
            }
            body { font-family: inherit; color: var(--hst-print-ink); }
            .hdr { width: 100%; border-bottom: 2px solid var(--hst-print-accent); padding-bottom: 6px; margin-bottom: 10px; }
            .hdr td { vertical-align: middle; }
            .hdr-main { width: 70%; text-align: right; }
            .hdr-logo { width: 30%; text-align: left; }
            .hdr-logo img { max-height: 46px; max-width: 120px; }
            .hdr .school { font-size: 13pt; font-weight: bold; color: var(--hst-print-accent); }
            .hdr .meta { font-size: 9pt; color: var(--hst-print-muted); }
            h2 { font-size: 12pt; margin: 4px 0; text-align: center; }
            .sub { font-size: 9pt; color: var(--hst-print-muted); text-align: center; margin-bottom: 8px; }
            table.grid { width: 100%; border-collapse: collapse; }
            table.grid th, table.grid td {
                border: 0.5pt solid var(--hst-print-border); padding: 5px 4px; text-align: center; font-size: 8.5pt;
            }
            table.grid thead th { background: var(--hst-print-accent); color: var(--hst-print-surface); font-size: 9pt; }
            table.grid .shift-cell { background: var(--hst-print-soft); font-weight: bold; width: 60px; }
            .cell-lesson { font-weight: bold; display: block; }
            .cell-sub { color: var(--hst-print-subtle); font-size: 7.5pt; display: block; }
            .cell-week { color: var(--hst-print-accent); font-size: 7pt; display: block; }
            .empty { color: var(--hst-print-border); }
            .foot { margin-top: 10px; font-size: 7.5pt; color: var(--hst-print-faint); text-align: center; }
        </style>

        <table class="hdr">
            <tr>
                <td class="hdr-main">
                    <span class="school"><?php echo $school; ?></span><br>
                    <span class="meta">تاریخ تهیه: <?php echo esc_html($today); ?></span>
                </td>
                <td class="hdr-logo"><?php echo $logo; ?></td>
            </tr>
        </table>

        <h2><?php echo $title; ?></h2>
        <?php if ($subtitle) : ?><div class="sub"><?php echo $subtitle; ?></div><?php endif; ?>

        <table class="grid">
            <thead>
                <tr>
                    <th class="shift-cell">زنگ</th>
                    <?php foreach ($days as $key => $label) : ?>
                        <th><?php echo esc_html($label); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shifts as $shift) : ?>
                    <tr>
                        <td class="shift-cell"><?php echo esc_html($shift_labels[$shift] ?? ('زنگ ' . $shift)); ?></td>
                        <?php foreach ($days as $day_key => $day_label) : ?>
                            <td>
                                <?php
                                $entries = $grid[$day_key][$shift] ?? [];
                                if (empty($entries)) {
                                    echo '<span class="empty">—</span>';
                                } else {
                                    foreach ($entries as $entry) {
                                        echo '<span class="cell-lesson">' . esc_html($entry['lesson']) . '</span>';
                                        if (!empty($entry['sub'])) {
                                            echo '<span class="cell-sub">' . esc_html($entry['sub']) . '</span>';
                                        }
                                        if (!empty($entry['week'])) {
                                            echo '<span class="cell-week">' . esc_html($entry['week']) . '</span>';
                                        }
                                    }
                                }
                                ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="foot">تولیدشده توسط سامانه مدرسه</div>
        <?php
        return ob_get_clean();
    }

    /**
     * Turn a data block (rows + mode + title) into a structured grid payload
     * the client can render to PDF with jsPDF: a header row (زنگ + days) and
     * one row per shift, each cell carrying multi-line text. This keeps PDF
     * generation device-independent (no browser print dialog).
     */
    private function grid_block(array $data): array
    {
        $days   = HST_Schedules::days();           // saturday => شنبه, ...
        $shifts = [1, 2, 3, 4];
        $shift_labels = [1 => 'زنگ ۱', 2 => 'زنگ ۲', 3 => 'زنگ ۳', 4 => 'زنگ ۴'];
        $grid   = $this->grid_by_day_shift($data['rows'] ?? [], $data['mode'] ?? 'teacher');

        // Day header labels (right-to-left order handled on the client).
        $day_labels = array_values($days);

        // Build a structured matrix: rows[shift][day] = array of entries.
        // Each entry: { lesson, sub, week } where week is '', 'هفته فرد', 'هفته زوج'.
        $matrix = [];
        foreach ($shifts as $shift) {
            $rowCells = [];
            foreach ($days as $day_key => $day_label) {
                $entries = $grid[$day_key][$shift] ?? [];
                $cell = [];
                foreach ($entries as $entry) {
                    $cell[] = [
                        'lesson' => $entry['lesson'],
                        'sub'    => $entry['sub'],
                        'week'   => $entry['week'], // '', 'هفته فرد', 'هفته زوج'
                    ];
                }
                $rowCells[] = $cell;
            }
            $matrix[] = [
                'label' => $shift_labels[$shift] ?? ('زنگ ' . $shift),
                'cells' => $rowCells,
            ];
        }

        return [
            'title'         => $data['title'] ?? 'برنامه هفتگی',
            'teacher_name'  => $data['teacher_name'] ?? ($data['title'] ?? ''),
            'avatar_url'    => $data['avatar_url'] ?? '',
            'initial'       => $data['initial'] ?? '',
            'subtitle'      => $data['subtitle'] ?? '',
            'download_url'  => $data['download_url'] ?? '',
            'qr_url'        => $data['qr_url'] ?? '',
            'mode'          => $data['block_mode'] ?? ($data['mode'] ?? ''),
            'hide_identity' => !empty($data['hide_identity']),
            'days'          => $day_labels,
            'shifts'        => $matrix,
        ];
    }

    private function grid_by_day_shift(array $rows, string $mode): array
    {
        $week_labels = ['odd' => 'هفته فرد', 'even' => 'هفته زوج', 'every' => ''];
        $grid = [];
        foreach ($rows as $row) {
            $day   = $row->day_of_week;
            $shift = (int) $row->school_shift;
            $entry = [
                'lesson' => $row->lesson_name,
                'sub'    => $mode === 'teacher'
                    ? ('کلاس ' . ($row->class_name ?? ''))
                    : ($row->teacher_name ?? ''),
                'week'   => $week_labels[$row->week_type] ?? '',
            ];
            $grid[$day][$shift][] = $entry;
        }
        return $grid;
    }

    private function user_initials(int $user_id, string $display_name): string
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

    // ---------------------------------------------------------------------
    //  Settings helpers
    // ---------------------------------------------------------------------

    private static function opt($name, $default = '')
    {
        return class_exists('HST_Settings') ? HST_Settings::option($name, $default) : $default;
    }

    public static function settings_orientation(): string
    {
        return 'L';
    }

    public static function settings_paper(): string
    {
        return 'A4';
    }

    public static function settings_header_text(): string
    {
        return (string) get_bloginfo('name');
    }

    private static function settings_logo_html(): string
    {
        $logo_id = absint(self::opt('hst-home-logo-id', 0));
        if (!$logo_id) {
            return '';
        }
        $url = wp_get_attachment_image_url($logo_id, 'medium');
        if (!$url) {
            return '';
        }
        return '<img src="' . esc_url($url) . '" alt="">';
    }
}
