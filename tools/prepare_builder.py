#!/usr/bin/env python3
from pathlib import Path
p = Path(__file__).with_name('build_first_term.py')
text = p.read_text(encoding='utf-8')

old = '''text = replace_once(
    text,
    "            'custom'  => 'اختصاصی',\\n",
    "            'custom'      => 'اختصاصی',\\n            'first_shift' => 'نوبت اول',\\n",
    "fallback type labels",
)
'''
new = '''text = replace_once(
    text,
    "            'weekly'  => 'هفتگی',\\n            'monthly' => 'ماهانه',\\n            'custom'  => 'اختصاصی',\\n        ];\\n\\n        foreach ($rows as $row) {",
    "            'weekly'      => 'هفتگی',\\n            'monthly'     => 'ماهانه',\\n            'custom'      => 'اختصاصی',\\n            'first_shift' => 'نوبت اول',\\n        ];\\n\\n        foreach ($rows as $row) {",
    "fallback type labels",
)
'''
if old not in text:
    raise SystemExit('builder fallback-label block was not found')
text = text.replace(old, new, 1)

old = '''text = replace_once(
    text,
    "        $show_chart = HST_Guard::post_int('show_chart') === 1;\\n        $duplex = !$show_chart && HST_Guard::post_int('duplex') === 1;",
    "        $show_chart = HST_Guard::post_int('show_chart') === 1;\\n        $chart_type = sanitize_key(HST_Guard::post_text('chart_type', 'bar'));\\n        if (!in_array($chart_type, ['bar', 'line', 'pie'], true)) {\\n            $chart_type = 'bar';\\n        }\\n        $show_written_average = HST_Guard::post_int('show_written_average') === 1;\\n        $duplex = !$show_chart && HST_Guard::post_int('duplex') === 1;",
    "print settings",
)
'''
new = '''text = replace_once(
    text,
    "        $mode = HST_Guard::post_text('mode', 'class');\\n        $show_chart = HST_Guard::post_int('show_chart') === 1;\\n        $duplex = !$show_chart && HST_Guard::post_int('duplex') === 1;",
    "        $mode = HST_Guard::post_text('mode', 'class');\\n        $show_chart = HST_Guard::post_int('show_chart') === 1;\\n        $chart_type = sanitize_key(HST_Guard::post_text('chart_type', 'bar'));\\n        if (!in_array($chart_type, ['bar', 'line', 'pie'], true)) {\\n            $chart_type = 'bar';\\n        }\\n        $show_written_average = HST_Guard::post_int('show_written_average') === 1;\\n        $duplex = !$show_chart && HST_Guard::post_int('duplex') === 1;",
    "print settings",
)
'''
if old not in text:
    raise SystemExit('builder print-settings block was not found')
text = text.replace(old, new, 1)

replacements = [
    ('"                                                 <span>نمایش نمودار مقایسه‌ای</span>"', '"                                                <span>نمایش نمودار مقایسه‌ای</span>"', 'chart-toggle label'),
    ('"       show_chart: settings.showChart ? 1 : 0,\\n       show_class_top: settings.showClassTop ? 1 : 0,"', '"      show_chart: settings.showChart ? 1 : 0,\\n      show_class_top: settings.showClassTop ? 1 : 0,"', 'print payload'),
]
for old, new, label in replacements:
    if old not in text:
        raise SystemExit(f'builder {label} anchor was not found')
    text = text.replace(old, new, 1)

p.write_text(text, encoding='utf-8')
print('Builder anchors normalized.')
