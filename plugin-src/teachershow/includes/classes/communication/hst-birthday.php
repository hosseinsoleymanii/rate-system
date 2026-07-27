<?php

defined('ABSPATH') || exit;

/**
 * Automatic birthday greetings for students.
 *
 * Runs once a day via WP-Cron. For every student whose stored Jalali birthdate
 * (user meta 'hst_birthdate') falls on today's month/day, it sends:
 *   - an SMS to the student (if birthday SMS is enabled and SMS is configured)
 *   - an in-app notification (default plugin behavior)
 *
 * A per-year guard meta prevents sending twice in the same year.
 */
class HST_Birthday
{
    public const SMS_OPTION      = 'hst-birthday-sms-enabled';
    public const TEMPLATE_OPTION = 'hst-birthday-template';
    public const BIRTHDATE_META  = 'hst_birthdate';

    private const CRON_HOOK   = 'hst_birthday_daily';
    private const SENT_META   = 'hst_birthday_last_year';

    public function __construct()
    {
        add_action(self::CRON_HOOK, [$this, 'run']);

        // Make sure the daily event is scheduled.
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'daily', self::CRON_HOOK);
        }
    }

    /** Clear the scheduled event (call on plugin deactivation). */
    public static function unschedule(): void
    {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    public static function default_template(): string
    {
        return '{name} عزیز، تولدت مبارک!  با آرزوی بهترین‌ها — {school}';
    }

    private static function sms_enabled(): bool
    {
        return get_option(self::SMS_OPTION, '0') === '1'
            && class_exists('HST_SMS') && HST_SMS::direct_ready();
    }

    private static function notify_enabled(): bool
    {
        return class_exists('HST_Notifications');
    }

    /**
     * Daily worker: find today's birthdays and greet them.
     */
    public function run(): void
    {
        $sms_on    = self::sms_enabled();
        $notify_on = self::notify_enabled();
        if (!$sms_on && !$notify_on) {
            return;
        }

        // Today's Jalali month/day (zero-padded, ASCII digits).
        $today = class_exists('HST_Date') ? HST_Date::today('Y/m/d') : '';
        $today = class_exists('HST_Date') ? HST_Date::en_digits($today) : $today;
        $parts = explode('/', $today);
        if (count($parts) !== 3) {
            return;
        }
        $today_year  = (int) $parts[0];
        $today_month = (int) $parts[1];
        $today_day   = (int) $parts[2];

        $recipients = get_users([
            'role__in' => ['student', 'teacher'],
            'fields'   => 'ID',
            'number'   => -1,
            'meta_key' => self::BIRTHDATE_META,
        ]);
        if (empty($recipients)) {
            return;
        }

        $school = class_exists('HST_Settings')
            ? (string) HST_Settings::option('hst-home-school-name', get_bloginfo('name'))
            : get_bloginfo('name');
        if (trim($school) === '') {
            $school = get_bloginfo('name') ?: 'مدرسه';
        }
        $template = trim((string) get_option(self::TEMPLATE_OPTION, ''));
        if ($template === '') {
            $template = self::default_template();
        }

        foreach ($recipients as $uid) {
            $uid = (int) $uid;
            $raw = (string) get_user_meta($uid, self::BIRTHDATE_META, true);
            if ($raw === '') {
                continue;
            }
            $b = class_exists('HST_Date') ? HST_Date::en_digits($raw) : $raw;
            $bp = preg_split('/[\/\-\.]/', trim($b));
            if (!$bp || count($bp) < 3) {
                continue;
            }
            $bmonth = (int) $bp[1];
            $bday   = (int) $bp[2];

            if ($bmonth !== $today_month || $bday !== $today_day) {
                continue;
            }

            // Skip if already greeted this Jalali year.
            if ((int) get_user_meta($uid, self::SENT_META, true) === $today_year) {
                continue;
            }

            $name = get_the_author_meta('display_name', $uid);
            if (trim((string) $name) === '') {
                $name = 'کاربر';
            }
            $notification_message = HST_SMS::render_message(self::default_template(), [
                'name'    => $name,
                'school'  => $school,
            ]);

            if ($sms_on) {
                $phone = HST_SMS::user_phone($uid);
                if ($phone) {
                    HST_SMS::send_birthday($phone, [
                        'name'         => $name,
                        'school'       => $school,
                        'sms_template' => $template,
                    ]);
                }
            }

            if ($notify_on) {
                HST_Notifications::create_notification([
                    'title'        => 'تبریک تولد ',
                    'message'      => $notification_message,
                    'notice_type'  => 'info',
                    'audience'     => 'users',
                    'source'       => 'auto',
                    'user_targets' => [$uid],
                    'created_by'   => 0,
                ]);
            }

            update_user_meta($uid, self::SENT_META, $today_year);
        }
    }
}
