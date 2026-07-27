<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-icons.php';
?>
<section class="hst-page hst-management-page hst-module hst-module--terms">
    <div class="hst-inline-filter" data-hst-inline-filter="hst-terms-table">
<div class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html(HST_Settings::management_page_title('terms', 'سال‌های تحصیلی')); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-stack">
<button type="button" class="hst-btn--icon hst-btn hst-btn--primary hst-btn--sm hst-terms-add hst-inline-filter__add" id="hst-terms-add" title="افزودن سال تحصیلی" aria-label="افزودن سال تحصیلی"><?php echo hst_icon('add'); ?><span>افزودن سال تحصیلی</span></button>
                
                <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__body hst-section-card__body">
<div class="hst-inline-filter__main">
                    <div class="hst-inline-filter__search">
                        <span class="hst-inline-filter__search-icon" aria-hidden="true"><?php echo hst_icon('terms'); ?></span>
                        <input type="search" class="hst-search" data-hst-inline-search placeholder="جست‌وجوی سال تحصیلی..." autocomplete="off">
                    </div>
                    <select class="hst-select hst-inline-filter__select" data-hst-inline-select="status" data-hst-segmented-label="none" aria-label="فیلتر وضعیت">
                        <option value="">همهٔ وضعیت‌ها</option>
                        <option value="active">فعال</option>
                        <option value="inactive">غیرفعال</option>
                    </select>
                </div>
        </div>
    </div>
</div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__header hst-section-card__header"><h3>لیست سال‌های تحصیلی</h3></div>
        <div class="hst-card__body hst-section-card__body">
            <?php if (!empty($terms)) : ?>
                <div class="hst-table-wrap hst-data-table-wrap hst-data-table">
                    <table class="hst-table hst-data-table" id="hst-terms-table">
                        <thead><tr><th>ردیف</th><th class="hst-col-fill">نام سال تحصیلی</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                        <tbody>
                            <?php foreach ($terms as $index => $term) : ?>
                                <?php
                                $hst_can_delete = !isset($term->can_delete) || (int) $term->can_delete === 1;
                                $hst_delete_title = $hst_can_delete ? 'حذف' : ($term->delete_disabled_reason ?: 'این سال تحصیلی قابل حذف نیست.');
                                ?>
                                <tr data-id="<?php echo esc_attr($term->id); ?>" data-hst-search="<?php echo esc_attr($term->term_name); ?>" data-hst-status="<?php echo ((int) $term->is_active === 1) ? 'active' : 'inactive'; ?>">
                                    <td><?php echo esc_html($index + 1); ?></td>
                                    <td class="hst-col-fill"><?php echo esc_html($term->term_name); ?></td>
                                    <td>
                                        <label class="hst-switch" aria-label="تغییر وضعیت سال تحصیلی">
                                            <input type="checkbox" class="hst-term-status" data-id="<?php echo esc_attr($term->id); ?>" <?php checked((int) $term->is_active, 1); ?>>
                                            <span class="hst-switch__slider"></span>
                                        </label>
                                    </td>
                                    <td class="hst-actions">
                                        <div class="hst-btn-group">
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon hst-edit" data-id="<?php echo esc_attr($term->id); ?>" data-name="<?php echo esc_attr($term->term_name); ?>" data-is-active="<?php echo esc_attr($term->is_active ?? 1); ?>" title="ویرایش" aria-label="ویرایش"><?php echo hst_icon('edit'); ?></button>
                                            <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon hst-delete" data-id="<?php echo esc_attr($term->id); ?>" <?php disabled(!$hst_can_delete); ?> title="<?php echo esc_attr($hst_delete_title); ?>" aria-label="حذف"><?php echo hst_icon('delete'); ?></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="hst-muted hst-inline-filter__empty hst-empty-state hst-empty-state--inline" data-hst-inline-empty hidden>موردی با این فیلتر پیدا نشد.</p>
            <?php else : ?>
                <p class="hst-alert hst-empty-state">هنوز سال تحصیلی تعریف نشده است.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
