<?php
/**
 * Shared user-avatar renderer for the header, people lists, tables and cards.
 * The owner and school managers use the same shared avatar editor provided by
 * hst-header.php.
 */
defined('ABSPATH') || exit;



if (!function_exists('hst_user_initials')) {
    /**
     * Return the first letter of the first name and the first letter of the
     * last name. Falls back to the first/last word of the supplied display
     * name when user meta is unavailable.
     *
     * @param int|WP_User $user User id or WP_User instance.
     */
    function hst_user_initials($user, string $name = ''): string
    {
        $user_id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;
        $first_name = '';
        $last_name = '';

        if ($user_id > 0) {
            $first_name = trim((string) get_user_meta($user_id, 'first_name', true));
            $last_name = trim((string) get_user_meta($user_id, 'last_name', true));
        }

        $first_char = static function (string $value): string {
            $value = trim($value);
            if ($value === '') {
                return '';
            }

            return function_exists('mb_substr')
                ? mb_substr($value, 0, 1, 'UTF-8')
                : substr($value, 0, 1);
        };

        $display_name = trim($name);
        if ($display_name === '' && is_object($user)) {
            $display_name = trim((string) ($user->display_name ?? ''));
        }

        $parts = preg_split('/\s+/u', $display_name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first_initial = $first_char($first_name) ?: $first_char((string) ($parts[0] ?? ''));
        $last_initial = $first_char($last_name);
        if ($last_initial === '' && count($parts) >= 2) {
            $last_initial = $first_char((string) $parts[count($parts) - 1]);
        }

        $initials = array_values(array_filter(
            [$first_initial, $last_initial],
            static function (string $initial): bool {
                return $initial !== '';
            }
        ));

        return $initials ? implode("\u{00A0}", $initials) : '؟';
    }
}

if (!function_exists('hst_can_manage_user_avatars')) {
    function hst_can_manage_user_avatars(): bool
    {
        return is_user_logged_in()
            && (current_user_can('manage_options') || current_user_can('hst_manage_school'));
    }
}

if (!function_exists('hst_user_avatar')) {
    /**
     * @param int|WP_User $user User id or WP_User instance.
     * @param string      $name Display name used for accessible labels.
     * @param int         $size Avatar size in pixels.
     * @param bool|null   $editable Force editable state for the owner/header.
     */
    function hst_user_avatar($user, string $name = '', int $size = 32, $editable = null): string
    {
        $user_id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;
        $size = max(24, min(96, absint($size)));

        if ($user_id <= 0) {
            return hst_user_avatar_placeholder($name, $size);
        }

        if (class_exists('HST_Avatar_Approval')) {
            $attachment_id = (int) HST_Avatar_Approval::display_avatar_id($user_id, get_current_user_id());
        } else {
            $attachment_id = absint(get_user_meta($user_id, 'hst_profile_avatar_id', true));
        }

        $url = $attachment_id ? (string) wp_get_attachment_image_url($attachment_id, 'thumbnail') : '';
        $label = $name !== '' ? $name : 'کاربر';
        $initials = hst_user_initials($user, $label);
        $style = sprintf('--hst-avatar-size:%dpx', $size);
        $has_image = $url !== '';
        $can_edit = hst_can_manage_user_avatars();
        if (is_bool($editable)) {
            $can_edit = $editable
                && is_user_logged_in()
                && ($user_id === get_current_user_id() || hst_can_manage_user_avatars());
        }

        if ($can_edit) {
            $content = $has_image
                ? sprintf(
                    '<img src="%s" alt="%s" loading="lazy" data-hst-avatar-img-for="%d">',
                    esc_url($url),
                    esc_attr('تصویر پروفایل ' . $label),
                    $user_id
                )
                : sprintf(
                    '<span class="hst-user-avatar__placeholder" data-hst-avatar-placeholder-for="%d">%s</span>',
                    $user_id,
                    esc_html($initials)
                );

            return sprintf(
                '<button type="button" class="hst-user-avatar hst-user-avatar--editable%s" style="%s" data-hst-avatar-open-for="%d" title="ویرایش تصویر پروفایل" aria-label="ویرایش تصویر پروفایل %s">%s</button>',
                $has_image ? '' : ' hst-user-avatar--placeholder',
                esc_attr($style),
                $user_id,
                esc_attr($label),
                $content
            );
        }

        if ($has_image) {
            return sprintf(
                '<span class="hst-user-avatar" style="%s"><img src="%s" alt="%s" loading="lazy"></span>',
                esc_attr($style),
                esc_url($url),
                esc_attr('تصویر پروفایل ' . $label)
            );
        }

        return hst_user_avatar_placeholder($label, $size, $user);
    }
}

if (!function_exists('hst_user_avatar_placeholder')) {
    function hst_user_avatar_placeholder(string $name = '', int $size = 32, $user = 0): string
    {
        $size = max(24, min(96, absint($size)));

        return sprintf(
            '<span class="hst-user-avatar hst-user-avatar--placeholder" style="--hst-avatar-size:%dpx" title="%s"><span class="hst-user-avatar__placeholder">%s</span></span>',
            $size,
            esc_attr($name),
            esc_html(hst_user_initials($user, $name))
        );
    }
}

if (!function_exists('hst_user_cell')) {
    function hst_user_cell($user, string $name, int $size = 32): string
    {
        return '<span class="hst-user-id">'
            . hst_user_avatar($user, $name, $size)
            . '<span class="hst-user-id__name">' . esc_html($name) . '</span>'
            . '</span>';
    }
}
