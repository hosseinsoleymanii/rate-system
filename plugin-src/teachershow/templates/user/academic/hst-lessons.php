<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-icons.php';

// Build distinct option lists for the filter selects.
$hst_lesson_classes = [];
$hst_lesson_units = [];
foreach ((array) $lessons as $lesson) {
    if (!empty($lesson->class_name)) {
        $hst_lesson_classes[$lesson->class_name] = true;
    }
    if (isset($lesson->unit) && $lesson->unit !== '') {
        $hst_lesson_units[(string) $lesson->unit] = true;
    }
}
$hst_lesson_classes = HST_Classes::sort_names(array_keys($hst_lesson_classes));
$hst_lesson_units = array_keys($hst_lesson_units);
sort($hst_lesson_units, SORT_NUMERIC);
?>
<section class="hst-page hst-management-page hst-module hst-module--lessons">
    <div class="hst-inline-filter" data-hst-inline-filter="hst-lessons-table">
<div class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html(HST_Settings::management_page_title('lessons', 'درس‌ها')); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-stack">
<button type="button" class="hst-btn--icon hst-btn hst-btn--primary hst-btn--sm hst-lessons-add hst-inline-filter__add" id="hst-lessons-add" data-hst-classes="<?php echo esc_attr(wp_json_encode(array_map(function ($c) { return ['id' => (int) $c->id, 'name' => $c->class_name]; }, (array) $classes))); ?>" title="افزودن درس" aria-label="افزودن درس"><?php echo hst_icon('add'); ?><span>افزودن درس</span></button>
                <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" id="hst-lessons-add-high-theory" title="افزودن خودکار دروس متوسطه دوم" aria-label="افزودن خودکار دروس متوسطه دوم"><?php echo hst_icon('import'); ?><span>دروس متوسطه دوم</span></button>
                
                <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__body hst-section-card__body">
<div class="hst-inline-filter__main">
                    <div class="hst-inline-filter__search">
                        <span class="hst-inline-filter__search-icon" aria-hidden="true"><?php echo hst_icon('lessons'); ?></span>
                        <input type="search" class="hst-search" data-hst-inline-search placeholder="جست‌وجوی درس..." autocomplete="off">
                    </div>
                    <select class="hst-select hst-inline-filter__select" data-hst-inline-select="class" aria-label="فیلتر کلاس">
                        <option value="">همهٔ کلاس‌ها</option>
                        <?php foreach ($hst_lesson_classes as $cname) : ?>
                            <option value="<?php echo esc_attr($cname); ?>"><?php echo esc_html($cname); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="hst-select hst-inline-filter__select" data-hst-inline-select="unit" aria-label="فیلتر واحد">
                        <option value="">همهٔ واحدها</option>
                        <?php foreach ($hst_lesson_units as $u) : ?>
                            <option value="<?php echo esc_attr($u); ?>"><?php echo esc_html($u); ?> واحد</option>
                        <?php endforeach; ?>
                    </select>
                </div>
        </div>
    </div>
</div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__header hst-section-card__header"><h3>لیست درس‌ها</h3></div>
        <div class="hst-card__body hst-section-card__body">
            <?php if (!empty($lessons)) : ?>
                <div class="hst-table-wrap hst-data-table-wrap hst-data-table">
                    <table class="hst-table hst-data-table" id="hst-lessons-table">
                        <thead><tr><th>ردیف</th><th>نام کلاس</th><th class="hst-col-fill">نام درس</th><th>واحد</th><th>عملیات</th></tr></thead>
                        <tbody>
                            <?php foreach ($lessons as $index => $lesson) : ?>
                                <?php
                                $hst_can_delete = !isset($lesson->can_delete) || (int) $lesson->can_delete === 1;
                                $hst_delete_title = $hst_can_delete ? 'حذف' : ($lesson->delete_disabled_reason ?: 'این درس قابل حذف نیست.');
                                ?>
                                <tr data-id="<?php echo esc_attr($lesson->id); ?>"
                                    data-hst-search="<?php echo esc_attr($lesson->lesson_name . ' ' . $lesson->class_name . ' ' . $lesson->unit); ?>"
                                    data-hst-class="<?php echo esc_attr($lesson->class_name); ?>"
                                    data-hst-unit="<?php echo esc_attr($lesson->unit); ?>">
                                    <td><?php echo esc_html($index + 1); ?></td>
                                    <td><?php echo esc_html($lesson->class_name); ?></td>
                                    <td class="hst-col-fill"><?php echo esc_html($lesson->lesson_name); ?></td>
                                    <td><?php echo esc_html($lesson->unit); ?></td>
                                    <td class="hst-actions">
                                        <div class="hst-btn-group">
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon hst-edit" data-id="<?php echo esc_attr($lesson->id); ?>" data-name="<?php echo esc_attr($lesson->lesson_name); ?>" data-class-id="<?php echo esc_attr($lesson->class_id); ?>" data-unit="<?php echo esc_attr($lesson->unit); ?>" title="ویرایش" aria-label="ویرایش"><?php echo hst_icon('edit'); ?></button>
                                            <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon hst-delete" data-id="<?php echo esc_attr($lesson->id); ?>" <?php disabled(!$hst_can_delete); ?> title="<?php echo esc_attr($hst_delete_title); ?>" aria-label="حذف"><?php echo hst_icon('delete'); ?></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="hst-muted hst-inline-filter__empty hst-empty-state hst-empty-state--inline" data-hst-inline-empty hidden>موردی با این فیلتر پیدا نشد.</p>
            <?php else : ?>
                <p class="hst-alert hst-empty-state">هنوز درسی تعریف نشده است.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
