<?php
/**
 * Discontinue a listing (replaces public_html/discontinue-product.php and the Barn2 "Discontinued
 * Products" plugin). Keeps the page live for Google and old links, removes the link to the vendor,
 * hides it from the shop/search, marks it "Discontinued" and suggests similar boxes as upsells.
 * Everything it changes is remembered, so "Restore listing" puts it back exactly as it was.
 */
defined( 'ABSPATH' ) || exit;

const SBT_DISC_STATUS = 'discontinued';

/* --- The "Discontinued" stock status ------------------------------------- */

add_filter( 'woocommerce_product_stock_status_options', function ( $options ) {
	$options[ SBT_DISC_STATUS ] = __( 'Discontinued', 'sb-toolkit' );
	return $options;
} );

if ( ! defined( 'SBT_OLD_URL_KEY' ) ) {
	define( 'SBT_OLD_URL_KEY', 'sbt_old_url' );
}

function sbt_is_discontinued( $product ) {
	if ( ! $product instanceof WC_Product ) {
		// Early in a page the global $product can still be the slug/ID – look it up instead of crashing.
		$product = ( is_numeric( $product ) && $product ) ? wc_get_product( (int) $product ) : ( is_singular( 'product' ) ? wc_get_product( get_queried_object_id() ) : null );
	}
	return $product instanceof WC_Product && SBT_DISC_STATUS === $product->get_stock_status();
}

add_filter( 'woocommerce_product_is_in_stock', function ( $in, $product ) {
	return sbt_is_discontinued( $product ) ? false : $in;
}, 20, 2 );

add_filter( 'woocommerce_is_purchasable', function ( $ok, $product ) {
	return sbt_is_discontinued( $product ) ? false : $ok;
}, 20, 2 );

add_filter( 'woocommerce_get_availability_text', function ( $text, $product ) {
	return sbt_is_discontinued( $product ) ? 'Discontinued' : $text;
}, 20, 2 );

add_filter( 'woocommerce_get_availability_class', function ( $class, $product ) {
	return sbt_is_discontinued( $product ) ? 'out-of-stock discontinued' : $class;
}, 20, 2 );

/* --- Product page for a discontinued box ---------------------------------------
 * No price, a clean notice with a button, and a full-width row of similar boxes (shop-card style)
 * instead of the theme's squeezed upsells in the summary column. */

/** Up to $n similar boxes: the saved upsells first, topped up with fresh picks. */
function sbt_disc_similar_ids( $product, $n = 4 ) {
	$ids = array_values( array_filter( array_map( 'intval', (array) $product->get_upsell_ids() ), function ( $id ) {
		return 'publish' === get_post_status( $id );
	} ) );
	if ( count( $ids ) < $n && function_exists( 'sbt_disc_pick_upsells' ) ) {
		$key  = 'sbt_disc_more_' . $product->get_id();
		$more = get_transient( $key );
		if ( false === $more ) {
			$more = sbt_disc_pick_upsells( $product->get_id(), $n + 4 );
			set_transient( $key, $more, DAY_IN_SECONDS );
		}
		foreach ( (array) $more as $id ) {
			if ( count( $ids ) >= $n ) {
				break;
			}
			if ( ! in_array( (int) $id, $ids, true ) && (int) $id !== $product->get_id() ) {
				$ids[] = (int) $id;
			}
		}
	}
	return array_slice( $ids, 0, $n );
}

