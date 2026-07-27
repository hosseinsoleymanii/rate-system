#!/usr/bin/env python3
from pathlib import Path
import re
import sys

ROOT = Path(sys.argv[1]).resolve()


def read(rel):
    return (ROOT / rel).read_text(encoding="utf-8")


def write(rel, text):
    (ROOT / rel).write_text(text, encoding="utf-8")


def replace_once(text, old, new, label):
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f"{label}: expected one match, found {count}")
    return text.replace(old, new, 1)


# ---------------------------------------------------------------------------
# Report-card data provider and renderer.
# ---------------------------------------------------------------------------
rel = "includes/classes/users/hst-report-cards.php"
text = read(rel)

text = replace_once(
    text,
    "SELECT id, period_key, period_name, period_type, score_count, sort_order\n",
    "SELECT id, period_key, period_name, period_type, score_count, start_date, end_date, sort_order\n",
    "period list select",
)
text = replace_once(
    text,
    "AND period_type IN ('weekly', 'monthly', 'custom')",
    "AND period_type IN ('weekly', 'monthly', 'custom', 'first_shift')",
    "period list types",
)
text = replace_once(
    text,
    "            'custom'  => 'اختصاصی',\n",
    "            'custom'      => 'اختصاصی',\n            'first_shift' => 'نوبت اول',\n",
    "fallback type labels",
)
text = replace_once(
    text,
    "            'custom'  => 'اختصاصی',\n        ];\n\n        $period_type = sanitize_key($period_type);",
    "            'custom'      => 'اختصاصی',\n            'first_shift' => 'نوبت اول',\n        ];\n\n        $period_type = sanitize_key($period_type);",
    "type label method",
)

text = replace_once(
    text,
    "        $type = sanitize_key((string) ($period['type'] ?? 'weekly'));\n        if ($type !== 'custom') {",
    "        $type = sanitize_key((string) ($period['type'] ?? 'weekly'));\n        if ($type === 'first_shift') {\n            return [\n                ['key' => 'continuous_1', 'label' => 'نمره مستمر'],\n                ['key' => 'final_1', 'label' => 'نمره پایانی'],\n                ['key' => 'term_final_1', 'label' => 'نمره نهایی نوبت اول'],\n            ];\n        }\n        if ($type !== 'custom') {",
    "first term score columns",
)

old_definition = """                \"SELECT id, period_key, period_name, period_type, score_count
                 FROM {$wpdb->prefix}hst_score_periods
                 WHERE term_id = %d AND period_key = %s AND is_active = 1
                 LIMIT 1\",
"""
new_definition = """                \"SELECT id, period_key, period_name, period_type, score_count, start_date, end_date
                 FROM {$wpdb->prefix}hst_score_periods
                 WHERE term_id = %d AND period_key = %s AND is_active = 1
                 LIMIT 1\",
"""
text = replace_once(text, old_definition, new_definition, "period definition select")
text = replace_once(
    text,
    "        if (!in_array($period_type, ['weekly', 'monthly', 'custom'], true)) {\n            $definition = [];\n        } else {\n            $score_count = $period_type === 'custom'\n                ? max(1, min(20, absint($period->score_count ?? 1)))\n                : 1;\n            $score_keys = [];\n            for ($index = 1; $index <= $score_count; $index++) {\n                $score_keys[] = 'score_' . $index;\n            }\n",
    "        if (!in_array($period_type, ['weekly', 'monthly', 'custom', 'first_shift'], true)) {\n            $definition = [];\n        } else {\n            if ($period_type === 'first_shift') {\n                $score_count = 2;\n                $score_keys = ['continuous_1', 'final_1'];\n            } else {\n                $score_count = $period_type === 'custom'\n                    ? max(1, min(20, absint($period->score_count ?? 1)))\n                    : 1;\n                $score_keys = [];\n                for ($index = 1; $index <= $score_count; $index++) {\n                    $score_keys[] = 'score_' . $index;\n                }\n            }\n",
    "period definition keys",
)
text = replace_once(
    text,
    "                'score_keys'  => $score_keys,\n            ];",
    "                'score_keys'  => $score_keys,\n                'start_date'  => trim((string) ($period->start_date ?? '')),\n                'end_date'    => trim((string) ($period->end_date ?? '')),\n            ];",
    "period definition dates",
)

# Print endpoint settings and card options.
text = replace_once(
    text,
    "        $show_chart = HST_Guard::post_int('show_chart') === 1;\n        $duplex = !$show_chart && HST_Guard::post_int('duplex') === 1;",
    "        $show_chart = HST_Guard::post_int('show_chart') === 1;\n        $chart_type = sanitize_key(HST_Guard::post_text('chart_type', 'bar'));\n        if (!in_array($chart_type, ['bar', 'line', 'pie'], true)) {\n            $chart_type = 'bar';\n        }\n        $show_written_average = HST_Guard::post_int('show_written_average') === 1;\n        $duplex = !$show_chart && HST_Guard::post_int('duplex') === 1;",
    "print settings",
)
text = replace_once(
    text,
    "        $subject_limit = $duplex ? 0 : 12;\n        $cards = [];",
    "        $period_type = sanitize_key((string) ($scope['period']->period_type ?? 'weekly'));\n        $subject_limit = $duplex ? 0 : ($period_type === 'first_shift' ? 14 : 12);\n        $cards = [];",
    "print subject limit",
)
text = replace_once(
    text,
    "            if (!is_wp_error($card)) {\n                $cards[] = $card;\n            }",
    "            if (!is_wp_error($card)) {\n                $card['report_options'] = [\n                    'chart_type' => $chart_type,\n                    'show_written_average' => $show_written_average,\n                ];\n                $cards[] = $card;\n            }",
    "print card options",
)
text = replace_once(
    text,
    "        $period_type = sanitize_key((string) ($scope['period']->period_type ?? 'weekly'));\n        $period_type_label = $this->report_period_type_label($period_type);",
    "        $period_type_label = $this->report_period_type_label($period_type);",
    "remove duplicate print period type",
)
text = replace_once(
    text,
    "        if (!$period || !in_array($period_type, ['weekly', 'monthly', 'custom'], true)) {\n            return new WP_Error('hst_report_print_invalid_period', 'چاپ کارنامه فقط برای دوره‌های هفتگی، ماهانه و اختصاصی فعال است.');\n        }",
    "        if (!$period || !in_array($period_type, ['weekly', 'monthly', 'custom', 'first_shift'], true)) {\n            return new WP_Error('hst_report_print_invalid_period', 'چاپ کارنامه برای دوره‌های هفتگی، ماهانه، اختصاصی و نوبت اول فعال است.');\n        }",
    "print period support",
)

