<?php
defined('ABSPATH') || exit;

include_once HST_PATH . 'templates/user/common/hst-icons.php';

$is_logged_in = is_user_logged_in();
$current_user = wp_get_current_user();

$hst_school_name = get_option('hst-home-school-name', '');
if ($hst_school_name === '') {
    $hst_school_name = get_bloginfo('name') ?: 'سامانه دفتر مدرسه';
}

$hst_logo_id  = absint(get_option('hst-home-logo-id', 0));
$hst_logo_url = $hst_logo_id ? wp_get_attachment_image_url($hst_logo_id, 'medium') : '';

$dashboard_url = home_url('/dashboard');
$home_link     = home_url('/');

// Where to send the user after a successful login.
$redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : $dashboard_url;

$otp_enabled = class_exists('HST_Otp_Login') && HST_Otp_Login::enabled();
$otp_nonce   = wp_create_nonce('hst_otp_login');
$ajax_url    = admin_url('admin-ajax.php');

// Login error feedback (set by HST_Settings::hst_login_failed_redirect).
$login_state = isset($_GET['login']) ? sanitize_key(wp_unslash($_GET['login'])) : '';
$error_message = '';
if ($login_state === 'failed') {
    $error_message = 'نام کاربری یا رمز عبور نادرست است. دوباره تلاش کنید.';
} elseif ($login_state === 'empty') {
    $error_message = 'لطفاً نام کاربری و رمز عبور را وارد کنید.';
} elseif ($login_state === 'loggedout') {
    $error_message = '';
}

$brand_logo = $hst_logo_url
    ? '<span class="hst-login__logo hst-login__logo--img"><img src="' . esc_url($hst_logo_url) . '" alt=""></span>'
    : '<span class="hst-login__logo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4l9 4-9 4-9-4 9-4z"/><path d="M6 10v5c0 1.5 12 1.5 12 0v-5"/></svg></span>';
