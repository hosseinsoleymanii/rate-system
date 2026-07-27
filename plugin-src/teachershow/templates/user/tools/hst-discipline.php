<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-icons.php';
include_once HST_PATH . 'templates/user/common/hst-user-avatar.php';

global $wpdb;
$hst_disc_table = $wpdb->prefix . 'hst_discipline';
$hst_disc_rows = [];
$hst_disc_stats = [
    'total'      => 0,
    'violations' => 0,
    'warnings'   => 0,
    'praises'    => 0,
    'absences'   => 0,
    'lates'      => 0,
    'notified'   => 0,
];

if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hst_disc_table)) === $hst_disc_table) {
    $hst_disc_rows = $wpdb->get_results(
        "SELECT d.*, u.display_name AS student_name
         FROM {$hst_disc_table} d
         INNER JOIN {$wpdb->users} u ON u.ID = d.student_id
         ORDER BY d.created_at DESC
         LIMIT 300"
    ) ?: [];

    $hst_disc_stats['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$hst_disc_table}");
    $hst_disc_stats['violations'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$hst_disc_table} WHERE type = %s", 'violation'));
    $hst_disc_stats['warnings'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$hst_disc_table} WHERE type = %s", 'warning'));
    $hst_disc_stats['praises'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$hst_disc_table} WHERE type = %s", 'praise'));
    $hst_disc_stats['absences'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$hst_disc_table} WHERE type = %s", 'absence'));
    $hst_disc_stats['lates'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$hst_disc_table} WHERE type = %s", 'late'));
    $hst_disc_stats['notified'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$hst_disc_table} WHERE parent_notified = 1");
}

$hst_disc_types = [
    'violation' => 'تخلف',
    'warning'   => 'اخطار',
    'praise'    => 'تشویق',
    'absence'   => 'غیبت',
    'late'      => 'تأخیر',
];
$hst_disc_calculation_settings = class_exists('HST_Discipline')
    ? HST_Discipline::calculation_settings()
    : [];
$hst_disc_severities = [
    'low'    => 'کم',
    'medium' => 'متوسط',
    'high'   => 'زیاد',
];
$hst_disc_fa_num = static function ($value): string {
    return strtr((string) $value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
};
$hst_disc_fa_date = static function ($value): string {
    if (empty($value)) {
        return '';
    }
    if (class_exists('HST_Date')) {
        return HST_Date::format($value, 'Y/m/d', '');
    }
    $ts = strtotime((string) $value);
    return $ts ? date_i18n('Y/m/d', $ts) : (string) $value;
};
$hst_disc_student_avatar = static function (int $student_id, string $student_name): array {
    $avatar_id = absint(get_user_meta($student_id, 'hst_profile_avatar_id', true));
    if (!$avatar_id && class_exists('HST_Avatar_Approval')) {
        $avatar_id = (int) HST_Avatar_Approval::display_avatar_id($student_id, $student_id);
    }

    $avatar_url = $avatar_id ? (string) wp_get_attachment_image_url($avatar_id, 'thumbnail') : '';
    $initial = function_exists('hst_user_initials')
        ? hst_user_initials($student_id, $student_name)
        : (function_exists('mb_substr') ? mb_substr(trim($student_name), 0, 1, 'UTF-8') : substr(trim($student_name), 0, 1));

    return [
        'url'     => $avatar_url,
        'initial' => $initial ?: '؟',
    ];
};
$hst_disc_sms_ready = class_exists('HST_SMS') && HST_SMS::direct_ready();
$hst_disc_active_term_id = class_exists('HST_Terms') ? (int) HST_Terms::active_id() : 0;
$hst_disc_active_student_count = 0;
if ($hst_disc_active_term_id) {
    $hst_disc_active_student_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id)
             FROM {$wpdb->prefix}hst_users_classes
             WHERE term_id = %d AND role = 'student'",
            $hst_disc_active_term_id
        )
    );
}
$hst_disc_has_active_students = $hst_disc_active_student_count > 0;
$hst_disc_add_title = $hst_disc_active_term_id
    ? 'افزودن مورد انضباطی'
    : 'ابتدا یک سال تحصیلی فعال تعریف کنید.';
