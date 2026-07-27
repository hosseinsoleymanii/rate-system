<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
$active_term = $tuition_context['active_term'] ?? null;
$invoices = $tuition_context['invoices'] ?? [];
$woocommerce_ready = !empty($tuition_context['woocommerce_ready']);
?>
<section class="hst-page" data-hst-tuition-payments>
    <div class="hst-card">
        <div class="hst-card__header"><h3>پرداخت شهریه</h3></div>
        <div class="hst-card__body">
            <?php if (!$woocommerce_ready) : ?>
                <p class="hst-alert hst-alert--danger">در حال حاضر پرداخت آنلاین شهریه فعال نیست.</p>
            <?php elseif (!$active_term) : ?>
                <p class="hst-alert">سال تحصیلی فعالی برای نمایش شهریه وجود ندارد.</p>
            <?php elseif (empty($invoices)) : ?>
                <p class="hst-alert">برای سال تحصیلی فعال شهریه‌ای برای شما ثبت نشده است.</p>
            <?php else : ?>
                <p class="hst-alert hst-alert--info">شهریه‌های سال تحصیلی فعال: <strong><?php echo esc_html($active_term->term_name); ?></strong></p>
                <?php if (isset($_GET['hst_paid'])) : ?>
                    <p class="hst-alert hst-alert--success" data-hst-pay-return>در حال بررسی وضعیت پرداخت شما هستیم. اگر پرداخت موفق بوده باشد، وضعیت شهریه در همین صفحه به‌روز شده است.</p>
                <?php endif; ?>
                <div class="hst-grid hst-grid--cards">
                    <?php foreach ($invoices as $invoice) : ?>
                        <?php
                        $invoice_status = (string) ($invoice->status ?? 'pending');
                        $status_class = $invoice_status === 'paid' ? 'hst-status--success' : ($invoice_status === 'overdue' ? 'hst-status--danger' : ($invoice_status === 'cancelled' ? 'hst-status--muted' : 'hst-status--warning'));
                        $status_label = !empty($invoice->status_label) ? (string) $invoice->status_label : ($invoice_status === 'paid' ? 'پرداخت‌شده' : ($invoice_status === 'overdue' ? 'سررسید گذشته' : ($invoice_status === 'cancelled' ? 'لغوشده' : 'در انتظار پرداخت')));
                        $can_pay = $invoice_status === 'pending';
                        ?>
                        <article class="hst-invoice <?php echo $invoice_status === 'paid' ? 'is-paid' : ($invoice_status === 'overdue' ? 'is-overdue' : 'is-pending'); ?>">
                            <div class="hst-invoice__head">
                                <h3><?php echo esc_html($invoice->title); ?></h3>
                                <span class="hst-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span>
                            </div>
                            <?php if (!empty($invoice->description)) : ?>
                                <p class="hst-muted"><?php echo esc_html($invoice->description); ?></p>
                            <?php endif; ?>
                            <div class="hst-invoice__meta">
                                <span>کلاس: <?php echo $invoice->class_name ? esc_html($invoice->class_name) : 'عمومی'; ?></span>
                                <span>مبلغ: <?php echo esc_html(!empty($invoice->amount_text) ? $invoice->amount_text : (class_exists('HST_Tuition') ? HST_Tuition::format_toman($invoice->amount) : number_format_i18n((float) $invoice->amount) . ' تومان')); ?></span>
                                <span>سررسید: <?php echo $invoice->due_date ? esc_html(class_exists('HST_Date') ? HST_Date::format($invoice->due_date, 'Y/m/d') : $invoice->due_date) : '—'; ?></span>
                            </div>
                            <?php if ($invoice_status === 'paid') : ?>
                                <p class="hst-alert hst-alert--success">این شهریه در <?php echo esc_html($invoice->paid_at ? (class_exists('HST_Date') ? HST_Date::format($invoice->paid_at, 'Y/m/d H:i') : $invoice->paid_at) : '—'); ?> پرداخت شده است.</p>
                            <?php elseif ($invoice_status === 'overdue') : ?>
                                <p class="hst-alert hst-alert--danger">مهلت پرداخت این صورتحساب به پایان رسیده است و امکان پرداخت آنلاین آن وجود ندارد.</p>
                            <?php elseif ($invoice_status === 'cancelled') : ?>
                                <p class="hst-alert">این صورتحساب در وضعیت لغوشده قرار دارد.</p>
                            <?php elseif ($can_pay) : ?>
                                <button type="button" class="hst-btn hst-btn--block hst-pay-tuition" data-id="<?php echo esc_attr($invoice->id); ?>">پرداخت شهریه</button>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="hst-modal" data-hst-modal-size="md" data-hst-pay-modal role="dialog" aria-modal="true" aria-labelledby="hst-pay-modal-title" aria-hidden="true">
        <div class="hst-modal__backdrop" data-hst-pay-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <h3 id="hst-pay-modal-title">انتخاب روش پرداخت</h3>
                <button type="button" class="hst-modal__close" data-hst-pay-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <p class="hst-muted" data-hst-pay-amount></p>
                <div class="hst-pay-gateways" data-hst-pay-gateways>
                    <p class="hst-empty-note"><?php echo hst_loading_state(); ?></p>
                </div>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn" data-hst-pay-confirm disabled>پرداخت</button>
                <button type="button" class="hst-btn hst-btn--ghost" data-hst-pay-close>بستن</button>
            </div>
        </div>
    </div>
</section>
</div>
