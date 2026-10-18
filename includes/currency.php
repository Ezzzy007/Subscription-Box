<?php
/**
 * Overseas prices. Replaces Currency per Product / External Products Currency.
 *
 * How it works: the "Shop Price" box (meta `intprice`, e.g. "Shop Price: £24.99 GBP") is the source of truth for a
 * box sold in another currency. On every save the toolkit reads it, converts it to the store currency with today's
 * rate and puts that in the normal WooCommerce price, so sorting, filters, feeds and carousels all use AUD.
 * Rates come from open.er-api.com once a day (no key needed) and every overseas box is re-priced.
 * Front end: "$45.56 AUD" for the price, plus the theme's existing "Shop Price: £24.99 GBP" line.
 */
defined( 'ABSPATH' ) || exit;

const SBT_FX_META = 'intprice';

function sbt_fx_base() {
	return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'AUD';
}

function sbt_fx_symbols() {
	return array( 'USD' => '$', 'AUD' => '$', 'NZD' => '$', 'CAD' => '$', 'SGD' => '$', 'HKD' => '$', 'GBP' => '£', 'EUR' => '€', 'JPY' => '¥', 'KRW' => '₩', 'CNY' => '¥', 'INR' => '₹' );
}

/** Units of each currency per 1 store-currency unit, e.g. ['USD'=>0.65,'GBP'=>0.51]. Manual overrides win. */
function sbt_fx_rates() {
	$fx    = get_option( 'sbt_fx', array() );
	$rates = isset( $fx['rates'] ) && is_array( $fx['rates'] ) ? $fx['rates'] : array();
	foreach ( sbt_lines( (string) sbt_get( 'fx_overrides' ) ) as $line ) {
		if ( preg_match( '/^([A-Z]{3})\s*[=:]\s*([\d.]+)/i', $line, $m ) && (float) $m[2] > 0 ) {
			$rates[ strtoupper( $m[1] ) ] = (float) $m[2];
		}
	}
	return $rates;
}

/** Download today's rates. Returns true on success. */
function sbt_fx_fetch() {
	$base = sbt_fx_base();
	$res  = wp_remote_get( 'https://open.er-api.com/v6/latest/' . rawurlencode( $base ), array( 'timeout' => 15 ) );
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		update_option( 'sbt_fx_error', is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ), false );
		return false;
	}
	$j = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $j['rates'] ) || ! is_array( $j['rates'] ) ) {
		update_option( 'sbt_fx_error', 'Unexpected reply from the rates service', false );
		return false;
	}
	$keep = array();
	foreach ( $j['rates'] as $c => $r ) {
		if ( (float) $r > 0 ) {
			$keep[ strtoupper( $c ) ] = (float) $r;
		}
	}
	update_option( 'sbt_fx', array( 'base' => $base, 'rates' => $keep, 'updated' => time() ), false );
	delete_option( 'sbt_fx_error' );
	return true;
}

/** "Shop Price: £24.99 GBP" / "Shop Price - $45.00 USD" / "$19.9 USD" → array( 24.99, 'GBP' ). */
function sbt_fx_parse( $text ) {
	if ( preg_match( '/([\d][\d,]*(?:\.\d+)?)\s*([A-Za-z]{3})\b/', (string) $text, $m ) ) {
		$amt = (float) str_replace( ',', '', $m[1] );
		$cur = strtoupper( $m[2] );
		if ( $amt > 0 && preg_match( '/^[A-Z]{3}$/', $cur ) ) {
			return array( $amt, $cur );
		}
	}
	return null;
}

function sbt_fx_label( $amt, $cur ) {
	$sym = sbt_fx_symbols();
	$s   = isset( $sym[ $cur ] ) ? $sym[ $cur ] : '';
	$n   = number_format( $amt, in_array( $cur, array( 'JPY', 'KRW' ), true ) ? 0 : 2, '.', ',' );
	return 'Shop Price: ' . $s . $n . ' ' . $cur;
}

function sbt_fx_types() {
	return array( 'simple', 'external' );
}