# Preview endpoint settings and options.
text = replace_once(
    text,
    "        $show_chart = HST_Guard::post_int('show_chart') === 1;\n        $duplex = !$show_chart && HST_Guard::post_int('duplex') === 1;\n        $preview_count = $duplex ? 2 : 1;",
    "        $show_chart = HST_Guard::post_int('show_chart') === 1;\n        $chart_type = sanitize_key(HST_Guard::post_text('chart_type', 'bar'));\n        if (!in_array($chart_type, ['bar', 'line', 'pie'], true)) {\n            $chart_type = 'bar';\n        }\n        $show_written_average = HST_Guard::post_int('show_written_average') === 1;\n        $duplex = !$show_chart && HST_Guard::post_int('duplex') === 1;\n        $preview_count = $duplex ? 2 : 1;",
    "preview settings",
)
text = replace_once(
    text,
    "            $previews[] = $preview;\n            $student_id = absint($preview['student']['id'] ?? 0);",
    "            $preview['report_options'] = [\n                'chart_type' => $chart_type,\n                'show_written_average' => $show_written_average,\n            ];\n            $previews[] = $preview;\n            $student_id = absint($preview['student']['id'] ?? 0);",
    "preview options",
)
text = replace_once(
    text,
    "        if (!$period || !in_array($period_type, ['weekly', 'monthly', 'custom'], true)) {\n            return new WP_Error('hst_report_preview_invalid_period', 'پیش‌نمایش کارنامه فقط برای دوره‌های هفتگی، ماهانه و اختصاصی فعال است.');\n        }",
    "        if (!$period || !in_array($period_type, ['weekly', 'monthly', 'custom', 'first_shift'], true)) {\n            return new WP_Error('hst_report_preview_invalid_period', 'پیش‌نمایش کارنامه برای دوره‌های هفتگی، ماهانه، اختصاصی و نوبت اول فعال است.');\n        }\n        if ($period_type === 'first_shift') {\n            $subject_limit = max($subject_limit, 14);\n        }",
    "preview period support",
)

old_preview_scores = """        $preview_score_count = sanitize_key((string) ($period->period_type ?? 'weekly')) === 'custom'
            ? max(1, min(20, absint($period->score_count ?? 1)))
            : 1;
        $subjects = [];
        $preview_component_totals = [];
        $preview_component_counts = [];
        for ($component_index = 1; $component_index <= $preview_score_count; $component_index++) {
            $component_key = 'score_' . $component_index;
            $preview_component_totals[$component_key] = 0.0;
            $preview_component_counts[$component_key] = 0;
        }
        foreach ($lesson_rows as $lesson) {
            $components = [];
            $lesson_total = 0.0;
            for ($component_index = 1; $component_index <= $preview_score_count; $component_index++) {
                $component_key = 'score_' . $component_index;
                $component_score = round($this->sample_score(), 2);
                $components[$component_key] = [
                    'determined' => true,
                    'score'      => $component_score,
                    'absence'    => '',
                    'included'   => true,
                ];
                $lesson_total += $component_score;
                $preview_component_totals[$component_key] += $component_score;
                $preview_component_counts[$component_key]++;
            }
            $score = round($lesson_total / $preview_score_count, 2);
            $average = max(0.0, min(20.0, $score - (wp_rand(3, 8) / 4)));
            $subjects[] = [
                'lesson_id'     => absint($lesson['lesson_id'] ?? 0),
                'title'         => (string) ($lesson['title'] ?? 'درس نمونه'),
                'score'         => $score,
                'class_average' => round($average, 2),
                'components'    => $components,
            ];
        }
"""
new_preview_scores = """        if ($period_type === 'first_shift') {
            $preview_score_keys = ['continuous_1', 'final_1'];
        } elseif ($period_type === 'custom') {
            $preview_score_count = max(1, min(20, absint($period->score_count ?? 1)));
            $preview_score_keys = [];
            for ($component_index = 1; $component_index <= $preview_score_count; $component_index++) {
                $preview_score_keys[] = 'score_' . $component_index;
            }
        } else {
            $preview_score_keys = ['score_1'];
        }
        $preview_score_count = count($preview_score_keys);
        $subjects = [];
        $preview_component_totals = array_fill_keys($preview_score_keys, 0.0);
        $preview_component_counts = array_fill_keys($preview_score_keys, 0);
        foreach ($lesson_rows as $lesson) {
            $components = [];
            $lesson_total = 0.0;
            foreach ($preview_score_keys as $component_key) {
                $component_score = round($this->sample_score(), 2);
                $components[$component_key] = [
                    'determined' => true,
                    'score'      => $component_score,
                    'absence'    => '',
                    'included'   => true,
                ];
                $lesson_total += $component_score;
                $preview_component_totals[$component_key] += $component_score;
                $preview_component_counts[$component_key]++;
            }
            $score = round($lesson_total / max(1, $preview_score_count), 2);
            $average = max(0.0, min(20.0, $score - (wp_rand(3, 8) / 4)));
            $subjects[] = [
                'lesson_id'     => absint($lesson['lesson_id'] ?? 0),
                'title'         => (string) ($lesson['title'] ?? 'درس نمونه'),
                'score'         => $score,
                'class_average' => round($average, 2),
                'components'    => $components,
            ];
        }
"""
text = replace_once(text, old_preview_scores, new_preview_scores, "preview score generation")
text = replace_once(
    text,
    "            'component_averages' => $preview_component_averages,\n            'tracking_code' => $tracking_code,",
    "            'component_averages' => $preview_component_averages,\n            'written_average' => $period_type === 'first_shift' ? ($preview_component_averages['final_1'] ?? null) : null,\n            'tracking_code' => $tracking_code,",
    "preview written average",
)

# Real card data includes the written average and section-aware QR.
text = replace_once(
    text,
    "            'report_card_section' => 'monthly',\n            'period_id'           => absint($period->id ?? 0),",
    "            'report_card_section' => sanitize_key((string) ($period->period_type ?? '')) === 'first_shift' ? 'first_shift' : 'monthly',\n            'period_id'           => absint($period->id ?? 0),",
    "print qr section",
)
text = replace_once(
    text,
    "            'component_averages' => (array) ($summary['component_averages'] ?? []),\n            'tracking_code' => $tracking_code,",
    "            'component_averages' => (array) ($summary['component_averages'] ?? []),\n            'written_average' => sanitize_key((string) ($period->period_type ?? '')) === 'first_shift'\n                ? (($summary['component_averages']['final_1'] ?? null))\n                : null,\n            'tracking_code' => $tracking_code,",
    "print written average",
)
text = replace_once(
    text,
    "            'report_card_section' => 'monthly',\n            'period_id'           => (int) $period->id,",
    "            'report_card_section' => $period_type === 'first_shift' ? 'first_shift' : 'monthly',\n            'period_id'           => (int) $period->id,",
    "preview qr section",
)

