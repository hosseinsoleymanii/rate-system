<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-icons.php';
include_once HST_PATH . 'templates/user/common/hst-list-filters.php';
include_once HST_PATH . 'templates/user/common/hst-user-avatar.php';

$is_teacher_view = (current_user_can('hst_teach') || current_user_can('teacher')) && !current_user_can('manage_options') && !current_user_can('hst_manage_school');
$hst_student_add_title = $active_term_id
    ? 'افزودن دانش‌آموز'
    : 'ابتدا یک سال تحصیلی فعال تعریف کنید.';

$class_options  = hst_filter_options_from_items($students, 'classes');
$lesson_options = hst_filter_options_from_items($students, 'lessons');
?>
<section class="hst-page hst-management-page hst-module hst-module--students">
    <?php if (!$is_teacher_view) : ?>
    <div class="hst-inline-filter" data-hst-inline-filter="hst-students-table">
<div class="hst-card hst-section-card hst-management-card">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo esc_html(HST_Settings::management_page_title('students', 'دانش‌آموزان')); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <div class="hst-stack">
<div class="hst-inline-filter__add">
                    <button type="button" class="hst-btn--icon hst-btn hst-btn--primary hst-btn--sm" id="hst-student-add" <?php disabled(!$active_term_id); ?> title="<?php echo esc_attr($hst_student_add_title); ?>" aria-label="<?php echo esc_attr($hst_student_add_title); ?>"><?php echo hst_icon('add'); ?><span>افزودن دانش‌آموز</span></button>
                </div>
                
                <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
            </div>
        </div>
    </div>

    <div class="hst-card hst-section-card">
        <div class="hst-card__body hst-section-card__body">
