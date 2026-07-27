<?php

defined('ABSPATH') || exit;

/**
 * Profile-picture approval module.
 *
 * When enabled (setting hst-enable-avatar-approval), a newly uploaded profile
 * picture is held for review WITHOUT replacing the user's current public image.
 *  - The OWNER sees their newly uploaded (pending) image, badged "awaiting".
 *  - EVERYONE ELSE keeps seeing the previous approved image (or the placeholder
 *    if there wasn't one).
 *  - On APPROVE: the pending image becomes the public avatar; the old image is
 *    removed.
 *  - On REJECT: the pending image is deleted and the user keeps whatever they
 *    had before (previous image, or the themed placeholder if none).
 *
 * Disabling the module restores the original behaviour (immediate replace).
 *
 * Storage (user meta):
 *   hst_profile_avatar_id     the LIVE (public) attachment id
 *   hst_avatar_pending_id     the attachment id awaiting review (owner-only)
 *   hst_avatar_status         'approved' | 'pending' | 'rejected'
 *   hst_avatar_submitted_at   mysql datetime of the last upload
 *   hst_avatar_reviewed_by    user id of the manager who reviewed
 */
class HST_Avatar_Approval
{
    const OPTION = 'hst-enable-avatar-approval';
    const META_STATUS = 'hst_avatar_status';
    const META_AVATAR = 'hst_profile_avatar_id';
    const META_PENDING = 'hst_avatar_pending_id';
    const META_SUBMITTED = 'hst_avatar_submitted_at';
    const META_REVIEWED_BY = 'hst_avatar_reviewed_by';
    const META_NOTIFICATION = 'hst_avatar_notification_id';

    public function __construct()
    {
        add_action('wp_ajax_hst_avatar_review', [$this, 'ajax_review']);
        add_action('init', [$this, 'migrate_legacy_notifications'], 30);
    }

    /**
     * Move old avatar-review notifications to the notifications archive and
     * make still-pending requests visible in the header modal.
     */
    public function migrate_legacy_notifications(): void
    {
        if (get_option('hst-avatar-notifications-migrated-v260', '0') === '1') {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'hst_notifications';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, created_by FROM {$table} WHERE source = 'auto' AND title = %s ORDER BY id DESC",
            'تصویر پروفایل در انتظار تأیید'
        ));
        $latest_by_user = [];

        foreach ((array) $rows as $row) {
            $user_id = absint($row->created_by ?? 0);
            $is_latest = $user_id && empty($latest_by_user[$user_id]);
            $is_pending = $is_latest
                && self::status_for($user_id) === 'pending'
                && self::pending_avatar_id($user_id);

            $wpdb->update(
                $table,
                [
                    'link_url'  => home_url('/notifications/'),
                    'is_active' => $is_pending ? 1 : 0,
                ],
                ['id' => (int) $row->id],
                ['%s', '%d'],
                ['%d']
            );

            if ($is_latest) {
                $latest_by_user[$user_id] = (int) $row->id;
                update_user_meta($user_id, self::META_NOTIFICATION, (int) $row->id);
            }
        }

