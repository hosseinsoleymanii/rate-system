<?php

defined('ABSPATH') || exit;

/**
 * Centralized automatic notification generation.
 *
 * Listens to plugin events (via do_action hooks) and turns them into
 * notifications using the existing HST_Notifications::create_notification()
 * API. Keeping all the "when X happens, notify Y" logic here means:
 *   - event call-sites stay clean (they just fire a do_action)
 *   - notification copy/audience/category live in one place
 *   - new event types are added by registering one more handler
 *
 * Each handler chooses the audience (a class of students, a specific teacher,
 * managers, etc.), the category (notice_type), and a deep link.
 */
class HST_Notify
{
    public function __construct()
    {
        // New homework assigned → notify the class's students.
        add_action('hst_assignment_created', [$this, 'on_assignment_created'], 10, 2);

        // Student submitted a homework → notify the assignment's teacher.
        add_action('hst_assignment_submitted', [$this, 'on_assignment_submitted'], 10, 1);

        // New exam created → notify the class's students.
        add_action('hst_exam_created', [$this, 'on_exam_created'], 10, 1);

        // New monthly grade registered → notify the student.
        add_action('hst_grade_registered', [$this, 'on_grade_registered'], 10, 1);

        // New tuition invoice → notify the student.
        add_action('hst_tuition_invoice_created', [$this, 'on_tuition_created'], 10, 1);

        // Profile image events.
        add_action('hst_avatar_pending', [$this, 'on_avatar_pending'], 10, 1);
        add_action('hst_avatar_reviewed', [$this, 'on_avatar_reviewed'], 10, 2);
    }

    private static function can_notify()
    {
        return class_exists('HST_Notifications')
            && method_exists('HST_Notifications', 'create_notification');
    }

    /**
     * Whether a given automatic-notification type is enabled in settings.
     * Each type has its own option, defaulting to ON.
     */
    public static function type_enabled(string $type): bool
    {
        $option = 'hst-notify-' . $type;
        return get_option($option, '1') === '1';
    }

    /**
     * Create an action-requested reminder for a teacher to complete score entry.
     * This is intentionally a fresh notification on every deliberate manager
     * action, so a previously read reminder is never silently reused.
     */
    public static function send_score_entry_reminder(array $context)
    {
        if (!self::can_notify()) {
            return false;
        }

        $teacher_id = absint($context['teacher_id'] ?? 0);
        if (!$teacher_id || !get_userdata($teacher_id)) {
            return false;
        }

        $class_name = sanitize_text_field((string) ($context['class_name'] ?? ''));
        $lesson_name = sanitize_text_field((string) ($context['lesson_name'] ?? ''));
        $period_label = sanitize_text_field((string) ($context['period_label'] ?? ''));
        $expected = absint($context['expected'] ?? 0);
        $missing = absint($context['missing'] ?? 0);

        if ($class_name === '' || $lesson_name === '' || $period_label === '' || $expected <= 0 || $missing <= 0) {
            return false;
        }

        return HST_Notifications::create_notification([
            'title'        => 'یادآوری ثبت نمره',
            'message'      => sprintf(
                'لطفاً ثبت نمرات دوره «%1$s» برای درس «%2$s» در کلاس «%3$s» را تکمیل کنید. از %4$s نمره مورد انتظار، %5$s مورد هنوز ثبت نشده است.',
                $period_label,
                $lesson_name,
                $class_name,
                number_format_i18n($expected),
                number_format_i18n($missing)
            ),
            'notice_type'  => 'warning',
            'audience'     => 'users',
            'source'       => 'auto',
            'is_active'    => 1,
            'user_targets' => [$teacher_id],
            'link_url'     => home_url('/enter-scores/'),
            'created_by'   => absint($context['created_by'] ?? get_current_user_id()),
            'merge_auto'   => false,
        ]);
    }

