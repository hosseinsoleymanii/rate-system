<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-icons.php';

$dashboard_items = [];

?>

<?php $hst_vice_role = class_exists('HST_Roles') ? HST_Roles::current_vice_role() : ''; ?>
<?php $hst_is_full_manager = class_exists('HST_Roles') ? HST_Roles::is_full_manager() : (current_user_can('manage_options') || current_user_can('hst_manage_school')); ?>

<?php if ($hst_is_full_manager) : ?>
<?php
    $dashboard_items = [
        ['url' => '/classes',      'label' => 'کلاس‌ها',          'icon' => 'classes'],
        ['url' => '/lessons',      'label' => 'درس‌ها',           'icon' => 'lessons'],
        ['url' => '/terms',        'label' => 'سال‌های تحصیلی',           'icon' => 'terms'],
        ['url' => '/teachers',     'label' => 'معلمان',           'icon' => 'teachers'],
        ['url' => '/students',     'label' => 'دانش‌آموزان',      'icon' => 'students'],
        ['url' => '/import-users', 'label' => 'انتقال از سیدا', 'icon' => 'import'],
        ['url' => '/term-transfer', 'label' => 'انتقال سال تحصیلی', 'icon' => 'transfer'],
        ['url' => '/schedules',    'label' => 'برنامه هفتگی',     'icon' => 'schedule'],
        ['url' => '/discipline', 'label' => 'موارد انضباطی', 'icon' => 'discipline'],
        ['url' => '/tuition',      'label' => 'شهریه',     'icon' => 'tuition'],
        ['url' => '/backup', 'label' => 'پشتیبان‌گیری', 'icon' => 'backup'],
        ['url' => '/periods', 'label' => 'دوره‌ها',       'icon' => 'terms'],
        ['url' => '/notifications','label' => 'اطلاعیه‌ها',        'icon' => 'notifications'],
        ['url' => '/exams',        'label' => 'آزمون‌ها',         'icon' => 'exams'],
    ];
    $dashboard_items[] = ['url' => '/report-cards', 'label' => 'کارنامه‌ها', 'icon' => 'report-card'];
    $dashboard_items[] = ['url' => '/smart-analysis', 'label' => 'تحلیل هوشمند', 'icon' => 'smart-analysis'];
    $dashboard_items[] = ['url' => '/plugin-settings', 'label' => 'تنظیمات سامانه', 'icon' => 'settings'];
?>
<?php elseif ($hst_vice_role === 'hst_vice_edu') : ?>
<?php
    // معاون آموزشی
    $dashboard_items = [
        ['url' => '/profile',       'label' => 'پروفایل',          'icon' => 'profile'],
        ['url' => '/discipline',    'label' => 'موارد انضباطی',    'icon' => 'discipline'],
        ['url' => '/exams',         'label' => 'آزمون‌ها',         'icon' => 'exams'],
        ['url' => '/schedules',     'label' => 'برنامه هفتگی',     'icon' => 'schedule'],
        ['url' => '/notifications', 'label' => 'اطلاعیه‌ها',        'icon' => 'notifications'],
    ];
?>
<?php elseif ($hst_vice_role === 'hst_vice_exec') : ?>
<?php
    // معاون اجرایی
    $dashboard_items = [
        ['url' => '/profile',         'label' => 'پروفایل',           'icon' => 'profile'],
        ['url' => '/classes',         'label' => 'کلاس‌ها',           'icon' => 'classes'],
        ['url' => '/lessons',         'label' => 'درس‌ها',            'icon' => 'lessons'],
        ['url' => '/terms',           'label' => 'سال‌های تحصیلی',            'icon' => 'terms'],
        ['url' => '/teachers',        'label' => 'معلمان',            'icon' => 'teachers'],
        ['url' => '/students',        'label' => 'دانش‌آموزان',       'icon' => 'students'],
        ['url' => '/import-users', 'label' => 'انتقال از سیدا',    'icon' => 'import'],
        ['url' => '/term-transfer',   'label' => 'انتقال سال تحصیلی', 'icon' => 'transfer'],
        ['url' => '/periods',    'label' => 'دوره‌ها',        'icon' => 'terms'],
        ['url' => '/notifications',   'label' => 'اطلاعیه‌ها',         'icon' => 'notifications'],
    ];
    $dashboard_items[] = ['url' => '/report-cards', 'label' => 'کارنامه‌ها', 'icon' => 'report-card'];
?>
<?php endif; ?>

