<?php
/**
 * Shared security and normalization helpers for TeacherShow.
 *
 * @package TeacherShow
 */

defined('ABSPATH') || exit;

class HST_Guard
{
    public static function verify_ajax(string $capability = 'hst_manage_school'): void
    {
        check_ajax_referer('hst_nonce', 'nonce');

        if (current_user_can('manage_options')) {
            return;
        }

        if ($capability && current_user_can($capability)) {
            return;
        }

        wp_send_json_error(['message' => 'دسترسی غیرمجاز است.'], 403);
    }

    public static function post_text(string $key, string $default = ''): string
    {
        return sanitize_text_field(wp_unslash($_POST[$key] ?? $default));
    }

    public static function post_textarea(string $key, string $default = ''): string
    {
        return sanitize_textarea_field(wp_unslash($_POST[$key] ?? $default));
    }

    public static function post_int(string $key, int $default = 0): int
    {
        return absint(wp_unslash($_POST[$key] ?? $default));
    }

    public static function post_id_list(string $key): array
    {
        $items = wp_unslash($_POST[$key] ?? []);
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('absint', $items))));
    }

    public static function normalize_mobile(string $value): string
    {
        if (class_exists('HST_User_Phones')) {
            return HST_User_Phones::normalize_phone($value);
        }

        $phone = preg_replace('/[^0-9]/', '', $value);
        return is_string($phone) ? $phone : '';
    }

    public static function is_valid_iran_mobile(string $phone): bool
    {
        return (bool) preg_match('/^09\d{9}$/', $phone);
    }

    public static function fail(string $message, int $status_code = 400): void
    {
        wp_send_json_error(['message' => $message], $status_code);
    }
}
