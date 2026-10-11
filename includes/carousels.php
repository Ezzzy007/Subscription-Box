<?php
/** Product carousel [sb_products] and latest posts grid [sb_posts]. */
defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * [sb_products] – product carousel (replaces WooCommerce Product Carousel Slider Pro).
 *   [sb_products cat="slider" limit="8" orderby="rand" autoplay="2.3"]   top "slider" carousel
 *   [sb_products limit="10"]                                             recently added boxes
 *   [sb_products featured="yes" limit="8" orderby="rand" autoplay="2.3"] featured boxes (Extreme listings are featured automatically)
 *   Atts: cat (category slug, blank = all), featured (yes = featured products only), limit, orderby (date|rand|menu_order|title),
 *         show (visible cards on desktop, default 6), autoplay (seconds, 0 = off).
 *   Always skips drafts, hidden products and the Cancelled / Plans categories.
 * [sb_posts] – latest posts grid (replaces Content Views).
 *   [sb_posts limit="4"]  optional cat="category-slug".
 *   Grid on desktop, swipeable row on phones.
 * ---------------------------------------------------------------------- */
function sb_strip_popular_slug() {
	return sanitize_title( (string) sbt_get( 'popular_cat' ) );
}

add_shortcode( 'sb_products', function ( $atts = array() ) {
	$atts = shortcode_atts( array( 'cat' => '', 'featured' => '', 'limit' => 10, 'orderby' => 'date', 'show' => 6, 'autoplay' => 0, 'exclude' => implode( ',', sbt_slugs( sbt_get( 'exclude_cats' ) ) ) ), $atts, 'sb_products' );
	if ( ! function_exists( 'wc_get_product' ) ) {
		return '';
	}
	$tax = array(
		'relation' => 'AND',
		array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'exclude-from-catalog', 'outofstock' ), 'operator' => 'NOT IN' ),
	);
	$exclude = sbt_slugs( $atts['exclude'] );
	if ( $exclude ) {
		$tax[] = array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $exclude, 'operator' => 'NOT IN', 'include_children' => true );
	}
	if ( in_array( strtolower( (string) $atts['featured'] ), array( '1', 'yes', 'true' ), true ) ) {
		$tax[] = array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'featured' ) );
	}
	if ( '' !== trim( (string) $atts['cat'] ) ) {
		$tax[] = array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => array_map( 'sanitize_title', explode( ',', $atts['cat'] ) ) );
	}
	$orderby = in_array( $atts['orderby'], array( 'date', 'rand', 'menu_order', 'title' ), true ) ? $atts['orderby'] : 'date';
	$ids     = get_posts( array(
		'post_type'        => 'product',
		'post_status'      => 'publish',
		'posts_per_page'   => max( 1, min( 30, (int) $atts['limit'] ) ) + 5,
		'orderby'          => 'menu_order' === $orderby ? array( 'menu_order' => 'ASC', 'date' => 'DESC' ) : $orderby,
		'order'            => 'title' === $orderby ? 'ASC' : 'DESC',
		'tax_query'        => $tax, // phpcs:ignore
		'post__not_in'     => function_exists( 'sbnz_all_plan_ids' ) ? array_map( 'absint', sbnz_all_plan_ids() ) : array(),
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'suppress_filters' => false,
	) );
	if ( ! $ids ) {
		return '';
	}
	$popular = sb_strip_popular_slug();
	$cards   = array();
	$max = max( 1, min( 30, (int) $atts['limit'] ) );
	foreach ( $ids as $id ) {
		if ( count( $cards ) >= $max ) {
			break;
		}
		$p = wc_get_product( $id );
		// Only live, visible listings – never plan / subscription products or nameless leftovers.
		if ( ! $p || 'publish' !== $p->get_status() || ! $p->is_visible() || '' === trim( $p->get_name() )
			|| in_array( $p->get_type(), array( 'subscription', 'variable-subscription' ), true ) ) {
			continue;
		}
		$url   = get_permalink( $id );
		$name  = $p->get_name();
		$img   = $p->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'alt' => $name, 'class' => 'sbp-img' ) );
		$badge = has_term( $popular, 'product_cat', $id ) ? '<span class="sbp-badge">Popular!</span>' : '';
		$cards[] = '<li class="sbp-card"><a href="' . esc_url( $url ) . '" title="' . esc_attr( $name ) . '"><span class="sbp-pic">' . $badge . $img . '</span>'
			. '<span class="sbp-name">' . esc_html( $name ) . '</span><span class="sbp-price">' . wp_kses_post( $p->get_price_html() ) . '</span></a></li>';
	}
	if ( ! $cards ) {
		return '';
	}
	$show = max( 2, min( 8, (int) $atts['show'] ) );
	$auto = max( 0, (float) $atts['autoplay'] );
	return sb_strip_assets() . '<div class="sbp" style="--sbp-show:' . $show . '" data-autoplay="' . esc_attr( $auto ) . '">'
		. '<button type="button" class="sbp-nav sbp-prev" aria-label="Previous">&#8249;</button>'
		. '<ul class="sbp-track">' . implode( '', $cards ) . '</ul>'
		. '<button type="button" class="sbp-nav sbp-next" aria-label="Next">&#8250;</button></div>';
} );