# Renderer: report options, first-term support, written average, synthetic final.
text = replace_once(
    text,
    "        $component_averages = (array) ($data['component_averages'] ?? []);\n        $score_columns = $this->report_score_columns($period);",
    "        $component_averages = (array) ($data['component_averages'] ?? []);\n        $report_options = (array) ($data['report_options'] ?? []);\n        $chart_type = sanitize_key((string) ($report_options['chart_type'] ?? 'bar'));\n        if (!in_array($chart_type, ['bar', 'line', 'pie'], true)) {\n            $chart_type = 'bar';\n        }\n        $written_average = isset($data['written_average']) && is_numeric($data['written_average'])\n            ? round((float) $data['written_average'], 2)\n            : (isset($component_averages['final_1']) && is_numeric($component_averages['final_1'])\n                ? round((float) $component_averages['final_1'], 2)\n                : null);\n        $score_columns = $this->report_score_columns($period);",
    "renderer options",
)
text = replace_once(
    text,
    "        $average_text = $average === null ? 'محاسبه نمی‌شود' : hst_format_grade($average);",
    "        $average_text = $average === null ? 'محاسبه نمی‌شود' : hst_format_grade($average);\n        $written_average_text = $written_average === null ? 'محاسبه نمی‌شود' : hst_format_grade($written_average);",
    "written average text",
)
text = replace_once(
    text,
    "        if (!in_array($period_type, ['weekly', 'monthly', 'custom'], true)) {\n            $period_type = 'weekly';\n        }",
    "        if (!in_array($period_type, ['weekly', 'monthly', 'custom', 'first_shift'], true)) {\n            $period_type = 'weekly';\n        }",
    "renderer first shift support",
)
text = replace_once(
    text,
    "        <article class=\"hst-report-preview-sheet<?php echo $compact ? ' is-compact' : ''; ?><?php echo $dense_subjects ? ' has-dense-subjects' : ''; ?><?php echo $has_multiple_score_columns ? ' has-multi-score-columns' : ''; ?><?php echo $has_many_score_columns ? ' has-many-score-columns' : ''; ?>\" data-hst-report-preview-sheet",
    "        <article class=\"hst-report-preview-sheet<?php echo $compact ? ' is-compact' : ''; ?><?php echo $dense_subjects ? ' has-dense-subjects' : ''; ?><?php echo $has_multiple_score_columns ? ' has-multi-score-columns' : ''; ?><?php echo $has_many_score_columns ? ' has-many-score-columns' : ''; ?><?php echo $period_type === 'first_shift' ? ' is-first-shift-report' : ''; ?>\" data-hst-report-preview-sheet",
    "first shift sheet class",
)
text = replace_once(
    text,
    "            <section class=\"hst-report-preview-ranks\" aria-label=\"رتبه‌های دانش‌آموز\">",
    "            <section class=\"hst-report-preview-ranks<?php echo $period_type === 'first_shift' ? ' is-first-shift-ranks' : ''; ?>\" aria-label=\"رتبه‌ها و معدل‌های دانش‌آموز\">",
    "rank class",
)
rank_anchor = """                <div class="hst-report-preview-rank hst-report-preview-rank--average">
                    <span class="hst-report-preview-rank__icon"><?php echo hst_icon('report'); ?></span>
                    <div><small>معدل</small><strong><?php echo esc_html($average_text); ?></strong></div>
                </div>
"""
rank_new = """                <?php if ($period_type === 'first_shift') : ?>
                    <div class="hst-report-preview-rank hst-report-preview-rank--written" data-hst-report-preview-written-average>
                        <span class="hst-report-preview-rank__icon"><?php echo hst_icon('scores'); ?></span>
                        <div><small>معدل کتبی</small><strong><?php echo esc_html($written_average_text); ?></strong></div>
                    </div>
                <?php endif; ?>
                <div class="hst-report-preview-rank hst-report-preview-rank--average">
                    <span class="hst-report-preview-rank__icon"><?php echo hst_icon('report'); ?></span>
                    <div><small>معدل</small><strong><?php echo esc_html($average_text); ?></strong></div>
                </div>
"""
text = replace_once(text, rank_anchor, rank_new, "written rank card")

text = replace_once(
    text,
    "                                        $score_key = sanitize_key((string) ($score_column['key'] ?? 'score_1'));\n                                        $component = (array) ($components[$score_key] ?? []);",
    "                                        $score_key = sanitize_key((string) ($score_column['key'] ?? 'score_1'));\n                                        if ($score_key === 'term_final_1') {\n                                            $component = [\n                                                'score' => $score,\n                                                'absence' => $absence,\n                                                'determined' => $score !== null || $absence !== '',\n                                                'included' => $score !== null,\n                                            ];\n                                        } else {\n                                            $component = (array) ($components[$score_key] ?? []);\n                                        }",
    "synthetic subject final",
)
text = replace_once(
    text,
    "                                        $score_key = sanitize_key((string) ($score_column['key'] ?? 'score_1'));\n                                        $component_average = $component_averages[$score_key] ?? null;",
    "                                        $score_key = sanitize_key((string) ($score_column['key'] ?? 'score_1'));\n                                        $component_average = $score_key === 'term_final_1'\n                                            ? $average\n                                            : ($component_averages[$score_key] ?? null);",
    "synthetic average final",
)

old_chart_branch = """                <?php if (($period['type'] ?? '') === 'custom') : ?>
                    <h3>نمودار روند ماهانه دروس</h3>
                    <div class="hst-report-preview-chart__canvas hst-report-preview-chart__canvas--custom">
                        <?php echo $this->render_custom_chart_svg($period, $subjects); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php echo $this->render_custom_chart_legend($subjects); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                <?php else : ?>
"""
new_chart_branch = """                <?php if (($period['type'] ?? '') === 'custom') : ?>
                    <h3>نمودار روند ماهانه دروس</h3>
                    <div class="hst-report-preview-chart__canvas hst-report-preview-chart__canvas--custom">
                        <?php echo $this->render_custom_chart_svg($period, $subjects); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php echo $this->render_custom_chart_legend($subjects); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                <?php elseif (($period['type'] ?? '') === 'first_shift') : ?>
                    <h3>نمودارهای تحلیلی پیشرفت دروس دانش‌آموز</h3>
                    <div class="hst-report-preview-chart__canvas hst-report-preview-chart__canvas--first-shift" data-hst-first-shift-chart-type="<?php echo esc_attr($chart_type); ?>">
                        <?php echo $this->render_first_shift_chart_html($subjects, $chart_type); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                <?php else : ?>
"""
text = replace_once(text, old_chart_branch, new_chart_branch, "first shift chart branch")

