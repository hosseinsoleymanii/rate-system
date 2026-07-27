<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
?>
<section class="hst-page hst-management-page hst-module hst-module--classes">
    <div class="hst-inline-filter" data-hst-inline-filter="hst-classes-table">
<div class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html(HST_Settings::management_page_title('classes', 'کلاس‌ها')); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-stack">
<button type="button" class="hst-btn--icon hst-btn hst-btn--primary hst-btn--sm hst-classes-add hst-inline-filter__add" id="hst-classes-add" title="افزودن کلاس" aria-label="افزودن کلاس"><?php echo hst_icon('add'); ?><span>افزودن کلاس</span></button>
                <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" id="hst-classes-add-high-theory" title="افزودن خودکار کلاس‌های متوسطه دوم" aria-label="افزودن خودکار کلاس‌های متوسطه دوم"><?php echo hst_icon('import'); ?><span>کلاس‌های متوسطه دوم</span></button>
                
                <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__body hst-section-card__body">
<div class="hst-inline-filter__main">
                    <div class="hst-inline-filter__search">
                        <span class="hst-inline-filter__search-icon" aria-hidden="true"><?php echo hst_icon('classes'); ?></span>
                        <input type="search" class="hst-search" data-hst-inline-search placeholder="جست‌وجوی کلاس..." autocomplete="off">
                    </div>
                </div>
        </div>
    </div>
</div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__header hst-section-card__header"><h3>لیست کلاس‌ها</h3></div>
        <div class="hst-card__body hst-section-card__body">
            <?php if (!empty($classes)) : ?>
                <div class="hst-table-wrap hst-data-table-wrap hst-data-table">
                    <table class="hst-table hst-data-table" id="hst-classes-table">
                        <thead><tr><th>ردیف</th><th class="hst-col-fill">نام کلاس</th><th>عملیات</th></tr></thead>
                        <tbody>
                            <?php foreach ($classes as $index => $class) : ?>
                                <?php
                                $hst_can_delete = !isset($class->can_delete) || (int) $class->can_delete === 1;
                                $hst_delete_title = $hst_can_delete ? 'حذف' : ($class->delete_disabled_reason ?: 'این کلاس قابل حذف نیست.');
                                ?>
                                <tr data-id="<?php echo esc_attr($class->id); ?>" data-hst-search="<?php echo esc_attr($class->class_name); ?>">
                                    <td><?php echo esc_html($index + 1); ?></td>
                                    <td class="hst-col-fill"><?php echo esc_html($class->class_name); ?></td>
                                    <td class="hst-actions">
                                        <div class="hst-btn-group">
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon hst-edit" data-id="<?php echo esc_attr($class->id); ?>" data-name="<?php echo esc_attr($class->class_name); ?>" title="ویرایش" aria-label="ویرایش"><?php echo hst_icon('edit'); ?></button>
                                            <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon hst-delete" data-id="<?php echo esc_attr($class->id); ?>" <?php disabled(!$hst_can_delete); ?> title="<?php echo esc_attr($hst_delete_title); ?>" aria-label="حذف"><?php echo hst_icon('delete'); ?></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="hst-inline-filter__empty hst-empty-state hst-empty-state--inline" data-hst-inline-empty hidden>موردی با این فیلتر پیدا نشد.</p>
            <?php else : ?>
                <p class="hst-alert hst-empty-state">هنوز کلاسی تعریف نشده است.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
</div>
