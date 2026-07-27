<?php

defined('ABSPATH') || exit;

function initialize_hst_classes($main_file_path = '')
{
    $classes = [
        'HST_Settings',
        'HST_Shortcodes',
        'HST_Scripts',
        'HST_PWA',
        'HST_Tables',
        'HST_User_Phones',
        'HST_Teachers',
        'HST_Students',
        'HST_Report_Cards',
        'HST_User_Import',
        'HST_Profile',
        'HST_Avatar_Approval',
        'HST_Lessons',
        'HST_Classes',
        'HST_Terms',
        'HST_Term_Transfer',
        'HST_Schedules',
        'HST_Schedule_PDF',
        'HST_Scores',
        'HST_Tuition',
        'HST_Notifications',
        'HST_Notify',
        'HST_Birthday',
        'HST_Assignments',
        'HST_Attendance',
        'HST_Discipline',
        'HST_Backup',
        'HST_Exams',
        'HST_Exam_Questions',
        'HST_Date',
        'HST_SMS',
        'HST_Otp_Login',
    ];

    foreach ($classes as $class_name) {
        if (!class_exists($class_name)) {
            error_log(sprintf('HST initialization skipped: %s not found.', $class_name));
            continue;
        }

        try {
            new $class_name();
        } catch (Throwable $e) {
            error_log(sprintf('HST initialization error in %s: %s', $class_name, $e->getMessage()));
        }
    }
}

/** Redirect obsolete plugin routes that may still exist in bookmarks. */
function hst_redirect_legacy_routes(): void
{
    if (is_admin() || !function_exists('is_page')) {
        return;
    }

    $routes = [
        'import-students' => '/import-users/',
        'sms'             => '/notifications/',
        'score-months'    => '/periods/',
    ];

    foreach ($routes as $legacy_slug => $target) {
        if (is_page($legacy_slug)) {
            wp_safe_redirect(home_url($target), 301);
            exit;
        }
    }
}
add_action('template_redirect', 'hst_redirect_legacy_routes');

/**
 * Run removed-feature cleanup once per cleanup schema version instead of on
 * every request. Exact plugin-generated obsolete pages are deleted; customized
 * pages only have the obsolete shortcode token removed.
 */
function hst_run_legacy_cleanup(): void
{
    $cleanup_version = '314';
    if ((string) get_option('hst-cleanup-schema', '') === $cleanup_version) {
        return;
    }

    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('hst_occasion_sms_cron');
    }

    $obsolete_options = [
        'hst-notify-autoopen',
        'hst-color-theme',
        'hst-dashboard-notices-count',
        'hst-dashboard-exams-count',
        'hst-auto-create-pages',
        'hst-sms-provider',
        'hst-sms-username',
        'hst-sms-password',
        'hst-sms-custom-url',
        'hst-sms-custom-method',
        'hst-sms-custom-token-header',
        'hst-sms-custom-body-template',
        'hst-sms-otp-custom-body-template',
        'hst-sms-notice-text',
        'hst-sms-otp-text',
        'hst-sms-otp-mode',
        'hst-birthday-notify-enabled',
        'hst-enable-role-login-redirect',
        'hst-login-redirect-teacher',
        'hst-login-redirect-student',
        'hst-login-redirect-manager',
        'hst-redirect-guest-access-to-login',
        'hst-enable-logout-redirect-to-login',
        'hst-login-page-url',
        'hst-hide-wp-admin-bar-for-school-roles',
        'hst-sms-general-pattern-code',
        'hst-sms-general-pattern-var',
        'hst-sms-notification-pattern-code',
        'hst-sms-tuition-pattern-code',
        'hst-sms-discipline-pattern-code',
        'hst-sms-birthday-pattern-code',
        'hst-sms-last-transaction',
        'hst-pdf-logo-id',
        'hst-pdf-orientation',
        'hst-pdf-paper',
        'hst-pdf-header-text',
        'hst-pdf-use-logo',
        'hst-custom-fields',
        'hst-notify-avatar-pending',
        'hst-avatar-review-page-cleaned-v260',
        'hst-teacher-can-view-students',
        'hst-student-can-view-scores',
        'hst-enable-tuition',
        'hst-enable-notifications',
        'hst-enable-assignments',
        'hst-enable-attendance',
        'hst-enable-exams',
        'hst-enable-my-teachers',
        'hst-enable-report-cards',
        'hst-home-use-logo',
        'hst-home-disable-builtin',
        'hst-mod-teacher-students',
        'hst-mod-teacher-my-schedule',
        'hst-mod-teacher-enter-scores',
        'hst-mod-teacher-notifications',
        'hst-mod-teacher-assignments',
        'hst-mod-teacher-attendance',
        'hst-mod-teacher-exams',
        'hst-mod-student-scores',
        'hst-mod-student-my-schedule',
        'hst-mod-student-tuition-payments',
        'hst-mod-student-notifications',
        'hst-mod-student-assignments',
        'hst-mod-student-exams',
        'hst-mod-student-my-teachers',
    ];

    foreach ($obsolete_options as $option_name) {
        delete_option($option_name);
    }

    $obsolete_pages = [
        'edit-teacher' => 'hst_edit_teachers',
        'edit-student' => 'hst_edit_students',
        'avatar-review' => 'hst_avatar_review',
    ];

    foreach ($obsolete_pages as $slug => $shortcode) {
        $page = get_page_by_path($slug, OBJECT, 'page');
        if (!$page instanceof WP_Post) {
            continue;
        }

        $content = trim((string) $page->post_content);
        $exact = '[' . $shortcode . ']';
        if ($content === $exact) {
            wp_delete_post((int) $page->ID, true);
            continue;
        }

        if (has_shortcode($content, $shortcode)) {
            wp_update_post([
                'ID'           => (int) $page->ID,
                'post_content' => trim(str_replace($exact, '', $content)),
            ]);
        }
    }

    update_option('hst-cleanup-schema', $cleanup_version, false);
}
add_action('init', 'hst_run_legacy_cleanup', 1);
