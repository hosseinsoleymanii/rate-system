<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-icons.php';
include_once HST_PATH . 'templates/user/common/hst-user-avatar.php';

$audit_context = is_array($audit_context ?? null) ? $audit_context : [];
$active_term = $audit_context['active_term'] ?? null;
$periods = $audit_context['periods'] ?? ($audit_context['months'] ?? []);
$selected_period = $audit_context['selected_period'] ?? ($audit_context['selected_month'] ?? '');
$filters = $audit_context['filters'] ?? ['teacher_id' => 0, 'teacher_search' => '', 'class_id' => 0, 'lesson_id' => 0, 'status' => ''];
$selected_period_label = (string) ($audit_context['selected_period_label'] ?? '');
$requires_period = !empty($audit_context['requires_period']);
$summary = $audit_context['summary'] ?? ['total' => 0, 'registered' => 0, 'remaining' => 0, 'no_students' => 0];
$rows = $audit_context['rows'] ?? [];

if (!function_exists('hst_score_audit_status_label')) {
    function hst_score_audit_status_label($status) {
        $labels = [
            'registered'  => 'ثبت شده',
            'remaining'   => 'مانده',
            'complete'    => 'ثبت شده',
            'partial'     => 'مانده',
            'missing'     => 'مانده',
            'no_students' => 'بدون دانش‌آموز',
        ];

        return $labels[$status] ?? 'نامشخص';
    }
}

if (!function_exists('hst_score_audit_status_class')) {
    function hst_score_audit_status_class($status) {
        $classes = [
            'registered'  => 'success',
            'remaining'   => 'warning',
            'complete'    => 'success',
            'partial'     => 'warning',
            'missing'     => 'warning',
            'no_students' => 'muted',
        ];

        return $classes[$status] ?? 'muted';
    }
}
?>
<section class="hst-page hst-score-audit-page hst-management-page hst-module hst-module--score-audit">
    <div class="hst-inline-filter" data-hst-inline-filter="hst-score-audit-list">
<div class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header">
            <h3>مدیریت ثبت نمره</h3>
            <?php if ($selected_period_label !== '') : ?>
                <span class="hst-status hst-status--info"><?php echo esc_html($selected_period_label); ?></span>
            <?php endif; ?>
        </div>
        <div class="hst-card__body hst-section-card__body">
            <?php if (!$active_term) : ?>
                <p class="hst-alert hst-empty-state">برای ثبت نمره ابتدا یک سال تحصیلی فعال تعریف کنید.</p>
            <?php elseif ($requires_period || $selected_period === '') : ?>
                <p class="hst-alert hst-empty-state">برای ثبت نمره، از صفحه دوره‌ها روی دکمه آیکنی ثبت نمره همان دوره کلیک کنید.</p>
            <?php else : ?>
                <div class="hst-stack">
<div class="hst-inline-filter__add">
                        <button type="button" class="hst-btn hst-btn--primary hst-btn--sm" title="ثبت نمره انفرادی" aria-label="ثبت نمره انفرادی">
                            <span class="hst-btn__icon" aria-hidden="true"><?php echo hst_icon('edit'); ?></span>
                            <span>ثبت نمره انفرادی</span>
                        </button>
                        <button type="button" class="hst-btn hst-btn--soft hst-btn--sm" title="گزارش کلی" aria-label="گزارش کلی">
                            <span class="hst-btn__icon" aria-hidden="true"><?php echo hst_icon('report'); ?></span>
                            <span>گزارش کلی</span>
                        </button>
                        <button
                            type="button"
                            class="hst-btn hst-btn--soft hst-btn--sm"
                            data-hst-score-audit-export-excel
                            data-period-key="<?php echo esc_attr($selected_period); ?>"
                            <?php disabled(empty($rows)); ?>
                            title="<?php echo esc_attr(empty($rows) ? 'داده‌ای برای خروجی اکسل وجود ندارد.' : 'خروجی اکسل'); ?>"
                            aria-label="<?php echo esc_attr(empty($rows) ? 'داده‌ای برای خروجی اکسل وجود ندارد.' : 'خروجی اکسل'); ?>"
                        >
                            <span class="hst-btn__icon" aria-hidden="true"><?php echo hst_icon('excel'); ?></span>
                            <span>خروجی اکسل</span>
                        </button>
                    </div>


                    

                    <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/periods')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($active_term && !$requires_period && $selected_period !== '') : ?>
        <div class="hst-card hst-section-card">
            <div class="hst-card__body hst-section-card__body">