/** Works out the overseas price for a product: from the Shop Price box, or (old listings) the old plugin's currency. */
function sbt_fx_source( $product ) {
	$base = sbt_fx_base();
	$p    = sbt_fx_parse( $product->get_meta( SBT_FX_META ) );
	if ( $p ) {
		return $p[1] === $base ? null : $p;
	}
	foreach ( array( '_alg_wc_cpp_currency', '_wcj_currency_per_product_currency' ) as $k ) {
		$c = strtoupper( (string) $product->get_meta( $k ) );
		if ( $c && $c !== $base && (float) $product->get_regular_price( 'edit' ) > 0 ) {
			return array( (float) $product->get_regular_price( 'edit' ), $c );
		}
	}
	return null;
}

/**
 * Sets the store-currency price from the overseas price. Runs on every product save (admin, CSV import, code),
 * so it never needs a separate step.
 */
function sbt_fx_apply( $product ) {
	if ( ! $product || ! in_array( $product->get_type(), sbt_fx_types(), true ) ) {
		return false;
	}
	$src = sbt_fx_source( $product );
	if ( ! $src ) {
		if ( $product->get_meta( '_sbt_cur' ) ) {
			$product->delete_meta_data( '_sbt_cur' );
			$product->delete_meta_data( '_sbt_cur_price' );
		}
		return false;
	}
	list( $amt, $cur ) = $src;
	$rates = sbt_fx_rates();
	$product->update_meta_data( '_sbt_cur', $cur );
	$product->update_meta_data( '_sbt_cur_price', $amt );
	$product->update_meta_data( SBT_FX_META, sbt_fx_label( $amt, $cur ) );
	// Neutralise the old plugins' meta so they can't double-convert if ever switched back on.
	$base = sbt_fx_base();
	foreach ( array( '_alg_wc_cpp_currency', '_wcj_currency_per_product_currency' ) as $k ) {
		if ( $product->get_meta( $k ) ) {
			$product->update_meta_data( $k, $base );
		}
	}
	if ( empty( $rates[ $cur ] ) ) {
		$product->update_meta_data( '_sbt_cur_norate', 1 );
		return false; // unknown currency: leave the price alone
	}
	$product->delete_meta_data( '_sbt_cur_norate' );
	$local = round( $amt / $rates[ $cur ], 2 );
	if ( (string) $local !== (string) $product->get_regular_price( 'edit' ) ) {
		$product->set_regular_price( (string) $local );
		if ( ! $product->get_sale_price( 'edit' ) ) {
			$product->set_price( (string) $local );
		}
	}
	return true;
}

function sbt_fx_recalc_batch( $offset = 0, $limit = 50 ) {
	$ids = get_posts( array(
		'post_type'      => 'product',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
		'posts_per_page' => $limit,
		'offset'         => $offset,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'fields'         => 'ids',
		'meta_query'     => array(
			'relation' => 'OR',
			array( 'key' => SBT_FX_META, 'value' => '', 'compare' => '!=' ),
			array( 'key' => '_alg_wc_cpp_currency', 'value' => sbt_fx_base(), 'compare' => '!=' ),
			array( 'key' => '_wcj_currency_per_product_currency', 'value' => sbt_fx_base(), 'compare' => '!=' ),
		),
	) );
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( $p && in_array( $p->get_type(), sbt_fx_types(), true ) ) {
			sbt_fx_apply( $p );
			$p->save();
		}
	}
	return count( $ids );
}

function sbt_fx_recalc_all() {
	$o = 0;
	do {
		$n  = sbt_fx_recalc_batch( $o, 100 );
		$o += $n;
	} while ( 100 === $n );
	return $o;
}

/* Daily: fetch rates, then re-price. */
if ( sbt_module_on( 'fx' ) ) :

add_action( 'woocommerce_before_product_object_save', function ( $product ) {
	static $busy = false;
	if ( $busy ) {
		return;
	}
	$busy = true;
	sbt_fx_apply( $product );
	$busy = false;
} );

/** Re-price every overseas box. $offset/$limit for batching. Returns number looked at. */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'sbt_fx_daily' ) ) {
		wp_schedule_event( time() + 300, 'daily', 'sbt_fx_daily' );
	}
} );
add_action( 'sbt_fx_daily', function () {
	if ( sbt_fx_fetch() ) {
		sbt_fx_recalc_all();
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
	}
} );

