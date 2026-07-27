<?php

defined('ABSPATH') || exit;

/**
 * Current-user profile actions (own profile, password, avatar) extracted
 * from the former monolithic HST_Users.
 */
class HST_Profile
{
    use HST_User_Ajax_Authorization;

    public function __construct()
    {
        add_action('wp_ajax_hst_update_own_password', [$this, 'hst_update_own_password']);
        add_action('wp_ajax_hst_update_own_profile', [$this, 'hst_update_own_profile']);
        add_action('wp_ajax_hst_update_profile_avatar', [$this, 'hst_update_profile_avatar']);
        add_action('template_redirect', [$this, 'enforce_password_change']);
        add_filter('pre_get_avatar_data', [$this, 'hst_pre_get_avatar_data'], 20, 2);
        add_filter('get_avatar_url', [$this, 'hst_filter_avatar_url'], 20, 3);
    }

    public static function default_avatar_url(): string
    {
        // WordPress creates a 2x srcset for avatars. A data URI is stripped by
        // esc_url() in that srcset and leaves the browser with a relative "2x"
        // URL (for example /import-users/2x). Use a real same-origin asset.
        return HST_URL . 'assets/images/default-avatar.svg';
    }

    private function avatar_user_id($id_or_email): int
    {
        if (is_numeric($id_or_email)) {
            return absint($id_or_email);
        }

        if ($id_or_email instanceof WP_User) {
            return (int) $id_or_email->ID;
        }

        if ($id_or_email instanceof WP_Post) {
            return (int) $id_or_email->post_author;
        }

        if ($id_or_email instanceof WP_Comment) {
            if (!empty($id_or_email->user_id)) {
                return (int) $id_or_email->user_id;
            }
            if (!empty($id_or_email->comment_author_email)) {
                $user = get_user_by('email', $id_or_email->comment_author_email);
                return $user instanceof WP_User ? (int) $user->ID : 0;
            }
        }

        if (is_object($id_or_email)) {
            if (!empty($id_or_email->user_id)) {
                return absint($id_or_email->user_id);
            }
            if (!empty($id_or_email->ID)) {
                return absint($id_or_email->ID);
            }
            if (!empty($id_or_email->comment_author_email)) {
                $user = get_user_by('email', $id_or_email->comment_author_email);
                return $user instanceof WP_User ? (int) $user->ID : 0;
            }
        }

        if (is_string($id_or_email) && is_email($id_or_email)) {
            $user = get_user_by('email', $id_or_email);
            return $user instanceof WP_User ? (int) $user->ID : 0;
        }

        return 0;
    }

    public static function avatar_url_for_user(int $user_id, string $size = 'thumbnail', int $viewer_id = 0): string
    {
        $user_id = absint($user_id);
        if (!$user_id) {
            return self::default_avatar_url();
        }

        $attachment_id = 0;
        if (class_exists('HST_Avatar_Approval')) {
            $attachment_id = (int) HST_Avatar_Approval::display_avatar_id($user_id, $viewer_id ?: get_current_user_id());
        } else {
            $attachment_id = absint(get_user_meta($user_id, 'hst_profile_avatar_id', true));
        }

        if ($attachment_id) {
            $url = wp_get_attachment_image_url($attachment_id, $size);
            if (!$url && $size !== 'thumbnail') {
                $url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
            }
            if (!$url) {
                $url = wp_get_attachment_image_url($attachment_id, 'full');
            }
            if (!$url) {
                $url = wp_get_attachment_url($attachment_id);
            }
            if ($url) {
                return (string) $url;
            }
        }

        return self::default_avatar_url();
    }

    public function hst_pre_get_avatar_data($args, $id_or_email)
    {
        if (!is_array($args)) {
            $args = [];
        }

        $user_id = $this->avatar_user_id($id_or_email);
        $size = isset($args['size']) ? absint($args['size']) : 96;
        $image_size = $size > 96 ? 'medium' : 'thumbnail';

        $args['url'] = self::avatar_url_for_user($user_id, $image_size, get_current_user_id());
        $args['found_avatar'] = $user_id > 0 && $args['url'] !== self::default_avatar_url();

        return $args;
    }