add_action( 'wp', function () {
	if ( ! is_singular( 'product' ) ) {
		return;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( ! sbt_is_discontinued( $product ) ) {
		return;
	}
	// The theme's upsells (in the summary column) – replaced by the row below.
	foreach ( array( 'woocommerce_single_product_summary', 'woocommerce_after_single_product_summary' ) as $hook ) {
		$pr = has_action( $hook, 'woocommerce_upsell_display' );
		if ( false !== $pr ) {
			remove_action( $hook, 'woocommerce_upsell_display', $pr );
		}
	}
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
	$rel = has_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products' );
	if ( false !== $rel ) {
		remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', $rel ); // the similar row replaces it
	}
	add_filter( 'woocommerce_get_price_html', function ( $html, $p ) use ( $product ) {
		return $p->get_id() === $product->get_id() ? '' : $html;
	}, 100, 2 );
	add_filter( 'sbt_show_shop_price_line', '__return_false' );

	add_action( 'woocommerce_single_product_summary', function () use ( $product ) {
		$sim = sbt_disc_similar_ids( $product, 4 );
		$msg = trim( (string) sbt_get( 'disc_message' ) );
		echo '<div class="sbt-disc-note">';
		echo '<span class="sbt-disc-pill">No longer available</span>';
		echo '<p class="sbt-disc-msg">' . esc_html( '' !== $msg ? $msg : 'This box is no longer available.' ) . '</p>';
		if ( $sim ) {
			echo '<a class="sbt-disc-btn" href="#sbt-similar">' . esc_html( sbt_get( 'disc_link_text' ) ) . ' &darr;</a>';
		}
		echo '</div>';
	}, 25 );

	add_action( 'woocommerce_after_single_product_summary', function () use ( $product ) {
		$sim = sbt_disc_similar_ids( $product, 4 );
		if ( ! $sim ) {
			return;
		}
		echo '<section id="sbt-similar" class="sbt-similar">';
		echo '<h2>Similar boxes you might like</h2>';
		echo '<ul class="products columns-' . count( $sim ) . '">';
		global $post;
		$keep = $post;
		foreach ( $sim as $id ) {
			$post = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			setup_postdata( $post );
			wc_get_template_part( 'content', 'product' );
		}
		$post = $keep; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		wp_reset_postdata();
		echo '</ul></section>';
	}, 5 );

	add_action( 'wp_head', function () {
		echo '<style>
.sbt-disc-note{margin:18px 0;padding:18px 20px;background:#f6f7f9;border:1px solid #e3e6ea;border-radius:10px}
.sbt-disc-pill{display:inline-block;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#6b7280;background:#e9ecef;border-radius:999px;padding:3px 10px;margin-bottom:8px}
.sbt-disc-msg{margin:0 0 12px;font-size:16px;color:#1f2937}
.sbt-disc-btn{display:inline-block;background:#00a8e8;color:#fff!important;font-weight:600;padding:10px 18px;border-radius:6px;text-decoration:none!important}
.sbt-disc-btn:hover{background:#0090c8}
.sbt-similar{clear:both;margin:40px 0 10px;padding-top:30px;border-top:1px solid #eee}
.sbt-similar h2{text-align:center;margin-bottom:24px}
.single-product .summary .cg-intprice{display:none}
.single-product .summary .woocommerce-product-attributes{display:none}
</style>';
	} );
}, 20 );

/* --- Picking similar boxes --------------------------------------------------- */

/** Category ids that say nothing about what the box is (admin, tiers, popular, plans…). */
function sbt_disc_ignored_cats() {
	$ids = array();
	foreach ( array( 'z-admin', 'zadmin' ) as $slug ) {
		$t = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $t ) {
			$ids[] = (int) $t->term_id;
			$ids   = array_merge( $ids, array_map( 'intval', get_term_children( $t->term_id, 'product_cat' ) ) );
		}
	}
	foreach ( array_merge( array( sbt_get( 'popular_cat' ), 'uncategorized' ), sbt_slugs( sbt_get( 'exclude_cats' ) ) ) as $slug ) {
		$t = get_term_by( 'slug', sanitize_title( $slug ), 'product_cat' );
		if ( $t ) {
			$ids[] = (int) $t->term_id;
		}
	}
	return array_unique( $ids );
}

/**
 * Up to $n live boxes from the same categories, most specific category first (e.g. "Dog Boxes" before
 * "Pets Boxes"). Paying listings first (Extreme, Premium…), shuffled within the same level.
 */
function sbt_disc_pick_upsells( $product_id, $n = 3 ) {
	$ignore = sbt_disc_ignored_cats();
	$cats   = array_diff( array_map( 'intval', wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) ) ), $ignore );
	if ( ! $cats ) {
		// Listing only had admin categories (tier / Slider) – fall back to Popular boxes so it still suggests something.
		$pop = get_term_by( 'slug', sanitize_title( (string) sbt_get( 'popular_cat' ) ), 'product_cat' );
		if ( ! $pop ) {
			return array();
		}
		$cats = array( (int) $pop->term_id );
		$ignore = array_diff( $ignore, $cats );
	}
	// Most specific first: deeper categories, then their parents as a fallback.
	$depth = function ( $id ) {
		return count( get_ancestors( $id, 'product_cat', 'taxonomy' ) );
	};
	usort( $cats, function ( $a, $b ) use ( $depth ) {
		return $depth( $b ) - $depth( $a );
	} );
	foreach ( $cats as $c ) {
		foreach ( get_ancestors( $c, 'product_cat', 'taxonomy' ) as $parent ) {
			if ( ! in_array( (int) $parent, $ignore, true ) ) {
				$cats[] = (int) $parent;
			}
		}
	}
	$cats = array_values( array_unique( $cats ) );

	$picked = array();
	foreach ( $cats as $cat ) {
		if ( count( $picked ) >= $n ) {
			break;
		}
		$ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'fields'         => 'ids',
			'orderby'        => 'rand',
			'post__not_in'   => array_merge( array( (int) $product_id ), $picked ),
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore
				'relation' => 'AND',
				array( 'taxonomy' => 'product_cat', 'terms' => array( $cat ), 'include_children' => true ),
				array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'exclude-from-catalog', 'exclude-from-search', 'outofstock' ), 'operator' => 'NOT IN' ),
			),
			'meta_query'     => array( array( 'key' => '_stock_status', 'value' => 'instock' ) ), // phpcs:ignore
		) );
		// Paying plans first (lower menu order), random within the same level.
		$order = array();
		foreach ( $ids as $id ) {
			$order[ $id ] = (int) get_post_field( 'menu_order', $id );
		}
		asort( $order );
		foreach ( array_keys( $order ) as $id ) {
			if ( count( $picked ) >= $n ) {
				break;
			}
			$picked[] = (int) $id;
		}
	}
	return $picked;
}