# First-term chart renderer, inserted before the existing palette method.
marker = """    /** @return string[] */
    private function report_chart_palette(): array
"""
first_shift_functions = r'''    private function first_shift_component_value(array $subject, string $score_key): ?float
    {
        $components = (array) ($subject['components'] ?? []);
        $component = (array) ($components[$score_key] ?? []);
        if (!empty($component['included']) && isset($component['score']) && is_numeric($component['score'])) {
            return max(0.0, min(20.0, round((float) $component['score'], 2)));
        }
        if (sanitize_key((string) ($component['absence'] ?? '')) === 'unexcused') {
            return 0.0;
        }
        return null;
    }

    private function first_shift_pie_sector(float $ratio): string
    {
        $ratio = max(0.0, min(1.0, $ratio));
        if ($ratio <= 0.0001) {
            return '';
        }
        if ($ratio >= 0.9999) {
            return '<circle class="hst-first-shift-chart__pie-final" cx="35" cy="36" r="22"/>';
        }

        $start = -M_PI / 2;
        $end = $start + (2 * M_PI * $ratio);
        $x1 = 35 + (22 * cos($start));
        $y1 = 36 + (22 * sin($start));
        $x2 = 35 + (22 * cos($end));
        $y2 = 36 + (22 * sin($end));
        $large = $ratio > 0.5 ? 1 : 0;
        $path = sprintf(
            'M35 36 L%.3f %.3f A22 22 0 %d 1 %.3f %.3f Z',
            $x1,
            $y1,
            $large,
            $x2,
            $y2
        );
        return '<path class="hst-first-shift-chart__pie-final" d="' . esc_attr($path) . '"/>';
    }

    private function render_first_shift_chart_html(array $subjects, string $chart_type): string
    {
        $chart_type = sanitize_key($chart_type);
        if (!in_array($chart_type, ['bar', 'line', 'pie'], true)) {
            $chart_type = 'bar';
        }

        $subjects = array_values(array_slice($subjects, 0, 14));
        if (empty($subjects)) {
            return '';
        }

        $cards = [];
        foreach ($subjects as $subject) {
            $title = trim((string) ($subject['title'] ?? 'درس'));
            $continuous = $this->first_shift_component_value((array) $subject, 'continuous_1');
            $final = $this->first_shift_component_value((array) $subject, 'final_1');
            $continuous_text = $continuous === null ? 'ثبت نشده' : hst_format_grade($continuous);
            $final_text = $final === null ? 'ثبت نشده' : hst_format_grade($final);
            $tooltip = 'مستمر: ' . $continuous_text . ' — پایانی: ' . $final_text;

            if ($chart_type === 'bar') {
                $continuous_height = $continuous === null ? 0 : round(($continuous / 20) * 48, 2);
                $final_height = $final === null ? 0 : round(($final / 20) * 48, 2);
                $graphic = '<svg viewBox="0 0 70 76" role="img" aria-label="' . esc_attr($tooltip) . '">'
                    . '<title>' . esc_html($tooltip) . '</title>'
                    . '<line class="hst-first-shift-chart__axis" x1="8" y1="66" x2="62" y2="66"/>'
                    . '<rect class="hst-first-shift-chart__bar hst-first-shift-chart__bar--continuous" x="18" y="' . esc_attr((string) (66 - $continuous_height)) . '" width="13" height="' . esc_attr((string) $continuous_height) . '" rx="3"/>'
                    . '<rect class="hst-first-shift-chart__bar hst-first-shift-chart__bar--final" x="39" y="' . esc_attr((string) (66 - $final_height)) . '" width="13" height="' . esc_attr((string) $final_height) . '" rx="3"/>'
                    . '</svg>';
            } elseif ($chart_type === 'line') {
                $continuous_y = $continuous === null ? 56 : round(60 - (($continuous / 20) * 42), 2);
                $final_y = $final === null ? 56 : round(60 - (($final / 20) * 42), 2);
                $graphic = '<svg viewBox="0 0 70 76" role="img" aria-label="' . esc_attr($tooltip) . '">'
                    . '<title>' . esc_html($tooltip) . '</title>'
                    . '<line class="hst-first-shift-chart__axis" x1="8" y1="66" x2="62" y2="66"/>'
                    . '<line class="hst-first-shift-chart__trend" x1="22" y1="' . esc_attr((string) $continuous_y) . '" x2="48" y2="' . esc_attr((string) $final_y) . '"/>'
                    . ($continuous === null ? '' : '<circle class="hst-first-shift-chart__point hst-first-shift-chart__point--continuous" cx="22" cy="' . esc_attr((string) $continuous_y) . '" r="4.5"/>')
                    . ($final === null ? '' : '<circle class="hst-first-shift-chart__point hst-first-shift-chart__point--final" cx="48" cy="' . esc_attr((string) $final_y) . '" r="4.5"/>')
                    . '</svg>';
            } else {
                $total = max(0.0, (float) ($continuous ?? 0)) + max(0.0, (float) ($final ?? 0));
                $final_ratio = $total > 0 ? max(0.0, (float) ($final ?? 0)) / $total : 0.5;
                $graphic = '<svg viewBox="0 0 70 76" role="img" aria-label="' . esc_attr($tooltip) . '">'
                    . '<title>' . esc_html($tooltip) . '</title>'
                    . '<circle class="hst-first-shift-chart__pie-continuous" cx="35" cy="36" r="22"/>'
                    . $this->first_shift_pie_sector($final_ratio)
                    . '</svg>';
            }

            $cards[] = '<div class="hst-first-shift-chart__card" title="' . esc_attr($tooltip) . '">'
                . '<span class="hst-first-shift-chart__subject">' . esc_html($title) . '</span>'
                . '<span class="hst-first-shift-chart__graphic">' . $graphic . '</span>'
                . '</div>';
        }

        return '<div class="hst-first-shift-chart hst-first-shift-chart--' . esc_attr($chart_type) . '" style="--hst-first-shift-subject-count:' . esc_attr((string) count($cards)) . '">'
            . '<div class="hst-first-shift-chart__legend" aria-label="راهنمای نمودار">'
            . '<span><i class="is-continuous"></i>نمره مستمر</span>'
            . '<span><i class="is-final"></i>نمره پایانی</span>'
            . '</div>'
            . '<div class="hst-first-shift-chart__grid">' . implode('', $cards) . '</div>'
            . '</div>';
    }

'''
text = replace_once(text, marker, first_shift_functions + marker, "insert first shift renderer")

