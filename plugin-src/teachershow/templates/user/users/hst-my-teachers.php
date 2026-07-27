<?php
defined('ABSPATH') || exit;
include_once HST_PATH . 'templates/user/common/hst-header.php';
include_once HST_PATH . 'templates/user/common/hst-user-avatar.php';

$teachers = isset($teachers) && is_array($teachers) ? $teachers : [];
?>
<section class="hst-page" aria-label="معلم‌های من">
    <div class="hst-card">
        <div class="hst-card__header"><h3>معلم‌های من</h3></div>
        <div class="hst-card__body">
            <p class="hst-muted">در این بخش با معلم‌های درس‌های خود در سال تحصیلی جاری آشنا می‌شوید.</p>

            <?php if (empty($teachers)) : ?>
                <p class="hst-empty-note">هنوز معلمی برای درس‌های شما در سال تحصیلی فعال ثبت نشده است.</p>
            <?php else : ?>
                <div class="hst-teacher-grid">
                    <?php foreach ($teachers as $teacher) : ?>
                        <article class="hst-teacher-card">
                            <div class="hst-teacher-card__head">
                                <?php echo hst_user_avatar($teacher->teacher_id, $teacher->name, 56); ?>
                                <div class="hst-teacher-card__id">
                                    <span class="hst-teacher-card__name"><?php echo esc_html($teacher->name); ?></span>
                                    <?php if (!empty($teacher->lessons)) : ?>
                                        <span class="hst-teacher-card__lessons"><?php echo esc_html(implode('، ', $teacher->lessons)); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($teacher->bio)) : ?>
                                <p class="hst-teacher-card__bio"><?php echo nl2br(esc_html($teacher->bio)); ?></p>
                            <?php else : ?>
                                <p class="hst-teacher-card__bio hst-teacher-card__bio--empty">معرفی‌ای برای این معلم ثبت نشده است.</p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
