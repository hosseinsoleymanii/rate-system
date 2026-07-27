<?php
/**
 * Plugin Name: تیچرشو
 * Plugin URI: https://nexoravp.xyz/shop/teachershow/
 * Description: سامانه جامع مدیریت مدارس
 * Version: 1.0.247
 * Author: نکسورا
 * Author URI: https://nexoravp.xyz/
 * Text Domain: teacher-show
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.0
 *
 * @package TeacherShow
 */

defined('ABSPATH') || exit;

if (!function_exists('hst_format_grade')) {
    function hst_format_grade($value, bool $localized = true): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $number = round((float) $value, 2);
        $decimals = abs($number - round($number)) < 0.00001
            ? 0
            : (abs(($number * 10) - round($number * 10)) < 0.00001 ? 1 : 2);

        return $localized
            ? number_format_i18n($number, $decimals)
            : number_format($number, $decimals, '.', '');
    }
}

if (!defined('HST_VERSION')) {
    define('HST_VERSION', '1.0.246');
}

if (!defined('HST_PATH')) {
    define('HST_PATH', plugin_dir_path(__FILE__));
}

if (!defined('HST_URL')) {
    define('HST_URL', plugin_dir_url(__FILE__));
}

$main_file_path = __FILE__;

require_once __DIR__ . '/includes/autoload.php';
require_once __DIR__ . '/includes/init.php';

initialize_hst_classes($main_file_path);

register_activation_hook($main_file_path, ['HST_Tables', 'hst_activate']);
register_activation_hook($main_file_path, ['HST_Settings', 'hst_activate_home_defaults']);
