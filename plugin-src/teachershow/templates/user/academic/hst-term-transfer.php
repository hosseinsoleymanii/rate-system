<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-icons.php';
?>
<section class="hst-page hst-term-transfer hst-management-page hst-module hst-module--term-transfer" data-hst-term-transfer>
    <div class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html(HST_Settings::management_page_title('term-transfer', 'انتقال سال تحصیلی')); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-inline-filter__add">
                <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__header hst-card__header--stack hst-section-card__header">
            <h3>انتخاب سال تحصیلی انتقال</h3>
            <p>دانش‌آموزان را از یک سال تحصیلی به سال تحصیلی بعدی منتقل کنید. برای هر کلاس مبدأ، کلاس مقصد را انتخاب کنید.</p>
        </div>
        <div class="hst-card__body hst-section-card__body">
            <?php if (count($terms) < 1) : ?>
                <p class="hst-alert hst-empty-state">برای استفاده از این بخش، باید حداقل یک سال تحصیلی تعریف شده باشد.</p>
            <?php else : ?>
                <div class="hst-tt-selection-grid" dir="rtl">
                    <div class="hst-filters__field hst-tt-selection-field">
                        <label for="hst-tt-source">سال تحصیلی مبدأ</label>
                        <select id="hst-tt-source" class="hst-select">
                            <option value="">انتخاب سال تحصیلی مبدأ</option>
                            <?php foreach ($terms as $t) : ?>
                                <option value="<?php echo esc_attr($t->id); ?>"><?php echo esc_html($t->term_name); ?><?php echo (int) $t->is_active ? ' (فعال)' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="hst-filters__field hst-tt-selection-field">
                        <label for="hst-tt-target">سال تحصیلی مقصد</label>
                        <select id="hst-tt-target" class="hst-select">
                            <option value="">انتخاب سال تحصیلی مقصد</option>
                            <?php foreach ($terms as $t) : ?>
                                <option value="<?php echo esc_attr($t->id); ?>"><?php echo esc_html($t->term_name); ?><?php echo (int) $t->is_active ? ' (فعال)' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (count($terms) >= 1) : ?>
        <div class="hst-card hst-tt-mapping-card hst-section-card" id="hst-tt-mapping-card" hidden>
            <div class="hst-card__header hst-card__header--stack hst-section-card__header">
                <h3>تعیین مقصد هر کلاس</h3>
            </div>
            <div class="hst-card__body hst-section-card__body">
                <div id="hst-tt-mapping" class="hst-tt-mapping hst-vstack"></div>
                <div class="hst-btn-group">
                    <button type="button" class="hst-btn hst-btn--primary hst-tt-action-btn" id="hst-tt-execute">اجرای انتقال</button>
                </div>
            </div>
        </div>

        <div class="hst-card hst-tt-result-card hst-section-card" id="hst-tt-result-card" hidden>
            <div class="hst-card__header hst-section-card__header"><h3>نتیجهٔ انتقال</h3></div>
            <div class="hst-card__body hst-section-card__body">
                <div class="hst-report-stats">
                    <div class="hst-report-stat hst-report-stat--new"><b data-tt="transferred">۰</b><span>منتقل‌شده</span></div>
                    <div class="hst-report-stat hst-report-stat--skip"><b data-tt="skipped">۰</b><span>رد‌شده / تکراری</span></div>
                </div>
                <div id="hst-tt-result-details" class="hst-tt-result-details"></div>
            </div>
        </div>
    <?php endif; ?>

    <div class="hst-modal" id="hst-tt-students-modal" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-tt-students-modal-title">
        <div class="hst-modal__backdrop" data-tt-students-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div>
                    <h3 id="hst-tt-students-modal-title">انتخاب دانش‌آموزان برای انتقال</h3>
                    <p class="hst-muted" id="hst-tt-students-modal-subtitle">همه دانش‌آموزان کلاس به‌صورت پیش‌فرض انتخاب شده‌اند.</p>
                </div>
                <button type="button" class="hst-modal__close" data-tt-students-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <div class="hst-tt-students-toolbar">
                    <label class="hst-checkline hst-tt-select-all-line">
                        <input type="checkbox" id="hst-tt-students-select-all" checked>
                        <span>انتخاب همه دانش‌آموزان این کلاس</span>
                    </label>
                    <span class="hst-badge" id="hst-tt-students-selected-count">۰ انتخاب</span>
                </div>
                <div class="hst-tt-students-list" id="hst-tt-students-list">
                    <p class="hst-muted"><?php echo hst_loading_state(); ?></p>
                </div>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--primary" id="hst-tt-students-apply">ذخیره تغییرات</button>
                <button type="button" class="hst-btn hst-btn--soft" data-tt-students-close>بستن</button>
            </div>
        </div>
    </div>

</section>
</div>
