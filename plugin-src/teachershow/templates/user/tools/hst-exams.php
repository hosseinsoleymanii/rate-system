<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';

$exam_status_labels = [
    'scheduled' => 'برنامه‌ریزی‌شده',
    'done' => 'برگزار شده',
    'cancelled' => 'لغوشده',
];

$format_exam_date = static function ($date) {
    return class_exists('HST_Date') ? HST_Date::format($date, 'Y/m/d') : esc_html($date);
};
$format_exam_score = static function ($score) {
    return function_exists('hst_format_grade') ? hst_format_grade($score, true) : number_format_i18n((float) $score, 2);
};
$exam_builder_now = new DateTimeImmutable('now', wp_timezone());
$exam_builder_start_time = $exam_builder_now->format('H:i');
$exam_builder_end_time = $exam_builder_now->modify('+90 minutes')->format('H:i');

$builder_scope = $is_manager ? ($manager_scope ?? ['classes' => [], 'lessons' => []]) : $teacher_scope;
$teacher_lessons_by_class = [];
if (!empty($builder_scope['lessons'])) {
    foreach ($builder_scope['lessons'] as $lesson) {
        $teacher_lessons_by_class[(int) $lesson->class_id][] = $lesson;
    }
}

$exam_management_sections = [
    'management' => [
        'title' => 'مدیریت آزمون‌ها',
        'badge' => 'مدیریت',
        'icon'  => 'schedule',
    ],
    'question-bank' => [
        'title' => 'بانک سؤال',
        'badge' => 'بانک سؤال',
        'icon'  => 'backup',
    ],
    'builder' => [
        'title' => 'ایجاد آزمون',
        'badge' => 'ساخت',
        'icon'  => 'edit',
    ],
    'reports' => [
        'title' => 'گزارش عملکرد',
        'badge' => 'گزارشات',
        'icon'  => 'report',
    ],
    'settings' => [
        'title' => 'تنظیمات آزمون',
        'badge' => 'تنظیمات',
        'icon'  => 'settings',
    ],
];

$exam_builder_grades = [
    'tenth' => 'دهم',
    'eleventh' => 'یازدهم',
    'twelfth' => 'دوازدهم',
];
$exam_builder_majors = [
    'experimental' => 'علوم تجربی',
    'math' => 'ریاضی و فیزیک',
    'humanities' => 'ادبیات و علوم انسانی',
];
$exam_builder_classes = [];
foreach (($builder_scope['classes'] ?? []) as $class) {
    $profile = class_exists('HST_Exams')
        ? HST_Exams::class_academic_profile((string) ($class->class_name ?? ''))
        : ['grade' => '', 'major' => ''];
    if (!isset($exam_builder_grades[$profile['grade']], $exam_builder_majors[$profile['major']])) {
        continue;
    }
    $exam_builder_classes[] = [
        'id' => (int) $class->id,
        'name' => (string) $class->class_name,
        'grade' => $profile['grade'],
        'major' => $profile['major'],
    ];
}
$exam_builder_types = [
    'continuous' => 'مستمر',
    'midterm' => 'میان ترم',
    'final_first' => 'پایانی اول',
    'final_second' => 'پایانی دوم',
    'quiz' => 'کوئیز',
];
$exam_builder_type_labels = $exam_builder_types + [
    'final' => 'پایانی',
    'custom' => 'اختصاصی',
];
$exam_builder_delivery_modes = [
    'online' => 'غیر حضوری',
    'in_person' => 'حضوری',
];
$exam_builder_result_modes = [
    'after_submit' => 'بلافاصله پس از ثبت پاسخ',
    'after_end' => 'پس از پایان زمان آزمون',
];

$question_bank = wp_parse_args($question_bank_context ?? [], [
    'questions' => [],
    'lessons' => [],
    'exams' => [],
    'types' => [],
    'difficulties' => [],
    'stats' => ['total' => 0, 'easy_medium' => 0, 'advanced' => 0],
]);
$question_bank_questions = is_array($question_bank['questions']) ? $question_bank['questions'] : [];
$question_bank_lessons = is_array($question_bank['lessons']) ? $question_bank['lessons'] : [];
$question_bank_exams = is_array($question_bank['exams']) ? $question_bank['exams'] : [];
$question_bank_types = is_array($question_bank['types']) ? $question_bank['types'] : [];
$question_bank_difficulties = is_array($question_bank['difficulties']) ? $question_bank['difficulties'] : [];
$question_bank_curriculum = is_array($question_bank['curriculum'] ?? null) ? $question_bank['curriculum'] : ['source' => [], 'grades' => []];
$question_bank_blueprint = is_array($question_bank['blueprint'] ?? null) ? $question_bank['blueprint'] : [];
$question_bank_stats = wp_parse_args(is_array($question_bank['stats']) ? $question_bank['stats'] : [], [
    'total' => 0,
    'easy_medium' => 0,
    'advanced' => 0,
]);

$exam_section = '';
if ($is_manager && isset($_GET['exam_section'])) {
    $requested_exam_section = sanitize_key(wp_unslash($_GET['exam_section']));
    if (isset($exam_management_sections[$requested_exam_section])) {
        $exam_section = $requested_exam_section;
    }
}

