<?php
defined('ABSPATH') || exit;

include_once HST_PATH . 'templates/user/common/hst-icons.php';

$is_logged_in = is_user_logged_in();
$current_user = wp_get_current_user();

$hst_school_name = get_option('hst-home-school-name', '');
if ($hst_school_name === '') {
    $hst_school_name = get_bloginfo('name') ?: 'سامانه دفتر مدرسه';
}
$hst_home_tagline = get_option('hst-home-tagline', '');
if ($hst_home_tagline === '') {
    $hst_home_tagline = 'پورتال آنلاین مدرسه برای دسترسی به نمرات، برنامهٔ هفتگی، حضور و غیاب، تکالیف و اطلاعیه‌ها.';
}

$dashboard_url = home_url('/dashboard');
$login_url     = class_exists('HST_Settings') ? HST_Settings::login_page_url($dashboard_url) : wp_login_url($dashboard_url);

$hst_logo_id   = absint(get_option('hst-home-logo-id', 0));
$hst_logo_url  = $hst_logo_id ? wp_get_attachment_image_url($hst_logo_id, 'medium') : '';
$hst_footer_note = get_option('hst-home-footer-note', '');
if ($hst_footer_note === '') {
    $hst_footer_note = 'پشتیبانی‌شده توسط تیچرشو';
}

// The prepared landing page is the default site front page.
$home_link = home_url('/');

// Brand mark: uploaded logo image, or the default inline SVG.
$svg_logo = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4l9 4-9 4-9-4 9-4z"/><path d="M6 10v5c0 1.5 12 1.5 12 0v-5"/></svg>';
if ($hst_logo_url) {
    $brand_logo = '<span class="hst-landing__logo hst-landing__logo--img" aria-hidden="true"><img src="' . esc_url($hst_logo_url) . '" alt=""></span>';
} else {
    $brand_logo = '<span class="hst-landing__logo" aria-hidden="true">' . $svg_logo . '</span>';
}

// Reusable inline arrow (points right→left for RTL).
$arrow = '<svg class="hst-btn__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>';

$features = [
    ['icon' => 'scores',        'title' => 'ثبت و کارنامه نمرات',  'desc' => 'ثبت نمرات دوره‌ای توسط معلم و مشاهده کارنامه توسط دانش‌آموز و خانواده.'],
    ['icon' => 'schedule',      'title' => 'برنامه هفتگی',   'desc' => 'چینش زنگ‌ها با کشیدن و رها کردن و چینش منظم برنامه‌ی بدون تداخل.'],
    ['icon' => 'attendance',    'title' => 'حضور و غیاب',           'desc' => 'ثبت سریع حاضر، غایب، تأخیر و موجه برای هر جلسه کلاس.'],
    ['icon' => 'notifications', 'title' => 'اطلاع‌رسانی و پیامک',    'desc' => 'ارسال اطلاعیه به مدرسه و پیامک خودکار به مخاطبان انتخابی.'],
    ['icon' => 'assignments',   'title' => 'تکالیف کلاسی',          'desc' => 'تعریف تکلیف توسط معلم و پیگیری وضعیت تحویل دانش‌آموزان.'],
    ['icon' => 'tuition',       'title' => 'شهریه',          'desc' => 'صدور و پیگیری پرداخت شهریه با اتصال به درگاه ووکامرس.'],
];

$mock_tiles = [
    ['icon' => 'students', 'label' => 'دانش‌آموزان'],
    ['icon' => 'scores',   'label' => 'نمرات'],
    ['icon' => 'schedule', 'label' => 'برنامه'],
    ['icon' => 'exams',    'label' => 'آزمون‌ها'],
];

$roles = [
    [
        'icon'  => 'teachers',
        'name'  => 'مدیر مدرسه',
        'desc'  => 'کنترل کامل مدرسه از یک پنل.',
        'items' => ['تعریف کلاس‌ها، درس‌ها و سال‌های تحصیلی', 'معلمان و دانش‌آموزان', 'برنامهٔ سراسری و شهریه'],
    ],
    [
        'icon'  => 'scores',
        'name'  => 'معلم',
        'desc'  => 'ابزارهای روزمرهٔ کلاس.',
        'items' => ['ثبت نمره و حضور و غیاب', 'تعریف تکلیف و آزمون', 'مشاهدهٔ برنامهٔ شخصی'],
    ],
    [
        'icon'  => 'profile',
        'name'  => 'دانش‌آموز و خانواده',
        'desc'  => 'پیگیری وضعیت تحصیلی.',
        'items' => ['کارنامه و نمرات', 'برنامهٔ هفتگی و تکالیف', 'پرداخت شهریه و اطلاعیه‌ها'],
    ],
];