/* --- Discontinue / restore ---------------------------------------------------- */

/**
 * Take off the "promote me" extras – Popular / Slider categories and the YITH badge – and remember
 * them in _sbt_disc_prev so Restore puts them back. Safe to run again on an already-discontinued listing.
 */
function sbt_disc_strip_promo( $product ) {
	$prev = $product->get_meta( '_sbt_disc_prev' );
	$prev = is_array( $prev ) ? $prev : array();
	$promo = array();
	foreach ( array( sbt_get( 'popular_cat' ), 'slider' ) as $slug ) {
		$t = get_term_by( 'slug', sanitize_title( $slug ), 'product_cat' );
		if ( $t ) {
			$promo[] = (int) $t->term_id;
		}
	}
	$has     = array_map( 'intval', $product->get_category_ids() );
	$removed = array_values( array_intersect( $has, $promo ) );
	if ( $removed ) {
		$product->set_category_ids( array_values( array_diff( $has, $removed ) ) );
		$prev['cats_removed'] = array_values( array_unique( array_merge( isset( $prev['cats_removed'] ) ? (array) $prev['cats_removed'] : array(), $removed ) ) );
	}
	$badge = array();
	foreach ( $product->get_meta_data() as $m ) {
		$d = $m->get_data();
		if ( 0 === strpos( $d['key'], '_yith_wcbm' ) ) {
			$badge[ $d['key'] ] = $d['value'];
		}
	}
	if ( $badge ) {
		foreach ( array_keys( $badge ) as $k ) {
			$product->delete_meta_data( $k );
		}
		$prev['badge_meta'] = array_merge( isset( $prev['badge_meta'] ) ? (array) $prev['badge_meta'] : array(), $badge );
	}
	if ( $prev ) {
		$product->update_meta_data( '_sbt_disc_prev', $prev );
	}
}

