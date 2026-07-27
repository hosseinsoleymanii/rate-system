<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-icons.php';
$active_term = $tuition_context['active_term'] ?? null;
$classes = $tuition_context['classes'] ?? [];
$plans = $tuition_context['plans'] ?? [];
$hst_sms_ready = class_exists('HST_SMS') && HST_SMS::direct_ready();
$current_sms_user = wp_get_current_user();
$tuition_sms_preview_context = [
    'name'     => ($current_sms_user && $current_sms_user->exists()) ? ($current_sms_user->display_name ?: $current_sms_user->user_login) : 'دانش‌آموز نمونه',
    'school'   => class_exists('HST_Settings') ? HST_Settings::option('hst-home-school-name', get_bloginfo('name')) : get_bloginfo('name'),
    'date'     => class_exists('HST_Date') ? HST_Date::today('Y/m/d') : date_i18n('Y/m/d'),
    'title'    => 'شهریه نمونه',
    'amount'   => '۱,۰۰۰,۰۰۰ تومان',
    'due_date' => '۱۴۰۳/۰۷/۳۰',
];
$tuition_sms_template_vars = [
    'name'     => 'نام دانش‌آموز',
    'school'   => 'نام مدرسه',
    'date'     => 'تاریخ امروز',
    'title'    => 'عنوان شهریه',
    'amount'   => 'مبلغ شهریه',
    'due_date' => 'تاریخ سررسید',
];
$tuition_sms_default_template = class_exists('HST_SMS') ? HST_SMS::default_template('tuition') : "{name} عزیز، شهریه «{title}» به مبلغ {amount} برای شما ثبت شد.\nمهلت پرداخت: {due_date}\n{school}";
?>
<section class="hst-page hst-management-page hst-module hst-module--tuition" data-hst-tuition>
    <div class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header">
            <h3><?php echo esc_html(HST_Settings::management_page_title('tuition', 'شهریه')); ?></h3>
        </div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-inline-filter">
                <button type="button" class="hst-btn--icon hst-btn hst-btn--primary hst-btn--sm hst-inline-filter__add" id="hst-tuition-add" <?php disabled(!$active_term); ?> title="<?php echo esc_attr($active_term ? 'افزودن شهریه' : 'ابتدا یک سال تحصیلی فعال تعریف کنید.'); ?>" aria-label="افزودن شهریه"><?php echo hst_icon('add'); ?><span>افزودن شهریه</span></button>
                <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
            </div>

            <template id="hst-tuition-class-options">
                <option value="0">همه کلاس‌ها</option>
                <?php foreach ($classes as $class) : ?>
                    <option value="<?php echo esc_attr($class->id); ?>"><?php echo esc_html($class->class_name); ?></option>
                <?php endforeach; ?>
            </template>
        </div>
    </div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__header hst-section-card__header"><h3>لیست شهریه‌ها</h3></div>
        <div class="hst-card__body hst-section-card__body">
            <?php if (!$active_term) : ?>
                <p class="hst-alert hst-alert--warning hst-empty-state">برای ثبت شهریه ابتدا یک سال تحصیلی فعال تعریف کنید.</p>
            <?php elseif (empty($plans)) : ?>
                <p class="hst-alert hst-empty-state">هنوز شهریه‌ای برای سال تحصیلی فعال تعریف نشده است.</p>
            <?php else : ?>
                <div class="hst-table-wrap hst-data-table-wrap">
                    <table class="hst-table hst-data-table hst-tuition-table">
                        <thead>
                            <tr>
                                <th>ردیف</th>
                                <th>عنوان</th>
                                <th>کلاس</th>
                                <th>مبلغ</th>
                                <th>سررسید</th>
                                <th>وضعیت</th>
                                <th>پیامک</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $hst_row_i = 0; foreach ($plans as $plan) : $hst_row_i++; ?>
                                <?php
                                $paid_count = (int) ($plan->paid_count ?? 0);
                                $is_active = (int) ($plan->is_active ?? 0) === 1;
                                ?>
                                <tr data-id="<?php echo esc_attr($plan->id); ?>" data-hst-status="<?php echo $is_active ? 'active' : 'inactive'; ?>" data-sms-enabled="<?php echo esc_attr((int) ($plan->sms_enabled ?? 0)); ?>" data-sms-sent="<?php echo esc_attr(!empty($plan->sms_sent_at) ? '1' : '0'); ?>" data-sms-message="<?php echo esc_attr((string) ($plan->sms_message ?? '')); ?>" data-title="<?php echo esc_attr($plan->title); ?>" data-amount-text="<?php echo esc_attr(class_exists('HST_Tuition') ? HST_Tuition::format_toman($plan->amount) : number_format_i18n((float) $plan->amount) . ' تومان'); ?>" data-due-date-text="<?php echo esc_attr($plan->due_date ? (class_exists('HST_Date') ? HST_Date::format($plan->due_date, 'Y/m/d') : $plan->due_date) : ''); ?>">
                                    <td class="hst-row-num"><?php echo esc_html(number_format_i18n($hst_row_i)); ?></td>
                                    <td><?php echo esc_html($plan->title); ?></td>
                                    <td><?php echo $plan->class_id ? esc_html($plan->class_name) : 'همه کلاس‌ها'; ?></td>
                                    <td><?php echo esc_html(class_exists('HST_Tuition') ? HST_Tuition::format_toman($plan->amount) : number_format_i18n((float) $plan->amount) . ' تومان'); ?></td>
                                    <td><?php echo $plan->due_date ? esc_html(class_exists('HST_Date') ? HST_Date::format($plan->due_date, 'Y/m/d') : $plan->due_date) : '—'; ?></td>
                                    <td>
                                        <label class="hst-switch" aria-label="تغییر وضعیت شهریه">
                                            <input type="checkbox" class="hst-tuition-status" data-id="<?php echo esc_attr($plan->id); ?>" <?php checked($is_active); ?>>
                                            <span class="hst-switch__slider"></span>
                                        </label>
                                    </td>
                                    <td>
                                        <?php if (!empty($plan->sms_sent_at)) : ?>
                                            <span class="hst-status hst-status--success hst-sms-sent-label">پیامک ارسال شده</span>
                                        <?php else : ?>
                                            <label class="hst-switch" title="<?php echo esc_attr($hst_sms_ready ? 'فعال‌سازی پیامک شهریه' : 'تنظیمات پیامک شهریه کامل نیست'); ?>" aria-label="فعال‌سازی پیامک شهریه">
                                                <input type="checkbox" class="hst-toggle-tuition-sms" data-id="<?php echo esc_attr($plan->id); ?>" data-sms-ready="<?php echo $hst_sms_ready ? '1' : '0'; ?>" <?php checked((int) ($plan->sms_enabled ?? 0), 1); ?>>
                                                <span class="hst-switch__slider"></span>
                                            </label>
                                        <?php endif; ?>
                                    </td>
                                    <td class="hst-actions">
                                        <div class="hst-btn-group">
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-tuition-report"
                                                data-id="<?php echo esc_attr($plan->id); ?>"
                                                data-title="<?php echo esc_attr($plan->title); ?>"
                                                data-class-id="<?php echo esc_attr((int) $plan->class_id); ?>"
                                                title="گزارش صورتحساب‌ها"
                                                aria-label="گزارش صورتحساب‌ها"><?php echo hst_icon('view'); ?><span>گزارش صورتحساب‌ها</span></button>
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon hst-edit-tuition"
                                                data-id="<?php echo esc_attr($plan->id); ?>"
                                                data-title="<?php echo esc_attr($plan->title); ?>"
                                                data-amount="<?php echo esc_attr((float) $plan->amount); ?>"
                                                data-class-id="<?php echo esc_attr((int) $plan->class_id); ?>"
                                                data-due-date="<?php echo esc_attr($plan->due_date ? (class_exists('HST_Date') ? HST_Date::format($plan->due_date, 'Y/m/d') : $plan->due_date) : ''); ?>"
                                                data-description="<?php echo esc_attr($plan->description ?? ''); ?>"                                                data-paid-count="<?php echo esc_attr($paid_count); ?>"
                                                title="<?php echo $paid_count > 0 ? esc_attr('ویرایش عنوان و سررسید') : esc_attr('ویرایش'); ?>"