<div class="hst-inline-filter__main">
                    <div class="hst-inline-filter__search">
                        <span class="hst-inline-filter__search-icon" aria-hidden="true"><?php echo hst_icon('students'); ?></span>
                        <input type="search" class="hst-search" data-hst-inline-search placeholder="جست‌وجوی دانش‌آموز..." autocomplete="off">
                    </div>
                    <?php if (!empty($class_options)) : ?>
                        <select class="hst-inline-filter__select" data-hst-inline-select="class" aria-label="فیلتر کلاس">
                            <option value="">همه کلاس‌ها</option>
                            <?php foreach ($class_options as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <?php if (!empty($lesson_options)) : ?>
                        <select class="hst-inline-filter__select" data-hst-inline-select="lesson" aria-label="فیلتر درس">
                            <option value="">همه درس‌ها</option>
                            <?php foreach ($lesson_options as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
        </div>
    </div>
</div>
    <?php endif; ?>

    <div class="hst-card hst-section-card">
        <div class="hst-card__header hst-section-card__header"><h3><?php echo $is_teacher_view ? esc_html__('دانش‌آموزان من', 'teacher-show') : esc_html__('لیست دانش‌آموزان', 'teacher-show'); ?></h3></div>
        <div class="hst-card__body hst-section-card__body">
            <?php if (!$is_teacher_view && !$active_term_id) : ?>
                <p class="hst-alert hst-alert--warning hst-empty-state">برای افزودن دانش‌آموز ابتدا یک سال تحصیلی فعال تعریف کنید.</p>
            <?php endif; ?>
            <?php if (!empty($students)) : ?>
                <?php if ($is_teacher_view) : ?>
                    <div class="hst-inline-filter" data-hst-inline-filter="hst-students-table">
                        <div class="hst-inline-filter__main">
                            <div class="hst-inline-filter__search">
                                <span class="hst-inline-filter__search-icon" aria-hidden="true"><?php echo hst_icon('students'); ?></span>
                                <input type="search" class="hst-search" data-hst-inline-search placeholder="جست‌وجوی دانش‌آموز..." autocomplete="off">
                            </div>
                            <?php if (!empty($class_options)) : ?>
                                <select class="hst-inline-filter__select" data-hst-inline-select="class" aria-label="فیلتر کلاس">
                                    <option value="">همه کلاس‌ها</option>
                                    <?php foreach ($class_options as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <?php if (!empty($lesson_options)) : ?>
                                <select class="hst-inline-filter__select" data-hst-inline-select="lesson" aria-label="فیلتر درس">
                                    <option value="">همه درس‌ها</option>
                                    <?php foreach ($lesson_options as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="hst-btn--icon hst-btn hst-btn--soft hst-btn--sm hst-inline-filter__back" data-hst-back data-hst-fallback="<?php echo esc_url(home_url('/dashboard')); ?>" title="بازگشت" aria-label="بازگشت"><?php echo hst_icon('back'); ?><span>بازگشت</span></button>
                    </div>
                <?php endif; ?>
                <div class="hst-table-wrap hst-data-table-wrap hst-data-table">
                    <table class="hst-table hst-data-table" id="hst-students-table">
                        <thead>
                            <tr>
                                <th>ردیف</th>
                                <th class="hst-col-fill">نام دانش‌آموز</th>
                                <?php if (!$is_teacher_view) : ?>
                                    <th>کد ملی</th>
                                    <th>نام پدر</th>
                                    <th>شماره موبایل</th>
                                    <th>تاریخ تولد</th>
                                    <th>عملیات</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $index => $student) :
                                $s_national = get_user_meta($student->ID, 'hst_national_code', true);
                                $s_father = get_user_meta($student->ID, 'hst_father_name', true);
                                $s_birthdate = get_user_meta($student->ID, 'hst_birthdate', true);
                                $s_phone = class_exists('HST_User_Phones') ? HST_User_Phones::get((int) $student->ID) : get_user_meta($student->ID, 'phone', true);
                                $hst_can_delete = (int) $student->ID !== (int) get_current_user_id();
                                $hst_delete_title = $hst_can_delete ? 'حذف' : 'امکان حذف حساب کاربری فعلی وجود ندارد.';
                            ?>
                                <tr data-id="<?php echo esc_attr($student->ID); ?>"
                                    data-hst-search="<?php echo esc_attr($student->display_name . ' ' . ($student->classes ?? '') . ' ' . ($student->lessons ?? '') . ' ' . $s_national . ' ' . $s_father . ' ' . $s_phone); ?>"
                                    data-hst-class="<?php echo esc_attr(str_replace(',', '|', (string) ($student->classes ?? ''))); ?>"
                                    data-hst-lesson="<?php echo esc_attr(str_replace(',', '|', (string) ($student->lessons ?? ''))); ?>">
                                    <td><?php echo esc_html($index + 1); ?></td>
                                    <td class="hst-col-fill"><?php echo hst_user_cell($student->ID, $student->display_name); ?></td>
                                    <?php if (!$is_teacher_view) : ?>
                                        <td><?php echo $s_national ? esc_html($s_national) : '<span class="hst-muted">—</span>'; ?></td>
                                        <td><?php echo $s_father ? esc_html($s_father) : '<span class="hst-muted">—</span>'; ?></td>
                                        <td><?php echo $s_phone ? esc_html($s_phone) : '<span class="hst-muted">—</span>'; ?></td>
                                        <td><?php echo $s_birthdate ? esc_html($s_birthdate) : '<span class="hst-muted">—</span>'; ?></td>
                                        <td class="hst-actions">
                                            <div class="hst-btn-group">
                                                <button type="button" class="hst-btn hst-btn--ghost hst-btn--sm hst-btn--icon hst-student-view" data-id="<?php echo esc_attr($student->ID); ?>" title="مشاهده" aria-label="مشاهده"><?php echo hst_icon('view'); ?></button>
                                                <button type="button" class="hst-btn hst-btn--soft hst-btn--sm hst-btn--icon hst-student-edit" data-id="<?php echo esc_attr($student->ID); ?>" title="ویرایش" aria-label="ویرایش"><?php echo hst_icon('edit'); ?></button>
                                                <button type="button" class="hst-btn hst-btn--danger hst-btn--sm hst-btn--icon hst-delete" data-id="<?php echo esc_attr($student->ID); ?>" <?php disabled(!$hst_can_delete); ?> title="<?php echo esc_attr($hst_delete_title); ?>" aria-label="حذف"><?php echo hst_icon('delete'); ?></button>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="hst-muted hst-inline-filter__empty hst-empty-state hst-empty-state--inline" data-hst-inline-empty hidden>موردی با این فیلتر پیدا نشد.</p>
            <?php else : ?>
                <p class="hst-alert hst-empty-state"><?php echo $is_teacher_view ? esc_html__('در سال تحصیلی فعال، دانش‌آموز مشترکی برای کلاس‌ها و درس‌های شما یافت نشد.', 'teacher-show') : esc_html__('هنوز دانش‌آموزی تعریف نشده است.', 'teacher-show'); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (!$is_teacher_view) : ?>
<!-- View-student modal -->
<div class="hst-modal" id="hst-student-view-modal" data-hst-modal-tone="detail" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-student-view-title">
    <div class="hst-modal__backdrop" data-hst-view-close></div>
    <div class="hst-modal__panel">
        <div class="hst-modal__header">
            <div class="hst-modal__title">
                <span class="hst-modal__icon" aria-hidden="true"><?php echo hst_icon('students'); ?></span>
                <div>
                    <h3 id="hst-student-view-title">اطلاعات دانش‌آموز</h3>
                    <p>مشخصات و اطلاعات کامل دانش‌آموز</p>
                </div>
            </div>
            <button type="button" class="hst-modal__close" data-hst-view-close aria-label="بستن">&times;</button>
        </div>
        <div class="hst-modal__body" id="hst-student-view-body">
            <p class="hst-muted"><?php echo hst_loading_state(); ?></p>
        </div>
        <div class="hst-modal__footer">
            <button type="button" class="hst-btn hst-btn--soft" data-hst-view-close>بستن</button>
        </div>
    </div>
</div>

<!-- Add / edit student modal -->
<div class="hst-modal" id="hst-student-modal" data-hst-modal-size="lg" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hst-student-modal-title">
    <div class="hst-modal__backdrop" data-hst-modal-close></div>
    <div class="hst-modal__panel">
        <div class="hst-modal__header">
            <h3 id="hst-student-modal-title">افزودن دانش‌آموز</h3>
            <button type="button" class="hst-modal__close" data-hst-modal-close aria-label="بستن">&times;</button>
        </div>
        <div class="hst-modal__body">
            <form class="hst-form" id="define-student-form" autocomplete="off">
                <div class="hst-field-grid">
                    <label class="hst-field">
                        <span>نام دانش‌آموز</span>
                        <input class="hst-keynav" type="text" name="student_name" placeholder="نام" required>
                    </label>
                    <label class="hst-field">
                        <span>نام خانوادگی</span>
                        <input class="hst-keynav" type="text" name="student_last_name" placeholder="نام خانوادگی" required>
                    </label>
                    <label class="hst-field">
                        <span>شماره موبایل</span>
                        <input class="hst-keynav" type="tel" name="student_phone" placeholder="0912..." inputmode="tel" required>
                    </label>
                    <label class="hst-field">
                        <span>کد ملی</span>
                        <input class="hst-keynav" type="text" name="student_national_code" placeholder="کد ملی دانش‌آموز" inputmode="numeric" maxlength="10" required>
                    </label>
                    <label class="hst-field">
                        <span>نام پدر</span>
                        <input class="hst-keynav" type="text" name="student_father_name" placeholder="نام پدر" required>
                    </label>
                    <label class="hst-field">
                        <span>شماره تماس پدر</span>
                        <input class="hst-keynav" type="tel" name="student_father_phone" placeholder="0912..." inputmode="tel" required>
                    </label>
                    <label class="hst-field">
                        <span>شماره تماس مادر</span>
                        <input class="hst-keynav" type="tel" name="student_mother_phone" placeholder="0912..." inputmode="tel">
                    </label>
                    <label class="hst-field">
                        <span>تاریخ تولد</span>
                        <input class="hst-keynav hst-jalali-birthdate" type="text" name="student_birthdate" readonly placeholder="انتخاب تاریخ" autocomplete="off">
                    </label>
                </div>

                <p class="hst-form__section"><span>کلاس</span></p>
                <div class="checkbox-group hst-choice-list" id="class-checkboxes">
                    <?php if (empty($classes)) : ?>
                        <p class="hst-alert hst-empty-state">کلاسی یافت نشد.</p>
                    <?php else : ?>
                        <?php foreach ($classes as $class) : ?>
                            <label>
                                <input type="radio" name="class_ids[]" value="<?php echo esc_attr($class->id); ?>" class="class-item">
                                <p><?php echo esc_html($class->class_name); ?></p>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="hst-student-lessons-section hst-form" id="student-lessons-section" hidden>
                    <p class="hst-form__section"><span>انتخاب واحد</span></p>
                    <div class="checkbox-group hst-choice-list" id="lesson-checkboxes"></div>
                </div>
            </form>
        </div>
        <div class="hst-modal__footer">
            <button type="submit" class="hst-btn" form="define-student-form">ذخیره تغییرات</button>
            <button type="button" class="hst-btn hst-btn--ghost" data-hst-modal-close>بستن</button>
        </div>
    </div>
</div>
<?php endif; ?>
</div>
