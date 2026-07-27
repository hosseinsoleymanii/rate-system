<?php
defined('ABSPATH') || exit;

include_once HST_PATH . 'templates/user/common/hst-icons.php';
include_once HST_PATH . 'templates/user/common/hst-user-avatar.php';

$user       = wp_get_current_user();
$user_roles = (array) $user->roles;
$role_names = [];


// Profile-picture approval: when the module is on and the user has an image
// awaiting review, badge it (only the owner sees this in the header).
$hst_avatar_pending = class_exists('HST_Avatar_Approval')
    && HST_Avatar_Approval::is_enabled()
    && HST_Avatar_Approval::pending_avatar_id($user->ID) > 0;

global $wp_roles;

$hst_header_notification_defaults = [
    'items'        => [],
    'unread_count' => 0,
    'archive_url'  => home_url('/notifications/'),
];
$hst_header_notifications = $hst_header_notification_defaults;

if (is_user_logged_in() && class_exists('HST_Notifications')) {
    try {
        $context = (new HST_Notifications())->get_header_context(get_current_user_id());
        $hst_header_notifications = wp_parse_args(
            is_array($context) ? $context : [],
            $hst_header_notification_defaults
        );
    } catch (Throwable $e) {
        $hst_header_notifications = $hst_header_notification_defaults;
    }
}

$hst_header_notifications['items'] = is_array($hst_header_notifications['items'] ?? null)
    ? $hst_header_notifications['items']
    : [];
$hst_header_notifications['unread_count'] = absint($hst_header_notifications['unread_count'] ?? 0);
$hst_header_notifications['archive_url'] = (string) ($hst_header_notifications['archive_url'] ?? home_url('/notifications/'));

