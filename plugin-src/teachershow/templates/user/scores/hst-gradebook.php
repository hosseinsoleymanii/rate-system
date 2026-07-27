<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
?>
<section class="hst-page hst-scores" data-hst-gradebook>
    <div class="hst-card">
        <div class="hst-card__header hst-card__header--stack">
            <h3>دفتر نمره</h3>
            <p>برای هر دانش‌آموز تا ۸ نمره (کوییز، فعالیت کلاسی و…) در هر دوره ثبت کنید. میانگین خودکار محاسبه می‌شود و هنگام ثبت «نمرهٔ دوره‌ای» پیشنهاد داده می‌شود.</p>
        </div>
        <div class="hst-card__body">
            <?php if (!$active_term) : ?>
                <p class="hst-alert">در حال حاضر سال تحصیلی فعالی وجود ندارد.</p>
            <?php else : ?>
                <div class="hst-scores-toolbar">
                    <label class="hst-field">
                        <span>کلاس</span>
                        <select id="hst-gb-class"><option value="">انتخاب کلاس</option></select>
                    </label>
                    <label class="hst-field">
                        <span>درس</span>
                        <select id="hst-gb-lesson" disabled><option value="">ابتدا کلاس را انتخاب کنید</option></select>
                    </label>
                    <label class="hst-field">
                        <span>دوره</span>
                        <select id="hst-gb-period">
                            <?php if (empty($months)) : ?>
                                <option value="">دوره‌ای تعریف نشده است</option>
                            <?php else : ?>
                                <?php foreach ($months as $month) : ?>
                                    <option value="<?php echo esc_attr($month->month_key); ?>" data-active="<?php echo esc_attr((int) $month->is_active); ?>"><?php echo esc_html($month->month_label); ?><?php echo $month->is_active ? '' : ' — فقط مشاهده'; ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </label>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($active_term) : ?>
        <div class="hst-scores-statbar" id="hst-gb-statbar" hidden>
            <div class="hst-scores-stat hst-scores-stat--accent">
                <span class="hst-scores-stat__value" data-stat="students">۰</span>
                <span class="hst-scores-stat__label">دانش‌آموزان</span>
            </div>
            <div class="hst-scores-stat">
                <span class="hst-scores-stat__value" data-stat="entries">۰</span>
                <span class="hst-scores-stat__label">نمرات ثبت‌شده</span>
            </div>
            <div class="hst-scores-stat hst-scores-stat--success">
                <span class="hst-scores-stat__value" data-stat="avg">—</span>
                <span class="hst-scores-stat__label">میانگین کلاس</span>
            </div>
            <div class="hst-scores-monthstate" id="hst-gb-periodstate"></div>
        </div>

        <div id="hst-gb-body" hidden>
            <div id="hst-gb-list" class="hst-scores-list hst-gb-list hst-vstack"></div>
            <div class="hst-scores-footer" id="hst-gb-footer">
                <p class="hst-muted">نمرهٔ بدون عدد ذخیره نمی‌شود. عنوان هر نمره اختیاری است.</p>
                <button type="button" class="hst-btn hst-btn--primary" id="hst-gb-save">ذخیرهٔ دفتر نمره</button>
            </div>
        </div>

        <div class="hst-card hst-scores-empty" id="hst-gb-empty" hidden>
            <div class="hst-card__body">
                <p class="hst-notice" id="hst-gb-empty-text">برای شروع، کلاس و درس را انتخاب کنید.</p>
            </div>
        </div>
    <?php endif; ?>
</section>
