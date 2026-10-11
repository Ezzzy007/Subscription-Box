<?php
/**
 * Vendor listings (was the separate "Subscription Box Listings" plugin): self-serve listing set-up
 * at My Account → My Listing, approval emails, cancel/expire lifecycle, Box Swap, marketplace upgrade perks
 * and menu order rules. Limits are editable on SB Toolkit → Vendor listings.
 */
defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

/** What each plan includes. Images / categories / menu order come from SB Toolkit → Vendor listings. */
function sbl_plans() {
	$plans = array(
		'mini'    => array( 'label' => 'Mini',    'images' => 1,  'cats' => 1,  'social' => false, 'popular' => false, 'featured' => false, 'tag_label' => false, 'order' => 6 ),
		'basic'   => array( 'label' => 'Basic',   'images' => 3,  'cats' => 2,  'social' => false, 'popular' => false, 'featured' => false, 'tag_label' => false, 'order' => 5 ),
		'premium' => array( 'label' => 'Premium', 'images' => 5,  'cats' => 3,  'social' => true,  'popular' => true,  'featured' => false, 'tag_label' => false, 'order' => 2 ),
		'extreme' => array( 'label' => 'Extreme', 'images' => 20, 'cats' => 0,  'social' => true,  'popular' => true,  'featured' => true,  'tag_label' => true,  'order' => 1 ), // cats 0 = unlimited
		// Box Swap: vendor sends boxes instead of paying; you pick Basic/Premium/Extreme when approving.
		'boxswap' => array( 'label' => 'Box Swap', 'images' => 20, 'cats' => 0,  'social' => true,  'popular' => false, 'featured' => false, 'tag_label' => false, 'order' => 7, 'swap' => true ),
	);
	foreach ( (array) sbt_get( 'listing_limits' ) as $key => $lim ) {
		if ( isset( $plans[ $key ] ) ) {
			$plans[ $key ]['images'] = max( 1, (int) $lim['images'] );
			$plans[ $key ]['cats']   = max( 0, (int) $lim['cats'] );
			$plans[ $key ]['order']  = (int) $lim['order'];
		}
	}
	return $plans;
}

/** Popular category slug for this site (SB Toolkit → Carousels). */
function sbl_popular_cat() {
	return sanitize_title( (string) sbt_get( 'popular_cat' ) );
}

function sbl_button_text() {
	$t = trim( (string) sbt_get( 'listing_button' ) );
	return '' !== $t ? $t : 'Subscribe To Box';
}

function sbl_max_upload_mb() {
	return max( 1, (int) sbt_get( 'listing_max_mb' ) );
}

/** Levels you can give a Box Swap listing. */
const SBL_SWAP_TIERS   = array( 'basic', 'premium', 'extreme' );
const SBL_BOX_SWAP_CAT = 'box-swap'; // "Box Swap" category under Z-Admin

/** The plan whose perks a listing gets: for Box Swap, the level you assigned (until then, none). */
function sbl_listing_plan_key( $plan, $product ) {
	if ( 'boxswap' === $plan && $product ) {
		$tier = $product->get_meta( '_sbl_tier' );
		if ( in_array( $tier, SBL_SWAP_TIERS, true ) ) {
			return $tier;
		}
	}
	return $plan;
}

const SBL_PLANS_CAT      = 'plans';         // category the plan products are in
const SBL_ADMIN_PARENTS  = array( 'z-admin', 'zadmin' ); // hidden parent category for tier categories (NZ, AU)
const SBL_ENDPOINT       = 'my-listing';
const SBL_SLIDER_CAT     = 'slider';        // homepage slider category (under Z-Admin) – Extreme listings go in here
const SBL_CANCELLED_CAT  = 'cancelled';     // added when a plan ends, removed if it comes back

/** Social networks on the form, with the icon images already used on the AU site. */
function sbl_social_networks() {
	$base = 'https://www.subscriptionboxaustralia.com/wp-content/uploads/2018/05/';
	return apply_filters( 'sbl_social_networks', array(
		'instagram' => array( 'label' => 'Instagram', 'icon' => $base . 'insta.png' ),
		'facebook'  => array( 'label' => 'Facebook',  'icon' => $base . 'facey2.jpg' ),
		'tiktok'    => array( 'label' => 'TikTok',    'icon' => $base . 'tiktok.png' ),
		'youtube'   => array( 'label' => 'YouTube',   'icon' => $base . 'youtube.png' ),
	) );
}

/**
 * The product meta field your theme shows as the small subtitle under the name in the shop grid
 * (the "cg-subtitle" line). Worked out from the theme code, else from existing products.
 */
function sbl_subtitle_key() {
	static $key = null;
	if ( null !== $key ) {
		return $key;
	}
	$key = (string) apply_filters( 'sbl_subtitle_key', '' );
	if ( $key ) {
		return $key;
	}
	// 1) Read the theme: find the code that prints class="cg-subtitle" and the field it reads.
	$dirs = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
	foreach ( $dirs as $dir ) {
		$files = array_merge( (array) glob( $dir . '/*.php' ), (array) glob( $dir . '/inc/*.php' ), (array) glob( $dir . '/inc/*/*.php' ) );
		foreach ( $files as $file ) {
			$code = (string) @file_get_contents( $file ); // phpcs:ignore
			$pos  = strpos( $code, 'cg-subtitle' );
			if ( false === $pos ) {
				continue;
			}
			$near = substr( $code, max( 0, $pos - 1500 ), 1700 );
			if ( preg_match_all( '/(?:get_post_meta|get_field|get_meta)\s*\([^;]*?[\'"]([A-Za-z0-9_\-]+)[\'"]/', $near, $m ) ) {
				$key = end( $m[1] );
				return $key;
			}
		}
	}
	// 2) Most-used common subtitle field on existing products.
	global $wpdb;
	$cands = array( 'subtitle', 'product_subtitle', '_subtitle', '_product_subtitle', 'wps_subtitle', '_wc_product_subtitle', 'cg_subtitle', '_cg_subtitle', 'shoptimizer_subtitle' );
	$in    = implode( ',', array_fill( 0, count( $cands ), '%s' ) );
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT pm.meta_key, COUNT(*) c FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'product' AND pm.meta_value <> '' AND pm.meta_value NOT LIKE 'field\_%%' AND pm.meta_key IN ($in) GROUP BY pm.meta_key ORDER BY c DESC LIMIT 1", $cands ) ); // phpcs:ignore
	$key   = $row ? $row->meta_key : 'subtitle';
	return $key;
}

/** Save the subtitle in the theme's field (and keep ACF happy if it's an ACF field). */
function sbl_set_subtitle( WC_Product $product, $subtitle ) {
	global $wpdb;
	$key = sbl_subtitle_key();
	$product->update_meta_data( $key, $subtitle );
	$product->update_meta_data( '_sbl_subtitle', $subtitle );
	if ( 0 !== strpos( $key, '_' ) ) {
		$ref = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1", '_' . $key, 'field\_%' ) );
		if ( $ref ) {
			$product->update_meta_data( '_' . $key, $ref ); // ACF field reference
		}
	}
}

/** "Subscription Box Australia" or "Subscription Box NZ" – used in the description heading. */
function sbl_site_suffix() {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	return apply_filters( 'sbl_site_suffix', false !== strpos( $host, 'australia' ) ? 'Subscription Box Australia' : 'Subscription Box NZ' );
}

/** Heading text: "Treat Crate Box", "Kiwi Snack Box" (adds " Box" unless the name already ends in box/boxes). */
function sbl_heading_name( $name ) {
	return preg_match( '/\bbox(es)?$/i', trim( $name ) ) ? trim( $name ) : trim( $name ) . ' Box';
}

/** Build the long description in the house layout. $body is the vendor's text as HTML paragraphs. */
function sbl_build_description( $name, $body ) {
	return '<h2 class="product__title h2">' . esc_html( sbl_heading_name( $name ) . ' | ' . sbl_site_suffix() ) . "</h2>\n<hr />\n" . $body;
}

/** Build the short description: tagline, divider, social icons (house layout). */
function sbl_build_short_description( $tagline, array $social, $plan ) {
	$plans = sbl_plans();
	$html  = $tagline ? '<p>' . esc_html( $tagline ) . '</p>' : '';
	if ( $social && ! empty( $plans[ $plan ]['social'] ) ) {
		$links = '';
		foreach ( sbl_social_networks() as $key => $net ) {
			if ( ! empty( $social[ $key ] ) ) {
				$links .= '<a href="' . esc_url( $social[ $key ] ) . '" target="_blank" rel="noopener"><img class="socials" src="' . esc_url( $net['icon'] ) . '" alt="' . esc_attr( $net['label'] ) . '" width="50" height="50" /></a>';
			}
		}
		if ( $links ) {
			$html .= "\n<hr />\n<p>" . $links . '</p>';
		}
	}
	return $html;
}

/**
 * Details fields on the form. Missing taxonomies are skipped.
 * type: single (pick one) | multi (pick any). source: frequency | fixed | country | terms. other: allow "type your own".
 */
function sbl_attribute_fields() {
	return array(
		'pa_ships'        => array( 'type' => 'single', 'source' => 'frequency' ),
		'pa_billed'       => array( 'type' => 'single', 'source' => 'frequency' ),
		'pa_free-postage' => array( 'type' => 'single', 'source' => 'fixed', 'options' => array( 'Yes', 'No' ) ),
		'pa_cancel'       => array( 'type' => 'single', 'source' => 'fixed', 'options' => array( 'Anytime' ), 'other' => true ),
		'pa_currency'     => array( 'type' => 'single', 'source' => 'terms' ),
		'pa_ships-from'   => array( 'type' => 'single', 'source' => 'country' ),
		'pa_ships-to'     => array( 'type' => 'multi',  'source' => 'country', 'worldwide' => true ),
	);
}

/** Tidy country list (this site's country first) and the messy names that mean the same thing. */
function sbl_countries() {
	$list = array( 'New Zealand', 'Australia', 'USA', 'UK', 'Canada', 'Japan', 'Korea' );
	if ( false !== strpos( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'australia' ) ) {
		$list = array_merge( array( 'Australia', 'New Zealand' ), array_slice( $list, 2 ) );
	}
	return apply_filters( 'sbl_countries', $list );
}
function sbl_country_aliases() {
	return array( 'nz' => 'New Zealand', 'new zealand' => 'New Zealand', 'aus' => 'Australia', 'au' => 'Australia', 'australia' => 'Australia', 'us' => 'USA', 'usa' => 'USA', 'united states' => 'USA', 'america' => 'USA', 'uk' => 'UK', 'united kingdom' => 'UK', 'england' => 'UK', 'gb' => 'UK', 'can' => 'Canada', 'canada' => 'Canada', 'japan' => 'Japan', 'korea' => 'Korea', 'south korea' => 'Korea', 'worldwide' => 'Worldwide', 'world wide' => 'Worldwide', 'international' => 'Worldwide' );
}

/** Weeks between boxes for a frequency label, or 0 if it isn't a frequency. Used to sort shortest → longest. */
function sbl_frequency_rank( $name ) {
	$n = strtolower( trim( $name ) );
	$rules = array(
		'/bi-?weekly|fortnight|2 weeks/'                => 2,
		'/weekly|every week|1 week/'                    => 1,
		'/bi-?monthly|every (2|two) months|^2 months/'  => 8.5,
		'/quarter|every (3|three) months|^3 months/'    => 13,
		'/every (4|four) months|^4 months/'             => 17,
		'/bi-?annual|half[- ]year|(6|six) months|twice a year/' => 26,
		'/year|annual|12 months/'                       => 52,
		'/month/'                                       => 4.3,
	);
	foreach ( $rules as $re => $rank ) {
		if ( preg_match( $re, $n ) ) {
			return $rank;
		}
	}
	return 0;
}

/** Options to show for a field, in display order. */
function sbl_attr_options( $tax, array $cfg ) {
	switch ( $cfg['source'] ) {
		case 'fixed':
			return $cfg['options'];
		case 'country':
			return array_merge( sbl_countries(), empty( $cfg['worldwide'] ) ? array() : array( 'Worldwide' ) );
		case 'frequency':
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
			$seen  = array();
			foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
				$name = html_entity_decode( $t->name );
				$rank = sbl_frequency_rank( $name );
				if ( $rank && ! isset( $seen[ (string) $rank ] ) ) {
					$seen[ (string) $rank ] = $name; // one label per frequency, non-frequencies dropped
				}
			}
			uksort( $seen, function ( $a, $b ) { return (float) $a <=> (float) $b; } );
			return array_values( $seen );
		default: // existing terms, de-duplicated (e.g. "AUD"/"aud")
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'name' ) );
			$out   = array();
			foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
				$out[ strtoupper( trim( html_entity_decode( $t->name ) ) ) ] = strtoupper( trim( html_entity_decode( $t->name ) ) );
			}
			$first = array_intersect( array( 'NZD', 'AUD', 'USD', 'GBP', 'CAD', 'EUR', 'JPY' ), $out );
			return array_values( array_unique( array_merge( $first, $out ) ) );
	}
}

