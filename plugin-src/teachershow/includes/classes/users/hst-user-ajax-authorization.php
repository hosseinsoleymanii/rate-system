<?php

defined('ABSPATH') || exit;

/**
 * Shared AJAX authorization helpers for the user-domain classes
 * (HST_Teachers, HST_Students, HST_Profile). Extracted from the former
 * monolithic HST_Users so each class stays focused on its own concern while
 * reusing one consistent gate.
 */
trait HST_User_Ajax_Authorization
{
    /** Manager-level AJAX gate (delegates to the shared guard). */
    private function authorize_ajax()
    {
        HST_Guard::verify_ajax('hst_manage_school');
    }

    /** Logged-in AJAX gate: valid nonce + authenticated user. */
    private function authorize_logged_ajax()
    {
        check_ajax_referer('hst_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'برای انجام این عملیات باید وارد حساب کاربری شوید.'], 401);
        }
    }

    /** Normalize a posted list of ids into a clean array of positive ints. */
}
