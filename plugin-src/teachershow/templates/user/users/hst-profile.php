<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';

$phone = class_exists('HST_User_Phones')
    ? HST_User_Phones::get((int) $profile_user->ID)
    : get_user_meta($profile_user->ID, 'phone', true);
$teacher_bio = get_user_meta($profile_user->ID, 'hst_teacher_bio', true);
$role_label = $role === 'teacher' ? 'معلم' : ($role === 'student' ? 'دانش‌آموز' : 'کاربر');
$full_name = trim(($profile_user->first_name ?: '') . ' ' . ($profile_user->last_name ?: ''));
if (!$full_name) {
    $full_name = $profile_user->display_name ?: $profile_user->user_login;
}
?>
<section class="hst-page" data-hst-profile>
    <div class="hst-card hst-profile-hero">
        <div class="hst-card__body">
            <div class="hst-profile-hero__content">
                <div>
                    <p class="hst-kicker"><?php echo esc_html($role_label); ?></p>
                    <h2 class="hst-profile-hero__name"><?php echo esc_html($full_name); ?></h2>
                    <p class="hst-profile-hero__subtitle">
                        اطلاعات کاربری، دسترسی‌ها و تنظیمات امنیتی حساب شما در تیچرشو
                    </p>
                </div>
                <div class="hst-btn-group">
                    <a class="hst-btn hst-btn--soft" href="<?php echo esc_url(home_url('/my-schedule')); ?>">برنامه هفتگی</a>
                    <?php if ($role === 'teacher') : ?>
                        <a class="hst-btn hst-btn--soft" href="<?php echo esc_url(home_url('/students')); ?>">دانش‌آموزان من</a>
                    <?php elseif ($role === 'student') : ?>
                        <a class="hst-btn hst-btn--soft" href="<?php echo esc_url(home_url('/scores')); ?>">نمرات من</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="hst-grid hst-grid--2">
        <div class="hst-card">
            <div class="hst-card__header"><h3>اطلاعات حساب</h3></div>
            <div class="hst-card__body">
                <form class="hst-form" id="hst-profile-form">
                    <div class="hst-form__row">
                        <label class="hst-field">
                            <span>نام</span>
                            <input type="text" name="first_name" value="<?php echo esc_attr($profile_user->first_name); ?>" placeholder="نام" maxlength="60" required>
                        </label>
                        <label class="hst-field">
                            <span>نام خانوادگی</span>
                            <input type="text" name="last_name" value="<?php echo esc_attr($profile_user->last_name); ?>" placeholder="نام خانوادگی" maxlength="60" required>
                        </label>
                    </div>
                    <?php if ($role === 'teacher') : ?>
                        <label class="hst-field">
                            <span>بیوگرافی</span>
                            <textarea name="teacher_bio" rows="4" maxlength="1000" placeholder="معرفی کوتاهی از خودتان بنویسید؛ این متن برای دانش‌آموزان شما نمایش داده می‌شود (اختیاری)..."><?php echo esc_textarea($teacher_bio); ?></textarea>
                        </label>
                    <?php endif; ?>
                    <button type="submit" class="hst-btn">ذخیره اطلاعات</button>
                </form>

                <dl class="hst-deflist">
                    <div class="hst-deflist__row">
                        <dt>نام کاربری / کد ملی</dt>
                        <dd><?php echo esc_html($profile_user->user_login ?: '—'); ?></dd>
                    </div>
                    <div class="hst-deflist__row">
                        <dt>شماره موبایل ثبت‌شده</dt>
                        <dd><?php echo esc_html($phone ?: '—'); ?></dd>
                    </div>
                    <div class="hst-deflist__row">
                        <dt>سال تحصیلی فعال</dt>
                        <dd><?php echo esc_html($active_term->term_name ?? 'سال تحصیلی فعالی ثبت نشده است'); ?></dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="hst-card">
            <div class="hst-card__header"><h3>امنیت حساب</h3></div>
            <div class="hst-card__body">
                <form class="hst-form" id="hst-password-form">
                    <label class="hst-field">
                        <span>رمز عبور جدید</span>
                        <input type="password" name="new_password" autocomplete="new-password" placeholder="رمز عبور جدید؛ حداقل ۸ کاراکتر" minlength="8" required>
                    </label>
                    <label class="hst-field">
                        <span>تکرار رمز عبور جدید</span>
                        <input type="password" name="confirm_password" autocomplete="new-password" placeholder="تکرار رمز عبور جدید" minlength="8" required>
                    </label>
                    <button type="submit" class="hst-btn">تغییر رمز عبور</button>
                </form>
                <p class="hst-alert">برای حفظ امنیت حساب، رمز عبور جدید را با کسی به اشتراک نگذارید.</p>
            </div>
        </div>
    </div>

    <div class="hst-card">
        <div class="hst-card__header">
            <h3><?php echo $role === 'teacher' ? 'کلاس‌های من در سال تحصیلی فعال' : 'کلاس من در سال تحصیلی فعال'; ?></h3>
        </div>
        <div class="hst-card__body">
            <?php if (!empty($classes)) : ?>
                <div class="hst-stat-row">
                    <?php foreach ($classes as $class) : ?>
                        <div class="hst-stat">
                            <span>کلاس</span>
                            <strong><?php echo esc_html($class->class_name); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="hst-alert">برای سال تحصیلی فعال، کلاسی برای شما ثبت نشده است.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
</div>
