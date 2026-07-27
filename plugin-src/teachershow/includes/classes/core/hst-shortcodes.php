<?php

defined('ABSPATH') || exit;

class HST_Shortcodes
{

    public function __construct()
    {
        add_shortcode('hst_dashboard', array($this, 'hst_render_dashboard'));
        add_shortcode('hst_home', array($this, 'hst_render_home'));
        add_shortcode('hst_login', array($this, 'hst_render_login'));
        add_shortcode('hst_classes', array($this, 'hst_render_classes'));
        add_shortcode('hst_lessons', array($this, 'hst_render_lessons'));
        add_shortcode('hst_terms', array($this, 'hst_render_terms'));
        add_shortcode('hst_teachers', array($this, 'hst_render_teachers'));
        add_shortcode('hst_students', array($this, 'hst_render_students'));
        add_shortcode('hst_schedules', array($this, 'hst_render_schedules'));
        add_shortcode('hst_my_schedule', array($this, 'hst_render_my_schedule'));
        add_shortcode('hst_profile', array($this, 'hst_render_profile'));
        add_shortcode('hst_periods', array($this, 'hst_render_periods'));
        add_shortcode('hst_enter_scores', array($this, 'hst_render_enter_scores'));
        add_shortcode('hst_gradebook', array($this, 'hst_render_gradebook'));
        add_shortcode('hst_scores', array($this, 'hst_render_scores'));
        add_shortcode('hst_score_audit', array($this, 'hst_render_score_audit'));
        add_shortcode('hst_tuition', array($this, 'hst_render_tuition'));
        add_shortcode('hst_tuition_payments', array($this, 'hst_render_tuition_payments'));
        add_shortcode('hst_notifications', array($this, 'hst_render_notifications'));
        add_shortcode('hst_import_users', array($this, 'hst_render_import_users'));
        add_shortcode('hst_discipline', array($this, 'hst_render_discipline'));
        add_shortcode('hst_term_transfer', array($this, 'hst_render_term_transfer'));
        add_shortcode('hst_backup', array($this, 'hst_render_backup'));
        add_shortcode('hst_plugin_settings', array($this, 'hst_render_plugin_settings'));
        add_shortcode('hst_assignments', array($this, 'hst_render_assignments'));
        add_shortcode('hst_attendance', array($this, 'hst_render_attendance'));
        add_shortcode('hst_exams', array($this, 'hst_render_exams'));
        add_shortcode('hst_my_teachers', array($this, 'hst_render_my_teachers'));
        add_shortcode('hst_report_cards', array($this, 'hst_render_report_cards'));
        add_shortcode('hst_smart_analysis', array($this, 'hst_render_smart_analysis'));
    }

    private function hst_user_has_role($role)
    {
        $user = wp_get_current_user();
        return $user && in_array($role, (array) $user->roles, true);
    }

    private function hst_is_manager()
    {
        return current_user_can('manage_options') || current_user_can('hst_manage_school') || $this->hst_user_has_role('modir');
    }

    private function hst_can_access($screen)
    {
        if (!is_user_logged_in()) {
            return false;
        }

        // Full managers see everything; vice-principals only their mapped
        // screens. (Vice roles also hold hst_manage_school for AJAX, so we must
        // route through HST_Roles instead of a bare capability check here.)
        if (class_exists('HST_Roles')) {
            $vice = HST_Roles::current_vice_role();
            if (HST_Roles::is_full_manager()) {
                return true;
            }
            if ($vice !== '') {
                // 'dashboard' is always available to any logged-in staff member.
                if ($screen === 'dashboard') {
                    return true;
                }
                return HST_Roles::can_access_screen($screen);
            }
        } elseif ($this->hst_is_manager()) {
            return true;
        }

        $is_teacher = current_user_can('hst_teach') || $this->hst_user_has_role('teacher');
        $is_student = current_user_can('hst_study') || $this->hst_user_has_role('student');

        if ($is_teacher) {
            return in_array($screen, [
                'dashboard',
                'profile',
                'students',
                'my_schedule',
                'enter_scores',
                'gradebook',
                'notifications',
                'assignments',
                'attendance',
                'exams',
            ], true);
        }

        if ($is_student) {
            return in_array($screen, [
                'dashboard',
                'profile',
                'scores',
                'my_schedule',
                'tuition_payments',
                'notifications',
                'assignments',
                'exams',
                'my_teachers',
            ], true);
        }

        return false;
    }

