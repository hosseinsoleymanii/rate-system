<?php

defined('ABSPATH') || exit;

class HST_Tables
{
    private const DB_VERSION = '1.12.0';

    public function __construct()
    {
        add_action('init', [$this, 'maybe_upgrade']);
    }

    public function maybe_upgrade()
    {
        if (get_option('hst_db_version') === self::DB_VERSION) {
            return;
        }

        self::hst_activate();
        update_option('hst_db_version', self::DB_VERSION);
    }

    public static function hst_activate()
    {
        $instance = new self();
        $instance->hst_classes_table();
        $instance->hst_terms_table();
        $instance->hst_lessons_table();
        $instance->hst_users_classes_table();
        $instance->hst_users_lessons_table();
        $instance->hst_users_availability_table();
        $instance->hst_schedules_table();
        $instance->hst_score_periods_table();
        $instance->hst_monthly_scores_table();
        $instance->hst_gradebook_table();
        $instance->hst_score_entry_access_table();
        $instance->hst_tuition_plans_table();
        $instance->hst_tuition_invoices_table();
        $instance->hst_notifications_table();
        $instance->hst_notification_reads_table();
        $instance->hst_assignments_table();
        $instance->hst_assignment_submissions_table();
        $instance->hst_attendance_records_table();
        $instance->hst_discipline_table();
        $instance->hst_exams_table();
        $instance->hst_exam_questions_table();
        $instance->hst_exam_question_items_table();
        $instance->hst_exam_attempts_table();

        // Register PWA endpoints then flush so /hst-pwa-manifest.json and
        // /hst-pwa-sw.js resolve immediately after activation.
        if (class_exists('HST_PWA')) {
            (new HST_PWA())->add_rewrite();
        }
        flush_rewrite_rules();
    }

