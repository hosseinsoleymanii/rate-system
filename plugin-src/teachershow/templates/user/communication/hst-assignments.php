<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';

$status_labels = [
    'draft' => 'پیش‌نویس',
    'published' => 'فعال',
    'closed' => 'بسته',
    'submitted' => 'ارسال شده',
    'reviewed' => 'بررسی شده',
    'needs_revision' => 'نیازمند اصلاح',
];

$format_date = static function ($date) {
    if (empty($date) || $date === '0000-00-00 00:00:00') {
        return 'بدون مهلت';
    }
    return esc_html(class_exists('HST_Date') ? HST_Date::format($date, 'Y/m/d H:i') : wp_date('Y/m/d H:i', strtotime($date)));
};
?>
<section class="hst-page hst-assignments-page" data-hst-assignments>
    <div class="hst-card">
        <div class="hst-card__header"><h3>تکالیف</h3></div>
        <div class="hst-card__body">
            <?php if (!$active_term) : ?>
                <p class="hst-alert">برای استفاده از تکالیف، ابتدا یک سال تحصیلی فعال تعریف کنید.</p>
            <?php else : ?>
                <p class="hst-alert hst-alert--info">سال تحصیلی فعال: <?php echo esc_html($active_term->term_name); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($active_term && ($is_teacher || current_user_can('manage_options') || current_user_can('hst_manage_school'))) : ?>
        <div class="hst-card hst-assignment-create-card">
            <div class="hst-card__header"><h3>تعریف تکلیف جدید</h3></div>
            <div class="hst-card__body">
                <?php if (empty($teacher_scope)) : ?>
                    <p class="hst-alert">برای شما کلاس/درسی در سال تحصیلی فعال ثبت نشده است.</p>
                <?php else : ?>
                    <form class="hst-form" id="hst-assignment-create-form">
                        <div class="hst-form__row">
                            <label class="hst-field">
                                <span>کلاس</span>
                                <select name="class_id" id="hst-assignment-class" required>
                                    <option value="">انتخاب کلاس</option>
                                    <?php
                                    $class_seen = [];
                                    foreach ($teacher_scope as $scope) :
                                        if (isset($class_seen[$scope->class_id])) {
                                            continue;
                                        }
                                        $class_seen[$scope->class_id] = true;
                                    ?>
                                        <option value="<?php echo esc_attr($scope->class_id); ?>"><?php echo esc_html($scope->class_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="hst-field">
                                <span>درس</span>
                                <select name="lesson_id" id="hst-assignment-lesson" required>
                                    <option value="">ابتدا کلاس را انتخاب کنید</option>
                                    <?php foreach ($teacher_scope as $scope) : ?>
                                        <option value="<?php echo esc_attr($scope->lesson_id); ?>" data-class="<?php echo esc_attr($scope->class_id); ?>">
                                            <?php echo esc_html($scope->lesson_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <label class="hst-field">
                            <span>عنوان تکلیف</span>
                            <input type="text" name="title" placeholder="عنوان تکلیف" required>
                        </label>
                        <label class="hst-field">
                            <span>توضیحات تکلیف</span>
                            <textarea name="description" rows="4" placeholder="توضیح تکلیف، نکات لازم، معیار بررسی و شیوه ارسال فایل"></textarea>
                        </label>
                        <div class="hst-form__row">
                            <label class="hst-field">
                                <span>مهلت ارسال</span>
                                <input type="text" name="due_at" class="hst-jalali-datetime" data-hst-time-title="انتخاب ساعت مهلت ارسال" placeholder="مثلاً ۱۴۰۳/۰۸/۱۵ ۱۴:۳۰" inputmode="numeric">
                            </label>
                            <label class="hst-field">
                                <span>حداکثر حجم فایل MB</span>
                                <input type="number" name="max_file_size" min="1" max="50" value="10">
                            </label>
                            <label class="hst-field">
                                <span>فرمت‌های مجاز</span>
                                <input type="text" name="allowed_types" value="pdf,doc,docx,ppt,pptx,jpg,jpeg,png,zip">
                            </label>
                        </div>
                        <input type="hidden" name="status" value="published">
                        <button type="submit" class="hst-btn">ثبت تکلیف</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="hst-card">
            <div class="hst-card__header"><h3>تکالیف تعریف‌شده من</h3></div>
            <div class="hst-card__body">
                <?php if (empty($teacher_assignments)) : ?>
                    <p class="hst-alert">هنوز تکلیفی تعریف نکرده‌اید.</p>
                <?php else : ?>
                    <div class="hst-stack">
                        <?php foreach ($teacher_assignments as $assignment) : ?>
                            <?php $submissions = class_exists('HST_Assignments') ? HST_Assignments::assignment_submissions((int) $assignment->id) : []; ?>
                            <article class="hst-assignment-item" data-assignment="<?php echo esc_attr($assignment->id); ?>">
                                <div class="hst-assignment__head">
                                    <div>
                                        <h4><?php echo esc_html($assignment->title); ?></h4>
                                        <p class="hst-muted"><?php echo esc_html($assignment->class_name . ' - ' . $assignment->lesson_name); ?></p>
                                    </div>
                                    <span class="hst-status hst-status--<?php echo esc_attr($assignment->status); ?>"><?php echo esc_html($status_labels[$assignment->status] ?? $assignment->status); ?></span>
                                </div>
                                <?php if (!empty($assignment->description)) : ?>
                                    <div class="hst-assignment__desc"><?php echo wp_kses_post(wpautop($assignment->description)); ?></div>
                                <?php endif; ?>
                                <div class="hst-assignment__meta">
                                    <span>مهلت: <?php echo $format_date($assignment->due_at); ?></span>
                                    <span>دانش‌آموزان: <?php echo esc_html((int) $assignment->student_count); ?></span>
                                    <span>ارسال‌شده: <?php echo esc_html((int) $assignment->submitted_count); ?></span>
                                    <span>بررسی‌شده: <?php echo esc_html((int) $assignment->reviewed_count); ?></span>
                                </div>
                                <div class="hst-btn-group">
                                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-assignment-toggle-submissions">مشاهده ارسال‌ها</button>
                                    <button type="button" class="hst-btn hst-btn--ghost hst-btn--sm hst-assignment-status" data-status="<?php echo $assignment->status === 'closed' ? 'published' : 'closed'; ?>">
                                        <?php echo $assignment->status === 'closed' ? 'فعال کردن' : 'بستن تکلیف'; ?>
                                    </button>
                                    <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-assignment-delete">حذف</button>
                                </div>
                                <div class="hst-assignment-submissions" hidden>
                                    <?php if (empty($submissions)) : ?>
                                        <p class="hst-alert">دانش‌آموزی برای این تکلیف پیدا نشد.</p>
                                    <?php else : ?>
                                        <div class="hst-table-wrap">
                                            <table class="hst-table">
                                                <thead>
                                                    <tr>
                                                        <th>ردیف</th>
                                                        <th>دانش‌آموز</th>
                                                        <th>وضعیت</th>
                                                        <th>فایل</th>
                                                        <th>نمره/نظر</th>
                                                        <th>عملیات</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php $hst_row_i = 0; foreach ($submissions as $sub) : $hst_row_i++; ?>
                                                        <tr>
                                                            <td class="hst-row-num"><?php echo esc_html(number_format_i18n($hst_row_i)); ?></td>
                                                            <td><?php echo esc_html($sub->student_name); ?></td>
                                                            <td><?php echo esc_html($sub->submission_id ? ($status_labels[$sub->status] ?? $sub->status) : 'ارسال نشده'); ?></td>
                                                            <td>
                                                                <?php if ($sub->file_url) : ?>
                                                                    <a class="hst-link" href="<?php echo esc_url($sub->file_url); ?>" target="_blank" rel="noopener">دانلود فایل</a>
                                                                    <small><?php echo esc_html($sub->original_name); ?></small>
                                                                <?php else : ?>
                                                                    <span class="hst-muted">—</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($sub->submission_id) : ?>
                                                                    <strong><?php echo $sub->score !== null ? esc_html(hst_format_grade($sub->score)) : 'بدون نمره'; ?></strong>
                                                                    <?php if ($sub->teacher_note) : ?><p class="hst-muted"><?php echo esc_html($sub->teacher_note); ?></p><?php endif; ?>
                                                                <?php else : ?>
                                                                    <span class="hst-muted">—</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($sub->submission_id) : ?>
                                                                    <form class="hst-form hst-assignment-review-form" data-submission="<?php echo esc_attr($sub->submission_id); ?>">
                                                                        <input type="number" name="score" min="0" max="20" step="0.25" value="<?php echo esc_attr($sub->score === null ? '' : hst_format_grade($sub->score, false)); ?>" placeholder="نمره">
                                                                        <select name="status">
                                                                            <option value="reviewed" <?php selected($sub->status, 'reviewed'); ?>>بررسی شد</option>
                                                                            <option value="needs_revision" <?php selected($sub->status, 'needs_revision'); ?>>نیازمند اصلاح</option>
                                                                        </select>
                                                                        <textarea name="teacher_note" rows="2" placeholder="نظر معلم"><?php echo esc_textarea($sub->teacher_note); ?></textarea>
                                                                        <button type="submit" class="hst-btn hst-btn--sm">ذخیره بررسی</button>
                                                                    </form>
                                                                <?php else : ?>
                                                                    <span class="hst-muted">در انتظار ارسال</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($active_term && $is_student) : ?>
        <div class="hst-card">
            <div class="hst-card__header"><h3>تکالیف من</h3></div>
            <div class="hst-card__body">
                <?php if (empty($student_assignments)) : ?>
                    <p class="hst-alert">فعلاً تکلیفی برای شما ثبت نشده است.</p>
                <?php else : ?>
                    <div class="hst-stack">
                        <?php foreach ($student_assignments as $assignment) : ?>
                            <article class="hst-assignment-item" data-assignment="<?php echo esc_attr($assignment->id); ?>">
                                <div class="hst-assignment__head">
                                    <div>
                                        <h4><?php echo esc_html($assignment->title); ?></h4>
                                        <p class="hst-muted"><?php echo esc_html($assignment->class_name . ' - ' . $assignment->lesson_name . ' | ' . $assignment->teacher_name); ?></p>
                                    </div>
                                    <span class="hst-status <?php echo $assignment->submission_id ? 'hst-status--submitted' : 'hst-status--pending'; ?>">
                                        <?php echo $assignment->submission_id ? esc_html($status_labels[$assignment->submission_status] ?? 'ارسال شده') : 'ارسال نشده'; ?>
                                    </span>
                                </div>
                                <?php if (!empty($assignment->description)) : ?>
                                    <div class="hst-assignment__desc"><?php echo wp_kses_post(wpautop($assignment->description)); ?></div>
                                <?php endif; ?>
                                <div class="hst-assignment__meta">
                                    <span>مهلت: <?php echo $format_date($assignment->due_at); ?></span>
                                    <?php if ($assignment->score !== null) : ?><span>نمره: <?php echo esc_html(hst_format_grade($assignment->score)); ?></span><?php endif; ?>
                                </div>
                                <?php if ($assignment->file_url) : ?>
                                    <p class="hst-alert hst-alert--success">فایل ارسال‌شده: <a class="hst-link" href="<?php echo esc_url($assignment->file_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($assignment->original_name); ?></a></p>
                                <?php endif; ?>
                                <?php if ($assignment->teacher_note) : ?>
                                    <p class="hst-assignment__feedback">نظر معلم: <?php echo esc_html($assignment->teacher_note); ?></p>
                                <?php endif; ?>
                                <?php
                                $hst_is_reviewed = ($assignment->submission_status ?? '') === 'reviewed'
                                    || ($assignment->score !== null && $assignment->score !== '');
                                ?>
                                <?php if ($hst_is_reviewed) : ?>
                                    <p class="hst-alert hst-alert--success">این تکلیف توسط معلم بررسی و نمره‌دهی شده است؛ امکان ارسال مجدد وجود ندارد.</p>
                                <?php elseif ($assignment->status === 'published') : ?>
                                    <form class="hst-form hst-assignment-submit-form" enctype="multipart/form-data" data-assignment="<?php echo esc_attr($assignment->id); ?>">
                                        <label class="hst-file-drop">
                                            <span class="hst-file-drop__icon" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M5 16v2a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-2"/></svg>
                                            </span>
                                            <span class="hst-file-drop__text">
                                                <strong>برای انتخاب فایل کلیک کنید</strong>
                                                <small data-hst-file-name>هیچ فایلی انتخاب نشده است</small>
                                            </span>
                                            <input type="file" name="assignment_file" required data-hst-file-input>
                                        </label>
                                        <div class="hst-assignment-submit-actions">
                                            <button type="submit" class="hst-btn"><?php echo $assignment->submission_id ? 'ارسال نسخه جدید' : 'ارسال پاسخ'; ?></button>
                                            <small class="hst-muted">فرمت‌های مجاز: <?php echo esc_html($assignment->allowed_types); ?> | حداکثر حجم: <?php echo esc_html($assignment->max_file_size); ?>MB</small>
                                        </div>
                                    </form>
                                <?php else : ?>
                                    <p class="hst-alert">مهلت ارسال این تکلیف بسته شده است.</p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
</div>
