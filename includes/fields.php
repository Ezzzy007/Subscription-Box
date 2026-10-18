<?php
/**
 * Product fields that used to need Advanced Custom Fields: Subtitle (shop cards) and Before Price (product page).
 * Shop Price is handled by currency.php. Same meta keys as ACF used, so nothing needs moving.
 * The child theme's ACF display snippets are switched off here and done by the toolkit instead, so ACF can be removed.
 */
defined( 'ABSPATH' ) || exit;

/* If ACF is removed, keep anything that still calls get_field()/the_field() working instead of crashing the page. */
add_action( 'after_setup_theme', function () {
	if ( ! function_exists( 'get_field' ) ) {
		function get_field( $key, $post_id = false ) {
			$post_id = $post_id ? $post_id : get_the_ID();
			return is_numeric( $post_id ) ? get_post_meta( (int) $post_id, $key, true ) : '';
		}
	}
	if ( ! function_exists( 'the_field' ) ) {
		function the_field( $key, $post_id = false ) {
			echo wp_kses_post( get_field( $key, $post_id ) );
		}
	}
}, 1 );

/* Take over from the child theme's snippets (they only worked through ACF). */
add_action( 'init', function () {
	remove_action( 'woocommerce_shop_loop_item_title', 'shoptimizer_custom_subtitle_field', 15 );
	remove_action( 'woocommerce_single_product_summary', 'shoptimizer_intprice', 12 );
	remove_action( 'woocommerce_single_product_summary', 'shoptimizer_before_price', 10 );
} );

add_action( 'woocommerce_shop_loop_item_title', function () {
	$v = get_post_meta( get_the_ID(), 'subtitle', true );
	if ( '' !== (string) $v ) {
		echo '<div class="cg-subtitle">' . esc_html( $v ) . '</div>';
	}
}, 15 );

add_action( 'woocommerce_single_product_summary', function () {
	$v = get_post_meta( get_the_ID(), 'before_price', true );
	if ( '' !== (string) $v ) {
		echo '<div class="cg-before_price">' . esc_html( $v ) . '</div>';
	}
}, 10 );

add_action( 'woocommerce_single_product_summary', function () {
	$v = get_post_meta( get_the_ID(), 'intprice', true );
	if ( '' !== (string) $v && apply_filters( 'sbt_show_shop_price_line', true ) ) {
		echo '<div class="cg-intprice">' . esc_html( $v ) . '</div>';
	}
}, 12 );

/* Edit screen: Subtitle under the product name. Hidden while ACF is still active (it shows its own box). */
add_action( 'edit_form_after_title', function ( $post ) {
	if ( 'product' !== $post->post_type || function_exists( 'acf_get_field_groups' ) ) {
		return;
	}
	wp_nonce_field( 'sbt_fields', 'sbt_fields_nonce' );
	$sub = get_post_meta( $post->ID, 'subtitle', true );
	$bef = get_post_meta( $post->ID, 'before_price', true );
	echo '<div style="margin:12px 0 4px;display:flex;gap:16px;flex-wrap:wrap">';
	echo '<label style="flex:1 1 320px"><strong>Subtitle</strong> <span style="color:#646970">(shown under the name on shop cards, e.g. "Sock Subscription")</span><br><input type="text" name="sbt_subtitle" value="' . esc_attr( $sub ) . '" style="width:100%" maxlength="60"></label>';
	echo '<label style="flex:0 1 200px"><strong>Before price</strong> <span style="color:#646970">(optional, e.g. "From")</span><br><input type="text" name="sbt_before_price" value="' . esc_attr( $bef ) . '" style="width:100%" maxlength="30"></label>';
	echo '</div>';
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	if ( empty( $_POST['sbt_fields_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['sbt_fields_nonce'] ), 'sbt_fields' ) ) {
		return;
	}
	foreach ( array( 'sbt_subtitle' => 'subtitle', 'sbt_before_price' => 'before_price' ) as $in => $key ) {
		if ( isset( $_POST[ $in ] ) ) {
			$v = sanitize_text_field( wp_unslash( $_POST[ $in ] ) );
			'' === $v ? $product->delete_meta_data( $key ) : $product->update_meta_data( $key, $v );
		}
	}
} );