<?php
if (!$hst_is_full_manager && $hst_vice_role === '') {
    if (current_user_can('hst_teach') || current_user_can('teacher')) {
        $dashboard_items = [
            ['url' => '/profile',      'label' => 'پروفایل',          'icon' => 'profile'],
            ['url' => '/students',     'label' => 'دانش‌آموزان',      'icon' => 'students'],
            ['url' => '/my-schedule',  'label' => 'برنامه من',        'icon' => 'schedule'],
            ['url' => '/enter-scores', 'label' => 'نمرهٔ دوره‌ای',       'icon' => 'scores'],
            ['url' => '/gradebook',    'label' => 'دفتر نمره',          'icon' => 'gradebook'],
            ['url' => '/notifications','label' => 'اطلاعیه‌ها',        'icon' => 'notifications'],
            ['url' => '/assignments',  'label' => 'تکالیف',           'icon' => 'assignments'],
            ['url' => '/attendance',   'label' => 'حضور و غیاب',      'icon' => 'attendance'],
            ['url' => '/exams',        'label' => 'آزمون‌ها',         'icon' => 'exams'],
        ];
    } elseif (current_user_can('hst_study') || current_user_can('student')) {
        $dashboard_items = [
            ['url' => '/profile',          'label' => 'پروفایل',      'icon' => 'profile'],
            ['url' => '/scores',           'label' => 'نمرات',        'icon' => 'scores'],
            ['url' => '/my-schedule',      'label' => 'برنامه من',    'icon' => 'schedule'],
            ['url' => '/tuition-payments', 'label' => 'شهریه',        'icon' => 'tuition'],
            ['url' => '/notifications',    'label' => 'اطلاعیه‌ها',    'icon' => 'notifications'],
            ['url' => '/assignments',      'label' => 'تکالیف',       'icon' => 'assignments'],
            ['url' => '/exams',            'label' => 'آزمون‌ها',     'icon' => 'exams'],
            ['url' => '/my-teachers',      'label' => 'معلم‌های من',  'icon' => 'teachers'],
        ];
    }
}

if (class_exists('HST_Settings')) {
    foreach ($dashboard_items as &$dashboard_item) {
        $path = (string) parse_url((string) ($dashboard_item['url'] ?? ''), PHP_URL_PATH);
        $slug = trim($path, '/');
        $dashboard_item['label'] = HST_Settings::plugin_page_title(
            $slug,
            (string) ($dashboard_item['label'] ?? '')
        );
    }
    unset($dashboard_item);
}
?>

<?php
$hst_is_manager = current_user_can('manage_options') || current_user_can('hst_manage_school');
$hst_is_teacher = !$hst_is_manager && (current_user_can('hst_teach') || current_user_can('teacher'));
$hst_is_student = !$hst_is_manager && !$hst_is_teacher && (current_user_can('hst_study') || current_user_can('student'));
$hst_overview = ($hst_is_manager && class_exists('HST_Stats')) ? HST_Stats::manager_overview() : null;
$hst_teacher_ov = ($hst_is_teacher && class_exists('HST_Stats')) ? HST_Stats::teacher_overview(get_current_user_id()) : null;
$hst_student_ov = ($hst_is_student && class_exists('HST_Stats')) ? HST_Stats::student_overview(get_current_user_id()) : null;

?>

