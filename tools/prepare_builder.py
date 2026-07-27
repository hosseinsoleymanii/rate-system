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
p.write_text(text, encoding='utf-8')
print('Builder anchors normalized.')