write(rel, text)


# ---------------------------------------------------------------------------
# Report-card page shortcode: section routing and direct period links.
# ---------------------------------------------------------------------------
rel = "includes/classes/core/hst-shortcodes.php"
text = read(rel)
text = replace_once(
    text,
    "            if ($requested_section === 'monthly') {\n                $report_card_section = 'monthly';\n            }",
    "            if (in_array($requested_section, ['monthly', 'first_shift'], true)) {\n                $report_card_section = $requested_section;\n            }",
    "shortcode section routing",
)
text = replace_once(
    text,
    "                if ($available_key === $requested_period && in_array($available_type, ['weekly', 'monthly', 'custom'], true)) {\n                    $report_card_period = $available_key;\n                    $report_card_section = 'monthly';",
    "                if ($available_key === $requested_period && in_array($available_type, ['weekly', 'monthly', 'custom', 'first_shift'], true)) {\n                    $report_card_period = $available_key;\n                    $report_card_section = $available_type === 'first_shift' ? 'first_shift' : 'monthly';",
    "shortcode direct period routing",
)
write(rel, text)


# ---------------------------------------------------------------------------
# Report-card management template. Reuse the existing panel and toolbar.
# ---------------------------------------------------------------------------
rel = "templates/user/users/hst-report-cards.php"
text = read(rel)
text = replace_once(
    text,
    "$report_card_section = isset($report_card_section) && $report_card_section === 'monthly' ? 'monthly' : '';",
    "$report_card_section = isset($report_card_section) && in_array($report_card_section, ['monthly', 'first_shift'], true) ? $report_card_section : '';",
    "template section",
)
text = replace_once(
    text,
    "$monthly_url = add_query_arg('report_card_section', 'monthly', $report_cards_url);",
    "$monthly_url = add_query_arg('report_card_section', 'monthly', $report_cards_url);\n$first_shift_url = add_query_arg('report_card_section', 'first_shift', $report_cards_url);\n$regular_report_periods = array_values(array_filter($monthly_periods, static function ($period): bool {\n    return in_array(sanitize_key((string) ($period->period_type ?? '')), ['weekly', 'monthly', 'custom'], true);\n}));\n$first_shift_report_periods = array_values(array_filter($monthly_periods, static function ($period): bool {\n    return sanitize_key((string) ($period->period_type ?? '')) === 'first_shift';\n}));",
    "template period groups",
)
old_first_tile = """                <div class="hst-tile" aria-disabled="true" data-hst-disabled-silent="true">
                    <span class="hst-chip">نوبت اول</span>
                    <span class="hst-tile__icon"><?php echo hst_icon('report'); ?></span>
                    <span>کارنامه نوبت اول</span>
                </div>
"""
new_first_tile = """                <a
                    href="<?php echo esc_url($first_shift_url); ?>"
                    class="hst-tile"
                    data-hst-report-card-section="first_shift"
                    aria-controls="hst-report-card-first-shift-panel"
                    aria-expanded="<?php echo $report_card_section === 'first_shift' ? 'true' : 'false'; ?>"
                    <?php echo $report_card_section === 'first_shift' ? 'aria-current="page"' : ''; ?>
                >
                    <span class="hst-chip">نوبت اول</span>
                    <span class="hst-tile__icon"><?php echo hst_icon('report'); ?></span>
                    <span>کارنامه نوبت اول</span>
                </a>
"""
text = replace_once(text, old_first_tile, new_first_tile, "first term tile")
text = replace_once(text, "<?php foreach ($monthly_periods as $period_index => $period) :", "<?php foreach ($regular_report_periods as $period_index => $period) :", "regular period loop")
text = replace_once(
    text,
    "                            $period_type_label = trim((string) ($period->period_type_label ?? 'اختصاصی'));\n                            $field_prefix",
    "                            $period_type_label = trim((string) ($period->period_type_label ?? 'اختصاصی'));\n                            $is_first_shift = $period_type === 'first_shift';\n                            $field_prefix",
    "period first term flag",
)
text = replace_once(
    text,
    "                                        <span class=\"hst-status hst-status--success\" data-hst-comparison-status>نمودار مقایسه‌ای فعال</span>",
    "                                        <span class=\"hst-status hst-status--success\" data-hst-comparison-status><?php echo $is_first_shift ? 'نمودار تحلیلی فعال' : 'نمودار مقایسه‌ای فعال'; ?></span>\n                                        <?php if ($is_first_shift) : ?><span class=\"hst-status hst-status--muted\" data-hst-chart-type-status>نمودار میله‌ای</span><?php endif; ?>",
    "chart status",
)
manager_button_end = """                                            </button>
                                        </div>

                                        <div class="hst-config-toolbar__group hst-config-toolbar__group--threshold">
"""
manager_button_new = """                                            </button>
                                            <?php if ($is_first_shift) : ?>
                                                <button
                                                    type="button"
                                                    class="hst-btn hst-btn--ghost hst-btn--sm hst-config-toolbar__chart-btn"
                                                    data-hst-report-chart-open
                                                >
                                                    <?php echo hst_icon('scores'); ?>
                                                    <span data-hst-report-chart-button-text>افزودن نمودار</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>

                                        <div class="hst-config-toolbar__group hst-config-toolbar__group--threshold">
"""
text = replace_once(text, manager_button_end, manager_button_new, "chart button")
text = replace_once(
    text,
    "                                                 <span>نمایش نمودار مقایسه‌ای</span>",
    "                                                 <span><?php echo $is_first_shift ? 'نمایش نمودار تحلیلی' : 'نمایش نمودار مقایسه‌ای'; ?></span>",
    "chart toggle label",
)
chart_toggle_group_end = """                                        </div>

                                        <div class="hst-config-toolbar__group">
                                            <label class="hst-check">
                                                <span>نفرات برتر کلاس</span>
"""
chart_toggle_group_new = """                                        </div>

                                        <?php if ($is_first_shift) : ?>
                                            <div class="hst-config-toolbar__group">
                                                <label class="hst-check">
                                                    <span>نمایش معدل کتبی</span>
                                                    <input type="checkbox" name="<?php echo esc_attr($field_prefix); ?>_written_average" value="1" checked="checked" autocomplete="off" data-hst-report-default-on data-hst-written-average-toggle>
                                                </label>
                                            </div>
                                        <?php endif; ?>

                                        <div class="hst-config-toolbar__group">
                                            <label class="hst-check">
                                                <span>نفرات برتر کلاس</span>
"""
text = replace_once(text, chart_toggle_group_end, chart_toggle_group_new, "written average setting")
text = replace_once(
    text,
    "                                    <input type=\"hidden\" name=\"<?php echo esc_attr($field_prefix); ?>_manager_message\" value=\"<?php echo esc_attr($default_manager_message); ?>\" data-hst-manager-message-value>",
    "                                    <input type=\"hidden\" name=\"<?php echo esc_attr($field_prefix); ?>_manager_message\" value=\"<?php echo esc_attr($default_manager_message); ?>\" data-hst-manager-message-value>\n                                    <?php if ($is_first_shift) : ?><input type=\"hidden\" name=\"<?php echo esc_attr($field_prefix); ?>_chart_type\" value=\"bar\" data-hst-report-chart-value><?php endif; ?>",
    "chart type hidden value",
)

