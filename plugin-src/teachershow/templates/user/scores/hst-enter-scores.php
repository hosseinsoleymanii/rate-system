<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';

$period_context = is_array($period_context ?? null) ? $period_context : [];
$active_term = $period_context['active_term'] ?? ($active_term ?? null);
$months = $period_context['periods'] ?? ($period_context['months'] ?? []);
?>
<section class="hst-page hst-scores" data-hst-enter-scores>
    <div class="hst-card">
        <div class="hst-card__header hst-card__header--stack">
            <h3>نمرهٔ دوره‌ای</h3>
            <p>نمره‌های هر دانش‌آموز را بر اساس نوع دوره انتخاب‌شده ثبت کنید. با انتخاب کلاس، درس و دوره، فهرست به‌صورت خودکار بارگذاری می‌شود.</p>
        </div>
        <div class="hst-card__body">
            <?php if (!$active_term) : ?>
                <p class="hst-alert">در حال حاضر سال تحصیلی فعالی برای ثبت یا مشاهده نمره وجود ندارد.</p>
            <?php else : ?>
                <div class="hst-scores-toolbar">
                    <label class="hst-field">
                        <span>کلاس</span>
                        <select id="hst-score-class"><option value="">انتخاب کلاس</option></select>
                    </label>
                    <label class="hst-field">
                        <span>درس</span>
                        <select id="hst-score-lesson" disabled><option value="">ابتدا کلاس را انتخاب کنید</option></select>
                    </label>
                    <label class="hst-field">
                        <span>دوره</span>
                        <select id="hst-score-period">
                            <?php if (empty($months)) : ?>
                                <option value="">دوره‌ای برای این سال تحصیلی تعریف نشده است</option>
                            <?php else : ?>
                                <?php foreach ($months as $month) : ?>
                                    <option value="<?php echo esc_attr($month->month_key); ?>" data-active="<?php echo esc_attr((int) $month->is_active); ?>">
                                        <?php echo esc_html($month->month_label); ?><?php echo $month->is_active ? '' : ' — فقط مشاهده'; ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </label>
                    <label class="hst-field">
                        <span>مرتب‌سازی</span>
                        <select id="hst-score-sort">
                            <option value="name">نام خانوادگی</option>
                            <option value="score-desc">نمره: زیاد به کم</option>
                            <option value="score-asc">نمره: کم به زیاد</option>
                            <option value="empty">بدون نمره (اول)</option>
                        </select>
                    </label>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($active_term) : ?>
        <!-- live status bar -->
        <div class="hst-scores-statbar" id="hst-score-statbar" hidden>
            <div class="hst-scores-stat hst-scores-stat--accent">
                <span class="hst-scores-stat__value" data-stat="done">۰</span>
                <span class="hst-scores-stat__label">ثبت‌شده</span>
            </div>
            <div class="hst-scores-stat">
                <span class="hst-scores-stat__value" data-stat="total">۰</span>
                <span class="hst-scores-stat__label">کل نمره‌های قابل ثبت</span>
            </div>
            <div class="hst-scores-stat hst-scores-stat--success">
                <span class="hst-scores-stat__value" data-stat="avg">—</span>
                <span class="hst-scores-stat__label">میانگین کلاس</span>
            </div>
            <div class="hst-scores-progress" aria-hidden="true">
                <div class="hst-scores-progress__bar" id="hst-score-progress"></div>
            </div>
            <div class="hst-scores-monthstate" id="hst-score-periodstate"></div>
        </div>

        <div class="hst-card hst-scores-result" id="hst-score-result" hidden>
            <div class="hst-card__body">
                <form id="hst-score-form" class="hst-stack">
                    <div id="hst-score-students" class="hst-scores-list hst-vstack"></div>
                    <div class="hst-scores-footer" id="hst-score-footer">
                        <p class="hst-muted" id="hst-score-form-note">برای حذف نمرهٔ یک دانش‌آموز، فیلد نمره را خالی بگذارید و ذخیره کنید.</p>
                        <button type="submit" class="hst-btn hst-btn--primary" id="hst-save-scores">ذخیرهٔ نمرات</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="hst-card hst-scores-empty" id="hst-score-empty" hidden>
            <div class="hst-card__body">
                <p class="hst-notice" id="hst-score-empty-text">برای شروع، کلاس و درس را انتخاب کنید.</p>
            </div>
        </div>
    <?php endif; ?>
</section>