foreach ($user_roles as $role) {
    if (isset($wp_roles->roles[$role])) {
        $role_names[] = translate_user_role($wp_roles->roles[$role]['name']);
    }
}
?>
<div class="hst-shell <?php echo class_exists('HST_Settings') ? esc_attr(HST_Settings::shell_mode_class()) : 'hst-shell--app'; ?>" dir="rtl">
    <header class="hst-header" role="banner">
        <?php
        $hst_school_name = class_exists('HST_Settings')
            ? (string) HST_Settings::option('hst-home-school-name', '')
            : '';
        if ($hst_school_name === '') {
            $hst_school_name = get_bloginfo('name');
        }
        ?>
        <div class="hst-header__user">
            <div class="hst-header-avatar<?php echo $hst_avatar_pending ? ' is-pending' : ''; ?>"
                 data-hst-avatar-header-for="<?php echo esc_attr((int) $user->ID); ?>">
                <?php
                echo hst_user_avatar(
                    $user,
                    (string) ($user->display_name ?: $user->user_login),
                    50,
                    true
                );
                ?>
                <?php if ($hst_avatar_pending) : ?>
                    <span class="hst-header-avatar__pending-badge" data-hst-avatar-pending-badge title="تصویر در انتظار تأیید مدیر است">در انتظار تأیید</span>
                <?php endif; ?>
            </div>

            <div>
                <div class="hst-header__name"><?php echo esc_html($user->display_name ?: $user->user_login); ?></div>
                <div class="hst-header__role"><?php echo esc_html($role_names ? implode('، ', $role_names) : 'کاربر'); ?></div>
            </div>
        </div>

        <?php if ($hst_school_name !== '') : ?>
            <div class="hst-header__school" title="<?php echo esc_attr($hst_school_name); ?>">
                <span class="hst-header__school-name"><?php echo esc_html($hst_school_name); ?></span>
            </div>
        <?php endif; ?>

        <nav class="hst-header__nav" aria-label="ناوبری کاربر">
            <div data-hst-header-notifications>
                <button type="button"
                    class="hst-bell hst-header-notification-toggle"
                    data-hst-notification-toggle
                    aria-label="نمایش اطلاعیه‌ها"
                    aria-haspopup="dialog"
                    aria-controls="hst-header-notification-modal">
                    <span class="hst-bell__icon" aria-hidden="true"><?php echo hst_icon('bell'); ?></span>
                    <span class="hst-bell__label">اطلاعیه‌ها</span>
                    <span class="hst-bell__count" data-hst-notification-count <?php echo empty($hst_header_notifications['unread_count']) ? 'hidden' : ''; ?>>
                        <?php echo esc_html((int) ($hst_header_notifications['unread_count'] ?? 0)); ?>
                    </span>
                </button>
            </div>
            <span class="hst-header__divider" aria-hidden="true"></span>
            <a class="hst-header__link" href="<?php echo esc_url(home_url('/dashboard')); ?>">
                <span class="hst-header__link-icon" aria-hidden="true"><?php echo hst_icon('home'); ?></span>
                <span class="hst-header__link-text">پیشخوان</span>
            </a>
            <span class="hst-header__divider" aria-hidden="true"></span>
            <a class="hst-header__link" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">
                <span class="hst-header__link-icon" aria-hidden="true"><?php echo hst_icon('logout'); ?></span>
                <span class="hst-header__link-text">خروج</span>
            </a>
        </nav>
    </header>

    <div class="hst-loader" aria-hidden="true">
        <span class="hst-loader__spinner"></span>
    </div>

    <!-- Generic confirm/content dialog. JS uses the shared modal shell and data-hst-dialog-* hooks. -->
    <div class="hst-modal" data-hst-modal-size="md" id="hst-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="hst-modal-title">
        <div class="hst-modal__backdrop" data-hst-dialog-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <h3 id="hst-modal-title" data-hst-dialog-title></h3>
                <button type="button" class="hst-modal__close" data-hst-dialog-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <p data-hst-dialog-text></p>
                <div class="hst-form" data-hst-dialog-content></div>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn" data-hst-dialog-confirm>ذخیره تغییرات</button>
                <button type="button" class="hst-btn hst-btn--ghost" data-hst-dialog-cancel>بستن</button>
            </div>
        </div>
    </div>

    <template id="hst-page-help-button-template">
        <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm" data-hst-page-help-open title="راهنما" aria-label="راهنما"><?php echo hst_icon('help'); ?><span>راهنما</span></button>
    </template>

    <div class="hst-modal" data-hst-modal-size="lg" id="hst-page-help-modal" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="hst-page-help-title" aria-describedby="hst-page-help-description">
        <div class="hst-modal__backdrop" data-hst-page-help-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div>
                    <h3 id="hst-page-help-title">راهنما</h3>
                    <p id="hst-page-help-description">در این راهنما با امکانات و نحوه استفاده از این صفحه آشنا می‌شوید.</p>
                </div>
                <button type="button" class="hst-modal__close" data-hst-page-help-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body" data-hst-page-help-content></div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--soft" data-hst-page-help-close>بستن</button>
            </div>
        </div>
    </div>

    <!-- Header notifications dropdown. JS (hst-header-notifications.js): opened via .is-active + hidden toggle on
         [data-hst-notification-modal]; list re-rendered into [data-hst-header-notification-list] with .hst-header-notification-item -->
    <div class="hst-modal" data-hst-modal-size="md" id="hst-header-notification-modal" data-hst-notification-modal hidden role="dialog" aria-modal="true" aria-labelledby="hst-header-notification-modal-title" aria-hidden="true">
        <div class="hst-modal__backdrop" data-hst-close-notification-modal></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div>
                    <h3 id="hst-header-notification-modal-title">اطلاعیه‌ها</h3>
                    <p>آخرین پیام‌ها و اطلاع‌رسانی‌های مدرسه</p>
                </div>
                <div class="hst-btn-group">
                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-mark-all-header-notifications <?php disabled(empty($hst_header_notifications['unread_count'])); ?>>خواندن همه</button>
                    <button type="button" class="hst-modal__close" data-hst-close-notification-modal aria-label="بستن">&times;</button>
                </div>
            </div>
            <div class="hst-modal__body">
                <div class="hst-header-notification-list hst-vstack" data-hst-header-notification-list>
                    <?php if (!empty($hst_header_notifications['items'])) : ?>
                        <?php foreach ($hst_header_notifications['items'] as $notice) : ?>
                            <?php $avatar_review = is_array($notice['avatar_review'] ?? null) ? $notice['avatar_review'] : []; ?>
                            <article class="hst-header-notification-item <?php echo empty($notice['is_read']) ? 'is-unread' : 'is-read'; ?>"
                                data-notification-id="<?php echo esc_attr((int) $notice['id']); ?>">
                                <div class="hst-header-notification-main">
                                    <div class="hst-header-notification-title-row">
                                        <strong><?php echo esc_html($notice['title']); ?></strong>
                                        <span><?php echo empty($notice['is_read']) ? 'جدید' : 'خوانده‌شده'; ?></span>
                                    </div>
                                    <p><?php echo esc_html($notice['message']); ?></p>
                                    <?php if (!empty($avatar_review)) : ?>
                                        <div class="hst-user-id">
                                            <?php if (!empty($avatar_review['image_url'])) : ?>
                                                <span class="hst-user-avatar"><img src="<?php echo esc_url($avatar_review['image_url']); ?>" alt="<?php echo esc_attr($avatar_review['name'] ?? 'تصویر پروفایل'); ?>"></span>
                                            <?php endif; ?>
                                            <span class="hst-user-id__name">
                                                <?php echo esc_html($avatar_review['name'] ?? 'کاربر'); ?>
                                                <small class="hst-muted"><?php echo esc_html($avatar_review['role'] ?? ''); ?></small>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="hst-header-notification-item-actions">
                                    <?php if (!empty($avatar_review['can_review'])) : ?>
                                        <button type="button"
                                                class="hst-btn hst-btn--primary hst-btn--sm hst-btn--icon"
                                                data-hst-avatar-header-action="approve"
                                                data-user-id="<?php echo esc_attr((int) $avatar_review['user_id']); ?>"
                                                data-notification-id="<?php echo esc_attr((int) $notice['id']); ?>"
                                                title="تأیید تصویر"
                                                aria-label="تأیید تصویر"><?php echo hst_icon('avatar-approve'); ?></button>
                                        <button type="button"
                                                class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon"
                                                data-hst-avatar-header-action="reject"
                                                data-user-id="<?php echo esc_attr((int) $avatar_review['user_id']); ?>"
                                                data-notification-id="<?php echo esc_attr((int) $notice['id']); ?>"
                                                title="رد تصویر"
                                                aria-label="رد تصویر"><?php echo hst_icon('avatar-reject'); ?></button>
                                    <?php elseif (!empty($avatar_review)) : ?>
                                        <?php
                                        $avatar_status = (string) ($avatar_review['status'] ?? '');
                                        $avatar_status_class = $avatar_status === 'approved'
                                            ? 'hst-status--success'
                                            : ($avatar_status === 'rejected'
                                                ? 'hst-status--danger'
                                                : ($avatar_status === 'superseded' ? 'hst-status--muted' : 'hst-status--warning'));
                                        ?>
                                        <span class="hst-status <?php echo esc_attr($avatar_status_class); ?>">
                                            <?php echo esc_html($avatar_review['status_label'] ?? 'بررسی شده'); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($notice['link_url'])) : ?>
                                        <a class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon"
                                           href="<?php echo esc_url($notice['link_url']); ?>"
                                           title="مشاهده"
                                           aria-label="مشاهده"><?php echo hst_icon('notification-view'); ?></a>
                                    <?php endif; ?>
                                    <?php if (empty($notice['is_read'])) : ?>
                                        <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-mark-header-notification-read title="خوانده شد" aria-label="خوانده شد"><?php echo hst_icon('notification-read'); ?><span>خوانده شد</span></button>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p class="hst-header-notification-empty">فعلاً اطلاعیه‌ای ندارید.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hst-modal__footer">
                <a class="hst-btn" href="<?php echo esc_url($hst_header_notifications['archive_url']); ?>">مشاهده همه اطلاعیه‌ها</a>
                <button type="button" class="hst-btn hst-btn--soft" data-hst-close-notification-modal>بستن</button>
            </div>
        </div>
    </div>

    <!-- Shared avatar editor used by the header and every editable user avatar. -->
    <div class="hst-modal" data-hst-modal-size="md" data-hst-avatar-modal hidden role="dialog" aria-modal="true" aria-labelledby="hst-avatar-modal-title" aria-hidden="true">
        <div class="hst-modal__backdrop" data-hst-avatar-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div>
                    <h3 id="hst-avatar-modal-title">تصویر پروفایل</h3>
                    <p>تصویر را انتخاب کنید، کادر را تنظیم کنید و نسخه بهینه‌شده ذخیره می‌شود.</p>
                </div>
                <button type="button" class="hst-modal__close" data-hst-avatar-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <input type="file" accept="image/png,image/jpeg,image/webp" id="hst-avatar-file" class="hst-visually-hidden">
                <button type="button" class="hst-btn hst-btn--ghost" data-hst-avatar-choose>انتخاب تصویر</button>

                <div class="hst-avatar-cropper" data-hst-avatar-cropper hidden>
                    <div class="hst-avatar-preview">
                        <img alt="پیش‌نمایش تصویر پروفایل" data-hst-avatar-preview>
                        <span></span>
                    </div>
                    <p class="hst-muted">برای تنظیم تصویر، با انگشت تصویر را جابه‌جا کنید و با دو انگشت بزرگ‌نمایی را تغییر دهید.</p>
                </div>

                <p class="hst-alert">بهترین نتیجه با تصویر واضح و مربعی به دست می‌آید. خروجی نهایی با اندازه استاندارد ۵۱۲×۵۱۲ ذخیره می‌شود.</p>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn" data-hst-avatar-save disabled>ذخیره تغییرات</button>
                <button type="button" class="hst-btn hst-btn--ghost" data-hst-avatar-close>بستن</button>
            </div>
        </div>
    </div>

    <div class="hst-toast-container" aria-live="polite" aria-atomic="true"></div>