$hst_disc_sms_preview_context = [
    'name'          => 'دانش‌آموز نمونه',
    'school'        => class_exists('HST_Settings') ? HST_Settings::option('hst-home-school-name', get_bloginfo('name')) : get_bloginfo('name'),
    'date'          => class_exists('HST_Date') ? HST_Date::today('Y/m/d') : date_i18n('Y/m/d'),
    'title'         => 'عنوان مورد انضباطی',
    'type'          => 'اخطار',
    'severity'      => 'متوسط',
    'description'   => 'توضیحات نمونه مورد انضباطی',
    'incident_date' => '۱۴۰۳/۰۷/۳۰',
];
$hst_disc_sms_default_template = class_exists('HST_SMS') ? HST_SMS::default_template('discipline') : "ولی محترم دانش‌آموز {name}، یک مورد انضباطی برای فرزند شما ثبت شد.\nموضوع: {title} - تاریخ: {incident_date}\n{school}";
$hst_disc_sms_template_vars = [
    'name'          => 'نام دانش‌آموز',
    'school'        => 'نام مدرسه',
    'date'          => 'تاریخ امروز',
    'title'         => 'عنوان مورد',
    'type'          => 'نوع مورد',
    'severity'      => 'شدت مورد',
    'description'   => 'توضیحات',
    'incident_date' => 'تاریخ ثبت',
];
?>
<section class="hst-page hst-discipline hst-management-page hst-module hst-module--discipline" data-hst-discipline data-hst-can-manage-avatars="<?php echo (current_user_can('manage_options') || current_user_can('hst_manage_school')) ? '1' : '0'; ?>" data-hst-has-active-students="<?php echo $hst_disc_has_active_students ? '1' : '0'; ?>">
    <div class="hst-inline-filter" data-hst-inline-filter="hst-disc-table">
<div class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html(HST_Settings::management_page_title('discipline', 'موارد انضباطی')); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-stack">
<div class="hst-inline-filter__add">
                    <button type="button" class="hst-btn--icon hst-btn hst-btn--primary hst-btn--sm" id="hst-disc-add" <?php disabled(!$hst_disc_active_term_id); ?> title="<?php echo esc_attr($hst_disc_add_title); ?>" aria-label="<?php echo esc_attr($hst_disc_add_title); ?>"><?php echo hst_icon('add'); ?><span>افزودن مورد انضباطی</span></button>
                    <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm" id="hst-disc-settings" title="تنظیمات محاسبه کارنامه انضباطی" aria-label="تنظیمات محاسبه کارنامه انضباطی"><?php echo hst_icon('settings'); ?><span>تنظیمات محاسبه</span></button>
                    <?php
                    $hst_disc_book_title = $hst_disc_has_active_students
                        ? 'دریافت دفتر انضباطی'
                        : ($hst_disc_active_term_id ? 'دانش‌آموزی در سال تحصیلی فعال ثبت نشده است.' : 'ابتدا یک سال تحصیلی فعال تعریف کنید.');
                    ?>
                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" id="hst-disc-print-book" <?php disabled(!$hst_disc_has_active_students); ?> title="<?php echo esc_attr($hst_disc_book_title); ?>" aria-label="<?php echo esc_attr($hst_disc_book_title); ?>">
                        <span class="hst-btn__icon" aria-hidden="true"><?php echo hst_icon('discipline-book'); ?></span>
                        <span>دفتر انضباطی</span>
                    </button>
                </div>


                

                <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__body hst-section-card__body">
<div class="hst-inline-filter__main">
                    <div class="hst-inline-filter__search">
                        <span class="hst-inline-filter__search-icon" aria-hidden="true"><?php echo hst_icon('students'); ?></span>
                        <input type="search" class="hst-search" data-hst-inline-search placeholder="جست‌وجوی دانش‌آموز..." autocomplete="off">
                    </div>

                    <select class="hst-inline-filter__select" data-hst-inline-select="type" aria-label="فیلتر نوع مورد">
                        <option value="">همهٔ انواع</option>
                        <option value="violation">تخلف</option>
                        <option value="warning">اخطار</option>
                        <option value="praise">تشویق</option>
                        <option value="absence">غیبت</option>
                        <option value="late">تأخیر</option>
                    </select>

                </div>
        </div>
    </div>
