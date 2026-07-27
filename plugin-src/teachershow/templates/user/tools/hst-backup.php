<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-icons.php';
?>
<section class="hst-page hst-management-page hst-module hst-module--backup" data-hst-backup>
    <div class="hst-inline-filter" data-hst-backup-filter>
<div class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html(HST_Settings::management_page_title('backup', 'پشتیبان‌گیری')); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-stack">
<div class="hst-inline-filter__add">
                    <button type="button" class="hst-btn hst-btn--primary hst-btn--sm" id="hst-backup-create" title="ساخت پشتیبان دستی" aria-label="ساخت پشتیبان دستی">
                        <span class="hst-btn__icon" aria-hidden="true"><?php echo hst_icon('backup'); ?></span>
                        <span>پشتیبان دستی</span>
                    </button>
                </div>


                

                <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__body hst-section-card__body">
<div class="hst-inline-filter__main">
                    <select class="hst-inline-filter__select" id="hst-backup-week-filter" aria-label="فیلتر هفته">
                        <option value="">همه هفته‌ها</option>
                        <option value="1">هفته اول</option>
                        <option value="2">هفته دوم</option>
                        <option value="3">هفته سوم</option>
                        <option value="4">هفته چهارم</option>
                        <option value="5">هفته پنجم</option>
                    </select>
                    <select class="hst-inline-filter__select" id="hst-backup-day-filter" aria-label="فیلتر روز">
                        <option value="">همه روزها</option>
                        <?php for ($hst_day = 1; $hst_day <= 31; $hst_day++) : ?>
                            <option value="<?php echo esc_attr($hst_day); ?>">روز <?php echo esc_html(number_format_i18n($hst_day)); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
        </div>
    </div>
</div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__header hst-section-card__header"><h3>بازیابی از فایل پشتیبان</h3></div>
        <div class="hst-card__body hst-section-card__body">
            <label class="hst-file-drop" for="hst-backup-restore-file" data-hst-backup-drop>
                <span class="hst-file-drop__icon" aria-hidden="true"><?php echo hst_icon('import'); ?></span>
                <span class="hst-file-drop__text">
                    <strong>انتخاب یا رها کردن فایل پشتیبان</strong>
                    <small data-hst-backup-file-name>فقط فایل JSON پشتیبان TeacherShow پذیرفته می‌شود.</small>
                </span>
                <input type="file" id="hst-backup-restore-file" accept="application/json,.json">
            </label>
            <div class="hst-btn-group">
                <button type="button" class="hst-btn hst-btn--primary hst-btn--sm" id="hst-backup-restore" disabled>
                    <span class="hst-btn__icon" aria-hidden="true"><?php echo hst_icon('import'); ?></span>
                    اعمال پشتیبان
                </button>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__header hst-section-card__header"><h3>لیست پشتیبان‌ها</h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-table-wrap hst-data-table-wrap">
                <table class="hst-table hst-data-table" id="hst-backup-table" data-hst-no-pagination="1">
                    <thead>
                        <tr>
                            <th>ردیف</th>
                            <th>تاریخ</th>
                            <th>هفته</th>
                            <th>روز</th>
                            <th>نوع</th>
                            <th>حجم</th>
                            <th class="hst-col-fill">نام فایل</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody id="hst-backup-list">
                        <tr class="hst-table-empty-row" data-hst-no-pagination><td colspan="8" class="hst-table-empty"><?php echo hst_loading_state(); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