add_shortcode( 'sb_posts', function ( $atts = array() ) {
	$atts = shortcode_atts( array( 'limit' => 4, 'cat' => '' ), $atts, 'sb_posts' );
	$args = array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => max( 1, min( 20, (int) $atts['limit'] ) ), 'no_found_rows' => true, 'ignore_sticky_posts' => true );
	if ( '' !== trim( (string) $atts['cat'] ) ) {
		$args['category_name'] = sanitize_title( $atts['cat'] );
	}
	$posts = get_posts( $args );
	if ( ! $posts ) {
		return '';
	}
	$cards = array();
	foreach ( $posts as $post ) {
		$img     = get_the_post_thumbnail( $post, 'medium', array( 'loading' => 'lazy', 'class' => 'sbr-img' ) );
		$title   = get_the_title( $post );
		$cards[] = '<li class="sbr-card"><a href="' . esc_url( get_permalink( $post ) ) . '">' . '<span class="sbr-pic">' . $img . '</span>' . '<span class="sbr-title">' . esc_html( wp_strip_all_tags( $title ) ) . '</span></a></li>';
	}
	return sb_strip_assets() . '<ul class="sbr-grid" style="--sbr-cols:' . min( 4, count( $cards ) ) . '">' . implode( '', $cards ) . '</ul>';
} );

/** CSS once per page (inline) and a tiny script in the footer. No jQuery, no carousel library. */
function sb_strip_assets() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	add_action( 'wp_footer', 'sb_strip_js', 50 );
	return '<style>' . sb_strip_css() . '</style>';
}

function sb_strip_css() {
	return '.sbp{position:relative;margin:1rem auto 2rem;max-width:1180px;padding:0 34px}'
		. '.sbp-track{--gap:14px;display:grid;grid-auto-flow:column;grid-auto-columns:calc((100% - (var(--sbp-show) - 1) * var(--gap)) / var(--sbp-show));gap:var(--gap);overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;list-style:none;margin:0;padding:4px 2px 12px;align-items:stretch;scrollbar-width:none;-webkit-overflow-scrolling:touch}'
		. '.sbp-track::-webkit-scrollbar{display:none}'
		. '.sbp-card{scroll-snap-align:start;margin:0!important;min-width:0;display:flex}'
		. '.sbp-card a{display:flex;flex-direction:column;flex:1 1 0;min-width:0;width:100%;box-sizing:border-box;background:#fff;border:1px solid #eee;border-radius:6px;padding:10px;text-align:center;text-decoration:none!important;color:#222;box-shadow:0 1px 4px rgba(0,0,0,.06);transition:box-shadow .15s,transform .15s}'
		. '.sbp-card a:hover{box-shadow:0 6px 14px rgba(0,0,0,.12);transform:translateY(-2px)}'
		. '.sbp-pic{position:relative;display:block;width:100%;aspect-ratio:1/1;overflow:hidden;margin-bottom:8px}'
		. '.sbp-pic img{width:100%!important;max-width:100%;height:100%!important;object-fit:contain;display:block;margin:0}'
		. '.sbp-badge{position:absolute;top:12px;left:-30px;z-index:2;width:110px;transform:rotate(-45deg);background:#f3d23a;color:#222;font-size:11px;font-weight:800;text-transform:uppercase;text-align:center;padding:3px 0;box-shadow:0 1px 3px rgba(0,0,0,.2)}'
		. '.sbp-name{display:block;font-weight:700;font-size:14px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
		. '.sbp-price{display:block;margin-top:4px;font-size:13px;color:#3bb54a;font-weight:600}.sbp-price .subscription-details{color:#3bb54a}'
		. '.sbp-nav{position:absolute;top:40%;z-index:3;width:30px;height:42px;border:0;border-radius:4px;background:#ccc;color:#fff;font-size:28px;line-height:1;cursor:pointer;padding:0}'
		. '.sbp-nav:hover{background:#999}.sbp-prev{left:0}.sbp-next{right:0}'
		. '@media (max-width:977px){.sbp{--sbp-show:4!important}}'
		. '@media (max-width:680px){.sbp{--sbp-show:2.3!important;padding:0}.sbp-nav{display:none}}'
		. '.sbr-grid{display:grid;grid-template-columns:repeat(var(--sbr-cols),minmax(0,1fr));gap:20px;list-style:none;margin:1rem auto 2rem;padding:0;max-width:1100px}'
		. '.sbr-card{margin:0!important;min-width:0}.sbr-card a{display:block;text-align:center;text-decoration:none!important;color:#222}'
		. '.sbr-pic{display:block;aspect-ratio:1/1;overflow:hidden;border-radius:6px;margin-bottom:10px;background:#f6f6f6}'
		. '.sbr-pic img{width:100%!important;max-width:100%;height:100%!important;object-fit:cover;display:block;margin:0;transition:transform .3s}.sbr-card a:hover img{transform:scale(1.04)}'
		. '.sbr-title{display:block;font-weight:700;font-size:15px;line-height:1.35}'
		. '@media (max-width:680px){.sbr-grid{grid-auto-flow:column;grid-template-columns:none;grid-auto-columns:46%;overflow-x:auto;scroll-snap-type:x mandatory;gap:12px;padding-bottom:8px}.sbr-card{scroll-snap-align:start}}';
}