aria-label="ویرایش"><?php echo hst_icon('edit'); ?></button>
                                            <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon hst-delete-tuition" data-id="<?php echo esc_attr($plan->id); ?>" <?php disabled($paid_count > 0); ?> title="<?php echo $paid_count > 0 ? esc_attr('به دلیل وجود پرداخت، حذف غیرفعال است') : esc_attr('حذف'); ?>" aria-label="حذف"><?php echo hst_icon('delete'); ?></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="hst-modal" id="hst-tuition-plan-modal" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-tuition-plan-modal-title">
        <div class="hst-modal__backdrop" data-hst-tuition-plan-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div>
                    <h3 id="hst-tuition-plan-modal-title">افزودن شهریه</h3>
                    <p class="hst-muted" data-hst-tuition-plan-help>اطلاعات شهریه جدید را وارد کنید.</p>
                </div>
                <button type="button" class="hst-modal__close" data-hst-tuition-plan-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <form class="hst-form" id="hst-tuition-plan-form" autocomplete="off">
                    <input type="hidden" name="plan_id" value="0">
                    <input type="hidden" name="paid_count" value="0">
                    <p class="hst-alert hst-alert--info" data-hst-tuition-paid-note hidden>برای شهریه دارای پرداخت، نام شهریه، توضیحات و تاریخ سررسید قابل ویرایش است.</p>
                    <div class="hst-field-grid">
                        <label class="hst-field">
                            <span>عنوان شهریه</span>
                            <input type="text" name="title" placeholder="مثلاً شهریه اردیبهشت" autocomplete="off" required>
                        </label>
                        <label class="hst-field" data-hst-tuition-lockable>
                            <span>مبلغ به تومان</span>
                            <input type="text" name="amount" inputmode="numeric" class="hst-toman-input" placeholder="مبلغ به تومان" required>
                        </label>
                        <label class="hst-field" data-hst-tuition-lockable>
                            <span>کلاس هدف</span>
                            <select name="class_id">
                                <option value="0">همه کلاس‌ها</option>
                                <?php foreach ($classes as $class) : ?>
                                    <option value="<?php echo esc_attr($class->id); ?>"><?php echo esc_html($class->class_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="hst-field">
                            <span>تاریخ سررسید</span>
                            <input type="text" name="due_date" class="hst-jalali-date" placeholder="مثال ۱۴۰۳/۰۸/۱۵" inputmode="numeric">
                        </label>
                        <label class="hst-field hst-field--wide">
                            <span>توضیحات</span>
                            <textarea name="description" rows="3" placeholder="توضیح کوتاه برای دانش‌آموزان"></textarea>
                        </label>
                    </div>
                </form>
            </div>
            <div class="hst-modal__footer">
                <button type="submit" class="hst-btn hst-btn--primary" form="hst-tuition-plan-form" data-hst-tuition-plan-submit>ذخیره تغییرات</button>
                <button type="button" class="hst-btn hst-btn--soft" data-hst-tuition-plan-close>بستن</button>
            </div>
        </div>
    </div>

    <div class="hst-modal" data-hst-modal-tone="detail" data-hst-modal-size="xl" data-hst-tuition-report-modal role="dialog" aria-modal="true" aria-labelledby="hst-tuition-report-title" aria-hidden="true">
        <div class="hst-modal__backdrop" data-hst-tuition-report-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div class="hst-modal__title">
                    <span class="hst-modal__icon" aria-hidden="true"><?php echo hst_icon('tuition'); ?></span>
                    <div>
                        <h3 id="hst-tuition-report-title">گزارش صورتحساب‌ها</h3>
                        <p>وضعیت پرداخت و جزئیات صورتحساب دانش‌آموزان</p>
                    </div>
                </div>
                <button type="button" class="hst-modal__close" data-hst-tuition-report-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <div class="hst-report-tools hst-tuition-report-tools">
                    <label class="hst-field">
                        <span>وضعیت پرداخت</span>
                        <select class="hst-select" data-hst-tuition-filter-status>
                            <option value="">همه</option>
                            <option value="paid">پرداخت‌شده</option>
                            <option value="unpaid">پرداخت‌نشده</option>
                            <option value="pending">در انتظار پرداخت</option>
                            <option value="overdue">سررسید گذشته</option>
                            <option value="cancelled">لغوشده</option>
                        </select>
                    </label>
                    <label class="hst-field">
                        <span>روش پرداخت</span>
                        <select class="hst-select" data-hst-tuition-filter-method>
                            <option value="">همه</option>
                            <option value="online">آنلاین</option>
                            <option value="cash">نقدی</option>
                            <option value="none">بدون پرداخت</option>
                        </select>
                    </label>
                    <label class="hst-field hst-tuition-class-filter" data-hst-tuition-class-filter-wrap hidden>
                        <span>کلاس</span>
                        <select class="hst-select" data-hst-tuition-filter-class>
                            <option value="">همه کلاس‌ها</option>
                        </select>
                    </label>
                    <label class="hst-field hst-tuition-search-field">
                        <span>جستجوی دانش‌آموز</span>
                        <input type="search" class="hst-input" data-hst-tuition-search placeholder="نام، نام خانوادگی یا کد ملی">
                    </label>
                </div>
                <div class="hst-report-actions hst-tuition-report-actions">
                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-tuition-export-excel disabled title="داده‌ای برای خروجی Excel وجود ندارد." aria-label="داده‌ای برای خروجی Excel وجود ندارد."><?php echo hst_icon('excel'); ?><span>خروجی Excel</span></button>
                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-tuition-invoices-pdf disabled title="صورتحسابی برای خروجی وجود ندارد." aria-label="صورتحسابی برای خروجی وجود ندارد."><?php echo hst_icon('report'); ?><span>فاکتورها</span></button>
                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-tuition-unpaid-list-pdf disabled title="صورتحساب پرداخت‌نشده‌ای وجود ندارد." aria-label="صورتحساب پرداخت‌نشده‌ای وجود ندارد."><?php echo hst_icon('avatar-reject'); ?><span>پرداخت‌نشده‌ها</span></button>
                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-tuition-paid-list-pdf disabled title="صورتحساب پرداخت‌شده‌ای وجود ندارد." aria-label="صورتحساب پرداخت‌شده‌ای وجود ندارد."><?php echo hst_icon('avatar-approve'); ?><span>پرداخت‌شده‌ها</span></button>
                </div>
                <div class="hst-tuition-report-summary" data-hst-tuition-report-summary></div>
                <div class="hst-tuition-report-body" data-hst-tuition-report-body>
                    <p class="hst-alert">برای مشاهده صورتحساب‌ها روی دکمه گزارش یکی از شهریه‌ها کلیک کنید.</p>
                </div>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--soft" data-hst-tuition-report-close>بستن</button>
            </div>
        </div>
    </div>


    <div class="hst-modal" id="hst-tuition-sms-modal" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-tuition-sms-title">
        <div class="hst-modal__backdrop" data-hst-tuition-sms-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <h3 id="hst-tuition-sms-title">متن پیامک شهریه</h3>
                <button type="button" class="hst-modal__close" data-hst-tuition-sms-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body hst-form"
                 data-sms-preview-name="<?php echo esc_attr($tuition_sms_preview_context['name']); ?>"
                 data-sms-preview-school="<?php echo esc_attr($tuition_sms_preview_context['school']); ?>"
                 data-sms-preview-date="<?php echo esc_attr($tuition_sms_preview_context['date']); ?>"
                 data-sms-preview-title="<?php echo esc_attr($tuition_sms_preview_context['title']); ?>"
                 data-sms-preview-amount="<?php echo esc_attr($tuition_sms_preview_context['amount']); ?>"
                 data-sms-preview-due-date="<?php echo esc_attr($tuition_sms_preview_context['due_date']); ?>"
                 data-sms-ready="<?php echo $hst_sms_ready ? '1' : '0'; ?>">
                <?php if (!$hst_sms_ready) : ?>
                    <p class="hst-alert hst-alert--warning">برای فعال‌سازی پیامک شهریه، ابتدا پیامک سامانه، توکن API و سرشماره را در تنظیمات تکمیل و ذخیره کنید.</p>
                <?php endif; ?>

                <div class="hst-field">
                    <label for="hst-tuition-sms-message">متن پیامک</label>
                    <div class="hst-btn-group" role="group" aria-label="متغیرهای قابل استفاده در متن پیامک">
                        <?php foreach ($tuition_sms_template_vars as $key => $label) : ?>
                            <?php $variable = '{' . $key . '}'; ?>
                            <button type="button"
                                    class="hst-chip"
                                    data-hst-sms-variable="<?php echo esc_attr($variable); ?>"
                                    data-hst-sms-target="#hst-tuition-sms-message"
                                    title="<?php echo esc_attr('درج ' . $label); ?>"
                                    aria-label="<?php echo esc_attr('درج ' . $label . ' در متن پیامک'); ?>"><?php echo esc_html($label); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <textarea id="hst-tuition-sms-message" rows="5" maxlength="500"><?php echo esc_textarea($tuition_sms_default_template); ?></textarea>
                </div>
                <label class="hst-field">
                    <span>پیش‌نمایش نهایی</span>
                    <div class="hst-alert hst-alert--info" id="hst-tuition-sms-preview"></div>
                        <div class="hst-sms-usage is-loading" id="hst-tuition-sms-usage" role="status" aria-live="polite" aria-busy="true">
                            <span class="hst-sms-usage__badge hst-sms-usage__badge--muted">در حال محاسبه مصرف پیامک...</span>
                        </div>
                </label>
                <label class="hst-field">
                    <span>ارسال تستی پیامک</span>
                    <div class="hst-btn-group hst-tuition-sms-test-row">
                        <input type="tel" id="hst-tuition-sms-test-phone" placeholder="شماره موبایل تست">
                        <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" id="hst-tuition-sms-test-send" <?php disabled(!$hst_sms_ready); ?>>ارسال تست</button>
                    </div>
                </label>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--primary" id="hst-tuition-sms-confirm">تأیید و ارسال</button>
                <button type="button" class="hst-btn hst-btn--soft" data-hst-tuition-sms-close>بستن</button>
            </div>
        </div>
    </div>

</section>
</div>
