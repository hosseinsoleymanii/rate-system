<?php

defined('ABSPATH') || exit;

spl_autoload_register(static function ($class_name) {
    if (strpos($class_name, 'HST_') !== 0) {
        return;
    }

    // Map each class to its domain subfolder and file name.
    // Structure mirrors the templates/ directory for better maintainability.
    $class_map = [
        // core (infrastructure)
        'HST_Settings'      => 'core/hst-settings',
        'HST_Roles'         => 'core/hst-roles',
        'HST_Shortcodes'    => 'core/hst-shortcodes',
        'HST_Scripts'       => 'core/hst-scripts',
        'HST_PWA'           => 'core/hst-pwa',
        'HST_Tables'        => 'core/hst-tables',
        'HST_Stats'         => 'core/hst-stats',
        'HST_Date'          => 'core/hst-date',
        'HST_SMS'           => 'core/hst-sms',
        'HST_Print_Document'=> 'core/hst-print-document',
        // academic
        'HST_Lessons'       => 'academic/hst-lessons',
        'HST_Classes'       => 'academic/hst-classes',
        'HST_Terms'         => 'academic/hst-terms',
        'HST_Term_Transfer' => 'academic/hst-term-transfer',
        // auth
        'HST_Otp_Login'     => 'auth/hst-otp-login',
        'HST_Guard'         => 'auth/hst-guard',
        // communication
        'HST_Notifications' => 'communication/hst-notifications',
        'HST_Notify'        => 'communication/hst-notify',
        'HST_Birthday'      => 'communication/hst-birthday',
        'HST_Assignments'   => 'communication/hst-assignments',
        // finance
        'HST_Tuition'       => 'finance/hst-tuition',
        // schedules
        'HST_Schedules'     => 'schedules/hst-schedules',
        'HST_Schedule_PDF'  => 'schedules/hst-schedule-pdf',
        // scores
        'HST_Scores'        => 'scores/hst-scores',
        // tools
        'HST_Attendance'    => 'tools/hst-attendance',
        'HST_Discipline'    => 'tools/hst-discipline',
        'HST_Backup'        => 'tools/hst-backup',
        'HST_Exams'         => 'tools/hst-exams',
        'HST_Exam_Curriculum'=> 'tools/hst-exam-curriculum',
        'HST_Exam_Questions'=> 'tools/hst-exam-questions',
        'HST_Economics_Lesson1_Question_Seeds' => 'tools/hst-economics-lesson1-question-seeds',
        'HST_Media_Lesson1_Question_Seeds' => 'tools/hst-media-lesson1-question-seeds',
        // users
        'HST_User_Ajax_Authorization' => 'users/hst-user-ajax-authorization',
        'HST_User_Phones'     => 'users/hst-user-phones',
        'HST_Teachers'      => 'users/hst-teachers',
        'HST_Students'      => 'users/hst-students',
        'HST_User_Import'    => 'users/hst-user-import',
        'HST_Profile'       => 'users/hst-profile',
        'HST_Avatar_Approval' => 'users/hst-avatar-approval',
        'HST_Report_Cards' => 'users/hst-report-cards',
    ];

    if (!isset($class_map[$class_name])) {
        return;
    }

    $file_path = trailingslashit(__DIR__) . 'classes/' . $class_map[$class_name] . '.php';

    if (is_readable($file_path)) {
        require_once $file_path;
    }
});
