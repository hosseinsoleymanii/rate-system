<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-icons.php';

$notification_context = is_array($notification_context ?? null) ? $notification_context : [];
$is_manager = !empty($notification_context['is_manager']);
$classes = $notification_context['classes'] ?? [];
$teachers = $notification_context['teachers'] ?? [];
$students = $notification_context['students'] ?? [];
$admin_notices = $notification_context['admin_notices'] ?? [];
$my_notices = $notification_context['my_notices'] ?? [];
$unread_count = intval($notification_context['unread_count'] ?? 0);
$role_options = [
    'administrator' => 'مدیرکل سایت',
    'modir' => 'مدیر مدرسه',
    'teacher' => 'معلم‌ها',
    'student' => 'دانش‌آموزان',
];
$type_options = [
    'info' => 'اطلاع‌رسانی',
    'success' => 'موفقیت',
    'warning' => 'هشدار',
    'danger' => 'فوری',
];
$sms_template_vars = class_exists('HST_Notifications') ? HST_Notifications::sms_template_vars() : [
    '{name}'    => 'نام گیرنده',
    '{title}'   => 'عنوان اطلاعیه',
    '{message}' => 'متن اطلاعیه',
    '{type}'    => 'نوع اطلاعیه',
    '{date}'    => 'تاریخ ارسال',
    '{school}'  => 'نام مدرسه',
];
$hst_sms_ready = class_exists('HST_SMS') && HST_SMS::direct_ready();
$notification_sms_default_template = class_exists('HST_SMS') ? HST_SMS::default_template('notification') : "{school} اطلاعیه‌ای با عنوان «{title}» منتشر کرد.\n{message}\nتاریخ: {date}";
$current_sms_user = wp_get_current_user();
$sms_preview_context = [
    'name'   => ($current_sms_user && $current_sms_user->exists()) ? ($current_sms_user->display_name ?: $current_sms_user->user_login) : 'کاربر نمونه',
    'school' => class_exists('HST_Settings') ? (string) HST_Settings::option('hst-home-school-name', get_bloginfo('name')) : get_bloginfo('name'),
    'date'   => class_exists('HST_Date') ? HST_Date::today('Y/m/d') : date_i18n('Y/m/d'),
];

$hst_notification_audience_label = static function ($notice) {
    $audience = $notice->audience ?? 'all';
    if ($audience === 'all') {
        return 'همه کاربران';
    }
    if ($audience === 'roles') {
        $role_labels = [
            'administrator' => 'مدیرکل سایت',
            'modir' => 'مدیر مدرسه',
            'teacher' => 'معلم‌ها',
            'student' => 'دانش‌آموزان',
        ];
        $roles = json_decode((string) ($notice->role_targets ?? ''), true);
        $roles = is_array($roles) ? $roles : [];
        $labels = array_values(array_filter(array_map(static function ($role) use ($role_labels) {
            return $role_labels[$role] ?? '';
        }, $roles)));
        return $labels ? 'نقش‌ها: ' . implode('، ', $labels) : 'نقش‌ها';
    }
    if ($audience === 'classes') {
        global $wpdb;
        $ids = json_decode((string) ($notice->class_targets ?? ''), true);
        $ids = array_values(array_filter(array_map('absint', is_array($ids) ? $ids : [])));
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $names = $wpdb->get_col($wpdb->prepare("SELECT class_name FROM {$wpdb->prefix}hst_classes WHERE id IN ($placeholders) ORDER BY class_name ASC", ...$ids));
            $names = HST_Classes::sort_names((array) $names);
            if ($names) {
                return 'کلاس‌ها: ' . implode('، ', array_map('sanitize_text_field', $names));
            }
        }
        return 'کلاس‌ها';
    }
    if ($audience === 'users') {
        $ids = json_decode((string) ($notice->user_targets ?? ''), true);
        $ids = array_values(array_filter(array_map('absint', is_array($ids) ? $ids : [])));
        $names = [];
        foreach ($ids as $id) {
            $user = get_userdata($id);
            if ($user) {
                $names[] = $user->display_name ?: $user->user_login;
            }
        }
        if ($names) {
            return 'کاربران: ' . implode('، ', array_slice($names, 0, 4)) . (count($names) > 4 ? ' و ' . (count($names) - 4) . ' نفر دیگر' : '');
        }
        return 'کاربران مشخص';
    }
    return 'نامشخص';
};
?>
<section class="hst-page hst-notifications-page hst-management-page hst-module hst-module--notifications" data-hst-notifications>
    <div class="hst-inline-filter" data-hst-inline-filter="hst-notif-list">