    private function hst_render_access_denied($message = '')
    {
        if (!is_user_logged_in()) {
            $current_url = home_url(add_query_arg([], wp_unslash($_SERVER['REQUEST_URI'] ?? '/')));
            $login_url = class_exists('HST_Settings') ? HST_Settings::login_page_url($current_url) : wp_login_url($current_url);

            if (!headers_sent()) {
                wp_safe_redirect($login_url);
                exit;
            }

            return '<script>window.location.href=' . wp_json_encode($login_url) . ';</script>'
                . '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($login_url) . '"></noscript>';
        }

        ob_start();
        $hst_access_message = $message ?: __('شما اجازه دسترسی به این بخش را ندارید.', 'teacher-show');
        $templatePath = HST_PATH . 'templates/user/common/hst-access-denied.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            echo '<p class="hst-notice hst-component hst-alert">' . esc_html($hst_access_message) . '</p>';
        }
        return ob_get_clean();
    }

    private function hst_require_access($screen, $message = '')
    {
        return $this->hst_can_access($screen) ? '' : $this->hst_render_access_denied($message);
    }


    private function hst_get_classes($limit = 0)
    {
        return HST_Classes::all($limit);
    }

    private function hst_get_lessons($limit = 0)
    {
        return HST_Lessons::all($limit);
    }

    private function hst_get_terms($limit = 0)
    {
        return HST_Terms::all($limit);
    }

    private function hst_get_terms_for_register($limit = 0)
    {
        return HST_Terms::active_all($limit);
    }

    private function hst_get_teachers()
    {
        return HST_Teachers::list_with_relations();
    }

    private function hst_get_students()
    {
        return HST_Students::list_for_viewer();
    }

    public function hst_render_home()
    {
        ob_start();
        $templatePath = HST_PATH . 'templates/user/home/hst-home.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

    public function hst_render_login()
    {
        ob_start();
        $templatePath = HST_PATH . 'templates/user/auth/hst-login.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }


    public function hst_render_dashboard()
    {
        $access_denied = $this->hst_require_access('dashboard');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();
        $templatePath = HST_PATH . 'templates/user/dashboard/hst-dashboard.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

    public function hst_render_classes()
    {
        $access_denied = $this->hst_require_access('classes');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();
        $classes = $this->hst_get_classes();
        $templatePath = HST_PATH . 'templates/user/academic/hst-classes.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }
    
    public function hst_render_lessons()
    {
        $access_denied = $this->hst_require_access('lessons');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();
        $classes = $this->hst_get_classes();
        $lessons = $this->hst_get_lessons();
        $templatePath = HST_PATH . 'templates/user/academic/hst-lessons.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

    public function hst_render_terms()
    {
        $access_denied = $this->hst_require_access('terms');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();
        $terms = $this->hst_get_terms();
        $templatePath = HST_PATH . 'templates/user/academic/hst-terms.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

    public function hst_render_teachers()
    {
        $access_denied = $this->hst_require_access('teachers');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();
        $teachers = $this->hst_get_teachers();
        $active_term_id = class_exists('HST_Terms') ? (int) HST_Terms::active_id() : 0;
        $teacher_schedule_ids = ($active_term_id && class_exists('HST_Schedules'))
            ? HST_Schedules::teacher_ids_with_saved_schedule($active_term_id)
            : [];
        $teacher_schedule_lookup = array_fill_keys($teacher_schedule_ids, true);
        $has_teacher_schedules = !empty($teacher_schedule_lookup);
        $templatePath = HST_PATH . 'templates/user/users/hst-teachers.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

    public function hst_render_students()
    {
        $access_denied = $this->hst_require_access('students');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();
        $classes = $this->hst_get_classes();
        $students = $this->hst_get_students();
        $terms = $this->hst_get_terms_for_register();
        $active_term_id = class_exists('HST_Terms') ? (int) HST_Terms::active_id() : 0;
        $templatePath = HST_PATH . 'templates/user/users/hst-students.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }


    public function hst_render_profile()
    {
        $access_denied = $this->hst_require_access('profile');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();

        $profile_user = wp_get_current_user();
        $active_term = $this->hst_get_active_term();

        $role = '';
        if ((current_user_can('hst_teach') || current_user_can('teacher'))) {
            $role = 'teacher';
        } elseif ((current_user_can('hst_study') || current_user_can('student'))) {
            $role = 'student';
        }

        $classes = [];
        if ($role && $active_term) {
            $classes = $this->hst_get_user_schedule_classes(
                (int) $profile_user->ID,
                $role,
                (int) $active_term->id
            );
        }

        $templatePath = HST_PATH . 'templates/user/users/hst-profile.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }

        return ob_get_clean();
    }

    private function hst_get_active_term()
    {
        return HST_Terms::active();
    }

    private function hst_get_user_schedule_classes($user_id, $role, $term_id)
    {
        return HST_Schedules::user_classes($user_id, $role, $term_id);
    }

    private function hst_get_class_saved_schedule($class_id, $term_id)
    {
        return HST_Schedules::class_saved_schedule($class_id, $term_id);
    }

    private function hst_build_schedule_grid(array $schedule_rows)
    {
        return HST_Schedules::build_grid($schedule_rows);
    }

    public function hst_render_my_schedule()
    {
        $access_denied = $this->hst_require_access('my_schedule');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();

        $current_user_id = get_current_user_id();
        $active_term = $this->hst_get_active_term();

        $days = class_exists('HST_Schedules') ? HST_Schedules::days() : [
            'saturday'  => 'شنبه',
            'sunday'    => 'یکشنبه',
            'monday'    => 'دوشنبه',
            'tuesday'   => 'سه‌شنبه',
            'wednesday' => 'چهارشنبه',
        ];

        $role = '';
        if ((current_user_can('hst_teach') || current_user_can('teacher'))) {
            $role = 'teacher';
        } elseif ((current_user_can('hst_study') || current_user_can('student'))) {
            $role = 'student';
        }

        $classes = [];
        $class_schedules = [];
        $teacher_grid = [];
        $teacher_has_slots = false;

        if ($role && $active_term) {
            // Teacher's own personal schedule across all classes (one grid).
            if ($role === 'teacher' && class_exists('HST_Schedules')) {
                $teacher_rows = HST_Schedules::teacher_saved_schedule($current_user_id, (int) $active_term->id);
                $teacher_has_slots = !empty($teacher_rows);
                $teacher_grid = HST_Schedules::build_grid($teacher_rows);
            }

            $classes = $this->hst_get_user_schedule_classes($current_user_id, $role, (int) $active_term->id);

            foreach ($classes as $class) {
                $rows = $this->hst_get_class_saved_schedule((int) $class->id, (int) $active_term->id);
                $class_schedules[(int) $class->id] = [
                    'class' => $class,
                    'rows'  => $rows,
                    'grid'  => $this->hst_build_schedule_grid($rows),
                ];
            }
        }

        $templatePath = HST_PATH . 'templates/user/schedules/hst-my-schedule.php';

        if (file_exists($templatePath)) {
            include $templatePath;
        }

        return ob_get_clean();
    }

    public function hst_render_schedules()
    {
        $access_denied = $this->hst_require_access('schedules');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();

        $classes = $this->hst_get_classes();
        $terms = $this->hst_get_terms_for_register();
        $days = class_exists('HST_Schedules') ? HST_Schedules::days() : [
            'saturday'  => 'شنبه',
            'sunday'    => 'یکشنبه',
            'monday'    => 'دوشنبه',
            'tuesday'   => 'سه‌شنبه',
            'wednesday' => 'چهارشنبه',
        ];

        $templatePath = HST_PATH . 'templates/user/schedules/hst-schedules.php';

        if (file_exists($templatePath)) {
            include $templatePath;
        }

        return ob_get_clean();
    }

    public function hst_render_periods()
    {
        $access_denied = $this->hst_require_access('periods');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();

        $score_service = class_exists('HST_Scores') ? new HST_Scores() : null;
        $active_term = $score_service ? $score_service->get_active_term() : null;
        $period_context = $score_service ? $score_service->get_score_periods_context() : [];

        $templatePath = HST_PATH . 'templates/user/scores/hst-periods.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }

        return ob_get_clean();
    }

    public function hst_render_enter_scores()
    {
        $access_denied = $this->hst_require_access('enter_scores');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();

        $score_service = class_exists('HST_Scores') ? new HST_Scores() : null;
        $active_term = $score_service ? $score_service->get_active_term() : null;
        $period_context = $score_service ? $score_service->get_score_periods_context() : [];

        $templatePath = HST_PATH . 'templates/user/scores/hst-enter-scores.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }

        return ob_get_clean();
    }


    public function hst_render_gradebook()
    {
        $access_denied = $this->hst_require_access('enter_scores');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();
        $score_service = class_exists('HST_Scores') ? new HST_Scores() : null;
        $active_term = $score_service ? $score_service->get_active_term() : null;
        $period_context = $score_service ? $score_service->get_score_periods_context() : [];

        $templatePath = HST_PATH . 'templates/user/scores/hst-gradebook.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }


    public function hst_render_scores()
    {
        $access_denied = $this->hst_require_access('scores');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();

        $score_service = class_exists('HST_Scores') ? new HST_Scores() : null;
        $score_context = $score_service ? $score_service->get_student_scores_context(
            get_current_user_id(),
            [
                'month_key' => isset($_GET['score_period']) ? sanitize_key(wp_unslash($_GET['score_period'])) : '',
                'lesson_id' => isset($_GET['score_lesson']) ? absint($_GET['score_lesson']) : 0,
                'class_id'  => isset($_GET['score_class']) ? absint($_GET['score_class']) : 0,
            ]
        ) : null;

        $templatePath = HST_PATH . 'templates/user/scores/hst-scores.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }

        return ob_get_clean();
    }


    public function hst_render_score_audit()
    {
        $access_denied = $this->hst_require_access('score_audit');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();

        $score_service = class_exists('HST_Scores') ? new HST_Scores() : null;
        $audit_context = $score_service ? $score_service->get_admin_score_audit_context([
            'month_key' => isset($_GET['audit_period']) ? sanitize_key(wp_unslash($_GET['audit_period'])) : '',
        ]) : null;

        $templatePath = HST_PATH . 'templates/user/scores/hst-score-audit.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }

        return ob_get_clean();
    }



    public function hst_render_tuition()
    {
        $access_denied = $this->hst_require_access('tuition');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();
        $tuition_service = class_exists('HST_Tuition') ? new HST_Tuition() : null;
        $tuition_context = $tuition_service ? $tuition_service->get_admin_context() : [];
        $templatePath = HST_PATH . 'templates/user/finance/hst-tuition.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

    public function hst_render_tuition_payments()
    {
        $access_denied = $this->hst_require_access('tuition_payments');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();
        $tuition_service = class_exists('HST_Tuition') ? new HST_Tuition() : null;
        $tuition_context = $tuition_service ? $tuition_service->get_student_context(get_current_user_id()) : [];
        $templatePath = HST_PATH . 'templates/user/finance/hst-tuition-payments.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }


    public function hst_render_notifications()
    {
        $access_denied = $this->hst_require_access('notifications');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();
        $notification_service = class_exists('HST_Notifications') ? new HST_Notifications() : null;
        $notification_context = $notification_service ? $notification_service->get_context() : [];
        $templatePath = HST_PATH . 'templates/user/communication/hst-notifications.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }


    public function hst_render_import_users()
    {
        if (!HST_Roles::can_access_screen('import_users')) {
            return $this->hst_render_access_denied('فقط مدیر مدرسه به انتقال از سیدا کاربران دسترسی دارد.');
        }

        ob_start();
        $templatePath = HST_PATH . 'templates/user/users/hst-import-users.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

    public function hst_render_discipline()
    {
        if (!HST_Roles::can_access_screen('discipline')) {
            return $this->hst_render_access_denied('فقط مدیر مدرسه به موارد انضباطی دسترسی دارد.');
        }

        ob_start();
        $templatePath = HST_PATH . 'templates/user/tools/hst-discipline.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

    public function hst_render_term_transfer()
    {
        if (!HST_Roles::can_access_screen('term_transfer')) {
            return $this->hst_render_access_denied('فقط مدیر مدرسه به انتقال سال تحصیلی دسترسی دارد.');
        }

        ob_start();
        $terms = class_exists('HST_Terms') ? HST_Terms::all() : [];
        $templatePath = HST_PATH . 'templates/user/academic/hst-term-transfer.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

    public function hst_render_backup()
    {
        if (!HST_Roles::is_full_manager()) {
            return $this->hst_render_access_denied('فقط مدیر مدرسه به پشتیبان‌گیری دسترسی دارد.');
        }

        ob_start();
        $templatePath = HST_PATH . 'templates/user/tools/hst-backup.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

    public function hst_render_plugin_settings()
    {
        if (!HST_Roles::is_full_manager()) {
            return $this->hst_render_access_denied('فقط مدیر مدرسه به تنظیمات افزونه دسترسی دارد.');
        }

        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        ob_start();
        $templatePath = HST_PATH . 'templates/user/settings/hst-plugin-settings.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

    public function hst_render_assignments()
    {
        $access_denied = $this->hst_require_access('assignments');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();
        $active_term = class_exists('HST_Assignments') ? HST_Assignments::active_term() : null;
        $is_teacher = current_user_can('hst_teach') || in_array('teacher', (array) wp_get_current_user()->roles, true);
        $is_student = current_user_can('hst_study') || in_array('student', (array) wp_get_current_user()->roles, true);
        $teacher_scope = [];
        $teacher_assignments = [];
        $student_assignments = [];

        if ($active_term && class_exists('HST_Assignments')) {
            if ($is_teacher || current_user_can('manage_options') || current_user_can('hst_manage_school')) {
                $teacher_scope = HST_Assignments::teacher_scope(get_current_user_id(), (int) $active_term->id);
                $teacher_assignments = HST_Assignments::teacher_assignments(get_current_user_id(), (int) $active_term->id);
            }
            if ($is_student) {
                $student_assignments = HST_Assignments::student_assignments(get_current_user_id(), (int) $active_term->id);
            }
        }

        $templatePath = HST_PATH . 'templates/user/communication/hst-assignments.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }





    public function hst_render_attendance()
    {
        $access_denied = $this->hst_require_access('attendance');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();

        $attendance_context = class_exists('HST_Attendance')
            ? HST_Attendance::get_teacher_context(get_current_user_id())
            : [];

        $templatePath = HST_PATH . 'templates/user/tools/hst-attendance.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }

        return ob_get_clean();
    }


    public function hst_render_exams()
    {
        $access_denied = $this->hst_require_access('exams');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();

        $exam_service = class_exists('HST_Exams') ? new HST_Exams() : null;
        $active_term = $exam_service ? $exam_service->active_term() : null;
        $current_roles = (array) wp_get_current_user()->roles;
        // Managers use the new exam builder; teachers keep the existing scheduling workflow.
        $is_manager = current_user_can('manage_options') || current_user_can('hst_manage_school');
        $is_teacher = !$is_manager && (current_user_can('hst_teach') || in_array('teacher', $current_roles, true));
        $is_student = !$is_manager && !$is_teacher && (current_user_can('hst_study') || in_array('student', $current_roles, true));
        // Teacher scope is limited to assignments; manager scope contains all classes and lessons.
        $teacher_scope = $exam_service && $active_term && $is_teacher ? $exam_service->teacher_scope(get_current_user_id(), (int) $active_term->id) : ['classes' => [], 'lessons' => []];
        $manager_scope = $exam_service && $active_term && $is_manager ? $exam_service->teacher_scope(get_current_user_id(), (int) $active_term->id) : ['classes' => [], 'lessons' => []];
        $teacher_exams = $exam_service && $active_term && $is_teacher ? $exam_service->teacher_exams(get_current_user_id(), (int) $active_term->id) : [];
        $student_exams = $exam_service && $active_term && $is_student ? $exam_service->student_exams(get_current_user_id(), (int) $active_term->id) : [];
        $manager_exams = $exam_service && $active_term && $is_manager ? $exam_service->all_exams((int) $active_term->id) : [];
        $exam_general_settings = $exam_service && $is_manager ? HST_Exams::general_settings() : HST_Exams::general_settings_defaults();
        $question_bank_service = class_exists('HST_Exam_Questions') ? new HST_Exam_Questions() : null;
        $question_bank_context = $question_bank_service && $active_term && $is_manager
            ? $question_bank_service->context((int) $active_term->id)
            : (class_exists('HST_Exam_Questions') ? HST_Exam_Questions::empty_context() : [
                'questions' => [], 'lessons' => [], 'exams' => [], 'types' => [], 'difficulties' => [],
                'stats' => ['total' => 0, 'easy_medium' => 0, 'advanced' => 0],
            ]);

        if ($is_manager && function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        $templatePath = HST_PATH . 'templates/user/tools/hst-exams.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }

        return ob_get_clean();
    }

    public function hst_render_my_teachers()
    {
        // Student-facing page.
        $access_denied = $this->hst_require_access('my_teachers');
        if ($access_denied) {
            return $access_denied;
        }

        ob_start();
        $teachers = class_exists('HST_Students')
            ? HST_Students::student_teachers(get_current_user_id())
            : [];
        $templatePath = HST_PATH . 'templates/user/users/hst-my-teachers.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

    public function hst_render_smart_analysis()
    {
        if (!HST_Roles::is_full_manager()) {
            return $this->hst_render_access_denied('فقط مدیر مدرسه به تحلیل هوشمند دسترسی دارد.');
        }

        ob_start();
        $templatePath = HST_PATH . 'templates/user/analysis/hst-smart-analysis.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

    public function hst_render_report_cards()
    {
        // Manager + معاون اجرایی.
        if (!HST_Roles::can_access_screen('report_cards')) {
            return $this->hst_render_access_denied('این صفحه فقط برای مدیر مدرسه در دسترس است.');
        }

        $active_term = HST_Report_Cards::active_term();
        $monthly_periods = $active_term
            ? HST_Report_Cards::monthly_issue_periods((int) $active_term->id)
            : [];
        $report_card_print_classes = $active_term
            ? HST_Report_Cards::print_classes((int) $active_term->id)
            : [];

        $report_card_section = '';
        if (isset($_GET['report_card_section'])) {
            $requested_section = sanitize_key(wp_unslash($_GET['report_card_section']));
            if ($requested_section === 'monthly') {
                $report_card_section = 'monthly';
            }
        }

        $report_card_period = '';
        if (isset($_GET['report_period'])) {
            $requested_period = sanitize_key(wp_unslash($_GET['report_period']));
            foreach ($monthly_periods as $available_period) {
                $available_key = sanitize_key((string) ($available_period->period_key ?? ''));
                $available_type = sanitize_key((string) ($available_period->period_type ?? ''));
                if ($available_key === $requested_period && in_array($available_type, ['weekly', 'monthly', 'custom'], true)) {
                    $report_card_period = $available_key;
                    $report_card_section = 'monthly';
                    break;
                }
            }
        }

        ob_start();
        $templatePath = HST_PATH . 'templates/user/users/hst-report-cards.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
        return ob_get_clean();
    }

}