    /** Resolve an assignment row for context. */
    private function assignment_row($assignment_id)
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT a.*, l.lesson_name, c.class_name
                 FROM {$wpdb->prefix}hst_assignments a
                 LEFT JOIN {$wpdb->prefix}hst_lessons l ON l.id = a.lesson_id
                 LEFT JOIN {$wpdb->prefix}hst_classes c ON c.id = a.class_id
                 WHERE a.id = %d",
                absint($assignment_id)
            )
        );
    }

    public function on_assignment_created($assignment_id, $teacher_id = 0)
    {
        if (!self::can_notify() || !self::type_enabled('assignment-created')) {
            return;
        }
        $row = $this->assignment_row($assignment_id);
        if (!$row || (int) $row->class_id <= 0) {
            return;
        }
        // Only published assignments should notify students.
        if (isset($row->status) && $row->status !== 'published') {
            return;
        }
        $lesson = $row->lesson_name ? ('درس ' . $row->lesson_name) : 'یکی از درس‌ها';
        HST_Notifications::create_notification([
            'title'         => 'تکلیف جدید',
            'message'       => sprintf('یک تکلیف جدید برای %s ثبت شد: %s', $lesson, $row->title),
            'notice_type'   => 'info',
            'audience'      => 'classes',
            'source'       => 'auto',
            'class_targets' => [(int) $row->class_id],
            'link_url'      => home_url('/assignments'),
            'created_by'    => absint($teacher_id) ?: (int) $row->teacher_id,
        ]);
    }

    public function on_assignment_submitted($context)
    {
        if (!self::can_notify() || !self::type_enabled('assignment-submitted') || !is_array($context)) {
            return;
        }
        $teacher_id = absint($context['teacher_id'] ?? 0);
        if (!$teacher_id) {
            return;
        }
        $student = get_userdata(absint($context['student_id'] ?? 0));
        $student_name = $student ? ($student->display_name ?: $student->user_login) : 'یک دانش‌آموز';
        $title = sanitize_text_field($context['title'] ?? '');
        $message = $title !== ''
            ? sprintf('%s پاسخ تکلیف «%s» را ارسال کرد و آماده بررسی است.', $student_name, $title)
            : sprintf('%s یک پاسخ تکلیف جدید ارسال کرد و آماده بررسی است.', $student_name);
        HST_Notifications::create_notification([
            'title'        => 'پاسخ تکلیف جدید',
            'message'      => $message,
            'notice_type'  => 'info',
            'audience'     => 'users',
            'source'       => 'auto',
            'user_targets' => [$teacher_id],
            'link_url'     => home_url('/assignments'),
            'created_by'   => absint($context['student_id'] ?? 0),
        ]);
    }

    public function on_exam_created($context)
    {
        if (!self::can_notify() || !self::type_enabled('exam-created') || !is_array($context)) {
            return;
        }
        $class_id = absint($context['class_id'] ?? 0);
        if (!$class_id) {
            return;
        }
        $title = sanitize_text_field($context['title'] ?? 'آزمون');
        HST_Notifications::create_notification([
            'title'         => 'آزمون جدید',
            'message'       => sprintf('آزمون جدیدی ثبت شد: %s', $title),
            'notice_type'   => 'info',
            'audience'      => 'classes',
            'source'       => 'auto',
            'class_targets' => [$class_id],
            'link_url'      => home_url('/exams'),
            'created_by'    => absint($context['teacher_id'] ?? 0),
        ]);
    }

    public function on_grade_registered($context)
    {
        if (!self::can_notify() || !self::type_enabled('grade-registered') || !is_array($context)) {
            return;
        }
        $student_id = absint($context['student_id'] ?? 0);
        if (!$student_id) {
            return;
        }
        $lesson = sanitize_text_field($context['lesson_name'] ?? '');
        $message = $lesson !== ''
            ? sprintf('نمره جدیدی برای درس %s برای شما ثبت شد.', $lesson)
            : 'نمره جدیدی برای شما ثبت شد.';
        HST_Notifications::create_notification([
            'title'        => 'ثبت نمره جدید',
            'message'      => $message,
            'notice_type'  => 'success',
            'audience'     => 'users',
            'source'       => 'auto',
            'user_targets' => [$student_id],
            'link_url'     => home_url('/scores'),
            'created_by'   => absint($context['teacher_id'] ?? 0),
        ]);
    }

    public function on_tuition_created($context)
    {
        if (!self::can_notify() || !self::type_enabled('tuition-created') || !is_array($context)) {
            return;
        }
        $student_id = absint($context['student_id'] ?? 0);
        if (!$student_id) {
            return;
        }
        HST_Notifications::create_notification([
            'title'        => 'صورتحساب شهریه جدید',
            'message'      => 'یک صورتحساب شهریه جدید برای شما ایجاد شد. برای مشاهده و پرداخت اقدام کنید.',
            'notice_type'  => 'warning',
            'audience'     => 'users',
            'source'       => 'auto',
            'user_targets' => [$student_id],
            'link_url'     => home_url('/tuition-payments'),
            'created_by'   => absint($context['created_by'] ?? 0),
        ]);
    }

    public function on_avatar_pending($user_id)
    {
        if (!self::can_notify()) {
            return;
        }
        // Notify managers that an image awaits approval.
        $managers = get_users([
            'role__in' => ['administrator', 'modir'],
            'fields'   => 'ID',
        ]);
        $managers = array_map('absint', (array) $managers);
        if (!$managers) {
            return;
        }
        $user_id = absint($user_id);
        $owner = get_userdata($user_id);
        $name = $owner ? ($owner->display_name ?: $owner->user_login) : 'یک کاربر';

        $previous_notice_id = class_exists('HST_Avatar_Approval')
            ? absint(get_user_meta($user_id, HST_Avatar_Approval::META_NOTIFICATION, true))
            : 0;
        if ($previous_notice_id) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'hst_notifications',
                ['is_active' => 0, 'updated_at' => current_time('mysql')],
                ['id' => $previous_notice_id],
                ['%d', '%s'],
                ['%d']
            );
        }

        $notification_id = HST_Notifications::create_notification([
            'title'        => 'تصویر پروفایل در انتظار تأیید',
            'message'      => sprintf('تصویر پروفایل جدیدی از «%s» در انتظار تأیید است. از همین اطلاعیه آن را بررسی کنید.', $name),
            'notice_type'  => 'warning',
            'audience'     => 'users',
            'source'       => 'auto',
            'is_active'    => 1,
            'user_targets' => $managers,
            'link_url'     => home_url('/notifications/'),
            'created_by'   => $user_id,
            'merge_auto'   => false,
        ]);

        if ($notification_id && class_exists('HST_Avatar_Approval')) {
            update_user_meta($user_id, HST_Avatar_Approval::META_NOTIFICATION, (int) $notification_id);
            return;
        }

        // Creation should normally succeed. If it does not, reactivate the previous
        // action notice so the manager is not left without a review path.
        if ($previous_notice_id) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'hst_notifications',
                ['is_active' => 1, 'updated_at' => current_time('mysql')],
                ['id' => $previous_notice_id],
                ['%d', '%s'],
                ['%d']
            );
        }
    }

    public function on_avatar_reviewed($user_id, $approved)
    {
        if (!self::can_notify() || !self::type_enabled('avatar-reviewed')) {
            return;
        }
        $user_id = absint($user_id);
        if (!$user_id) {
            return;
        }
        $approved = (bool) $approved;
        HST_Notifications::create_notification([
            'title'        => $approved ? 'تصویر پروفایل تأیید شد' : 'تصویر پروفایل رد شد',
            'message'      => $approved
                ? 'تصویر پروفایل شما تأیید و منتشر شد.'
                : 'تصویر پروفایل ارسالی شما تأیید نشد. می‌توانید تصویر دیگری بارگذاری کنید.',
            'notice_type'  => $approved ? 'success' : 'danger',
            'audience'     => 'users',
            'source'       => 'auto',
            'is_active'    => 1,
            'user_targets' => [$user_id],
            'link_url'     => home_url('/profile'),
        ]);
    }
}