$exam_page_url = home_url('/exams');
$exam_preview_user = wp_get_current_user();
$exam_preview_user_name = trim((string) ($exam_preview_user->display_name ?? '')) ?: 'کاربر پیش‌نمایش';
?>
<section class="hst-page hst-exams-page<?php echo $is_manager ? ' hst-management-page' : ''; ?>"<?php echo $is_manager ? ' data-hst-exams-manager data-hst-initial-section="' . esc_attr($exam_section) . '"' : ''; ?>>
    <?php if ($is_manager) : ?>
        <div class="hst-card hst-section-card hst-management-card" data-hst-exam-manager-shell>
            <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html(HST_Settings::management_page_title('exams', 'آزمون‌ها')); ?></h3></div>
            <div class="hst-card__body hst-section-card__body">
                <div class="hst-stack">
                    <button
                        type="button"
                        class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back"
                        data-hst-exams-back
                        data-hst-dashboard-url="<?php echo esc_url(home_url('/dashboard')); ?>"
                        title="بازگشت"
                        aria-label="بازگشت"
                    ><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
                </div>
            </div>
        </div>

        <div class="hst-card hst-section-card" data-hst-exam-manager-shell>
            <div class="hst-card__body hst-section-card__body">
                <nav class="hst-dashboard hst-dashboard--management" aria-label="بخش‌های مدیریت آزمون">
                    <?php foreach ($exam_management_sections as $section_key => $section_data) : ?>
                        <?php $section_url = add_query_arg('exam_section', $section_key, $exam_page_url); ?>
                        <a
                            href="<?php echo esc_url($section_url); ?>"
                            class="hst-tile"
                            data-hst-exam-section="<?php echo esc_attr($section_key); ?>"
                            <?php echo $exam_section === $section_key ? 'aria-current="page"' : ''; ?>
                        >
                            <span class="hst-chip"><?php echo esc_html($section_data['badge']); ?></span>
                            <span class="hst-tile__icon"><?php echo hst_icon($section_data['icon']); ?></span>
                            <span><?php echo esc_html($section_data['title']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </div>
    <?php else : ?>
        <div class="hst-card">
            <div class="hst-card__header"><h3><?php echo $is_student ? 'آزمون‌های من' : 'برنامه‌ریزی آزمون‌ها'; ?></h3></div>
            <div class="hst-card__body">
                <?php if (empty($active_term)) : ?>
                    <p class="hst-alert">سال تحصیلی فعالی برای آزمون‌ها پیدا نشد.</p>
                <?php elseif ($is_student) : ?>
                    <p class="hst-alert hst-alert--info">آزمون‌های غیرحضوری کلاس شما بر اساس زمان برگزاری در این صفحه نمایش داده می‌شوند.</p>
                <?php else : ?>
                    <p class="hst-alert hst-alert--info">سال تحصیلی فعال: <?php echo esc_html($active_term->term_name); ?>. ثبت آزمون فقط بر اساس برنامه هفتگی کلاس/درس امکان‌پذیر است.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($is_student && !empty($active_term)) : ?>
        <div data-hst-student-exams-list>
            <div class="hst-card hst-section-card">
                <div class="hst-card__header hst-section-card__header">
                    <div>
                        <h3>فهرست آزمون‌های غیرحضوری</h3>
                        <p>برای شروع یا ادامه آزمون، از دکمه همان آزمون استفاده کنید.</p>
                    </div>
                </div>
                <div class="hst-card__body hst-section-card__body">
                    <?php if (empty($student_exams)) : ?>
                        <p class="hst-alert hst-empty-state">آزمون غیرحضوری برای کلاس شما تعریف نشده است.</p>
                    <?php else : ?>
                        <div class="hst-assignment-list">
                            <?php foreach ($student_exams as $student_exam_item) :
                                $exam = $student_exam_item['exam'];
                                $result = $student_exam_item['result'];
                                $button_label = !empty($student_exam_item['active_attempt_id']) ? 'ادامه آزمون' : 'شروع آزمون';
                            ?>
                                <article class="hst-assignment-item" data-hst-student-exam-card data-exam-id="<?php echo esc_attr((int) $exam->id); ?>">
                                    <div class="hst-assignment-item__head">
                                        <div class="hst-btn-group">
                                            <span class="hst-status hst-status--info"><?php echo esc_html($exam->lesson_name); ?></span>
                                            <span class="hst-status hst-status--muted"><?php echo esc_html($exam->class_name); ?></span>
                                            <span class="hst-status hst-status--muted"><?php echo esc_html((int) $exam->actual_question_count); ?> سؤال</span>
                                        </div>
                                        <span class="hst-status hst-status--<?php echo esc_attr($student_exam_item['window'] === 'active' ? 'success' : ($student_exam_item['window'] === 'waiting' ? 'warning' : 'muted')); ?>">
                                            <?php echo esc_html($student_exam_item['window_label']); ?>
                                        </span>
                                    </div>
                                    <h3><?php echo esc_html($exam->title); ?></h3>
                                    <div class="hst-view-grid">
                                        <div class="hst-view-row">
                                            <span class="hst-view-row__label">زمان برگزاری</span>
                                            <span class="hst-view-row__value"><?php echo esc_html($format_exam_date($exam->start_date)); ?>، <?php echo esc_html(substr((string) $exam->start_time, 0, 5)); ?> تا <?php echo esc_html(substr((string) $exam->end_time, 0, 5)); ?></span>
                                        </div>
                                        <div class="hst-view-row">
                                            <span class="hst-view-row__label">دفعات شرکت</span>
                                            <span class="hst-view-row__value"><?php echo esc_html(number_format_i18n((int) $student_exam_item['attempt_count'])); ?> از <?php echo esc_html(number_format_i18n((int) $student_exam_item['attempt_limit'])); ?></span>
                                        </div>
                                        <?php if ($result) : ?>
                                            <div class="hst-view-row hst-view-row--wide">
                                                <span class="hst-view-row__label">نتیجه آخرین تلاش</span>
                                                <span class="hst-view-row__value">
                                                    <?php echo esc_html($format_exam_score($result['score'])); ?> از <?php echo esc_html($format_exam_score($result['max_score'])); ?>
                                                    <?php if (!empty($result['manual_pending'])) : ?>
                                                        — <?php echo esc_html(number_format_i18n((int) $result['manual_pending'])); ?> سؤال در انتظار تصحیح دبیر
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="hst-btn-group">
                                        <button
                                            type="button"
                                            class="hst-btn"
                                            data-hst-student-exam-start
                                            data-exam-id="<?php echo esc_attr((int) $exam->id); ?>"
                                            <?php disabled(empty($student_exam_item['can_start'])); ?>
                                        ><?php echo hst_icon('edit'); ?><span><?php echo esc_html($button_label); ?></span></button>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div data-hst-student-exam-runner hidden>
            <div class="hst-card hst-section-card hst-exam-online-preview">
                <div class="hst-card__header hst-section-card__header">
                    <div>
                        <h3>برگزاری آزمون غیرحضوری</h3>
                        <p>پاسخ‌ها هنگام جابه‌جایی بین سؤال‌ها به‌صورت خودکار ذخیره می‌شوند.</p>
                    </div>
                </div>
                <div class="hst-card__body hst-section-card__body">
                    <div class="hst-exam-online-preview__topbar">
                        <div class="hst-user-id hst-exam-online-preview__identity">
                            <?php echo hst_user_avatar($exam_preview_user, $exam_preview_user_name, 52, false); ?>
                            <span class="hst-user-id__meta">
                                <strong><?php echo esc_html($exam_preview_user_name); ?></strong>
                                <small><span class="hst-status hst-status--success">دانش‌آموز</span> <span data-hst-student-exam-class>—</span></small>
                            </span>
                        </div>
                        <div class="hst-exam-online-preview__heading">
                            <h3 data-hst-student-exam-title>—</h3>
                            <p><span>درس: <b data-hst-student-exam-lesson>—</b></span><i aria-hidden="true">•</i><span>تلاش: <b data-hst-student-exam-attempt>—</b></span></p>
                        </div>
                        <div class="hst-status hst-status--danger hst-exam-online-preview__timer" role="timer" aria-live="polite">
                            <?php echo hst_icon('month'); ?>
                            <span>زمان باقی‌مانده</span>
                            <b data-hst-student-exam-timer>۰۰:۰۰</b>
                        </div>
                    </div>

                    <div class="hst-exam-online-preview__layout">
                        <aside class="hst-exam-online-preview__sidebar">
                            <div class="hst-card hst-exam-online-preview__status-card">
                                <div class="hst-card__body">
                                    <h3>وضعیت پاسخ‌دهی</h3>
                                    <div class="hst-exam-online-preview__progress-copy"><span>پیشرفت آزمون:</span><b data-hst-student-exam-progress-text>۰ از ۰ سؤال</b></div>
                                    <span class="hst-progress" data-status="missing"><span class="hst-progress__bar" data-hst-student-exam-progress-bar style="width:0%"></span></span>
                                </div>
                            </div>
                            <div class="hst-card hst-exam-online-preview__list-card">
                                <div class="hst-card__body">
                                    <h3>لیست سؤالات</h3>
                                    <div class="hst-exam-online-preview__question-list" data-hst-student-exam-question-list></div>
                                </div>
                            </div>
                            <button type="button" class="hst-btn hst-btn--danger hst-btn--block" data-hst-student-exam-finish><?php echo hst_icon('scores'); ?><span>ثبت نهایی پاسخ‌ها</span></button>
                        </aside>

                        <div class="hst-card hst-exam-online-preview__question-card">
                            <div class="hst-card__body">
                                <div class="hst-exam-online-preview__question-meta">
                                    <div class="hst-btn-group">
                                        <span class="hst-status hst-status--info" data-hst-student-exam-number>سؤال ۱ از ۱</span>
                                        <span class="hst-status hst-status--muted" data-hst-student-exam-type>—</span>
                                        <span class="hst-status hst-status--muted" data-hst-student-exam-difficulty>—</span>
                                    </div>
                                    <span class="hst-status hst-status--info" data-hst-student-exam-score>بارم: ۰ نمره</span>
                                </div>
                                <div class="hst-exam-online-preview__question-text" dir="auto" data-hst-student-exam-question-text></div>
                                <div class="hst-exam-online-preview__answer" data-hst-student-exam-answer></div>
                                <div class="hst-btn-group hst-exam-online-preview__navigation">
                                    <button type="button" class="hst-btn hst-btn--soft" data-hst-student-exam-prev><?php echo hst_icon('back'); ?><span>سؤال قبلی</span></button>
                                    <button type="button" class="hst-btn" data-hst-student-exam-next><span>سؤال بعدی</span><?php echo hst_icon('back'); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="hst-modal" id="hst-student-exam-finish-modal" data-hst-modal-size="md" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-student-exam-finish-title">
            <div class="hst-modal__backdrop" data-hst-student-exam-finish-close></div>
            <div class="hst-modal__panel">
                <div class="hst-modal__header">
                    <div class="hst-modal__title">
                        <h3 id="hst-student-exam-finish-title">ثبت نهایی آزمون</h3>
                        <p>پس از ثبت نهایی، پاسخ‌های این تلاش قابل ویرایش نیستند.</p>
                    </div>
                    <button type="button" class="hst-modal__close" data-hst-student-exam-finish-close aria-label="بستن">&times;</button>
                </div>
                <div class="hst-modal__body">
                    <p class="hst-alert hst-alert--info" data-hst-student-exam-finish-summary>تعداد کل سؤالات: ۰ | پاسخ داده شده: ۰ | بدون پاسخ: ۰</p>
                </div>
                <div class="hst-modal__footer">
                    <button type="button" class="hst-btn hst-btn--soft" data-hst-student-exam-finish-close>ادامه پاسخ‌گویی</button>
                    <button type="button" class="hst-btn" data-hst-student-exam-finish-confirm>ثبت نهایی</button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($is_teacher && !empty($active_term)) : ?>
        <div class="hst-grid hst-grid--1 hst-exam-form-layout">
            <div class="hst-card">
                <div class="hst-card__header"><h3>تعریف آزمون جدید</h3></div>
                <div class="hst-card__body">
                    <form class="hst-form" id="hst-exam-form">
                        <input type="hidden" name="id" value="">
                        <label class="hst-field">
                            <span>عنوان آزمون</span>
                            <input type="text" name="title" placeholder="عنوان آزمون؛ مثل آزمون فصل اول" required>
                        </label>

                        <label class="hst-field">
                            <span>کلاس</span>
                            <select name="class_id" id="hst-exam-class" required>
                                <option value="">انتخاب کلاس</option>
                                <?php foreach ($teacher_scope['classes'] as $class) : ?>
                                    <option value="<?php echo esc_attr($class->id); ?>"><?php echo esc_html($class->class_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="hst-field">
                            <span>درس</span>
                            <select name="lesson_id" id="hst-exam-lesson" required disabled>
                                <option value="">ابتدا کلاس را انتخاب کنید</option>
                            </select>
                        </label>

                        <div class="hst-form__row">
                            <label class="hst-field">
                                <span>تاریخ آزمون</span>
                                <input type="text" name="exam_date" id="hst-exam-date" class="hst-jalali-date" data-hst-min-date="today" placeholder="تاریخ آزمون" required>
                            </label>
                            <label class="hst-field">
                                <span>زنگ</span>
                                <select name="school_shift" id="hst-exam-shift" required disabled>
                                    <option value="">ابتدا تاریخ معتبر انتخاب کنید</option>
                                </select>
                            </label>
                        </div>

                        <div class="hst-form__row">
                            <label class="hst-field">
                                <span>مدت آزمون</span>
                                <input type="number" name="duration_minutes" min="15" max="240" step="5" value="45" placeholder="مدت آزمون به دقیقه">
                            </label>
                            <label class="hst-field">
                                <span>محل برگزاری</span>
                                <input type="text" name="location" placeholder="محل برگزاری؛ اختیاری">
                            </label>
                        </div>

                        <label class="hst-field">
                            <span>توضیحات آزمون</span>
                            <textarea name="description" rows="4" placeholder="توضیحات آزمون، محدوده مطالعه، منابع یا نکات مهم..."></textarea>
                        </label>

                        <label class="hst-field">
                            <span>وضعیت</span>
                            <select name="status">
                                <option value="scheduled">برنامه‌ریزی‌شده</option>
                                <option value="done">برگزارشده</option>
                                <option value="cancelled">لغوشده</option>
                            </select>
                        </label>

                        <div class="hst-warnings hst-vstack" id="hst-exam-feedback" hidden></div>

                        <div class="hst-btn-group">
                            <button type="submit" class="hst-btn">ذخیره آزمون</button>
                            <button type="button" class="hst-btn hst-btn--danger" id="hst-exam-reset">فرم جدید</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="hst-card">
            <div class="hst-card__header"><h3>آزمون‌های ثبت‌شده من</h3></div>
            <div class="hst-card__body">
                <?php if (!empty($teacher_exams)) : ?>
                    <div class="hst-table-wrap">
                        <table class="hst-table hst-exam-table">
                            <thead>
                                <tr>
                                    <th>ردیف</th>
                                    <th>تاریخ</th>
                                    <th>زنگ</th>
                                    <th>کلاس</th>
                                    <th>درس</th>
                                    <th>عنوان</th>
                                    <th>وضعیت</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $hst_row_i = 0; foreach ($teacher_exams as $exam) : $hst_row_i++; ?>
                                    <tr
                                        data-id="<?php echo esc_attr($exam->id); ?>"
                                        data-class-id="<?php echo esc_attr($exam->class_id); ?>"
                                        data-lesson-id="<?php echo esc_attr($exam->lesson_id); ?>"
                                        data-date="<?php echo esc_attr($format_exam_date($exam->exam_date)); ?>"
                                        data-shift="<?php echo esc_attr($exam->school_shift); ?>"
                                        data-title="<?php echo esc_attr($exam->title); ?>"
                                        data-description="<?php echo esc_attr($exam->description); ?>"
                                        data-duration="<?php echo esc_attr($exam->duration_minutes); ?>"
                                        data-location="<?php echo esc_attr($exam->location); ?>"
                                        data-status="<?php echo esc_attr($exam->status); ?>"
                                    >
                                        <td class="hst-row-num"><?php echo esc_html(number_format_i18n($hst_row_i)); ?></td>
                                        <td><?php echo esc_html($format_exam_date($exam->exam_date)); ?></td>
                                        <td>زنگ <?php echo esc_html($exam->school_shift); ?></td>
                                        <td><?php echo esc_html($exam->class_name); ?></td>
                                        <td><?php echo esc_html($exam->lesson_name); ?></td>
                                        <td>
                                            <strong><?php echo esc_html($exam->title); ?></strong>
                                            <?php if (!empty($exam->location)) : ?>
                                                <small><?php echo esc_html($exam->location); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="hst-status hst-status--<?php echo esc_attr($exam->status); ?>"><?php echo esc_html($exam_status_labels[$exam->status] ?? $exam->status); ?></span></td>
                                        <td class="hst-actions">
                                            <div class="hst-btn-group">
                                                <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-exam-edit">ویرایش</button>
                                                <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-exam-delete">حذف</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <p class="hst-alert">هنوز آزمونی در سال تحصیلی فعال ثبت نشده است.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($is_manager) : ?>
        <div class="hst-management-page" data-hst-exam-section-panel="management"<?php echo $exam_section === 'management' ? '' : ' hidden'; ?>>
            <div class="hst-page hst-management-page" data-hst-exam-management-overview>
            <?php
                $exam_management_rows = [];
                $exam_report_total = count($manager_exams);
                $exam_report_active = 0;
                $exam_report_waiting = 0;
                $exam_report_done = 0;
                $exam_report_participants = 0;
                $exam_report_eligible = 0;
                $exam_timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
                $exam_now = function_exists('current_datetime') ? current_datetime() : new DateTimeImmutable('now', $exam_timezone);

                foreach ($manager_exams as $exam) {
                    $start_date = (string) ($exam->start_date ?: $exam->exam_date);
                    $end_date = (string) ($exam->end_date ?: $start_date);
                    $start_time = substr((string) ($exam->start_time ?: '00:00:00'), 0, 5);
                    $end_time = substr((string) ($exam->end_time ?: '23:59:59'), 0, 5);
                    $runtime_key = 'waiting';
                    $runtime_label = 'برگزار نشده';
                    $runtime_class = 'muted';
                    $filter_status = 'not_done';

                    if ((string) $exam->status === 'done') {
                        $runtime_key = 'done';
                        $runtime_label = 'برگزار شده';
                        $runtime_class = 'success';
                        $filter_status = 'done';
                        $exam_report_done++;
                    } elseif ((string) $exam->status === 'cancelled') {
                        $runtime_key = 'cancelled';
                        $runtime_label = 'لغو شده';
                        $runtime_class = 'danger';
                    } else {
                        try {
                            $start_at = new DateTimeImmutable($start_date . ' ' . $start_time, $exam_timezone);
                            $end_at = new DateTimeImmutable($end_date . ' ' . $end_time, $exam_timezone);
                            if ($exam_now > $end_at) {
                                $runtime_key = 'done';
                                $runtime_label = 'برگزار شده';
                                $runtime_class = 'success';
                                $filter_status = 'done';
                                $exam_report_done++;
                            } elseif ($exam_now >= $start_at) {
                                $runtime_key = 'active';
                                $runtime_label = 'در حال برگزاری';
                                $runtime_class = 'warning';
                                $exam_report_active++;
                            } else {
                                $exam_report_waiting++;
                            }
                        } catch (Exception $exception) {
                            $exam_report_waiting++;
                        }
                    }

                    $participants = max(0, (int) ($exam->participant_count ?? 0));
                    $eligible = max(0, (int) ($exam->eligible_participants ?? 0));
                    $is_in_person_exam = (string) ($exam->delivery_mode ?? '') === 'in_person';
                    $participation_percent = $is_in_person_exam
                        ? 100
                        : ($eligible > 0 ? min(100, (int) round(($participants / $eligible) * 100)) : 0);
                    $exam_report_participants += $is_in_person_exam ? 1 : $participants;
                    $exam_report_eligible += $is_in_person_exam ? 1 : $eligible;

                    $exam_management_rows[] = [
                        'exam' => $exam,
                        'start_date' => $start_date,
                        'end_date' => $end_date,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                        'runtime_key' => $runtime_key,
                        'runtime_label' => $runtime_label,
                        'runtime_class' => $runtime_class,
                        'filter_status' => $filter_status,
                        'participants' => $participants,
                        'eligible' => $eligible,
                        'participation_percent' => $participation_percent,
                        'is_in_person' => $is_in_person_exam,
                    ];
                }

                $exam_report_average = $exam_report_eligible > 0
                    ? min(100, (int) round(($exam_report_participants / $exam_report_eligible) * 100))
                    : 0;
            ?>

            <div class="hst-card hst-section-card">
                <div class="hst-card__header hst-section-card__header"><h3>مدیریت آزمون‌ها</h3></div>
                <div class="hst-card__body hst-section-card__body">
                    <?php if (empty($active_term)) : ?>
                        <p class="hst-alert hst-empty-state">برای مدیریت آزمون‌ها ابتدا یک سال تحصیلی فعال تعریف کنید.</p>
                    <?php else : ?>
                        <div class="hst-report-stats">
                            <div class="hst-report-stat hst-report-stat--total"><b data-hst-exam-stat="total"><?php echo esc_html(number_format_i18n($exam_report_total)); ?></b><span>کل آزمون‌ها</span></div>
                            <div class="hst-report-stat hst-report-stat--info"><b data-hst-exam-stat="active"><?php echo esc_html(number_format_i18n($exam_report_active)); ?></b><span>آزمون فعال</span></div>
                            <div class="hst-report-stat hst-report-stat--warning"><b data-hst-exam-stat="waiting"><?php echo esc_html(number_format_i18n($exam_report_waiting)); ?></b><span>در انتظار برگزاری</span></div>
                            <div class="hst-report-stat hst-report-stat--success"><b data-hst-exam-stat="done"><?php echo esc_html(number_format_i18n($exam_report_done)); ?></b><span>برگزاری نهایی شده</span></div>
                            <div class="hst-report-stat hst-report-stat--upd"><b data-hst-exam-stat="average"><?php echo esc_html(number_format_i18n($exam_report_average)); ?>٪</b><span>میانگین مشارکت</span></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($active_term)) : ?>
                <div class="hst-inline-filter" data-hst-inline-filter="hst-exam-management-table">
                    <div class="hst-card hst-section-card">
                        <div class="hst-card__body hst-section-card__body">
                            <div class="hst-inline-filter__main">
                                <div class="hst-inline-filter__search">
                                    <span class="hst-inline-filter__search-icon" aria-hidden="true"><?php echo hst_icon('exams'); ?></span>
                                    <input type="search" class="hst-search" data-hst-inline-search placeholder="جست‌وجوی معلم، کلاس یا درس..." autocomplete="off">
                                </div>
                                <select class="hst-inline-filter__select" data-hst-inline-select="status" data-hst-segmented-label="none" aria-label="فیلتر وضعیت برگزاری">
                                    <option value="">همه</option>
                                    <option value="done">برگزار شده</option>
                                    <option value="not_done">برگزار نشده</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hst-card hst-section-card">
                    <div class="hst-card__header hst-section-card__header"><h3>لیست آزمون‌ها</h3></div>
                    <div class="hst-card__body hst-section-card__body">
                        <p class="hst-alert hst-empty-state" data-hst-exam-management-empty<?php echo empty($exam_management_rows) ? '' : ' hidden'; ?>>آزمونی برای نمایش وجود ندارد.</p>
                        <div class="hst-table-wrap hst-data-table-wrap" data-hst-exam-management-table-wrap<?php echo empty($exam_management_rows) ? ' hidden' : ''; ?>>
                            <table class="hst-table hst-data-table" id="hst-exam-management-table">
                                <thead>
                                    <tr>
                                        <th>ردیف</th>
                                        <th>نام آزمون</th>
                                        <th>درس</th>
                                        <th>کلاس</th>
                                        <th>معلم</th>
                                        <th>تاریخ آزمون</th>
                                        <th>شیوه آزمون</th>
                                        <th>نوع آزمون</th>
                                        <th>تعداد شرکت‌کنندگان</th>
                                        <th>وضعیت</th>
                                        <th>عملیات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($exam_management_rows as $index => $row) :
                                        $exam = $row['exam'];
                                        $exam_type_label = $exam_builder_type_labels[(string) ($exam->exam_type ?? '')] ?? '—';
                                        $delivery_label = $exam_builder_delivery_modes[(string) ($exam->delivery_mode ?? '')] ?? '—';
                                        $has_exam_questions = (int) ($exam->actual_question_count ?? 0) > 0;
                                        $exam_download_title = $has_exam_questions
                                            ? 'دریافت نمونه سؤال و راهنمای تصحیح'
                                            : 'هنوز سؤالی برای این آزمون ثبت نشده است.';
                                        $search_text = trim(implode(' ', [
                                            (string) $exam->title,
                                            (string) $exam->lesson_name,
                                            (string) $exam->class_name,
                                            (string) $exam->teacher_name,
                                            $exam_type_label,
                                            $delivery_label,
                                            $row['runtime_label'],
                                        ]));
                                    ?>
                                        <tr
                                            data-id="<?php echo esc_attr((int) $exam->id); ?>"
                                            data-hst-search="<?php echo esc_attr($search_text); ?>"
                                            data-hst-status="<?php echo esc_attr($row['filter_status']); ?>"
                                            data-runtime="<?php echo esc_attr($row['runtime_key']); ?>"
                                            data-participants="<?php echo esc_attr($row['participants']); ?>"
                                            data-eligible="<?php echo esc_attr($row['eligible']); ?>"
                                            data-title="<?php echo esc_attr($exam->title); ?>"
                                            data-grade="<?php echo esc_attr((string) ($exam->grade ?? '')); ?>"
                                            data-major="<?php echo esc_attr((string) ($exam->major ?? '')); ?>"
                                            data-class-id="<?php echo esc_attr((int) $exam->class_id); ?>"
                                            data-lesson-id="<?php echo esc_attr((int) $exam->lesson_id); ?>"
                                            data-exam-type="<?php echo esc_attr((string) ($exam->exam_type ?? '')); ?>"
                                            data-delivery-mode="<?php echo esc_attr((string) ($exam->delivery_mode ?? '')); ?>"
                                            data-duration="<?php echo esc_attr((int) ($exam->duration_minutes ?? 0)); ?>"
                                            data-question-count="<?php echo esc_attr((int) ($exam->question_count ?? 0)); ?>"
                                            data-actual-question-count="<?php echo esc_attr((int) ($exam->actual_question_count ?? 0)); ?>"
                                            data-start-date="<?php echo esc_attr($format_exam_date($row['start_date'])); ?>"
                                            data-end-date="<?php echo esc_attr($format_exam_date($row['end_date'])); ?>"
                                            data-start-time="<?php echo esc_attr($row['start_time']); ?>"
                                            data-end-time="<?php echo esc_attr($row['end_time']); ?>"
                                            data-attempt-limit="<?php echo esc_attr((int) ($exam->attempt_limit ?? 1)); ?>"
                                            data-result-visibility="<?php echo esc_attr((string) ($exam->result_visibility ?? 'after_end')); ?>"
                                            data-randomize-questions="<?php echo esc_attr((int) ($exam->randomize_questions ?? 0)); ?>"
                                            data-randomize-options="<?php echo esc_attr((int) ($exam->randomize_options ?? 0)); ?>"
                                            data-record-exit-time="<?php echo esc_attr((int) ($exam->record_exit_time ?? 0)); ?>"
                                            data-ip-restriction="<?php echo esc_attr((int) ($exam->ip_restriction ?? 0)); ?>"
                                            data-view-title="<?php echo esc_attr($exam->title); ?>"
                                            data-view-lesson="<?php echo esc_attr($exam->lesson_name); ?>"
                                            data-view-class="<?php echo esc_attr($exam->class_name); ?>"
                                            data-view-teacher="<?php echo esc_attr($exam->teacher_name); ?>"
                                            data-view-date="<?php echo esc_attr($format_exam_date($row['start_date'])); ?>"
                                            data-view-delivery="<?php echo esc_attr($delivery_label); ?>"
                                            data-view-type="<?php echo esc_attr($exam_type_label); ?>"
                                            data-view-participation="<?php echo esc_attr($row['is_in_person'] ? '۱۰۰٪' : (number_format_i18n($row['participants']) . ' / ' . number_format_i18n($row['eligible']))); ?>"
                                            data-view-exits="<?php echo esc_attr(number_format_i18n((int) ($exam->exit_event_count ?? 0))); ?>"
                                            data-view-status="<?php echo esc_attr($row['runtime_label']); ?>"
                                        >
                                            <td class="hst-row-num"><?php echo esc_html(number_format_i18n($index + 1)); ?></td>
                                            <td><strong data-hst-exam-cell="title"><?php echo esc_html($exam->title); ?></strong></td>
                                            <td data-hst-exam-cell="lesson"><?php echo esc_html($exam->lesson_name); ?></td>
                                            <td data-hst-exam-cell="class"><?php echo esc_html($exam->class_name); ?></td>
                                            <td><?php echo esc_html($exam->teacher_name); ?></td>
                                            <td data-hst-exam-cell="date"><?php echo esc_html($format_exam_date($row['start_date'])); ?></td>
                                            <td><span class="hst-status hst-status--info" data-hst-exam-cell="delivery"><?php echo esc_html($delivery_label); ?></span></td>
                                            <td><span class="hst-status hst-status--<?php echo esc_attr($exam->exam_type === 'continuous' ? 'success' : ($exam->exam_type === 'midterm' ? 'warning' : 'info')); ?>" data-hst-exam-cell="type"><?php echo esc_html($exam_type_label); ?></span></td>
                                            <td>
                                                <div class="hst-vstack">
                                                    <small class="hst-muted"><?php echo esc_html($row['is_in_person'] ? '۱۰۰٪' : (number_format_i18n($row['participants']) . ' / ' . number_format_i18n($row['eligible']))); ?></small>
                                                    <span class="hst-progress" data-status="<?php echo esc_attr($row['participation_percent'] >= 100 ? 'complete' : ($row['participation_percent'] > 0 ? 'partial' : 'missing')); ?>"><span class="hst-progress__bar" style="width: <?php echo esc_attr((string) $row['participation_percent']); ?>%;"></span></span>
                                                </div>
                                            </td>
                                            <td><span class="hst-status hst-status--<?php echo esc_attr($row['runtime_class']); ?>" data-hst-exam-status-label><?php echo esc_html($row['runtime_label']); ?></span></td>
                                            <td class="hst-actions">
                                                <div class="hst-btn-group">
                                                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-exam-management-action="view" title="مشاهده آزمون" aria-label="مشاهده آزمون"><?php echo hst_icon('view'); ?></button>
                                                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-exam-management-action="edit" title="ویرایش آزمون" aria-label="ویرایش آزمون"><?php echo hst_icon('edit'); ?></button>
                                                    <button type="button" class="hst-btn hst-btn--primary hst-btn--sm hst-btn--icon" data-hst-exam-management-action="preview" title="پیش‌نمایش آزمون" aria-label="پیش‌نمایش آزمون"<?php echo $row['is_in_person'] ? ' disabled aria-disabled="true"' : ''; ?>><?php echo hst_icon('play'); ?></button>
                                                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-exam-management-action="report" title="گزارش آزمون" aria-label="گزارش آزمون"><?php echo hst_icon('report'); ?></button>
                                                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-exam-management-action="download" <?php disabled(!$has_exam_questions); ?> title="<?php echo esc_attr($exam_download_title); ?>" aria-label="<?php echo esc_attr($exam_download_title); ?>"><?php echo hst_icon('download'); ?></button>
                                                    <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon" data-hst-exam-management-action="delete" title="حذف آزمون" aria-label="حذف آزمون"><?php echo hst_icon('delete'); ?></button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="hst-modal" id="hst-exam-management-view-modal" data-hst-modal-tone="detail" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-exam-management-view-title">
                    <div class="hst-modal__backdrop" data-hst-exam-management-view-close></div>
                    <div class="hst-modal__panel">
                        <div class="hst-modal__header">
                            <div class="hst-modal__title">
                                <span class="hst-modal__icon" aria-hidden="true"><?php echo hst_icon('exams'); ?></span>
                                <div><h3 id="hst-exam-management-view-title">جزئیات آزمون</h3><p>مشخصات ثبت‌شده و وضعیت برگزاری آزمون</p></div>
                            </div>
                            <button type="button" class="hst-modal__close" data-hst-exam-management-view-close aria-label="بستن">&times;</button>
                        </div>
                        <div class="hst-modal__body">
                            <div class="hst-view-grid">
                                <div class="hst-view-row hst-view-row--wide"><span class="hst-view-row__label">نام آزمون</span><span class="hst-view-row__value" data-hst-exam-view-field="title">—</span></div>
                                <div class="hst-view-row"><span class="hst-view-row__label">درس</span><span class="hst-view-row__value" data-hst-exam-view-field="lesson">—</span></div>
                                <div class="hst-view-row"><span class="hst-view-row__label">کلاس</span><span class="hst-view-row__value" data-hst-exam-view-field="class">—</span></div>
                                <div class="hst-view-row"><span class="hst-view-row__label">معلم</span><span class="hst-view-row__value" data-hst-exam-view-field="teacher">—</span></div>
                                <div class="hst-view-row"><span class="hst-view-row__label">تاریخ آزمون</span><span class="hst-view-row__value" data-hst-exam-view-field="date">—</span></div>
                                <div class="hst-view-row"><span class="hst-view-row__label">شیوه آزمون</span><span class="hst-view-row__value" data-hst-exam-view-field="delivery">—</span></div>
                                <div class="hst-view-row"><span class="hst-view-row__label">نوع آزمون</span><span class="hst-view-row__value" data-hst-exam-view-field="type">—</span></div>
                                <div class="hst-view-row"><span class="hst-view-row__label">تعداد شرکت‌کنندگان</span><span class="hst-view-row__value" data-hst-exam-view-field="participation">—</span></div>
                                <div class="hst-view-row"><span class="hst-view-row__label">خروج‌های ثبت‌شده</span><span class="hst-view-row__value" data-hst-exam-view-field="exits">—</span></div>
                                <div class="hst-view-row"><span class="hst-view-row__label">وضعیت</span><span class="hst-view-row__value" data-hst-exam-view-field="status">—</span></div>
                            </div>
                        </div>
                        <div class="hst-modal__footer"><button type="button" class="hst-btn hst-btn--soft" data-hst-exam-management-view-close>بستن</button></div>
                    </div>
                </div>
            <?php endif; ?>
            </div>

            <div data-hst-exam-online-preview hidden>
                <div class="hst-card hst-section-card hst-exam-online-preview">
                    <div class="hst-card__header hst-section-card__header">
                        <div>
                            <h3>پیش‌نمایش آزمون غیر حضوری</h3>
                            <p>شبیه‌سازی کامل محیط برگزاری آزمون برای بررسی مدیر؛ پاسخ‌ها ذخیره نمی‌شوند.</p>
                        </div>
                        <button type="button" class="hst-btn hst-btn--soft" data-hst-online-preview-close><?php echo hst_icon('back'); ?><span>بازگشت به مدیریت آزمون‌ها</span></button>
                    </div>
                    <div class="hst-card__body hst-section-card__body">
                        <div class="hst-exam-online-preview__topbar">
                            <div class="hst-user-id hst-exam-online-preview__identity">
                                <?php echo hst_user_avatar($exam_preview_user, $exam_preview_user_name, 52, false); ?>
                                <span class="hst-user-id__meta">
                                    <strong><?php echo esc_html($exam_preview_user_name); ?></strong>
                                    <small><span class="hst-status hst-status--info">حالت پیش‌نمایش</span> <span data-hst-online-preview-class>—</span></small>
                                </span>
                            </div>
                            <div class="hst-exam-online-preview__heading">
                                <h3 data-hst-online-preview-title>—</h3>
                                <p><span>درس: <b data-hst-online-preview-lesson>—</b></span><i aria-hidden="true">•</i><span>مدت زمان: <b data-hst-online-preview-duration>—</b> دقیقه</span></p>
                            </div>
                            <div class="hst-status hst-status--danger hst-exam-online-preview__timer" role="timer" aria-live="polite">
                                <?php echo hst_icon('month'); ?>
                                <span>زمان باقی‌مانده</span>
                                <b data-hst-online-preview-timer>۰۰:۰۰</b>
                            </div>
                        </div>

                        <div class="hst-exam-online-preview__layout">
                            <aside class="hst-exam-online-preview__sidebar">
                                <div class="hst-card hst-exam-online-preview__status-card">
                                    <div class="hst-card__body">
                                        <h3>وضعیت پاسخ‌دهی</h3>
                                        <div class="hst-exam-online-preview__progress-copy"><span>پیشرفت آزمون:</span><b data-hst-online-preview-progress-text>۰ از ۰ سؤال</b></div>
                                        <span class="hst-progress" data-status="missing"><span class="hst-progress__bar" data-hst-online-preview-progress-bar style="width:0%"></span></span>
                                        <div class="hst-exam-online-preview__legend"><span><i class="is-answered"></i>پاسخ داده شده</span><span><i></i>بی‌پاسخ</span></div>
                                    </div>
                                </div>

                                <div class="hst-card hst-exam-online-preview__list-card">
                                    <div class="hst-card__body">
                                        <h3>لیست سؤالات</h3>
                                        <div class="hst-exam-online-preview__question-list" data-hst-online-preview-question-list></div>
                                    </div>
                                </div>

                                <button type="button" class="hst-btn hst-btn--danger hst-btn--block" data-hst-online-preview-finish><?php echo hst_icon('scores'); ?><span>ثبت نهایی و اتمام پیش‌نمایش</span></button>
                            </aside>

                            <div class="hst-card hst-exam-online-preview__question-card">
                                <div class="hst-card__body">
                                    <div class="hst-exam-online-preview__question-meta">
                                        <div class="hst-btn-group">
                                            <span class="hst-status hst-status--info" data-hst-online-preview-number>سؤال ۱ از ۱</span>
                                            <span class="hst-status hst-status--muted" data-hst-online-preview-type>—</span>
                                            <span class="hst-status hst-status--muted" data-hst-online-preview-difficulty>—</span>
                                        </div>
                                        <span class="hst-status hst-status--info" data-hst-online-preview-score>بارم: ۰ نمره</span>
                                    </div>
                                    <div class="hst-exam-online-preview__question-text" dir="auto" data-hst-online-preview-question-text></div>
                                    <div class="hst-exam-online-preview__answer" data-hst-online-preview-answer></div>
                                    <div class="hst-btn-group hst-exam-online-preview__navigation">
                                        <button type="button" class="hst-btn hst-btn--soft" data-hst-online-preview-prev><?php echo hst_icon('back'); ?><span>سؤال قبلی</span></button>
                                        <button type="button" class="hst-btn" data-hst-online-preview-next><span>سؤال بعدی</span><?php echo hst_icon('back'); ?></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hst-modal" id="hst-online-exam-preview-finish-modal" data-hst-modal-size="md" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-online-exam-preview-finish-title">
                <div class="hst-modal__backdrop" data-hst-online-preview-finish-close></div>
                <div class="hst-modal__panel">
                    <div class="hst-modal__header">
                        <div class="hst-modal__title"><h3 id="hst-online-exam-preview-finish-title">اتمام پیش‌نمایش آزمون</h3></div>
                        <button type="button" class="hst-modal__close" data-hst-online-preview-finish-close aria-label="بستن">&times;</button>
                    </div>
                    <div class="hst-modal__body">
                        <p>این بخش فقط پیش‌نمایش مدیریتی است و هیچ پاسخی برای دانش‌آموز ثبت نمی‌شود. برای پایان‌دادن به پیش‌نمایش مطمئن هستید؟</p>
                        <p class="hst-alert hst-alert--info" data-hst-online-preview-finish-summary>تعداد کل سؤالات: ۰ | پاسخ داده شده: ۰ | بدون پاسخ: ۰</p>
                    </div>
                    <div class="hst-modal__footer">
                        <button type="button" class="hst-btn hst-btn--soft" data-hst-online-preview-finish-close>انصراف و ادامه بررسی</button>
                        <button type="button" class="hst-btn" data-hst-online-preview-finish-confirm>بله، پایان پیش‌نمایش</button>
                    </div>
                </div>
            </div>
        </div>

        <div data-hst-exam-section-panel="builder"<?php echo $exam_section === 'builder' ? '' : ' hidden'; ?>>
            <div class="hst-card hst-section-card">
                <div class="hst-card__header hst-section-card__header">
                    <div>
                        <h3 id="hst-exam-builder-title">ایجاد آزمون جدید</h3>
                        <p>تعریف شناسنامه آزمون، تعیین زمان‌بندی و تنظیمات امنیتی آزمون</p>
                    </div>
                </div>
                <div class="hst-card__body hst-section-card__body">
                    <?php if (empty($active_term)) : ?>
                        <p class="hst-alert hst-empty-state">برای ایجاد آزمون ابتدا یک سال تحصیلی فعال تعریف کنید.</p>
                    <?php else : ?>
                        <form
                            class="hst-form"
                            id="hst-exam-builder-form"
                            novalidate
                            data-default-start-time="<?php echo esc_attr($exam_builder_start_time); ?>"
                            data-default-end-time="<?php echo esc_attr($exam_builder_end_time); ?>"
                            data-default-attempt-limit="<?php echo esc_attr((int) ($exam_general_settings['max_attempts'] ?? 1)); ?>"
                            data-default-result-visibility="<?php echo esc_attr(!empty($exam_general_settings['instant_results']) ? 'after_submit' : 'after_end'); ?>"
                            data-auto-grading="<?php echo esc_attr(!empty($exam_general_settings['auto_grading']) ? '1' : '0'); ?>"
                        >
                            <input type="hidden" name="id" value="">
                            <div class="hst-form" data-hst-exam-step="1">
                                <div class="hst-grid hst-exam-builder-grid">
                                    <label class="hst-field">
                                        <span>عنوان آزمون</span>
                                        <input type="text" name="title" maxlength="120" placeholder="مثال: آزمون مستمر فصل اول و دوم فیزیک دهم" required>
                                    </label>
                                    <label class="hst-field">
                                        <span>پایه تحصیلی</span>
                                        <select name="grade" required>
                                            <option value="">انتخاب پایه تحصیلی</option>
                                            <?php foreach ($exam_builder_grades as $value => $label) : ?>
                                                <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="hst-field">
                                        <span>رشته تحصیلی</span>
                                        <select name="major" required>
                                            <option value="">انتخاب رشته تحصیلی</option>
                                            <?php foreach ($exam_builder_majors as $value => $label) : ?>
                                                <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="hst-field">
                                        <span>کلاس</span>
                                        <select name="class_id" id="hst-exam-builder-class" required disabled>
                                            <option value="">ابتدا پایه و رشته تحصیلی را انتخاب کنید</option>
                                            <?php foreach ($exam_builder_classes as $class) : ?>
                                                <option
                                                    value="<?php echo esc_attr($class['id']); ?>"
                                                    data-grade="<?php echo esc_attr($class['grade']); ?>"
                                                    data-major="<?php echo esc_attr($class['major']); ?>"
                                                ><?php echo esc_html($class['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="hst-field">
                                        <span>درس</span>
                                        <select name="lesson_id" id="hst-exam-builder-lesson" required disabled>
                                            <option value="">ابتدا کلاس را انتخاب کنید</option>
                                        </select>
                                    </label>
                                    <label class="hst-field">
                                        <span>نوع آزمون</span>
                                        <select name="exam_type" required>
                                            <option value="">انتخاب نوع آزمون</option>
                                            <?php foreach ($exam_builder_types as $value => $label) : ?>
                                                <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="hst-field">
                                        <span>شیوه آزمون</span>
                                        <select name="delivery_mode" required>
                                            <option value="">انتخاب شیوه آزمون</option>
                                            <?php foreach ($exam_builder_delivery_modes as $value => $label) : ?>
                                                <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="hst-field">
                                        <span>مدت زمان آزمون (دقیقه)</span>
                                        <input type="number" name="duration_minutes" min="1" max="600" value="90" required>
                                    </label>
                                    <label class="hst-field">
                                        <span>تعداد سؤالات</span>
                                        <input type="number" name="question_count" min="1" max="500" value="20" required>
                                    </label>
                                </div>
                                <div class="hst-btn-group hst-exam-builder-actions">
                                    <button type="button" class="hst-btn" data-hst-exam-next>مرحله بعد</button>
                                </div>
                            </div>

                            <div class="hst-form" data-hst-exam-step="2" hidden>
                                <div class="hst-grid hst-exam-builder-grid hst-exam-builder-grid--schedule">
                                    <div class="hst-field">
                                        <span>تاریخ شروع آزمون</span>
                                        <div class="hst-exam-control">
                                            <input type="text" name="start_date" class="hst-jalali-date" data-hst-min-date="today" placeholder="انتخاب تاریخ شروع" autocomplete="off" required>
                                            <button type="button" class="hst-btn hst-btn--icon" data-hst-date-target="start_date" title="انتخاب تاریخ شروع" aria-label="انتخاب تاریخ شروع"><?php echo hst_icon('schedule'); ?></button>
                                        </div>
                                    </div>
                                    <div class="hst-field">
                                        <span>ساعت شروع آزمون</span>
                                        <div class="hst-exam-control">
                                            <input type="text" name="start_time" class="hst-time-input" data-hst-time-title="انتخاب ساعت شروع آزمون" value="<?php echo esc_attr($exam_builder_start_time); ?>" inputmode="numeric" readonly required>
                                            <button type="button" class="hst-btn hst-btn--icon" data-hst-time-target="start_time" title="انتخاب ساعت شروع" aria-label="انتخاب ساعت شروع"><?php echo hst_icon('month'); ?></button>
                                        </div>
                                    </div>
                                    <div class="hst-field">
                                        <span>تاریخ پایان آزمون</span>
                                        <div class="hst-exam-control">
                                            <input type="text" name="end_date" class="hst-jalali-date" data-hst-min-date="today" placeholder="انتخاب تاریخ پایان" autocomplete="off" required>
                                            <button type="button" class="hst-btn hst-btn--icon" data-hst-date-target="end_date" title="انتخاب تاریخ پایان" aria-label="انتخاب تاریخ پایان"><?php echo hst_icon('schedule'); ?></button>
                                        </div>
                                    </div>
                                    <div class="hst-field">
                                        <span>ساعت پایان آزمون</span>
                                        <div class="hst-exam-control">
                                            <input type="text" name="end_time" class="hst-time-input" data-hst-time-title="انتخاب ساعت پایان آزمون" value="<?php echo esc_attr($exam_builder_end_time); ?>" inputmode="numeric" readonly required>
                                            <button type="button" class="hst-btn hst-btn--icon" data-hst-time-target="end_time" title="انتخاب ساعت پایان" aria-label="انتخاب ساعت پایان"><?php echo hst_icon('month'); ?></button>
                                        </div>
                                    </div>
                                    <label class="hst-field" data-hst-online-only>
                                        <span>تعداد دفعات مجاز شرکت در آزمون</span>
                                        <input type="number" name="attempt_limit" min="1" max="10" value="<?php echo esc_attr((int) ($exam_general_settings['max_attempts'] ?? 1)); ?>" required>
                                    </label>
                                    <label class="hst-field" data-hst-online-only>
                                        <span>نمایش نتیجه آزمون به دانش‌آموز</span>
                                        <select name="result_visibility" required>
                                            <?php foreach ($exam_builder_result_modes as $value => $label) : ?>
                                                <option
                                                    value="<?php echo esc_attr($value); ?>"
                                                    <?php echo $value === (!empty($exam_general_settings['instant_results']) ? 'after_submit' : 'after_end') ? ' selected' : ''; ?>
                                                    <?php disabled($value === 'after_submit' && empty($exam_general_settings['auto_grading'])); ?>
                                                ><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>

                                <div class="hst-choice-list" data-hst-online-only>
                                    <label><input type="checkbox" name="randomize_questions" value="1" checked> ترتیب سؤالات تصادفی</label>
                                    <label><input type="checkbox" name="randomize_options" value="1" checked> ترتیب گزینه‌ها تصادفی</label>
                                    <label><input type="checkbox" name="record_exit_time" value="1"> ثبت زمان خروج از آزمون</label>
                                    <label><input type="checkbox" name="ip_restriction" value="1"> محدودیت IP</label>
                                </div>
                                <div class="hst-btn-group hst-exam-builder-actions">
                                    <button type="button" class="hst-btn hst-btn--soft" data-hst-exam-prev>مرحله قبل</button>
                                    <button type="submit" class="hst-btn" data-hst-exam-builder-submit>ثبت آزمون</button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="hst-management-page hst-question-bank-page" data-hst-exam-section-panel="question-bank" data-hst-question-bank-root<?php echo $exam_section === 'question-bank' ? '' : ' hidden'; ?>>
            <script type="application/json" data-hst-question-curriculum><?php echo wp_json_encode($question_bank_curriculum, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
            <script type="application/json" data-hst-question-blueprint><?php echo wp_json_encode($question_bank_blueprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>

            <section class="hst-question-blueprint" data-hst-question-stage="1" aria-labelledby="hst-question-blueprint-title">
                <div class="hst-card hst-section-card">
                    <div class="hst-card__header hst-card__header--row hst-section-card__header hst-question-bank__heading">
                        <div>
                            <h3 id="hst-question-blueprint-title">سرفصل آزمون (بودجه‌بندی)</h3>
                            <p>ابتدا پایه، رشته، درس و محدوده آزمون را تعیین کنید تا بانک سؤال بر همان اساس آماده شود.</p>
                        </div>
                        <div class="hst-btn-group">
                            <button type="submit" form="hst-question-blueprint-form" class="hst-btn hst-btn--sm" data-hst-blueprint-next disabled>مرحله بعد</button>
                        </div>
                    </div>
                    <div class="hst-card__body hst-section-card__body">
                        <form id="hst-question-blueprint-form" class="hst-form hst-question-blueprint__form" data-hst-question-blueprint-form>
                            <div class="hst-question-blueprint__panel">
                                <div class="hst-question-blueprint__panel-title">
                                    <h4>سرفصل و بودجه‌بندی آزمون</h4>
                                </div>

                                <div class="hst-question-blueprint__selectors">
                                    <label class="hst-field">
                                        <span>پایه</span>
                                        <select name="grade" data-hst-blueprint-grade required>
                                            <option value="">انتخاب پایه</option>
                                            <?php foreach ($exam_builder_grades as $grade_key => $grade_label) : ?>
                                                <option value="<?php echo esc_attr($grade_key); ?>"><?php echo esc_html($grade_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="hst-field">
                                        <span>رشته</span>
                                        <select name="major" data-hst-blueprint-major required disabled>
                                            <option value="">ابتدا پایه را انتخاب کنید</option>
                                        </select>
                                    </label>
                                    <label class="hst-field">
                                        <span>درس</span>
                                        <select name="subject" data-hst-blueprint-subject required disabled>
                                            <option value="">ابتدا رشته را انتخاب کنید</option>
                                        </select>
                                    </label>
                                </div>

                                <div class="hst-question-blueprint__tree-wrap">
                                    <div class="hst-question-blueprint__tree-heading">
                                        <div>
                                            <h4>فصل‌ها و درس‌ها</h4>
                                            <p>می‌توانید یک یا چند فصل، درس یا بخش مستقل را انتخاب کنید.</p>
                                        </div>
                                        <span class="hst-question-blueprint__selection" data-hst-blueprint-count>۰ درس یا بخش انتخاب شده</span>
                                    </div>
                                    <div class="hst-question-blueprint__tree" data-hst-blueprint-tree>
                                        <p class="hst-alert hst-empty-state">برای مشاهده بودجه‌بندی، پایه، رشته و درس را انتخاب کنید.</p>
                                    </div>
                                </div>

                            </div>
                        </form>
                        <div class="hst-question-bank__footer">
                            <div class="hst-btn-group">
                                <button type="submit" form="hst-question-blueprint-form" class="hst-btn hst-btn--sm" data-hst-blueprint-next disabled>مرحله بعد</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="hst-management-page hst-question-bank__stage" data-hst-question-stage="2" aria-label="فهرست بانک سؤال" hidden>
            <div class="hst-inline-filter" data-hst-inline-filter="hst-question-bank-list">
                <div class="hst-card hst-section-card hst-management-card">
                    <div class="hst-card__header hst-card__header--row hst-section-card__header hst-question-bank__heading">
                        <div>
                            <h3>بانک سؤالات جامع</h3>
                            <p data-hst-question-scope-summary>مدیریت، بازبینی و انتقال سؤالات در محدوده بودجه‌بندی انتخاب‌شده</p>
                        </div>
                        <div class="hst-btn-group">
                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-question-blueprint-back>تغییر بودجه‌بندی</button>
                            <button type="button" class="hst-btn hst-btn--sm" data-hst-question-next>مرحله بعد</button>
                        </div>
                    </div>
                    <div class="hst-card__body hst-section-card__body">
                        <div class="hst-stack">
                            <div class="hst-inline-filter__add">
                                <button type="button" class="hst-btn--icon hst-btn hst-btn--primary hst-btn--sm" data-hst-question-open title="طراحی و افزودن سؤال جدید" aria-label="طراحی و افزودن سؤال جدید"<?php echo empty($active_term) || empty($question_bank_lessons) ? ' disabled' : ''; ?>><?php echo hst_icon('add'); ?><span>طراحی و افزودن سؤال جدید</span></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hst-card hst-section-card">
                    <div class="hst-card__body hst-section-card__body">
                        <div class="hst-inline-filter__main" data-hst-question-filters>
                            <div class="hst-inline-filter__search">
                                <span class="hst-inline-filter__search-icon" aria-hidden="true"><?php echo hst_icon('exams'); ?></span>
                                <input type="search" id="hst-question-bank-search" class="hst-search" data-hst-question-search data-hst-inline-search placeholder="جست‌وجو در صورت سؤال…" autocomplete="off" aria-label="جست‌وجوی سؤال">
                            </div>
                            <select id="hst-question-bank-type" class="hst-inline-filter__select" data-hst-question-type data-hst-inline-select="type" aria-label="فیلتر نوع سؤال">
                                <option value="">همه انواع سؤال</option>
                                <?php foreach ($question_bank_types as $type_key => $type_label) : ?>
                                    <option value="<?php echo esc_attr($type_key); ?>"><?php echo esc_html($type_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="hst-question-bank-difficulty" class="hst-inline-filter__select" data-hst-question-difficulty data-hst-inline-select="difficulty" aria-label="فیلتر سطح دشواری">
                                <option value="">همه سطح‌ها</option>
                                <?php foreach ($question_bank_difficulties as $difficulty_key => $difficulty_label) : ?>
                                    <option value="<?php echo esc_attr($difficulty_key); ?>"><?php echo esc_html($difficulty_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($active_term) || empty($question_bank_lessons)) : ?>
                <div class="hst-card hst-section-card">
                    <div class="hst-card__body hst-section-card__body">
                        <p class="hst-alert hst-empty-state"><?php echo empty($active_term) ? 'برای ساخت بانک سؤال ابتدا یک سال تحصیلی را فعال کنید.' : 'درس معتبری برای پایه‌های دهم، یازدهم و دوازدهم پیدا نشد.'; ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="hst-card hst-section-card hst-question-bank__stats-card">
                <div class="hst-card__body hst-section-card__body hst-question-bank__stats-body">
                    <div class="hst-report-stats hst-question-bank__stats">
                        <div class="hst-report-stat hst-report-stat--total"><b data-hst-question-stat="total"><?php echo esc_html(number_format_i18n((int) $question_bank_stats['total'])); ?></b><span>کل سؤالات ثبت شده</span></div>
                        <div class="hst-report-stat hst-report-stat--success"><b data-hst-question-stat="easy-medium"><?php echo esc_html(number_format_i18n((int) $question_bank_stats['easy_medium'])); ?></b><span>سطح متوسط و آسان</span></div>
                        <div class="hst-report-stat hst-report-stat--danger"><b data-hst-question-stat="advanced"><?php echo esc_html(number_format_i18n((int) $question_bank_stats['advanced'])); ?></b><span>سطح سخت و مفهومی</span></div>
                    </div>
                </div>
            </div>

            <div class="hst-card hst-section-card">
                <div class="hst-card__body hst-section-card__body">
                    <div class="hst-card__header--row">
                        <label class="hst-chip" data-hst-question-select-all-wrap><input type="checkbox" data-hst-question-select-all aria-label="انتخاب همه سؤالات قابل مشاهده"> انتخاب همه این صفحه</label>
                        <span class="hst-muted" data-hst-question-selection-status>هیچ سؤالی انتخاب نشده است.</span>
                    </div>
                    <div class="hst-vstack" id="hst-question-bank-list" data-hst-question-list>
                        <?php foreach ($question_bank_questions as $question_index => $question) :
                            $question_payload = wp_json_encode($question, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $question_html = (string) ($question['question_text'] ?? '');
                            $question_list_text = html_entity_decode(wp_strip_all_tags($question_html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $question_list_text = preg_replace('/[\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u', '', $question_list_text) ?? $question_list_text;
                            $question_list_text = preg_replace('/\s+/u', ' ', $question_list_text) ?? $question_list_text;
                            $question_list_text = trim($question_list_text);
                            if (function_exists('wp_check_invalid_utf8')) {
                                $question_list_text = wp_check_invalid_utf8($question_list_text, true);
                            }
                            if ($question_list_text === '') {
                                if (stripos($question_html, '<img') !== false) {
                                    $question_list_text = 'سؤال تصویری';
                                } elseif (stripos($question_html, '<table') !== false) {
                                    $question_list_text = 'سؤال دارای جدول';
                                } else {
                                    $question_list_text = (string) ($question['question_preview'] ?? '');
                                }
                            }
                        ?>
                            <details
                                class="hst-availability-accordion"
                                data-hst-inline-item
                                data-hst-question-row
                                data-question-id="<?php echo esc_attr((int) $question['id']); ?>"
                                data-lesson-id="<?php echo esc_attr((int) $question['lesson_id']); ?>"
                                data-hst-lesson-id="<?php echo esc_attr((int) $question['lesson_id']); ?>"
                                data-class-id="<?php echo esc_attr((int) $question['class_id']); ?>"
                                data-class-name="<?php echo esc_attr((string) $question['class_name']); ?>"
                                data-lesson-name="<?php echo esc_attr($question['lesson_name']); ?>"
                                data-grade="<?php echo esc_attr($question['grade']); ?>"
                                data-major="<?php echo esc_attr($question['major']); ?>"
                                data-curriculum-subject="<?php echo esc_attr($question['curriculum_subject'] ?? ''); ?>"
                                data-curriculum-chapter="<?php echo esc_attr($question['curriculum_chapter'] ?? ''); ?>"
                                data-curriculum-topics="<?php echo esc_attr(implode(',', (array) ($question['curriculum_topics'] ?? []))); ?>"
                                data-question-type="<?php echo esc_attr($question['question_type']); ?>"
                                data-hst-type="<?php echo esc_attr($question['question_type']); ?>"
                                data-difficulty="<?php echo esc_attr($question['difficulty']); ?>"
                                data-hst-difficulty="<?php echo esc_attr($question['difficulty']); ?>"
                                data-score="<?php echo esc_attr((float) $question['score']); ?>"
                                data-type-label="<?php echo esc_attr($question['question_type_label']); ?>"
                                data-question-text="<?php echo esc_attr($question_list_text); ?>"
                                data-preview="<?php echo esc_attr($question_list_text); ?>"
                                data-search="<?php echo esc_attr($question_list_text); ?>"
                                data-hst-search="<?php echo esc_attr($question_list_text); ?>"
                            >
                                <summary>
                                    <span data-hst-question-summary-main>
                                        <input type="checkbox" value="<?php echo esc_attr((int) $question['id']); ?>" data-hst-question-select aria-label="انتخاب سؤال <?php echo esc_attr((int) $question['id']); ?>">
                                        <span class="hst-status hst-status--muted" data-hst-question-row-number>سؤال <?php echo esc_html(number_format_i18n($question_index + 1)); ?></span>
                                        <strong data-hst-question-text-display title="<?php echo esc_attr($question_list_text); ?>"><?php echo esc_html($question_list_text); ?></strong>
                                    </span>
                                    <span class="hst-btn-group" data-hst-question-summary-meta>
                                        <span class="hst-chip"><?php echo esc_html($question['question_type_label']); ?></span>
                                        <span class="hst-status hst-status--<?php echo in_array($question['difficulty'], ['easy', 'medium'], true) ? 'success' : 'warning'; ?>"><?php echo esc_html($question['difficulty_label']); ?></span>
                                        <span class="hst-chip"><?php echo esc_html(hst_format_grade($question['score'])); ?> نمره</span>
                                        <span class="hst-chip"><?php echo esc_html(number_format_i18n((int) $question['usage_count'])); ?> آزمون</span>
                                        <span class="hst-actions">
                                            <span class="hst-btn-group">
                                                <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-question-edit data-question="<?php echo esc_attr($question_payload); ?>" title="ویرایش سؤال" aria-label="ویرایش سؤال"><?php echo hst_icon('edit'); ?></button>
                                                <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon" data-hst-question-delete data-id="<?php echo esc_attr((int) $question['id']); ?>"<?php echo (int) $question['usage_count'] > 0 ? ' disabled title="این سؤال در آزمون استفاده شده است"' : ' title="حذف سؤال"'; ?> aria-label="حذف سؤال"><?php echo hst_icon('delete'); ?></button>
                                            </span>
                                        </span>
                                    </span>
                                </summary>
                                <div class="hst-availability" data-hst-question-answer></div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                    <div class="hst-alert hst-empty-state" data-hst-question-empty data-hst-inline-empty<?php echo empty($question_bank_questions) ? '' : ' hidden'; ?>>هیچ سؤالی با فیلترهای انتخابی شما یافت نشد.</div>
                    <div class="hst-question-bank__footer">
                        <div class="hst-btn-group">
                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-question-blueprint-back>تغییر بودجه‌بندی</button>
                            <button type="button" class="hst-btn hst-btn--sm" data-hst-question-next>مرحله بعد</button>
                        </div>
                    </div>
                </div>
            </div>
            </section>

            <section class="hst-question-bank__stage" data-hst-question-stage="3" aria-labelledby="hst-question-design-title" hidden>
                <div class="hst-card hst-section-card">
                    <div class="hst-card__header hst-card__header--row hst-section-card__header">
                        <h3 id="hst-question-design-title">مدیریت طراحی سوالات</h3>
                        <div class="hst-btn-group">
                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-question-design-back>مرحله قبل</button>
                            <button type="button" class="hst-btn hst-btn--sm" data-hst-question-design-next>مرحله بعد</button>
                        </div>
                    </div>
                    <div class="hst-card__body hst-section-card__body">
                        <div class="hst-report-stats hst-question-design__stats">
                            <article class="hst-report-stat hst-report-stat--total hst-question-design__stat">
                                <h4>تعداد سوالات</h4>
                                <div class="hst-question-design__ring" data-hst-design-ring="count" data-target="20" role="img" aria-label="تعداد سوالات انتخاب‌شده">
                                    <svg viewBox="0 0 42 42" aria-hidden="true" focusable="false" shape-rendering="geometricPrecision">
                                        <circle class="hst-question-design__ring-track" cx="21" cy="21" r="15.9155" pathLength="100"></circle>
                                        <circle class="hst-question-design__ring-value hst-question-design__ring-value--count" data-hst-design-segment="count" cx="21" cy="21" r="15.9155" pathLength="100" stroke-linecap="round" stroke-linejoin="round"></circle>
                                    </svg>
                                    <b dir="ltr" data-hst-design-value="count">۰ / ۲۰</b>
                                </div>
                                <span>هدف: ۲۰ سوال</span>
                            </article>

                            <article class="hst-report-stat hst-report-stat--success hst-question-design__stat">
                                <h4>بارم سوالات</h4>
                                <div class="hst-question-design__ring" data-hst-design-ring="score" data-target="20" role="img" aria-label="بارم سوالات انتخاب‌شده">
                                    <svg viewBox="0 0 42 42" aria-hidden="true" focusable="false" shape-rendering="geometricPrecision">
                                        <circle class="hst-question-design__ring-track" cx="21" cy="21" r="15.9155" pathLength="100"></circle>
                                        <circle class="hst-question-design__ring-value hst-question-design__ring-value--score" data-hst-design-segment="score" cx="21" cy="21" r="15.9155" pathLength="100" stroke-linecap="round" stroke-linejoin="round"></circle>
                                    </svg>
                                    <b dir="ltr" data-hst-design-value="score">۰ / ۲۰</b>
                                </div>
                                <span>هدف: ۲۰ نمره</span>
                            </article>

                            <article class="hst-report-stat hst-question-design__stat">
                                <h4>درجه سختی سوالات</h4>
                                <div class="hst-question-design__ring" data-hst-design-ring="difficulty" role="img" aria-label="توزیع درجه سختی سوالات انتخاب‌شده">
                                    <svg viewBox="0 0 42 42" aria-hidden="true" focusable="false" shape-rendering="geometricPrecision">
                                        <circle class="hst-question-design__ring-track" cx="21" cy="21" r="15.9155" pathLength="100"></circle>
                                        <circle class="hst-question-design__ring-value hst-question-design__ring-value--easy" data-hst-design-segment="easy" cx="21" cy="21" r="15.9155" pathLength="100" stroke-linecap="round" stroke-linejoin="round"></circle>
                                        <circle class="hst-question-design__ring-value hst-question-design__ring-value--medium" data-hst-design-segment="medium" cx="21" cy="21" r="15.9155" pathLength="100" stroke-linecap="round" stroke-linejoin="round"></circle>
                                        <circle class="hst-question-design__ring-value hst-question-design__ring-value--hard" data-hst-design-segment="hard" cx="21" cy="21" r="15.9155" pathLength="100" stroke-linecap="round" stroke-linejoin="round"></circle>
                                    </svg>
                                </div>
                                <div class="hst-btn-group" aria-label="تعداد سوالات بر اساس درجه سختی">
                                    <span class="hst-status hst-status--success" data-hst-design-value="easy">آسان ۰</span>
                                    <span class="hst-status hst-status--warning" data-hst-design-value="medium">متوسط ۰</span>
                                    <span class="hst-status hst-status--danger" data-hst-design-value="hard">سخت ۰</span>
                                </div>
                            </article>
                        </div>
                    </div>
                </div>

                <div class="hst-card hst-section-card">
                    <div class="hst-card__header hst-card__header--row hst-section-card__header">
                        <h3>سوالات انتخاب شده</h3>
                        <div class="hst-btn-group">
                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-question-auto-build><?php echo hst_icon('refresh'); ?><span>ساخت آزمون اتوماتیک</span></button>
                        </div>
                    </div>
                    <div class="hst-card__body hst-section-card__body">
                        <div class="hst-stack" data-hst-selected-question-list hidden></div>
                        <div class="hst-alert hst-empty-state" data-hst-selected-question-empty hidden>سوالی برای نمایش در این مرحله انتخاب نشده است.</div>
                        <p class="hst-muted">ترتیب سؤال‌ها با جابه‌جایی ذخیره می‌شود.</p>
                        <div class="hst-question-bank__footer">
                            <div class="hst-btn-group">
                                <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-question-design-back>مرحله قبل</button>
                                <button type="button" class="hst-btn hst-btn--sm" data-hst-question-design-next>مرحله بعد</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="hst-question-bank__stage" data-hst-question-stage="4" aria-labelledby="hst-question-final-title" hidden>
                <div class="hst-card hst-section-card">
                    <div class="hst-card__header hst-card__header--row hst-section-card__header">
                        <h3 id="hst-question-final-title">نمونه سوال و راهنمای تصحیح</h3>
                        <div class="hst-btn-group">
                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-question-final-back>مرحله قبل</button>
                            <button type="button" class="hst-btn hst-btn--sm" data-hst-question-final-submit>ثبت نهایی آزمون</button>
                        </div>
                    </div>
                    <div class="hst-card__body hst-section-card__body">
                        <div class="hst-btn-group" aria-label="مشاهده و دریافت نمونه سوال و راهنمای تصحیح">
                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-exam-paper-preview="questions" aria-pressed="false">
                                <span class="hst-btn__icon" aria-hidden="true"><?php echo hst_icon('view'); ?></span>
                                <span>نمونه سوال</span>
                            </button>
                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-exam-paper-preview="answers" aria-pressed="false">
                                <span class="hst-btn__icon" aria-hidden="true"><?php echo hst_icon('report-card'); ?></span>
                                <span>راهنمای تصحیح</span>
                            </button>
                            <button type="button" class="hst-btn hst-btn--ghost hst-btn--sm" data-hst-exam-paper-download="questions" disabled title="هنوز سؤالی برای دریافت آماده نشده است.">
                                <span class="hst-btn__icon" aria-hidden="true"><?php echo hst_icon('download'); ?></span>
                                <span>دانلود نمونه سوال</span>
                            </button>
                            <button type="button" class="hst-btn hst-btn--ghost hst-btn--sm" data-hst-exam-paper-download="answers" disabled title="هنوز راهنمای تصحیحی برای دریافت آماده نشده است.">
                                <span class="hst-btn__icon" aria-hidden="true"><?php echo hst_icon('download'); ?></span>
                                <span>دانلود راهنمای تصحیح</span>
                            </button>
                        </div>
                        <p class="hst-muted">پس از بررسی نمونه سوال و کلید، ثبت نهایی را انجام دهید.</p>
                    </div>
                </div>

                <div class="hst-card hst-section-card" data-hst-exam-paper-preview-card hidden>
                    <div class="hst-card__header hst-card__header--row hst-section-card__header">
                        <h3 data-hst-exam-paper-inline-title>پیش‌نمایش نمونه سوال</h3>
                        <span class="hst-muted" data-hst-exam-paper-inline-subtitle></span>
                    </div>
                    <div class="hst-card__body hst-section-card__body">
                        <div data-hst-exam-paper-preview-loading hidden><?php echo hst_loading_state(); ?></div>
                        <div class="hst-stack" data-hst-exam-paper-inline-preview></div>
                        <div class="hst-list-pagination" data-hst-exam-paper-preview-pagination hidden>
                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-page-prev">قبلی</button>
                            <div class="hst-page-numbers" aria-live="polite"></div>
                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-page-next">بعدی</button>
                        </div>
                    </div>
                </div>
                <div class="hst-question-bank__footer">
                    <div class="hst-btn-group">
                        <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" data-hst-question-final-back>مرحله قبل</button>
                        <button type="button" class="hst-btn hst-btn--sm" data-hst-question-final-submit>ثبت نهایی آزمون</button>
                    </div>
                </div>
            </section>

            <div class="hst-modal hst-question-editor-modal" id="hst-question-editor-modal" data-hst-modal-size="xl" aria-hidden="true" hidden>
                <div class="hst-modal__backdrop" data-hst-question-close></div>
                <div class="hst-modal__panel" role="dialog" aria-modal="true" aria-labelledby="hst-question-editor-title">
                    <div class="hst-modal__header">
                        <div class="hst-modal__title"><h3 id="hst-question-editor-title">طراحی و افزودن سؤال جدید به بانک سؤالات</h3></div>
                        <button type="button" class="hst-modal__close" data-hst-question-close aria-label="بستن">×</button>
                    </div>
                    <form id="hst-question-editor-form">
                        <div class="hst-modal__body">
                            <input type="hidden" name="id" value="">
                            <div class="hst-question-editor__grid hst-question-editor__grid--profile">
                                <label class="hst-field"><span>پایه تحصیلی</span><select name="grade" required><option value="">انتخاب پایه</option><?php foreach ($exam_builder_grades as $grade_key => $grade_label) : ?><option value="<?php echo esc_attr($grade_key); ?>"><?php echo esc_html($grade_label); ?></option><?php endforeach; ?></select></label>
                                <label class="hst-field"><span>رشته تحصیلی</span><select name="major" required><option value="">انتخاب رشته</option><?php foreach ($exam_builder_majors as $major_key => $major_label) : ?><option value="<?php echo esc_attr($major_key); ?>"><?php echo esc_html($major_label); ?></option><?php endforeach; ?></select></label>
                                <label class="hst-field"><span>نام درس</span><select name="lesson_id" required disabled><option value="">ابتدا پایه و رشته را انتخاب کنید</option><?php foreach ($question_bank_lessons as $lesson) : ?><option value="<?php echo esc_attr((int) $lesson['id']); ?>" data-grade="<?php echo esc_attr($lesson['grade']); ?>" data-major="<?php echo esc_attr($lesson['major']); ?>" data-lesson-name="<?php echo esc_attr($lesson['name']); ?>"><?php echo esc_html($lesson['name'] . ' — ' . $lesson['class_name']); ?></option><?php endforeach; ?></select></label>
                            </div>
                            <div class="hst-question-editor__grid hst-question-editor__grid--meta">
                                <label class="hst-field hst-question-editor__type"><span>قالب سؤال</span><select name="question_type" required><?php foreach ($question_bank_types as $type_key => $type_label) : ?><option value="<?php echo esc_attr($type_key); ?>"><?php echo esc_html($type_label); ?></option><?php endforeach; ?></select></label>
                                <label class="hst-field"><span>سطح سؤال</span><select name="difficulty" required><?php foreach ($question_bank_difficulties as $difficulty_key => $difficulty_label) : ?><option value="<?php echo esc_attr($difficulty_key); ?>"<?php echo $difficulty_key === 'medium' ? ' selected' : ''; ?>><?php echo esc_html($difficulty_label); ?></option><?php endforeach; ?></select></label>
                                <label class="hst-field"><span>بارم نمره</span><input type="number" name="score" value="1.5" min="0.25" max="100" step="0.25" required></label>
                            </div>
                            <div class="hst-field hst-question-editor__question-field">
                                <span>متن و صورت سؤال</span>
                                <div class="hst-question-editor">
                                    <div class="hst-question-editor__toolbar" role="toolbar" aria-label="ابزارهای ویرایش سؤال">
                                        <button type="button" data-hst-editor-command="bold" title="درشت"><b>B</b></button><button type="button" data-hst-editor-command="italic" title="مایل"><i>I</i></button><button type="button" data-hst-editor-command="underline" title="زیرخط"><u>U</u></button>
                                        <span aria-hidden="true"></span><button type="button" data-hst-editor-command="justifyRight" title="راست‌چین">☰</button><button type="button" data-hst-editor-command="justifyCenter" title="وسط‌چین">≡</button><button type="button" data-hst-editor-command="justifyLeft" title="چپ‌چین">☰</button><button type="button" data-hst-editor-command="insertUnorderedList" title="فهرست">☷</button>
                                        <span aria-hidden="true"></span><button type="button" data-hst-editor-media title="افزودن تصویر">▣</button><button type="button" data-hst-editor-formula title="درج فرمول"><i>f(x)</i></button><button type="button" data-hst-editor-table title="درج جدول">▦</button>
                                    </div>
                                    <div class="hst-question-table-tools" data-hst-question-table-tools hidden>
                                        <div class="hst-btn-group" data-hst-table-only>
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-table-action="add-row" title="افزودن سطر" aria-label="افزودن سطر">↕+</button>
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-table-action="remove-row" title="حذف سطر" aria-label="حذف سطر">↕−</button>
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-table-action="add-column" title="افزودن ستون" aria-label="افزودن ستون">↔+</button>
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-table-action="remove-column" title="حذف ستون" aria-label="حذف ستون">↔−</button>
                                        </div>
                                        <div class="hst-btn-group">
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-element-action="move-up" title="انتقال به بالا" aria-label="انتقال به بالا">↑</button>
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-element-action="move-down" title="انتقال به پایین" aria-label="انتقال به پایین">↓</button>
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-element-action="align-right" title="انتقال به راست" aria-label="انتقال به راست">→</button>
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-element-action="align-center" title="وسط‌چین" aria-label="وسط‌چین">↔</button>
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-element-action="align-left" title="انتقال به چپ" aria-label="انتقال به چپ">←</button>
                                        </div>
                                        <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon" data-hst-element-action="delete" title="حذف عنصر" aria-label="حذف عنصر"><?php echo hst_icon('delete'); ?></button>
                                    </div>
                                    <div class="hst-question-editor__surface" contenteditable="true" data-hst-question-editor data-placeholder="متن سؤال را اینجا به زبان شیرین فارسی وارد کنید…" role="textbox" aria-multiline="true"></div>
                                    <textarea name="question_text" hidden></textarea>
                                </div>
                                <section class="hst-formula-builder" data-hst-formula-builder hidden>
                                    <div class="hst-formula-builder__head">
                                        <strong>فرمول‌نویس ریاضی هوشمند</strong>
                                        <button type="button" data-hst-formula-close>× بستن</button>
                                    </div>
                                    <div class="hst-formula-builder__tabs" role="tablist" aria-label="دسته‌بندی فرمول‌ها">
                                        <button type="button" class="is-active" data-hst-formula-tab="basic">پایه و کسرات</button>
                                        <button type="button" data-hst-formula-tab="greek">حروف یونانی</button>
                                        <button type="button" data-hst-formula-tab="advanced">دیفرانسیل و مجموعه‌ها</button>
                                    </div>
                                    <div class="hst-formula-builder__symbols" data-hst-formula-symbols="basic">
                                        <button type="button" data-latex="\\frac{1}{2}">½</button><button type="button" data-latex="\\frac{3}{4}">¾</button><button type="button" data-latex="\\div">÷</button><button type="button" data-latex="\\times">×</button><button type="button" data-latex="\\sqrt{x}">√x</button><button type="button" data-latex="x^{2}">x²</button><button type="button" data-latex="x^{3}">x³</button><button type="button" data-latex="x^{n}">xⁿ</button><button type="button" data-latex="x_{i}">xᵢ</button><button type="button" data-latex="\\pm">±</button><button type="button" data-latex="\\neq">≠</button><button type="button" data-latex="\\approx">≈</button>
                                    </div>
                                    <div class="hst-formula-builder__symbols" data-hst-formula-symbols="greek" hidden>
                                        <button type="button" data-latex="\\alpha">α</button><button type="button" data-latex="\\beta">β</button><button type="button" data-latex="\\gamma">γ</button><button type="button" data-latex="\\Delta">Δ</button><button type="button" data-latex="\\theta">θ</button><button type="button" data-latex="\\lambda">λ</button><button type="button" data-latex="\\mu">μ</button><button type="button" data-latex="\\pi">π</button><button type="button" data-latex="\\rho">ρ</button><button type="button" data-latex="\\sigma">σ</button><button type="button" data-latex="\\phi">φ</button><button type="button" data-latex="\\omega">ω</button>
                                    </div>
                                    <div class="hst-formula-builder__symbols" data-hst-formula-symbols="advanced" hidden>
                                        <button type="button" data-latex="\\sum_{i=1}^{n}">Σ</button><button type="button" data-latex="\\prod_{i=1}^{n}">∏</button><button type="button" data-latex="\\int_{a}^{b}">∫</button><button type="button" data-latex="\\lim_{x \\to a}">lim</button><button type="button" data-latex="\\frac{d}{dx}">d/dx</button><button type="button" data-latex="\\partial">∂</button><button type="button" data-latex="\\in">∈</button><button type="button" data-latex="\\notin">∉</button><button type="button" data-latex="\\subset">⊂</button><button type="button" data-latex="\\cup">∪</button><button type="button" data-latex="\\cap">∩</button><button type="button" data-latex="\\infty">∞</button>
                                    </div>
                                    <div class="hst-formula-builder__fields">
                                        <label><span>کد فرمول (LaTeX)</span><input type="text" dir="ltr" data-hst-formula-input placeholder="e.g. \\frac{a}{b} or \\sqrt{x}"></label>
                                        <div><span>پیش‌نمایش زنده</span><output dir="ltr" data-hst-formula-preview>فرمول اینجا نمایش داده می‌شود</output></div>
                                    </div>
                                    <button type="button" class="hst-btn" data-hst-formula-insert>درج فرمول در متن</button>
                                </section>
                            </div>
                            <div data-hst-question-answer-fields></div>
                        </div>
                        <div class="hst-modal__footer">
                            <button type="button" class="hst-btn hst-btn--ghost" data-hst-question-close>انصراف</button>
                            <button type="submit" class="hst-btn" data-hst-question-submit>افزودن به بانک سؤالات</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="hst-modal" id="hst-question-transfer-modal" data-hst-modal-size="md" aria-hidden="true" hidden>
                <div class="hst-modal__backdrop" data-hst-question-transfer-close></div>
                <div class="hst-modal__panel" role="dialog" aria-modal="true" aria-labelledby="hst-question-transfer-title">
                    <div class="hst-modal__header"><div class="hst-modal__title"><h3 id="hst-question-transfer-title">انتقال سؤالات انتخابی به آزمون</h3></div><button type="button" class="hst-modal__close" data-hst-question-transfer-close aria-label="بستن">×</button></div>
                    <form id="hst-question-transfer-form">
                        <div class="hst-modal__body">
                            <p class="hst-alert hst-alert--info" data-hst-question-transfer-summary></p>
                            <label class="hst-field" data-hst-question-transfer-field<?php echo empty($question_bank_exams) ? ' hidden' : ''; ?>><span>آزمون مقصد</span><select name="exam_id" required<?php echo empty($question_bank_exams) ? ' disabled' : ''; ?>><option value="">انتخاب آزمون</option><?php foreach ($question_bank_exams as $exam) : ?><option
                                value="<?php echo esc_attr((int) $exam['id']); ?>"
                                data-title="<?php echo esc_attr((string) $exam['title']); ?>"
                                data-lesson-id="<?php echo esc_attr((int) $exam['lesson_id']); ?>"
                                data-class-id="<?php echo esc_attr((int) $exam['class_id']); ?>"
                                data-lesson-name="<?php echo esc_attr((string) $exam['lesson_name']); ?>"
                                data-class-name="<?php echo esc_attr((string) $exam['class_name']); ?>"
                                data-exam-date="<?php echo esc_attr($format_exam_date((string) $exam['exam_date'])); ?>"
                                data-duration="<?php echo esc_attr((int) ($exam['duration_minutes'] ?? 0)); ?>"
                                data-teacher-name="<?php echo esc_attr((string) ($exam['teacher_name'] ?? 'مدیر سامانه')); ?>"
                                data-grade="<?php echo esc_attr((string) ($exam['grade'] ?? '')); ?>"
                                data-major="<?php echo esc_attr((string) ($exam['major'] ?? '')); ?>"
                                data-exam-type="<?php echo esc_attr((string) ($exam['exam_type'] ?? '')); ?>"
                            ><?php echo esc_html($exam['title'] . ' — ' . $exam['lesson_name'] . ' — ' . $exam['class_name']); ?></option><?php endforeach; ?></select></label>
                        </div>
                        <div class="hst-modal__footer"><button type="button" class="hst-btn hst-btn--ghost" data-hst-question-transfer-close>انصراف</button><button type="submit" class="hst-btn" data-hst-question-transfer-submit<?php echo empty($question_bank_exams) ? ' hidden disabled' : ''; ?>>انتخاب آزمون و ادامه</button></div>
                    </form>
                </div>
            </div>

        </div>

        <div data-hst-exam-section-panel="reports"<?php echo $exam_section === 'reports' ? '' : ' hidden'; ?>>
            <div class="hst-card hst-section-card">
                <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html($exam_management_sections['reports']['title']); ?></h3></div>
                <div class="hst-card__body hst-section-card__body"><p class="hst-alert hst-empty-state">ساختار این بخش آماده شده است و امکانات مدیریتی آن در مرحله بعد تکمیل می‌شود.</p></div>
            </div>
        </div>

        <div data-hst-exam-section-panel="settings"<?php echo $exam_section === 'settings' ? '' : ' hidden'; ?>>
            <div class="hst-card">
                <div class="hst-card__header">
                    <div>
                        <h3>تنظیمات و پارامترهای عمومی آزمون</h3>
                        <p>مدیریت قواعد عمومی اجرای آزمون غیرحضوری و پیش‌فرض‌های ساخت آزمون</p>
                    </div>
                </div>
                <div class="hst-card__body">
                    <form class="hst-form" id="hst-exam-general-settings-form">
                        <div class="hst-form__row">
                            <div class="hst-field">
                                <span>نمره منفی برای پاسخ‌های غلط</span>
                                <small class="hst-muted">کسر یک‌سوم بارم سؤال برای پاسخ نادرست در سؤال‌های تستی آزمون غیرحضوری</small>
                                <label class="hst-switch" aria-label="فعال‌سازی نمره منفی">
                                    <input type="checkbox" name="negative_marking" value="1" <?php checked(!empty($exam_general_settings['negative_marking'])); ?>>
                                    <span class="hst-switch__slider"></span>
                                </label>
                            </div>

                            <div class="hst-field">
                                <span>تصحیح خودکار هوشمند آزمون‌ها</span>
                                <small class="hst-muted">محاسبه خودکار نمره سؤال‌های دارای پاسخ قطعی؛ سؤال تشریحی برای بررسی دبیر باقی می‌ماند</small>
                                <label class="hst-switch" aria-label="فعال‌سازی تصحیح خودکار">
                                    <input type="checkbox" name="auto_grading" value="1" <?php checked(!empty($exam_general_settings['auto_grading'])); ?>>
                                    <span class="hst-switch__slider"></span>
                                </label>
                            </div>

                            <div class="hst-field">
                                <span>نمایش فوری نتیجه به‌صورت پیش‌فرض</span>
                                <small class="hst-muted">انتخاب خودکار «بلافاصله پس از ثبت پاسخ» هنگام ساخت آزمون غیرحضوری جدید</small>
                                <label class="hst-switch" aria-label="فعال‌سازی نمایش فوری نتایج">
                                    <input type="checkbox" name="instant_results" value="1" <?php checked(!empty($exam_general_settings['instant_results'])); ?>>
                                    <span class="hst-switch__slider"></span>
                                </label>
                            </div>

                            <div class="hst-field">
                                <span>محدودیت سخت‌گیرانه زمان آزمون</span>
                                <small class="hst-muted">قفل پاسخ‌ها و پایان اجباری آزمون غیرحضوری بلافاصله پس از اتمام زمان</small>
                                <label class="hst-switch" aria-label="فعال‌سازی محدودیت سخت‌گیرانه زمان">
                                    <input type="checkbox" name="strict_time_limit" value="1" <?php checked(!empty($exam_general_settings['strict_time_limit'])); ?>>
                                    <span class="hst-switch__slider"></span>
                                </label>
                            </div>

                            <label class="hst-field">
                                <span>پیش‌فرض دفعات مجاز شرکت</span>
                                <small class="hst-muted">مقدار اولیه تعداد دفعات مجاز هنگام ساخت آزمون غیرحضوری جدید</small>
                                <input type="number" name="max_attempts" min="1" max="10" value="<?php echo esc_attr((int) ($exam_general_settings['max_attempts'] ?? 1)); ?>">
                            </label>
                        </div>

                        <div class="hst-btn-group">
                            <button type="submit" class="hst-btn hst-btn--primary" data-hst-exam-settings-submit>ذخیره تغییرات</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php endif; ?>

</section>

<script>
window.HST_EXAM_LESSONS = <?php echo wp_json_encode($teacher_lessons_by_class); ?>;
</script>
</div>