</div>

    <div class="hst-card hst-section-card" id="hst-disc-statbar-card">
        <div class="hst-card__header hst-section-card__header"><h3>گزارش موارد انضباطی</h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-report-stats" id="hst-disc-statbar">
                <div class="hst-report-stat hst-report-stat--total"><b data-stat="total"><?php echo esc_html($hst_disc_fa_num($hst_disc_stats['total'])); ?></b><span>کل موارد</span></div>
                <div class="hst-report-stat hst-report-stat--skip"><b data-stat="violations"><?php echo esc_html($hst_disc_fa_num($hst_disc_stats['violations'])); ?></b><span>تخلف</span></div>
                <div class="hst-report-stat hst-report-stat--warning"><b data-stat="warnings"><?php echo esc_html($hst_disc_fa_num($hst_disc_stats['warnings'])); ?></b><span>اخطار</span></div>
                <div class="hst-report-stat hst-report-stat--new"><b data-stat="praises"><?php echo esc_html($hst_disc_fa_num($hst_disc_stats['praises'])); ?></b><span>تشویق</span></div>
                <div class="hst-report-stat hst-report-stat--skip"><b data-stat="absences"><?php echo esc_html($hst_disc_fa_num($hst_disc_stats['absences'])); ?></b><span>غیبت</span></div>
                <div class="hst-report-stat hst-report-stat--warning"><b data-stat="lates"><?php echo esc_html($hst_disc_fa_num($hst_disc_stats['lates'])); ?></b><span>تأخیر</span></div>
                <div class="hst-report-stat hst-report-stat--upd"><b data-stat="notified"><?php echo esc_html($hst_disc_fa_num($hst_disc_stats['notified'])); ?></b><span>اطلاع‌داده‌شده</span></div>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__header hst-section-card__header"><h3>لیست موارد انضباطی</h3></div>
        <div class="hst-card__body hst-section-card__body">
            <?php if (!$hst_disc_active_term_id) : ?>
                <p class="hst-alert hst-alert--warning hst-empty-state">برای ثبت مورد انضباطی ابتدا یک سال تحصیلی فعال تعریف کنید.</p>
            <?php endif; ?>
            <div id="hst-disc-list" class="hst-disc-list">
                <?php if (!empty($hst_disc_rows)) : ?>
                    <div class="hst-table-wrap hst-disc-table-wrap hst-data-table-wrap hst-data-table">
                        <table class="hst-table hst-disc-table hst-data-table" id="hst-disc-table">
                            <thead>
                                <tr>
                                    <th>ردیف</th>
                                    <th class="hst-col-fill">دانش‌آموز</th>
                                    <th>نوع / شدت</th>
                                    <th>تاریخ</th>
                                    <th>پیامک</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hst_disc_rows as $index => $record) :
                                    $type_label = $hst_disc_types[$record->type] ?? $record->type;
                                    $severity_label = $hst_disc_severities[$record->severity] ?? $record->severity;
                                    $incident_date = $hst_disc_fa_date($record->incident_date ?: $record->created_at);
                                    $student_name = (string) ($record->student_name ?: '—');
                                    $student_avatar = $hst_disc_student_avatar((int) $record->student_id, $student_name);
                                    $search_text = trim($student_name . ' ' . (string) $record->title . ' ' . (string) $record->description . ' ' . $type_label . ' ' . $severity_label . ' ' . $incident_date);
                                ?>
                                    <tr class="<?php echo esc_attr('is-' . sanitize_html_class((string) $record->type)); ?>"
                                        data-id="<?php echo esc_attr($record->id); ?>"
                                        data-student-id="<?php echo esc_attr($record->student_id); ?>"
                                        data-student-name="<?php echo esc_attr($student_name); ?>"
                                        data-avatar-url="<?php echo esc_url($student_avatar['url']); ?>"
                                        data-initial="<?php echo esc_attr($student_avatar['initial']); ?>"
                                        data-title="<?php echo esc_attr($record->title); ?>"
                                        data-description="<?php echo esc_attr($record->description); ?>"
                                        data-incident-date="<?php echo esc_attr($incident_date); ?>"
                                        data-type-label="<?php echo esc_attr($type_label); ?>"
                                        data-severity-label="<?php echo esc_attr($severity_label); ?>"
                                        data-sms-enabled="<?php echo esc_attr((int) ($record->sms_enabled ?? 0)); ?>"
                                        data-sms-sent="<?php echo esc_attr((int) ($record->parent_notified ?? 0)); ?>"
                                        data-sms-message="<?php echo esc_attr((string) ($record->sms_message ?? '')); ?>"
                                        data-hst-search="<?php echo esc_attr($search_text); ?>"
                                        data-hst-type="<?php echo esc_attr($record->type); ?>">
                                        <td><?php echo esc_html($hst_disc_fa_num($index + 1)); ?></td>
                                        <td class="hst-col-fill">
                                            <?php echo hst_user_cell((int) $record->student_id, $student_name); ?>
                                        </td>
                                        <td><span class="hst-disc-badge hst-disc-badge--<?php echo esc_attr($record->type); ?>"><?php echo esc_html($type_label . ' · ' . $severity_label); ?></span></td>
                                        <td><?php echo esc_html($incident_date ?: '—'); ?></td>
                                        <td>
                                            <?php if (!empty($record->parent_notified)) : ?>
                                                <span class="hst-status hst-status--success hst-sms-sent-label">پیامک ارسال شده</span>
                                            <?php else : ?>
                                                <label class="hst-switch" title="فعال‌سازی پیامک مورد انضباطی" aria-label="فعال‌سازی پیامک مورد انضباطی">
                                                    <input type="checkbox" class="hst-toggle-discipline-sms" data-id="<?php echo esc_attr($record->id); ?>" <?php checked((int) ($record->sms_enabled ?? 0), 1); ?> <?php disabled(!$hst_disc_sms_ready); ?>>
                                                    <span class="hst-switch__slider"></span>
                                                </label>
                                            <?php endif; ?>
                                        </td>
                                        <td class="hst-actions">
                                            <div class="hst-btn-group">
                                                <button type="button" class="hst-btn hst-btn--ghost hst-btn--sm hst-btn--icon hst-disc-view" data-id="<?php echo esc_attr($record->id); ?>" title="مشاهده" aria-label="مشاهده"><?php echo hst_icon('view'); ?></button>
                                                <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon hst-disc-edit" data-id="<?php echo esc_attr($record->id); ?>" title="ویرایش" aria-label="ویرایش"><?php echo hst_icon('edit'); ?></button>
                                                <button type="button" class="hst-btn hst-btn--ghost hst-btn--sm hst-disc-print-student" data-student-id="<?php echo esc_attr($record->student_id); ?>" title="چاپ دفتر انضباطی دانش‌آموز" aria-label="چاپ دفتر انضباطی دانش‌آموز"><?php echo hst_icon('print'); ?><span>دفتر انضباطی</span></button>
                                                <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon hst-disc-del" data-id="<?php echo esc_attr($record->id); ?>" title="حذف" aria-label="حذف"><?php echo hst_icon('delete'); ?></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <p class="hst-alert hst-empty-state">هنوز مورد انضباطی ثبت نشده است.</p>
                <?php endif; ?>
            </div>
            <p class="hst-muted hst-inline-filter__empty hst-empty-state hst-empty-state--inline" data-hst-inline-empty hidden>موردی با این فیلتر پیدا نشد.</p>
        </div>
    </div>

    <div class="hst-modal" id="hst-disc-view-modal" data-hst-modal-tone="detail" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-disc-view-title">
        <div class="hst-modal__backdrop" data-hst-disc-view-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div class="hst-modal__title">
                    <span class="hst-modal__icon" aria-hidden="true"><?php echo hst_icon('discipline'); ?></span>
                    <div>
                        <h3 id="hst-disc-view-title">مشاهده مورد انضباطی</h3>
                        <p>مشخصات کامل مورد انضباطی ثبت‌شده</p>
                    </div>
                </div>
                <button type="button" class="hst-modal__close" data-hst-disc-view-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body" id="hst-disc-view-body">
                <p class="hst-muted"><?php echo hst_loading_state(); ?></p>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--soft" data-hst-disc-view-close>بستن</button>
            </div>
        </div>
    </div>

    <div class="hst-modal" id="hst-discipline-sms-modal" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-discipline-sms-title">
        <div class="hst-modal__backdrop" data-hst-discipline-sms-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <h3 id="hst-discipline-sms-title">متن پیامک مورد انضباطی</h3>
                <button type="button" class="hst-modal__close" data-hst-discipline-sms-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body hst-form"
                 data-sms-preview-name="<?php echo esc_attr($hst_disc_sms_preview_context['name']); ?>"
                 data-sms-preview-school="<?php echo esc_attr($hst_disc_sms_preview_context['school']); ?>"
                 data-sms-preview-date="<?php echo esc_attr($hst_disc_sms_preview_context['date']); ?>"
                 data-sms-preview-title="<?php echo esc_attr($hst_disc_sms_preview_context['title']); ?>"
                 data-sms-preview-type="<?php echo esc_attr($hst_disc_sms_preview_context['type']); ?>"
                 data-sms-preview-severity="<?php echo esc_attr($hst_disc_sms_preview_context['severity']); ?>"
                 data-sms-preview-description="<?php echo esc_attr($hst_disc_sms_preview_context['description']); ?>"
                 data-sms-preview-incident-date="<?php echo esc_attr($hst_disc_sms_preview_context['incident_date']); ?>">
                
                <div class="hst-field">
                    <label for="hst-discipline-sms-message">متن پیامک</label>
                    <div class="hst-btn-group" role="group" aria-label="متغیرهای قابل استفاده در متن پیامک">
                        <?php foreach ($hst_disc_sms_template_vars as $key => $label) : ?>
                            <?php $variable = '{' . $key . '}'; ?>
                            <button type="button"
                                    class="hst-chip"
                                    data-hst-sms-variable="<?php echo esc_attr($variable); ?>"
                                    data-hst-sms-target="#hst-discipline-sms-message"
                                    title="<?php echo esc_attr('درج ' . $label); ?>"
                                    aria-label="<?php echo esc_attr('درج ' . $label . ' در متن پیامک'); ?>"><?php echo esc_html($label); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <textarea id="hst-discipline-sms-message" rows="5" maxlength="500"><?php echo esc_textarea($hst_disc_sms_default_template); ?></textarea>
                </div>
                <label class="hst-field">
                    <span>پیش‌نمایش نهایی</span>
                    <div class="hst-alert hst-alert--info" id="hst-discipline-sms-preview"></div>
                        <div class="hst-sms-usage is-loading" id="hst-discipline-sms-usage" role="status" aria-live="polite" aria-busy="true">
                            <span class="hst-sms-usage__badge hst-sms-usage__badge--muted">در حال محاسبه مصرف پیامک...</span>
                        </div>
                </label>
                <label class="hst-field">
                    <span>ارسال تستی پیامک</span>
                    <div class="hst-btn-group hst-discipline-sms-test-row">
                        <input type="tel" id="hst-discipline-sms-test-phone" placeholder="شماره موبایل تست">
                        <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" id="hst-discipline-sms-test-send" <?php disabled(!$hst_disc_sms_ready); ?>>ارسال تست</button>
                    </div>
                </label>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--primary" id="hst-discipline-sms-confirm">تأیید و ارسال</button>
                <button type="button" class="hst-btn hst-btn--soft" data-hst-discipline-sms-close>بستن</button>
            </div>
        </div>
    </div>

    <div class="hst-modal" id="hst-disc-modal" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-disc-modal-title">
        <div class="hst-modal__backdrop" data-hst-disc-modal-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <h3 id="hst-disc-modal-title">ثبت مورد انضباطی جدید</h3>
                <button type="button" class="hst-modal__close" data-hst-disc-modal-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <form class="hst-form" id="hst-disc-form" autocomplete="off">
                    <input type="hidden" id="hst-disc-id" value="0">

                    <div class="hst-field hst-disc-form__student">
                        <span>دانش‌آموز / دانش‌آموزان</span>
                        <div class="hst-user-picker hst-user-picker--multi" data-hst-disc-picker>
                            <input type="text" class="hst-user-picker-search hst-search" placeholder="جست‌وجوی نام دانش‌آموز برای افزودن...">
                            <div class="hst-user-picker-results" hidden></div>
                            <div class="hst-user-picker-selected"></div>
                        </div>
                    </div>

                    <div class="hst-field-grid">
                        <label class="hst-field">
                            <span>نوع</span>
                            <select id="hst-disc-type">
                                <option value="violation">تخلف</option>
                                <option value="warning">اخطار</option>
                                <option value="praise">تشویق</option>
                                <option value="absence">غیبت</option>
                                <option value="late">تأخیر</option>
                            </select>
                        </label>

                        <label class="hst-field">
                            <span>شدت</span>
                            <select id="hst-disc-severity">
                                <option value="low">کم</option>
                                <option value="medium" selected>متوسط</option>
                                <option value="high">زیاد</option>
                            </select>
                        </label>

                        <label class="hst-field">
                            <span>تاریخ رخداد</span>
                            <input type="text" id="hst-disc-date" class="hst-jalali-date" readonly placeholder="انتخاب تاریخ" autocomplete="off">
                        </label>

                        <label class="hst-field hst-field--wide">
                            <span>عنوان</span>
                            <input type="text" id="hst-disc-title" placeholder="مثلاً: تأخیر مکرر در ورود به کلاس">
                        </label>

                        <label class="hst-field hst-field--wide">
                            <span>توضیحات اختیاری</span>
                            <textarea id="hst-disc-description" rows="3" placeholder="جزئیات بیشتر..."></textarea>
                        </label>
                    </div>
                </form>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--primary" id="hst-disc-save">ذخیره تغییرات</button>
                <button type="button" class="hst-btn hst-btn--soft" data-hst-disc-modal-close>بستن</button>
            </div>
        </div>
    </div>


    <div class="hst-modal" id="hst-disc-settings-modal" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-disc-settings-title">
        <div class="hst-modal__backdrop" data-hst-disc-settings-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <h3 id="hst-disc-settings-title">تنظیمات محاسبه کارنامه انضباطی</h3>
                <button type="button" class="hst-modal__close" data-hst-disc-settings-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <div class="hst-table-wrap hst-data-table-wrap">
                    <table class="hst-table hst-data-table">
                        <thead>
                            <tr>
                                <th>نوع مورد</th>
                                <th>اثر هر بار ثبت بر معدل انضباط</th>
                                <th>اثر هر بار ثبت بر درصد حضور</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hst_disc_types as $type_key => $type_label) :
                                $effects = $hst_disc_calculation_settings[$type_key] ?? ['conduct' => 0, 'attendance' => 0];
                            ?>
                                <tr data-hst-disc-setting-type="<?php echo esc_attr($type_key); ?>">
                                    <td><strong><?php echo esc_html($type_label); ?></strong></td>
                                    <td><input type="number" step="0.01" min="-20" max="20" data-hst-disc-setting="conduct" value="<?php echo esc_attr((string) ($effects['conduct'] ?? 0)); ?>" aria-label="اثر <?php echo esc_attr($type_label); ?> بر معدل انضباط"></td>
                                    <td>
                                        <?php if (in_array($type_key, ['absence', 'late'], true)) : ?>
                                            <input type="number" step="0.01" min="-100" max="100" data-hst-disc-setting="attendance" value="<?php echo esc_attr((string) ($effects['attendance'] ?? 0)); ?>" aria-label="اثر <?php echo esc_attr($type_label); ?> بر درصد حضور">
                                        <?php else : ?>
                                            <input type="number" value="0" data-hst-disc-setting="attendance" aria-label="بدون اثر بر درصد حضور" disabled>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--primary" id="hst-disc-settings-save">ذخیره تغییرات</button>
                <button type="button" class="hst-btn hst-btn--soft" data-hst-disc-settings-close>بستن</button>
            </div>
        </div>
    </div>
</section>
</div>
