<?php

defined('ABSPATH') || exit;

/**
 * Central role + screen-access map for TeacherShow.
 *
 * The full manager (modir / administrator / hst_manage_school) can access every
 * management screen. The two vice-principal roles are restricted to a specific
 * subset, enforced both in the dashboard menu and in each screen's render
 * method via HST_Roles::can_access_screen().
 */
class HST_Roles
{
    const VICE_EDU  = 'hst_vice_edu';
    const VICE_EXEC = 'hst_vice_exec';

    /**
     * Screens each vice-principal role is allowed to open. Screen keys match the
     * identifiers used by the shortcode render methods / dashboard menu.
     *
     * @return array<string,string[]>
     */
    public static function vice_screens(): array
    {
        return [
            self::VICE_EDU => [
                'profile', 'discipline', 'exams', 'notifications', 'schedules',
            ],
            self::VICE_EXEC => [
                'profile', 'classes', 'lessons', 'terms', 'teachers', 'students',
                'import_users', 'term_transfer', 'periods', 'score_audit',
                'notifications', 'report_cards',
            ],
        ];
    }

    /** Is the current user a full manager (sees everything)? */
    public static function is_full_manager(): bool
    {
        if (current_user_can('manage_options') || current_user_can('hst_manage_school')) {
            // A vice-principal also has hst_manage_school for AJAX purposes, so
            // exclude them here: full manager means modir/admin, not a vice.
            if (self::current_vice_role() === '') {
                return true;
            }
        }
        $user = wp_get_current_user();
        return $user && in_array('modir', (array) $user->roles, true);
    }

    /** Returns the vice role of the current user, or '' if none. */
    public static function current_vice_role(): string
    {
        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return '';
        }
        $roles = (array) $user->roles;
        if (in_array(self::VICE_EDU, $roles, true)) {
            return self::VICE_EDU;
        }
        if (in_array(self::VICE_EXEC, $roles, true)) {
            return self::VICE_EXEC;
        }
        return '';
    }

    /**
     * Can the current user access a given management screen?
     * Full managers: always. Vice-principals: only their mapped screens.
     */
    public static function can_access_screen(string $screen): bool
    {
        if (self::is_full_manager()) {
            return true;
        }
        $vice = self::current_vice_role();
        if ($vice === '') {
            return false;
        }
        $map = self::vice_screens();
        return in_array($screen, $map[$vice] ?? [], true);
    }

    /** Human label for a role key. */
    public static function role_label(string $role): string
    {
        $labels = [
            'modir'         => 'مدیر مدرسه',
            self::VICE_EDU  => 'معاون آموزشی',
            self::VICE_EXEC => 'معاون اجرایی',
            'teacher'       => 'معلم',
            'student'       => 'دانش‌آموز',
        ];
        return $labels[$role] ?? $role;
    }
}