    private function run_schema($sql)
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * dbDelta may try to add an already-existing UNIQUE index during upgrades.
     * Keep table creation idempotent and add unique indexes only when missing.
     */
    private function index_exists($table, $index_name)
    {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index_name)
        );
    }

    private function ensure_unique_index($table, $index_name, $columns)
    {
        global $wpdb;

        if ($this->index_exists($table, $index_name)) {
            return;
        }

        $wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index_name}` ({$columns})");
    }

    private function drop_index_if_exists($table, $index_name)
    {
        global $wpdb;

        if (!$this->index_exists($table, $index_name)) {
            return;
        }

        $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `{$index_name}`");
    }

    public function hst_classes_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_classes';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            class_name varchar(255) NOT NULL,
            class_slug varchar(191) NOT NULL,
            PRIMARY KEY  (id),
            KEY class_name (class_name(191))
        ) {$charset};");

        $this->ensure_unique_index($table, 'class_slug', '`class_slug`');
    }

    public function hst_terms_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_terms';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            term_name varchar(50) NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) {$charset};");

        $this->ensure_unique_index($table, 'term_name', '`term_name`');
    }

    public function hst_lessons_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_lessons';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            lesson_name varchar(255) NOT NULL,
            class_id mediumint(9) NOT NULL,
            unit int NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            KEY class_id (class_id)
        ) {$charset};");

        $this->ensure_unique_index($table, 'lesson_class', '`lesson_name`(191), `class_id`');
    }

    public function hst_users_classes_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_users_classes';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            class_id mediumint(9) NOT NULL,
            term_id mediumint(9) NOT NULL,
            role enum('student','teacher') NOT NULL DEFAULT 'student',
            enrollment_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_term (user_id, term_id),
            KEY class_id (class_id),
            KEY term_id (term_id)
        ) {$charset};");

        $this->ensure_unique_index($table, 'user_class_term_role', '`user_id`, `class_id`, `term_id`, `role`');
    }

    public function hst_users_lessons_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_users_lessons';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            class_id mediumint(9) NOT NULL,
            term_id mediumint(9) NOT NULL,
            lesson_id mediumint(9) NOT NULL,
            lesson_unit int NOT NULL DEFAULT 1,
            role enum('student','teacher') NOT NULL DEFAULT 'student',
            enrollment_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_term (user_id, term_id),
            KEY lesson_id (lesson_id),
            KEY class_id (class_id),
            KEY term_id (term_id),
            KEY role_lesson_term (role, lesson_id, term_id)
        ) {$charset};");
    }

    public function hst_users_availability_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_users_availability';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            term_id mediumint(9) NOT NULL,
            day_of_week enum('saturday','sunday','monday','tuesday','wednesday') NOT NULL,
            school_shift tinyint(1) NOT NULL,
            role enum('student','teacher') NOT NULL DEFAULT 'teacher',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY term_id (term_id)
        ) {$charset};");

        $this->ensure_unique_index($table, 'user_term_day_shift_role', '`user_id`, `term_id`, `day_of_week`, `school_shift`, `role`');
    }

    public function hst_schedules_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_schedules';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id mediumint(9) NOT NULL,
            class_id mediumint(9) NOT NULL,
            day_of_week enum('saturday','sunday','monday','tuesday','wednesday') NOT NULL,
            school_shift tinyint(1) NOT NULL,
            lesson_id mediumint(9) NOT NULL,
            teacher_id bigint(20) unsigned NOT NULL,
            week_type enum('every','odd','even') NOT NULL DEFAULT 'every',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY class_term (class_id, term_id),
            KEY teacher_term_time (teacher_id, term_id, day_of_week, school_shift),
            KEY lesson_id (lesson_id),
            KEY term_id (term_id)
        ) {$charset};");
    }

    public function hst_score_periods_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_score_periods';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id mediumint(9) NOT NULL,
            period_key varchar(64) NOT NULL,
            period_name varchar(120) NOT NULL,
            period_type varchar(32) NOT NULL DEFAULT 'custom',
            score_count smallint(5) unsigned NOT NULL DEFAULT 1,
            start_date varchar(32) NOT NULL DEFAULT '',
            end_date varchar(32) NOT NULL DEFAULT '',
            deadline_date varchar(32) NOT NULL DEFAULT '',
            description text NULL,
            is_active tinyint(1) NOT NULL DEFAULT 0,
            sort_order int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY term_active (term_id, is_active),
            KEY period_type (period_type)
        ) {$charset};");

        $this->ensure_unique_index($table, 'term_period_key', '`term_id`, `period_key`');

        $wpdb->query(
            "UPDATE `{$table}` SET score_count = CASE
                WHEN period_type = 'first_shift' THEN 2
                WHEN period_type = 'second_shift' THEN 4
                WHEN period_type IN ('weekly', 'monthly') THEN 1
                WHEN score_count < 1 THEN 1
                WHEN score_count > 20 THEN 20
                ELSE score_count
             END"
        );
    }


    public function hst_monthly_scores_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_monthly_scores';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id mediumint(9) NOT NULL,
            class_id mediumint(9) NOT NULL,
            lesson_id mediumint(9) NOT NULL,
            teacher_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            month_key varchar(32) NOT NULL,
            score_key varchar(64) NOT NULL DEFAULT 'score_1',
            score decimal(5,2) NULL DEFAULT NULL,
            is_present tinyint(1) NOT NULL DEFAULT 1,
            absence_excused tinyint(1) NULL DEFAULT NULL,
            teacher_created_at datetime NULL DEFAULT NULL,
            teacher_updated_at datetime NULL DEFAULT NULL,
            admin_created_at datetime NULL DEFAULT NULL,
            admin_updated_at datetime NULL DEFAULT NULL,
            description text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY student_term (student_id, term_id),
            KEY teacher_term (teacher_id, term_id),
            KEY class_lesson_term (class_id, lesson_id, term_id),
            KEY period_score (term_id, month_key, score_key)
        ) {$charset};");

        // The legacy unique key allowed only one score per student and period.
        // Replace it with a slot-aware key while preserving all existing rows as score_1.
        $this->drop_index_if_exists($table, 'score_scope');
        $this->ensure_unique_index($table, 'score_scope_v2', '`term_id`, `class_id`, `lesson_id`, `teacher_id`, `student_id`, `month_key`, `score_key`');

        // Preserve legacy single-score rows by assigning them to the first
        // logical slot of their period type.
        $periods_table = $wpdb->prefix . 'hst_score_periods';
        if ($this->index_exists($table, 'score_scope_v2')) {
            $wpdb->query(
                "UPDATE `{$table}` ms
                 INNER JOIN `{$periods_table}` sp
                    ON sp.term_id = ms.term_id AND sp.period_key = ms.month_key
                 SET ms.score_key = CASE
                    WHEN sp.period_type = 'first_shift' THEN 'continuous_1'
                    WHEN sp.period_type = 'second_shift' THEN 'continuous_2'
                    ELSE 'score_1'
                 END
                 WHERE ms.score_key = 'score_1'"
            );
        }
    }

    public function hst_gradebook_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_gradebook';
        $charset = $wpdb->get_charset_collate();

        // A teacher's working gradebook: up to several scores per student per
        // score period (e.g. quizzes), each with an optional title. The average of a
        // student's scores for a period is suggested when entering the monthly
        // (report-card) score.
        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id mediumint(9) NOT NULL,
            class_id mediumint(9) NOT NULL,
            lesson_id mediumint(9) NOT NULL,
            teacher_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            month_key varchar(32) NOT NULL,
            title varchar(120) NULL,
            score decimal(5,2) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY gb_scope (term_id, class_id, lesson_id, teacher_id, month_key),
            KEY gb_student (student_id, term_id, month_key)
        ) {$charset};");
    }



    public function hst_score_entry_access_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_score_entry_access';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id mediumint(9) NOT NULL,
            class_id mediumint(9) NOT NULL,
            lesson_id mediumint(9) NOT NULL,
            teacher_id bigint(20) unsigned NOT NULL,
            period_key varchar(64) NOT NULL,
            is_enabled tinyint(1) NOT NULL DEFAULT 1,
            locked_at datetime NULL DEFAULT NULL,
            unlocked_at datetime NULL DEFAULT NULL,
            lock_source varchar(32) NULL DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY teacher_term (teacher_id, term_id),
            KEY class_lesson_term (class_id, lesson_id, term_id),
            KEY period_key (period_key),
            KEY is_enabled (is_enabled)
        ) {$charset};");

        $this->ensure_unique_index($table, 'score_entry_access_scope', '`term_id`, `class_id`, `lesson_id`, `teacher_id`, `period_key`');
    }

    public function hst_tuition_plans_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_tuition_plans';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id mediumint(9) NOT NULL,
            class_id mediumint(9) NOT NULL DEFAULT 0,
            title varchar(191) NOT NULL,
            description text NULL,
            amount decimal(12,2) NOT NULL DEFAULT 0,
            due_date varchar(20) NOT NULL DEFAULT '',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            source varchar(16) NOT NULL DEFAULT 'manual',
            sms_enabled tinyint(1) NOT NULL DEFAULT 0,
            sms_message longtext NULL,
            sms_sent_at datetime NULL DEFAULT NULL,
            sms_result longtext NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY term_id (term_id),
            KEY class_id (class_id),
            KEY is_active (is_active),
            KEY sms_enabled (sms_enabled)
        ) {$charset};");
    }

    public function hst_tuition_invoices_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_tuition_invoices';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            plan_id bigint(20) unsigned NOT NULL,
            term_id mediumint(9) NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            amount decimal(12,2) NOT NULL DEFAULT 0,
            status enum('pending','paid','cancelled','overdue') NOT NULL DEFAULT 'pending',
            wc_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            paid_at datetime NULL DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY student_term (student_id, term_id),
            KEY plan_id (plan_id),
            KEY wc_order_id (wc_order_id),
            KEY status (status)
        ) {$charset};");

        $this->ensure_unique_index($table, 'plan_student', '`plan_id`, `student_id`');
    }


    public function hst_notifications_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_notifications';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(191) NOT NULL,
            message longtext NOT NULL,
            notice_type varchar(32) NOT NULL DEFAULT 'info',
            audience varchar(32) NOT NULL DEFAULT 'all',
            role_targets text NULL,
            class_targets text NULL,
            user_targets text NULL,
            link_url varchar(255) NOT NULL DEFAULT '',
            publish_at datetime NULL DEFAULT NULL,
            expire_at datetime NULL DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            source varchar(16) NOT NULL DEFAULT 'manual',
            sms_enabled tinyint(1) NOT NULL DEFAULT 0,
            sms_message longtext NULL,
            sms_sent_at datetime NULL DEFAULT NULL,
            sms_result longtext NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY is_active (is_active),
            KEY audience (audience),
            KEY source (source),
            KEY sms_enabled (sms_enabled),
            KEY publish_at (publish_at),
            KEY expire_at (expire_at)
        ) {$charset};");
    }

    public function hst_notification_reads_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_notification_reads';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            notification_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            read_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY notification_id (notification_id)
        ) {$charset};");

        $this->ensure_unique_index($table, 'notice_user', '`notification_id`, `user_id`');
    }


    public function hst_assignments_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_assignments';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id mediumint(9) NOT NULL,
            class_id mediumint(9) NOT NULL,
            lesson_id mediumint(9) NOT NULL,
            teacher_id bigint(20) unsigned NOT NULL,
            title varchar(191) NOT NULL,
            description longtext NULL,
            due_at datetime NULL DEFAULT NULL,
            status enum('draft','published','closed') NOT NULL DEFAULT 'published',
            max_file_size int NOT NULL DEFAULT 10,
            allowed_types varchar(191) NOT NULL DEFAULT 'pdf,doc,docx,ppt,pptx,jpg,jpeg,png,zip',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY teacher_term (teacher_id, term_id),
            KEY class_lesson_term (class_id, lesson_id, term_id),
            KEY status (status),
            KEY due_at (due_at)
        ) {$charset};");
    }

    public function hst_assignment_submissions_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_assignment_submissions';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            assignment_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            file_url varchar(255) NOT NULL DEFAULT '',
            file_path varchar(255) NOT NULL DEFAULT '',
            original_name varchar(191) NOT NULL DEFAULT '',
            status enum('submitted','reviewed','needs_revision') NOT NULL DEFAULT 'submitted',
            teacher_note text NULL,
            score decimal(5,2) NULL DEFAULT NULL,
            submitted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY student_id (student_id),
            KEY assignment_id (assignment_id),
            KEY status (status)
        ) {$charset};");

        $this->ensure_unique_index($table, 'assignment_student', '`assignment_id`, `student_id`');
    }

    public function hst_attendance_records_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_attendance_records';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id mediumint(9) NOT NULL,
            class_id mediumint(9) NOT NULL,
            lesson_id mediumint(9) NOT NULL,
            teacher_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            attendance_date date NOT NULL,
            school_shift tinyint(1) NOT NULL,
            status enum('present','absent','late','excused') NOT NULL DEFAULT 'present',
            late_minutes smallint(5) unsigned NOT NULL DEFAULT 0,
            note text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY teacher_term_date (teacher_id, term_id, attendance_date),
            KEY student_term_date (student_id, term_id, attendance_date),
            KEY class_lesson_term (class_id, lesson_id, term_id),
            KEY status (status)
        ) {$charset};");

        $this->ensure_unique_index($table, 'attendance_scope', '`term_id`, `class_id`, `lesson_id`, `teacher_id`, `student_id`, `attendance_date`, `school_shift`');
    }

    public function hst_discipline_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_discipline';
        $charset = $wpdb->get_charset_collate();

        // School-wide discipline log. Records include violations, warnings,
        // praise, absences and late arrivals. Parents can be notified by SMS; we store
        // whether/when that happened so the manager sees notification status.
        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            student_id bigint(20) unsigned NOT NULL,
            term_id mediumint(9) NOT NULL DEFAULT 0,
            type enum('violation','warning','praise','absence','late') NOT NULL DEFAULT 'violation',
            severity enum('low','medium','high') NOT NULL DEFAULT 'medium',
            title varchar(160) NOT NULL,
            description text NULL,
            incident_date date NULL,
            recorded_by bigint(20) unsigned NOT NULL,
            parent_notified tinyint(1) NOT NULL DEFAULT 0,
            sms_enabled tinyint(1) NOT NULL DEFAULT 0,
            sms_message longtext NULL,
            notified_at datetime NULL DEFAULT NULL,
            sms_result longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY student (student_id),
            KEY type (type),
            KEY sms_enabled (sms_enabled),
            KEY incident_date (incident_date)
        ) {$charset};");
    }

    public function hst_exams_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_exams';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id mediumint(9) NOT NULL,
            class_id mediumint(9) NOT NULL,
            lesson_id mediumint(9) NOT NULL,
            teacher_id bigint(20) unsigned NOT NULL,
            title varchar(191) NOT NULL,
            description longtext NULL,
            exam_date date NOT NULL,
            day_of_week enum('saturday','sunday','monday','tuesday','wednesday','thursday','friday') NOT NULL,
            school_shift tinyint(1) NOT NULL DEFAULT 1,
            duration_minutes smallint(4) NOT NULL DEFAULT 45,
            location varchar(191) NOT NULL DEFAULT '',
            grade varchar(64) NOT NULL DEFAULT '',
            major varchar(120) NOT NULL DEFAULT '',
            exam_type varchar(64) NOT NULL DEFAULT '',
            delivery_mode enum('in_person','online') NOT NULL DEFAULT 'in_person',
            question_count smallint(5) unsigned NOT NULL DEFAULT 0,
            start_date date NULL DEFAULT NULL,
            end_date date NULL DEFAULT NULL,
            start_time time NULL DEFAULT NULL,
            end_time time NULL DEFAULT NULL,
            attempt_limit tinyint(3) unsigned NOT NULL DEFAULT 1,
            result_visibility enum('after_submit','after_end','manual') NOT NULL DEFAULT 'after_end',
            randomize_questions tinyint(1) NOT NULL DEFAULT 0,
            randomize_options tinyint(1) NOT NULL DEFAULT 0,
            record_exit_time tinyint(1) NOT NULL DEFAULT 0,
            ip_restriction tinyint(1) NOT NULL DEFAULT 0,
            ai_review tinyint(1) NOT NULL DEFAULT 0,
            status enum('scheduled','done','cancelled') NOT NULL DEFAULT 'scheduled',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY teacher_term (teacher_id, term_id),
            KEY class_term_date (class_id, term_id, exam_date),
            KEY lesson_id (lesson_id),
            KEY exam_date (exam_date),
            KEY status (status)
        ) {$charset};");
    }

    public function hst_exam_questions_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_exam_questions';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id mediumint(9) NOT NULL,
            class_id mediumint(9) NOT NULL,
            lesson_id mediumint(9) NOT NULL,
            created_by bigint(20) unsigned NOT NULL,
            grade varchar(64) NOT NULL DEFAULT '',
            major varchar(120) NOT NULL DEFAULT '',
            curriculum_subject varchar(100) NOT NULL DEFAULT '',
            curriculum_chapter varchar(50) NOT NULL DEFAULT '',
            curriculum_topics longtext NULL,
            question_type varchar(32) NOT NULL,
            difficulty varchar(32) NOT NULL,
            score decimal(6,2) NOT NULL DEFAULT 1,
            question_text longtext NOT NULL,
            answer_data longtext NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY term_lesson (term_id, lesson_id),
            KEY curriculum_scope (curriculum_subject, curriculum_chapter),
            KEY class_id (class_id),
            KEY question_type (question_type),
            KEY difficulty (difficulty),
            KEY created_by (created_by)
        ) {$charset};");
    }

    public function hst_exam_question_items_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_exam_question_items';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            exam_id bigint(20) unsigned NOT NULL,
            question_id bigint(20) unsigned NOT NULL,
            sort_order int NOT NULL DEFAULT 0,
            score decimal(6,2) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY exam_id (exam_id),
            KEY question_id (question_id)
        ) {$charset};");

        $this->ensure_unique_index($table, 'exam_question', '`exam_id`, `question_id`');
    }

    public function hst_exam_attempts_table()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'hst_exam_attempts';
        $charset = $wpdb->get_charset_collate();

        $this->run_schema("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            exam_id bigint(20) unsigned NOT NULL,
            term_id mediumint(9) NOT NULL,
            class_id mediumint(9) NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            attempt_number tinyint(3) unsigned NOT NULL DEFAULT 1,
            status enum('in_progress','submitted','expired') NOT NULL DEFAULT 'in_progress',
            question_order longtext NULL,
            option_orders longtext NULL,
            answers longtext NULL,
            score decimal(8,2) NULL DEFAULT NULL,
            max_score decimal(8,2) NOT NULL DEFAULT 0,
            manual_pending smallint(5) unsigned NOT NULL DEFAULT 0,
            ip_hash char(64) NOT NULL DEFAULT '',
            exit_count smallint(5) unsigned NOT NULL DEFAULT 0,
            exit_log longtext NULL,
            started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_activity_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            submitted_at datetime NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY exam_status (exam_id, status),
            KEY student_term (student_id, term_id),
            KEY student_exam (student_id, exam_id)
        ) {$charset};");

        $this->ensure_unique_index($table, 'exam_student_attempt', '`exam_id`, `student_id`, `attempt_number`');
    }

}