/** Map a stored term name onto one of the field's option labels (or keep it, for "type your own"). */
function sbl_attr_canonical( $tax, array $cfg, $name ) {
	$name = trim( html_entity_decode( (string) $name ) );
	$low  = strtolower( $name );
	switch ( $cfg['source'] ) {
		case 'country':
			$aliases = sbl_country_aliases();
			return isset( $aliases[ $low ] ) ? $aliases[ $low ] : $name;
		case 'frequency':
			$rank = sbl_frequency_rank( $name );
			foreach ( sbl_attr_options( $tax, $cfg ) as $opt ) {
				if ( $rank && sbl_frequency_rank( $opt ) === $rank ) {
					return $opt;
				}
			}
			return $name;
		case 'fixed':
			foreach ( $cfg['options'] as $opt ) {
				if ( 0 === strpos( $low, strtolower( $opt ) ) ) {
					return $opt; // "No - $10.00" counts as "No"
				}
			}
			return $name;
		default:
			return strtoupper( $name );
	}
}

/** Term ID for a label: an exact match, else a messy term that means the same thing, else a new term. */
function sbl_attr_term_id( $tax, array $cfg, $label ) {
	$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
	$terms = is_wp_error( $terms ) ? array() : $terms;
	foreach ( $terms as $t ) {
		if ( 0 === strcasecmp( html_entity_decode( $t->name ), $label ) ) {
			return (int) $t->term_id;
		}
	}
	foreach ( $terms as $t ) {
		if ( 'fixed' !== $cfg['source'] && sbl_attr_canonical( $tax, $cfg, $t->name ) === $label ) {
			return (int) $t->term_id;
		}
	}
	$made = wp_insert_term( $label, $tax );
	return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
}

/** Who gets the approval emails. */
function sbl_admin_email() {
	return apply_filters( 'sbl_admin_email', get_option( 'admin_email' ) );
}

/* -------------------------------------------------------------------------
 * Plan helpers
 * ---------------------------------------------------------------------- */

/** Plan key (mini|basic|premium|extreme) for a plan product or variation, or ''. */
function sbl_plan_from_product( $product_id ) {
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return '';
	}
	$parent_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	$swap_text = strtolower( get_post_field( 'post_name', $parent_id ) . ' ' . get_the_title( $parent_id ) );
	if ( false !== strpos( $swap_text, 'marketplace' ) ) {
		return false !== strpos( $swap_text, 'extreme' ) ? 'mkt_extreme' : ( false !== strpos( $swap_text, 'premium' ) ? 'mkt_premium' : '' );
	}
	if ( false !== strpos( $swap_text, 'box-swap' ) || false !== strpos( $swap_text, 'box swap' ) ) {
		return 'boxswap';
	}
	if ( ! has_term( SBL_PLANS_CAT, 'product_cat', $parent_id ) ) {
		return '';
	}
	$haystack = strtolower( get_post_field( 'post_name', $parent_id ) . ' ' . get_the_title( $parent_id ) );
	foreach ( array( 'extreme', 'premium', 'basic', 'mini' ) as $key ) {
		if ( false !== strpos( $haystack, $key ) ) {
			return $key;
		}
	}
	return '';
}

/** Plan key for a listing subscription, or '' (marketplace upgrades are handled separately). */
function sbl_plan_from_subscription( $subscription ) {
	foreach ( $subscription->get_items() as $item ) {
		$plan = sbl_plan_from_product( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
		if ( $plan && 0 !== strpos( $plan, 'mkt_' ) ) {
			return $plan;
		}
	}
	return '';
}

/**
 * If this subscription has no listing but the vendor already has one (e.g. they resubscribed
 * after cancelling), link that listing to it instead of starting a new one.
 * Returns the listing product ID or 0.
 */
function sbl_adopt_listing( $sub ) {
	if ( ! $sub || $sub->get_meta( '_sbl_listing_id' ) ) {
		return (int) ( $sub ? $sub->get_meta( '_sbl_listing_id' ) : 0 );
	}
	$listing_id = sbl_user_listing_id( $sub->get_customer_id() );
	if ( ! $listing_id ) {
		return 0;
	}
	$old_id = (int) get_post_meta( $listing_id, '_sbl_subscription_id', true );
	$old    = $old_id ? wcs_get_subscription( $old_id ) : null;
	if ( $old && $old->get_id() !== $sub->get_id() && $old->has_status( array( 'active', 'pending-cancel', 'on-hold' ) ) ) {
		return 0; // still attached to another live plan
	}
	if ( $old && $old->get_id() !== $sub->get_id() ) {
		$old->delete_meta_data( '_sbl_listing_id' );
		$old->save();
	}
	update_post_meta( $listing_id, '_sbl_subscription_id', $sub->get_id() );
	$sub->update_meta_data( '_sbl_listing_id', $listing_id );
	$sub->save();
	$product = wc_get_product( $listing_id );
	$plan    = sbl_plan_from_subscription( $sub );
	if ( $product && $plan ) {
		sbl_apply_plan( $product, $plan );
		if ( 'hidden' === $product->get_meta( '_sbl_state' ) && $sub->has_status( array( 'active', 'pending-cancel' ) ) ) {
			$product->update_meta_data( '_sbl_state', 'live' );
			$product->set_category_ids( array_values( array_diff( $product->get_category_ids(), array( sbl_category_id( SBL_CANCELLED_CAT ) ) ) ) );
			$product->set_status( 'publish' );
			$product->save();
			sbl_notify_admin( $product, 'restored', $sub );
		} else {
			$product->save();
		}
	}
	return $listing_id;
}

/** The user's current listing-plan subscription (active, pending-cancel or on-hold), or null. */
function sbl_user_subscription( $user_id ) {
	if ( ! $user_id || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
		return null;
	}
	$best = null;
	foreach ( wcs_get_users_subscriptions( $user_id ) as $sub ) {
		if ( ! $sub->has_status( array( 'active', 'pending-cancel', 'on-hold' ) ) || ! sbl_plan_from_subscription( $sub ) ) {
			continue;
		}
		// Prefer one that already has a listing, then the newest.
		if ( ! $best || ( $sub->get_meta( '_sbl_listing_id' ) && ! $best->get_meta( '_sbl_listing_id' ) ) ) {
			$best = $sub;
		}
	}
	return $best;
}

/** Get-or-create a category by slug (optionally under a parent slug). Returns term ID or 0. */
function sbl_category_id( $slug, $name = '', $parent_slug = '' ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $term ) {
		return (int) $term->term_id;
	}
	if ( ! $name ) {
		return 0;
	}
	$parent = $parent_slug ? get_term_by( 'slug', $parent_slug, 'product_cat' ) : null;
	$made   = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug, 'parent' => $parent ? $parent->term_id : 0 ) );
	return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
}

/** The hidden admin parent category on this site (z-admin on NZ, zadmin on AU), or null. */
function sbl_admin_parent() {
	foreach ( SBL_ADMIN_PARENTS as $slug ) {
		$t = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $t ) {
			return $t;
		}
	}
	return null;
}

/** Tier category IDs for every plan. Uses an existing "<Plan> Subscription" category if there is one, else creates it under the admin parent. */
function sbl_tier_category_ids() {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}
	$parent = sbl_admin_parent();
	$ids    = array();
	foreach ( sbl_plans() as $key => $plan ) {
		if ( ! empty( $plan['swap'] ) ) {
			continue;
		}
		$name = $plan['label'] . ' Subscription';
		$id   = sbl_category_id( $key . '-subscription' );
		if ( ! $id ) {
			$found = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'name' => $name, 'number' => 1 ) );
			$id    = ( ! is_wp_error( $found ) && $found ) ? (int) $found[0]->term_id : 0;
		}
		if ( ! $id ) {
			$made = wp_insert_term( $name, 'product_cat', array( 'slug' => $key . '-subscription', 'parent' => $parent ? $parent->term_id : 0 ) );
			$id   = is_wp_error( $made ) ? 0 : (int) $made['term_id'];
		}
		$ids[ $key ] = $id;
	}
	return $ids;
}

/** Categories vendors may pick from: everything except admin/system ones. id => label */
function sbl_choosable_categories() {
	$exclude = array( sbl_popular_cat(), 'uncategorized', SBL_PLANS_CAT );
	$admin   = sbl_admin_parent();
	$terms   = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	$out     = array();
	if ( is_wp_error( $terms ) ) {
		return $out;
	}
	$by_id = array();
	foreach ( $terms as $t ) {
		$by_id[ $t->term_id ] = $t;
	}
	foreach ( $terms as $t ) {
		if ( in_array( $t->slug, $exclude, true ) ) {
			continue;
		}
		if ( $admin && ( $t->term_id === (int) $admin->term_id || in_array( (int) $admin->term_id, get_ancestors( $t->term_id, 'product_cat' ), true ) ) ) {
			continue;
		}
		$label = html_entity_decode( $t->name );
		if ( $t->parent && isset( $by_id[ $t->parent ] ) ) {
			$label = html_entity_decode( $by_id[ $t->parent ]->name ) . ' › ' . $label;
		}
		$out[ $t->term_id ] = $label;
	}
	asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
	return $out;
}

/** Detail fields that exist on this site: taxonomy => [label, cfg, options] */
function sbl_available_attributes() {
	$out = array();
	foreach ( sbl_attribute_fields() as $tax => $cfg ) {
		if ( ! taxonomy_exists( $tax ) ) {
			continue;
		}
		$options = sbl_attr_options( $tax, $cfg );
		if ( ! $options && empty( $cfg['other'] ) ) {
			continue;
		}
		$out[ $tax ] = array( 'label' => wc_attribute_label( $tax ), 'cfg' => $cfg, 'options' => $options );
	}
	return $out;
}

/** Current selection for a field as option labels (from term IDs or labels). */
function sbl_attr_current_labels( $tax, array $cfg, $values ) {
	$out = array();
	foreach ( (array) $values as $val ) {
		if ( is_numeric( $val ) ) {
			$t   = get_term( (int) $val, $tax );
			$val = ( $t && ! is_wp_error( $t ) ) ? $t->name : '';
		}
		if ( '' !== $val ) {
			$out[] = sbl_attr_canonical( $tax, $cfg, $val );
		}
	}
	return array_values( array_unique( $out ) );
}

/* -------------------------------------------------------------------------
 * Applying data to a listing product
 * ---------------------------------------------------------------------- */

/**
 * Write listing data onto a product.
 * $data keys: name, url, tagline, description, price, cats[], attrs[tax=>ids[]], image_ids[], social[]
 */
