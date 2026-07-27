<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-icons.php';
include_once HST_PATH . 'templates/user/common/hst-user-avatar.php';
$hst_teacher_add_title = 'افزودن معلم';
?>
<section class="hst-page hst-management-page hst-module hst-module--teachers">
    <div class="hst-inline-filter" data-hst-inline-filter="hst-teachers-table">
<div class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html(HST_Settings::management_page_title('teachers', 'معلمان')); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-stack">
<div class="hst-inline-filter__add">
                    <button type="button" class="hst-btn--icon hst-btn hst-btn--primary hst-btn--sm" id="hst-teacher-add" title="<?php echo esc_attr($hst_teacher_add_title); ?>" aria-label="<?php echo esc_attr($hst_teacher_add_title); ?>"><?php echo hst_icon('add'); ?><span>افزودن معلم</span></button>
                    <?php $hst_all_schedules_title = $has_teacher_schedules ? 'دریافت برنامه معلمان' : 'هنوز برنامه‌ای برای معلمان ثبت نشده است.'; ?>
                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-schedule-pdf data-type="all_teachers" <?php disabled(!$has_teacher_schedules); ?> title="<?php echo esc_attr($hst_all_schedules_title); ?>" aria-label="<?php echo esc_attr($hst_all_schedules_title); ?>">
                        <span class="hst-btn__icon" aria-hidden="true"><?php echo hst_icon('schedule'); ?></span>
                        <span>برنامه معلمان</span>
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
                        <span class="hst-inline-filter__search-icon" aria-hidden="true"><?php echo hst_icon('teachers'); ?></span>
                        <input type="search" class="hst-search" data-hst-inline-search placeholder="جست‌وجوی معلم..." autocomplete="off">
                    </div>
                </div>
        </div>
    </div>
</div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__header hst-section-card__header"><h3>لیست معلمان</h3></div>
        <div class="hst-card__body hst-section-card__body">
            <?php if (!empty($teachers)) : ?>
                <div class="hst-table-wrap hst-data-table-wrap hst-data-table">
                    <table class="hst-table hst-data-table" id="hst-teachers-table">
                        <thead><tr><th>ردیف</th><th class="hst-col-fill">نام معلم</th><th>کد ملی</th><th>کد پرسنلی</th><th>شماره موبایل</th><th>عملیات</th></tr></thead>
                        <tbody>
                            <?php foreach ($teachers as $index => $teacher) :
                                $t_national = get_user_meta($teacher->ID, 'hst_national_code', true);
                                $t_personnel = get_user_meta($teacher->ID, 'hst_personnel_code', true);
                                $t_phone = class_exists('HST_User_Phones') ? HST_User_Phones::get((int) $teacher->ID) : get_user_meta($teacher->ID, 'phone', true);
                                $hst_can_delete = (int) $teacher->ID !== (int) get_current_user_id();
                                $hst_delete_title = $hst_can_delete ? 'حذف' : 'امکان حذف حساب کاربری فعلی وجود ندارد.';
                                $hst_has_schedule = !empty($teacher_schedule_lookup[(int) $teacher->ID]);
                                $hst_schedule_title = $hst_has_schedule ? 'دریافت برنامه' : 'برای این معلم برنامه‌ای ثبت نشده است.';
                            ?>
                                <tr data-id="<?php echo esc_attr($teacher->ID); ?>"
                                    data-hst-search="<?php echo esc_attr($teacher->display_name . ' ' . ($teacher->classes ?? '') . ' ' . ($teacher->lessons ?? '') . ' ' . $t_national . ' ' . $t_personnel . ' ' . $t_phone); ?>"
                                    data-hst-class="<?php echo esc_attr(str_replace(',', '|', (string) ($teacher->classes ?? ''))); ?>">
                                    <td><?php echo esc_html($index + 1); ?></td>
                                    <td class="hst-col-fill"><?php echo hst_user_cell($teacher->ID, $teacher->display_name); ?></td>
                                    <td><?php echo $t_national ? esc_html($t_national) : '<span class="hst-muted">—</span>'; ?></td>
                                    <td><?php echo $t_personnel ? esc_html($t_personnel) : '<span class="hst-muted">—</span>'; ?></td>
                                    <td><?php echo $t_phone ? esc_html($t_phone) : '<span class="hst-muted">—</span>'; ?></td>
                                    <td class="hst-actions">
                                        <div class="hst-btn-group">
                                            <button type="button" class="hst-btn hst-btn--ghost hst-btn--sm hst-btn--icon hst-teacher-view" data-id="<?php echo esc_attr($teacher->ID); ?>" title="مشاهده" aria-label="مشاهده"><?php echo hst_icon('view'); ?></button>
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon hst-teacher-edit" data-id="<?php echo esc_attr($teacher->ID); ?>" title="ویرایش" aria-label="ویرایش"><?php echo hst_icon('edit'); ?></button>
                                            <button type="button" class="hst-btn hst-btn--ghost hst-btn--sm" data-hst-schedule-pdf data-type="teacher" data-teacher-id="<?php echo esc_attr($teacher->ID); ?>" <?php disabled(!$hst_has_schedule); ?> title="<?php echo esc_attr($hst_schedule_title); ?>" aria-label="<?php echo esc_attr($hst_schedule_title); ?>"><?php echo hst_icon('schedule'); ?><span>برنامه</span></button>
                                            <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon hst-delete" data-id="<?php echo esc_attr($teacher->ID); ?>" <?php disabled(!$hst_can_delete); ?> title="<?php echo esc_attr($hst_delete_title); ?>" aria-label="حذف"><?php echo hst_icon('delete'); ?></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="hst-muted hst-inline-filter__empty hst-empty-state hst-empty-state--inline" data-hst-inline-empty hidden>موردی با این فیلتر پیدا نشد.</p>
            <?php else : ?>
                <p class="hst-alert hst-empty-state">هنوز معلمی تعریف نشده است.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- View-teacher modal -->
