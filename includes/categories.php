<?php
/** Category tiles: [sb_categories]. */
defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * [sb_categories] – "Shop by category" tiles (text, so they fit any screen).
 * [sb_categories items="popular-boxes,all,beauty-boxes,..."] to choose/ordering; "all" = the shop page.
 * Optional label override: items="popular-boxes:Popular,food-snack-boxes:Snacks".
 * ---------------------------------------------------------------------- */
function sb_categories_is_au() {
	return 'au' === sbt_site();
}

/** "slug:Label,slug:Label" from SB Toolkit → Category tiles. */
function sb_categories_default_items() {
	$items = array();
	foreach ( sbt_lines( sbt_get( 'cats_items' ) ) as $line ) {
		$parts   = array_map( 'trim', explode( ':', $line, 2 ) );
		$items[] = $parts[0] . ( isset( $parts[1] ) && '' !== $parts[1] ? ':' . str_replace( ',', ' ', $parts[1] ) : '' );
	}
	return implode( ',', $items );
}

add_shortcode( 'sb_categories', function ( $atts = array() ) {
	$atts  = shortcode_atts( array( 'items' => sb_categories_default_items(), 'sub' => sbt_get( 'cats_sub' ), 'look' => sbt_get( 'cats_look' ) ), $atts, 'sb_categories' );
	$tiles = array();
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) $atts['items'] ) ) ) as $item ) {
		$parts = explode( ':', $item, 2 );
		$slug  = sanitize_title( $parts[0] );
		$label = isset( $parts[1] ) ? trim( $parts[1] ) : '';
		if ( 'all' === $slug ) {
			$all   = trim( (string) sbt_get( 'cats_all_url' ) );
			$url   = $all ? ( 0 === strpos( $all, 'http' ) ? $all : home_url( $all ) ) : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ) );
			$label = $label ? $label : 'All';
		} else {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue; // category doesn't exist on this site
			}
			$url   = get_term_link( $term );
			$label = $label ? $label : trim( preg_replace( '/\s+(subscription\s+)?boxes$/i', '', html_entity_decode( $term->name ) ) );
		}
		if ( is_wp_error( $url ) ) {
			continue;
		}
		$len  = strlen( $label );
		$size = $len >= 11 ? ' sbc-xl' : ( $len >= 9 ? ' sbc-l' : '' );
		$tiles[] = '<a class="sbc-tile' . $size . '" href="' . esc_url( $url ) . '"><span class="sbc-t">' . esc_html( $label ) . '</span><span class="sbc-s">' . esc_html( $atts['sub'] ) . '</span></a>';
	}
	if ( ! $tiles ) {
		return '';
	}
	static $font_done = false;
	$font = '';
	if ( ! $font_done ) {
		$font_done = (bool) did_action( 'wp_head' ); // an early (thrown-away) render doesn't count
		$font = '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo+Black&display=swap"><style>' . sb_categories_css() . '</style>';
	}
	return $font . '<nav class="sbc-grid sbc-' . esc_attr( sanitize_title( $atts['look'] ) ) . '" aria-label="Shop by category">' . implode( '', $tiles ) . '</nav>';
} );

function sb_categories_css() {
	$bg = 'radial-gradient(ellipse 60% 70% at 12% 25%,rgba(236,120,200,.85),transparent 70%),'
		. 'radial-gradient(ellipse 55% 80% at 88% 20%,rgba(120,200,245,.9),transparent 70%),'
		. 'radial-gradient(ellipse 60% 70% at 60% 95%,rgba(250,190,160,.9),transparent 70%),'
		. 'radial-gradient(ellipse 50% 60% at 35% 70%,rgba(190,160,240,.75),transparent 70%),'
		. 'radial-gradient(ellipse 45% 60% at 75% 60%,rgba(255,170,215,.7),transparent 70%),#f6eef6';
	$au = 'radial-gradient(ellipse 60% 70% at 12% 25%,rgba(95,201,196,.8),transparent 70%),'
		. 'radial-gradient(ellipse 55% 80% at 88% 20%,rgba(120,190,240,.75),transparent 70%),'
		. 'radial-gradient(ellipse 60% 70% at 60% 95%,rgba(255,214,140,.8),transparent 70%),'
		. 'radial-gradient(ellipse 50% 60% at 35% 70%,rgba(170,230,200,.75),transparent 70%),'
		. 'radial-gradient(ellipse 45% 60% at 75% 60%,rgba(255,180,160,.55),transparent 70%),#effaf9';
	return '.sbc-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin:1.25rem auto 2rem;max-width:1100px;padding:0}'
		. '.sbc-tile{position:relative;container-type:inline-size;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;aspect-ratio:2.3/1;padding:6px 8px;border-radius:6px;overflow:hidden;'
		. 'background:' . $bg . ';background-size:180% 180%;background-position:0 0;box-shadow:0 2px 6px rgba(40,20,60,.14);'
		. 'color:#111!important;text-decoration:none!important;font-family:"Archivo Black","Arial Black",Impact,sans-serif;font-weight:400;font-synthesis:none;text-transform:uppercase;line-height:1;letter-spacing:.01em;'
		. 'transition:background-position .5s,transform .15s,box-shadow .15s}'
		. '.sbc-tile:nth-child(5n+2){background-position:40% 30%}.sbc-tile:nth-child(5n+3){background-position:80% 60%}.sbc-tile:nth-child(5n+4){background-position:20% 90%}.sbc-tile:nth-child(5n){background-position:100% 20%}'
		. '.sbc-t{display:block;font-size:24px;font-size:17cqi;white-space:nowrap}'
		. '.sbc-l .sbc-t{font-size:20px;font-size:13cqi}.sbc-xl .sbc-t{font-size:16px;font-size:10.5cqi}'
		. '.sbc-s{display:block;margin-top:.12em;font-size:22px;font-size:15cqi;color:transparent;-webkit-text-stroke:1.5px #111;paint-order:stroke fill}'
		. '@supports not (-webkit-text-stroke:1px #000){.sbc-s{color:#111}}'
		. '.sbc-tile:hover,.sbc-tile:focus-visible{background-position:100% 100%;transform:translateY(-2px);box-shadow:0 8px 16px rgba(40,20,60,.2)}'
		. '.sbc-tile:focus-visible{outline:3px solid #111;outline-offset:2px}'
		. '.sbc-au .sbc-tile{background:' . $au . ';background-size:180% 180%;border:2px solid #5fc9c4;box-shadow:0 2px 6px rgba(20,70,70,.12)}'
		. '.sbc-au .sbc-tile:nth-child(5n+2){background-position:40% 30%}.sbc-au .sbc-tile:nth-child(5n+3){background-position:80% 60%}.sbc-au .sbc-tile:nth-child(5n+4){background-position:20% 90%}.sbc-au .sbc-tile:nth-child(5n){background-position:100% 20%}'
		. '.sbc-au .sbc-tile:hover,.sbc-au .sbc-tile:focus-visible{background-position:100% 100%;box-shadow:0 8px 16px rgba(20,70,70,.2)}'
		. '@media (max-width:900px){.sbc-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}'
		. '@media (max-width:680px){.sbc-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.sbc-s{-webkit-text-stroke-width:1.2px}}';
}