<div class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html($is_manager ? HST_Settings::management_page_title('notifications', 'اطلاعیه‌ها') : HST_Settings::plugin_page_title('notifications', 'اطلاعیه‌ها')); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-stack">
<?php if ($is_manager) : ?>
                    <div class="hst-inline-filter__add">
                        <button type="button" class="hst-btn--icon hst-btn hst-btn--primary hst-btn--sm" id="hst-notification-add" title="افزودن اطلاعیه" aria-label="افزودن اطلاعیه"><?php echo hst_icon('add'); ?><span>افزودن اطلاعیه</span></button>
                    </div>


                    
                <?php else : ?>
                    <div class="hst-inline-filter__add">
                        <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" id="hst-mark-all-notifications-read" <?php disabled(!$unread_count); ?> title="خواندن همه اطلاعیه‌ها" aria-label="خواندن همه اطلاعیه‌ها">
                            <span class="hst-btn__icon" aria-hidden="true"><?php echo hst_icon('notification-read'); ?></span>
                            <span>خواندن همه <?php echo $unread_count ? '(' . esc_html($unread_count) . ' جدید)' : ''; ?></span>
                        </button>
                    </div>
                <?php endif; ?>

                <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
            </div>
        </div>
    </div>

    <?php if ($is_manager) : ?>
<div class="hst-card hst-section-card">
        <div class="hst-card__body hst-section-card__body">
<div class="hst-inline-filter__main">
                    <select class="hst-inline-filter__select" id="hst-notif-filter-type" data-hst-inline-select="type" aria-label="فیلتر نوع اطلاعیه">
                            <option value="">همه نوع‌ها</option>
                            <option value="info">اطلاع‌رسانی</option>
                            <option value="success">موفقیت</option>
                            <option value="warning">هشدار</option>
                            <option value="danger">مهم</option>
                        </select>
                        <select class="hst-inline-filter__select" id="hst-notif-filter-source" data-hst-inline-select="source" aria-label="فیلتر منبع اطلاعیه">
                            <option value="">همه منابع</option>
                            <option value="manual">دستی</option>
                            <option value="auto">خودکار</option>
                        </select>
</div>
        </div>
    </div>
    <?php endif; ?>
