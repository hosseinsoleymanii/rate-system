<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-icons.php';

$active_term = class_exists('HST_Terms') ? HST_Terms::active() : null;
$active_term_id = $active_term ? (int) $active_term->id : 0;
$active_term_name = $active_term ? (string) $active_term->term_name : '';
$has_saved_schedules = $active_term_id && class_exists('HST_Schedules')
    ? HST_Schedules::term_has_saved_schedule($active_term_id)
    : false;
$generation_status = class_exists('HST_Schedules')
    ? HST_Schedules::generation_status($active_term_id)
    : [
        'can_generate' => false,
        'message' => 'امکان بررسی پیش‌نیازهای تولید برنامه وجود ندارد.',
    ];
$can_generate_schedule = !empty($generation_status['can_generate']);
$generate_schedule_title = (string) ($generation_status['message'] ?? 'تولید برنامه');
$school_schedule_title = $has_saved_schedules
    ? 'دریافت برنامه هفتگی مدرسه'
    : 'هنوز برنامه هفتگی ذخیره‌شده‌ای وجود ندارد.';
$shift_times = [
    1 => ['08:00', '09:30'],
    2 => ['09:45', '11:15'],
    3 => ['11:30', '13:00'],
    4 => ['13:15', '14:45'],
];
?>
<section class="hst-page hst-schedule-page hst-schedule-page--v2 hst-management-page hst-module hst-module--schedules" data-hst-schedule data-active-term-id="<?php echo esc_attr($active_term_id); ?>" data-can-generate="<?php echo $can_generate_schedule ? '1' : '0'; ?>" data-generation-message="<?php echo esc_attr($generate_schedule_title); ?>">
    <div class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html(HST_Settings::management_page_title('schedules', 'برنامه هفتگی')); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-inline-filter hst-schedule-topbar">
                <div class="hst-schedule-action-group">
                    <button type="button" class="hst-btn hst-btn--primary hst-btn--sm" id="hst-generate-all-schedules" <?php disabled(!$can_generate_schedule); ?> title="<?php echo esc_attr($generate_schedule_title); ?>" aria-label="<?php echo esc_attr($generate_schedule_title); ?>">
                        <?php echo hst_icon('schedule'); ?>
                        <span>تولید برنامه</span>
                    </button>
                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-schedule-pdf data-type="all_classes" <?php disabled(!$has_saved_schedules); ?> title="<?php echo esc_attr($school_schedule_title); ?>" aria-label="<?php echo esc_attr($school_schedule_title); ?>">
                        <?php echo hst_icon('download'); ?>
                        <span>برنامه هفتگی مدرسه</span>
                    </button>
                </div>

                <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?></button>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card hst-schedule-settings-card">
        <div class="hst-card__header hst-card__header--row hst-section-card__header">
            <div>
                <h3>تنظیمات تولید برنامه</h3>
                <p class="hst-muted">تنظیمات سریع تولید سراسری برنامه.</p>
            </div>
        </div>
        <div class="hst-card__body hst-section-card__body">
            <?php if (!$active_term_id) : ?>
                <div class="hst-alert hst-alert--warning hst-empty-state">برای استفاده از برنامه هفتگی، ابتدا یک سال تحصیلی فعال تعریف کنید.</div>
            <?php endif; ?>

            <div class="hst-schedule-options">
                <label class="hst-checkline hst-schedule-option-line">
                    <input type="checkbox" id="hst-ignore-teacher-shifts" class="hst-schedule-option-input" checked>
                    <span>فقط روزهای حضور معلم بررسی شود</span>
                </label>

                <label class="hst-checkline hst-schedule-option-line">
                    <input type="checkbox" id="hst-prefer-early-shifts" class="hst-schedule-option-input" checked>
                    <span>اولویت با زنگ‌های ۱ تا ۳ باشد</span>
                </label>

                <button type="button" class="hst-schedule-blocked-trigger" id="hst-schedule-blocked-trigger" aria-haspopup="dialog" aria-controls="hst-schedule-blocked-modal">
                    <strong>زنگ‌های بسته برای تولید برنامه</strong>
                    <span data-hst-schedule-blocked-summary>زنگ‌هایی که نباید در تولید برنامه استفاده شوند</span>
                </button>
            </div>
        </div>
    </div>

    <div class="hst-schedule-report-area" hidden>
        <div class="hst-schedule-results" id="hst-schedule-results" hidden></div>
        <div class="hst-warnings hst-vstack" id="hst-schedule-warnings" hidden></div>
    </div>

    <div class="hst-card hst-section-card" aria-labelledby="hst-schedule-teacher-browser-title">
        <div class="hst-card__header hst-card__header--row hst-section-card__header">
            <div>
                <h3 id="hst-schedule-teacher-browser-title">انتخاب دبیر مرجع</h3>
                <p class="hst-muted">برای تخصیص درس و برنامه حضور، یک دبیر را انتخاب کنید.</p>
            </div>
            <?php if ($active_term_id) : ?>
                <div class="hst-search-wrap hst-schedule-teacher-browser__search">
                    <span aria-hidden="true"><?php echo hst_icon('notification-view'); ?></span>
                    <input type="search" class="hst-search" id="hst-schedule-teacher-search" placeholder="جست‌وجوی سریع دبیر...">
                </div>
            <?php endif; ?>
        </div>
        <div class="hst-card__body hst-section-card__body">
            <?php if (!$active_term_id) : ?>
                <div class="hst-alert hst-alert--warning hst-empty-state">برای استفاده از برنامه هفتگی، ابتدا یک سال تحصیلی فعال تعریف کنید.</div>
            <?php else : ?>
                <div class="hst-schedule-teacher-browser">
                    <div class="hst-schedule-teacher-chips" id="hst-schedule-teacher-list">
                        <p class="hst-muted">در حال دریافت فهرست دبیران...</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="hst-card hst-section-card hst-schedule-assignment-card" data-hst-schedule-assignment-workspace hidden>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-schedule-assignment">
                <section class="hst-schedule-panel hst-schedule-picker--lessons" aria-labelledby="hst-schedule-lessons-title">
                    <div class="hst-schedule-picker__head">
                        <div>
                            <div class="hst-schedule-panel-title-row">
                                <h3 id="hst-schedule-lessons-title">تخصیص دروس آموزشی</h3>
                                <span class="hst-chip" data-hst-schedule-selected-count>۰ درس منتخب</span>
                            </div>
                            <p class="hst-muted">درس‌های مدنظر جهت تخصیص به دبیر منتخب را تیک بزنید.</p>
                        </div>
                        <div class="hst-search-wrap">
                            <span aria-hidden="true"><?php echo hst_icon('notification-view'); ?></span>
                            <input type="search" class="hst-search" id="hst-schedule-lesson-search" placeholder="جست‌وجوی درس یا پایه تحصیلی...">
                        </div>
                    </div>
                    <div class="hst-schedule-picker__list" id="hst-schedule-lesson-list">
                        <p class="hst-muted">ابتدا دبیر را انتخاب کنید.</p>
                    </div>
                </section>

                <section class="hst-schedule-panel hst-schedule-availability-panel" aria-labelledby="hst-schedule-availability-title">
                    <div class="hst-schedule-picker__head">
                        <div>
                            <div class="hst-schedule-panel-title-row">
                                <h3 id="hst-schedule-availability-title">برنامه حضور هفتگی</h3>
                                <span class="hst-chip" data-hst-schedule-selected-teacher>دبیری انتخاب نشده</span>
                            </div>
                            <p class="hst-muted">ساعات حضور دبیر در طول هفته را انتخاب کنید.</p>
                        </div>
                    </div>

                    <div class="hst-schedule-availability-grid" aria-label="برنامه حضور معلم">
                        <div class="hst-schedule-availability-grid__head" aria-hidden="true"></div>
                        <?php foreach ($shift_times as $shift_number => $time_range) : ?>
                            <div class="hst-schedule-availability-grid__head">
                                <strong>زنگ <?php echo esc_html($shift_number); ?></strong>
                                <small><?php echo esc_html($time_range[0] . ' - ' . $time_range[1]); ?></small>
                            </div>
                        <?php endforeach; ?>

                        <?php foreach ($days as $key => $label) : ?>
                            <div class="hst-schedule-availability-grid__day"><?php echo esc_html($label); ?></div>
                            <?php for ($i = 1; $i <= 4; $i++) : ?>
                                <?php $value = $key . '_' . $i; ?>
                                <label class="hst-schedule-availability-cell">
                                    <input type="checkbox" name="schedule_availability[]" value="<?php echo esc_attr($value); ?>">
                                    <span>
                                        <strong>زنگ <?php echo esc_html($i); ?></strong>
                                        <small><?php echo esc_html($shift_times[$i][0] . ' - ' . $shift_times[$i][1]); ?></small>
                                    </span>
                                </label>
                            <?php endfor; ?>
                        <?php endforeach; ?>
                    </div>
                </section>

            </div>
            <div class="hst-btn-group hst-schedule-assignment-actions">
                <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" id="hst-schedule-clear-teacher-form" <?php disabled(!$active_term_id); ?>>پاک‌سازی</button>
                <button type="button" class="hst-btn hst-btn--primary hst-btn--sm" id="hst-schedule-save-teacher-assignment" <?php disabled(!$active_term_id); ?>>ذخیره‌سازی</button>
            </div>
        </div>
    </div>