?>
<div class="hst-shell hst-login <?php echo class_exists('HST_Settings') ? esc_attr(HST_Settings::shell_mode_class()) : 'hst-shell--app'; ?>" dir="rtl">
    <div class="hst-login__card">
        <div class="hst-login__aside" aria-hidden="true">
            <div class="hst-login__aside-top">
                <?php echo $brand_logo; ?>
                <span class="hst-login__aside-brand"><?php echo esc_html($hst_school_name); ?></span>
            </div>
            <div class="hst-login__aside-mid">
                <span class="hst-login__aside-badge">پنل مدرسه</span>
                <h2>به سامانهٔ مدرسه خوش آمدید</h2>
                <p>نمرات، برنامهٔ هفتگی، حضور و غیاب و تکالیف — همه در یک پنل یکپارچه و امن.</p>
            </div>
            <ul class="hst-login__aside-list">
                <li><span><?php echo hst_icon('scores'); ?></span>کارنامه و نمرات لحظه‌ای</li>
                <li><span><?php echo hst_icon('schedule'); ?></span>برنامهٔ هفتگی</li>
                <li><span><?php echo hst_icon('notifications'); ?></span>اطلاع‌رسانی و پیامک</li>
            </ul>
            <div class="hst-login__aside-foot">
                <span class="hst-login__aside-dot"></span>
                ورود امن و رمزنگاری‌شده
            </div>
        </div>

        <div class="hst-login__main">
            <a class="hst-login__brand" href="<?php echo esc_url($home_link); ?>">
                <?php echo $brand_logo; ?>
                <span><?php echo esc_html($hst_school_name); ?></span>
            </a>

            <?php if ($is_logged_in) : ?>
                <div class="hst-login__head">
                    <h1>شما وارد شده‌اید</h1>
                    <p>با حساب «<?php echo esc_html($current_user->display_name ?: $current_user->user_login); ?>» وارد سامانه هستید.</p>
                </div>
                <div class="hst-login__form hst-form">
                    <a class="hst-btn hst-btn--lg hst-btn--block" href="<?php echo esc_url($dashboard_url); ?>">رفتن به پیشخوان</a>
                    <a class="hst-btn hst-btn--lg hst-btn--ghost hst-btn--block" href="<?php echo esc_url(wp_logout_url($home_link)); ?>">خروج از حساب</a>
                </div>
            <?php else : ?>
                <div class="hst-login__head">
                    <h1>ورود به سامانه</h1>
                    <p>با نام کاربری و رمز عبور حساب مدرسه وارد شوید.</p>
                </div>

                <?php if ($error_message) : ?>
                    <div class="hst-login__alert" role="alert"><?php echo esc_html($error_message); ?></div>
                <?php endif; ?>

                <?php if ($otp_enabled) : ?>
                    <div class="hst-login__methods" role="tablist">
                        <button type="button" class="hst-login__method is-active" data-hst-login-method="password" aria-selected="true">رمز عبور</button>
                        <button type="button" class="hst-login__method" data-hst-login-method="otp" aria-selected="false">ورود با پیامک</button>
                    </div>
                <?php endif; ?>

                <form class="hst-login__form hst-form" data-hst-login-panel="password" method="post" action="<?php echo esc_url(site_url('wp-login.php', 'login_post')); ?>">
                    <label class="hst-field">
                        <span>نام کاربری (کد ملی) یا ایمیل</span>
                        <input type="text" name="log" autocomplete="username" required placeholder="کد ملی یا ایمیل خود را وارد کنید">
                    </label>

                    <label class="hst-field hst-login__password">
                        <span>رمز عبور</span>
                        <span class="hst-login__password-wrap">
                        <input type="password" name="pwd" id="hst-login-pwd" autocomplete="current-password" required placeholder="••••••••">
                        <button type="button" class="hst-login__toggle" data-hst-login-toggle aria-label="نمایش رمز عبور" aria-pressed="false">
                            <svg class="hst-login__toggle-eye" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="hst-login__toggle-eye-off" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M3 3l18 18"/>
                                <path d="M10.6 10.6A3 3 0 0 0 13.4 13.4"/>
                                <path d="M9.9 5.3A10.8 10.8 0 0 1 12 5c6 0 9.5 7 9.5 7a17.6 17.6 0 0 1-2.7 3.5"/>
                                <path d="M6.2 6.9C3.8 8.5 2.5 12 2.5 12s3.5 7 9.5 7a10.7 10.7 0 0 0 4.1-.8"/>
                            </svg>
                        </button>
                        </span>
                    </label>

                    <div class="hst-login__row">
                        <label class="hst-login__remember">
                            <input type="checkbox" name="rememberme" value="forever" checked>
                            <span>مرا به خاطر بسپار</span>
                        </label>
                        <button type="button" class="hst-login__forgot" data-hst-forgot
                            data-hst-otp-enabled="<?php echo $otp_enabled ? '1' : '0'; ?>">رمز عبور را فراموش کرده‌اید؟</button>
                    </div>

                    <?php if ($otp_enabled) : ?>
                        <div class="hst-login__alert hst-login__alert--info" data-hst-forgot-hint hidden role="status">
                            برای بازیابی، از بخش «ورود با پیامک» وارد شوید؛ پس از ورود، رمز جدیدی برای حساب خود تنظیم کنید.
                        </div>
                    <?php else : ?>
                        <div class="hst-login__alert hst-login__alert--info" data-hst-forgot-notice hidden role="status">
                            برای بازیابی حساب کاربری، لطفاً با پشتیبانی مدرسه تماس بگیرید.
                        </div>
                    <?php endif; ?>

                    <input type="hidden" name="redirect_to" value="<?php echo esc_url($redirect_to); ?>">
                    <button type="submit" name="wp-submit" class="hst-btn hst-btn--lg hst-btn--block">ورود به سامانه</button>
                </form>

                <?php if ($otp_enabled) : ?>
                    <form class="hst-login__form hst-form" data-hst-login-panel="otp" hidden
                          data-hst-otp-url="<?php echo esc_url($ajax_url); ?>"
                          data-hst-otp-nonce="<?php echo esc_attr($otp_nonce); ?>"
                          data-hst-otp-redirect="<?php echo esc_url($redirect_to); ?>">
                        <div class="hst-login__alert hst-login__otp-msg" role="status" hidden></div>

                        <label class="hst-field">
                            <span>شماره موبایل</span>
                            <input type="tel" inputmode="numeric" data-hst-otp-phone autocomplete="tel" placeholder="مثلاً ۰۹۱۲۳۴۵۶۷۸۹">
                        </label>

                        <div class="hst-field hst-login__otp-code-field" hidden>
                            <span>کد تأیید پیامک‌شده</span>
                            <input type="text" inputmode="numeric" maxlength="6" data-hst-otp-code placeholder="کد ۶ رقمی" autocomplete="one-time-code">
                        </div>

                        <button type="button" class="hst-btn hst-btn--lg hst-btn--block" data-hst-otp-send>ارسال کد ورود</button>
                        <button type="button" class="hst-btn hst-btn--lg hst-btn--block" data-hst-otp-verify hidden>تأیید و ورود</button>
                        <button type="button" class="hst-login__otp-resend" data-hst-otp-resend hidden>ارسال دوبارهٔ کد</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <p class="hst-login__foot">
                <a href="<?php echo esc_url($home_link); ?>">بازگشت به صفحهٔ اصلی</a>
            </p>
        </div>
    </div>

    <script>
        (function () {
            var t = document.querySelector('[data-hst-login-toggle]');
            var p = document.getElementById('hst-login-pwd');
            if (t && p) {
                t.addEventListener('click', function () {
                    var show = p.type === 'password';
                    p.type = show ? 'text' : 'password';
                    t.classList.toggle('is-visible', show);
                    t.setAttribute('aria-label', show ? 'پنهان کردن رمز عبور' : 'نمایش رمز عبور');
                    t.setAttribute('aria-pressed', show ? 'true' : 'false');
                });
            }

            // Method tabs (password / OTP)
            var methods = document.querySelectorAll('[data-hst-login-method]');
            var panels = document.querySelectorAll('[data-hst-login-panel]');
            methods.forEach(function (m) {
                m.addEventListener('click', function () {
                    var name = m.getAttribute('data-hst-login-method');
                    methods.forEach(function (x) {
                        var on = x === m;
                        x.classList.toggle('is-active', on);
                        x.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    panels.forEach(function (pn) {
                        pn.hidden = pn.getAttribute('data-hst-login-panel') !== name;
                    });
                });
            });

            // Custom "forgot password" flow — never sends users to the
            // default WordPress lost-password screens.
            var forgotBtn = document.querySelector('[data-hst-forgot]');
            if (forgotBtn) {
                forgotBtn.addEventListener('click', function () {
                    var otpOn = forgotBtn.getAttribute('data-hst-otp-enabled') === '1';
                    if (otpOn) {
                        // Switch to the SMS authentication flow in recovery mode.
                        window.__hstRecovery = true;
                        var otpTab = document.querySelector('[data-hst-login-method="otp"]');
                        if (otpTab) { otpTab.click(); }
                        var hint = document.querySelector('[data-hst-forgot-hint]');
                        if (hint) { hint.hidden = false; }
                    } else {
                        // No SMS login → show a custom support message.
                        var notice = document.querySelector('[data-hst-forgot-notice]');
                        if (notice) { notice.hidden = false; }
                    }
                });
            }

            // OTP flow
            var otp = document.querySelector('[data-hst-login-panel="otp"]');
            if (!otp) return;

            var url = otp.getAttribute('data-hst-otp-url');
            var nonce = otp.getAttribute('data-hst-otp-nonce');
            var redirect = otp.getAttribute('data-hst-otp-redirect');
            var phoneInput = otp.querySelector('[data-hst-otp-phone]');
            var codeField = otp.querySelector('.hst-login__otp-code-field');
            var codeInput = otp.querySelector('[data-hst-otp-code]');
            var sendBtn = otp.querySelector('[data-hst-otp-send]');
            var verifyBtn = otp.querySelector('[data-hst-otp-verify]');
            var resendBtn = otp.querySelector('[data-hst-otp-resend]');
            var msg = otp.querySelector('.hst-login__otp-msg');
            var timer = null;

            function showMsg(text, kind) {
                msg.hidden = false;
                msg.textContent = text;
                msg.classList.toggle('is-ok', kind === 'ok');
            }

            function post(action, data) {
                var body = new URLSearchParams();
                body.append('action', action);
                body.append('nonce', nonce);
                Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
                return fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function (r) { return r.json(); });
            }

            function startCountdown(seconds) {
                if (timer) clearInterval(timer);
                var left = seconds;
                resendBtn.hidden = false;
                resendBtn.disabled = true;
                resendBtn.textContent = 'ارسال دوباره تا ' + left + ' ثانیه';
                timer = setInterval(function () {
                    left -= 1;
                    if (left <= 0) {
                        clearInterval(timer);
                        resendBtn.disabled = false;
                        resendBtn.textContent = 'ارسال دوبارهٔ کد';
                    } else {
                        resendBtn.textContent = 'ارسال دوباره تا ' + left + ' ثانیه';
                    }
                }, 1000);
            }

            function sendCode() {
                var phone = (phoneInput.value || '').trim();
                if (!phone) { showMsg('شماره موبایل را وارد کنید.'); return; }
                sendBtn.disabled = true;
                showMsg('در حال ارسال کد...', 'ok');
                post('hst_send_login_otp', { phone: phone }).then(function (res) {
                    sendBtn.disabled = false;
                    if (res && res.success) {
                        codeField.hidden = false;
                        sendBtn.hidden = true;
                        verifyBtn.hidden = false;
                        phoneInput.setAttribute('readonly', 'readonly');
                        showMsg((res.data && res.data.message) || 'کد ارسال شد.', 'ok');
                        startCountdown((res.data && res.data.wait) || 60);
                        codeInput.focus();
                    } else {
                        showMsg((res && res.data && res.data.message) || 'ارسال کد ناموفق بود.');
                    }
                }).catch(function () { sendBtn.disabled = false; showMsg('خطای ارتباط با سرور.'); });
            }

            function verifyCode() {
                var code = (codeInput.value || '').trim();
                if (code.length !== 6) { showMsg('کد ۶ رقمی را کامل وارد کنید.'); return; }
                verifyBtn.disabled = true;
                showMsg('در حال بررسی کد...', 'ok');
                post('hst_verify_login_otp', { phone: phoneInput.value.trim(), code: code, redirect_to: redirect, recovery: window.__hstRecovery ? '1' : '' }).then(function (res) {
                    if (res && res.success) {
                        showMsg((res.data && res.data.message) || 'ورود موفق بود.', 'ok');
                        window.location.href = (res.data && res.data.redirect) || redirect;
                    } else {
                        verifyBtn.disabled = false;
                        showMsg((res && res.data && res.data.message) || 'کد نادرست است.');
                    }
                }).catch(function () { verifyBtn.disabled = false; showMsg('خطای ارتباط با سرور.'); });
            }

            sendBtn.addEventListener('click', sendCode);
            verifyBtn.addEventListener('click', verifyCode);
            resendBtn.addEventListener('click', sendCode);
            codeInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); verifyCode(); } });
            phoneInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); sendCode(); } });
        })();
    </script>
</div>