<div class="hst-inline-filter__main">
                        <div class="hst-inline-filter__search">
                            <span class="hst-inline-filter__search-icon" aria-hidden="true"><?php echo hst_icon('teachers'); ?></span>
                            <input
                                type="search"
                                class="hst-search"
                                value="<?php echo esc_attr((string) ($filters['teacher_search'] ?? '')); ?>"
                                placeholder="جست‌وجوی معلم یا مدیر..."
                                autocomplete="off"
                                data-hst-inline-search
                            >
                        </div>

                        <select class="hst-inline-filter__select" data-hst-inline-select="status" data-hst-segmented-label="none" aria-label="فیلتر وضعیت ثبت نمره">
                            <option value="">همه وضعیت‌ها</option>
                            <option value="registered" <?php selected(($filters['status'] ?? ''), 'registered'); ?>>ثبت شده</option>
                            <option value="remaining" <?php selected(($filters['status'] ?? ''), 'remaining'); ?>>مانده</option>
                        </select>
                    </div>
            </div>
        </div>
    <?php endif; ?>
</div>

    <?php if ($active_term && !$requires_period && $selected_period !== '') : ?>
        <div class="hst-card hst-section-card">
            <div class="hst-card__header hst-section-card__header">
                <h3>گزارش ثبت نمره</h3>
            </div>
            <div class="hst-card__body hst-section-card__body">
                <div class="hst-report-stats">
                    <div class="hst-report-stat hst-report-stat--total"><b><?php echo esc_html(number_format_i18n((int) ($summary['total'] ?? 0))); ?></b><span>کل</span></div>
                    <div class="hst-report-stat hst-report-stat--new"><b><?php echo esc_html(number_format_i18n((int) ($summary['registered'] ?? 0))); ?></b><span>ثبت شده</span></div>
                    <div class="hst-report-stat hst-report-stat--warning"><b><?php echo esc_html(number_format_i18n((int) ($summary['remaining'] ?? 0))); ?></b><span>مانده</span></div>
                    <div class="hst-report-stat hst-report-stat--upd"><b><?php echo esc_html(number_format_i18n((int) ($summary['no_students'] ?? 0))); ?></b><span>بدون دانش‌آموز</span></div>
                </div>
            </div>
        </div>

        <div class="hst-card hst-section-card">
            <div class="hst-card__header hst-section-card__header">
                <h3>لیست ثبت نمره</h3>
            </div>
            <div class="hst-card__body hst-section-card__body">
                <?php if (empty($rows)) : ?>
                    <p class="hst-alert hst-empty-state">برای این دوره و فیلترهای انتخاب‌شده موردی پیدا نشد.</p>
                <?php else : ?>
                    <div class="hst-table-wrap hst-data-table-wrap">
                        <table id="hst-score-audit-list" class="hst-table hst-data-table hst-score-audit-table">
                            <thead>
                                <tr>
                                    <th>ردیف</th>
                                    <th>مسئول ثبت</th>
                                    <th>کلاس</th>
                                    <th>درس</th>
                                    <th>نمره‌های مورد انتظار</th>
                                    <th>ثبت شده</th>
                                    <th>مانده</th>
                                    <th>پیشرفت</th>
                                    <th>وضعیت ثبت</th>
                                    <th>دسترسی</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $hst_row_i = 0; foreach ($rows as $row) : $hst_row_i++; ?>
                                    <?php $pct = min(100, max(0, (int) $row->completion_percent)); $hst_manager_only = !empty($row->manager_only); ?>
                                    <tr
                                        data-status="<?php echo esc_attr($row->status); ?>"
                                        data-hst-status="<?php echo esc_attr($row->status); ?>"
                                        data-hst-search="<?php echo esc_attr($row->teacher_name . ' ' . $row->class_name . ' ' . $row->lesson_name); ?>"
                                        data-teacher-id="<?php echo esc_attr((int) $row->teacher_id); ?>"
                                        data-class-id="<?php echo esc_attr((int) $row->class_id); ?>"
                                        data-lesson-id="<?php echo esc_attr((int) $row->lesson_id); ?>"
                                        data-period-key="<?php echo esc_attr($selected_period); ?>"
                                        data-period-label="<?php echo esc_attr($selected_period_label); ?>"
                                        data-teacher-name="<?php echo esc_attr($row->teacher_name); ?>"
                                        data-class-name="<?php echo esc_attr($row->class_name); ?>"
                                        data-lesson-name="<?php echo esc_attr($row->lesson_name); ?>"
                                        data-manager-only="<?php echo $hst_manager_only ? '1' : '0'; ?>"
                                    >
                                        <td class="hst-row-num"><?php echo esc_html(number_format_i18n($hst_row_i)); ?></td>
                                        <td><?php echo $hst_manager_only ? '<span class="hst-status hst-status--info">مدیر مدرسه</span>' : hst_user_cell((int) $row->teacher_id, $row->teacher_name); ?></td>
                                        <td><?php echo esc_html($row->class_name); ?></td>
                                        <td><?php echo esc_html($row->lesson_name); ?></td>
                                        <td class="hst-score-audit-expected"><?php echo esc_html(number_format_i18n((int) ($row->expected_students ?? 0))); ?></td>
                                        <td class="hst-score-audit-registered"><?php echo esc_html(number_format_i18n((int) ($row->registered_scores ?? 0))); ?></td>
                                        <td class="hst-score-audit-missing"><?php echo esc_html(number_format_i18n((int) ($row->missing_scores ?? 0))); ?></td>
                                        <td class="hst-score-audit-progress-cell">
                                            <span class="hst-progress" data-hst-progress data-status="<?php echo esc_attr($row->status); ?>">
                                                <span class="hst-progress__bar" style="width: <?php echo esc_attr((string) $pct); ?>%;"></span>
                                            </span>
                                            <small class="hst-muted"><?php echo esc_html(number_format_i18n((int) ($row->completion_percent ?? 0))); ?>٪</small>
                                        </td>
                                        <td>
                                            <span class="hst-status hst-score-audit-status hst-status--<?php echo esc_attr(hst_score_audit_status_class($row->status)); ?>">
                                                <?php echo esc_html(hst_score_audit_status_label($row->status)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $hst_access_status = (string) ($row->access_status ?? 'inactive');
                                            $hst_access_label = (string) ($row->access_label ?? 'دسترسی ثبت نمره');
                                            $hst_access_checked = !empty($row->access_checked);
                                            ?>
                                            <?php if ($hst_manager_only) : ?>
                                                <span class="hst-status hst-status--info">فقط مدیر</span>
                                            <?php else : ?>
                                                <label class="hst-switch" title="<?php echo esc_attr($hst_access_label); ?>" aria-label="<?php echo esc_attr($hst_access_label); ?>">
                                                    <input
                                                        type="checkbox"
                                                        class="hst-score-entry-access-toggle"
                                                        data-teacher-id="<?php echo esc_attr((int) $row->teacher_id); ?>"
                                                        data-class-id="<?php echo esc_attr((int) $row->class_id); ?>"
                                                        data-lesson-id="<?php echo esc_attr((int) $row->lesson_id); ?>"
                                                        data-period-key="<?php echo esc_attr($selected_period); ?>"
                                                        <?php checked($hst_access_checked); ?>
                                                    >
                                                    <span class="hst-switch__slider"></span>
                                                </label>
                                            <?php endif; ?>
                                        </td>
                                        <td class="hst-actions">
                                            <?php if ($hst_manager_only) : ?>
                                                <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" title="مشاهده نمرات معلم برای درس انضباط کاربرد ندارد" aria-label="مشاهده نمرات معلم برای درس انضباط غیرفعال است" disabled>
                                                    <?php echo hst_icon('view'); ?>
                                                </button>
                                            <?php else : ?>
                                                <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon hst-score-audit-view-scores" title="مشاهده نمرات ثبت شده توسط معلم" aria-label="مشاهده نمرات ثبت شده توسط معلم">
                                                    <?php echo hst_icon('view'); ?>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="hst-btn hst-btn--primary hst-btn--sm hst-score-audit-admin-scores" title="<?php echo esc_attr($hst_manager_only ? 'ثبت یا ویرایش نمره انضباط' : 'ثبت نمره توسط مدیر'); ?>" aria-label="<?php echo esc_attr($hst_manager_only ? 'ثبت یا ویرایش نمره انضباط' : 'ثبت نمره توسط مدیر'); ?>">
                                                <?php echo hst_icon('edit'); ?><span><?php echo esc_html($hst_manager_only ? 'نمره انضباط' : 'ثبت نمره'); ?></span>
                                            </button>
                                            <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-score-audit-security" title="گزارش امنیت و ممیزی نمرات" aria-label="گزارش امنیت و ممیزی نمرات">
                                                <?php echo hst_icon('security'); ?><span>گزارش ممیزی</span>
                                            </button>
                                            <?php
                                            if ($hst_manager_only) {
                                                $hst_reminder_enabled = false;
                                                $hst_reminder_label = 'درس انضباط معلم ندارد و ارسال یادآوری غیرفعال است';
                                            } else {
                                                $hst_reminder_enabled = (string) $row->status === 'remaining' && $hst_access_checked;
                                                if ((string) $row->status === 'registered') {
                                                    $hst_reminder_label = 'تمام نمرات این مورد ثبت شده است';
                                                } elseif ((string) $row->status === 'no_students') {
                                                    $hst_reminder_label = 'برای این کلاس و درس دانش‌آموزی وجود ندارد';
                                                } elseif (!$hst_access_checked) {
                                                    $hst_reminder_label = 'ابتدا دسترسی ثبت نمره دبیر را فعال کنید';
                                                } else {
                                                    $hst_reminder_label = 'ارسال یادآوری ثبت نمره به دبیر';
                                                }
                                            }
                                            ?>
                                            <button
                                                type="button"
                                                class="hst-btn hst-btn--soft hst-btn--sm"
                                                <?php if (!$hst_manager_only) : ?>data-hst-score-reminder<?php endif; ?>
                                                title="<?php echo esc_attr($hst_reminder_label); ?>"
                                                aria-label="<?php echo esc_attr($hst_reminder_label); ?>"
                                                <?php disabled(!$hst_reminder_enabled); ?>
                                            >
                                                <?php echo hst_icon('bell'); ?><span>ارسال یادآوری</span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="hst-muted hst-inline-filter__empty hst-empty-state hst-empty-state--inline" data-hst-inline-empty hidden>موردی با این فیلتر پیدا نشد.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="hst-modal" data-hst-modal-tone="detail" data-hst-modal-size="xl" id="hst-score-audit-scores-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="hst-score-audit-modal-title">
        <div class="hst-modal__backdrop" data-hst-score-audit-modal-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div class="hst-modal__title">
                    <span class="hst-modal__icon" aria-hidden="true"><?php echo hst_icon('scores'); ?></span>
                    <div>
                        <h3 id="hst-score-audit-modal-title" data-hst-score-audit-modal-title>نمرات دوره</h3>
                        <p data-hst-score-audit-modal-subtitle>مشاهده جزئیات نمرات ثبت‌شده توسط دبیر</p>
                    </div>
                </div>
                <div class="hst-modal__actions">
                    <div class="hst-score-bulk-entry" data-hst-score-bulk-entry hidden>
                        <label class="hst-visually-hidden" for="hst-score-bulk-slot">ستون نمره</label>
                        <select id="hst-score-bulk-slot" class="hst-select hst-score-bulk-entry__slot" data-hst-score-bulk-slot aria-label="انتخاب ستون نمره" hidden></select>
                        <label class="hst-visually-hidden" for="hst-score-bulk-value">نمره مشترک</label>
                        <input id="hst-score-bulk-value" type="number" inputmode="decimal" class="hst-input hst-score-bulk-entry__input" data-hst-score-bulk-value min="0" max="20" step="0.25" placeholder="نمره مشترک" autocomplete="off">
                        <button type="button" class="hst-btn hst-btn--primary hst-btn--sm hst-score-bulk-entry__apply" data-hst-score-bulk-apply>
                            اعمال
                        </button>
                    </div>
                    <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon" data-hst-score-audit-modal-edit title="ویرایش نمرات ثبت‌شده" aria-label="ویرایش نمرات ثبت‌شده" hidden>
                        <?php echo hst_icon('edit'); ?>
                    </button>
                    <button type="button" class="hst-modal__close" data-hst-score-audit-modal-close aria-label="بستن">&times;</button>
                </div>
            </div>
            <div class="hst-modal__body">
                <div class="hst-table-wrap hst-data-table-wrap">
                    <table class="hst-table hst-data-table hst-score-audit-table" data-hst-no-pagination="1">
                        <thead data-hst-score-audit-modal-head>
                            <tr>
                                <th>ردیف</th>
                                <th>نام دانش‌آموز</th>
                                <th>نام پدر</th>
                                <th>نمره دوره</th>
                                <th>حضور و غیاب</th>
                            </tr>
                        </thead>
                        <tbody data-hst-score-audit-modal-body>
                            <tr data-hst-no-pagination><td colspan="5" class="hst-table-empty"><?php echo hst_loading_state(); ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="hst-alert hst-empty-state" data-hst-score-audit-modal-empty hidden>دانش‌آموزی برای این کلاس و درس پیدا نشد.</p>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--primary" data-hst-score-audit-modal-save hidden>ذخیره تغییرات</button>
                <button type="button" class="hst-btn hst-btn--soft" data-hst-score-audit-modal-close>بستن</button>
            </div>
        </div>
    </div>

    <div class="hst-modal" data-hst-modal-tone="detail" data-hst-modal-size="lg" id="hst-score-audit-security-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="hst-score-audit-security-title">
        <div class="hst-modal__backdrop" data-hst-score-security-close></div>
        <div class="hst-modal__panel">
            <div class="hst-modal__header">
                <div class="hst-modal__title">
                    <span class="hst-modal__icon" aria-hidden="true"><?php echo hst_icon('security'); ?></span>
                    <div>
                        <h3 id="hst-score-audit-security-title">گزارش ممیزی و امنیت نمرات</h3>
                        <p>لاگ‌های امنیتی ثبت و ویرایش نمرات</p>
                    </div>
                </div>
                <button type="button" class="hst-modal__close" data-hst-score-security-close aria-label="بستن">&times;</button>
            </div>
            <div class="hst-modal__body" data-hst-score-security-body>
                <p class="hst-empty-state"><?php echo hst_loading_state(); ?></p>
            </div>
            <div class="hst-modal__footer">
                <button type="button" class="hst-btn hst-btn--soft" data-hst-score-security-close>بستن</button>
            </div>
        </div>
    </div>

</section>
