//use this code anywhere in your WordPress theme to display the ratting form and more

<div class="average-rating">
<img src="<?php echo TD;?>/asset/imgs/rate.png" alt="">
میانگین امتیازات: <?php echo get_average_rating( get_the_ID() ); ?> ستاره
</div>
<br>
<div class="grade">
    <?php
    global $wpdb;
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;
    $post_id = get_the_ID();
    $table_name = $wpdb->prefix . 'ratings';
    
    $has_rated = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE post_id = %d AND user_id = %d",
            $post_id,
            $user_id
        )
    );
    if ( $has_rated ) {
        echo "شما قبلاً به این پست امتیاز داده‌اید.";
    } else {
    ?>
    <?php
    if ( isset( $_POST['submit_rating'] ) ) {
        $post_id = get_the_ID();
        $rating = intval( $_POST['rating'] );
        if ( $rating > 0 && $rating <= 5 ) {
            update_post_meta( $post_id, 'rating', $rating, true );
        }
    }
    ?>
    <?php
    if(is_user_logged_in()){
    ?>
    <div class="rating">
    <p><strong>به این مقاله امتیاز بده</strong></p>
    <form method="post" action="" class="stars">
        <input type="radio" id="star5" name="rating" value="5" />
        <label for="star5">&#9733;</label>
        <input type="radio" id="star4" name="rating" value="4" />
        <label for="star4">&#9733;</label>
        <input type="radio" id="star3" name="rating" value="3" />
        <label for="star3">&#9733;</label>
        <input type="radio" id="star2" name="rating" value="2" />
        <label for="star2">&#9733;</label>
        <input type="radio" id="star1" name="rating" value="1" />
        <label for="star1">&#9733;</label>
        <input type="submit" name="submit" value="تایید" />
    </form>
    </div>
    <?php
    }else{
    ?>
    <?php echo "برای ثبت امتیاز ابتدا وارد سایت شوید!"?>
    <?php
    }
    ?>
    <?php } ?>
</div>

//use this code anywhere in your WordPress theme to display the ratting form and more