# Duplicate the shared, already-styled panel for first-term periods.
panel_start = text.index('    <div\n        id="hst-report-card-monthly-panel"')
panel_end = text.index('    <div\n        class="hst-modal hst-report-preview-modal"', panel_start)
monthly_block = text[panel_start:panel_end]
first_block = monthly_block
first_block = first_block.replace('id="hst-report-card-monthly-panel"', 'id="hst-report-card-first-shift-panel"', 1)
first_block = first_block.replace('data-hst-report-card-panel="monthly"', 'data-hst-report-card-panel="first_shift"', 1)
first_block = first_block.replace("$report_card_section === 'monthly'", "$report_card_section === 'first_shift'", 1)
first_block = first_block.replace('id="hst-report-card-period-search"', 'id="hst-report-card-first-shift-search"', 1)
first_block = first_block.replace('id="hst-report-card-period-type"', 'id="hst-report-card-first-shift-type"', 1)
first_block = first_block.replace("<?php foreach ($regular_report_periods as $period_index => $period) :", "<?php foreach ($first_shift_report_periods as $period_index => $period) :", 1)
first_block = first_block.replace('لیست دوره‌ها', 'دوره نوبت اول', 1)
first_block = first_block.replace('برای سال تحصیلی فعال هنوز دوره هفتگی، ماهانه یا اختصاصی تعریف نشده است.', 'برای سال تحصیلی فعال هنوز دوره نوبت اول تعریف نشده است.', 1)
first_block = first_block.replace('<option value="">همه</option>\n                             <option value="weekly">هفتگی</option>\n                             <option value="monthly">ماهانه</option>\n                             <option value="custom">اختصاصی</option>', '<option value="first_shift">نوبت اول</option>', 1)
text = text[:panel_end] + first_block + text[panel_end:]

# Chart picker modal, built with the shared modal/card/button classes.
chart_modal = r'''

    <div
        class="hst-modal hst-report-chart-modal"
        data-hst-modal-size="lg"
        id="hst-report-chart-modal"
        hidden
        role="dialog"
        aria-modal="true"
        aria-hidden="true"
        aria-labelledby="hst-report-chart-title"
        aria-describedby="hst-report-chart-description"
    >
        <div class="hst-modal__backdrop" data-hst-report-chart-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div>
                    <h3 id="hst-report-chart-title">افزودن نمودار به کارنامه نوبت اول</h3>
                    <p id="hst-report-chart-description">یکی از قالب‌های نمودار زیر را جهت درج در کارنامه نوبت اول انتخاب نمایید.</p>
                </div>
                <button type="button" class="hst-modal__close" data-hst-report-chart-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body">
                <div class="hst-report-chart-picker" role="radiogroup" aria-label="نوع نمودار کارنامه">
                    <button type="button" class="hst-card hst-report-chart-option is-selected" data-hst-report-chart-option="bar" role="radio" aria-checked="true">
                        <span class="hst-report-chart-option__icon is-bar"><?php echo hst_icon('scores'); ?></span>
                        <strong>نمودار میله‌ای</strong>
                        <small>جهت مقایسه نمرات تک تک دروس</small>
                    </button>
                    <button type="button" class="hst-card hst-report-chart-option" data-hst-report-chart-option="line" role="radio" aria-checked="false">
                        <span class="hst-report-chart-option__icon is-line"><?php echo hst_icon('report'); ?></span>
                        <strong>نمودار خطی</strong>
                        <small>جهت نمایش روند رشد و تغییرات</small>
                    </button>
                    <button type="button" class="hst-card hst-report-chart-option" data-hst-report-chart-option="pie" role="radio" aria-checked="false">
                        <span class="hst-report-chart-option__icon is-pie"><?php echo hst_icon('terms'); ?></span>
                        <strong>نمودار دایره‌ای</strong>
                        <small>جهت نمایش سهم و توزیع فراوانی</small>
                    </button>
                </div>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn" data-hst-report-chart-save>ثبت نمودار</button>
                <button type="button" class="hst-btn hst-btn--ghost" data-hst-report-chart-close>انصراف</button>
            </div>
        </div>
    </div>
'''
text = replace_once(text, "\n</section>", chart_modal + "\n</section>", "chart modal")
write(rel, text)


# ---------------------------------------------------------------------------
# Report-card JS: first-term section, picker, settings, AJAX payload.
# ---------------------------------------------------------------------------
rel = "assets/js/users/hst-report-cards.js"
text = read(rel)

text = replace_once(
    text,
    '    const supported = type === "weekly" || type === "monthly" || type === "custom";\n    const labelFromDom',
    '    const supported = type === "weekly" || type === "monthly" || type === "custom" || type === "first_shift";\n    const labelFromDom',
    "js supported period",
)
text = replace_once(
    text,
    '    const label = labelFromDom || (type === "monthly" ? "ماهانه" : (type === "custom" ? "اختصاصی" : "هفتگی"));',
    '    const label = labelFromDom || (type === "monthly" ? "ماهانه" : (type === "custom" ? "اختصاصی" : (type === "first_shift" ? "نوبت اول" : "هفتگی")));',
    "js period label",
)
text = text.replace("پیش‌نمایش کارنامه فقط برای دوره‌های هفتگی، ماهانه و اختصاصی فعال است.", "پیش‌نمایش کارنامه برای دوره‌های هفتگی، ماهانه، اختصاصی و نوبت اول فعال است.")
text = text.replace("چاپ کارنامه فقط برای دوره‌های هفتگی، ماهانه و اختصاصی فعال است.", "چاپ کارنامه برای دوره‌های هفتگی، ماهانه، اختصاصی و نوبت اول فعال است.")