function sbl_apply_data( WC_Product_External $product, array $data, $plan ) {
	$product->set_name( $data['name'] );
	if ( array_key_exists( 'subtitle', $data ) ) {
		sbl_set_subtitle( $product, $data['subtitle'] );
	}
	$product->set_product_url( $data['url'] );
	$product->set_button_text( sbl_button_text() );
	$product->update_meta_data( '_sbl_tagline', $data['tagline'] );
	$product->update_meta_data( '_sbl_body', $data['description'] );
	$product->set_short_description( sbl_build_short_description( $data['tagline'], array_filter( (array) $data['social'] ), $plan ) );
	$product->set_description( sbl_build_description( $data['name'], $data['description'] ) );
	$product->set_regular_price( '' !== $data['price'] ? wc_format_decimal( $data['price'] ) : '' );

	$attributes = array();
	$position   = 0;
	foreach ( $data['attrs'] as $tax => $term_ids ) {
		$term_ids = array_filter( array_map( 'absint', (array) $term_ids ) );
		if ( ! $term_ids || ! taxonomy_exists( $tax ) ) {
			continue;
		}
		$attr = new WC_Product_Attribute();
		$attr->set_id( wc_attribute_taxonomy_id_by_name( $tax ) );
		$attr->set_name( $tax );
		$attr->set_options( $term_ids );
		$attr->set_position( $position++ );
		$attr->set_visible( true );
		$attr->set_variation( false );
		$attributes[] = $attr;
	}
	$product->set_attributes( $attributes );

	// Currency also goes on as a product tag (matches existing listings) and, if Currency per Product is active, as its currency.
	$tag_ids = array();
	if ( ! empty( $data['attrs']['pa_currency'] ) ) {
		$cur = get_term( absint( reset( $data['attrs']['pa_currency'] ) ), 'pa_currency' );
		if ( $cur && ! is_wp_error( $cur ) ) {
			$tag = term_exists( $cur->name, 'product_tag' );
			if ( ! $tag ) {
				$tag = wp_insert_term( $cur->name, 'product_tag' );
			}
			if ( ! is_wp_error( $tag ) && $tag ) {
				$tag_ids[] = (int) ( is_array( $tag ) ? $tag['term_id'] : $tag );
			}
			if ( class_exists( 'Alg_WC_CPP' ) || class_exists( 'Alg_WC_CPP_Core' ) ) {
				$product->update_meta_data( '_alg_wc_cpp_currency', strtoupper( $cur->name ) );
			}
		}
	}
	$product->set_tag_ids( $tag_ids );

	$images = array_values( array_filter( array_map( 'absint', (array) $data['image_ids'] ) ) );
	$product->set_image_id( $images ? $images[0] : 0 );
	$product->set_gallery_image_ids( array_slice( $images, 1 ) );

	$prev_chosen = array_map( 'absint', (array) $product->get_meta( '_sbl_chosen_cats' ) );
	$product->update_meta_data( '_sbl_social', array_filter( (array) $data['social'] ) );
	$product->update_meta_data( '_sbl_chosen_cats', array_map( 'absint', (array) $data['cats'] ) );

	sbl_apply_plan( $product, $plan, $prev_chosen );
}

/**
 * Plan-driven extras: tier category, Popular Boxes, Slider, badge, featured, search order.
 * Only the categories this plugin manages are changed – anything you added by hand stays.
 */
function sbl_apply_plan( WC_Product $product, $plan, $prev_chosen = null ) {
	$plans = sbl_plans();
	if ( ! isset( $plans[ $plan ] ) ) {
		return;
	}
	$p      = $plans[ $plan ];
	$tiers  = sbl_tier_category_ids();
	$chosen = array_map( 'absint', (array) $product->get_meta( '_sbl_chosen_cats' ) );
	$is_swap = 'boxswap' === $product->get_meta( '_sbl_source' );
	if ( $p['cats'] > 0 && ! $is_swap ) {
		$chosen = array_slice( $chosen, 0, $p['cats'] );
	}
	$managed = array_merge( array_values( $tiers ), array( sbl_category_id( sbl_popular_cat() ), sbl_category_id( SBL_SLIDER_CAT ), sbl_category_id( SBL_CANCELLED_CAT ), sbl_category_id( SBL_BOX_SWAP_CAT ) ), null === $prev_chosen ? $chosen : $prev_chosen );
	$manual  = array_diff( array_map( 'absint', $product->get_category_ids() ), array_filter( $managed ) );
	$cats    = array_merge( $chosen, $manual );
	if ( sbl_admin_parent() ) {
		$cats[] = (int) sbl_admin_parent()->term_id; // matches existing listings: Z-Admin parent ticked too
	}
	if ( ! empty( $tiers[ $plan ] ) ) {
		$cats[] = $tiers[ $plan ];
	}
	if ( $p['popular'] ) {
		$popular = sbl_category_id( sbl_popular_cat() );
		if ( $popular ) {
			$cats[] = $popular;
		}
	}
	if ( $is_swap ) {
		$swap_cat = sbl_category_id( SBL_BOX_SWAP_CAT, 'Box Swap', sbl_admin_parent() ? sbl_admin_parent()->slug : '' );
		if ( $swap_cat ) {
			$cats[] = $swap_cat;
		}
	}
	if ( 'extreme' === $plan ) {
		$slider = sbl_category_id( SBL_SLIDER_CAT );
		if ( $slider ) {
			$cats[] = $slider;
		}
	}
	if ( in_array( sbl_category_id( SBL_CANCELLED_CAT ), array_map( 'absint', $product->get_category_ids() ), true ) && 'hidden' === $product->get_meta( '_sbl_state' ) ) {
		$cats[] = sbl_category_id( SBL_CANCELLED_CAT ); // keep the Cancelled marker while hidden
	}
	$product->set_category_ids( array_values( array_unique( array_filter( $cats ) ) ) );
	sbl_apply_badge( $product, $p['tag_label'] );
	$product->set_featured( $p['featured'] );
	$product->update_meta_data( '_sbl_plan', $plan );
	$product->set_menu_order( sbl_menu_order_for( $product ) );
	$product->update_meta_data( '_sbl_plan', $plan );
}

/**
 * YITH Badge Management: give top-plan listings the same badge as your existing Extreme listings
 * (copies the badge settings from one of them), and take it off if the plan drops.
 */
function sbl_apply_badge( WC_Product $product, $wants_badge ) {
	if ( ! defined( 'YITH_WCBM_VERSION' ) && ! function_exists( 'yith_wcbm_init' ) && ! class_exists( 'YITH_WCBM' ) ) {
		return;
	}
	global $wpdb;
	if ( ! $wants_badge ) {
		if ( $product->get_meta( '_sbl_badge_copied' ) ) {
			foreach ( (array) $product->get_meta( '_sbl_badge_copied' ) as $key ) {
				$product->delete_meta_data( $key );
			}
			$product->delete_meta_data( '_sbl_badge_copied' );
		}
		return;
	}
	$tier = sbl_category_id( 'extreme-subscription' );
	if ( ! $tier ) {
		return;
	}
	$refs = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'fields' => 'ids', 'posts_per_page' => 50, 'orderby' => 'modified', 'post__not_in' => array( (int) $product->get_id() ), 'tax_query' => array( array( 'taxonomy' => 'product_cat', 'terms' => $tier ) ) ) );
	foreach ( $refs as $ref ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $ref, $wpdb->esc_like( '_yith_wcbm' ) . '%' ) );
		if ( ! $rows ) {
			continue;
		}
		$keys = array();
		foreach ( $rows as $row ) {
			$product->update_meta_data( $row->meta_key, maybe_unserialize( $row->meta_value ) );
			$keys[] = $row->meta_key;
		}
		$product->update_meta_data( '_sbl_badge_copied', $keys );
		return;
	}
}

/* -------------------------------------------------------------------------
 * My Account → My Listing
 * ---------------------------------------------------------------------- */

add_action( 'init', function () {
	add_rewrite_endpoint( SBL_ENDPOINT, EP_ROOT | EP_PAGES );
	if ( get_option( 'sbl_rewrite_version' ) !== '1' ) {
		flush_rewrite_rules( false );
		update_option( 'sbl_rewrite_version', '1' );
	}
} );

add_filter( 'woocommerce_get_query_vars', function ( $vars ) {
	$vars[ SBL_ENDPOINT ] = SBL_ENDPOINT;
	return $vars;
} );

add_filter( 'woocommerce_account_menu_items', function ( $items ) {
	$user = get_current_user_id();
	if ( ! $user || ( ! sbl_user_subscription( $user ) && ! sbl_user_listing_id( $user ) ) ) {
		return $items;
	}
	$new = array();
	foreach ( $items as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'dashboard' === $key ) {
			$new[ SBL_ENDPOINT ] = 'My Listing';
		}
	}
	if ( ! isset( $new[ SBL_ENDPOINT ] ) ) {
		$new[ SBL_ENDPOINT ] = 'My Listing';
	}
	return $new;
} );

add_filter( 'woocommerce_endpoint_' . SBL_ENDPOINT . '_title', function () {
	return 'My Listing';
} );

/** Listing product ID owned by a user (any status), or 0. */
function sbl_user_listing_id( $user_id ) {
	$found = get_posts( array(
		'post_type'      => 'product',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
		'meta_key'       => '_sbl_owner',
		'meta_value'     => (int) $user_id,
		'fields'         => 'ids',
		'posts_per_page' => 1,
	) );
	return $found ? (int) $found[0] : 0;
}

function sbl_listing_url() {
	return wc_get_account_endpoint_url( SBL_ENDPOINT );
}

add_action( 'woocommerce_account_' . SBL_ENDPOINT . '_endpoint', function () {
	$user_id = get_current_user_id();
	$sub     = sbl_user_subscription( $user_id );
	$plan    = $sub ? sbl_plan_from_subscription( $sub ) : '';

	if ( ! $sub || ! $plan ) {
		echo '<p>You don\'t have an active listing plan right now.</p>';
		$plans_page = apply_filters( 'sbl_plans_page_url', home_url( '/' ) );
		echo '<p><a class="button" href="' . esc_url( $plans_page ) . '">See listing plans</a></p>';
		return;
	}

	$listing_id = sbl_adopt_listing( $sub );
	$product    = $listing_id ? wc_get_product( $listing_id ) : null;
	if ( $product && (int) $product->get_meta( '_sbl_owner' ) !== $user_id ) {
		$product = null;
	}
	$state   = $product ? $product->get_meta( '_sbl_state' ) : '';
	$pending = $product ? $product->get_meta( '_sbl_pending' ) : array();
	$plans   = sbl_plans();

	if ( isset( $_GET['sbl'] ) ) {
		$msg = array(
			'created' => 'Thanks! Your listing has been sent for review. We\'ll email you as soon as it\'s live.',
			'updated' => 'Saved. Your listing is still awaiting review.',
			'pending' => 'Thanks! Your changes have been sent for review. Your current listing stays live until they\'re approved.',
		);
		$key = sanitize_key( wp_unslash( $_GET['sbl'] ) );
		if ( isset( $msg[ $key ] ) ) {
			wc_print_notice( $msg[ $key ], 'success' );
		}
	}
	$just_saved = isset( $_GET['sbl'] );
	if ( $just_saved ) {
		// The success message above already says where things stand.
	} elseif ( $product && 'changes_pending' === $state ) {
		wc_print_notice( 'You have changes waiting for review. Submitting again replaces them.', 'notice' );
	} elseif ( $product && 'awaiting' === $state ) {
		wc_print_notice( 'Your listing is waiting for review. You can still make changes below.', 'notice' );
	} elseif ( $product && 'hidden' === $state ) {
		wc_print_notice( 'Your listing is currently hidden because your plan is not active.', 'notice' );
	}

	// Values to prefill: pending changes if any, else the product, else blank.
	$v = sbl_form_values( $product, is_array( $pending ) ? $pending : array() );
	if ( ! empty( $GLOBALS['sbl_form_errors'] ) ) {
		$v = array_merge( $v, $GLOBALS['sbl_posted'] );
	}

	sbl_render_form( $v, $plans[ $plan ], $plan, (bool) $product, $product && 'publish' === $product->get_status() );
} );

function sbl_form_values( $product, array $pending ) {
	$v = array( 'name' => '', 'subtitle' => '', 'url' => '', 'tagline' => '', 'description' => '', 'price' => '', 'cats' => array(), 'attrs' => array(), 'image_ids' => array(), 'social' => array() );
	if ( $product ) {
		$v['name']        = $product->get_name();
		$v['subtitle']    = (string) $product->get_meta( sbl_subtitle_key() );
		$v['url']         = $product->get_product_url();
		$v['tagline']     = $product->meta_exists( '_sbl_tagline' ) ? $product->get_meta( '_sbl_tagline' ) : sbl_html_to_text( preg_replace( '#<hr\s*/?>.*$#is', '', $product->get_short_description() ) );
		$v['description'] = $product->meta_exists( '_sbl_body' ) ? $product->get_meta( '_sbl_body' ) : preg_replace( '#^\s*<h2[^>]*>.*?</h2>\s*<hr\s*/?>\s*#is', '', $product->get_description() );
		$v['price']       = $product->get_regular_price();
		$v['cats']        = array_map( 'absint', (array) $product->get_meta( '_sbl_chosen_cats' ) );
		foreach ( $product->get_attributes() as $tax => $attr ) {
			if ( $attr->is_taxonomy() ) {
				$v['attrs'][ $tax ] = $attr->get_options();
			}
		}
		$v['image_ids'] = array_values( array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) );
		$v['social']    = (array) $product->get_meta( '_sbl_social' );
	}
	return $pending ? array_merge( $v, $pending ) : $v;
}