        update_option('hst-avatar-notifications-migrated-v260', '1', false);
    }

    /** Whether the approval workflow is switched on. */
    public static function is_enabled()
    {
        if (class_exists('HST_Settings')) {
            return HST_Settings::enabled(self::OPTION, '0');
        }
        return get_option(self::OPTION, '0') === '1';
    }

    /**
     * Store a freshly uploaded image as pending review, keeping the previous
     * public avatar intact. Called by the uploader when the module is on.
     *
     * @param int $user_id
     * @param int $new_attachment_id The just-uploaded image.
     * @param int $previous_attachment_id The current public avatar (kept).
     */
    public static function set_pending_avatar($user_id, $new_attachment_id, $previous_attachment_id = 0)
    {
        $user_id = absint($user_id);
        if (!$user_id) {
            return;
        }
        // If a different image was already pending, discard it (superseded).
        $existing_pending = absint(get_user_meta($user_id, self::META_PENDING, true));
        if ($existing_pending && $existing_pending !== absint($new_attachment_id) && get_post_field('post_author', $existing_pending) == $user_id) {
            wp_delete_attachment($existing_pending, true);
        }

        update_user_meta($user_id, self::META_PENDING, absint($new_attachment_id));
        update_user_meta($user_id, self::META_STATUS, 'pending');
        update_user_meta($user_id, self::META_SUBMITTED, current_time('mysql'));
        delete_user_meta($user_id, self::META_REVIEWED_BY);
        // Note: META_AVATAR (the public image) is intentionally left as the
        // previous image so others keep seeing it until approval.

        do_action('hst_avatar_pending', $user_id);
    }

    /**
     * Called when the module is OFF: the image is already live, just mark it
     * approved so status stays consistent.
     *
     * @param int $user_id
     */
    public static function on_avatar_uploaded($user_id)
    {
        $user_id = absint($user_id);
        if (!$user_id) {
            return;
        }
        update_user_meta($user_id, self::META_STATUS, 'approved');
        update_user_meta($user_id, self::META_SUBMITTED, current_time('mysql'));
        delete_user_meta($user_id, self::META_PENDING);
        delete_user_meta($user_id, self::META_REVIEWED_BY);
        delete_user_meta($user_id, self::META_NOTIFICATION);
    }

    /**
     * The avatar status for a user. Treats a missing value as 'approved' so
     * existing images (uploaded before the module existed) are not hidden.
     *
     * @param int $user_id
     * @return string
     */
    public static function status_for($user_id)
    {
        $status = get_user_meta(absint($user_id), self::META_STATUS, true);
        return $status ?: 'approved';
    }

    /** The pending (owner-only) attachment id, if any. */
    public static function pending_avatar_id($user_id)
    {
        return absint(get_user_meta(absint($user_id), self::META_PENDING, true));
    }

    /**
     * Whether a user's avatar may be shown to OTHER people. When the module is
     * off, always true. When on, only approved images are public.
     *
     * @param int $user_id
     * @return bool
     */
    public static function is_public($user_id)
    {
        // The stored public avatar (META_AVATAR) is always the approved image
        // under the new model — pending uploads live in META_PENDING and never
        // overwrite the public one until approved. So the public avatar is
        // always safe to show to others.
        return true;
    }

    /**
     * The attachment id to display for a given viewer:
     *  - the owner sees their pending image if one is awaiting review;
     *  - everyone else (and the owner with no pending image) sees the public,
     *    approved avatar.
     *
     * @param int $user_id   Whose avatar we are showing.
     * @param int $viewer_id The current viewer (0 = not the owner).
     * @return int Attachment id, or 0 for none (placeholder).
     */
    public static function display_avatar_id($user_id, $viewer_id = 0)
    {
        $user_id = absint($user_id);
        $is_owner = $viewer_id && (absint($viewer_id) === $user_id);

        if ($is_owner && self::is_enabled()) {
            $pending = self::pending_avatar_id($user_id);
            if ($pending) {
                return $pending;
            }
        }
        return absint(get_user_meta($user_id, self::META_AVATAR, true));
    }

    /** Capability gate: only school managers can review. */
    public static function can_current_user_review(): bool
    {
        return current_user_can('manage_options') || current_user_can('hst_manage_school');
    }

    /**
     * Detect automatic notifications created for a pending profile image.
     * Existing notifications from older builds are recognized through their
     * stable title, source and creator fields.
     */
    public static function is_review_notification($notice): bool
    {
        if (!is_object($notice)) {
            return false;
        }

        return ($notice->source ?? '') === 'auto'
            && ($notice->title ?? '') === 'تصویر پروفایل در انتظار تأیید'
            && absint($notice->created_by ?? 0) > 0;
    }

    /**
     * Build the action context consumed by the header notification modal and
     * the notifications page. It reuses existing avatar/button/modal classes.
     */
    public static function notification_context($notice): array
    {
        if (!self::can_current_user_review() || !self::is_review_notification($notice)) {
            return [];
        }

        $user_id = absint($notice->created_by ?? 0);
        $user = $user_id ? get_userdata($user_id) : false;
        if (!$user) {
            return [];
        }

        $notice_id = absint($notice->id ?? 0);
        $current_notice_id = absint(get_user_meta($user_id, self::META_NOTIFICATION, true));
        $is_current = $notice_id > 0 && $notice_id === $current_notice_id;
        $pending_id = self::pending_avatar_id($user_id);
        $status = self::status_for($user_id);
        $can_review = $is_current && $status === 'pending' && $pending_id > 0 && (int) ($notice->is_active ?? 0) === 1;
        $image_id = $pending_id ?: absint(get_user_meta($user_id, self::META_AVATAR, true));
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';

        $status_labels = [
            'pending'  => 'در انتظار بررسی',
            'approved' => 'تأیید شده',
            'rejected' => 'رد شده',
        ];
        $context_status = $is_current ? $status : 'superseded';
        $status_label = $is_current ? ($status_labels[$status] ?? 'بررسی شده') : 'جایگزین شده';

        return [
            'user_id'      => $user_id,
            'name'         => $user->display_name ?: $user->user_login,
            'role'         => self::role_label($user),
            'image_url'    => $image_url ?: '',
            'status'       => $context_status,
            'status_label' => $status_label,
            'can_review'   => $can_review,
        ];
    }

    private static function role_label($user): string
    {
        $map = [
            'administrator' => 'مدیر کل',
            'modir'         => 'مدیر',
            'hst_vice_edu'  => 'معاون آموزشی',
            'hst_vice_exec' => 'معاون اجرایی',
            'teacher'       => 'معلم',
            'student'       => 'دانش‌آموز',
            'hst_manager'   => 'مدیر',
            'hst_teacher'   => 'معلم',
            'hst_student'   => 'دانش‌آموز',
        ];
        foreach ((array) $user->roles as $role) {
            if (isset($map[$role])) {
                return $map[$role];
            }
        }
        return 'کاربر';
    }

    private static function validate_notification(int $notification_id, int $user_id): bool
    {
        if (!$notification_id || absint(get_user_meta($user_id, self::META_NOTIFICATION, true)) !== $notification_id) {
            return false;
        }

        global $wpdb;
        $notice = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hst_notifications WHERE id = %d",
            $notification_id
        ));

        return self::is_review_notification($notice)
            && absint($notice->created_by ?? 0) === $user_id
            && (int) ($notice->is_active ?? 0) === 1;
    }

    private static function deactivate_notification(int $notification_id): void
    {
        if (!$notification_id) {
            return;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hst_notifications',
            [
                'is_active' => 0,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $notification_id],
            ['%d', '%s'],
            ['%d']
        );
    }

    private static function mark_notification_read(int $notification_id): void
    {
        if (!$notification_id || !get_current_user_id()) {
            return;
        }

        global $wpdb;
        $wpdb->replace(
            $wpdb->prefix . 'hst_notification_reads',
            [
                'notification_id' => $notification_id,
                'user_id'         => get_current_user_id(),
                'read_at'         => current_time('mysql'),
            ],
            ['%d', '%d', '%s']
        );
    }

    /**
     * AJAX: approve or reject a pending avatar.
     *  - approve: pending image becomes the public avatar; old public image is
     *    deleted.
     *  - reject: pending image is deleted; the user keeps their previous public
     *    image (or the placeholder if they had none).
     */
    public function ajax_review()
    {
        check_ajax_referer('hst_nonce', 'nonce');
        if (!self::can_current_user_review()) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز'], 403);
        }

        $user_id = absint(wp_unslash($_POST['user_id'] ?? 0));
        $notification_id = absint(wp_unslash($_POST['notification_id'] ?? 0));
        $decision = sanitize_key(wp_unslash($_POST['decision'] ?? ''));

        if (!$user_id || !$notification_id || !in_array($decision, ['approve', 'reject'], true)) {
            wp_send_json_error(['message' => 'درخواست نامعتبر است.'], 400);
        }

        if (!self::validate_notification($notification_id, $user_id)) {
            wp_send_json_error(['message' => 'این اطلاعیه با درخواست تصویر انتخاب‌شده مطابقت ندارد.'], 400);
        }

        $pending_id = self::pending_avatar_id($user_id);
        if (!$pending_id || self::status_for($user_id) !== 'pending') {
            wp_send_json_error(['message' => 'این تصویر قبلاً بررسی شده یا دیگر در انتظار تأیید نیست.'], 409);
        }

        if ($decision === 'approve') {
            $old_public = absint(get_user_meta($user_id, self::META_AVATAR, true));
            update_user_meta($user_id, self::META_AVATAR, $pending_id);
            delete_user_meta($user_id, self::META_PENDING);
            update_user_meta($user_id, self::META_STATUS, 'approved');
            update_user_meta($user_id, self::META_REVIEWED_BY, get_current_user_id());

            if ($old_public && $old_public !== $pending_id && get_post_field('post_author', $old_public) == $user_id) {
                wp_delete_attachment($old_public, true);
            }

            self::deactivate_notification($notification_id);
            self::mark_notification_read($notification_id);
            do_action('hst_avatar_reviewed', $user_id, true);
            wp_send_json_success([
                'message'         => 'تصویر پروفایل تأیید شد.',
                'user_id'         => $user_id,
                'notification_id' => $notification_id,
                'status'          => 'approved',
                'status_label'    => 'تأیید شده',
            ]);
        }

        // Reject: delete only the pending image and keep the previous public one.
        if (get_post_field('post_author', $pending_id) == $user_id) {
            wp_delete_attachment($pending_id, true);
        }
        delete_user_meta($user_id, self::META_PENDING);
        update_user_meta($user_id, self::META_STATUS, 'rejected');
        update_user_meta($user_id, self::META_REVIEWED_BY, get_current_user_id());

        self::deactivate_notification($notification_id);
        self::mark_notification_read($notification_id);
        do_action('hst_avatar_reviewed', $user_id, false);
        wp_send_json_success([
            'message'         => 'تصویر رد شد؛ تصویر قبلی کاربر حفظ شد.',
            'user_id'         => $user_id,
            'notification_id' => $notification_id,
            'status'          => 'rejected',
            'status_label'    => 'رد شده',
        ]);
    }

}