# Insert chart picker state and handlers before duplex logic.
chart_js_marker = "  function syncDuplexAvailability($periodItem) {"
chart_js = r'''  const $reportChartModal = $("#hst-report-chart-modal");
  const chartTypeLabels = {
    bar: "نمودار میله‌ای",
    line: "نمودار خطی",
    pie: "نمودار دایره‌ای",
  };
  let $activeChartPeriodItem = $();
  let chartModalTrigger = null;
  let pendingChartType = "bar";

  function normalizeChartType(value) {
    value = String(value || "bar");
    return Object.prototype.hasOwnProperty.call(chartTypeLabels, value) ? value : "bar";
  }

  function syncReportChartChoice($periodItem, chartType) {
    if (!$periodItem || !$periodItem.length) return;
    chartType = normalizeChartType(
      chartType || $periodItem.find("[data-hst-report-chart-value]").first().val()
    );
    $periodItem.find("[data-hst-report-chart-value]").val(chartType);
    $periodItem.find("[data-hst-chart-type-status]").text(chartTypeLabels[chartType]);
    $periodItem.find("[data-hst-report-chart-button-text]").text("ویرایش نمودار");
  }

  function selectChartType(chartType) {
    pendingChartType = normalizeChartType(chartType);
    $reportChartModal.find("[data-hst-report-chart-option]").each(function () {
      const selected = String($(this).attr("data-hst-report-chart-option") || "") === pendingChartType;
      $(this).toggleClass("is-selected", selected).attr("aria-checked", selected ? "true" : "false");
    });
  }

  function openReportChartModal($periodItem, trigger) {
    if (!$reportChartModal.length || !$periodItem.length) return;
    $activeChartPeriodItem = $periodItem;
    chartModalTrigger = trigger || null;
    selectChartType($periodItem.find("[data-hst-report-chart-value]").first().val());
    $reportChartModal.prop("hidden", false).addClass("is-active").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");
    window.setTimeout(function () {
      $reportChartModal.find('[data-hst-report-chart-option="' + pendingChartType + '"]').trigger("focus");
    }, 60);
  }

  function closeReportChartModal() {
    if (!$reportChartModal.length) return;
    $reportChartModal.removeClass("is-active").attr("aria-hidden", "true").prop("hidden", true);
    if (!$(".hst-modal.is-active, .hst-modal.is-open, .hst-modal[data-open]").length) {
      $("body").removeClass("hst-modal-open");
    }
    if (chartModalTrigger) $(chartModalTrigger).trigger("focus");
    $activeChartPeriodItem = $();
    chartModalTrigger = null;
  }

  $page.on("click", "[data-hst-report-chart-open]", function () {
    openReportChartModal($(this).closest("[data-hst-report-period-item]"), this);
  });
  $reportChartModal.on("click", "[data-hst-report-chart-option]", function () {
    selectChartType($(this).attr("data-hst-report-chart-option"));
  });
  $reportChartModal.on("click", "[data-hst-report-chart-save]", function () {
    if ($activeChartPeriodItem.length) {
      syncReportChartChoice($activeChartPeriodItem, pendingChartType);
      $activeChartPeriodItem.find("[data-hst-comparison-toggle]").prop("checked", true).trigger("change");
    }
    closeReportChartModal();
  });
  $reportChartModal.on("click", "[data-hst-report-chart-close]", closeReportChartModal);
  $(document).on("keydown.hstReportChart", function (event) {
    if (!$reportChartModal.hasClass("is-active")) return;
    if (event.key === "Escape") {
      event.preventDefault();
      closeReportChartModal();
    }
  });

'''
text = replace_once(text, chart_js_marker, chart_js + chart_js_marker, "insert chart picker js")

text = replace_once(
    text,
    "    syncManagerMessageState(\n      $periodItem,\n      $periodItem.find(\"[data-hst-manager-message-value]\").val()\n    );\n    syncDuplexAvailability($periodItem);",
    "    syncManagerMessageState(\n      $periodItem,\n      $periodItem.find(\"[data-hst-manager-message-value]\").val()\n    );\n    if ($periodItem.find(\"[data-hst-report-chart-value]\").length) {\n      syncReportChartChoice($periodItem);\n    }\n    syncDuplexAvailability($periodItem);",
    "initialize chart state",
)

text = replace_once(
    text,
    "      showChart: $periodItem\n        .find('input[name$=\"_comparison_chart\"]')\n        .first()\n        .is(\":checked\"),",
    "      showChart: $periodItem\n        .find('input[name$=\"_comparison_chart\"]')\n        .first()\n        .is(\":checked\"),\n      chartType: normalizeChartType(\n        $periodItem.find(\"[data-hst-report-chart-value]\").first().val()\n      ),\n      showWrittenAverage: !$periodItem.find(\"[data-hst-written-average-toggle]\").length ||\n        $periodItem.find(\"[data-hst-written-average-toggle]\").first().is(\":checked\"),",
    "read first term settings",
)

text = replace_once(
    text,
    "    const showChart = settings.showChart !== false;\n    const managerMessage = managerMessageOrDefault(settings.managerMessage);",
    "    const showChart = settings.showChart !== false;\n    const showWrittenAverage = settings.showWrittenAverage !== false;\n    const managerMessage = managerMessageOrDefault(settings.managerMessage);",
    "apply written setting variable",
)
text = replace_once(
    text,
    "    $message.prop(\"hidden\", false);\n    $message.find(\"[data-hst-report-preview-manager-message-text]\").text(managerMessage);",
    "    $message.prop(\"hidden\", false);\n    $message.find(\"[data-hst-report-preview-manager-message-text]\").text(managerMessage);\n    $root.find(\"[data-hst-report-preview-written-average]\").prop(\"hidden\", !showWrittenAverage);\n    $root.find(\"[data-hst-report-preview-sheet]\").toggleClass(\"is-written-average-hidden\", !showWrittenAverage);",
    "apply written average visibility",
)

# Preview request sends the selected first-term options.
text = replace_once(
    text,
    "    const periodId = Number($periodItem.attr(\"data-period-id\") || 0);\n    const showChart = $periodItem",
    "    const periodId = Number($periodItem.attr(\"data-period-id\") || 0);\n    const previewSettings = readReportSettings($periodItem);\n    const showChart = $periodItem",
    "preview settings read",
)
text = replace_once(
    text,
    "        show_chart: showChart ? 1 : 0,\n      },",
    "        show_chart: showChart ? 1 : 0,\n        chart_type: previewSettings.chartType,\n        show_written_average: previewSettings.showWrittenAverage ? 1 : 0,\n      },",
    "preview settings payload",
)
text = replace_once(
    text,
    '      dedupe: "hst_get_report_card_preview_" + periodId + "_" + (duplex ? "2" : "1"),',
    '      dedupe: "hst_get_report_card_preview_" + periodId + "_" + (duplex ? "2" : "1") + "_" + previewSettings.chartType,',
    "preview request dedupe",
)