function sbl_render_form( array $v, array $plan, $plan_key, $has_listing, $is_live ) {
	$cats       = sbl_choosable_categories();
	$attrs      = sbl_available_attributes();
	$cat_limit  = (int) $plan['cats'];
	$img_limit  = (int) $plan['images'];
	$errors     = isset( $GLOBALS['sbl_form_errors'] ) ? $GLOBALS['sbl_form_errors'] : array();
	if ( $errors ) {
		wc_print_notice( implode( '<br>', array_map( 'esc_html', $errors ) ), 'error' );
	}
	$button = ! $has_listing ? 'Submit my listing' : ( $is_live ? 'Send changes for review' : 'Save listing' );
	?>
	<div class="sbl-wrap">
		<?php if ( ! empty( $plan['swap'] ) ) : ?>
			<p class="sbl-plan">Your plan: <strong>Box Swap</strong>. Add as many images and categories as you like. We'll confirm your listing level (Basic, Premium or Extreme) when we approve it.</p>
		<?php else : ?>
		<p class="sbl-plan">Your plan: <strong><?php echo esc_html( $plan['label'] ); ?></strong> — up to <?php echo esc_html( $img_limit ); ?> image<?php echo 1 === $img_limit ? '' : 's'; ?> and <?php echo $cat_limit ? esc_html( $cat_limit ) . ' categor' . ( 1 === $cat_limit ? 'y' : 'ies' ) : 'unlimited categories'; ?>.</p>
		<?php endif; ?>
		<form method="post" enctype="multipart/form-data" class="sbl-form" data-cat-limit="<?php echo esc_attr( $cat_limit ); ?>" data-img-limit="<?php echo esc_attr( $img_limit ); ?>">
			<?php wp_nonce_field( 'sbl_save', 'sbl_nonce' ); ?>
			<input type="hidden" name="sbl_action" value="save">

			<div class="sbl-card"><h3>Your box</h3>
				<p><label for="sbl_name">Box name *</label><input type="text" id="sbl_name" name="sbl_name" required maxlength="120" value="<?php echo esc_attr( $v['name'] ); ?>"></p>
				<p><label for="sbl_subtitle">Subtitle</label><input type="text" id="sbl_subtitle" name="sbl_subtitle" maxlength="30" placeholder="2–3 words, e.g. Worldly Snacks" value="<?php echo esc_attr( isset( $v['subtitle'] ) ? $v['subtitle'] : '' ); ?>"><small class="sbl-hint" style="display:block;margin-top:.3rem">Shows under your box name in the shop.</small></p>
				<p><label for="sbl_url">Link to your box (where customers subscribe) *</label><input type="url" id="sbl_url" name="sbl_url" required placeholder="https://" value="<?php echo esc_attr( $v['url'] ); ?>"></p>
				<p><label for="sbl_tagline">Tagline</label><input type="text" id="sbl_tagline" name="sbl_tagline" maxlength="160" placeholder="One line that sums up your box" value="<?php echo esc_attr( $v['tagline'] ); ?>"></p>
				<p><label for="sbl_description">Description *</label><textarea id="sbl_description" name="sbl_description" rows="7" required placeholder="What's in the box, who it's for, what makes it special"><?php echo esc_textarea( sbl_html_to_text( $v['description'] ) ); ?></textarea></p>
				<p><label for="sbl_price">Price per box *</label><input type="number" id="sbl_price" name="sbl_price" required min="0" step="0.01" value="<?php echo esc_attr( $v['price'] ); ?>"></p>
			</div>

			<?php if ( $attrs ) : ?>
			<div class="sbl-card"><h3>Details</h3>
				<?php foreach ( $attrs as $tax => $a ) :
					$cfg     = $a['cfg'];
					$multi   = 'multi' === $cfg['type'];
					$current = sbl_attr_current_labels( $tax, $cfg, isset( $v['attrs'][ $tax ] ) ? $v['attrs'][ $tax ] : array() );
					$other   = '';
					if ( ! empty( $cfg['other'] ) ) {
						foreach ( $current as $c ) {
							if ( ! in_array( $c, $a['options'], true ) ) {
								$other = $c;
							}
						}
					}
					?>
					<div class="sbl-field"><span class="sbl-lbl"><?php echo esc_html( $a['label'] ); ?><?php echo $multi ? ' <small>(pick all that apply)</small>' : ''; ?></span>
						<div class="sbl-pills">
							<?php foreach ( $a['options'] as $opt ) : ?>
								<label class="sbl-pill"><input type="<?php echo $multi ? 'checkbox' : 'radio'; ?>" name="sbl_attrs[<?php echo esc_attr( $tax ); ?>][]" value="<?php echo esc_attr( $opt ); ?>" <?php checked( in_array( $opt, $current, true ) ); ?>><span><?php echo esc_html( $opt ); ?></span></label>
							<?php endforeach; ?>
							<?php if ( ! empty( $cfg['other'] ) ) : ?>
								<label class="sbl-pill"><input type="radio" name="sbl_attrs[<?php echo esc_attr( $tax ); ?>][]" value="__other" <?php checked( '' !== $other ); ?>><span>Other</span></label>
								<input type="text" class="sbl-other" name="sbl_attr_other[<?php echo esc_attr( $tax ); ?>]" maxlength="40" placeholder="e.g. After 3 boxes" value="<?php echo esc_attr( $other ); ?>">
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<div class="sbl-card"><h3>Categories <span class="sbl-count" data-for="cats"></span></h3>
				<p class="sbl-hint"><?php echo $cat_limit ? 'Pick up to ' . esc_html( $cat_limit ) . '.' : 'Pick as many as fit your box.'; ?></p>
				<div class="sbl-cats">
					<?php foreach ( $cats as $id => $label ) : ?>
						<label class="sbl-check"><input type="checkbox" name="sbl_cats[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( (int) $id, array_map( 'absint', (array) $v['cats'] ), true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="sbl-card"><h3>Images <span class="sbl-count" data-for="imgs"></span></h3>
				<p class="sbl-hint">Up to <?php echo esc_html( $img_limit ); ?>. JPG, PNG or WebP, max <?php echo esc_html( sbl_max_upload_mb() ); ?> MB each. The first image is your main picture.</p>
				<?php if ( $v['image_ids'] ) : ?>
					<div class="sbl-thumbs">
						<?php foreach ( $v['image_ids'] as $img_id ) : ?>
							<label class="sbl-thumb"><?php echo wp_get_attachment_image( $img_id, 'thumbnail' ); ?>
								<input type="hidden" name="sbl_keep_all[]" value="<?php echo esc_attr( $img_id ); ?>">
								<span><input type="checkbox" name="sbl_remove[]" value="<?php echo esc_attr( $img_id ); ?>"> Remove</span></label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<p><input type="file" name="sbl_images[]" accept="image/jpeg,image/png,image/webp" multiple></p>
			</div>

			<?php if ( $plan['social'] ) : ?>
			<div class="sbl-card"><h3>Your social media</h3>
				<?php foreach ( wp_list_pluck( sbl_social_networks(), 'label' ) as $k => $label ) : ?>
					<p><label for="sbl_social_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label><input type="url" id="sbl_social_<?php echo esc_attr( $k ); ?>" name="sbl_social[<?php echo esc_attr( $k ); ?>]" placeholder="https://" value="<?php echo esc_attr( isset( $v['social'][ $k ] ) ? $v['social'][ $k ] : '' ); ?>"></p>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<p><button type="submit" class="button sbl-submit"><?php echo esc_html( $button ); ?></button></p>
		</form>
	</div>
	<style>
		.sbl-card{border:1px solid #e6e1ee;border-radius:14px;padding:1.1rem 1.25rem 1.25rem;margin:0 0 1.25rem;background:#fff}
		.sbl-card h3{margin:0 0 .9rem;font-size:1.15rem;line-height:1.3}
		.sbl-form label{display:block;font-weight:600;margin-bottom:.3rem}
		.sbl-field{margin:0 0 1rem}
		.sbl-field small{font-weight:400;color:#6b6478}
		.sbl-pills{display:flex;flex-wrap:wrap;gap:.45rem;align-items:center}
		.sbl-pill{display:inline-flex!important;margin:0!important;font-weight:500!important;cursor:pointer}
		.sbl-pill input{position:absolute;opacity:0;width:1px;height:1px}
		.sbl-pill span{display:inline-block;padding:.45rem .9rem;border:1px solid #cfc8db;border-radius:999px;background:#fff;line-height:1.2;transition:background .12s,border-color .12s,color .12s}
		.sbl-pill:hover span{border-color:#7e4fc4}
		.sbl-pill input:checked+span{background:#7e4fc4;border-color:#7e4fc4;color:#fff}
		.sbl-pill input:focus-visible+span{outline:2px solid #7e4fc4;outline-offset:2px}
		.sbl-form .sbl-other{width:auto!important;flex:1 1 180px;max-width:260px!important;padding:.45rem .8rem!important;border-radius:999px!important}
		.sbl-form input[type=text],.sbl-form input[type=url],.sbl-form input[type=number],.sbl-form textarea,.sbl-form select{width:100%;max-width:100%;padding:.6rem .7rem;border:1px solid #cfc8db;border-radius:8px;font:inherit;box-sizing:border-box}
		.sbl-form p{margin:0 0 .9rem}
		.sbl-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 1rem}
		.sbl-cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:.25rem 1rem;max-height:340px;overflow:auto}
		.sbl-check{font-weight:400!important;display:flex!important;gap:.45rem;align-items:center;margin:0!important}
		.sbl-lbl{display:block;font-weight:600;margin-bottom:.3rem}
		.sbl-multi{margin-bottom:1rem}
		.sbl-hint{color:#6b6478;font-size:.9rem;margin:.1rem 0 .6rem}
		.sbl-count{font-weight:400;color:#6b6478;font-size:.9rem}
		.sbl-count.is-over{color:#b32d2e;font-weight:700}
		.sbl-thumbs{display:flex;flex-wrap:wrap;gap:.75rem;margin-bottom:.75rem}
		.sbl-thumb{width:110px;font-weight:400!important}
		.sbl-thumb img{width:110px;height:110px;object-fit:cover;border-radius:8px;display:block}
		.sbl-thumb span{display:flex;gap:.3rem;font-size:.85rem;margin-top:.25rem}
	</style>
	<script>
	(function(){
		var f=document.querySelector('.sbl-form'); if(!f) return;
		var cl=+f.dataset.catLimit, il=+f.dataset.imgLimit;
		function upd(){
			var c=f.querySelectorAll('input[name="sbl_cats[]"]:checked').length, ce=f.querySelector('[data-for=cats]');
			if(ce){ ce.textContent=cl?'('+c+' of '+cl+')':'('+c+' chosen)'; ce.classList.toggle('is-over', cl && c>cl); }
			var kept=f.querySelectorAll('input[name="sbl_keep_all[]"]').length-f.querySelectorAll('input[name="sbl_remove[]"]:checked').length;
			var fi=f.querySelector('input[type=file]'), n=kept+(fi&&fi.files?fi.files.length:0), ie=f.querySelector('[data-for=imgs]');
			if(ie){ ie.textContent='('+n+' of '+il+')'; ie.classList.toggle('is-over', n>il); }
		}
		f.addEventListener('change',upd); upd();
		f.querySelectorAll('.sbl-other').forEach(function(t){ t.addEventListener('input',function(){ var r=t.parentNode.querySelector('input[value=__other]'); if(r&&t.value) r.checked=true; }); });
		f.addEventListener('submit',function(e){ if(f.querySelector('.sbl-count.is-over')){ e.preventDefault(); alert('Please stay within your plan limits for categories and images.'); } });
	})();
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * Form submission
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', function () {
	if ( empty( $_POST['sbl_action'] ) || 'save' !== $_POST['sbl_action'] || ! is_user_logged_in() ) {
		return;
	}
	if ( ! isset( $_POST['sbl_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sbl_nonce'] ) ), 'sbl_save' ) ) {
		$GLOBALS['sbl_form_errors'] = array( 'Your session expired. Please try again.' );
		return;
	}
	$user_id = get_current_user_id();
	$sub     = sbl_user_subscription( $user_id );
	$plan    = $sub ? sbl_plan_from_subscription( $sub ) : '';
	if ( ! $plan ) {
		return;
	}
	$plans = sbl_plans();
	$limit = $plans[ $plan ];

	$listing_id = sbl_adopt_listing( $sub );
	$product    = $listing_id ? wc_get_product( $listing_id ) : null;
	if ( $product && ( ! $product->is_type( 'external' ) || (int) $product->get_meta( '_sbl_owner' ) !== $user_id ) ) {
		$product = null;
	}

	// Collect + validate.
	$errors = array();
	$data   = array(
		'name'        => sanitize_text_field( wp_unslash( $_POST['sbl_name'] ?? '' ) ),
		'subtitle'    => mb_substr( sanitize_text_field( wp_unslash( $_POST['sbl_subtitle'] ?? '' ) ), 0, 40 ),
		'url'         => esc_url_raw( wp_unslash( $_POST['sbl_url'] ?? '' ) ),
		'tagline'     => sanitize_text_field( wp_unslash( $_POST['sbl_tagline'] ?? '' ) ),
		'description' => wp_kses_post( wpautop( sanitize_textarea_field( wp_unslash( $_POST['sbl_description'] ?? '' ) ) ) ),
		'price'       => wc_format_decimal( wp_unslash( $_POST['sbl_price'] ?? '' ) ),
		'cats'        => array(),
		'attrs'       => array(),
		'image_ids'   => array(),
		'social'      => array(),
	);
	if ( '' === $data['name'] ) {
		$errors[] = 'Please enter your box name.';
	}
	if ( ! $data['url'] || ! filter_var( $data['url'], FILTER_VALIDATE_URL ) || ! in_array( wp_parse_url( $data['url'], PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
		$errors[] = 'Please enter a valid link to your box (starting with https://).';
	}
	if ( '' === trim( wp_strip_all_tags( $data['description'] ) ) ) {
		$errors[] = 'Please add a description.';
	}
	if ( '' === $data['price'] || (float) $data['price'] < 0 ) {
		$errors[] = 'Please enter the price per box.';
	}

	$allowed_cats = array_keys( sbl_choosable_categories() );
	$cats         = array_values( array_intersect( array_map( 'absint', (array) ( $_POST['sbl_cats'] ?? array() ) ), $allowed_cats ) );
	if ( ! $cats ) {
		$errors[] = 'Please choose at least one category.';
	} elseif ( $limit['cats'] && count( $cats ) > $limit['cats'] ) {
		$errors[] = sprintf( 'Your plan includes up to %d categor%s.', $limit['cats'], 1 === $limit['cats'] ? 'y' : 'ies' );
	}
	$data['cats'] = $cats;

	$available = sbl_available_attributes();
	$others    = (array) ( $_POST['sbl_attr_other'] ?? array() );
	foreach ( (array) ( $_POST['sbl_attrs'] ?? array() ) as $tax => $labels ) {
		$tax = sanitize_key( $tax );
		if ( ! isset( $available[ $tax ] ) ) {
			continue;
		}
		$cfg    = $available[ $tax ]['cfg'];
		$picked = array();
		foreach ( (array) $labels as $label ) {
			$label = sanitize_text_field( wp_unslash( $label ) );
			if ( '__other' === $label && ! empty( $cfg['other'] ) ) {
				$label = trim( sanitize_text_field( wp_unslash( $others[ $tax ] ?? '' ) ) );
				$label = mb_substr( $label, 0, 40 );
				if ( '' === $label ) {
					$errors[] = sprintf( 'Please type your %s option, or pick one of the choices.', strtolower( $available[ $tax ]['label'] ) );
					continue;
				}
			} elseif ( ! in_array( $label, $available[ $tax ]['options'], true ) ) {
				continue;
			}
			$picked[] = $label;
		}
		if ( 'single' === $cfg['type'] ) {
			$picked = array_slice( $picked, 0, 1 );
		}
		$ids = array_filter( array_map( function ( $label ) use ( $tax, $cfg ) {
			return sbl_attr_term_id( $tax, $cfg, $label );
		}, $picked ) );
		if ( $ids ) {
			$data['attrs'][ $tax ] = array_values( $ids );
		}
	}

	if ( $limit['social'] ) {
		foreach ( array_keys( sbl_social_networks() ) as $k ) {
			$url = esc_url_raw( wp_unslash( $_POST['sbl_social'][ $k ] ?? '' ) );
			if ( $url ) {
				$data['social'][ $k ] = $url;
			}
		}
	}

	// Images: existing ones the vendor kept (only ones that really belong to this listing) + new uploads.
	$owned = array();
	if ( $product ) {
		$current = sbl_form_values( $product, (array) $product->get_meta( '_sbl_pending' ) );
		$owned   = array_map( 'absint', $current['image_ids'] );
	}
	$keep   = array_values( array_intersect( array_map( 'absint', (array) ( $_POST['sbl_keep_all'] ?? array() ) ), $owned ) );
	$remove = array_map( 'absint', (array) ( $_POST['sbl_remove'] ?? array() ) );
	$keep   = array_values( array_diff( $keep, $remove ) );

	$uploads = sbl_normalise_files( $_FILES['sbl_images'] ?? array() );
	if ( count( $keep ) + count( $uploads ) > $limit['images'] ) {
		$errors[] = sprintf( 'Your plan includes up to %d image%s.', $limit['images'], 1 === $limit['images'] ? '' : 's' );
	}
	foreach ( $uploads as $file ) {
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( ! in_array( $check['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			$errors[] = sprintf( '"%s" isn\'t a JPG, PNG or WebP image.', sanitize_file_name( $file['name'] ) );
		} elseif ( $file['size'] > sbl_max_upload_mb() * MB_IN_BYTES ) {
			$errors[] = sprintf( '"%s" is larger than %d MB.', sanitize_file_name( $file['name'] ), sbl_max_upload_mb() );
		}
	}
	if ( ! $keep && ! $uploads ) {
		$errors[] = 'Please add at least one image.';
	}

	if ( $errors ) {
		$GLOBALS['sbl_form_errors'] = $errors;
		$GLOBALS['sbl_posted']      = array_merge( $data, array( 'image_ids' => $keep ) );
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$new_ids = array();
	foreach ( $uploads as $i => $file ) {
		$key            = 'sbl_upload_' . $i;
		$_FILES[ $key ] = $file;
		$att            = media_handle_upload( $key, 0, array( 'post_author' => $user_id ), array( 'test_form' => false ) );
		if ( is_wp_error( $att ) ) {
			$GLOBALS['sbl_form_errors'] = array( 'An image failed to upload: ' . $att->get_error_message() );
			$GLOBALS['sbl_posted']      = array_merge( $data, array( 'image_ids' => array_merge( $keep, $new_ids ) ) );
			return;
		}
		$new_ids[] = (int) $att;
	}
	$data['image_ids'] = array_merge( $keep, $new_ids );

	$result = 'created';
	if ( ! $product ) {
		$product = new WC_Product_External();
		$product->set_status( 'draft' );
		$product->set_catalog_visibility( 'visible' );
		$product->update_meta_data( '_sbl_owner', $user_id );
		$product->update_meta_data( '_sbl_subscription_id', $sub->get_id() );
		$product->update_meta_data( '_sbl_state', 'awaiting' );
		$product->update_meta_data( '_sbl_token', wp_generate_password( 32, false ) );
		if ( 'boxswap' === $plan ) {
			$product->update_meta_data( '_sbl_source', 'boxswap' );
		}
		sbl_apply_data( $product, $data, $plan );
		$listing_id = $product->save();
		wp_update_post( array( 'ID' => $listing_id, 'post_author' => $user_id ) );
		$sub->update_meta_data( '_sbl_listing_id', $listing_id );
		$sub->save();
		sbl_attach_images( $data['image_ids'], $listing_id );
		sbl_notify_admin( $product, 'new', $sub );
	} elseif ( 'publish' !== $product->get_status() ) {
		// Not live yet: edit the draft directly.
		$before = sbl_form_values( $product, array() );
		$GLOBALS['sbl_last_changes'] = sbl_changes_summary( $product, $before, $data );
		sbl_apply_data( $product, $data, sbl_listing_plan_key( $plan, $product ) );
		$product->save();
		sbl_attach_images( $data['image_ids'], $product->get_id() );
		if ( 'awaiting' === $product->get_meta( '_sbl_state' ) ) {
			sbl_notify_admin( $product, 'new', $sub, true );
		}
		$result = 'updated';
	} else {
		// Live: hold the changes for approval.
		$product->update_meta_data( '_sbl_pending', $data );
		$product->update_meta_data( '_sbl_state', 'changes_pending' );
		if ( ! $product->get_meta( '_sbl_token' ) ) {
			$product->update_meta_data( '_sbl_token', wp_generate_password( 32, false ) );
		}
		$product->save();
		sbl_attach_images( $new_ids, $product->get_id() );
		sbl_notify_admin( $product, 'changes', $sub );
		$result = 'pending';
	}

	wp_safe_redirect( add_query_arg( 'sbl', $result, sbl_listing_url() ) );
	exit;
} );

/** HTML description -> plain text with paragraph breaks, for the textarea. */
function sbl_html_to_text( $html ) {
	$text = preg_replace( array( '#<br\s*/?>#i', '#</p>\s*#i' ), array( "\n", "\n\n" ), (string) $html );
	return trim( html_entity_decode( wp_strip_all_tags( $text ) ) );
}

/** Turn a multi-file $_FILES entry into a list of single files, dropping empty slots. */
function sbl_normalise_files( $files ) {
	$out = array();
	if ( empty( $files['name'] ) || ! is_array( $files['name'] ) ) {
		return $out;
	}
	foreach ( $files['name'] as $i => $name ) {
		if ( '' === $name || UPLOAD_ERR_NO_FILE === $files['error'][ $i ] ) {
			continue;
		}
		$out[] = array(
			'name'     => $name,
			'type'     => $files['type'][ $i ],
			'tmp_name' => $files['tmp_name'][ $i ],
			'error'    => $files['error'][ $i ],
			'size'     => $files['size'][ $i ],
		);
	}
	return $out;
}

/** Attach uploaded images to the listing so they show under it in the media library. */
function sbl_attach_images( array $ids, $product_id ) {
	foreach ( $ids as $id ) {
		if ( ! wp_get_post_parent_id( $id ) ) {
			wp_update_post( array( 'ID' => $id, 'post_parent' => $product_id ) );
		}
	}
}

/* -------------------------------------------------------------------------
 * Approval (one-click links in the email + buttons in the product editor)
 * ---------------------------------------------------------------------- */

function sbl_action_url( $product, $do ) {
	return add_query_arg( array( 'action' => 'sbl_review', 'do' => $do, 'p' => $product->get_id(), 't' => $product->get_meta( '_sbl_token' ) ), admin_url( 'admin-post.php' ) );
}

add_action( 'admin_post_sbl_review', function () {
	if ( ! current_user_can( 'edit_products' ) ) {
		wp_die( 'You need to be logged in as a shop manager or admin.' );
	}
	$product = wc_get_product( absint( $_GET['p'] ?? 0 ) );
	$token   = sanitize_text_field( wp_unslash( $_GET['t'] ?? '' ) );
	if ( ! $product || ! $product->get_meta( '_sbl_owner' ) || ! $token || ! hash_equals( (string) $product->get_meta( '_sbl_token' ), $token ) ) {
		wp_die( 'This approval link is invalid or has already been used.' );
	}
	$do   = sanitize_key( $_GET['do'] ?? '' );
	$tier = sanitize_key( $_GET['tier'] ?? '' );
	if ( 'boxswap' === $product->get_meta( '_sbl_source' ) && 'approve' === $do && 'awaiting' === $product->get_meta( '_sbl_state' ) && ! in_array( $tier, SBL_SWAP_TIERS, true ) ) {
		$links = '';
		foreach ( SBL_SWAP_TIERS as $t ) {
			$links .= '<p><a class="button button-primary" href="' . esc_url( add_query_arg( 'tier', $t, sbl_action_url( $product, 'approve' ) ) ) . '">Approve as ' . esc_html( sbl_plans()[ $t ]['label'] ) . '</a></p>';
		}
		wp_die( '<h1>Choose a listing level</h1><p>' . esc_html( $product->get_name() ) . ' is a Box Swap listing. Which level should it get?</p>' . $links, 'Choose a listing level' ); // phpcs:ignore
	}
	$done = sbl_review( $product, $do, $tier );
	wp_safe_redirect( add_query_arg( 'sbl_done', $done ? $do : 'nothing', get_edit_post_link( $product->get_id(), 'raw' ) ) );
	exit;
} );

/** approve | reject | tier. Returns true if something happened. */
function sbl_review( WC_Product $product, $do, $tier = '' ) {
	$state   = $product->get_meta( '_sbl_state' );
	$owner   = get_userdata( (int) $product->get_meta( '_sbl_owner' ) );
	$is_swap = 'boxswap' === $product->get_meta( '_sbl_source' );

	// Box Swap: set (or change) the listing level.
	if ( $is_swap && in_array( $tier, SBL_SWAP_TIERS, true ) && in_array( $do, array( 'approve', 'tier' ), true ) ) {
		$product->update_meta_data( '_sbl_tier', $tier );
		sbl_apply_plan( $product, $tier );
		if ( 'tier' === $do ) {
			$product->update_meta_data( '_sbl_token', wp_generate_password( 32, false ) );
			$product->save();
			return true;
		}
	}

	if ( 'approve' === $do && 'awaiting' === $state ) {
		update_post_meta( $product->get_id(), '_sbl_state', 'live' ); // before publishing, so the publish hook doesn't email twice
		$product->update_meta_data( '_sbl_state', 'live' );
		$plans_cfg = sbl_plans();
		$plan_now  = $product->get_meta( '_sbl_plan' );
		if ( isset( $plans_cfg[ $plan_now ] ) ) {
			sbl_apply_badge( $product, $plans_cfg[ $plan_now ]['tag_label'] );
		}
		$product->update_meta_data( '_sbl_token', wp_generate_password( 32, false ) ); // links are single-use
		$product->set_status( 'publish' );
		$product->save();
		sbl_notify_vendor( $owner, 'Your listing is live!', sprintf( '<p>Good news: <strong>%s</strong> is now live on %s.</p><p><a href="%s">View your listing</a></p><p>You can update it any time from <a href="%s">My Account → My Listing</a>.</p>', esc_html( $product->get_name() ), esc_html( get_bloginfo( 'name' ) ), esc_url( get_permalink( $product->get_id() ) ), esc_url( sbl_listing_url() ) ) );
		return true;
	}
	if ( 'approve' === $do && 'changes_pending' === $state ) {
		$pending = (array) $product->get_meta( '_sbl_pending' );
		$product->delete_meta_data( '_sbl_pending' );
		$product->update_meta_data( '_sbl_state', 'live' );
		$product->update_meta_data( '_sbl_token', wp_generate_password( 32, false ) );
		sbl_apply_data( $product, array_merge( sbl_form_values( $product, array() ), $pending ), $product->get_meta( '_sbl_plan' ) );
		$product->save();
		sbl_notify_vendor( $owner, 'Your listing changes are live', sprintf( '<p>Your changes to <strong>%s</strong> have been approved and are now live.</p><p><a href="%s">View your listing</a></p>', esc_html( $product->get_name() ), esc_url( get_permalink( $product->get_id() ) ) ) );
		return true;
	}
	if ( 'reject' === $do && 'changes_pending' === $state ) {
		$product->delete_meta_data( '_sbl_pending' );
		$product->update_meta_data( '_sbl_state', 'live' );
		$product->update_meta_data( '_sbl_token', wp_generate_password( 32, false ) );
		$product->save();
		sbl_notify_vendor( $owner, 'About your listing changes', sprintf( '<p>We weren\'t able to approve your latest changes to <strong>%s</strong>, so your listing stays as it was. Reply to this email if you\'d like to talk it through.</p>', esc_html( $product->get_name() ) ) );
		return true;
	}
	return false;
}

/** If you publish a vendor listing the normal way in WP admin, treat it as approved. */
add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( 'product' !== $post->post_type || 'publish' !== $new || 'publish' === $old ) {
		return;
	}
	if ( 'awaiting' !== get_post_meta( $post->ID, '_sbl_state', true ) ) {
		return;
	}
	update_post_meta( $post->ID, '_sbl_state', 'live' );
	$owner = get_userdata( (int) get_post_meta( $post->ID, '_sbl_owner', true ) );
	sbl_notify_vendor( $owner, 'Your listing is live!', sprintf( '<p>Good news: <strong>%s</strong> is now live on %s.</p><p><a href="%s">View your listing</a></p>', esc_html( get_the_title( $post ) ), esc_html( get_bloginfo( 'name' ) ), esc_url( get_permalink( $post ) ) ) );
}, 10, 3 );

/* -------------------------------------------------------------------------
 * Subscription lifecycle
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_subscription_status_updated', function ( $sub, $new, $old ) {
	if ( 'active' === $new && ! $sub->get_meta( '_sbl_listing_id' ) && sbl_plan_from_subscription( $sub ) ) {
		sbl_adopt_listing( $sub ); // resubscribed: bring their old listing back
		return;
	}
	$product = wc_get_product( (int) $sub->get_meta( '_sbl_listing_id' ) );
	if ( ! $product ) {
		return;
	}
	if ( in_array( $new, array( 'cancelled', 'expired' ), true ) ) {
		if ( 'publish' === $product->get_status() ) {
			$product->update_meta_data( '_sbl_state_before_hide', $product->get_meta( '_sbl_state' ) );
			$product->update_meta_data( '_sbl_state', 'hidden' );
			$discontinue = function_exists( 'sbt_discontinue' ) && 'discontinue' === sbt_get( 'disc_on_plan_end' );
			if ( ! $discontinue ) {
				$product->set_status( 'draft' );
			}
			$cancelled = sbl_category_id( SBL_CANCELLED_CAT, 'Cancelled', sbl_admin_parent() ? sbl_admin_parent()->slug : '' );
			if ( $cancelled ) {
				$product->set_category_ids( array_values( array_unique( array_merge( $product->get_category_ids(), array( $cancelled ) ) ) ) );
			}
			$product->save();
			if ( $discontinue ) {
				sbt_discontinue( $product->get_id(), 'Plan ' . $new . ' (subscription #' . $sub->get_id() . ')' );
				$product = wc_get_product( $product->get_id() );
			}
			sbl_notify_admin( $product, 'hidden', $sub );
		}
	} elseif ( 'pending-cancel' === $new ) {
		sbl_notify_admin( $product, 'cancelling', $sub );
	} elseif ( 'active' === $new && 'hidden' === $product->get_meta( '_sbl_state' ) ) {
		$product->update_meta_data( '_sbl_state', $product->get_meta( '_sbl_state_before_hide' ) ? $product->get_meta( '_sbl_state_before_hide' ) : 'live' );
		$product->delete_meta_data( '_sbl_state_before_hide' );
		$product->set_category_ids( array_values( array_diff( $product->get_category_ids(), array( sbl_category_id( SBL_CANCELLED_CAT ) ) ) ) );
		$product->set_status( 'publish' );
		$product->save();
		if ( function_exists( 'sbt_undiscontinue' ) && $product->get_meta( '_sbt_disc_prev' ) ) {
			sbt_undiscontinue( $product->get_id() );
			$product = wc_get_product( $product->get_id() );
		}
		sbl_notify_admin( $product, 'restored', $sub );
	}
}, 10, 3 );

/** Plan upgrades/downgrades: re-apply plan extras. */
add_action( 'woocommerce_subscriptions_switch_completed', function ( $order ) {
	foreach ( wcs_get_subscriptions_for_switch_order( $order ) as $sub ) {
		$product = wc_get_product( (int) $sub->get_meta( '_sbl_listing_id' ) );
		$plan    = sbl_plan_from_subscription( $sub );
		if ( $product && $plan ) {
			sbl_apply_plan( $product, $plan );
			$product->save();
			sbl_notify_admin( $product, 'switched', $sub );
		}
	}
} );

/* -------------------------------------------------------------------------
 * Pointing vendors to the form
 * ---------------------------------------------------------------------- */

function sbl_order_has_plan( $order ) {
	foreach ( $order->get_items() as $item ) {
		$plan = sbl_plan_from_product( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
		if ( $plan && 0 !== strpos( $plan, 'mkt_' ) ) {
			return true;
		}
	}
	return false;
}

add_action( 'woocommerce_thankyou', function ( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order && ! sbl_order_has_plan( $order ) && sbl_order_mkt_tier( $order ) ) {
		$tier = sbl_order_mkt_tier( $order );
		echo '<div class="sbl-next" style="border:2px solid #7e4fc4;border-radius:14px;padding:1.25rem 1.4rem;margin:0 0 2rem;background:#faf7fe"><h2 style="margin-top:0">Your ' . esc_html( 'mkt_extreme' === $tier ? 'Extreme' : 'Premium' ) . ' upgrade is on</h2><p style="margin-bottom:0">It has been applied to all your marketplace listings, and to any new ones you add while it\'s active. Nothing else to do.</p></div>';
		return;
	}
	if ( ! $order || ! sbl_order_has_plan( $order ) ) {
		return;
	}
	?>
	<div class="sbl-next" style="border:2px solid #7e4fc4;border-radius:14px;padding:1.25rem 1.4rem;margin:0 0 2rem;background:#faf7fe">
		<h2 style="margin-top:0">Next step: set up your listing</h2>
		<p>Add your box details, images and categories. We'll check it over and let you know as soon as it's live.</p>
		<p style="margin-bottom:0"><a class="button" style="background:#7e4fc4;color:#fff" href="<?php echo esc_url( sbl_listing_url() ); ?>">Set up my listing</a></p>
	</div>
	<?php
}, 5 );

add_action( 'woocommerce_email_order_details', function ( $order, $sent_to_admin, $plain_text, $email ) {
	if ( $sent_to_admin || ! $order instanceof WC_Order || ! in_array( $email->id, array( 'customer_processing_order', 'customer_completed_order' ), true ) || ! sbl_order_has_plan( $order ) ) {
		return;
	}
	if ( $plain_text ) {
		echo "\nNext step: set up your listing at " . esc_url_raw( sbl_listing_url() ) . "\n\n";
	} else {
		echo '<p style="font-size:16px"><strong>Next step:</strong> <a href="' . esc_url( sbl_listing_url() ) . '">set up your listing</a>. Add your box details, images and categories.</p>';
	}
}, 5, 4 );

/* -------------------------------------------------------------------------
 * Front end: "Popular" label for Extreme listings (only if YITH Badge Management isn't active)
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_before_shop_loop_item_title', function () {
	global $product;
	$yith = defined( 'YITH_WCBM_VERSION' ) || class_exists( 'YITH_WCBM' );
	if ( ! $yith && $product && has_term( 'extreme-subscription', 'product_cat', $product->get_id() ) ) {
		echo '<span class="sbl-popular">Popular</span>';
	}
}, 9 );

add_action( 'wp_head', function () {
	echo '<style>.woocommerce-product-details__short-description a:has(> img.socials){display:inline-block;margin:0 10px 0 0;line-height:0;vertical-align:middle}.woocommerce-product-details__short-description img.socials{width:34px!important;height:34px!important;max-width:34px;display:inline-block!important;margin:0!important;border-radius:50%;object-fit:contain}</style>';
	echo '<style>.sbl-popular{position:absolute;z-index:3;top:10px;left:10px;background:#7e4fc4;color:#fff;font-size:12px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:3px 9px;border-radius:999px}li.product{position:relative}</style>';
} );

/* -------------------------------------------------------------------------
 * Admin: listing panel on the product editor
 * ---------------------------------------------------------------------- */

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'sbl_listing', 'Vendor listing', 'sbl_meta_box', 'product', 'side', 'high' );
} );

function sbl_meta_box( $post ) {
	$product = wc_get_product( $post->ID );
	if ( ! $product ) {
		return;
	}
	$owner   = get_userdata( (int) $product->get_meta( '_sbl_owner' ) );
	$sub_id  = (int) $product->get_meta( '_sbl_subscription_id' );
	$state   = $product->get_meta( '_sbl_state' );
	$labels  = array( 'awaiting' => 'Awaiting your approval', 'changes_pending' => 'Changes awaiting approval', 'live' => 'Live', 'hidden' => 'Hidden (plan not active)' );
	wp_nonce_field( 'sbl_link_sub', 'sbl_link_nonce' );
	if ( $owner ) {
		echo '<p><strong>Status:</strong> ' . esc_html( $labels[ $state ] ?? '—' ) . '</p>';
		echo '<p><strong>Vendor:</strong> ' . esc_html( $owner->display_name ) . '<br><a href="mailto:' . esc_attr( $owner->user_email ) . '">' . esc_html( $owner->user_email ) . '</a></p>';
		$is_swap = 'boxswap' === $product->get_meta( '_sbl_source' );
		if ( $is_swap ) {
			$tier = $product->get_meta( '_sbl_tier' );
			echo '<p><strong>Box Swap</strong> — level: ' . esc_html( $tier ? sbl_plans()[ $tier ]['label'] : 'not set yet' ) . '<br>' . esc_html( sbl_swap_details( $product ) ) . '</p>';
		}
		if ( 'awaiting' === $state ) {
			if ( $is_swap ) {
				foreach ( SBL_SWAP_TIERS as $t ) {
					echo '<p><a class="button button-primary" href="' . esc_url( add_query_arg( 'tier', $t, sbl_action_url( $product, 'approve' ) ) ) . '">Approve as ' . esc_html( sbl_plans()[ $t ]['label'] ) . '</a></p>';
				}
			} else {
				echo '<p><a class="button button-primary" href="' . esc_url( sbl_action_url( $product, 'approve' ) ) . '">Approve &amp; publish</a></p>';
			}
		} elseif ( $is_swap && 'live' === $state ) {
			echo '<p>Change level: ';
			foreach ( SBL_SWAP_TIERS as $t ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( 'tier', $t, sbl_action_url( $product, 'tier' ) ) ) . '">' . esc_html( sbl_plans()[ $t ]['label'] ) . '</a> ';
			}
			echo '</p>';
		}
		if ( 'changes_pending' === $state ) {
			echo '<p>' . wp_kses_post( sbl_changes_summary( $product ) ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( sbl_action_url( $product, 'approve' ) ) . '">Approve changes</a> <a class="button" href="' . esc_url( sbl_action_url( $product, 'reject' ) ) . '">Reject</a></p>';
		}
	} else {
		echo '<p>Not linked to a vendor yet. To have this listing hide automatically when a plan ends, enter the vendor\'s subscription ID.</p>';
	}
	echo '<p class="description">Subtitle field: <code>' . esc_html( sbl_subtitle_key() ) . '</code></p>';
	echo '<p><label for="sbl_link_sub"><strong>Subscription ID</strong></label><br><input type="number" id="sbl_link_sub" name="sbl_link_sub" value="' . esc_attr( $sub_id ? $sub_id : '' ) . '" style="width:100%"></p>';
	if ( $sub_id && function_exists( 'wcs_get_subscription' ) ) {
		$sub = wcs_get_subscription( $sub_id );
		if ( $sub ) {
			echo '<p><a href="' . esc_url( $sub->get_edit_order_url() ) . '">Subscription #' . esc_html( $sub_id ) . '</a> — ' . esc_html( wcs_get_subscription_status_name( $sub->get_status() ) ) . '</p>';
		}
	}
}

/** "Box sells for: Over $50, Sends: Every 3 Months" from the Box Swap subscription. */
function sbl_swap_details( WC_Product $product ) {
	$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( (int) $product->get_meta( '_sbl_subscription_id' ) ) : null;
	if ( ! $sub ) {
		return '';
	}
	foreach ( $sub->get_items() as $item ) {
		$parts = array();
		foreach ( $item->get_formatted_meta_data( '_', true ) as $meta ) {
			$parts[] = wp_strip_all_tags( $meta->display_key ) . ': ' . wp_strip_all_tags( $meta->display_value );
		}
		if ( $parts ) {
			return implode( ', ', $parts );
		}
	}
	return '';
}

/** Short list of which fields a pending edit changes (or, with $before/$after, between two versions). */
function sbl_changes_summary( WC_Product $product, $before = null, $after = null ) {
	$pending = null === $after ? (array) $product->get_meta( '_sbl_pending' ) : (array) $after;
	$current = null === $before ? sbl_form_values( $product, array() ) : (array) $before;
	$names   = array( 'name' => 'name', 'subtitle' => 'subtitle', 'url' => 'link', 'tagline' => 'tagline', 'description' => 'description', 'price' => 'price', 'cats' => 'categories', 'attrs' => 'details', 'image_ids' => 'images', 'social' => 'social links' );
	$changed = array();
	foreach ( $names as $k => $label ) {
		if ( array_key_exists( $k, $pending ) && wp_json_encode( $pending[ $k ] ) !== wp_json_encode( $current[ $k ] ) ) {
			$changed[] = $label;
		}
	}
	return $changed ? 'Changed: ' . esc_html( implode( ', ', $changed ) ) : 'No visible changes.';
}

/** Manually link an existing listing to a subscription (for listings made before this plugin). */
add_action( 'save_post_product', function ( $post_id ) {
	if ( ! isset( $_POST['sbl_link_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sbl_link_nonce'] ) ), 'sbl_link_sub' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$sub_id = absint( $_POST['sbl_link_sub'] ?? 0 );
	$old    = (int) get_post_meta( $post_id, '_sbl_subscription_id', true );
	if ( $sub_id === $old ) {
		return;
	}
	if ( $old && ( $old_sub = wcs_get_subscription( $old ) ) ) {
		$old_sub->delete_meta_data( '_sbl_listing_id' );
		$old_sub->save();
	}
	if ( ! $sub_id ) {
		delete_post_meta( $post_id, '_sbl_subscription_id' );
		return;
	}
	$sub = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $sub_id ) : null;
	if ( ! $sub ) {
		return;
	}
	update_post_meta( $post_id, '_sbl_subscription_id', $sub_id );
	if ( ! get_post_meta( $post_id, '_sbl_owner', true ) ) {
		update_post_meta( $post_id, '_sbl_owner', $sub->get_customer_id() );
		update_post_meta( $post_id, '_sbl_state', 'publish' === get_post_status( $post_id ) ? 'live' : 'awaiting' );
		update_post_meta( $post_id, '_sbl_token', wp_generate_password( 32, false ) );
	}
	$plan = sbl_plan_from_subscription( $sub );
	if ( $plan ) {
		update_post_meta( $post_id, '_sbl_plan', $plan );
	}
	$sub->update_meta_data( '_sbl_listing_id', $post_id );
	$sub->save();
}, 20 );

add_action( 'admin_notices', function () {
	if ( empty( $_GET['sbl_done'] ) ) {
		return;
	}
	$msg = array( 'approve' => 'Approved — the listing is live and the vendor has been emailed.', 'reject' => 'Changes rejected — the vendor has been emailed.', 'nothing' => 'Nothing to do — this listing had nothing waiting for approval.' );
	$k   = sanitize_key( wp_unslash( $_GET['sbl_done'] ) );
	if ( isset( $msg[ $k ] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg[ $k ] ) . '</p></div>';
	}
} );

/* -------------------------------------------------------------------------
 * Marketplace upgrades (Premium Marketplace / Extreme Marketplace)
 * Applies perks to every product the WCFM vendor owns; removes them when the upgrade ends.
 * ---------------------------------------------------------------------- */

function sbl_mkt_perks() {
	return array(
		'mkt_premium' => array( 'label' => 'Premium Marketplace', 'slug' => 'premium-marketplace', 'order' => 2, 'featured' => false, 'slider' => false, 'badge' => false ),
		'mkt_extreme' => array( 'label' => 'Extreme Marketplace', 'slug' => 'extreme-marketplace', 'order' => 1, 'featured' => true,  'slider' => true,  'badge' => true ),
	);
}

/** mkt_premium | mkt_extreme for a subscription or order, or ''. */
function sbl_mkt_tier_from_items( $items ) {
	$best = '';
	foreach ( $items as $item ) {
		$plan = sbl_plan_from_product( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
		if ( 'mkt_extreme' === $plan ) {
			return $plan;
		}
		if ( 'mkt_premium' === $plan ) {
			$best = $plan;
		}
	}
	return $best;
}
function sbl_order_mkt_tier( $order ) {
	return sbl_mkt_tier_from_items( $order->get_items() );
}

/** The best marketplace upgrade a vendor currently has (active or cancelled-but-paid-up), or ''. */
function sbl_vendor_mkt_tier( $user_id ) {
	if ( ! $user_id || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
		return '';
	}
	$best = '';
	foreach ( wcs_get_users_subscriptions( $user_id ) as $sub ) {
		if ( ! $sub->has_status( array( 'active', 'pending-cancel', 'on-hold' ) ) ) {
			continue;
		}
		$tier = sbl_mkt_tier_from_items( $sub->get_items() );
		if ( 'mkt_extreme' === $tier ) {
			return $tier;
		}
		if ( $tier ) {
			$best = $tier;
		}
	}
	return $best;
}

function sbl_vendor_product_ids( $user_id ) {
	return get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'pending', 'draft', 'private' ), 'author' => (int) $user_id, 'fields' => 'ids', 'posts_per_page' => -1 ) );
}

/** Put a product on the given upgrade ('' = take everything off and restore it). */
function sbl_mkt_apply( $product_id, $tier ) {
	$product = wc_get_product( $product_id );
	if ( ! $product || $product->get_meta( '_sbl_owner' ) ) {
		return; // listing-plan products are managed by their own plan
	}
	$perks   = sbl_mkt_perks();
	$current = $product->get_meta( '_sbl_mkt_tier' );
	if ( $current === $tier ) {
		return;
	}
	// Take the old upgrade off first.
	if ( $current ) {
		$added = array_map( 'absint', (array) $product->get_meta( '_sbl_mkt_added_cats' ) );
		$product->set_category_ids( array_values( array_diff( array_map( 'absint', $product->get_category_ids() ), $added ) ) );
		$orig = (array) $product->get_meta( '_sbl_mkt_orig' );
		$product->set_featured( ! empty( $orig['featured'] ) );
		sbl_apply_badge( $product, false );
		foreach ( array( '_sbl_mkt_tier', '_sbl_mkt_added_cats', '_sbl_mkt_orig' ) as $k ) {
			$product->delete_meta_data( $k );
		}
	}
	if ( $tier && isset( $perks[ $tier ] ) ) {
		$p = $perks[ $tier ];
		$product->update_meta_data( '_sbl_mkt_orig', array( 'order' => $product->get_menu_order(), 'featured' => $product->get_featured() ) );
		$want = array(
			sbl_category_id( sbl_popular_cat() ),
			sbl_category_id( $p['slug'], $p['label'], sbl_admin_parent() ? sbl_admin_parent()->slug : '' ),
		);
		if ( $p['slider'] ) {
			$want[] = sbl_category_id( SBL_SLIDER_CAT );
		}
		$existing = array_map( 'absint', $product->get_category_ids() );
		$added    = array_values( array_diff( array_filter( $want ), $existing ) );
		$product->set_category_ids( array_values( array_unique( array_merge( $existing, $added ) ) ) );
		$product->update_meta_data( '_sbl_mkt_added_cats', $added );
		$product->set_menu_order( $p['order'] );
		if ( $p['featured'] ) {
			$product->set_featured( true );
		}
		sbl_apply_badge( $product, $p['badge'] );
		$product->update_meta_data( '_sbl_mkt_tier', $tier );
	}
	$product->set_menu_order( sbl_menu_order_for( $product ) );
	$GLOBALS['sbl_mkt_saving'] = true;
	$product->save();
	$GLOBALS['sbl_mkt_saving'] = false;
}

/** Re-sync all of a vendor's products to whatever upgrade they currently have. Returns the number of products. */
function sbl_mkt_sync_vendor( $user_id ) {
	$tier = sbl_vendor_mkt_tier( $user_id );
	$ids  = sbl_vendor_product_ids( $user_id );
	foreach ( $ids as $id ) {
		sbl_mkt_apply( $id, $tier );
	}
	return count( $ids );
}

add_action( 'woocommerce_subscription_status_updated', function ( $sub, $new, $old ) {
	$tier = sbl_mkt_tier_from_items( $sub->get_items() );
	if ( ! $tier ) {
		return;
	}
	$user  = get_userdata( $sub->get_customer_id() );
	$label = sbl_mkt_perks()[ $tier ]['label'];
	$who   = $user ? $user->display_name . ' (' . $user->user_email . ')' : 'Customer #' . $sub->get_customer_id();
	if ( 'pending-cancel' === $new ) {
		$end = $sub->get_date( 'end' );
		sbl_send( sbl_admin_email(), "{$label} cancelled: {$who}", '<p>' . esc_html( $who ) . ' cancelled their <strong>' . esc_html( $label ) . '</strong> upgrade. The perks stay on until the paid period ends' . ( $end ? ' on <strong>' . esc_html( date_i18n( get_option( 'date_format' ), wcs_date_to_time( $end ) ) ) . '</strong>' : '' ) . ', then come off automatically.</p>', $user );
		return;
	}
	$count = sbl_mkt_sync_vendor( $sub->get_customer_id() );
	if ( 'active' === $new && 'active' !== $old ) {
		$note = $count ? "Applied to {$count} of their products." : '<strong>They have no marketplace products yet</strong> (or bought it from a different account than their vendor account) — perks will apply as soon as they add products under this account.';
		sbl_send( sbl_admin_email(), "{$label} upgrade active: {$who}", '<p>' . esc_html( $who ) . ' bought <strong>' . esc_html( $label ) . '</strong>. ' . $note . '</p><p>Remember the social media posts for this vendor.</p>', $user );
	} elseif ( in_array( $new, array( 'cancelled', 'expired' ), true ) ) {
		sbl_send( sbl_admin_email(), "{$label} ended: {$who}", '<p>The <strong>' . esc_html( $label ) . '</strong> upgrade for ' . esc_html( $who ) . ' has ended. The perks have been removed from ' . (int) $count . ' products and they are back to normal (still listed).</p>', $user );
	}
}, 20, 3 );

add_action( 'woocommerce_subscriptions_switch_completed', function ( $order ) {
	sbl_mkt_sync_vendor( $order->get_customer_id() );
}, 20 );

/** New or edited vendor products pick up the vendor's current upgrade. */
add_action( 'save_post_product', function ( $post_id, $post ) {
	if ( ! empty( $GLOBALS['sbl_mkt_saving'] ) || wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
		return;
	}
	$tier = sbl_vendor_mkt_tier( (int) $post->post_author );
	if ( $tier !== (string) get_post_meta( $post_id, '_sbl_mkt_tier', true ) ) {
		sbl_mkt_apply( $post_id, $tier );
	}
}, 30, 2 );

/* -------------------------------------------------------------------------
 * Menu order (shop / search position)
 * 1 Extreme · 2 Premium · 3 Marketplace vendor · 4 Affiliate · 5 Basic · 6 Mini · 7 everything else
 * ---------------------------------------------------------------------- */

function sbl_order_rules() {
	return apply_filters( 'sbl_order_rules', array(
		'extreme'   => 1,
		'premium'   => 2,
		'vendor'    => 3,
		'affiliate' => 4,
		'basic'     => 5,
		'mini'      => 6,
		'other'     => 7,
	) );
}

/** Is this user a WCFM marketplace vendor? */
function sbl_is_marketplace_vendor( $user_id ) {
	$user = $user_id ? get_userdata( $user_id ) : null;
	return $user && array_intersect( (array) $user->roles, array( 'wcfm_vendor', 'seller', 'vendor', 'dc_vendor' ) );
}

/** The menu order a product should have under the rules (uses its current, possibly unsaved, data). */
function sbl_menu_order_for( WC_Product $product ) {
	$r     = sbl_order_rules();
	$cats  = array_map( 'absint', $product->get_category_ids() );
	$has   = function ( $slug ) use ( $cats ) {
		$id = sbl_category_id( $slug );
		return $id && in_array( $id, $cats, true );
	};
	$ranks = array( $r['other'] );
	$plan  = $product->get_meta( '_sbl_plan' );
	$mkt   = $product->get_meta( '_sbl_mkt_tier' );
	if ( 'extreme' === $plan || 'mkt_extreme' === $mkt || $has( 'extreme-subscription' ) ) {
		$ranks[] = $r['extreme'];
	}
	if ( 'premium' === $plan || 'mkt_premium' === $mkt || $has( 'premium-subscription' ) ) {
		$ranks[] = $r['premium'];
	}
	$author = (int) get_post_field( 'post_author', $product->get_id() );
	if ( $product->get_id() && ! $product->get_meta( '_sbl_owner' ) && sbl_is_marketplace_vendor( $author ) ) {
		$ranks[] = $r['vendor'];
	}
	if ( $has( 'affiliate-link' ) ) {
		$ranks[] = $r['affiliate'];
	}
	if ( 'basic' === $plan || $has( 'basic-subscription' ) ) {
		$ranks[] = $r['basic'];
	}
	if ( 'mini' === $plan || $has( 'mini-subscription' ) ) {
		$ranks[] = $r['mini'];
	}
	return (int) min( $ranks );
}

/** Products the order rule should leave alone: the plan products themselves. */
function sbl_order_skip( WC_Product $product ) {
	return has_term( SBL_PLANS_CAT, 'product_cat', $product->get_id() ) || 'boxswap' === sbl_plan_from_product( $product->get_id() ) || 0 === strpos( (string) sbl_plan_from_product( $product->get_id() ), 'mkt_' );
}

/** Set a product's order straight in the database (no save hooks). Returns true if it changed. */
function sbl_sync_menu_order( $product_id ) {
	$product = wc_get_product( $product_id );
	if ( ! $product || $product->get_parent_id() || sbl_order_skip( $product ) ) {
		return false;
	}
	$want = sbl_menu_order_for( $product );
	if ( (int) get_post_field( 'menu_order', $product_id ) === $want ) {
		return false;
	}
	global $wpdb;
	$wpdb->update( $wpdb->posts, array( 'menu_order' => $want ), array( 'ID' => $product_id ) );
	clean_post_cache( $product_id );
	return true;
}

// Keep it right whenever a product is saved. These run after WooCommerce has written categories,
// so the rule sees the final data.
foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product' ) as $sbl_hook ) {
	add_action( $sbl_hook, function ( $product_id ) {
		if ( 'auto-draft' !== get_post_status( $product_id ) ) {
			sbl_sync_menu_order( $product_id );
		}
	}, 40 );
}

