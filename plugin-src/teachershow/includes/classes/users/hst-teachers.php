<?php

defined('ABSPATH') || exit;

/**
 * Teacher management (CRUD + teacher-scoped data) extracted from the former
 * monolithic HST_Users so each user role has a focused class.
 */
class HST_Teachers
{
    use HST_User_Ajax_Authorization;

    public function __construct()
    {
        add_action('wp_ajax_hst_add_teacher', [$this, 'hst_add_teacher']);
        add_action('wp_ajax_hst_delete_teacher', [$this, 'hst_delete_teacher']);
        add_action('wp_ajax_hst_update_teacher', [$this, 'hst_update_teacher']);
        add_action('wp_ajax_hst_get_teacher_details', [$this, 'hst_get_teacher_details']);
    }

    /**
     * Returns a teacher's full profile for the view / edit modals.
     */
    public function hst_get_teacher_details()
    {
        $this->authorize_ajax();

        $teacher_id = absint($_POST['teacher_id'] ?? 0);
        $user = $teacher_id ? get_userdata($teacher_id) : null;
        if (!$user || !in_array('teacher', (array) $user->roles, true)) {
            HST_Guard::fail('معلم یافت نشد.');
        }

        $phone = class_exists('HST_User_Phones')
            ? HST_User_Phones::get($teacher_id)
            : (string) get_user_meta($teacher_id, 'phone', true);

        $avatar_id = absint(get_user_meta($teacher_id, 'hst_profile_avatar_id', true));
        if (!$avatar_id && class_exists('HST_Avatar_Approval')) {
            $avatar_id = (int) HST_Avatar_Approval::display_avatar_id($teacher_id, $teacher_id);
        }
        $avatar_url = $avatar_id ? wp_get_attachment_image_url($avatar_id, 'thumbnail') : '';

        $first = (string) get_user_meta($teacher_id, 'first_name', true);
        $last = (string) get_user_meta($teacher_id, 'last_name', true);

        global $wpdb;
        $active_term_id = (int) HST_Terms::active_id();
        $classes = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT c.class_name FROM {$wpdb->prefix}hst_users_classes uc
             INNER JOIN {$wpdb->prefix}hst_classes c ON c.id = uc.class_id
             WHERE uc.user_id = %d AND uc.role = 'teacher' AND uc.term_id = %d",
            $teacher_id,
            $active_term_id
        ));
        $classes = HST_Classes::sort_names((array) $classes);
        $lessons = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT l.lesson_name FROM {$wpdb->prefix}hst_users_lessons ul
             INNER JOIN {$wpdb->prefix}hst_lessons l ON l.id = ul.lesson_id
             WHERE ul.user_id = %d AND ul.role = 'teacher' AND ul.term_id = %d",
            $teacher_id,
            $active_term_id
        ));

        wp_send_json_success([
            'id'             => $teacher_id,
            'display_name'   => $user->display_name,
            'first_name'     => $first,
            'last_name'      => $last,
            'phone'          => $phone,
            'national_code'  => (string) get_user_meta($teacher_id, 'hst_national_code', true),
            'personnel_code' => (string) get_user_meta($teacher_id, 'hst_personnel_code', true),
            'birthdate'      => (string) get_user_meta($teacher_id, 'hst_birthdate', true),
            'avatar_url'     => $avatar_url,
            'classes'        => array_values(array_filter((array) $classes)),
            'lessons'        => array_values(array_filter((array) $lessons)),
        ]);
    }

    /**
     * All teachers with their classes and lessons (comma-joined). Read API used
     * by the teachers list page instead of querying from the renderer.
     *
     * @return array
     */
    public static function list_with_relations()
    {
        global $wpdb;
        $active_term_id = HST_Terms::active_id();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.ID,
                        u.display_name,
                        GROUP_CONCAT(DISTINCT c.class_name) as classes,
                        GROUP_CONCAT(DISTINCT l.lesson_name) as lessons
                FROM {$wpdb->prefix}users u
                INNER JOIN {$wpdb->usermeta} rolemeta
                    ON rolemeta.user_id = u.ID
                    AND rolemeta.meta_key = %s
                    AND rolemeta.meta_value LIKE %s
                LEFT JOIN {$wpdb->prefix}hst_users_classes uc
                    ON u.ID = uc.user_id
                    AND uc.role = 'teacher'
                    AND uc.term_id = %d
                LEFT JOIN {$wpdb->prefix}hst_classes c ON uc.class_id = c.id
                LEFT JOIN {$wpdb->prefix}hst_users_lessons ul
                    ON u.ID = ul.user_id
                    AND ul.role = 'teacher'
                    AND ul.term_id = %d
                LEFT JOIN {$wpdb->prefix}hst_lessons l ON ul.lesson_id = l.id
                GROUP BY u.ID
                ORDER BY u.display_name ASC",
                $wpdb->prefix . 'capabilities',
                '%"teacher"%',
                $active_term_id,
                $active_term_id
            )
        ) ?: [];

        foreach ($rows as $row) {
            $class_names = preg_split('/\s*,\s*/u', (string) ($row->classes ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $row->classes = implode(', ', HST_Classes::sort_names($class_names));
        }

        return $rows;
    }



public function hst_add_teacher()
    {
        $this->authorize_ajax();

        $first_name = HST_Guard::post_text('teacher_name');
        $last_name = HST_Guard::post_text('teacher_last_name');
        $phone = HST_Guard::normalize_mobile(HST_Guard::post_text('teacher_phone'));
        $national_code = HST_Guard::post_text('teacher_national_code');
        $personnel_code = isset($_POST['teacher_personnel_code'])
            ? preg_replace('/[^0-9]/', '', (string) wp_unslash($_POST['teacher_personnel_code']))
            : '';
        $birthdate = HST_Guard::post_text('teacher_birthdate');

        if (!$first_name || !$last_name) {
            HST_Guard::fail('نام و نام خانوادگی الزامی است.');
        }

        if (!HST_Guard::is_valid_iran_mobile($phone)) {
            HST_Guard::fail('شماره موبایل باید با 09 شروع شود و 11 رقم باشد.');
        }

        if (!class_exists('HST_User_Phones') || HST_User_Phones::normalize_national_code($national_code) === '') {
            HST_Guard::fail('کد ملی باید دقیقاً 10 رقم باشد.');
        }

        $national_code = HST_User_Phones::normalize_national_code($national_code);

        if ($personnel_code === '' || $birthdate === '') {
            HST_Guard::fail('کد ملی، کد پرسنلی و تاریخ تولد الزامی است.');
        }

        if (username_exists($national_code)) {
            HST_Guard::fail('این کد ملی قبلاً به‌عنوان نام کاربری ثبت شده است.');
        }

        if (HST_User_Phones::owner($phone)) {
            HST_Guard::fail('این شماره موبایل قبلاً برای کاربر دیگری ثبت شده است.');
        }

        $password = wp_generate_password(12, true, true);
        $user_id = wp_insert_user([
            'user_login'   => $national_code,
            'user_pass'    => $password,
            'role'         => 'teacher',
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim($first_name . ' ' . $last_name),
        ]);

        if (is_wp_error($user_id)) {
            HST_Guard::fail('خطا در ساخت کاربر: ' . $user_id->get_error_message());
        }

        $phone_result = HST_User_Phones::set((int) $user_id, $phone);
        if (is_wp_error($phone_result)) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user_id);
            HST_Guard::fail($phone_result->get_error_message());
        }

        update_user_meta($user_id, 'hst_birthdate', $birthdate);
        update_user_meta($user_id, 'hst_national_code', $national_code);
        update_user_meta($user_id, 'hst_personnel_code', $personnel_code);

        wp_send_json_success([
            'message'  => 'معلم با موفقیت ثبت شد. تخصیص کلاس، درس و واحد را از صفحه برنامه هفتگی انجام دهید.',
            'username' => $national_code,
            'password' => $password,
        ]);
    }



    public function hst_delete_teacher()
    {
        $this->authorize_ajax();

        $user_id = HST_Guard::post_int('id');

        if (!$user_id) {
            HST_Guard::fail('شناسه نامعتبر است.');
        }

        if ((int) get_current_user_id() === $user_id) {
            HST_Guard::fail('امکان حذف حساب کاربری فعلی وجود ندارد.');
        }

        $user = get_userdata($user_id);
        if (!$user || !in_array('teacher', (array) $user->roles, true)) {
            HST_Guard::fail('کاربر انتخاب‌شده معلم نیست یا وجود ندارد.');
        }

        global $wpdb;

        $wpdb->delete($wpdb->prefix . 'hst_users_classes', ['user_id' => $user_id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'hst_users_lessons', ['user_id' => $user_id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'hst_users_availability', ['user_id' => $user_id], ['%d']);

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user_id);

        wp_send_json_success(['message' => 'معلم با موفقیت حذف شد.']);
    }


    public function hst_update_teacher()
    {
        $this->authorize_ajax();

        $user_id = HST_Guard::post_int('id');
        $first_name = HST_Guard::post_text('teacher_name');
        $last_name = HST_Guard::post_text('teacher_last_name');
        $phone = HST_Guard::normalize_mobile(HST_Guard::post_text('teacher_phone'));
        $national_code = HST_Guard::post_text('teacher_national_code');
        $birthdate = HST_Guard::post_text('teacher_birthdate');
        $personnel_code = isset($_POST['teacher_personnel_code'])
            ? preg_replace('/[^0-9]/', '', (string) wp_unslash($_POST['teacher_personnel_code']))
            : '';
        $biography = isset($_POST['teacher_bio'])
            ? sanitize_textarea_field(wp_unslash($_POST['teacher_bio']))
            : '';

        if (!$user_id || !$first_name || !$last_name) {
            HST_Guard::fail('اطلاعات معلم ناقص است.');
        }

        $user = get_userdata($user_id);
        if (!$user || !in_array('teacher', (array) $user->roles, true)) {
            HST_Guard::fail('معلم یافت نشد.');
        }

        if (!HST_Guard::is_valid_iran_mobile($phone)) {
            HST_Guard::fail('شماره موبایل باید با 09 شروع شود و 11 رقم باشد.');
        }

        $national_code = class_exists('HST_User_Phones')
            ? HST_User_Phones::normalize_national_code($national_code)
            : '';
        if ($national_code === '') {
            HST_Guard::fail('کد ملی باید دقیقاً 10 رقم باشد.');
        }

        if ($personnel_code === '' || $birthdate === '') {
            HST_Guard::fail('کد ملی، کد پرسنلی و تاریخ تولد الزامی است.');
        }

        $phone_owner = HST_User_Phones::owner($phone);
        if ($phone_owner && $phone_owner !== $user_id) {
            HST_Guard::fail('این شماره موبایل قبلاً برای کاربر دیگری ثبت شده است.');
        }

        $identity_result = HST_User_Phones::sync_username($user_id, $national_code);
        if (is_wp_error($identity_result)) {
            HST_Guard::fail($identity_result->get_error_message());
        }

        $phone_result = HST_User_Phones::set($user_id, $phone);
        if (is_wp_error($phone_result)) {
            HST_Guard::fail($phone_result->get_error_message());
        }

        $updated = wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim($first_name . ' ' . $last_name),
        ]);
        if (is_wp_error($updated)) {
            HST_Guard::fail($updated->get_error_message());
        }

        update_user_meta($user_id, 'hst_teacher_bio', $biography);
        update_user_meta($user_id, 'hst_birthdate', $birthdate);
        update_user_meta($user_id, 'hst_national_code', $national_code);
        update_user_meta($user_id, 'hst_personnel_code', $personnel_code);

        // Assignment rows are intentionally untouched here. The weekly schedule
        // page is the single source of truth for teacher classes, lessons, units,
        // and availability.
        wp_send_json_success([
            'message' => 'اطلاعات معلم بروزرسانی شد.',
        ]);
    }



}
