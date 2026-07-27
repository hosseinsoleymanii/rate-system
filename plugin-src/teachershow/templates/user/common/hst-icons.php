<?php
/**
 * Inline SVG icon set for TeacherShow.
 * Usage: echo hst_icon('students');  // returns a 24x24 stroke icon (currentColor-friendly via .ico)
 * All icons are line-style, sized/colored by the .ico CSS class.
 */
defined('ABSPATH') || exit;

if (!function_exists('hst_icon')) {
    function hst_icon(string $name, string $class = 'ico'): string {
        $p = [
            // academic
            'classes'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 9h18"/>',
            'lessons'   => '<path d="M4 5h12v14H4z"/><path d="M16 5l4 2v12l-4-2"/><path d="M7 9h6M7 12h6"/>',
            'terms'     => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
            'teachers'  => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.4"/><path d="M3 19c1-4 11-4 12 0M14 19c.5-3 7-3 7 0"/>',
            'students'  => '<path d="M12 4l9 4-9 4-9-4 9-4z"/><path d="M6 10v5c0 1.5 12 1.5 12 0v-5"/>',
            'schedule'  => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 13h3M8 16h6"/>',
            'month'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'score-audit' => '<path d="M4 6h16M4 12h16M4 18h10"/><path d="M16 17l2 2 4-4"/>',
            'scores'    => '<path d="M12 3l2.7 5.5 6 .9-4.3 4.2 1 6-5.4-2.8L6.6 19.6l1-6L3.3 9.4l6-.9z"/>',
            'tuition'   => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9.5v.01M18 14.5v.01"/>',
            'notifications' => '<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2 2 0 0 0 4 0"/>',
            'exams'     => '<path d="M6 3h12v18l-6-3-6 3z"/><path d="M9 8h6M9 11h6"/>',
            'profile'   => '<circle cx="12" cy="8" r="4"/><path d="M4 20c1.5-4 14.5-4 16 0"/>',
            'attendance' => '<path d="M4 5h13v14H4z"/><path d="M8 11l2.5 2.5L15 9"/><path d="M20 5v14"/>',
            'assignments' => '<path d="M6 3h9l3 3v15H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
            'logout'    => '<path d="M9 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
            // distinct icons to avoid duplicates in the dashboard menu
            'discipline'  => '<path d="M12 3l8 3v5c0 5-4 8-8 10-4-2-8-5-8-10V6z"/><path d="M12 9v3M12 15v.01"/>',
            'security'    => '<path d="M12 3l8 3v5c0 5-4 8-8 10-4-2-8-5-8-10V6z"/><path d="M9.5 12l1.8 1.8L15 10"/>',
            'backup'      => '<ellipse cx="12" cy="6" rx="7" ry="2.6"/><path d="M5 6v12c0 1.5 3.1 2.6 7 2.6s7-1.1 7-2.6V6"/><path d="M5 12c0 1.5 3.1 2.6 7 2.6s7-1.1 7-2.6"/>',
            'transfer'    => '<path d="M4 8h13l-3-3M4 8l3 3"/><path d="M20 16H7l3 3M20 16l-3-3"/>',
            'import'      => '<path d="M12 3v10M8 9l4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
            'sms'         => '<path d="M4 5h16v11H9l-4 3v-3H4z"/><path d="M8 10h.01M12 10h.01M16 10h.01"/>',
            'report-card' => '<path d="M6 3h9l3 3v15H6z"/><path d="M14 3v4h4"/><path d="M12 11l1.2 2.4 2.6.4-1.9 1.8.4 2.6-2.3-1.2-2.3 1.2.4-2.6L8.2 13.8l2.6-.4z"/>',
            'award'       => '<circle cx="12" cy="8" r="5"/><path d="M8.8 12.1L8 21l4-2.5 4 2.5-.8-8.9"/><path d="M12 5.5v5M9.8 8h4.4"/>',
            'gradebook'   => '<path d="M5 4h11a2 2 0 0 1 2 2v14H7a2 2 0 0 1-2-2z"/><path d="M5 18a2 2 0 0 1 2-2h11"/><path d="M9 8h6M9 11h4"/>',
            'back'        => '<path d="M5 12h14"/><path d="M12 5l7 7-7 7"/>',
            'print'       => '<path d="M6 9V3h12v6"/><rect x="5" y="13" width="14" height="8" rx="1"/><path d="M7 13H5a3 3 0 0 1 0-6h14a3 3 0 0 1 0 6h-2"/><path d="M8 17h8M8 20h8"/>',
            'view'        => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
            'avatar-approve' => '<g stroke-width="2.15"><circle cx="12" cy="12" r="8.5"/><path d="M8.2 12.2l2.5 2.5 5.2-5.4"/></g>',
            'avatar-reject'  => '<g stroke-width="2.15"><circle cx="12" cy="12" r="8.5"/><path d="M9 9l6 6M15 9l-6 6"/></g>',
            'notification-view' => '<g stroke-width="2.15"><circle cx="10.5" cy="10.5" r="5.5"/><path d="M15 15l5 5"/></g>',
            'notification-read' => '<g stroke-width="2.15"><path d="M3.5 12.5l3.2 3.2 6.1-7"/><path d="M10.5 15.5l2 2 7.5-9"/></g>',
            'edit'        => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
            'delete'      => '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
            'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'smart-analysis' => '<path d="M9.5 4.5A3.5 3.5 0 0 0 6 8v1a3 3 0 0 0-2 2.8A3.2 3.2 0 0 0 7.2 15H8v1a3.5 3.5 0 0 0 3.5 3.5V4.5z"/><path d="M14.5 4.5A3.5 3.5 0 0 1 18 8v1a3 3 0 0 1 2 2.8 3.2 3.2 0 0 1-3.2 3.2H16v1a3.5 3.5 0 0 1-3.5 3.5V4.5z"/><path d="M8 9h2M14 9h2M8 14h2M14 14h2"/>',
            'home'      => '<path d="M4 11l8-6 8 6"/><path d="M6 10v9h12v-9"/>',
            'bell'      => '<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2 2 0 0 0 4 0"/>',
            'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.8 2.8 0 0 1 5.1-1.6c.7 1 .5 2.3-.4 3.1-.8.7-1.7 1.2-2 2.3"/><path d="M12 17h.01"/>',
            'add' => '<path d="M12 5v14M5 12h14"/>',
            'download' => '<path d="M12 3v12"/><path d="M8 11l4 4 4-4"/><path d="M4 19h16"/>',
            'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9" r="1.5"/><path d="M4.5 17l4.5-4.5 3.2 3.2 2.8-2.8 4.5 4.1"/>',
            'play' => '<path d="M8 5l11 7-11 7z"/>',
            'refresh' => '<path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M18.5 9a7 7 0 0 0-11.8-2.5L4 9"/><path d="M5.5 15a7 7 0 0 0 11.8 2.5L20 15"/>',
            'report' => '<path d="M6 3h9l3 3v15H6z"/><path d="M14 3v4h4"/><path d="M9 17v-3M12 17v-6M15 17v-4"/>',
            'excel' => '<path d="M6 3h9l3 3v15H6z"/><path d="M14 3v4h4"/><path d="M8.5 10l6 8M14.5 10l-6 8"/>',
            'discipline-book' => '<path d="M12 6.5C10.4 4.8 8.4 4 5.5 4H4v14h1.5c2.9 0 4.9.8 6.5 2.5z"/><path d="M12 6.5C13.6 4.8 15.6 4 18.5 4H20v14h-1.5c-2.9 0-4.9.8-6.5 2.5z"/><path d="M12 7v13.5M16 8v4M16 15h.01"/>',
        ];

        $body = $p[$name] ?? '<circle cx="12" cy="12" r="9"/>';

        return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $body . '</svg>';
    }
}

if (!function_exists('hst_loading_state')) {
    function hst_loading_state(): string {
        return '<span class="hst-inline-loading" role="status" aria-live="polite">'
            . '<span class="hst-inline-loading__spinner" aria-hidden="true"></span>'
            . '<span class="hst-inline-loading__text">در حال بارگذاری...</span>'
            . '</span>';
    }
}
