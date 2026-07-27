<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
?>
<section class="hst-page">
    <div class="hst-card">
        <div class="hst-card__header"><h3>دسترسی غیرمجاز</h3></div>
        <div class="hst-card__body">
            <p class="hst-alert hst-alert--danger"><?php echo esc_html($hst_access_message ?? __('شما اجازه دسترسی به این بخش را ندارید.', 'teacher-show')); ?></p>
            <a class="hst-btn" href="<?php echo esc_url(home_url('/dashboard')); ?>">بازگشت به پیشخوان</a>
        </div>
    </div>
</section>
</div>
