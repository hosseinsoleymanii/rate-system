<?php
defined('ABSPATH') || exit;
$hst_document_title = wp_get_document_title();
remove_action('wp_head', '_wp_render_title_tag', 1);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($hst_document_title); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class('hst-blank-slate'); ?>>
    <?php
    while (have_posts()):
        the_post();
        the_content();
    endwhile;
    ?>
    <?php wp_footer(); ?>
</body>
</html>