text = replace_once(
    text,
    "       show_chart: settings.showChart ? 1 : 0,\n       show_class_top: settings.showClassTop ? 1 : 0,",
    "       show_chart: settings.showChart ? 1 : 0,\n       chart_type: settings.chartType,\n       show_written_average: settings.showWrittenAverage ? 1 : 0,\n       show_class_top: settings.showClassTop ? 1 : 0,",
    "print settings payload",
)
write(rel, text)


# ---------------------------------------------------------------------------
# Styles: use existing modal/card/button shells and add only chart specifics.
# ---------------------------------------------------------------------------
rel = "assets/css/main.css"
text = read(rel)
css = r'''

/* First-term report-card controls and charts. */
.hst-config-toolbar__group--message {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.hst-report-chart-picker {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 18px;
}
.hst-report-chart-option {
  min-height: 190px;
  padding: 24px 18px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  border: 2px solid var(--hst-border, #dbe4ef);
  background: var(--hst-surface, #fff);
  color: var(--hst-text, #17233b);
  cursor: pointer;
  text-align: center;
  transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
}
.hst-report-chart-option:hover,
.hst-report-chart-option:focus-visible {
  transform: translateY(-2px);
  border-color: #93b4db;
  box-shadow: 0 10px 24px rgba(30, 64, 110, .09);
}
.hst-report-chart-option.is-selected {
  border-color: #274b78;
  box-shadow: 0 0 0 3px rgba(39, 75, 120, .12);
}
.hst-report-chart-option strong { font-size: 1.05rem; }
.hst-report-chart-option small { color: #64748b; line-height: 1.8; }
.hst-report-chart-option__icon {
  width: 62px;
  height: 62px;
  border-radius: 999px;
  display: inline-grid;
  place-items: center;
}
.hst-report-chart-option__icon .ico { width: 28px; height: 28px; }
.hst-report-chart-option__icon.is-bar { color: #1683c7; background: #e0f3ff; }
.hst-report-chart-option__icon.is-line { color: #0ca875; background: #e4faf2; }
.hst-report-chart-option__icon.is-pie { color: #df8a00; background: #fff4d7; }

.hst-report-preview-ranks.is-first-shift-ranks {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}
.hst-report-preview-rank--written .hst-report-preview-rank__icon {
  color: #7c3aed;
  background: #f0e7ff;
}
.hst-report-preview-sheet.is-written-average-hidden .hst-report-preview-ranks.is-first-shift-ranks {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}
.hst-report-preview-sheet.is-first-shift-report .hst-report-preview-table th,
.hst-report-preview-sheet.is-first-shift-report .hst-report-preview-table td {
  padding-block: 6px;
}
.hst-report-preview-sheet.is-first-shift-report .hst-report-preview-chart {
  margin-top: 12px;
}
.hst-report-preview-chart__canvas--first-shift {
  padding: 8px 0 0;
  background: #fff;
}
.hst-first-shift-chart {
  width: 100%;
  direction: rtl;
}
.hst-first-shift-chart__legend {
  display: flex;
  align-items: center;
  gap: 14px;
  margin: 0 0 8px;
  font-size: 11px;
  color: #42526a;
}
.hst-first-shift-chart__legend span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.hst-first-shift-chart__legend i {
  width: 8px;
  height: 8px;
  border-radius: 2px;
  display: inline-block;
}
.hst-first-shift-chart__legend i.is-continuous { background: #94a3b8; }
.hst-first-shift-chart__legend i.is-final { background: #3b82f6; }
.hst-first-shift-chart__grid {
  display: grid;
  grid-template-columns: repeat(var(--hst-first-shift-subject-count), minmax(0, 1fr));
  gap: 4px;
  align-items: stretch;
}
.hst-first-shift-chart__card {
  min-width: 0;
  min-height: 100px;
  border: 1px solid #cbd8e8;
  border-radius: 7px;
  background: #fff;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  align-items: stretch;
}
.hst-first-shift-chart__subject {
  min-height: 28px;
  padding: 5px 3px;
  display: grid;
  place-items: center;
  border-bottom: 1px solid #e6edf5;
  font-size: 8.5px;
  line-height: 1.35;
  text-align: center;
  color: #1e3a66;
  overflow-wrap: anywhere;
}
.hst-first-shift-chart__graphic {
  flex: 1;
  min-height: 70px;
  display: grid;
  place-items: center;
}
.hst-first-shift-chart__graphic svg {
  width: 100%;
  height: 72px;
  overflow: visible;
}
.hst-first-shift-chart__axis {
  stroke: #d7e0ec;
  stroke-width: 1;
}
.hst-first-shift-chart__bar--continuous,
.hst-first-shift-chart__point--continuous,
.hst-first-shift-chart__pie-continuous { fill: #94a3b8; }
.hst-first-shift-chart__bar--final,
.hst-first-shift-chart__point--final,
.hst-first-shift-chart__pie-final { fill: #3b82f6; }
.hst-first-shift-chart__trend {
  stroke: #3b82f6;
  stroke-width: 2.5;
  stroke-linecap: round;
}
.hst-report-preview-sheet.is-compact .hst-first-shift-chart__card { min-height: 74px; }
.hst-report-preview-sheet.is-compact .hst-first-shift-chart__subject { min-height: 21px; font-size: 6.5px; padding: 2px; }
.hst-report-preview-sheet.is-compact .hst-first-shift-chart__graphic { min-height: 48px; }
.hst-report-preview-sheet.is-compact .hst-first-shift-chart__graphic svg { height: 48px; }
.hst-report-preview-sheet.is-compact .hst-first-shift-chart__legend { font-size: 8px; margin-bottom: 4px; }

@media (max-width: 760px) {
  .hst-report-chart-picker { grid-template-columns: 1fr; }
  .hst-report-chart-option { min-height: 145px; }
  .hst-report-preview-ranks.is-first-shift-ranks { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
'''
if "/* First-term report-card controls and charts. */" in text:
    raise RuntimeError("first-term CSS already exists")
text += css
write(rel, text)


# Version bump.
rel = "teachershow.php"
text = read(rel)
text = replace_once(text, " * Version: 1.0.247", " * Version: 1.0.248", "plugin header version")
text = replace_once(text, "define('HST_VERSION', '1.0.246');", "define('HST_VERSION', '1.0.248');", "asset version")
write(rel, text)

print("TeacherShow first-term report card patch applied successfully.")
