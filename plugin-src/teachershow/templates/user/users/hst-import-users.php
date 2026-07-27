<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';

$hst_classes = class_exists('HST_Classes') ? HST_Classes::all() : [];
$hst_terms   = class_exists('HST_Terms') ? HST_Terms::all() : [];
$hst_active_term = class_exists('HST_Terms') ? (int) HST_Terms::active_id() : 0;
$hst_saved_photo_prefix = (string) get_option('hst-import-photo-prefix', '');
$hst_class_list = array_map(static function ($c) {
    return ['id' => (int) $c->id, 'name' => (string) $c->class_name];
}, $hst_classes);

?>
<script>
window.hstImportClasses = <?php echo wp_json_encode($hst_class_list); ?>;
</script>
<section class="hst-page hst-management-page hst-module hst-module--sida-import" data-hst-import>
    <div class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html(HST_Settings::management_page_title('import-users', 'انتقال از سیدا')); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-inline-filter" data-hst-inline-filter="hst-students-table">
                <div class="hst-inline-filter__add">
                    <button
                        type="button"
                        class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm"
                        id="hst-import-student-photos"
                        title="انتقال تصاویر دانش‌آموزان"
                        aria-label="انتقال تصاویر دانش‌آموزان"
                        <?php disabled(!$hst_active_term); ?>
                    ><?php echo hst_icon('image'); ?><span>انتقال تصاویر</span></button>
                </div>
                <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
            </div>
        </div>
    </div>
    <div class="hst-card hst-section-card">
        <div class="hst-card__header hst-card__header--stack hst-section-card__header">
            <h3>انتقال از سیدا دانش‌آموزان و معلم‌ها</h3>
            <div class="hst-btn-group">
                <p>
                    نوع import را انتخاب کنید و سپس یا متن کپی‌شده از سیدا را وارد کنید یا فایل Excel همان نوع کاربر را بارگذاری کنید.
                    کد ملی به‌عنوان نام کاربری و شماره موبایل برای ورود پیامکی ذخیره می‌شود.
                </p>
            </div>
        </div>
        <div class="hst-card__body hst-section-card__body">
            <?php if (!$hst_active_term) : ?>
                <p class="hst-alert hst-alert--warning hst-empty-state">برای انتقال دانش‌آموزان و معلم‌ها ابتدا یک سال تحصیلی فعال تعریف کنید.</p>
            <?php endif; ?>
            <div class="hst-form__row hst-import-mode-row hst-form-grid">
                <label class="hst-field">
                    <span>نوع ورود</span>
                    <select id="hst-import-role" name="hst_import_role">
                        <option value="student">دانش‌آموزان</option>
                        <option value="teacher">معلم‌ها</option>
                    </select>
                </label>

                <label class="hst-field" data-hst-student-class-options>
                    <span>کلاس مقصد</span>
                    <select id="hst-import-class" name="hst_import_class">
                        <option value="auto">— تشخیص خودکار از متن سیدا —</option>
                        <option value="">— انتخاب دستی —</option>
                        <?php foreach ($hst_classes as $c) : ?>
                            <option value="<?php echo esc_attr($c->id); ?>">
                                <?php echo esc_html($c->class_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <input type="hidden" id="hst-import-term" name="hst_import_term" value="<?php echo esc_attr($hst_active_term); ?>">

            <label class="hst-field">
                <span>چسباندن اطلاعات</span>
                <textarea id="hst-import-paste" name="hst_import_paste" rows="10" dir="rtl"
                    placeholder="از صفحه دفتر آمار سیدا Ctrl+A و سپس Ctrl+C بزنید و متن را اینجا paste کنید..."></textarea>
            </label>

            <div class="hst-import-or"><span>یا</span></div>

            <div class="hst-import-uploadbox" data-hst-excel-panel>
                <div class="hst-import-uploadbox__top">
                    <div class="hst-import-uploadbox__title">
                        <b data-hst-excel-title>ورود از Excel</b>
                        <small data-hst-excel-desc>فایل نمونه را دانلود کنید، اطلاعات را اصلاح کنید و همان فایل را بارگذاری کنید.</small>
                    </div>
                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-import-sample" id="hst-import-sample">
                        دانلود نمونه Excel
                    </button>
                </div>

                <label class="hst-import-dropzone" for="hst-import-file" data-hst-dropzone>
                    <span class="hst-import-dropzone__text">
                        <b data-hst-upload-title>انتخاب یا رها کردن فایل Excel</b>
                        <small>فرمت‌های مجاز: XLSX, XLS, CSV</small>
                    </span>
                    <span class="hst-import-file-status hst-muted" id="hst-import-file-name">فایلی انتخاب نشده</span>
                </label>
                <input type="file" id="hst-import-file" name="hst_import_file" class="hst-import-file" hidden accept=".csv,.txt,.xlsx,.xls">
            </div>

            <label class="hst-field" data-hst-student-photo-options>
                <span>پیشوند عکس دانش‌آموزان (اختیاری)</span>
                <input type="text" id="hst-import-photo-prefix" name="hst_import_photo_prefix" dir="ltr"
                    placeholder="https://sida.medu.ir/ImageStudent/**/****/********/"
                    value="<?php echo esc_attr($hst_saved_photo_prefix); ?>">
            </label>

            <div class="hst-btn-group">
                <button type="button" class="hst-btn hst-btn--soft" id="hst-import-preview" <?php disabled(!$hst_active_term); ?> title="<?php echo esc_attr($hst_active_term ? 'بررسی و پیش‌نمایش' : 'ابتدا یک سال تحصیلی فعال تعریف کنید.'); ?>">
                    بررسی و پیش‌نمایش
                </button>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card" id="hst-import-preview-card" hidden>
        <div class="hst-card__header hst-section-card__header">
            <h3>پیش‌نمایش</h3>
        </div>

        <div class="hst-card__body hst-section-card__body">
            <div id="hst-import-preview-body" class="hst-vstack"></div>
            <div class="hst-btn-group">
                <button type="button" class="hst-btn hst-btn--primary" id="hst-import-confirm">
                    ثبت نهایی
                </button>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card" id="hst-import-result-card" hidden>
        <div class="hst-card__header hst-section-card__header">
            <h3>نتیجه ورود</h3>
        </div>

        <div class="hst-card__body hst-section-card__body">
            <div id="hst-import-result-body"></div>
        </div>
    </div>
</section>

<div class="hst-import-progress" id="hst-import-progress" hidden aria-hidden="true">
    <div class="hst-import-progress__panel" role="status" aria-live="polite" aria-label="وضعیت انتقال از سیدا">
        <div class="hst-import-progress__head">
            <div class="hst-import-progress__title">
                <b data-hst-import-progress-title>در حال انجام انتقال از سیدا</b>
                <span data-hst-import-progress-subtitle>لطفاً این صفحه را نبندید.</span>
            </div>
            <span class="hst-import-progress__percent" data-hst-import-progress-percent>0٪</span>
        </div>
        <div class="hst-import-progress__track" aria-hidden="true">
            <div class="hst-import-progress__bar" data-hst-import-progress-bar></div>
        </div>
        <div class="hst-import-progress__body">
            <div class="hst-import-progress__message">
                <span data-hst-import-progress-message>آماده‌سازی ردیف‌ها</span><span class="hst-import-progress__dots" aria-hidden="true"></span>
            </div>
            <div class="hst-import-progress__detail" data-hst-import-progress-detail>
                سیستم در حال شروع عملیات است.
            </div>
        </div>
    </div>
</div>