$steps = [
    ['n' => '۱', 'title' => 'ورود به حساب', 'desc' => 'با نام کاربری و رمز عبور مدرسه وارد سامانه شوید.'],
    ['n' => '۲', 'title' => 'پیشخوان نقش‌محور', 'desc' => 'بر اساس نقش شما فقط ابزارهای مرتبط نمایش داده می‌شوند.'],
    ['n' => '۳', 'title' => 'کارهای روزمره', 'desc' => 'نمره، حضور، برنامه و اطلاع‌رسانی را در چند کلیک انجام دهید.'],
];
?>
<div class="hst-shell hst-landing <?php echo class_exists('HST_Settings') ? esc_attr(HST_Settings::shell_mode_class()) : 'hst-shell--app'; ?>" dir="rtl">

    <header class="hst-landing__bar">
        <a class="hst-landing__brand" href="<?php echo esc_url($home_link); ?>" aria-label="<?php echo esc_attr($hst_school_name); ?>">
            <?php echo $brand_logo; ?>
            <span class="hst-landing__brand-name"><?php echo esc_html($hst_school_name); ?></span>
        </a>
        <nav class="hst-landing__nav">
            <a class="hst-landing__nav-link" href="#hst-features">دسترسی‌ها</a>
            <a class="hst-landing__nav-link" href="#hst-roles">کاربران</a>
            <?php if ($is_logged_in) : ?>
                <a class="hst-btn" href="<?php echo esc_url($dashboard_url); ?>">پیشخوان<?php echo $arrow; ?></a>
            <?php else : ?>
                <a class="hst-btn" href="<?php echo esc_url($login_url); ?>">ورود به سامانه<?php echo $arrow; ?></a>
            <?php endif; ?>
        </nav>
    </header>

    <section class="hst-landing__hero">
        <div class="hst-landing__hero-text">
            <span class="hst-landing__eyebrow"><span class="hst-landing__eyebrow-dot"></span><?php echo esc_html($hst_school_name); ?></span>
            <h1>به سامانهٔ <span><?php echo esc_html($hst_school_name); ?></span> خوش آمدید</h1>
            <p><?php echo esc_html($hst_home_tagline); ?></p>
            <div class="hst-landing__cta">
                <?php if ($is_logged_in) : ?>
                    <a class="hst-btn hst-btn--lg" href="<?php echo esc_url($dashboard_url); ?>">رفتن به پیشخوان<?php echo $arrow; ?></a>
                <?php else : ?>
                    <a class="hst-btn hst-btn--lg" href="<?php echo esc_url($login_url); ?>">ورود به سامانه<?php echo $arrow; ?></a>
                <?php endif; ?>
                <a class="hst-btn hst-btn--lg hst-btn--ghost" href="#hst-features">دسترسی‌های سامانه</a>
            </div>
            <ul class="hst-landing__assurances">
                <li>راست‌چین و کاملاً فارسی</li>
                <li>دسترسی از هر دستگاه</li>
                <li>ورود نقش‌محور</li>
            </ul>
        </div>

        <div class="hst-landing__hero-art" aria-hidden="true">
            <div class="hst-landing__window">
                <div class="hst-landing__window-bar">
                    <span></span><span></span><span></span>
                    <em>پیشخوان مدرسه</em>
                </div>
                <div class="hst-landing__window-body">
                    <div class="hst-landing__mock-head">
                        <div class="hst-landing__mock-avatar"></div>
                        <div class="hst-landing__mock-lines"><b></b><i></i></div>
                        <div class="hst-landing__mock-bell"><?php echo hst_icon('bell'); ?></div>
                    </div>
                    <div class="hst-landing__mock-tiles">
                        <?php foreach ($mock_tiles as $tile) : ?>
                            <div class="hst-landing__mock-tile">
                                <span><?php echo hst_icon($tile['icon']); ?></span>
                                <small><?php echo esc_html($tile['label']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="hst-landing__mock-row">
                        <div class="hst-landing__mock-stat"><b>۱۸.۷</b><i>میانگین کلاس</i></div>
                        <div class="hst-landing__mock-stat"><b>۹۸٪</b><i>حضور امروز</i></div>
                    </div>
                    <div class="hst-landing__mock-bars">
                        <span></span><span></span><span></span><span></span><span></span>
                    </div>
                </div>
            </div>
            <div class="hst-landing__chip hst-landing__chip--1"><?php echo hst_icon('attendance'); ?><span>حضور ثبت شد</span></div>
            <div class="hst-landing__chip hst-landing__chip--2"><?php echo hst_icon('notifications'); ?><span>اطلاعیه جدید</span></div>
        </div>
    </section>

    <section class="hst-landing__features" id="hst-features" aria-label="دسترسی‌های سامانه">
        <div class="hst-landing__section-head">
            <span class="hst-landing__eyebrow">دسترسی‌ها</span>
            <h2>کارهایی که در سامانه انجام می‌دهید</h2>
            <p>بخش‌های اصلی سامانهٔ مدرسه، در یک پنل یکپارچه و ساده.</p>
        </div>
        <div class="hst-landing__feature-grid">
            <?php foreach ($features as $feature) : ?>
                <article class="hst-landing__feature">
                    <span class="hst-landing__feature-icon"><?php echo hst_icon($feature['icon']); ?></span>
                    <h3><?php echo esc_html($feature['title']); ?></h3>
                    <p><?php echo esc_html($feature['desc']); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="hst-landing__roles" id="hst-roles" aria-label="نقش‌های کاربری">
        <div class="hst-landing__section-head">
            <span class="hst-landing__eyebrow">کاربران</span>
            <h2>هر کاربر، دسترسی مخصوص خودش</h2>
            <p>پس از ورود، هر کاربر فقط بخش‌های مرتبط با نقش خود را می‌بیند.</p>
        </div>
        <div class="hst-landing__role-grid">
            <?php foreach ($roles as $role) : ?>
                <article class="hst-landing__role">
                    <div class="hst-landing__role-head">
                        <span class="hst-landing__role-icon"><?php echo hst_icon($role['icon']); ?></span>
                        <div>
                            <h3><?php echo esc_html($role['name']); ?></h3>
                            <p><?php echo esc_html($role['desc']); ?></p>
                        </div>
                    </div>
                    <ul class="hst-landing__role-list">
                        <?php foreach ($role['items'] as $item) : ?>
                            <li><?php echo esc_html($item); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="hst-landing__steps" aria-label="شروع کار">
        <div class="hst-landing__section-head">
            <span class="hst-landing__eyebrow">شروع سریع</span>
            <h2>در سه گام وارد سامانه شوید</h2>
        </div>
        <div class="hst-landing__step-grid">
            <?php foreach ($steps as $step) : ?>
                <article class="hst-landing__step">
                    <span class="hst-landing__step-num"><?php echo esc_html($step['n']); ?></span>
                    <h3><?php echo esc_html($step['title']); ?></h3>
                    <p><?php echo esc_html($step['desc']); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="hst-landing__band">
        <div class="hst-landing__band-text">
            <h2>همین حالا وارد سامانه شوید</h2>
            <p>مدیر، معلم و دانش‌آموز هر کدام با نقش خود وارد می‌شوند و به ابزارهای مخصوص خود دسترسی دارند.</p>
        </div>
        <?php if ($is_logged_in) : ?>
            <a class="hst-btn hst-btn--lg hst-btn--invert" href="<?php echo esc_url($dashboard_url); ?>">رفتن به پیشخوان<?php echo $arrow; ?></a>
        <?php else : ?>
            <a class="hst-btn hst-btn--lg hst-btn--invert" href="<?php echo esc_url($login_url); ?>">ورود به سامانه<?php echo $arrow; ?></a>
        <?php endif; ?>
    </section>

    <footer class="hst-landing__foot" aria-label="پاورقی صفحه اصلی سامانه">
        <div class="hst-landing__foot-main">
            <div class="hst-landing__foot-brand">
                <a class="hst-landing__brand" href="<?php echo esc_url($home_link); ?>" aria-label="<?php echo esc_attr($hst_school_name); ?>">
                    <?php echo $brand_logo; ?>
                    <span><?php echo esc_html($hst_school_name); ?></span>
                </a>
                <p><?php echo esc_html($hst_home_tagline); ?></p>
                <div class="hst-landing__foot-badges" aria-label="ویژگی‌های سامانه">
                    <span><?php echo hst_icon('profile'); ?> نقش‌محور</span>
                    <span><?php echo hst_icon('schedule'); ?> برنامه هفتگی</span>
                    <span><?php echo hst_icon('notifications'); ?> اطلاع‌رسانی</span>
                </div>
            </div>

            <div class="hst-landing__foot-col">
                <h3>دسترسی سریع</h3>
                <a href="#hst-features">دسترسی‌های سامانه</a>
                <a href="#hst-roles">کاربران سامانه</a>
                <?php if ($is_logged_in) : ?>
                    <a href="<?php echo esc_url($dashboard_url); ?>">ورود به پیشخوان</a>
                <?php else : ?>
                    <a href="<?php echo esc_url($login_url); ?>">ورود به سامانه</a>
                <?php endif; ?>
            </div>

            <div class="hst-landing__foot-col">
                <h3>بخش‌های سامانه</h3>
                <span>نمرات و کارنامه</span>
                <span>حضور و غیاب</span>
                <span>تکالیف و آزمون‌ها</span>
                <span>شهریه و پرداخت‌ها</span>
            </div>
        </div>

        <div class="hst-landing__foot-bottom">
            <span class="hst-landing__foot-note"><?php echo esc_html($hst_footer_note); ?></span>
            <span class="hst-landing__foot-copy">© <?php echo esc_html(date_i18n('Y')); ?> <?php echo esc_html($hst_school_name); ?></span>
        </div>
    </footer>

</div>
