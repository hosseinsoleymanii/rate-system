<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-icons.php';

$period_context = $period_context ?? [];
$active_term = $period_context['active_term'] ?? null;
$periods = $period_context['periods'] ?? [];
$types = $period_context['types'] ?? (class_exists('HST_Scores') ? HST_Scores::period_types() : []);
$summary = $period_context['summary'] ?? ['total' => 0, 'active' => 0, 'inactive' => 0, 'complete' => 0];
?>

<div class="hst-page hst-management-page hst-module hst-module--periods" data-hst-periods>
    <div class="hst-inline-filter" data-hst-inline-filter="hst-periods-list">
<section class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header">
            <h3><?php echo esc_html(HST_Settings::management_page_title('periods', 'دوره‌ها')); ?></h3>
        </div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-stack">
<div class="hst-inline-filter__add">
                    <button type="button" class="hst-btn--icon hst-btn hst-btn--primary hst-btn--sm" id="hst-period-add" <?php disabled(!$active_term); ?> title="<?php echo esc_attr($active_term ? 'افزودن دوره' : 'ابتدا یک سال تحصیلی فعال تعریف کنید.'); ?>" aria-label="<?php echo esc_attr($active_term ? 'افزودن دوره' : 'ابتدا یک سال تحصیلی فعال تعریف کنید.'); ?>"><?php echo hst_icon('add'); ?><span>افزودن دوره</span></button>
                </div>


                

                <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
            </div>
        </div>
    </section>

    <div class="hst-card hst-section-card">
        <div class="hst-card__body hst-section-card__body">
<div class="hst-inline-filter__main">
                    <div class="hst-inline-filter__search">
                        <span class="hst-inline-filter__search-icon" aria-hidden="true"><?php echo hst_icon('students'); ?></span>
                        <input type="search" class="hst-search" data-hst-inline-search placeholder="جست‌وجوی دوره..." autocomplete="off">
                    </div>

                    <select class="hst-inline-filter__select" data-hst-inline-select="type" aria-label="فیلتر نوع دوره">
                        <option value="">همهٔ نوع‌ها</option>
                        <?php foreach ($types as $type_key => $type_label) : ?>
                            <option value="<?php echo esc_attr($type_key); ?>"><?php echo esc_html($type_label); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select class="hst-inline-filter__select" data-hst-inline-select="status" aria-label="فیلتر وضعیت دوره">
                        <option value="">همهٔ وضعیت‌ها</option>
                        <option value="active">فعال</option>
                        <option value="inactive">غیرفعال</option>
                    </select>

                </div>
        </div>
    </div>
