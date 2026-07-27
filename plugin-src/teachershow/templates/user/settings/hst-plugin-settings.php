<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
?>
<section class="hst-page hst-plugin-settings hst-management-page">
    <div class="hst-card hst-section-card hst-management-card hst-no-print">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html(HST_Settings::management_page_title('plugin-settings', 'تنظیمات سامانه')); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-stack">
                <button
                    type="button"
                    class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back"
                    data-hst-settings-back
                    data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>"
                    title="بازگشت"
                    aria-label="بازگشت"
                ><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
            </div>
        </div>
    </div>
    <?php
    include HST_PATH . 'templates/user/settings/hst-settings-body.php';
    ?>
</section>