<div class="hst-modal" id="hst-teacher-view-modal" data-hst-modal-tone="detail" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-teacher-view-title">
    <div class="hst-modal__backdrop" data-hst-view-close></div>
    <div class="hst-modal__panel">
        <div class="hst-modal__header">
            <div class="hst-modal__title">
                <span class="hst-modal__icon" aria-hidden="true"><?php echo hst_icon('teachers'); ?></span>
                <div>
                    <h3 id="hst-teacher-view-title">اطلاعات معلم</h3>
                    <p>مشخصات و اطلاعات کامل معلم</p>
                </div>
            </div>
            <button type="button" class="hst-modal__close" data-hst-view-close aria-label="بستن">&times;</button>
        </div>
        <div class="hst-modal__body" id="hst-teacher-view-body">
            <p class="hst-muted"><?php echo hst_loading_state(); ?></p>
        </div>
        <div class="hst-modal__footer">
            <button type="button" class="hst-btn hst-btn--soft" data-hst-view-close>بستن</button>
        </div>
    </div>
</div>

<!-- Add-teacher modal -->
<div class="hst-modal" id="hst-teacher-modal" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-teacher-modal-title">
    <div class="hst-modal__backdrop" data-hst-modal-close></div>
    <div class="hst-modal__panel">
        <div class="hst-modal__header">
            <h3 id="hst-teacher-modal-title">افزودن معلم</h3>
            <button type="button" class="hst-modal__close" data-hst-modal-close aria-label="بستن">&times;</button>
        </div>
        <div class="hst-modal__body">
            <form class="hst-form" id="define-teacher-form" autocomplete="off">
                <div class="hst-field-grid">
                    <label class="hst-field">
                        <span>نام معلم</span>
                        <input class="hst-keynav" type="text" name="teacher_name" placeholder="نام" required>
                    </label>
                    <label class="hst-field">
                        <span>نام خانوادگی</span>
                        <input class="hst-keynav" type="text" name="teacher_last_name" placeholder="نام خانوادگی" required>
                    </label>
                    <label class="hst-field">
                        <span>شماره موبایل</span>
                        <input class="hst-keynav" type="tel" name="teacher_phone" placeholder="0912..." inputmode="tel" required>
                    </label>
                    <label class="hst-field">
                        <span>کد ملی</span>
                        <input class="hst-keynav" type="text" name="teacher_national_code" placeholder="کد ملی" inputmode="numeric" maxlength="10" required>
                    </label>
                    <label class="hst-field">
                        <span>کد پرسنلی</span>
                        <input class="hst-keynav" type="text" name="teacher_personnel_code" placeholder="کد پرسنلی" inputmode="numeric" maxlength="20" required>
                    </label>
                    <label class="hst-field">
                        <span>تاریخ تولد</span>
                        <input class="hst-keynav hst-jalali-birthdate" type="text" name="teacher_birthdate" readonly placeholder="انتخاب تاریخ" autocomplete="off" required>
                    </label>
                </div>

            </form>
        </div>
        <div class="hst-modal__footer">
            <button type="submit" class="hst-btn" form="define-teacher-form">ذخیره تغییرات</button>
            <button type="button" class="hst-btn hst-btn--ghost" data-hst-modal-close>بستن</button>
        </div>
    </div>
</div>