</div>


    <?php if ($active_term) : ?>
        <div class="hst-card hst-section-card" id="hst-periods-report-card">
            <div class="hst-card__header hst-section-card__header"><h3>گزارش دوره‌ها</h3></div>
            <div class="hst-card__body hst-section-card__body">
                <div class="hst-report-stats" id="hst-periods-statbar">
                    <div class="hst-report-stat hst-report-stat--total"><b><?php echo esc_html(number_format_i18n((int) ($summary['total'] ?? 0))); ?></b><span>کل دوره‌ها</span></div>
                    <div class="hst-report-stat hst-report-stat--new"><b><?php echo esc_html(number_format_i18n((int) ($summary['active'] ?? 0))); ?></b><span>دوره‌های فعال</span></div>
                    <div class="hst-report-stat hst-report-stat--skip"><b><?php echo esc_html(number_format_i18n((int) ($summary['inactive'] ?? 0))); ?></b><span>دوره‌های غیرفعال</span></div>
                    <div class="hst-report-stat hst-report-stat--upd"><b><?php echo esc_html(number_format_i18n((int) ($summary['complete'] ?? 0))); ?></b><span>ثبت کامل</span></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php if (!$active_term) : ?>
        <section class="hst-card">
            <div class="hst-card__body">
                <p class="hst-alert hst-alert--warning">برای ثبت دوره‌ها ابتدا یک سال تحصیلی فعال تعریف کنید.</p>
            </div>
        </section>
    <?php else : ?>
        <section class="hst-card hst-section-card">
            <div class="hst-card__header hst-section-card__header">
                <h3>لیست دوره‌ها</h3>
            </div>
            <div class="hst-card__body hst-section-card__body">
                <?php if (empty($periods)) : ?>
                    <p class="hst-alert hst-empty-state">هنوز دوره‌ای برای این سال تحصیلی ثبت نشده است.</p>
                <?php else : ?>
                    <div class="hst-table-wrap">
                        <table class="hst-table hst-data-table">
                            <thead>
                                <tr>
                                    <th>ردیف</th>
                                    <th>نام دوره</th>
                                    <th>نوع دوره</th>
                                    <th>درصد ثبت نمرات</th>
                                    <th>وضعیت</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody id="hst-periods-list">
                                <?php foreach ($periods as $index => $period) : ?>
                                    <?php
                                    $is_active = (int) $period->is_active === 1;
                                    $status_key = $is_active ? 'active' : 'inactive';
                                    $created_label = !empty($period->created_at) && class_exists('HST_Date') ? HST_Date::format($period->created_at, 'Y/m/d', '') : (string) ($period->created_at ?? '');
                                    $hst_can_delete = !isset($period->can_delete) || (int) $period->can_delete === 1;
                                    $hst_delete_title = $hst_can_delete ? 'حذف' : ($period->delete_disabled_reason ?: 'این دوره قابل حذف نیست.');
                                    $hst_score_entry_url = add_query_arg(
                                        ['audit_period' => (string) $period->period_key],
                                        home_url('/score-audit/')
                                    );
                                    $hst_report_card_supported = $is_active && in_array((string) $period->period_type, ['weekly', 'monthly', 'custom'], true);
                                    $hst_report_card_title = $hst_report_card_supported
                                        ? 'رفتن به کارنامه این دوره'
                                        : (!$is_active ? 'برای صدور کارنامه ابتدا دوره را فعال کنید.' : 'صدور کارنامه برای این نوع دوره فعال نیست.');
                                    $hst_report_card_url = add_query_arg(
                                        [
                                            'report_card_section' => 'monthly',
                                            'report_period'       => (string) $period->period_key,
                                        ],
                                        home_url('/report-cards/')
                                    );
                                    ?>
                                    <tr
                                        data-id="<?php echo esc_attr($period->id); ?>"
                                        data-period-name="<?php echo esc_attr($period->period_name); ?>"
                                        data-period-type="<?php echo esc_attr($period->period_type); ?>"
                                        data-score-count="<?php echo esc_attr((int) ($period->score_count ?? 1)); ?>"
                                        data-start-date="<?php echo esc_attr($period->start_date); ?>"
                                        data-end-date="<?php echo esc_attr($period->end_date); ?>"
                                        data-deadline-date="<?php echo esc_attr($period->deadline_date); ?>"
                                        data-description="<?php echo esc_attr($period->description); ?>"
                                        data-created-label="<?php echo esc_attr($created_label); ?>"
                                        data-hst-type="<?php echo esc_attr($period->period_type); ?>"
                                        data-hst-status="<?php echo esc_attr($status_key); ?>"
                                        data-hst-search="<?php echo esc_attr($period->period_name . ' ' . $period->period_type_label . ' ' . $created_label); ?>"
                                    >
                                        <td><?php echo esc_html(number_format_i18n($index + 1)); ?></td>
                                        <td><strong><?php echo esc_html($period->period_name); ?></strong></td>
                                        <td><span class="hst-status hst-status--info"><?php echo esc_html($period->period_type_label); ?></span></td>
                                        <td>
                                            <?php $pct = min(100, max(0, (int) ($period->completion_percent ?? 0))); ?>
                                            <span class="hst-progress" data-hst-progress data-status="<?php echo esc_attr($period->completion_status ?? 'missing'); ?>">
                                                <span class="hst-progress__bar" style="width: <?php echo esc_attr((string) $pct); ?>%;"></span>
                                            </span>
                                            <small class="hst-muted"><?php echo esc_html(number_format_i18n((int) ($period->completion_percent ?? 0))); ?>٪</small>
                                        </td>
                                        <td>
                                            <label class="hst-switch">
                                                <input type="checkbox" class="hst-period-toggle" <?php checked($is_active); ?>>
                                                <span class="hst-switch__slider"></span>
                                            </label>
                                        </td>
                                        <td class="hst-actions">
                                            <a class="hst-btn hst-btn--soft hst-btn--sm" href="<?php echo esc_url($hst_score_entry_url); ?>" title="ثبت نمره این دوره" aria-label="ثبت نمره این دوره">
                                                <?php echo hst_icon('score-audit'); ?><span>ثبت نمره</span>
                                            </a>
                                            <?php if ($hst_report_card_supported) : ?>
                                                <a
                                                    class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon"
                                                    href="<?php echo esc_url($hst_report_card_url); ?>"
                                                    title="<?php echo esc_attr($hst_report_card_title); ?>"
                                                    aria-label="<?php echo esc_attr($hst_report_card_title . '؛ ' . (string) $period->period_name); ?>"
                                                ><?php echo hst_icon('report-card'); ?></a>
                                            <?php else : ?>
                                                <button
                                                    type="button"
                                                    class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon"
                                                    disabled
                                                    title="<?php echo esc_attr($hst_report_card_title); ?>"
                                                    aria-label="<?php echo esc_attr($hst_report_card_title); ?>"
                                                ><?php echo hst_icon('report-card'); ?></button>
                                            <?php endif; ?>
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon hst-period-view" aria-label="مشاهده">
                                                <?php echo hst_icon('view'); ?>
                                            </button>
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon hst-period-edit" aria-label="ویرایش">
                                                <?php echo hst_icon('edit'); ?>
                                            </button>
                                            <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon hst-period-delete" <?php disabled(!$hst_can_delete); ?> title="<?php echo esc_attr($hst_delete_title); ?>" aria-label="حذف">
                                                <?php echo hst_icon('delete'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <span class="hst-muted hst-inline-filter__empty hst-empty-state hst-empty-state--inline" data-hst-inline-empty hidden>موردی با این فیلتر پیدا نشد.</span>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="hst-modal" id="hst-period-modal" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-period-modal-title">
        <div class="hst-modal__backdrop" data-hst-period-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <h3 id="hst-period-modal-title">افزودن دوره جدید</h3>
                <button type="button" class="hst-modal__close" data-hst-period-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <form class="hst-form" id="hst-period-form">
                    <input type="hidden" name="id" value="">
                    <label class="hst-field">
                        <span>نام دوره</span>
                        <input type="text" name="period_name" required placeholder="مثلاً ریاضی عمومی دهم">
                    </label>
                    <div class="hst-form__row">
                        <label class="hst-field">
                            <span>نوع دوره</span>
                            <select name="period_type" required>
                                <?php foreach ($types as $type_key => $type_label) : ?>
                                    <option value="<?php echo esc_attr($type_key); ?>"><?php echo esc_html($type_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="hst-muted" data-hst-period-score-hint></small>
                        </label>
                        <label class="hst-field" data-hst-custom-score-count hidden>
                            <span>تعداد نمره‌های دوره اختصاصی</span>
                            <input type="number" name="score_count" min="1" max="20" step="1" value="1" inputmode="numeric">
                            <small class="hst-muted">بین ۱ تا ۲۰ نمره</small>
                        </label>
                        <label class="hst-field">
                            <span>تاریخ شروع دوره</span>
                            <input type="text" name="start_date" class="hst-jalali-date" placeholder="۱۴۰۵/۰۳/۱۵" required>
                        </label>
                    </div>
                    <div class="hst-form__row">
                        <label class="hst-field">
                            <span>تاریخ پایان دوره</span>
                            <input type="text" name="end_date" class="hst-jalali-date" placeholder="۱۴۰۵/۰۹/۳۰" required>
                        </label>
                        <label class="hst-field">
                            <span>مهلت ثبت نمره</span>
                            <input type="text" name="deadline_date" class="hst-jalali-date" placeholder="۱۴۰۵/۱۰/۱۵" required>
                        </label>
                    </div>
                    <label class="hst-field">
                        <span>توضیحات اختیاری</span>
                        <textarea name="description" rows="4" placeholder="توضیحات و جزئیات مربوط به این دوره..."></textarea>
                    </label>
                </form>
            </div>
            <div class="hst-modal__footer">
                <button type="submit" class="hst-btn hst-btn--primary" form="hst-period-form">ذخیره تغییرات</button>
                <button type="button" class="hst-btn hst-btn--soft" data-hst-period-close>بستن</button>
            </div>
        </div>
    </div>
    <div class="hst-modal" id="hst-period-view-modal" data-hst-modal-tone="detail" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-period-view-title">
        <div class="hst-modal__backdrop" data-hst-period-view-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div class="hst-modal__title">
                    <span class="hst-modal__icon" aria-hidden="true"><?php echo hst_icon('terms'); ?></span>
                    <div>
                        <h3 id="hst-period-view-title">جزئیات دوره</h3>
                        <p>مشخصات کامل دوره ثبت و ارزیابی نمرات</p>
                    </div>
                </div>
                <button type="button" class="hst-modal__close" data-hst-period-view-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <div class="hst-view-head">
                    <div>
                        <strong class="hst-view-name" data-hst-period-view-field="name"></strong>
                        <p class="hst-muted" data-hst-period-view-field="type"></p>
                    </div>
                </div>
                <div class="hst-view-grid">
                    <div class="hst-view-row">
                        <span class="hst-view-row__label">تاریخ شروع</span>
                        <span class="hst-view-row__value" data-hst-period-view-field="start"></span>
                    </div>
                    <div class="hst-view-row">
                        <span class="hst-view-row__label">تاریخ پایان</span>
                        <span class="hst-view-row__value" data-hst-period-view-field="end"></span>
                    </div>
                    <div class="hst-view-row">
                        <span class="hst-view-row__label">مهلت ثبت نمره</span>
                        <span class="hst-view-row__value" data-hst-period-view-field="deadline"></span>
                    </div>
                    <div class="hst-view-row">
                        <span class="hst-view-row__label">وضعیت</span>
                        <span class="hst-view-row__value" data-hst-period-view-field="status"></span>
                    </div>
                    <div class="hst-view-row">
                        <span class="hst-view-row__label">تعداد نمره‌ها</span>
                        <span class="hst-view-row__value" data-hst-period-view-field="score_count"></span>
                    </div>
                    <div class="hst-view-row hst-view-row--wide">
                        <span class="hst-view-row__label">توضیحات</span>
                        <span class="hst-view-row__value" data-hst-period-view-field="description"></span>
                    </div>
                </div>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--soft" data-hst-period-view-close>بستن</button>
            </div>
        </div>
    </div>

</div>