// One-click "apply to everything" under Tools → Listing order.
add_action( 'admin_menu', function () {
	add_management_page( 'Listing order', 'Listing order', 'manage_woocommerce', 'sbl-order', function () {
		$r = sbl_order_rules();
		echo '<div class="wrap"><h1>Listing order</h1><p>Shop and search position for every product:</p><ol>';
		foreach ( array( 'Extreme (plans, Box Swap at Extreme, Extreme Marketplace)', 'Premium (plans, Box Swap at Premium, Premium Marketplace)', 'Marketplace vendor products', 'Affiliate listings', 'Basic', 'Mini', 'Everything else' ) as $label ) {
			echo '<li>' . esc_html( $label ) . '</li>';
		}
		echo '</ol><p>New and edited products follow this automatically. Use the button to apply it to all existing products now.</p>';
		if ( isset( $_GET['sbl_done'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( absint( $_GET['sbl_done'] ) ) . ' products updated.</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sbl_order_all' );
		echo '<input type="hidden" name="action" value="sbl_order_all"><p><button class="button button-primary">Apply to all products</button></p></form>';
		echo '<p class="description">For this to show in the shop, WooCommerce → Settings → Products → Default product sorting must be "Default sorting (custom ordering + name)".</p></div>';
	} );
} );

add_action( 'admin_post_sbl_order_all', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'sbl_order_all' ) ) {
		wp_die( 'Not allowed.' );
	}
	$ids     = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'fields' => 'ids', 'posts_per_page' => -1 ) );
	$changed = 0;
	foreach ( $ids as $id ) {
		$changed += sbl_sync_menu_order( $id ) ? 1 : 0;
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'sbl-order', 'sbl_done' => $changed ), admin_url( 'tools.php' ) ) );
	exit;
} );

