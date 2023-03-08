//Functions of WordPress Articles ratting System
<?php
//Creating a new table to record rates
add_action( 'init', 'create_rating_table' );
function create_rating_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ratings';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT(20) UNSIGNED NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        rating INT(1) NOT NULL DEFAULT '0',
        PRIMARY KEY  (id),
        KEY post_id (post_id),
        KEY user_id (user_id)
    ) $charset_collate;";
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}
//Creating a new table to record rates

//get the average of recorded rates
function get_average_rating( $post_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ratings';
    $ratings = $wpdb->get_results( $wpdb->prepare(
        "SELECT rating FROM $table_name WHERE post_id = %d",
        $post_id
    ) );
    $total_ratings = count( $ratings );
    $average_rating = 0;
    if ( $total_ratings > 0 ) {
        foreach ( $ratings as $rating ) {
            $average_rating += intval( $rating->rating );
        }
        $average_rating /= $total_ratings;
    }
    return round( $average_rating, 1 );
}
//get the average of recorded rates

//Saving the rates in the database
add_action( 'wp', 'save_rating' );
function save_rating() {
    if ( isset( $_POST['submit'] ) ) {
        global $wpdb;
        $rating = intval( $_POST['rating'] );
        if ( $rating >= 1 && $rating <= 5 ) {
            $table_name = $wpdb->prefix . 'ratings';
            $current_user = wp_get_current_user();
            $user_id = $current_user->ID;
            $post_id = get_the_ID();
            $has_rated = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE post_id = %d AND user_id = %d",
                $post_id,
                $user_id
            ) );
            if ( $has_rated ) {
                return;
            }
            $wpdb->insert(
                $table_name,
                array(
                    'post_id' => $post_id,
                    'user_id' => $user_id,
                    'rating' => $rating,
                ),
                array(
                    '%d',
                    '%d',
                    '%d',
                )
            );
        }
    }
}
//Saving the rates in the database
?>
//Functions of WordPress Articles ratting System
