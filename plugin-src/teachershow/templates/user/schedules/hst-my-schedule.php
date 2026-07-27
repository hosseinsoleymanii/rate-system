<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';

$week_type_labels = [
    'every' => 'هر هفته',
    'odd'   => 'هفته فرد',
    'even'  => 'هفته زوج',
];
?>
<section class="hst-page hst-schedule-page">
    <div class="hst-card">
        <div class="hst-card__header"><h3>برنامه هفتگی من</h3></div>
        <div class="hst-card__body">
            <?php if (!$active_term) : ?>
                <p class="hst-alert">در حال حاضر سال تحصیلی فعالی تعریف نشده است.</p>
            <?php elseif (!$role) : ?>
                <p class="hst-alert">این صفحه فقط برای معلمان و دانش‌آموزان قابل استفاده است.</p>
            <?php else : ?>
                <p class="hst-alert hst-alert--info">
                    برنامه‌ها بر اساس سال تحصیلی فعال نمایش داده می‌شوند:
                    <strong>سال تحصیلی: <?php echo esc_html($active_term->term_name); ?></strong>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($active_term && $role === 'teacher') : ?>
        <div class="hst-card">
            <div class="hst-card__header hst-card__header--row">
                <h3>برنامهٔ اختصاصی من</h3>
            </div>
            <div class="hst-card__body">
                <?php if (!$teacher_has_slots) : ?>
                    <p class="hst-alert">هنوز هیچ زنگی در سال تحصیلی فعال برای شما ثبت نشده است.</p>
                <?php else : ?>
                    <p class="hst-muted">روزها و زنگ‌هایی که شما در آن‌ها تدریس دارید، همراه با درس و کلاس مربوطه.</p>
                    <div class="hst-schedule-grid hst-schedule-grid--readonly">
                        <div class="hst-schedule-grid-head">زنگ / روز</div>
                        <?php foreach ($days as $day_key => $day_label) : ?>
                            <div class="hst-schedule-grid-head"><?php echo esc_html($day_label); ?></div>
                        <?php endforeach; ?>

                        <?php for ($shift = 1; $shift <= 4; $shift++) : ?>
                            <div class="hst-schedule-shift-label">زنگ <?php echo esc_html($shift); ?></div>
                            <?php foreach ($days as $day_key => $day_label) : ?>
                                <?php $slot_items = $teacher_grid[$shift][$day_key] ?? []; ?>
                                <div class="hst-schedule-slot hst-schedule-slot--readonly">
                                    <?php if (empty($slot_items)) : ?>
                                        <span class="hst-schedule-slot-empty">—</span>
                                    <?php else : ?>
                                        <?php foreach ($slot_items as $item) : ?>
                                            <div class="hst-schedule-entry hst-schedule-entry--<?php echo esc_attr($item->week_type); ?>">
                                                <strong><?php echo esc_html($item->lesson_name); ?></strong>
                                                <span><?php echo esc_html($item->class_name); ?></span>
                                                <small><?php echo esc_html($week_type_labels[$item->week_type] ?? $item->week_type); ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($active_term && $role && empty($class_schedules)) : ?>
        <div class="hst-card">
            <div class="hst-card__body">
                <p class="hst-alert">برای شما در سال تحصیلی فعال، کلاس یا برنامه ذخیره‌شده‌ای پیدا نشد.</p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($active_term && $role && !empty($class_schedules)) : ?>
        <?php foreach ($class_schedules as $schedule_item) : ?>
            <?php
            $class = $schedule_item['class'];
            $grid = $schedule_item['grid'];
            $rows = $schedule_item['rows'];
            ?>
            <div class="hst-card">
                <div class="hst-card__header hst-card__header--row">
                    <h3>برنامه کلاس <?php echo esc_html($class->class_name); ?></h3>
                    <?php if (!empty($rows)) : ?>
                        <button type="button" class="hst-btn hst-btn--soft hst-btn--sm"
                            data-hst-schedule-pdf
                            data-type="class"
                            data-class-id="<?php echo esc_attr($class->id); ?>"
                            data-term-id="<?php echo esc_attr($active_term->id); ?>">چاپ / دانلود PDF</button>
                    <?php endif; ?>
                </div>

                <div class="hst-card__body">
                    <?php if (empty($rows)) : ?>
                        <p class="hst-alert">هنوز برنامه‌ای برای این کلاس ذخیره نشده است.</p>
                    <?php else : ?>
                        <div class="hst-schedule-grid hst-schedule-grid--readonly">
                            <div class="hst-schedule-grid-head">زنگ / روز</div>

                            <?php foreach ($days as $day_key => $day_label) : ?>
                                <div class="hst-schedule-grid-head"><?php echo esc_html($day_label); ?></div>
                            <?php endforeach; ?>

                            <?php for ($shift = 1; $shift <= 4; $shift++) : ?>
                                <div class="hst-schedule-shift-label">زنگ <?php echo esc_html($shift); ?></div>

                                <?php foreach ($days as $day_key => $day_label) : ?>
                                    <?php $slot_items = $grid[$shift][$day_key] ?? []; ?>
                                    <div class="hst-schedule-slot hst-schedule-slot--readonly">
                                        <?php if (empty($slot_items)) : ?>
                                            <span class="hst-schedule-slot-empty">خالی</span>
                                        <?php else : ?>
                                            <?php foreach ($slot_items as $item) : ?>
                                                <div class="hst-schedule-entry hst-schedule-entry--<?php echo esc_attr($item->week_type); ?>">
                                                    <strong><?php echo esc_html($item->lesson_name); ?></strong>
                                                    <span><?php echo esc_html($item->teacher_name); ?></span>
                                                    <small><?php echo esc_html($week_type_labels[$item->week_type] ?? $item->week_type); ?></small>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
</div>