/* Front end: "$45.56 AUD". Replaces the child theme's tag-based snippet, which put "GBP" after AUD amounts. */
add_action( 'init', function () {
	remove_filter( 'woocommerce_get_price_html', 'themeprefix_custom_price_message' );
} );
add_filter( 'woocommerce_get_price_html', function ( $html, $product ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $html;
	}
	if ( $html && in_array( $product->get_type(), sbt_fx_types(), true ) && false === strpos( $html, 'sbt-fx-code' ) ) {
		$html .= ' <span class="sbt-fx-code">' . esc_html( sbt_fx_base() ) . '</span>';
	}
	return $html;
}, 99, 2 );

/* Product edit screen: Overseas price + Currency, right under the normal price. */
function sbt_fx_choices() {
	$list = array( sbt_fx_base(), 'USD', 'GBP', 'EUR', 'NZD', 'CAD', 'JPY', 'KRW', 'SGD', 'HKD' );
	return array_values( array_unique( $list ) );
}

add_action( 'woocommerce_product_options_pricing', function () {
	global $product_object;
	if ( ! $product_object ) {
		return;
	}
	$base = sbt_fx_base();
	$cur  = $product_object->get_meta( '_sbt_cur' );
	$amt  = $product_object->get_meta( '_sbt_cur_price' );
	$cur  = $cur ? $cur : $base;
	$opts = array();
	foreach ( sbt_fx_choices() as $c ) {
		$opts[ $c ] = $c === $base ? $c . ' – Australian box (just use the price above)' : $c;
	}
	if ( ! isset( $opts[ $cur ] ) ) {
		$opts[ $cur ] = $cur;
	}
	echo '<div class="sbt-fx-fields" style="border-top:1px solid #eee;margin-top:6px;padding-top:4px">';
	woocommerce_wp_select( array( 'id' => '_sbt_cur_in', 'label' => 'Box currency', 'value' => $cur, 'options' => $opts ) );
	woocommerce_wp_text_input( array( 'id' => '_sbt_cur_price_in', 'label' => 'Overseas price', 'value' => $cur === $base ? '' : $amt, 'data_type' => 'price', 'placeholder' => 'e.g. 24.99', 'description' => 'For overseas boxes: type their own price and pick the currency. The price above and the Shop Price line fill themselves when you click Update.', 'desc_tip' => false ) );
	if ( $cur !== $base && $amt ) {
		$rates = sbt_fx_rates();
		echo '<p class="form-field" style="color:#646970;margin-top:-6px">';
		echo ! empty( $rates[ $cur ] )
			? esc_html( sbt_fx_label( (float) $amt, $cur ) ) . ' = ' . esc_html( number_format( (float) $amt / $rates[ $cur ], 2 ) . ' ' . $base ) . ' today. Re-checked every day.'
			: '<span style="color:#d63638">No exchange rate for ' . esc_html( $cur ) . ' yet – SB Toolkit → Currency → Update rates now.</span>';
		echo '</p>';
	}
	echo '</div>';
	?>
	<script>jQuery(function($){var t=function(){var aud=$('#_sbt_cur_in').val()==='<?php echo esc_js( $base ); ?>';$('._sbt_cur_price_in_field').toggle(!aud);$('#_regular_price').prop('readonly',!aud).css('background',aud?'':'#f0f0f1');};$('#_sbt_cur_in').on('change',t);t();});</script>
	<?php
} );

/** Turn the two fields into the Shop Price line on save. */
function sbt_fx_from_post() {
	if ( ! isset( $_POST['_sbt_cur_in'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce checks the nonce
		return null;
	}
	$cur = strtoupper( sanitize_text_field( wp_unslash( $_POST['_sbt_cur_in'] ) ) ); // phpcs:ignore
	$amt = isset( $_POST['_sbt_cur_price_in'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['_sbt_cur_price_in'] ) ) : 0; // phpcs:ignore
	if ( $cur === sbt_fx_base() || $amt <= 0 || ! preg_match( '/^[A-Z]{3}$/', $cur ) ) {
		return '';
	}
	return sbt_fx_label( $amt, $cur );
}

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	$label = sbt_fx_from_post();
	if ( null === $label ) {
		return;
	}
	if ( '' === $label ) {
		$product->delete_meta_data( SBT_FX_META ); // Australian box: the price typed above stands
		foreach ( array( '_alg_wc_cpp_currency', '_wcj_currency_per_product_currency' ) as $k ) {
			$product->delete_meta_data( $k );
		}
	} else {
		$product->update_meta_data( SBT_FX_META, $label );
	}
} );