</section>

<div class="hst-modal" id="hst-schedule-blocked-modal" role="dialog" aria-modal="true" aria-labelledby="hst-schedule-blocked-modal-title" aria-hidden="true">
    <div class="hst-modal__backdrop" data-hst-schedule-blocked-close></div>
    <div class="hst-modal__panel">
        <div class="hst-modal__header">
            <div>
                <h3 id="hst-schedule-blocked-modal-title">زنگ‌های بسته برای تولید برنامه</h3>
                <p>زنگ‌هایی که نباید در تولید برنامه استفاده شوند را انتخاب کنید.</p>
            </div>
            <button type="button" class="hst-modal__close" data-hst-schedule-blocked-close aria-label="بستن">×</button>
        </div>
        <div class="hst-modal__body">
            <?php if (!$active_term_id) : ?>
                <div class="hst-alert hst-alert--warning hst-empty-state">برای ذخیره زنگ‌های بسته، ابتدا یک سال تحصیلی فعال تعریف کنید.</div>
            <?php endif; ?>
            <div class="hst-schedule-blocked-grid" aria-label="زنگ‌های بسته برای تولید برنامه">
                <div class="hst-schedule-blocked-grid__head" aria-hidden="true"></div>
                <?php foreach ($shift_times as $shift_number => $time_range) : ?>
                    <div class="hst-schedule-blocked-grid__head">
                        <strong>زنگ <?php echo esc_html($shift_number); ?></strong>
                        <small><?php echo esc_html($time_range[0] . ' - ' . $time_range[1]); ?></small>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($days as $day_key => $day_label) : ?>
                    <div class="hst-schedule-blocked-grid__day"><?php echo esc_html($day_label); ?></div>
                    <?php for ($shift_option = 1; $shift_option <= 4; $shift_option++) : ?>
                        <label class="hst-schedule-blocked-cell">
                            <input type="checkbox"
                                class="hst-schedule-option-input hst-schedule-blocked-shift"
                                value="<?php echo esc_attr($day_key . '_' . $shift_option); ?>"
                                <?php checked($day_key === 'wednesday' && $shift_option === 4); ?>>
                            <span>
                                <strong>زنگ <?php echo esc_html($shift_option); ?></strong>
                                <small><?php echo esc_html($shift_times[$shift_option][0] . ' - ' . $shift_times[$shift_option][1]); ?></small>
                            </span>
                        </label>
                    <?php endfor; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="hst-modal__footer">
            <button type="button" class="hst-btn hst-btn--primary" data-hst-schedule-blocked-confirm <?php disabled(!$active_term_id); ?>>ذخیره‌سازی</button>
        </div>
    </div>
</div>
</div>