    public function hst_filter_avatar_url($url, $id_or_email, $args)
    {
        $user_id = $this->avatar_user_id($id_or_email);
        $size = is_array($args) && isset($args['size']) ? absint($args['size']) : 96;
        $image_size = $size > 96 ? 'medium' : 'thumbnail';

        return self::avatar_url_for_user($user_id, $image_size, get_current_user_id());
    }

    /**
     * Users who recovered access via SMS are flagged to set a new password.
     * Until they do, send them to the profile page (where the password form
     * lives). Front-end only; never interferes with admin or AJAX.
     */
    public function enforce_password_change()
    {
        if (is_admin() || wp_doing_ajax() || !is_user_logged_in()) {
            return;
        }
        if (get_user_meta(get_current_user_id(), 'hst_force_password_change', true) !== '1') {
            return;
        }
        // Allow the profile page itself (and logout) so they can act.
        $profile_url = home_url('/profile');
        $current = home_url(add_query_arg([], $GLOBALS['wp']->request ?? ''));
        if (strpos($current, '/profile') !== false) {
            return;
        }
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            return;
        }
        wp_safe_redirect($profile_url);
        exit;
    }

    public function hst_update_own_profile()
    {
        $this->authorize_logged_ajax();

        $user_id = get_current_user_id();
        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last_name  = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));

        $first_name = trim(mb_substr($first_name, 0, 60));
        $last_name  = trim(mb_substr($last_name, 0, 60));

        if ($first_name === '' || $last_name === '') {
            wp_send_json_error(['message' => 'نام و نام خانوادگی را کامل وارد کنید.']);
        }

        $display_name = trim($first_name . ' ' . $last_name);

        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => $display_name,
        ]);

        // Teachers may set their own biography (shown to their students). Only
        // saved when the current user is actually a teacher.
        if (isset($_POST['teacher_bio'])) {
            $current = wp_get_current_user();
            $is_teacher = current_user_can('hst_teach') || ($current && in_array('teacher', (array) $current->roles, true));
            if ($is_teacher) {
                $bio = sanitize_textarea_field(wp_unslash($_POST['teacher_bio']));
                $bio = mb_substr($bio, 0, 1000);
                update_user_meta($user_id, 'hst_teacher_bio', $bio);
            }
        }

        wp_send_json_success([
            'message'      => 'اطلاعات حساب با موفقیت ذخیره شد.',
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => $display_name,
        ]);
    }

    public function hst_update_own_password()
    {
        $this->authorize_logged_ajax();

        $user = wp_get_current_user();
        $new_password = (string) ($_POST['new_password'] ?? '');
        $confirm_password = (string) ($_POST['confirm_password'] ?? '');

        if (!$new_password || !$confirm_password) {
            wp_send_json_error(['message' => 'لطفاً رمز عبور جدید و تکرار آن را وارد کنید.']);
        }

        if ($new_password !== $confirm_password) {
            wp_send_json_error(['message' => 'تکرار رمز عبور جدید با رمز جدید یکسان نیست.']);
        }

        if (strlen($new_password) < 8) {
            wp_send_json_error(['message' => 'رمز عبور جدید باید حداقل ۸ کاراکتر باشد.']);
        }

        wp_set_password($new_password, $user->ID);
        wp_set_auth_cookie($user->ID, true);
        delete_user_meta($user->ID, 'hst_force_password_change');

        wp_send_json_success(['message' => 'رمز عبور با موفقیت تغییر کرد.']);
    }

    public function hst_update_profile_avatar()
    {
        $this->authorize_logged_ajax();

        $current_user_id = get_current_user_id();
        $target_user_id = absint($_POST['target_user_id'] ?? 0);

        // Managers may update another user's avatar (e.g. from the teacher list).
        if ($target_user_id && $target_user_id !== $current_user_id) {
            if (!current_user_can('manage_options') && !current_user_can('hst_manage_school')) {
                wp_send_json_error(['message' => 'دسترسی غیرمجاز است.'], 403);
            }
            $user_id = $target_user_id;
        } else {
            $user_id = $current_user_id;
        }

        $image_data = (string) ($_POST['image'] ?? '');

        if (!$image_data || strpos($image_data, 'data:image/') !== 0) {
            wp_send_json_error(['message' => 'تصویر ارسال‌شده معتبر نیست.']);
        }

        if (!preg_match('/^data:image\/(png|jpe?g|webp);base64,/', $image_data, $matches)) {
            wp_send_json_error(['message' => 'فرمت تصویر باید JPG، PNG یا WEBP باشد.']);
        }

        $extension = strtolower($matches[1]);
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $image_data = substr($image_data, strpos($image_data, ',') + 1);
        $decoded = base64_decode($image_data);

        if (!$decoded) {
            wp_send_json_error(['message' => 'امکان پردازش تصویر وجود ندارد.']);
        }

        $max_size = 2 * 1024 * 1024;
        if (strlen($decoded) > $max_size) {
            wp_send_json_error(['message' => 'حجم تصویر بیش از حد مجاز است.']);
        }

        if (!function_exists('wp_upload_bits')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $filename = 'hst-avatar-' . $user_id . '-' . time() . '.' . $extension;
        $upload = wp_upload_bits($filename, null, $decoded);

        if (!empty($upload['error'])) {
            wp_send_json_error(['message' => 'آپلود تصویر انجام نشد: ' . $upload['error']]);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $editor = wp_get_image_editor($upload['file']);
        if (is_wp_error($editor)) {
            wp_delete_file($upload['file']);
            wp_send_json_error(['message' => 'پردازش تصویر انجام نشد.']);
        }

        $editor->resize(512, 512, true);
        $saved = $editor->save($upload['file'], 'image/jpeg');

        if (is_wp_error($saved) || empty($saved['path'])) {
            wp_delete_file($upload['file']);
            wp_send_json_error(['message' => 'ذخیره نسخه بهینه تصویر انجام نشد.']);
        }

        $upload['file'] = $saved['path'];
        $upload['url']  = str_replace(wp_normalize_path(wp_upload_dir()['basedir']), wp_upload_dir()['baseurl'], wp_normalize_path($saved['path']));
        $filename       = basename($saved['path']);
        $filetype       = wp_check_filetype($upload['file'], null);

        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
            'post_title'     => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => $user_id,
        ], $upload['file']);

        if (is_wp_error($attachment_id) || !$attachment_id) {
            wp_send_json_error(['message' => 'ذخیره تصویر انجام نشد.']);
        }

        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $metadata);

        $old_attachment_id = absint(get_user_meta($user_id, 'hst_profile_avatar_id', true));

        // A manager setting another user's avatar bypasses the approval queue.
        $is_manager_for_other = ($user_id !== $current_user_id);
        $module_on = !$is_manager_for_other && class_exists('HST_Avatar_Approval') && HST_Avatar_Approval::is_enabled();

        if ($module_on) {
            // Keep the previous (already-public) image so that, if a manager
            // rejects the new one, we can restore it. We do NOT switch the live
            // avatar yet — the owner sees the pending image via the module, but
            // the stored public avatar stays the old one until approval.
            HST_Avatar_Approval::set_pending_avatar($user_id, $attachment_id, $old_attachment_id);
            $pending = true;
        } else {
            // No approval workflow: replace immediately and clean up the old one.
            update_user_meta($user_id, 'hst_profile_avatar_id', $attachment_id);
            if (class_exists('HST_Avatar_Approval')) {
                HST_Avatar_Approval::on_avatar_uploaded($user_id);
            }
            if ($old_attachment_id && $old_attachment_id !== $attachment_id && get_post_field('post_author', $old_attachment_id) == $user_id) {
                wp_delete_attachment($old_attachment_id, true);
            }
            $pending = false;
        }

        $avatar_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
        if (!$avatar_url) {
            $avatar_url = $upload['url'];
        }

        wp_send_json_success([
            'message' => $pending
                ? 'تصویر پروفایل ارسال شد و پس از تأیید مدیر نمایش داده می‌شود.'
                : 'تصویر پروفایل با موفقیت ذخیره شد.',
            'avatar_url' => esc_url_raw($avatar_url),
            'pending' => $pending,
        ]);
    }
}
