<?php
defined('ABSPATH') || exit;

if (class_exists('WC_Payment_Gateway') && !class_exists('HST_WC_Gateway_School_Cash')) {
    class HST_WC_Gateway_School_Cash extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'hst_school_cash';
            $this->method_title = 'پرداخت نقدی مدرسه';
            $this->method_description = 'روش پرداخت داخلی TeacherShow برای شهریه‌هایی که در مدرسه پرداخت می‌شوند.';
            $this->has_fields = false;
            $this->supports = ['products'];

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option('enabled', 'yes');
            $this->title = $this->get_option('title', 'پرداخت نقدی در مدرسه');
            $this->description = $this->get_option('description', 'پرداخت به صورت حضوری در مدرسه ثبت می‌شود و پس از تأیید مدیر، شهریه پرداخت‌شده محسوب خواهد شد.');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'فعال‌سازی',
                    'type' => 'checkbox',
                    'label' => 'فعال کردن پرداخت نقدی مدرسه',
                    'default' => 'yes',
                ],
                'title' => [
                    'title' => 'عنوان',
                    'type' => 'text',
                    'default' => 'پرداخت نقدی در مدرسه',
                ],
                'description' => [
                    'title' => 'توضیحات',
                    'type' => 'textarea',
                    'default' => 'پرداخت به صورت حضوری در مدرسه ثبت می‌شود و پس از تأیید مدیر، شهریه پرداخت‌شده محسوب خواهد شد.',
                ],
            ];
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_status('on-hold', 'در انتظار تأیید پرداخت نقدی مدرسه.');
            }

            return [
                'result' => 'success',
                'redirect' => $order ? $order->get_checkout_order_received_url() : home_url('/'),
            ];
        }
    }
}