/* --- What a discontinued page shows -------------------------------------------- */

/** No attributes table (Ships, Billed, Ships From…) – nothing to buy, so no point. */
add_filter( 'woocommerce_display_product_attributes', function ( $attrs, $product ) {
	return sbt_is_discontinued( $product ) ? array() : $attrs;
}, 99, 2 );

add_filter( 'woocommerce_product_tabs', function ( $tabs ) {
	global $product;
	if ( sbt_is_discontinued( $product ) ) {
		unset( $tabs['additional_information'] );
	}
	return $tabs;
}, 99 );

/** No links to the vendor: off-site links become plain text, social icons go. */
function sbt_disc_unlink( $html ) {
	$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	return preg_replace_callback( '#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function ( $m ) use ( $home ) {
		$host = (string) wp_parse_url( html_entity_decode( $m[2] ), PHP_URL_HOST );
		if ( '' === $host || $host === $home || 'www.' . $host === $home || 'www.' . $home === $host ) {
			return $m[0]; // own site – keep
		}
		return preg_match( '#<img\s#i', $m[3] ) ? '' : $m[3];
	}, (string) $html );
}

add_filter( 'woocommerce_short_description', function ( $html ) {
	global $product;
	return ( is_product() && sbt_is_discontinued( $product ) ) ? sbt_disc_unlink( $html ) : $html;
}, 99 );

add_filter( 'the_content', function ( $html ) {
	if ( ! is_singular( 'product' ) || ! in_the_loop() ) {
		return $html;
	}
	$p = wc_get_product( get_the_ID() );
	return sbt_is_discontinued( $p ) ? sbt_disc_unlink( $html ) : $html;
}, 99 );

/** Forget WooCommerce's cached copy of a product after changing its type. */
function sbt_disc_flush( $product_id ) {
	wc_delete_product_transients( $product_id );
	clean_post_cache( $product_id );
	if ( class_exists( 'WC_Cache_Helper' ) ) {
		WC_Cache_Helper::invalidate_cache_group( 'product_' . $product_id );
	}
	wp_cache_delete( $product_id, 'post_meta' );
}

/**
 * Keeps the vendor/affiliate link in an admin-only custom field (sbt_old_url) so it's never lost.
 * Never printed on the front end; shows in the Discontinue box, Custom Fields and CSV exports.
 */
function sbt_disc_keep_url( $product ) {
	if ( $product->get_meta( SBT_OLD_URL_KEY ) ) {
		return;
	}
	$url = is_callable( array( $product, 'get_product_url' ) ) ? $product->get_product_url( 'edit' ) : '';
	if ( ! $url ) {
		$prev = $product->get_meta( '_sbt_disc_prev' );
		$url  = is_array( $prev ) && ! empty( $prev['url'] ) ? $prev['url'] : '';
	}
	if ( $url ) {
		update_post_meta( $product->get_id(), SBT_OLD_URL_KEY, esc_url_raw( $url ) );
	}
}

// One-off: copy the link out for boxes discontinued before this field existed.
add_action( 'admin_init', function () {
	if ( get_option( 'sbt_old_url_backfill' ) ) {
		return;
	}
	$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_sbt_disc_prev' ) );
	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) {
			sbt_disc_keep_url( $product );
		}
	}
	update_option( 'sbt_old_url_backfill', 1, false );
} );

