<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';

$monthly_periods = isset($monthly_periods) && is_array($monthly_periods) ? $monthly_periods : [];
$report_card_print_classes = isset($report_card_print_classes) && is_array($report_card_print_classes) ? $report_card_print_classes : [];
$report_card_section = isset($report_card_section) && $report_card_section === 'monthly' ? 'monthly' : '';
$report_card_period = isset($report_card_period) ? sanitize_key((string) $report_card_period) : '';
$report_cards_url = get_permalink() ?: home_url('/report-cards');
$monthly_url = add_query_arg('report_card_section', 'monthly', $report_cards_url);
$default_manager_message = 'دانش‌آموز عزیز، تلاش مستمر و مسئولیت‌پذیری تو ارزشمند است. با همین پشتکار مسیر پیشرفت را ادامه بده.';

?>
<section
    class="hst-page hst-report-cards-page hst-management-page"
    data-hst-report-cards
    data-hst-initial-section="<?php echo esc_attr($report_card_section); ?>"
    data-hst-initial-period="<?php echo esc_attr($report_card_period); ?>"
    data-hst-default-manager-message="<?php echo esc_attr($default_manager_message); ?>"
>
    <div class="hst-card hst-section-card hst-management-card hst-no-print">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html(HST_Settings::management_page_title('report-cards', 'کارنامه‌ها')); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-stack">
                <button
                    type="button"
                    class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back"
                    data-hst-back
                    data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>"
                    title="بازگشت"
                    aria-label="بازگشت"
                ><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card hst-no-print">
        <div class="hst-card__body hst-section-card__body">
            <nav class="hst-dashboard hst-dashboard--management hst-dashboard--four" aria-label="انواع کارنامه">
                <a
                    href="<?php echo esc_url($monthly_url); ?>"
                    class="hst-tile"
                    data-hst-report-card-section="monthly"
                    aria-controls="hst-report-card-monthly-panel"
                    aria-expanded="<?php echo $report_card_section === 'monthly' ? 'true' : 'false'; ?>"
                    <?php echo $report_card_section === 'monthly' ? 'aria-current="page"' : ''; ?>
                >
                    <span class="hst-chip">ماهانه</span>
                    <span class="hst-tile__icon"><?php echo hst_icon('terms'); ?></span>
                    <span>کارنامه ماهانه</span>
                </a>
                <div class="hst-tile" aria-disabled="true" data-hst-disabled-silent="true">
                    <span class="hst-chip">نوبت اول</span>
                    <span class="hst-tile__icon"><?php echo hst_icon('report'); ?></span>
                    <span>کارنامه نوبت اول</span>
                </div>
                <div class="hst-tile" aria-disabled="true" data-hst-disabled-silent="true">
                    <span class="hst-chip">نوبت دوم</span>
                    <span class="hst-tile__icon"><?php echo hst_icon('award'); ?></span>
                    <span>کارنامه نوبت دوم</span>
                </div>
                <div class="hst-tile" aria-disabled="true" data-hst-disabled-silent="true">
                    <span class="hst-chip">دبیران</span>
                    <span class="hst-tile__icon"><?php echo hst_icon('teachers'); ?></span>
                    <span>کارنامه دبیر</span>
                </div>
            </nav>
        </div>
    </div>

    <div
        id="hst-report-card-monthly-panel"
        class="hst-vstack hst-management-page"
        data-hst-report-card-panel="monthly"
        <?php echo $report_card_section === 'monthly' ? '' : 'hidden'; ?>
    >
        <div class="hst-inline-filter hst-no-print" data-hst-report-period-filters>
            <div class="hst-card hst-section-card">
                <div class="hst-card__body hst-section-card__body">
                    <div class="hst-inline-filter__main">
                        <div class="hst-inline-filter__search">
                            <span class="hst-inline-filter__search-icon" aria-hidden="true"><?php echo hst_icon('terms'); ?></span>
                            <input
                                type="search"
                                id="hst-report-card-period-search"
                                name="report_card_period_search"
                                class="hst-search"
                                data-hst-report-period-search-filter
                                placeholder="جست‌وجو در دوره‌ها..."
                                autocomplete="off"
                                aria-label="جست‌وجوی دوره"
                            >
                        </div>
                        <select
                            id="hst-report-card-period-type"
                            name="report_card_period_type"
                            class="hst-inline-filter__select"
                            data-hst-report-period-type-filter
                            aria-label="فیلتر نوع دوره"
                        >
                            <option value="">همه</option>
                            <option value="weekly">هفتگی</option>
                            <option value="monthly">ماهانه</option>
                            <option value="custom">اختصاصی</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="hst-card hst-section-card hst-no-print">
            <div class="hst-card__header hst-section-card__header">
                <div class="hst-vstack">
                    <h3>لیست دوره‌ها</h3>
                </div>
            </div>
            <div class="hst-card__body hst-section-card__body">
                <?php if (empty($monthly_periods)) : ?>
                    <p class="hst-alert hst-empty-state">برای سال تحصیلی فعال هنوز دوره هفتگی، ماهانه یا اختصاصی تعریف نشده است.</p>
                <?php else : ?>
                    <div class="hst-vstack" data-hst-report-period-list>
                        <?php foreach ($monthly_periods as $period_index => $period) :
                            $period_id = absint($period->id ?? 0);
                            $period_key = sanitize_key((string) ($period->period_key ?? ''));
                            $period_name = trim((string) ($period->period_name ?? ''));
                            $period_type = sanitize_key((string) ($period->period_type ?? 'custom'));
                            $period_type_label = trim((string) ($period->period_type_label ?? 'اختصاصی'));
                            $field_prefix = 'report_card_period_' . ($period_id ?: $period_key);
                        ?>
                            <details
                                class="hst-availability-accordion hst-config-accordion"
                                data-hst-report-period-item
                                data-period-id="<?php echo esc_attr($period_id); ?>"
                                data-period-key="<?php echo esc_attr($period_key); ?>"
                                data-period-type="<?php echo esc_attr($period_type); ?>"
                                data-period-type-label="<?php echo esc_attr($period_type_label); ?>"
                                data-period-name="<?php echo esc_attr($period_name); ?>"
                                data-hst-period-search="<?php echo esc_attr($period_name . ' ' . $period_type_label); ?>"
                            >
                                <summary>
                                    <span class="hst-config-accordion__summary">
                                        <strong><?php echo esc_html($period_name ?: 'دوره بدون عنوان'); ?></strong>
                                        <span class="hst-status hst-status--muted"><?php echo esc_html($period_type_label); ?></span>
                                        <span class="hst-status hst-status--success" data-hst-manager-message-status>پیام پیش‌فرض مدیر فعال است</span>
                                        <span class="hst-status hst-status--success" data-hst-comparison-status>نمودار مقایسه‌ای فعال</span>
                                    </span>
                                </summary>
                                <div class="hst-config-accordion__body">
                                    <div class="hst-config-toolbar">
                                        <div class="hst-config-toolbar__group hst-config-toolbar__group--message">
                                            <button
                                                type="button"
                                                class="hst-btn hst-btn--ghost hst-btn--sm hst-config-toolbar__message-btn"
                                                data-hst-manager-message-open
                                            >
                                                <?php echo hst_icon('sms'); ?>
                                                <span data-hst-manager-message-button-text>ویرایش پیام مدیر</span>
                                            </button>
                                        </div>

                                        <div class="hst-config-toolbar__group hst-config-toolbar__group--threshold">
                                            <span class="hst-config-toolbar__label">قرمز کردن نمرات زیر:</span>
                                            <input
                                                type="number"
                                                class="hst-config-toolbar__number"
                                                name="<?php echo esc_attr($field_prefix); ?>_red_below"
                                                min="0"
                                                max="20"
                                                step="0.25"
                                                value="10"
                                                inputmode="decimal"
                                                aria-label="مرز قرمز کردن نمرات دوره <?php echo esc_attr($period_name); ?>"
                                            >
                                        </div>

                                        <div class="hst-config-toolbar__group">
                                            <label class="hst-check">
                                                <span>نمایش نمودار مقایسه‌ای</span>
                                                <input
                                                    type="checkbox"
                                                    name="<?php echo esc_attr($field_prefix); ?>_comparison_chart"
                                                    value="1"
                                                    checked="checked"
                                                    autocomplete="off"
                                                    data-hst-report-default-on
                                                    data-hst-comparison-toggle
                                                >
                                            </label>
                                        </div>

                                        <div class="hst-config-toolbar__group">
                                            <label class="hst-check">
                                                <span>نفرات برتر کلاس</span>
                                                <input type="checkbox" name="<?php echo esc_attr($field_prefix); ?>_class_top_scores" value="1" checked="checked" autocomplete="off" data-hst-report-default-on>
                                            </label>
                                        </div>

                                        <div class="hst-config-toolbar__group">
                                            <label class="hst-check">
                                                <span>نفرات برتر مدرسه</span>
                                                <input type="checkbox" name="<?php echo esc_attr($field_prefix); ?>_school_top_scores" value="1" checked="checked" autocomplete="off" data-hst-report-default-on>
                                            </label>
                                        </div>

                                        <div class="hst-config-toolbar__group">
                                            <label class="hst-check" data-hst-duplex-option>
                                                <span>چاپ دوتایی</span>
                                                <input
                                                    type="checkbox"
                                                    name="<?php echo esc_attr($field_prefix); ?>_duplex_print"
                                                    value="1"
                                                    autocomplete="off"
                                                    data-hst-duplex-toggle
                                                >
                                            </label>
                                        </div>

                                        <div class="hst-config-toolbar__group hst-config-toolbar__group--actions">
                                            <div class="hst-btn-group">
                                                <button
                                                    type="button"
                                                    class="hst-btn hst-btn--ghost hst-btn--sm hst-btn--icon"
                                                    data-hst-report-period-preview
                                                    title="پیش‌نمایش کارنامه"
                                                    aria-label="پیش‌نمایش کارنامه <?php echo esc_attr($period_name); ?>"
                                                ><?php echo hst_icon('view'); ?></button>
                                                <button
                                                    type="button"
                                                    class="hst-btn hst-btn--ghost hst-btn--sm hst-btn--icon"
                                                    data-hst-report-period-print
                                                    title="چاپ کارنامه"
                                                    aria-label="چاپ کارنامه <?php echo esc_attr($period_name); ?>"
                                                ><?php echo hst_icon('print'); ?></button>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="<?php echo esc_attr($field_prefix); ?>_manager_message" value="<?php echo esc_attr($default_manager_message); ?>" data-hst-manager-message-value>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>

                    <p class="hst-alert hst-empty-state" data-hst-report-period-empty hidden>هیچ دوره‌ای با فیلترهای انتخابی شما یافت نشد.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div
        class="hst-modal hst-report-preview-modal"
        data-hst-modal-size="xl"
        id="hst-report-card-preview-modal"
        hidden
        role="dialog"
        aria-modal="true"
        aria-hidden="true"
        aria-labelledby="hst-report-card-preview-title"
    >
        <div class="hst-modal__backdrop" data-hst-report-preview-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div class="hst-modal__title">
                    <span class="hst-modal__icon"><?php echo hst_icon('view'); ?></span>
                    <div>
                        <h3 id="hst-report-card-preview-title">پیش‌نمایش کارنامه</h3>
                    </div>
                </div>
                <button type="button" class="hst-modal__close" data-hst-report-preview-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <div class="hst-report-preview-host" data-hst-report-preview-host>
                    <p class="hst-alert hst-alert--info">برای نمایش نمونه کارنامه، روی دکمه پیش‌نمایش دوره هفتگی، ماهانه یا اختصاصی کلیک کنید.</p>
                </div>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--ghost" data-hst-report-preview-close>بستن</button>
            </div>
        </div>
    </div>

    <script type="application/json" id="hst-report-print-classes-data"><?php echo wp_json_encode(array_values($report_card_print_classes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>

    <div
        class="hst-modal hst-report-print-modal"
        data-hst-modal-size="lg"
        id="hst-report-card-print-modal"
        hidden
        role="dialog"
        aria-modal="true"
        aria-hidden="true"
        aria-labelledby="hst-report-card-print-title"
    >
        <div class="hst-modal__backdrop" data-hst-report-print-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div class="hst-modal__title">
                    <span class="hst-modal__icon"><?php echo hst_icon('print'); ?></span>
                    <div><h3 id="hst-report-card-print-title" data-hst-report-print-title>امکانات پیشرفته چاپ کارنامه</h3></div>
                </div>
                <button type="button" class="hst-modal__close" data-hst-report-print-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body hst-vstack">
                <div class="hst-card hst-section-card">
                    <div class="hst-card__body hst-section-card__body">
                        <div class="hst-report-print-filter-grid">
                            <label class="hst-field">
                                <span>پایه تحصیلی</span>
                                <select data-hst-report-print-grade aria-label="پایه تحصیلی"></select>
                            </label>
                            <label class="hst-field">
                                <span>رشته تحصیلی</span>
                                <select data-hst-report-print-major aria-label="رشته تحصیلی"></select>
                            </label>
                            <label class="hst-field">
                                <span>کلاس</span>
                                <select data-hst-report-print-class aria-label="کلاس"></select>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="hst-alert hst-alert--info hst-report-print-operation">
                    <span class="hst-report-print-operation__icon" aria-hidden="true"><?php echo hst_icon('report'); ?></span>
                    <div class="hst-report-print-operation__copy">
                        <strong>عملیات چاپ گروهی کلاس</strong>
                        <span data-hst-report-print-count>کلاس و دانش‌آموزان آماده چاپ را انتخاب کنید.</span>
                    </div>
                    <div class="hst-btn-group hst-report-print-operation__actions">
                        <button type="button" class="hst-btn hst-btn--success-soft" data-hst-report-print-class-pdf>
                            <?php echo hst_icon('print'); ?><span>چاپ کارنامه کلاس</span>
                        </button>
                        <button type="button" class="hst-btn hst-btn--warning-soft" data-hst-report-print-individual-open>
                            <?php echo hst_icon('students'); ?><span>چاپ کارنامه انفرادی</span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--ghost" data-hst-report-print-close>بستن</button>
            </div>
        </div>
    </div>

    <div
        class="hst-modal hst-report-individual-modal"
        data-hst-modal-size="xl"
        id="hst-report-card-individual-modal"
        hidden
        role="dialog"
        aria-modal="true"
        aria-hidden="true"
        aria-labelledby="hst-report-card-individual-title"
    >
        <div class="hst-modal__backdrop" data-hst-report-individual-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div class="hst-modal__title">
                    <span class="hst-modal__icon"><?php echo hst_icon('students'); ?></span>
                    <div><h3 id="hst-report-card-individual-title" data-hst-report-individual-title>چاپ کارنامه انفرادی</h3></div>
                </div>
                <button type="button" class="hst-modal__close" data-hst-report-individual-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body hst-vstack">
                <div class="hst-inline-filter hst-report-individual-search" data-hst-inline-filter="hst-report-individual-table">
                    <div class="hst-inline-filter__search">
                        <span class="hst-inline-filter__search-icon" aria-hidden="true"><?php echo hst_icon('notification-view'); ?></span>
                        <input
                            type="search"
                            class="hst-search"
                            data-hst-report-individual-search
                            data-hst-inline-search
                            placeholder="جست‌وجوی دانش‌آموز بر اساس نام، نام خانوادگی، نام پدر، کد ملی یا کلاس..."
                            autocomplete="off"
                            aria-label="جست‌وجوی دانش‌آموز"
                        >
                    </div>
                </div>

                <div class="hst-table-wrap hst-data-table-wrap hst-report-table-wrap">
                    <table class="hst-table hst-data-table hst-tuition-invoice-table" id="hst-report-individual-table" data-hst-no-pagination="1">
                        <thead>
                            <tr>
                                <th>ردیف</th>
                                <th class="hst-col-fill">نام و نام خانوادگی دانش‌آموز</th>
                                <th>نام پدر</th>
                                <th>کلاس</th>
                                <th>دریافت کارنامه</th>
                            </tr>
                        </thead>
                        <tbody data-hst-report-individual-body></tbody>
                    </table>
                </div>
                <span class="hst-visually-hidden" data-hst-inline-empty hidden>دانش‌آموزی با جست‌وجوی شما پیدا نشد.</span>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--ghost" data-hst-report-individual-close>بستن</button>
            </div>
        </div>
    </div>

    <div
        class="hst-modal"
        data-hst-modal-size="md"
        id="hst-report-manager-message-modal"
        hidden
        role="dialog"
        aria-modal="true"
        aria-hidden="true"
        aria-labelledby="hst-report-manager-message-title"
        aria-describedby="hst-report-manager-message-description"
    >
        <div class="hst-modal__backdrop" data-hst-manager-message-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div>
                    <h3 id="hst-report-manager-message-title">ثبت پیام مدیر</h3>
                    <p id="hst-report-manager-message-description">پیام خود را جهت نمایش در کارنامه وارد کنید.</p>
                </div>
                <button type="button" class="hst-modal__close" data-hst-manager-message-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <label class="hst-field" for="hst-report-manager-message-text">
                    <span>متن پیام (حداکثر ۳۰۰ کاراکتر):</span>
                    <textarea
                        id="hst-report-manager-message-text"
                        class="hst-textarea--lg"
                        maxlength="300"
                        placeholder="برای مثال: عملکرد دانش‌آموز در این ماه بسیار رضایت‌بخش بوده و پیشرفت شایانی داشته است..."
                        data-hst-manager-message-text
                    ></textarea>
                </label>
                <div class="hst-card__header--row hst-muted">
                    <span>کاراکترهای وارد شده</span>
                    <span><b data-hst-manager-message-count>۰</b> / ۳۰۰</span>
                </div>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn" data-hst-manager-message-save>ثبت</button>
                <button type="button" class="hst-btn hst-btn--ghost" data-hst-manager-message-close>انصراف</button>
            </div>
        </div>
    </div>
</section>