/* ACF saves its own copy of the Shop Price box after WooCommerce – make it save ours instead, and lock the box. */
add_filter( 'acf/update_value/name=' . SBT_FX_META, function ( $value ) {
	$label = sbt_fx_from_post();
	return null === $label ? $value : $label;
} );
add_filter( 'acf/prepare_field/name=' . SBT_FX_META, function ( $field ) {
	$field['readonly']     = 1;
	$field['instructions'] = 'Filled in automatically from "Overseas price" in Product data → General.';
	return $field;
} );

endif; // module on

/* --- SB Toolkit → Currency tab actions ---------------------------------------------- */

function sbt_fx_old_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$slug = dirname( $file );
		if ( in_array( $slug, array( 'currency-per-product-for-woocommerce', 'currency-per-product-for-woocommerce-pro', 'external-products-currency-for-woocommerce' ), true ) && is_plugin_active( $file ) ) {
			$out[ $file ] = $data['Name'];
		}
	}
	return $out;
}

add_action( 'admin_post_sbt_fx', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	$do = isset( $_REQUEST['do'] ) ? sanitize_key( $_REQUEST['do'] ) : '';
	check_admin_referer( 'sbt_fx_' . $do );
	$back = admin_url( 'admin.php?page=sb-toolkit&tab=fx' );
	if ( 'rates' === $do ) {
		$ok = sbt_fx_fetch();
		wp_safe_redirect( add_query_arg( 'sbt_msg', $ok ? 'rates' : 'rates_fail', $back ) );
		exit;
	}
	if ( 'switch' === $do || 'recalc' === $do ) {
		$off = isset( $_GET['off'] ) ? absint( $_GET['off'] ) : 0;
		if ( 0 === $off ) {
			if ( 'switch' === $do ) {
				$old = array_keys( sbt_fx_old_plugins() );
				if ( $old ) {
					deactivate_plugins( $old );
				}
				$s                     = get_option( 'sbt_settings', array() );
				$s['modules']['fx']    = 1;
				update_option( 'sbt_settings', $s );
			}
			if ( ! get_option( 'sbt_fx' ) ) {
				sbt_fx_fetch();
			}
		}
		$n = sbt_fx_recalc_batch( $off, 40 );
		if ( 40 === $n ) {
			wp_safe_redirect( wp_nonce_url( admin_url( 'admin-post.php?action=sbt_fx&do=' . $do . '&off=' . ( $off + 40 ) ), 'sbt_fx_' . $do ) );
			exit;
		}
		wc_delete_product_transients();
		wp_safe_redirect( add_query_arg( 'sbt_msg', 'done', $back ) );
		exit;
	}
	wp_safe_redirect( $back );
	exit;
} );

/** Rows for the preview / status table on the Currency tab. */
function sbt_fx_overview() {
	global $wpdb;
	$ids  = $wpdb->get_col( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type='product' AND p.post_status IN ('publish','draft','pending','private') AND ( (m.meta_key='intprice' AND m.meta_value<>'') OR (m.meta_key IN ('_alg_wc_cpp_currency','_wcj_currency_per_product_currency') AND m.meta_value NOT IN ('', '" . esc_sql( sbt_fx_base() ) . "')) ) ORDER BY p.ID" ); // phpcs:ignore
	$rows = array();
	$rates = sbt_fx_rates();
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p || ! in_array( $p->get_type(), sbt_fx_types(), true ) ) {
			continue;
		}
		$src = sbt_fx_source( $p );
		if ( ! $src ) {
			continue;
		}
		$new    = empty( $rates[ $src[1] ] ) ? null : round( $src[0] / $rates[ $src[1] ], 2 );
		$rows[] = array( 'id' => $id, 'name' => $p->get_name(), 'status' => get_post_status( $id ), 'shop' => sbt_fx_label( $src[0], $src[1] ), 'now' => $p->get_regular_price( 'edit' ), 'new' => $new, 'cur' => $src[1] );
	}
	return $rows;
}
