<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';

$score_context = is_array($score_context ?? null) ? $score_context : [];
$active_term = $score_context['active_term'] ?? null;
$periods = $score_context['periods'] ?? ($score_context['months'] ?? []);
$classes = $score_context['classes'] ?? [];
$lessons = $score_context['lessons'] ?? [];
$rows = $score_context['rows'] ?? [];
$averages = $score_context['averages'] ?? ['total' => 0, 'avg' => null, 'best' => null, 'low' => null];
$filters = $score_context['filters'] ?? ['period_key' => '', 'month_key' => '', 'lesson_id' => 0, 'class_id' => 0];

if (!function_exists('hst_score_format_value')) {
function hst_score_format_value($value) {
    if ($value === null || $value === '') {
        return '—';
    }

    return hst_format_grade($value);
}
}
?>
<section class="hst-page">
    <div class="hst-card">
        <div class="hst-card__header"><h3>نمرات من</h3></div>
        <div class="hst-card__body">
            <?php if (!$active_term) : ?>
                <p class="hst-alert">در حال حاضر سال تحصیلی فعالی برای نمایش نمرات وجود ندارد.</p>
            <?php else : ?>
                <div class="hst-stat-row">
                    <div class="hst-stat">
                        <span>سال تحصیلی فعال</span>
                        <strong><?php echo esc_html($active_term->term_name); ?></strong>
                    </div>
                    <div class="hst-stat">
                        <span>تعداد نمرات</span>
                        <strong><?php echo esc_html((string) $averages['total']); ?></strong>
                    </div>
                    <div class="hst-stat">
                        <span>میانگین</span>
                        <strong><?php echo esc_html(hst_score_format_value($averages['avg'])); ?></strong>
                    </div>
                    <div class="hst-stat">
                        <span>بیشترین</span>
                        <strong><?php echo esc_html(hst_score_format_value($averages['best'])); ?></strong>
                    </div>
                    <div class="hst-stat">
                        <span>کمترین</span>
                        <strong><?php echo esc_html(hst_score_format_value($averages['low'])); ?></strong>
                    </div>
                </div>

                <form class="" method="get" data-hst-auto-submit-filter>
                    <label class="hst-field">
                        <span>کلاس</span>
                        <select name="score_class">
                            <option value="">همه کلاس‌ها</option>
                            <?php foreach ($classes as $class) : ?>
                                <option value="<?php echo esc_attr($class->id); ?>" <?php selected((int) $filters['class_id'], (int) $class->id); ?>>
                                    <?php echo esc_html($class->class_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="hst-field">
                        <span>درس</span>
                        <select name="score_lesson">
                            <option value="">همه درس‌ها</option>
                            <?php foreach ($lessons as $lesson) : ?>
                                <option value="<?php echo esc_attr($lesson->id); ?>" <?php selected((int) $filters['lesson_id'], (int) $lesson->id); ?>>
                                    <?php echo esc_html($lesson->lesson_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="hst-field">
                        <span>دوره</span>
                        <select name="score_period">
                            <option value="">همه دوره‌ها</option>
                            <?php foreach ($periods as $period) : ?>
                                <option value="<?php echo esc_attr($period->period_key); ?>" <?php selected(($filters['period_key'] ?? $filters['month_key'] ?? ''), $period->period_key); ?>>
                                    <?php echo esc_html($period->period_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
</form>

                <?php if (empty($rows)) : ?>
                    <p class="hst-alert">هنوز نمره‌ای برای این سال تحصیلی ثبت نشده است.</p>
                <?php else : ?>
                    <div class="hst-table-wrap">
                        <table class="hst-table hst-student-score-table">
                            <thead>
                                <tr>
                                    <th>ردیف</th>
                                    <th>دوره</th>
                                    <th>نوع نمره</th>
                                    <th>کلاس</th>
                                    <th>درس</th>
                                    <th>معلم</th>
                                    <th>نمره</th>
                                    <th>توضیح</th>
                                    <th>آخرین بروزرسانی</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $hst_row_i = 0; foreach ($rows as $row) : $hst_row_i++; ?>
                                    <tr>
                                        <td class="hst-row-num"><?php echo esc_html(number_format_i18n($hst_row_i)); ?></td>
                                        <td><?php echo esc_html($row->period_label ?: $row->month_key); ?></td>
                                        <td><?php echo esc_html($row->score_label ?? 'نمره'); ?></td>
                                        <td><?php echo esc_html($row->class_name); ?></td>
                                        <td><?php echo esc_html($row->lesson_name); ?></td>
                                        <td><?php echo esc_html($row->teacher_name); ?></td>
                                        <td>
                                            <?php if ((int) ($row->is_present ?? 1) === 0) : ?>
                                                <span class="hst-status hst-status--warning"><?php echo !empty($row->absence_excused) ? 'غایب موجه' : 'غایب غیرموجه'; ?></span>
                                            <?php else : ?>
                                                <strong><?php echo esc_html(hst_score_format_value($row->score)); ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $row->description ? esc_html($row->description) : '<span class="hst-muted">—</span>'; ?></td>
                                        <td>
                                            <?php
                                            $date = $row->updated_at ?: $row->created_at;
                                            echo esc_html($date ? (class_exists('HST_Date') ? HST_Date::format($date, 'Y/m/d H:i') : wp_date('Y/m/d H:i', strtotime($date))) : '—');
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
</div>