/** The Z-Admin → Discontinued category (created on first use). */
function sbt_disc_cat_id() {
	$t = get_term_by( 'slug', 'discontinued', 'product_cat' );
	if ( $t ) {
		return (int) $t->term_id;
	}
	$parent = 0;
	foreach ( array( 'zadmin', 'z-admin' ) as $slug ) {
		$pt = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $pt ) {
			$parent = (int) $pt->term_id;
			break;
		}
	}
	$r = wp_insert_term( 'Discontinued', 'product_cat', array( 'slug' => 'discontinued', 'parent' => $parent ) );
	return is_wp_error( $r ) ? 0 : (int) $r['term_id'];
}

/** Old categories that meant "closed" – swapped for Discontinued. */
function sbt_disc_legacy_cat_ids() {
	$ids = array();
	foreach ( array( 'currently-closed', 'currently-closed-boxes' ) as $slug ) {
		$t = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $t ) {
			$ids[] = (int) $t->term_id;
		}
	}
	return $ids;
}

/** Returns array( 'ok' => bool, 'msg' => string, 'upsells' => int[] ). */
function sbt_discontinue( $product_id, $note = '' ) {
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return array( 'ok' => false, 'msg' => 'Product not found.', 'upsells' => array() );
	}
	if ( ! sbt_is_discontinued( $product ) || ! $product->get_meta( '_sbt_disc_prev' ) ) {
		// Remember everything we change so Restore can undo it.
		$product->update_meta_data( '_sbt_disc_prev', array(
			'type'         => $product->get_type(),
			'url'          => is_callable( array( $product, 'get_product_url' ) ) ? $product->get_product_url( 'edit' ) : '',
			'button'       => is_callable( array( $product, 'get_button_text' ) ) ? $product->get_button_text( 'edit' ) : '',
			'visibility'   => $product->get_catalog_visibility(),
			'stock_status' => $product->get_stock_status(),
			'manage_stock' => $product->get_manage_stock(),
			'upsells'      => $product->get_upsell_ids(),
			'featured'     => $product->get_featured(),
		) );
		$product->save();
	}
	sbt_disc_keep_url( $product );
	// External → simple, so there's no "Subscribe To Box" button linking to the vendor.
	if ( 'external' === $product->get_type() ) {
		wp_set_object_terms( $product_id, 'simple', 'product_type' );
		delete_post_meta( $product_id, '_product_url' );
		delete_post_meta( $product_id, '_button_text' );
		sbt_disc_flush( $product_id );
		$product = wc_get_product( $product_id );
	}
	sbt_disc_strip_promo( $product );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_featured( false );
	if ( 'external' !== $product->get_type() ) {
		$product->set_manage_stock( false );
	}
	$product->set_stock_status( SBT_DISC_STATUS );
	$upsells = sbt_disc_pick_upsells( $product_id, max( 1, (int) sbt_get( 'disc_upsells' ) ) );
	if ( $upsells ) {
		$product->set_upsell_ids( $upsells );
	}
	$cats = array_diff( array_map( 'intval', $product->get_category_ids() ), sbt_disc_legacy_cat_ids() );
	$dc   = sbt_disc_cat_id();
	if ( $dc ) {
		$cats[] = $dc;
	}
	$product->set_category_ids( array_values( array_unique( $cats ) ) );
	$product->update_meta_data( '_sbt_disc_date', current_time( 'mysql' ) );
	if ( $note ) {
		$product->update_meta_data( '_sbt_disc_note', $note );
	}
	$product->save();
	wc_delete_product_transients( $product_id );
	return array( 'ok' => true, 'msg' => 'Discontinued.', 'upsells' => $upsells );
}

