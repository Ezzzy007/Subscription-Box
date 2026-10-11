<?php
/** Small site fixes (was the sb-site-tweaks.php must-use file). Each one can be switched off on SB Toolkit → Site tweaks. */
defined( 'ABSPATH' ) || exit;

/* 1. Currency per Product Pro – blank price guard: stops a crash (string * int) when a price is blank. */
if ( sbt_module_on( 'price_guard' ) ) {
add_action( 'wp_loaded', function () {
	if ( ! class_exists( 'Alg_WC_CPP_Core' ) ) {
		return;
	}
	global $wp_filter;
	foreach ( $wp_filter as $tag => $hook ) {
		$callbacks = $hook->callbacks; // copy, so we can edit while looping
		foreach ( $callbacks as $priority => $items ) {
			foreach ( $items as $item ) {
				$fn = $item['function'];
				if ( ! is_array( $fn ) || ! is_object( $fn[0] ) || ! ( $fn[0] instanceof Alg_WC_CPP_Core ) || 'change_price' !== $fn[1] ) {
					continue;
				}
				$core = $fn[0];
				remove_filter( $tag, $fn, $priority );
				add_filter( $tag, function ( $price, ...$rest ) use ( $core ) {
					// Blank or non-numeric price: nothing to convert, pass it through untouched.
					if ( '' === $price || null === $price || ! is_numeric( $price ) ) {
						return $price;
					}
					return $core->change_price( $price, ...$rest );
				}, $priority, $item['accepted_args'] );
			}
		}
	}
}, 999 );
}

/* 2. Tidy admin Products list: drops SKU, GTIN, Tags, Badge, Meta catalog, Yoast and brand columns; fixes the footer overlap. */
if ( sbt_module_on( 'products_tidy' ) ) {
/** Columns to drop, matched by label text (keys differ between plugin versions). */
function sbt_tidy_drop_labels() {
	return array( 'GTIN', 'Synced to Meta', 'Product brands', 'Brands', 'Badge' );
}

add_filter( 'manage_edit-product_columns', function ( $cols ) {
	unset( $cols['sku'], $cols['product_tag'] );
	foreach ( $cols as $key => $label ) {
		$text = trim( wp_strip_all_tags( (string) $label ) );
		if ( 0 === strpos( $key, 'wpseo-' ) ) { // Yoast: SEO score, readability, outgoing/incoming links
			unset( $cols[ $key ] );
			continue;
		}
		foreach ( sbt_tidy_drop_labels() as $drop ) {
			if ( '' !== $text && false !== stripos( $text, $drop ) ) {
				unset( $cols[ $key ] );
				break;
			}
		}
	}
	return $cols;
}, 9999 );

/** No "If you like WooCommerce…" footer on product screens. */
add_filter( 'woocommerce_display_admin_footer_text', function ( $show ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	return ( $screen && 'product' === $screen->post_type ) ? false : $show;
} );

add_action( 'admin_head-edit.php', function () {
	$screen = get_current_screen();
	if ( ! $screen || 'product' !== $screen->post_type ) {
		return;
	}
	echo '<style>'
		. '#wpfooter{position:relative!important;clear:both}'
		. '.wp-list-table.posts .column-name{width:22%}'
		. '.wp-list-table.posts .column-price{width:9%}'
		. '.wp-list-table.posts .column-product_cat{width:14%}'
		. '.wp-list-table.posts .column-date{width:9%}'
		. '.wp-list-table.posts [class*="store"],.wp-list-table.posts [id*="store"]{width:11%}'
		. '.wp-list-table.posts td{word-break:normal;overflow-wrap:break-word}'
		. '.wp-list-table.posts th{word-break:normal;overflow-wrap:normal}'
		. '</style>';
} );
}
