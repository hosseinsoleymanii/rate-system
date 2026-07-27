<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';

$active_term = $attendance_context['active_term'] ?? null;
$classes = $attendance_context['classes'] ?? [];
$statuses = $attendance_context['statuses'] ?? [];
$shifts = $attendance_context['shifts'] ?? [];
$today = class_exists('HST_Date') ? HST_Date::today('Y/m/d') : current_time('Y-m-d');
?>
<section class="hst-page" data-hst-attendance>
    <div class="hst-card">
        <div class="hst-card__header"><h3>حضور و غیاب دانش‌آموزان</h3></div>
        <div class="hst-card__body">
            <?php if (!$active_term) : ?>
                <p class="hst-alert">برای ثبت حضور و غیاب، ابتدا باید یک سال تحصیلی فعال توسط مدیر تعریف شود.</p>
            <?php elseif (empty($classes)) : ?>
                <p class="hst-alert">برای شما در سال تحصیلی فعال کلاس و درس مشترکی تعریف نشده است.</p>
            <?php else : ?>
                <div class="hst-stat-row">
                    <div class="hst-stat"><span>سال تحصیلی فعال</span><strong><?php echo esc_html($active_term->term_name); ?></strong></div>
                    <div class="hst-stat"><span>وضعیت</span><strong id="hst-attendance-count">ابتدا کلاس و درس را انتخاب کنید.</strong></div>
                </div>

                <div class="">
                    <label class="hst-field">
                        <span>کلاس</span>
                        <select id="hst-attendance-class">
                            <option value="">انتخاب کلاس</option>
                            <?php foreach ($classes as $class) : ?>
                                <option value="<?php echo esc_attr($class['id']); ?>"><?php echo esc_html($class['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="hst-field">
                        <span>درس</span>
                        <select id="hst-attendance-lesson" disabled>
                            <option value="">ابتدا کلاس را انتخاب کنید</option>
                        </select>
                    </label>

                    <label class="hst-field">
                        <span>تاریخ</span>
                        <input type="text" id="hst-attendance-date" class="hst-jalali-date" value="<?php echo esc_attr($today); ?>" placeholder="۱۴۰۳/۰۸/۱۵" inputmode="numeric">
                    </label>

                    <label class="hst-field">
                        <span>زنگ</span>
                        <select id="hst-attendance-shift">
                            <?php foreach ($shifts as $shift => $label) : ?>
                                <option value="<?php echo esc_attr($shift); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div class="hst-inline-filter__action hst-btn-group">
                        <button type="button" class="hst-btn hst-btn--soft" id="hst-attendance-load">نمایش دانش‌آموزان</button>
                        <button type="button" class="hst-btn" id="hst-attendance-save" disabled>ذخیره حضور و غیاب</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($active_term && !empty($classes)) : ?>
        <div class="hst-card">
            <div class="hst-card__header"><h3>لیست حضور و غیاب</h3></div>
            <div class="hst-card__body">
                <div class="">
                    <label class="hst-field hst-inline-filter__grow">
                        <span class="hst-visually-hidden">جست‌وجو</span>
                        <input type="search" class="hst-search" id="hst-attendance-search" placeholder="جست‌وجوی نام دانش‌آموز...">
                    </label>
                    <div class="hst-inline-filter__action hst-btn-group">
                        <button type="button" class="hst-btn hst-btn--soft" data-hst-bulk-status="present">همه حاضر</button>
                        <button type="button" class="hst-btn hst-btn--danger" data-hst-bulk-status="absent">همه غایب</button>
                    </div>
                </div>

                <div class="hst-table-wrap">
                    <table class="hst-table hst-attendance-table">
                        <thead>
                            <tr>
                                <th>ردیف</th>
                                <th>دانش‌آموز</th>
                                <th>وضعیت</th>
                                <th>دقیقه تأخیر</th>
                                <th>توضیح</th>
                            </tr>
                        </thead>
                        <tbody id="hst-attendance-rows">
                            <tr>
                                <td colspan="5" class="hst-table-empty">برای شروع، کلاس و درس را انتخاب کنید.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="hst-legend">
                    <?php foreach ($statuses as $key => $label) : ?>
                        <span class="hst-legend__dot hst-attendance-status-<?php echo esc_attr($key); ?>">
                            <?php echo esc_html($label); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
</div>

<script>
window.hstAttendanceData = <?php echo wp_json_encode([
    'classes' => $classes,
    'statuses' => $statuses,
], JSON_UNESCAPED_UNICODE); ?>;
</script>