function sbt_undiscontinue( $product_id ) {
	$product = wc_get_product( $product_id );
	$prev    = $product ? $product->get_meta( '_sbt_disc_prev' ) : null;
	if ( ! $product || ! is_array( $prev ) ) {
		return array( 'ok' => false, 'msg' => 'Nothing to restore – this listing was not discontinued by the toolkit.' );
	}
	if ( 'external' === $prev['type'] && 'external' !== $product->get_type() ) {
		wp_set_object_terms( $product_id, 'external', 'product_type' );
		sbt_disc_flush( $product_id );
		$product = wc_get_product( $product_id );
		if ( $prev['url'] ) {
			$product->set_product_url( $prev['url'] );
		}
		if ( $prev['button'] ) {
			$product->set_button_text( $prev['button'] );
		}
	}
	$product->set_catalog_visibility( $prev['visibility'] ? $prev['visibility'] : 'visible' );
	if ( 'external' !== $product->get_type() ) {
		$product->set_manage_stock( (bool) $prev['manage_stock'] );
	}
	$product->set_stock_status( $prev['stock_status'] && SBT_DISC_STATUS !== $prev['stock_status'] ? $prev['stock_status'] : 'instock' );
	$product->set_upsell_ids( (array) $prev['upsells'] );
	$product->set_featured( ! empty( $prev['featured'] ) );
	if ( ! empty( $prev['cats_removed'] ) ) {
		$product->set_category_ids( array_values( array_unique( array_merge( $product->get_category_ids(), array_map( 'intval', $prev['cats_removed'] ) ) ) ) );
	}
	if ( ! empty( $prev['badge_meta'] ) && is_array( $prev['badge_meta'] ) ) {
		foreach ( $prev['badge_meta'] as $k => $v ) {
			$product->update_meta_data( $k, $v );
		}
	}
	$dc = sbt_disc_cat_id();
	if ( $dc ) {
		$product->set_category_ids( array_values( array_diff( array_map( 'intval', $product->get_category_ids() ), array( $dc ) ) ) );
	}
	$product->delete_meta_data( '_sbt_disc_prev' );
	$product->delete_meta_data( '_sbt_disc_date' );
	$product->delete_meta_data( '_sbt_disc_note' );
	$product->save();
	if ( function_exists( 'sbl_apply_plan' ) && $product->get_meta( '_sbl_plan' ) ) {
		$product->save(); // listing products re-apply their menu order on save
	}
	wc_delete_product_transients( $product_id );
	return array( 'ok' => true, 'msg' => 'Restored.' );
}

/* --- Product edit screen box ------------------------------------------------- */

add_action( 'add_meta_boxes_product', function () {
	add_meta_box( 'sbt-discontinue', 'Discontinue listing', 'sbt_disc_box', 'product', 'side', 'default' );
} );

function sbt_disc_action_url( $do, $id ) {
	return wp_nonce_url( admin_url( 'admin-post.php?action=sbt_disc&do=' . $do . '&id=' . (int) $id ), 'sbt_disc_' . $do . '_' . (int) $id );
}