<?php if ($hst_overview) : ?>
    <section class="hst-dash-overview hst-dash-overview--manager" aria-label="خلاصه وضعیت مدرسه">
        <div class="hst-stat-grid">
            <a href="<?php echo esc_url(home_url('/classes')); ?>" class="hst-stat-card hst-stat-card--accent hst-stat-card--link">
                <span class="hst-stat-card__icon"><?php echo hst_icon('classes'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value"><?php echo esc_html(number_format_i18n($hst_overview['classes_count'])); ?></span>
                    <span class="hst-stat-card__label">کلاس‌ها</span>
                </span>
            </a>
            <a href="<?php echo esc_url(home_url('/students')); ?>" class="hst-stat-card hst-stat-card--info hst-stat-card--link">
                <span class="hst-stat-card__icon"><?php echo hst_icon('students'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value"><?php echo esc_html(number_format_i18n($hst_overview['students_count'])); ?></span>
                    <span class="hst-stat-card__label">دانش‌آموزان</span>
                </span>
            </a>
            <a href="<?php echo esc_url(home_url('/teachers')); ?>" class="hst-stat-card hst-stat-card--accent hst-stat-card--link">
                <span class="hst-stat-card__icon"><?php echo hst_icon('teachers'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value"><?php echo esc_html(number_format_i18n($hst_overview['teachers_count'])); ?></span>
                    <span class="hst-stat-card__label">معلمان</span>
                </span>
            </a>
            <button
                type="button"
                class="hst-stat-card hst-stat-card--success hst-stat-card--link hst-stat-card--action"
                data-hst-dashboard-sms-balance
                data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                data-nonce="<?php echo esc_attr(wp_create_nonce('hst_nonce')); ?>"
                title="<?php echo esc_attr(($hst_overview['sms_balance']['error'] ?? '') ?: 'برای بروزرسانی مانده پیامک کلیک کنید.'); ?>"
                aria-label="بروزرسانی مانده پیامک"
            >
                <span class="hst-stat-card__icon"><?php echo hst_icon('sms'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value" data-hst-dashboard-sms-balance-value aria-live="polite">
                        <?php
                        $hst_sms_remaining = $hst_overview['sms_balance']['available_balance'] ?? '';
                        echo $hst_sms_remaining === '' ? '—' : esc_html(number_format_i18n((float) $hst_sms_remaining));
                        ?>
                    </span>
                    <span class="hst-stat-card__label">پیامک باقی‌مانده</span>
                </span>
            </button>
            <a href="<?php echo esc_url(home_url('/tuition')); ?>" class="hst-stat-card hst-stat-card--link <?php echo $hst_overview['unpaid_invoices'] > 0 ? 'hst-stat-card--danger' : 'hst-stat-card--muted'; ?>">
                <span class="hst-stat-card__icon"><?php echo hst_icon('tuition'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value"><?php echo esc_html(number_format_i18n($hst_overview['unpaid_invoices'])); ?></span>
                    <span class="hst-stat-card__label">شهریه پرداخت‌نشده</span>
                </span>
            </a>
            <a href="<?php echo esc_url(home_url('/terms')); ?>" class="hst-stat-card hst-stat-card--muted hst-stat-card--link">
                <span class="hst-stat-card__icon"><?php echo hst_icon('terms'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value hst-stat-card__value--text"><?php echo esc_html($hst_overview['term']->term_name ?? 'بدون سال تحصیلی فعال'); ?></span>
                    <span class="hst-stat-card__label">سال تحصیلی فعال</span>
                </span>
            </a>
        </div>
    </section>
<?php endif; ?>

<?php if ($hst_teacher_ov) : ?>
    <section class="hst-dash-overview" aria-label="خلاصه وضعیت من">
        <div class="hst-stat-grid">
            <a href="<?php echo esc_url(home_url('/my-schedule')); ?>" class="hst-stat-card hst-stat-card--accent hst-stat-card--link">
                <span class="hst-stat-card__icon"><?php echo hst_icon('schedule'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value"><?php echo esc_html(number_format_i18n($hst_teacher_ov['periods_count'])); ?></span>
                    <span class="hst-stat-card__label">زنگ‌های هفتگی</span>
                </span>
            </a>
            <div class="hst-stat-card hst-stat-card--info">
                <span class="hst-stat-card__icon"><?php echo hst_icon('classes'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value"><?php echo esc_html(number_format_i18n($hst_teacher_ov['classes_count'])); ?></span>
                    <span class="hst-stat-card__label">کلاس‌ها</span>
                </span>
            </div>
            <div class="hst-stat-card hst-stat-card--accent">
                <span class="hst-stat-card__icon"><?php echo hst_icon('scores'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value"><?php echo esc_html(number_format_i18n($hst_teacher_ov['lessons_count'])); ?></span>
                    <span class="hst-stat-card__label">درس‌ها</span>
                </span>
            </div>
            <div class="hst-stat-card hst-stat-card--success">
                <span class="hst-stat-card__icon"><?php echo hst_icon('students'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value"><?php echo esc_html(number_format_i18n($hst_teacher_ov['students_count'])); ?></span>
                    <span class="hst-stat-card__label">دانش‌آموزان</span>
                </span>
            </div>
            <div class="hst-stat-card hst-stat-card--muted">
                <span class="hst-stat-card__icon"><?php echo hst_icon('terms'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value hst-stat-card__value--text"><?php echo esc_html($hst_teacher_ov['term']->term_name ?? 'بدون سال تحصیلی فعال'); ?></span>
                    <span class="hst-stat-card__label">سال تحصیلی فعال</span>
                </span>
            </div>
        </div>

    </section>
<?php endif; ?>

<?php if ($hst_student_ov) : ?>
    <section class="hst-dash-overview" aria-label="خلاصه وضعیت من">
        <div class="hst-stat-grid">
            <a href="<?php echo esc_url(home_url('/my-schedule')); ?>" class="hst-stat-card hst-stat-card--accent hst-stat-card--link">
                <span class="hst-stat-card__icon"><?php echo hst_icon('schedule'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value"><?php echo esc_html(number_format_i18n($hst_student_ov['today_periods'])); ?></span>
                    <span class="hst-stat-card__label">زنگ‌های امروز</span>
                </span>
            </a>
            <div class="hst-stat-card hst-stat-card--info">
                <span class="hst-stat-card__icon"><?php echo hst_icon('classes'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value"><?php echo esc_html(number_format_i18n($hst_student_ov['classes_count'])); ?></span>
                    <span class="hst-stat-card__label">کلاس‌ها</span>
                </span>
            </div>
            <div class="hst-stat-card hst-stat-card--accent">
                <span class="hst-stat-card__icon"><?php echo hst_icon('scores'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value"><?php echo esc_html(number_format_i18n($hst_student_ov['lessons_count'])); ?></span>
                    <span class="hst-stat-card__label">درس‌ها</span>
                </span>
            </div>
            <div class="hst-stat-card hst-stat-card--muted">
                <span class="hst-stat-card__icon"><?php echo hst_icon('terms'); ?></span>
                <span class="hst-stat-card__body">
                    <span class="hst-stat-card__value hst-stat-card__value--text"><?php echo esc_html($hst_student_ov['term']->term_name ?? 'بدون سال تحصیلی فعال'); ?></span>
                    <span class="hst-stat-card__label">سال تحصیلی فعال</span>
                </span>
            </div>
        </div>

    </section>
<?php endif; ?>

<section class="hst-dashboard" aria-label="منوی پیشخوان">
    <?php if (!empty($dashboard_items)) : ?>
        <?php foreach ($dashboard_items as $item) : ?>
            <a href="<?php echo esc_url(home_url($item['url'])); ?>" class="hst-tile">
                <span class="hst-tile__icon"><?php echo hst_icon($item['icon']); ?></span>
                <span><?php echo esc_html($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    <?php else : ?>
        <div class="hst-card">
            <div class="hst-card__body">
                <p class="hst-alert">برای این نقش کاربری قابلیتی تعریف نشده است.</p>
            </div>
        </div>
    <?php endif; ?>
</section>

<?php if ($hst_overview) : ?>
<script>
(function () {
    'use strict';

    var balanceCard = document.querySelector('[data-hst-dashboard-sms-balance]');
    if (!balanceCard) return;

    var balanceValue = balanceCard.querySelector('[data-hst-dashboard-sms-balance-value]');
    var ajaxUrl = balanceCard.getAttribute('data-ajax-url') || '';
    var nonce = balanceCard.getAttribute('data-nonce') || '';
    function setLoading(loading) {
        balanceCard.disabled = loading;
        balanceCard.setAttribute('aria-busy', loading ? 'true' : 'false');

        if (window.HST && HST.loader) {
            if (loading && typeof HST.loader.show === 'function') HST.loader.show();
            if (!loading && typeof HST.loader.hide === 'function') HST.loader.hide();
        }
    }

    function showToast(message, type) {
        if (window.HST && typeof HST.toast === 'function') {
            HST.toast(message, type);
        }
    }

    balanceCard.addEventListener('click', function () {
        if (!ajaxUrl || !nonce || balanceCard.disabled) return;

        setLoading(true);

        var formData = new FormData();
        formData.append('action', 'hst_sms_balance');
        formData.append('nonce', nonce);

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (response) {
                if (!response || !response.success || !response.data) {
                    throw new Error(
                        window.HST && typeof HST.getMessage === 'function'
                            ? HST.getMessage(response, 'دریافت موجودی پیامک انجام نشد.')
                            : 'دریافت موجودی پیامک انجام نشد.'
                    );
                }

                if (balanceValue) {
                    balanceValue.textContent = response.data.display_balance || '—';
                }
                balanceCard.title = 'برای بروزرسانی دوباره مانده پیامک کلیک کنید.';
                showToast(response.data.message || 'موجودی پیامک بروزرسانی شد.', 'success');
            })
            .catch(function (error) {
                var message = error && error.message && !/^HTTP\s/.test(error.message)
                    ? error.message
                    : 'ارتباط با سرور برای دریافت موجودی برقرار نشد.';
                balanceCard.title = message;
                showToast(message, 'error');
            })
            .finally(function () {
                setLoading(false);
            });
    });
})();
</script>
<?php endif; ?>
</div>
