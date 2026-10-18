<?php
/**
 * Settings store for the toolkit. Everything editable on SB Toolkit in wp-admin lives in the
 * `sbt_settings` option; anything not saved there falls back to the defaults below (per site).
 */
defined( 'ABSPATH' ) || exit;

/** 'au' on subscriptionboxaustralia.com, otherwise 'nz'. */
function sbt_site() {
	return false !== strpos( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'subscriptionboxaustralia' ) ? 'au' : 'nz';
}

function sbt_defaults() {
	$au = 'au' === sbt_site();
	return array(
		'plans'             => array(
			'mini'    => array( 'id' => $au ? 6532 : 289, 'tagline' => 'Get listed', 'features' => "1 image\n1 category\nLink to your website" ),
			'basic'   => array( 'id' => $au ? 5668 : 290, 'tagline' => 'Start getting seen', 'features' => "3 images\n2 categories\nLink to your website\nMonthly social media post" ),
			'premium' => array( 'id' => $au ? 5682 : 292, 'tagline' => 'Stand out', 'features' => "5 images\n3 categories\nLink to your website\nFortnightly social media posts\nPopular box category\nYour social media links\nHigher in search & pages" ),
			'extreme' => array( 'id' => $au ? 5687 : 291, 'tagline' => 'Maximum exposure', 'features' => "Unlimited images\nUnlimited categories\nLink to your website\nWeekly social media posts\nPopular box category\nYour social media links\nTop of search & pages\nFeatured on the front page\nPopular box label" ),
		),
		'plans_recommended' => 'extreme',
		'plans_badge'       => 'Recommended',
		'plans_footer'      => "Pay securely by PayPal or card. You'll set up your listing right after checkout.",
		'mkt'               => array(
			'premium' => array( 'id' => $au ? 6219 : 0, 'tagline' => 'Marketplace upgrade', 'features' => "Higher in search & pages\nFortnightly social media posts\nPopular box category\nYour social media links" ),
			'extreme' => array( 'id' => $au ? 6222 : 0, 'tagline' => 'Marketplace upgrade', 'features' => "Top of search & pages\nWeekly social media posts\nPopular box category\nYour social media links\nFeatured on the front page\nPopular box label" ),
		),
		'mkt_recommended'   => 'extreme',
		'mkt_badge'         => 'Recommended',
		'mkt_footer'        => 'For existing marketplace vendors only — please buy it while logged in to your vendor account. The upgrade applies to all your marketplace listings. Pay securely by PayPal or card.',
		'cats_items'        => $au
			? "popular-subscription-boxes : Popular\nall : All\nbeauty-subscription-boxes : Beauty\nfood-snack-subscription-boxes : Food\nkids-babies-subscription-boxes : Kids/Baby\norganic-natural-vegan-subscription-boxes : Organic\npets-subscription-boxes : Pets\nhealth-fitness-subscription-boxes : Fitness\narts-crafts-creative-subscription-boxes : Craft\nfashion-subscription-boxes : Fashion\nmens-subscription-boxes : Mens\nhouse-home-subscription-boxes : Home\nbook-subscription-boxes : Book\nperiod-tampon-subscription-boxes : Period\nadults-sex-toy-subscription-boxes : Adult\ndrinks-beverage-boxes : Drink\ncollectables-subscription-boxes : Collectable\ngift-subscription-boxes : Gift\nmiscellaneous-subscription-boxes : Miscellaneous\ninternational-subscription-boxes : International"
			: "popular-boxes : Popular\nall : All\nbeauty-boxes : Beauty\nfood-snack-boxes : Food\nkids-babies-boxes : Kids/Baby\norganic-vegan-boxes : Organic\npets-boxes : Pets\nhealth-boxes : Fitness\narts-crafts-creative-boxes : Craft\nfashion-boxes : Fashion\nmens-boxes : Mens\nhouse-home-boxes : Home\nbook-boxes : Book\nperiod-tampon-boxes : Period\nadults-sex-toy-boxes : Adult\ndrinks-beverage-boxes : Drink\ncollectables-boxes : Collectable\ngift-boxes : Gift\nmiscellaneous-boxes : Miscellaneous\ninternational-boxes : International",
		'cats_sub'          => 'Boxes',
		'cats_look'         => $au ? 'au' : 'nz',
		'cats_all_url'      => $au ? '/directory/' : '',
		'popular_cat'       => $au ? 'popular-subscription-boxes' : 'popular-boxes',
		'exclude_cats'      => 'cancelled, plans, discontinued',
		'listing_limits'    => array(
			'mini'    => array( 'images' => 1, 'cats' => 1, 'order' => 6 ),
			'basic'   => array( 'images' => 3, 'cats' => 2, 'order' => 5 ),
			'premium' => array( 'images' => 5, 'cats' => 3, 'order' => 2 ),
			'extreme' => array( 'images' => 20, 'cats' => 0, 'order' => 1 ),
			'boxswap' => array( 'images' => 20, 'cats' => 0, 'order' => 7 ),
		),
		'listing_button'    => 'Subscribe To Box',
		'listing_max_mb'    => 5,
		'disc_upsells'      => 3,
		'disc_message'      => 'This box is no longer available.',
		'disc_link_text'    => 'See similar boxes',
		'disc_on_plan_end'  => 'draft',
		'fx_overrides'      => '',
		'gh_repo'           => 'Ezzzy007/Subscription-Box',
		'modules'           => array( 'listings' => 1, 'price_guard' => 1, 'products_tidy' => 1, 'discontinue' => 1, 'fx' => 0, 'fields' => 1 ),
	);
}

/** Saved settings merged over the defaults. sbt_get( 'plans' ), sbt_get( 'cats_sub' ) … */
function sbt_get( $key = null ) {
	static $all = null;
	if ( null === $all ) {
		$saved = get_option( 'sbt_settings', array() );
		$all   = sbt_merge( sbt_defaults(), is_array( $saved ) ? $saved : array() );
	}
	if ( null === $key ) {
		return $all;
	}
	return isset( $all[ $key ] ) ? $all[ $key ] : null;
}

/** Recursive merge that only keeps keys the defaults know about. */
function sbt_merge( $defaults, $saved ) {
	foreach ( $defaults as $k => $v ) {
		if ( ! array_key_exists( $k, $saved ) ) {
			continue;
		}
		$defaults[ $k ] = is_array( $v ) ? sbt_merge( $v, (array) $saved[ $k ] ) : $saved[ $k ];
	}
	return $defaults;
}

/** Textarea → array of trimmed non-empty lines. */
function sbt_lines( $text ) {
	return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $text ) ), 'strlen' ) );
}

/** Comma/line separated slugs → array. */
function sbt_slugs( $text ) {
	return array_values( array_filter( array_map( 'sanitize_title', preg_split( '/[\s,]+/', (string) $text ) ) ) );
}

/** Rows for a pricing table: product id => config. */
function sbt_plan_rows( $group, $recommended, $badge ) {
	$out = array();
	foreach ( (array) sbt_get( $group ) as $key => $p ) {
		$id = absint( $p['id'] );
		if ( ! $id ) {
			continue;
		}
		$row = array( 'key' => $key, 'tagline' => (string) $p['tagline'], 'features' => sbt_lines( $p['features'] ) );
		if ( $key === $recommended ) {
			$row['featured'] = true;
			$row['badge']    = $badge;
		}
		$out[ $id ] = $row;
	}
	return $out;
}

/** Is a module switched on (SB Toolkit → Vendor listings / Site tweaks)? */
function sbt_module_on( $key ) {
	$m = (array) sbt_get( 'modules' );
	return ! empty( $m[ $key ] );
}