function sbt_disc_box( $post ) {
	$product = wc_get_product( $post->ID );
	if ( ! $product ) {
		return;
	}
	if ( sbt_is_discontinued( $product ) ) {
		$date = $product->get_meta( '_sbt_disc_date' );
		echo '<p><strong style="color:#d63638">Discontinued</strong>' . ( $date ? ' on ' . esc_html( mysql2date( 'j M Y', $date ) ) : '' ) . '</p>';
		if ( $product->get_meta( '_sbt_disc_note' ) ) {
			echo '<p class="description">' . esc_html( $product->get_meta( '_sbt_disc_note' ) ) . '</p>';
		}
		$old = $product->get_meta( SBT_OLD_URL_KEY );
		if ( $old ) {
			echo '<p style="word-break:break-all"><strong>Old vendor link</strong> (admin only):<br><a href="' . esc_url( $old ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $old ) . '</a></p>';
		}
		$ups = $product->get_upsell_ids();
		if ( $ups ) {
			echo '<p>Suggesting instead:</p><ul style="margin:0 0 10px 16px;list-style:disc">';
			foreach ( $ups as $u ) {
				echo '<li><a href="' . esc_url( get_edit_post_link( $u ) ) . '">' . esc_html( get_the_title( $u ) ) . '</a></li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="description">No similar boxes found – add some under Product data → Linked Products → Upsells.</p>';
		}
		echo '<p><a class="button" href="' . esc_url( sbt_disc_action_url( 'reupsell', $post->ID ) ) . '">Refresh (new suggestions)</a></p>';
		if ( $product->get_meta( '_sbt_disc_prev' ) ) {
			echo '<p><a class="button" href="' . esc_url( sbt_disc_action_url( 'restore', $post->ID ) ) . '" onclick="return confirm(\'Put this listing back exactly as it was before it was discontinued?\')">Restore listing</a></p>';
		}
		echo '<p><a href="' . esc_url( get_permalink( $post->ID ) ) . '" target="_blank">View page ↗</a></p>';
		return;
	}
	echo '<p class="description">For a box that has closed or a vendor who has left: the page stays live (keeps its Google ranking), the link to the vendor and their social links are removed, the Popular badge and box details come off, it is hidden from the shop and search, shows "no longer available", and suggests ' . (int) sbt_get( 'disc_upsells' ) . ' similar boxes instead. You can restore it later.</p>';
	echo '<p><a class="button button-primary" href="' . esc_url( sbt_disc_action_url( 'discontinue', $post->ID ) ) . '" onclick="return confirm(\'Discontinue this listing and suggest similar boxes?\')">Mark discontinued + upsells</a></p>';
}

add_action( 'admin_post_sbt_disc', function () {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
	if ( ! $id || ! current_user_can( 'edit_product', $id ) || ! check_admin_referer( 'sbt_disc_' . $do . '_' . $id ) ) {
		wp_die( 'Not allowed.' );
	}
	if ( 'discontinue' === $do ) {
		$r = sbt_discontinue( $id );
	} elseif ( 'restore' === $do ) {
		$r = sbt_undiscontinue( $id );
	} elseif ( 'reupsell' === $do ) {
		$r        = sbt_discontinue( $id );
		$r['msg'] = 'Refreshed – new suggestions picked, badge and Popular removed.';
	} else {
		wp_die( 'Unknown action.' );
	}
	set_transient( 'sbt_disc_msg_' . get_current_user_id(), $r, 60 );
	wp_safe_redirect( admin_url( 'post.php?post=' . $id . '&action=edit' ) );
	exit;
} );

add_action( 'admin_notices', function () {
	$r = get_transient( 'sbt_disc_msg_' . get_current_user_id() );
	if ( ! $r ) {
		return;
	}
	delete_transient( 'sbt_disc_msg_' . get_current_user_id() );
	$extra = '';
	if ( ! empty( $r['upsells'] ) ) {
		$extra = ' Suggesting: ' . implode( ', ', array_map( 'get_the_title', $r['upsells'] ) ) . '.';
	} elseif ( isset( $r['upsells'] ) && $r['ok'] ) {
		$extra = ' No similar live boxes found – add upsells by hand if you want some.';
	}
	echo '<div class="notice notice-' . ( $r['ok'] ? 'success' : 'error' ) . ' is-dismissible"><p><strong>Listing:</strong> ' . esc_html( $r['msg'] . $extra ) . ' Purge LiteSpeed/Cloudflare for the page to update straight away.</p></div>';
} );

/* --- Bulk action on Products → All Products ----------------------------------- */

add_filter( 'bulk_actions-edit-product', function ( $actions ) {
	$actions['sbt_discontinue'] = 'Discontinue + upsells';
	return $actions;
} );

add_filter( 'handle_bulk_actions-edit-product', function ( $redirect, $action, $ids ) {
	if ( 'sbt_discontinue' !== $action ) {
		return $redirect;
	}
	$n = 0;
	foreach ( $ids as $id ) {
		if ( current_user_can( 'edit_product', $id ) && ! empty( sbt_discontinue( $id )['ok'] ) ) {
			$n++;
		}
	}
	set_transient( 'sbt_disc_msg_' . get_current_user_id(), array( 'ok' => true, 'msg' => $n . ' listing' . ( 1 === $n ? '' : 's' ) . ' discontinued with suggestions.' ), 60 );
	return $redirect;
}, 10, 3 );

/* --- Paste a list of IDs ----------------------------------------------------- */

/** Box under the Discontinued tab: paste product IDs (any separators) and discontinue or restore them all. */
function sbt_disc_paste_box() {
	$res = get_transient( 'sbt_disc_paste_res_' . get_current_user_id() );
	if ( $res ) {
		delete_transient( 'sbt_disc_paste_res_' . get_current_user_id() );
		echo '<div class="notice notice-success"><p><strong>' . esc_html( $res['label'] ) . ':</strong> ' . (int) $res['done'] . ' done';
		if ( $res['skipped'] ) {
			echo ', skipped (not found or nothing to restore): ' . esc_html( implode( ', ', $res['skipped'] ) );
		}
		echo '.</p></div>';
	}
	?>
	<div class="sbt-card">
		<h2 style="margin-top:0">Paste a list of IDs</h2>
		<p class="sbt-muted">Paste product IDs from a spreadsheet: one per line, or separated by commas or spaces. They're done 20 at a time, so a long list just takes a few seconds longer.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sbt_disc_paste">
			<?php wp_nonce_field( 'sbt_disc_paste' ); ?>
			<textarea name="ids" rows="6" style="width:100%;max-width:620px;font-family:monospace" placeholder="24&#10;41&#10;137"></textarea>
			<p>
				<label><input type="radio" name="do" value="discontinue" checked> Discontinue + upsells</label> &nbsp;
				<label><input type="radio" name="do" value="restore"> Restore listing</label>
			</p>
			<p><button class="button button-primary" onclick="return confirm('Run this on every ID in the list?')">Run</button></p>
		</form>
	</div>
	<?php
}

add_action( 'admin_post_sbt_disc_paste', function () {
	if ( ! current_user_can( 'edit_products' ) ) {
		wp_die( 'Not allowed.' );
	}
	$uid = get_current_user_id();
	$key = 'sbt_disc_paste_q_' . $uid;
	if ( isset( $_POST['ids'] ) ) {
		check_admin_referer( 'sbt_disc_paste' );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', preg_split( '/[^0-9]+/', wp_unslash( $_POST['ids'] ) ) ) ) ) );
		$do  = ( isset( $_POST['do'] ) && 'restore' === $_POST['do'] ) ? 'restore' : 'discontinue';
		$q   = array( 'ids' => $ids, 'do' => $do, 'done' => 0, 'skipped' => array() );
	} else {
		check_admin_referer( 'sbt_disc_paste_next' );
		$q = get_transient( $key );
		if ( ! is_array( $q ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=sb-toolkit&tab=disc' ) );
			exit;
		}
	}
	$batch = array_splice( $q['ids'], 0, 20 );
	foreach ( $batch as $id ) {
		if ( 'product' !== get_post_type( $id ) ) {
			$q['skipped'][] = $id;
			continue;
		}
		$r = 'restore' === $q['do'] ? sbt_undiscontinue( $id ) : sbt_discontinue( $id );
		if ( empty( $r['ok'] ) ) {
			$q['skipped'][] = $id;
		} else {
			$q['done']++;
		}
	}
	if ( $q['ids'] ) {
		set_transient( $key, $q, HOUR_IN_SECONDS );
		wp_safe_redirect( add_query_arg( '_wpnonce', wp_create_nonce( 'sbt_disc_paste_next' ), admin_url( 'admin-post.php?action=sbt_disc_paste' ) ) ); // not wp_nonce_url: that HTML-escapes the & and breaks the redirect
		exit;
	}
	delete_transient( $key );
	set_transient( 'sbt_disc_paste_res_' . $uid, array(
		'label'   => 'restore' === $q['do'] ? 'Restored' : 'Discontinued',
		'done'    => $q['done'],
		'skipped' => $q['skipped'],
	), 10 * MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'admin.php?page=sb-toolkit&tab=disc' ) );
	exit;
} );