</div>

    <?php if ($is_manager) : ?>
        <div class="hst-modal" id="hst-notification-modal" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-notification-modal-title">
            <div class="hst-modal__backdrop" data-hst-notification-modal-close></div>
            <div class="hst-modal__panel">
                <div class="hst-modal__header">
                    <h3 id="hst-notification-modal-title">ثبت اطلاعیه جدید</h3>
                    <button type="button" class="hst-modal__close" data-hst-notification-modal-close aria-label="بستن">&times;</button>
                </div>
                <div class="hst-modal__body">
                    <form class="hst-form" id="hst-notification-form">
                        <div class="hst-form__row">
                            <label class="hst-field">
                                <span>عنوان اطلاعیه</span>
                                <input type="text" name="title" placeholder="عنوان اطلاعیه" required>
                            </label>
                            <label class="hst-field">
                                <span>نوع اطلاعیه</span>
                                <select name="notice_type">
                                    <?php foreach ($type_options as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <label class="hst-field">
                            <span>متن اطلاعیه</span>
                            <textarea id="hst-notification-message" name="message" rows="4" placeholder="متن اطلاعیه را بنویسید..." required></textarea>
                        </label>

                        <div class="hst-form__row">
                            <label class="hst-field">
                                <span>مخاطبان</span>
                                <select name="audience" id="hst-notification-audience">
                                    <option value="all">عمومی برای همه</option>
                                    <option value="roles">بر اساس نقش</option>
                                    <option value="classes">بر اساس کلاس</option>
                                    <option value="users">کاربران مشخص</option>
                                </select>
                            </label>
                            <label class="hst-field">
                                <span>لینک مرتبط</span>
                                <input type="url" name="link_url" placeholder="لینک مرتبط؛ اختیاری">
                            </label>
                        </div>

                        <div class="hst-notification-targets" data-target="roles" hidden>
                            <p class="hst-form__section"><span>نقش‌های دریافت‌کننده</span></p>
                            <div class="checkbox-group hst-choice-list">
                                <?php foreach ($role_options as $role => $label) : ?>
                                    <label><input type="checkbox" name="role_targets[]" value="<?php echo esc_attr($role); ?>"><p><?php echo esc_html($label); ?></p></label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="hst-notification-targets" data-target="classes" hidden>
                            <p class="hst-form__section"><span>کلاس‌های دریافت‌کننده</span></p>
                            <div class="checkbox-group hst-choice-list">
                                <?php foreach ($classes as $class) : ?>
                                    <label><input type="checkbox" name="class_targets[]" value="<?php echo esc_attr($class->id); ?>"><p><?php echo esc_html($class->class_name); ?></p></label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="hst-notification-targets" data-target="users" hidden>
                            <div class="hst-user-picker" data-hst-user-picker>
                                <div class="hst-user-picker__head">
                                    <label class="hst-field">
                                        <span>جست‌وجوی کاربر</span>
                                        <input type="search" class="hst-user-picker-search hst-search" placeholder="نام، نام خانوادگی یا شماره موبایل کاربر را وارد کنید..." autocomplete="off">
                                    </label>
                                    <div class="hst-user-picker__actions">
                                        <button type="button" class="hst-btn hst-btn--ghost hst-btn--sm hst-user-picker-clear" hidden>پاک کردن انتخاب‌ها</button>
                                    </div>
                                </div>
                                <div class="hst-user-picker-selected" aria-live="polite"></div>
                                <div class="hst-user-picker-results" hidden>
                                    <p class="hst-alert">نتیجه‌ای برای نمایش وجود ندارد.</p>
                                </div>
                            </div>
                        </div>
                        <label class="hst-field">
                            <span>زمان انتشار</span>
                            <input type="text" name="publish_at" class="hst-jalali-datetime" data-hst-time-title="انتخاب ساعت انتشار" placeholder="۱۴۰۳/۰۸/۱۵ ۰۸:۰۰" inputmode="numeric">
                        </label>
                    </form>
                </div>
                <div class="hst-modal__footer">
                    <button type="submit" class="hst-btn hst-btn--primary" form="hst-notification-form">ذخیره تغییرات</button>
                    <button type="button" class="hst-btn hst-btn--soft" data-hst-notification-modal-close>بستن</button>
                </div>
            </div>
        </div>
    <?php endif; ?>


    <?php if ($is_manager) : ?>
        <div class="hst-modal" id="hst-notification-sms-modal" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-notification-sms-title">
            <div class="hst-modal__backdrop" data-hst-notification-sms-close></div>
            <div class="hst-modal__panel">
                <div class="hst-modal__header">
                    <h3 id="hst-notification-sms-title">متن پیامک اطلاعیه</h3>
                    <button type="button" class="hst-modal__close" data-hst-notification-sms-close aria-label="بستن">&times;</button>
                </div>
                <div class="hst-modal__body hst-form" data-sms-preview-name="<?php echo esc_attr($sms_preview_context['name']); ?>" data-sms-preview-school="<?php echo esc_attr($sms_preview_context['school']); ?>" data-sms-preview-date="<?php echo esc_attr($sms_preview_context['date']); ?>">
                    
                    <div class="hst-field">
                        <label for="hst-notification-sms-message">متن پیامک</label>
                        <div class="hst-btn-group" role="group" aria-label="متغیرهای قابل استفاده در متن پیامک">
                            <?php foreach ($sms_template_vars as $variable => $label) : ?>
                                <button type="button"
                                        class="hst-chip"
                                        data-hst-sms-variable="<?php echo esc_attr($variable); ?>"
                                        data-hst-sms-target="#hst-notification-sms-message"
                                        title="<?php echo esc_attr('درج ' . $label); ?>"
                                        aria-label="<?php echo esc_attr('درج ' . $label . ' در متن پیامک'); ?>"><?php echo esc_html($label); ?></button>
                            <?php endforeach; ?>
                        </div>
                        <textarea id="hst-notification-sms-message" rows="5" maxlength="500"><?php echo esc_textarea($notification_sms_default_template); ?></textarea>
                    </div>
                    <label class="hst-field">
                        <span>پیش‌نمایش نهایی</span>
                        <div class="hst-alert hst-alert--info" id="hst-notification-sms-preview"></div>
                        <div class="hst-sms-usage is-loading" id="hst-notification-sms-usage" role="status" aria-live="polite" aria-busy="true">
                            <span class="hst-sms-usage__badge hst-sms-usage__badge--muted">در حال محاسبه مصرف پیامک...</span>
                        </div>
                    </label>
                    <label class="hst-field">
                        <span>ارسال تستی پیامک</span>
                        <div class="hst-btn-group hst-notification-sms-test-row">
                            <input type="tel" id="hst-notification-sms-test-phone" placeholder="شماره موبایل تست">
                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" id="hst-notification-sms-test-send" <?php disabled(!$hst_sms_ready); ?>>ارسال تست</button>
                        </div>
                    </label>
                </div>
                <div class="hst-modal__footer">
                    <button type="button" class="hst-btn hst-btn--primary" id="hst-notification-sms-confirm">تأیید و ارسال</button>
                    <button type="button" class="hst-btn hst-btn--soft" data-hst-notification-sms-close>بستن</button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="hst-modal" id="hst-notification-view-modal" data-hst-modal-tone="detail" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-notification-view-title">
        <div class="hst-modal__backdrop" data-hst-notification-view-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div class="hst-modal__title">
                    <span class="hst-modal__icon" aria-hidden="true"><?php echo hst_icon('notifications'); ?></span>
                    <div>
                        <h3 id="hst-notification-view-title">جزئیات اطلاعیه</h3>
                        <p>مشخصات، محتوای کامل و وضعیت انتشار اطلاعیه</p>
                    </div>
                </div>
                <button type="button" class="hst-modal__close" data-hst-notification-view-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <div class="hst-view-head">
                    <div>
                        <h3 class="hst-view-name" id="hst-notification-view-field-title">—</h3>
                        <p class="hst-muted" id="hst-notification-view-field-date">—</p>
                    </div>
                </div>
                <div class="hst-view-grid">
                    <div class="hst-view-row hst-view-row--wide">
                        <span class="hst-view-row__label">متن اطلاعیه</span>
                        <span class="hst-view-row__value" id="hst-notification-view-field-message">—</span>
                    </div>
                    <div class="hst-view-row">
                        <span class="hst-view-row__label">مخاطب</span>
                        <span class="hst-view-row__value" id="hst-notification-view-field-audience">—</span>
                    </div>
                    <div class="hst-view-row">
                        <span class="hst-view-row__label">نوع اطلاعیه</span>
                        <span class="hst-view-row__value" id="hst-notification-view-field-type">—</span>
                    </div>
                    <div class="hst-view-row">
                        <span class="hst-view-row__label">منبع</span>
                        <span class="hst-view-row__value" id="hst-notification-view-field-source">—</span>
                    </div>
                    <div class="hst-view-row">
                        <span class="hst-view-row__label">وضعیت</span>
                        <span class="hst-view-row__value" id="hst-notification-view-field-status">—</span>
                    </div>
                    <div class="hst-view-row">
                        <span class="hst-view-row__label">لینک مرتبط</span>
                        <span class="hst-view-row__value" id="hst-notification-view-field-link"></span>
                    </div>
                    <div class="hst-view-row hst-view-row--wide" id="hst-notification-view-avatar-review" hidden>
                        <span class="hst-view-row__label">بررسی تصویر پروفایل</span>
                        <span class="hst-view-row__value"><span class="hst-user-id"><span class="hst-user-avatar" id="hst-notification-view-avatar" hidden><img id="hst-notification-view-avatar-image" src="" alt=""></span><span class="hst-user-id__meta"><strong id="hst-notification-view-avatar-name">—</strong><small id="hst-notification-view-avatar-role">—</small></span><span class="hst-status hst-status--warning" id="hst-notification-view-avatar-status">—</span></span></span>
                    </div>
                </div>
            </div>
            <div class="hst-modal__footer">
                <div class="hst-btn-group" id="hst-notification-view-avatar-actions" hidden>
                    <button type="button" class="hst-btn hst-btn--primary" data-hst-avatar-notification-action="approve">تأیید تصویر</button>
                    <button type="button" class="hst-btn hst-btn--danger" data-hst-avatar-notification-action="reject">رد تصویر</button>
                </div>
                <button type="button" class="hst-btn hst-btn--soft" data-hst-notification-view-close>بستن</button>
            </div>
        </div>
    </div>

    <div class="hst-modal" data-hst-modal-tone="detail" data-hst-modal-size="xl" data-hst-notification-report-modal role="dialog" aria-modal="true" aria-labelledby="hst-notification-report-title" aria-hidden="true">
        <div class="hst-modal__backdrop" data-hst-notification-report-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div class="hst-modal__title">
                    <span class="hst-modal__icon" aria-hidden="true"><?php echo hst_icon('report'); ?></span>
                    <div>
                        <h3 id="hst-notification-report-title">گزارش اطلاعیه</h3>
                        <p>آمار مشاهده و وضعیت دریافت اطلاعیه توسط مخاطبان</p>
                    </div>
                </div>
                <button type="button" class="hst-modal__close" data-hst-notification-report-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <div class="hst-report-tools">
                    <label class="hst-field">
                        <span>وضعیت خواندن</span>
                        <select class="hst-select" data-hst-notification-report-read-filter>
                            <option value="">همه</option>
                            <option value="read">خوانده‌شده</option>
                            <option value="unread">خوانده‌نشده</option>
                        </select>
                    </label>
                    <label class="hst-field">
                        <span>نقش</span>
                        <select class="hst-select" data-hst-notification-report-role-filter>
                            <option value="">همه نقش‌ها</option>
                        </select>
                    </label>
                    <label class="hst-field">
                        <span>کلاس</span>
                        <select class="hst-select" data-hst-notification-report-class-filter>
                            <option value="">همه کلاس‌ها</option>
                        </select>
                    </label>
                    <label class="hst-field">
                        <span>جستجو</span>
                        <input type="search" class="hst-input" data-hst-notification-report-search placeholder="نام، شماره موبایل یا کلاس">
                    </label>
                </div>
                <div class="hst-notification-report-summary" data-hst-notification-report-summary></div>
                <div class="hst-notification-report-body" data-hst-notification-report-body>
                    <p class="hst-alert">برای مشاهده گزارش، روی دکمه گزارش یکی از اطلاعیه‌ها کلیک کنید.</p>
                </div>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--soft" data-hst-notification-report-close>بستن</button>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo $is_manager ? 'لیست اطلاعیه‌ها' : 'اطلاعیه‌های من'; ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <?php $notices = $is_manager ? $admin_notices : $my_notices; ?>
            <?php if (!empty($notices)) : ?>
                <div class="hst-table-wrap hst-data-table-wrap">
                    <table class="hst-table hst-data-table">
                        <thead>
                            <tr>
                                <th>ردیف</th>
                                <th>مخاطب</th>
                                <th>نوع</th>
                                <th>منبع</th>
                                <th>فعال / غیرفعال</th>
                                <?php if ($is_manager) : ?>
                                    <th>پیامک</th>
                                <?php endif; ?>
                                <th>تاریخ</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody id="hst-notif-list">
                            <?php foreach ($notices as $index => $notice) : ?>
                                <?php $is_read = !empty($notice->read_at); ?>
                                <?php $src = ($notice->source ?? 'manual') === 'auto' ? 'auto' : 'manual'; ?>
                                <?php
                                $notice_type_label = $type_options[$notice->notice_type] ?? 'اطلاعیه';
                                $audience_label = $is_manager ? $hst_notification_audience_label($notice) : 'شما';
                                $source_label = $src === 'auto' ? 'خودکار' : 'دستی';
                                $status_label = $is_manager
                                    ? ((int) $notice->is_active === 1 ? 'فعال' : 'غیرفعال')
                                    : ($is_read ? 'خوانده‌شده' : 'جدید');
                                $created_label = class_exists('HST_Date') ? HST_Date::format($notice->created_at, 'Y/m/d H:i') : wp_date('Y/m/d H:i', strtotime($notice->created_at));
                                $avatar_review = ($is_manager && class_exists('HST_Avatar_Approval'))
                                    ? HST_Avatar_Approval::notification_context($notice)
                                    : [];
                                ?>
                                <tr class="hst-notification-item <?php echo $is_read ? 'is-read' : 'is-unread'; ?>"
                                    data-id="<?php echo esc_attr($notice->id); ?>"
                                    data-audience="<?php echo esc_attr($notice->audience ?? 'all'); ?>"
                                    data-source="<?php echo esc_attr($src); ?>"
                                    data-hst-type="<?php echo esc_attr($notice->notice_type ?? 'info'); ?>"
                                    data-hst-type-value="<?php echo esc_attr($notice->notice_type ?? 'info'); ?>"
                                    data-hst-source="<?php echo esc_attr($src); ?>"
                                    data-hst-source-value="<?php echo esc_attr($src); ?>"
                                    data-hst-search="<?php echo esc_attr($notice->title . ' ' . wp_strip_all_tags((string) $notice->message) . ' ' . $audience_label . ' ' . $notice_type_label . ' ' . $source_label); ?>"
                                    data-title="<?php echo esc_attr($notice->title); ?>"
                                    data-message="<?php echo esc_attr(wp_strip_all_tags((string) $notice->message)); ?>"
                                    data-link-url="<?php echo esc_url($notice->link_url ?? ''); ?>"
                                    data-audience-label="<?php echo esc_attr($audience_label); ?>"
                                    data-type-label="<?php echo esc_attr($notice_type_label); ?>"
                                    data-source-label="<?php echo esc_attr($source_label); ?>"
                                    data-status-label="<?php echo esc_attr($status_label); ?>"
                                    data-sms-enabled="<?php echo esc_attr((int) ($notice->sms_enabled ?? 0)); ?>"
                                    data-sms-message="<?php echo esc_attr((string) ($notice->sms_message ?? '')); ?>"
                                    data-sms-sent="<?php echo esc_attr(!empty($notice->sms_sent_at) ? '1' : '0'); ?>"
                                    data-created-label="<?php echo esc_attr($created_label); ?>"
                                    data-avatar-review="<?php echo !empty($avatar_review) ? '1' : '0'; ?>"
                                    data-avatar-review-user="<?php echo esc_attr((int) ($avatar_review['user_id'] ?? 0)); ?>"
                                    data-avatar-review-name="<?php echo esc_attr((string) ($avatar_review['name'] ?? '')); ?>"
                                    data-avatar-review-role="<?php echo esc_attr((string) ($avatar_review['role'] ?? '')); ?>"
                                    data-avatar-review-image="<?php echo esc_url((string) ($avatar_review['image_url'] ?? '')); ?>"
                                    data-avatar-review-status="<?php echo esc_attr((string) ($avatar_review['status'] ?? '')); ?>"
                                    data-avatar-review-status-label="<?php echo esc_attr((string) ($avatar_review['status_label'] ?? '')); ?>"
                                    data-avatar-review-can="<?php echo !empty($avatar_review['can_review']) ? '1' : '0'; ?>">
                                    <td><?php echo esc_html(number_format_i18n($index + 1)); ?></td>
                                    <td><?php echo esc_html($audience_label); ?></td>
                                    <td><span class="hst-status hst-status--info"><?php echo esc_html($notice_type_label); ?></span></td>
                                    <td><span class="hst-status <?php echo $src === 'auto' ? 'hst-status--success' : 'hst-status--muted'; ?>"><?php echo esc_html($source_label); ?></span></td>
                                    <td>
                                        <?php if ($is_manager) : ?>
                                            <label class="hst-switch" title="<?php echo esc_attr($notice->is_active ? 'غیرفعال کردن' : 'فعال کردن'); ?>" aria-label="<?php echo esc_attr($notice->is_active ? 'غیرفعال کردن' : 'فعال کردن'); ?>">
                                                <input type="checkbox"
                                                       class="hst-toggle-notification"
                                                       data-id="<?php echo esc_attr($notice->id); ?>"
                                                       <?php checked((int) $notice->is_active, 1); ?>>
                                                <span class="hst-switch__slider"></span>
                                            </label>
                                        <?php else : ?>
                                            <span class="hst-status <?php echo $is_read ? 'hst-status--muted' : 'hst-status--warning'; ?>"><?php echo esc_html($status_label); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($is_manager) : ?>
                                        <td>
                                            <?php if (!empty($notice->sms_sent_at)) : ?>
                                                <span class="hst-status hst-status--success hst-sms-sent-label">پیامک ارسال شده</span>
                                            <?php else : ?>
                                                <label class="hst-switch" title="فعال‌سازی پیامک اطلاعیه" aria-label="فعال‌سازی پیامک اطلاعیه">
                                                    <input type="checkbox"
                                                           class="hst-toggle-notification-sms"
                                                           data-id="<?php echo esc_attr($notice->id); ?>"
                                                           <?php checked((int) ($notice->sms_enabled ?? 0), 1); ?>
                                                           <?php disabled(!$hst_sms_ready); ?>>
                                                    <span class="hst-switch__slider"></span>
                                                </label>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td><?php echo esc_html($created_label); ?></td>
                                    <td class="hst-actions">
                                        <div class="hst-btn-group">
                                            <button type="button"
                                                    class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon hst-view-notification"
                                                    title="مشاهده"
                                                    aria-label="مشاهده">
                                                <?php echo hst_icon('view'); ?>
                                            </button>

                                            <?php if (!empty($avatar_review['can_review'])) : ?>
                                                <button type="button"
                                                        class="hst-btn hst-btn--primary hst-btn--sm hst-btn--icon"
                                                        data-hst-avatar-notification-action="approve"
                                                        data-user-id="<?php echo esc_attr((int) $avatar_review['user_id']); ?>"
                                                        data-notification-id="<?php echo esc_attr((int) $notice->id); ?>"
                                                        title="تأیید تصویر"
                                                        aria-label="تأیید تصویر">
                                                    <?php echo hst_icon('avatar-approve'); ?>
                                                </button>
                                                <button type="button"
                                                        class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon"
                                                        data-hst-avatar-notification-action="reject"
                                                        data-user-id="<?php echo esc_attr((int) $avatar_review['user_id']); ?>"
                                                        data-notification-id="<?php echo esc_attr((int) $notice->id); ?>"
                                                        title="رد تصویر"
                                                        aria-label="رد تصویر">
                                                    <?php echo hst_icon('avatar-reject'); ?>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($is_manager) : ?>
                                                <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-notification-report" title="گزارش" aria-label="گزارش">
                                                    <?php echo hst_icon('report-card'); ?><span>گزارش</span>
                                                </button>
                                                <button type="button"
                                                        class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon hst-delete-notification"
                                                        title="حذف"
                                                        aria-label="حذف">
                                                    <?php echo hst_icon('delete'); ?>
                                                </button>
                                            <?php elseif (!$is_read) : ?>
                                                <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-mark-notification-read" title="خوانده شد" aria-label="خوانده شد">
                                                    <?php echo hst_icon('notification-read'); ?><span>خوانده شد</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="hst-notice hst-notif-empty-filtered hst-empty-state hst-empty-state--inline" id="hst-notif-empty-filtered" data-hst-inline-empty hidden>موردی با این فیلتر پیدا نشد.</p>
            <?php else : ?>
                <p class="hst-alert hst-empty-state">فعلاً اطلاعیه‌ای برای نمایش وجود ندارد.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
