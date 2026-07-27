<?php
defined('ABSPATH') || exit;
$settings_keys = class_exists('HST_Settings') ? HST_Settings::setting_keys() : [];
$sms_api_key = get_option($settings_keys['sms_api_key'] ?? 'hst-sms-api-key', '');
$sms_sender = get_option($settings_keys['sms_sender'] ?? 'hst-sms-sender', '');
$sms_enabled = get_option($settings_keys['sms_enabled'] ?? 'hst-sms-enabled', '0');
$sms_login_enabled = get_option($settings_keys['sms_login_enabled'] ?? 'hst-sms-login-enabled', '0');
$sms_otp_pattern_code = get_option($settings_keys['sms_otp_pattern_code'] ?? 'hst-sms-otp-pattern-code', '');
$sms_otp_pattern_var = get_option($settings_keys['sms_otp_pattern_var'] ?? 'hst-sms-otp-pattern-var', class_exists('HST_SMS') ? HST_SMS::default_otp_pattern_var() : 'OTP');
if (class_exists('HST_SMS')) {
    $sms_otp_pattern_code = HST_SMS::sanitize_pattern_identifier($sms_otp_pattern_code);
    $sms_otp_pattern_var = HST_SMS::sanitize_pattern_variable($sms_otp_pattern_var, HST_SMS::default_otp_pattern_var());
}
$birthday_sms_enabled = get_option($settings_keys['birthday_sms_enabled'] ?? 'hst-birthday-sms-enabled', '0');
$birthday_default = class_exists('HST_Birthday') ? HST_Birthday::default_template() : '{name} عزیز، تولدت مبارک!';
$birthday_template = get_option($settings_keys['birthday_template'] ?? 'hst-birthday-template', $birthday_default);
$birthday_template = class_exists('HST_SMS') ? HST_SMS::message_template($birthday_template, 'birthday') : $birthday_template;
$birthday_template_vars = [
    '{name}'   => 'نام مخاطب',
    '{school}' => 'نام مدرسه',
];
$hst_settings_section = isset($_GET['settings_section']) ? sanitize_key(wp_unslash($_GET['settings_section'])) : '';
if (!in_array($hst_settings_section, ['general', 'sms'], true)) {
    $hst_settings_section = '';
}
$hst_settings_general_url = add_query_arg('settings_section', 'general');
$hst_settings_sms_url = add_query_arg('settings_section', 'sms');
?>
<div class="hst-settings" dir="rtl" data-hst-settings data-hst-initial-section="<?php echo esc_attr($hst_settings_section); ?>">

    <form method="post" class="hst-form hst-settings__form" data-hst-settings-form>

        <div class="hst-card hst-section-card hst-no-print">
            <div class="hst-card__body hst-section-card__body">
                <nav class="hst-dashboard hst-dashboard--management hst-dashboard--two" aria-label="بخش‌های تنظیمات سامانه">
                    <a
                        href="<?php echo esc_url($hst_settings_general_url); ?>"
                        class="hst-tile"
                        data-hst-settings-section="general"
                        aria-controls="hst-settings-general"
                        aria-expanded="<?php echo $hst_settings_section === 'general' ? 'true' : 'false'; ?>"
                    >
                        <span class="hst-chip">سامانه</span>
                        <span class="hst-tile__icon"><?php echo hst_icon('settings'); ?></span>
                        <span>عمومی</span>
                    </a>
                    <a
                        href="<?php echo esc_url($hst_settings_sms_url); ?>"
                        class="hst-tile"
                        data-hst-settings-section="sms"
                        aria-controls="hst-settings-sms"
                        aria-expanded="<?php echo $hst_settings_section === 'sms' ? 'true' : 'false'; ?>"
                    >
                        <span class="hst-chip">Panelchi</span>
                        <span class="hst-tile__icon"><?php echo hst_icon('sms'); ?></span>
                        <span>پیامک</span>
                    </a>
                </nav>
            </div>
        </div>

        <div class="hst-settings__sections">
            <div id="hst-settings-general" class="hst-settings__section" data-hst-settings-section-panel="general" <?php echo $hst_settings_section === 'general' ? '' : 'hidden'; ?>>
                <?php
                $hst_global_logo_id  = absint(get_option($settings_keys['home_logo'] ?? 'hst-home-logo-id', 0));
                $hst_global_logo_url = $hst_global_logo_id ? wp_get_attachment_image_url($hst_global_logo_id, 'medium') : '';
                ?>
                <section class="hst-card">
                    <div class="hst-card__header">
                        <div>
                            <h3>لوگوی سراسری</h3>
                            <p>یک لوگو برای کل سامانه آپلود کنید تا در بخش‌های اصلی و خروجی‌های سامانه استفاده شود.</p>
                        </div>
                    </div>
                    <div class="hst-card__body">
                        <div class="hst-field hst-logo-field" data-hst-logo-field>
                            <span>لوگوی سامانه</span>
                            <div class="hst-logo-field__row">
                                <img class="hst-logo-field__preview" data-hst-logo-preview src="<?php echo esc_url($hst_global_logo_url); ?>" alt="پیش‌نمایش لوگو" <?php echo $hst_global_logo_url ? '' : 'hidden'; ?>>
                                <div class="hst-btn-group">
                                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-logo-choose>انتخاب لوگو</button>
                                    <button type="button" class="hst-btn hst-btn--ghost hst-btn--sm" data-hst-logo-remove <?php echo $hst_global_logo_url ? '' : 'hidden'; ?>>حذف لوگو</button>
                                </div>
                                <input type="hidden" name="<?php echo esc_attr($settings_keys['home_logo'] ?? 'hst-home-logo-id'); ?>" value="<?php echo esc_attr($hst_global_logo_id); ?>" data-hst-logo-input>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="hst-card">
                    <div class="hst-card__header">
                        <div>
                            <h3>صفحه اصلی و ورود</h3>
                        </div>
                    </div>
                    <div class="hst-card__body">
                        <div class="hst-form__row">
                            <label class="hst-field">
                                <span>نام مدرسه / عنوان صفحه</span>
                                <input type="text" name="<?php echo esc_attr($settings_keys['home_school_name']); ?>" value="<?php echo esc_attr(get_option($settings_keys['home_school_name'], '')); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                            </label>
                            <label class="hst-field">
                                <span>شعار / توضیح کوتاه</span>
                                <input type="text" name="<?php echo esc_attr($settings_keys['home_tagline']); ?>" value="<?php echo esc_attr(get_option($settings_keys['home_tagline'], '')); ?>" placeholder="سامانه مدرسه برای نمرات، برنامه و حضور و غیاب">
                            </label>
                            <label class="hst-field">
                                <span>متن پاورقی (برند)</span>
                                <input type="text" name="<?php echo esc_attr($settings_keys['home_footer_note']); ?>" value="<?php echo esc_attr(get_option($settings_keys['home_footer_note'], '')); ?>" placeholder="پشتیبانی‌شده توسط تیچرشو">
                            </label>
                        </div>
                    </div>
                </section>

                <section class="hst-card">
                    <div class="hst-card__header">
                        <div>
                            <h3>اعلان‌های خودکار</h3>
                            <p>رویدادهای دارای اعلان خودکار را انتخاب کنید.</p>
                        </div>
                    </div>
                    <div class="hst-card__body">
                        <?php
                        $hst_notify_items = [
                            'hst-notify-assignment-created'   => 'تکلیف جدید (اطلاع به دانش‌آموزان کلاس)',
                            'hst-notify-assignment-submitted' => 'ارسال تکلیف توسط دانش‌آموز (اطلاع به معلم)',
                            'hst-notify-exam-created'         => 'آزمون جدید (اطلاع به دانش‌آموزان کلاس)',
                            'hst-notify-grade-registered'     => 'ثبت نمرهٔ دوره‌ای (اطلاع به دانش‌آموز)',
                            'hst-notify-tuition-created'      => 'صدور شهریهٔ جدید (اطلاع به دانش‌آموز)',
                            'hst-notify-avatar-reviewed'      => 'بررسی تصویر پروفایل (اطلاع به کاربر)',
                        ];
                        foreach ($hst_notify_items as $opt => $label) :
                        ?>
                            <label class="hst-check">
                                <input type="checkbox" name="<?php echo esc_attr($opt); ?>" value="1" <?php checked(get_option($opt, '1'), '1'); ?>>
                                <span><?php echo esc_html($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="hst-card">
                    <div class="hst-card__header">
                        <div>
                            <h3>نصب اپلیکیشن</h3>
                            <p>امکان نصب سامانه روی گوشی و دسکتاپ را فعال می‌کند.</p>
                        </div>
                    </div>
                    <div class="hst-card__body">
                        <label class="hst-check">
                            <input type="checkbox" name="hst-pwa-enabled" value="1" <?php checked(get_option('hst-pwa-enabled', '1'), '1'); ?>>
                            <span>قابلیت نصب اپلیکیشن (PWA) فعال باشد</span>
                        </label>
                    </div>
                </section>
            </div><!-- /general -->

            <div id="hst-settings-sms" class="hst-settings__section" data-hst-settings-section-panel="sms" <?php echo $hst_settings_section === 'sms' ? '' : 'hidden'; ?>>
                <section class="hst-card">
                    <div class="hst-card__header">
                        <div>
                            <h3>پیامک</h3>
                        </div>
                    </div>

                    <div class="hst-card__body">
                        <div class="hst-form__row">
                            <div class="hst-field">
                                <span>فعال بودن پیامک سامانه</span>
                                <label class="hst-switch">
                                    <input type="checkbox" name="<?php echo esc_attr($settings_keys['sms_enabled'] ?? 'hst-sms-enabled'); ?>" value="1" <?php checked($sms_enabled, '1'); ?>>
                                    <span class="hst-switch__slider"></span>
                                </label>
                            </div>

                            <div class="hst-field">
                                <span>ورود پیامکی با کد تأیید</span>
                                <label class="hst-switch">
                                    <input type="checkbox" name="<?php echo esc_attr($settings_keys['sms_login_enabled'] ?? 'hst-sms-login-enabled'); ?>" value="1" <?php checked($sms_login_enabled, '1'); ?>>
                                    <span class="hst-switch__slider"></span>
                                </label>
                            </div>

                            <div class="hst-field">
                                <span>ارسال خودکار پیامک تبریک تولد</span>
                                <label class="hst-switch">
                                    <input type="checkbox" name="<?php echo esc_attr($settings_keys['birthday_sms_enabled'] ?? 'hst-birthday-sms-enabled'); ?>" value="1" <?php checked($birthday_sms_enabled, '1'); ?>>
                                    <span class="hst-switch__slider"></span>
                                </label>
                            </div>

                            <label class="hst-field">
                                <span>توکن API پنلچی</span>
                                <input type="password" name="<?php echo esc_attr($settings_keys['sms_api_key'] ?? 'hst-sms-api-key'); ?>" value="<?php echo esc_attr($sms_api_key); ?>" autocomplete="new-password">
                            </label>

                            <label class="hst-field">
                                <span>سرشماره ارسال</span>
                                <input type="text" name="<?php echo esc_attr($settings_keys['sms_sender'] ?? 'hst-sms-sender'); ?>" value="<?php echo esc_attr($sms_sender); ?>" placeholder="مثلاً 10001">
                            </label>

                            <label class="hst-field">
                                <span>اسلاگ پترن کد تأیید</span>
                                <input type="text" name="<?php echo esc_attr($settings_keys['sms_otp_pattern_code'] ?? 'hst-sms-otp-pattern-code'); ?>" value="<?php echo esc_attr($sms_otp_pattern_code); ?>" placeholder="otp-code">
                            </label>

                            <label class="hst-field">
                                <span>نام متغیر کد تأیید</span>
                                <input type="text" name="<?php echo esc_attr($settings_keys['sms_otp_pattern_var'] ?? 'hst-sms-otp-pattern-var'); ?>" value="<?php echo esc_attr($sms_otp_pattern_var ?: 'OTP'); ?>" placeholder="OTP">
                            </label>

                            <div class="hst-field">
                                <label for="hst-birthday-sms-template">متن پیامک تبریک تولد</label>
                                <div class="hst-btn-group" role="group" aria-label="متغیرهای قابل استفاده در متن پیامک تبریک تولد">
                                    <?php foreach ($birthday_template_vars as $variable => $label) : ?>
                                        <button
                                            type="button"
                                            class="hst-chip"
                                            data-hst-sms-variable="<?php echo esc_attr($variable); ?>"
                                            data-hst-sms-target="#hst-birthday-sms-template"
                                            title="<?php echo esc_attr('درج ' . $label); ?>"
                                            aria-label="<?php echo esc_attr('درج ' . $label . ' در متن پیامک تبریک تولد'); ?>"
                                        ><?php echo esc_html($label); ?></button>
                                    <?php endforeach; ?>
                                </div>
                                <textarea id="hst-birthday-sms-template" name="<?php echo esc_attr($settings_keys['birthday_template'] ?? 'hst-birthday-template'); ?>" rows="4" maxlength="500"><?php echo esc_textarea($birthday_template); ?></textarea>
                            </div>
                        </div>

                    </div>
                </section>
            </div><!-- /sms -->
        </div><!-- /sections -->

        <div class="hst-settings__savebar" data-hst-settings-savebar <?php echo $hst_settings_section === '' ? 'hidden' : ''; ?>>
            <button type="submit" class="hst-btn" data-hst-settings-submit>ذخیره تنظیمات</button>
        </div>
    </form>
</div>

<script>
(function () {
    if (window.jQuery && window.wp && wp.media) {
        jQuery('[data-hst-logo-field]').each(function () {
            var $wrap = jQuery(this);
            var frame;
            var $input = $wrap.find('[data-hst-logo-input]');
            var $preview = $wrap.find('[data-hst-logo-preview]');
            var $remove = $wrap.find('[data-hst-logo-remove]');

            $wrap.on('click', '[data-hst-logo-choose]', function (event) {
                event.preventDefault();
                if (frame) {
                    frame.open();
                    return;
                }

                frame = wp.media({
                    title: 'انتخاب لوگو',
                    button: { text: 'استفاده از این تصویر' },
                    library: { type: 'image' },
                    multiple: false
                });
                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                    $input.val(attachment.id);
                    $preview.attr('src', url).prop('hidden', false);
                    $remove.prop('hidden', false);
                });
                frame.open();
            });

            $wrap.on('click', '[data-hst-logo-remove]', function (event) {
                event.preventDefault();
                $input.val('');
                $preview.removeAttr('src').prop('hidden', true);
                $remove.prop('hidden', true);
            });
        });
    }

    var settingsRoot = document.querySelector('[data-hst-settings]');
    if (!settingsRoot) return;

    var settingsForm = settingsRoot.querySelector('[data-hst-settings-form]');
    var settingsSubmit = settingsRoot.querySelector('[data-hst-settings-submit]');
    var settingsTiles = settingsRoot.querySelectorAll('[data-hst-settings-section]');
    var settingsSections = settingsRoot.querySelectorAll('[data-hst-settings-section-panel]');
    var settingsSavebar = settingsRoot.querySelector('[data-hst-settings-savebar]');
    var settingsPage = settingsRoot.closest('.hst-plugin-settings');
    var settingsBack = settingsPage ? settingsPage.querySelector('[data-hst-settings-back]') : null;
    var validSettingsSections = Array.prototype.map.call(settingsSections, function (section) {
        return section.getAttribute('data-hst-settings-section-panel') || '';
    });

    function normalizeSettingsSection(value) {
        value = String(value || '');
        return validSettingsSections.indexOf(value) !== -1 ? value : '';
    }

    function settingsSectionFromLocation() {
        try {
            return normalizeSettingsSection(new URL(window.location.href).searchParams.get('settings_section'));
        } catch (error) {
            return '';
        }
    }

    function updateSettingsAddress(section, replace) {
        if (!window.history || !window.history.pushState) return;
        var url = new URL(window.location.href);
        if (section) {
            url.searchParams.set('settings_section', section);
        } else {
            url.searchParams.delete('settings_section');
        }
        window.history[replace ? 'replaceState' : 'pushState'](
            { hstSettingsSection: section },
            '',
            url.toString()
        );
    }

    function showSettingsSection(section, options) {
        options = Object.assign({ updateHistory: false, replaceHistory: false, scroll: false }, options || {});
        section = normalizeSettingsSection(section);

        Array.prototype.forEach.call(settingsSections, function (panel) {
            panel.hidden = panel.getAttribute('data-hst-settings-section-panel') !== section;
        });
        Array.prototype.forEach.call(settingsTiles, function (tile) {
            var active = tile.getAttribute('data-hst-settings-section') === section;
            tile.setAttribute('aria-expanded', active ? 'true' : 'false');
            if (active) {
                tile.setAttribute('aria-current', 'page');
            } else {
                tile.removeAttribute('aria-current');
            }
        });
        if (settingsSavebar) settingsSavebar.hidden = section === '';
        settingsRoot.setAttribute('data-hst-active-section', section);

        if (options.updateHistory) {
            updateSettingsAddress(section, options.replaceHistory);
        }
        if (options.scroll && section) {
            var activePanel = settingsRoot.querySelector('[data-hst-settings-section-panel="' + section + '"]');
            if (activePanel) {
                window.requestAnimationFrame(function () {
                    activePanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            }
        }
    }

    Array.prototype.forEach.call(settingsTiles, function (tile) {
        tile.addEventListener('click', function (event) {
            event.preventDefault();
            showSettingsSection(tile.getAttribute('data-hst-settings-section'), {
                updateHistory: true,
                scroll: true
            });
        });
    });

    window.addEventListener('popstate', function () {
        showSettingsSection(settingsSectionFromLocation());
    });

    if (settingsBack) {
        settingsBack.addEventListener('click', function (event) {
            event.preventDefault();
            var fallback = settingsBack.getAttribute('data-hst-fallback') || '/dashboard';
            var sameOriginReferrer = document.referrer && document.referrer.indexOf(window.location.origin) === 0;
            if (window.history.length > 1 && sameOriginReferrer) {
                window.history.back();
            } else {
                window.location.href = fallback;
            }
        });
    }

    if (settingsForm) {
        settingsForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!settingsForm.checkValidity()) {
                settingsForm.reportValidity();
                return;
            }

            if (!window.HST || typeof window.HST.request !== 'function') {
                if (window.HST && typeof window.HST.toast === 'function') {
                    window.HST.toast('امکان ذخیره تنظیمات در حال حاضر وجود ندارد.', 'error');
                }
                return;
            }

            var requestData = {};
            var formData = new FormData(settingsForm);
            formData.forEach(function (value, key) {
                if (Object.prototype.hasOwnProperty.call(requestData, key)) {
                    if (!Array.isArray(requestData[key])) {
                        requestData[key] = [requestData[key]];
                    }
                    requestData[key].push(value);
                    return;
                }
                requestData[key] = value;
            });

            window.HST.request({
                action: 'hst_save_settings',
                data: requestData,
                trigger: settingsSubmit,
                showLoader: true,
                successMessage: true,
                errorMessage: 'ذخیره تنظیمات انجام نشد.'
            });
        });
    }

    var initialSettingsSection = normalizeSettingsSection(
        settingsRoot.getAttribute('data-hst-initial-section') || settingsSectionFromLocation()
    );
    showSettingsSection(initialSettingsSection, {
        updateHistory: true,
        replaceHistory: true
    });

})();
</script>