/* -------------------------------------------------------------------------
 * Emails
 * ---------------------------------------------------------------------- */

function sbl_send( $to, $subject, $html, $reply_to = null ) {
	if ( ! $to ) {
		return;
	}
	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	if ( $reply_to && ! empty( $reply_to->user_email ) ) {
		$headers[] = 'Reply-To: ' . str_replace( array( '"', "\r", "\n" ), '', $reply_to->display_name ) . ' <' . $reply_to->user_email . '>';
	}
	$mailer = WC()->mailer();
	$mailer->send( $to, $subject, $mailer->wrap_message( $subject, $html ), $headers );
}

function sbl_notify_vendor( $user, $subject, $html ) {
	if ( $user ) {
		sbl_send( $user->user_email, $subject, $html );
	}
}

function sbl_notify_admin( WC_Product $product, $type, $sub, $is_update = false ) {
	$owner = get_userdata( (int) $product->get_meta( '_sbl_owner' ) );
	$who   = $owner ? $owner->display_name . ' (' . $owner->user_email . ')' : 'A vendor';
	$plans = sbl_plans();
	$plan  = $product->get_meta( '_sbl_plan' );
	$plan  = isset( $plans[ $plan ] ) ? $plans[ $plan ]['label'] : '';
	$name  = esc_html( $product->get_name() );
	$edit  = esc_url( admin_url( 'post.php?post=' . $product->get_id() . '&action=edit' ) );
	$prev  = esc_url( get_preview_post_link( $product->get_id() ) );
	$end   = $sub ? $sub->get_date( 'end' ) : '';
	$end   = $end ? date_i18n( get_option( 'date_format' ), wcs_date_to_time( $end ) ) : '';

	switch ( $type ) {
		case 'new':
			if ( 'boxswap' === $product->get_meta( '_sbl_source' ) ) {
				$subject = ( $is_update ? 'Updated' : 'New' ) . " Box Swap listing to approve: {$product->get_name()}";
				$btns    = '';
				foreach ( SBL_SWAP_TIERS as $t ) {
					$btns .= '<a href="' . esc_url( add_query_arg( 'tier', $t, sbl_action_url( $product, 'approve' ) ) ) . '" style="display:inline-block;background:#7e4fc4;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:bold;margin:0 6px 6px 0">Approve as ' . esc_html( $plans[ $t ]['label'] ) . '</a>';
				}
				$html = "<p>{$who} " . ( $is_update ? 'updated their' : 'submitted a' ) . " <strong>Box Swap</strong> listing: <strong>{$name}</strong>." . ( $is_update && ! empty( $GLOBALS['sbl_last_changes'] ) ? ' ' . esc_html( $GLOBALS['sbl_last_changes'] ) : '' ) . "</p><p>" . esc_html( sbl_swap_details( $product ) ) . "</p><p>{$btns}</p><p><a href=\"{$prev}\">Preview</a> · <a href=\"{$edit}\">Edit first</a></p>";
				break;
			}
			$subject = ( $is_update ? 'Updated' : 'New' ) . " listing to approve: {$product->get_name()}";
			$html    = "<p>{$who} " . ( $is_update ? 'updated their' : 'submitted a new' ) . " <strong>{$plan}</strong> listing: <strong>{$name}</strong>." . ( $is_update && ! empty( $GLOBALS['sbl_last_changes'] ) ? ' ' . esc_html( $GLOBALS['sbl_last_changes'] ) : '' ) . "</p><p><a href=\"" . esc_url( sbl_action_url( $product, 'approve' ) ) . "\" style=\"display:inline-block;background:#7e4fc4;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:bold\">Approve &amp; publish</a></p><p><a href=\"{$prev}\">Preview</a> · <a href=\"{$edit}\">Edit first</a></p>";
			break;
		case 'changes':
			$subject = "Listing changes to approve: {$product->get_name()}";
			$html    = "<p>{$who} sent changes to <strong>{$name}</strong>. " . sbl_changes_summary( $product ) . "</p><p><a href=\"" . esc_url( sbl_action_url( $product, 'approve' ) ) . "\" style=\"display:inline-block;background:#7e4fc4;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:bold\">Approve changes</a> &nbsp; <a href=\"" . esc_url( sbl_action_url( $product, 'reject' ) ) . "\">Reject</a></p><p><a href=\"{$edit}\">Open the listing</a></p>";
			break;
		case 'cancelling':
			$subject = "Plan cancelled: {$product->get_name()}";
			$html    = "<p>{$who} cancelled their <strong>{$plan}</strong> plan. <strong>{$name}</strong> stays live until the paid period ends" . ( $end ? " on <strong>{$end}</strong>" : '' ) . ', then hides automatically.</p>';
			break;
		case 'hidden':
			$subject = "Listing hidden: {$product->get_name()}";
			$html    = "<p>The plan for <strong>{$name}</strong> has ended, so the listing has been hidden (set to draft). It comes back automatically if the plan is reactivated.</p><p><a href=\"{$edit}\">Open the listing</a></p>";
			break;
		case 'restored':
			$subject = "Listing restored: {$product->get_name()}";
			$html    = "<p>The plan for <strong>{$name}</strong> is active again, so the listing is live again.</p>";
			break;
		case 'switched':
			$subject = "Plan changed: {$product->get_name()}";
			$html    = "<p>{$who} switched to the <strong>{$plan}</strong> plan. The tier, Popular Boxes, featured status and search position on <strong>{$name}</strong> have been updated. Images or categories over the new limit stay until their next edit.</p><p><a href=\"{$edit}\">Open the listing</a></p>";
			break;
		default:
			return;
	}
	sbl_send( sbl_admin_email(), $subject, $html . ( $owner ? '<p style="color:#6b6478;font-size:13px">Reply to this email to contact the vendor directly.</p>' : '' ), $owner );
}