function sb_strip_js() {
	?>
<script>
(function(){
document.querySelectorAll('.sbp').forEach(function(w){
	var t=w.querySelector('.sbp-track');if(!t)return;
	var orig=[].slice.call(t.children);if(!orig.length)return;
	var gap=function(){return parseFloat(getComputedStyle(t).columnGap)||14;};
	var step=function(){return orig[0].getBoundingClientRect().width+gap();};
	var loop=t.scrollWidth>t.clientWidth+4; // only loop when there's more than fits
	if(loop){ // one copy of the set after the originals, so the end runs straight into the start
		orig.forEach(function(li){var c=li.cloneNode(true);c.setAttribute('aria-hidden','true');c.querySelectorAll('a').forEach(function(a){a.tabIndex=-1;});t.appendChild(c);});
	}
	var setW=function(){return loop?t.children[orig.length].offsetLeft-orig[0].offsetLeft:0;};
	var jump=function(x){ // move instantly (the copy looks identical, so nobody sees it)
		try{t.scrollTo({left:x,behavior:'instant'});}catch(e){t.scrollLeft=x;}};
	var last=0;var wrap=function(){if(!loop||Date.now()-last<700)return;var W=setW();if(t.scrollLeft>=W-4)jump(t.scrollLeft-W);};
	function go(d){
		last=Date.now();
		if(loop){var W=setW();
			if(d>0&&t.scrollLeft>=W-step()/2)jump(t.scrollLeft-W);
			if(d<0&&t.scrollLeft<step()/2)jump(t.scrollLeft+W);
			t.scrollBy({left:d*step(),behavior:'smooth'});return;}
		var end=t.scrollLeft+t.clientWidth>=t.scrollWidth-4;
		if(d>0&&end)t.scrollTo({left:0,behavior:'smooth'});else if(d<0&&t.scrollLeft<=4)t.scrollTo({left:t.scrollWidth,behavior:'smooth'});else t.scrollBy({left:d*step(),behavior:'smooth'});
	}
	var settle;t.addEventListener('scroll',function(){clearTimeout(settle);settle=setTimeout(wrap,200);},{passive:true}); // after swipes too
	w.querySelector('.sbp-prev').addEventListener('click',function(){go(-1);});
	w.querySelector('.sbp-next').addEventListener('click',function(){go(1);});
	var s=parseFloat(w.dataset.autoplay)||0;if(!s||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
	var paused=false;setInterval(function(){if(!paused&&!document.hidden)go(1);},s*1000);
	['mouseenter','touchstart','focusin'].forEach(function(e){w.addEventListener(e,function(){paused=true;},{passive:true});});
	['mouseleave','focusout','touchend'].forEach(function(e){w.addEventListener(e,function(){if(e==='touchend'){setTimeout(function(){paused=false;},3000);}else{paused=false;}},{passive:true});});
});
})();
</script>
	<?php
}
