<?php
/** Pricing tables: [sbnz_plans], [sbnz_swap_plans], [sbnz_marketplace_plans] and the ?sbnz_plan= checkout handler. */
defined( 'ABSPATH' ) || exit;

/** Listing plans for this site (edit on SB Toolkit → Plans). */
function sbnz_plans_config( $set = 'listing' ) {
	if ( 'marketplace' === $set ) {
		return sbnz_marketplace_config();
	}
	return sbt_plan_rows( 'plans', sbt_get( 'plans_recommended' ), sbt_get( 'plans_badge' ) );
}

/** Marketplace vendor upgrades (edit on SB Toolkit → Marketplace). */
function sbnz_marketplace_config() {
	return sbt_plan_rows( 'mkt', sbt_get( 'mkt_recommended' ), sbt_get( 'mkt_badge' ) );
}

/** Every product ID any table can put in the cart. */
function sbnz_all_plan_ids() {
	return array_merge( array_keys( sbnz_plans_config( 'listing' ) ), array_keys( sbnz_plans_config( 'marketplace' ) ) );
}

/** Attribute that holds pay-monthly / pay-yearly on the variations. */
const SBNZ_PLANS_ATTR = 'pa_choose-your-subscription';

/**
 * Collect monthly + yearly variation data for one plan.
 */
function sbnz_plans_variations( $product ) {
	$out = array();
	foreach ( $product->get_children() as $child_id ) {
		$v = wc_get_product( $child_id );
		if ( ! $v || 'publish' !== $v->get_status() ) {
			$GLOBALS['sbnz_plans_why'][] = 'variation ' . $child_id . ( $v ? ' status=' . $v->get_status() : ' missing' );
			continue;
		}
		$attrs = $v->get_attributes();
		$value = isset( $attrs[ SBNZ_PLANS_ATTR ] ) ? $attrs[ SBNZ_PLANS_ATTR ] : '';
		$key   = ( false !== strpos( $value, 'year' ) ) ? 'yearly' : ( ( false !== strpos( $value, 'month' ) ) ? 'monthly' : '' );
		if ( ! $key ) {
			$GLOBALS['sbnz_plans_why'][] = 'variation ' . $child_id . ' attr=' . wp_json_encode( $attrs );
			continue;
		}
		$out[ $key ] = array(
			'id'           => $v->get_id(),
			'price'        => (float) WC_Subscriptions_Product::get_price( $v ),
			'trial_length' => (int) WC_Subscriptions_Product::get_trial_length( $v ),
			'trial_period' => (string) WC_Subscriptions_Product::get_trial_period( $v ),
			'url'          => add_query_arg( 'sbnz_plan', $v->get_id(), wc_get_checkout_url() ),
		);
	}
	return $out;
}

/** "$40" for whole amounts, "$4.50" otherwise. */
function sbnz_plans_money( $amount ) {
	$decimals = ( floor( $amount ) == $amount ) ? 0 : 2;
	return wp_strip_all_tags( wc_price( $amount, array( 'decimals' => $decimals ) ) );
}

function sbnz_plans_trial_text( $length, $period ) {
	if ( $length < 1 || ! $period ) {
		return '';
	}
	return sprintf( '%d %s free', $length, $length > 1 ? $period . 's' : $period );
}

/**
 * [sbnz_plans]                                   – full table with prices + sign-up buttons
 * [sbnz_plans show="basic,premium,extreme" mode="features"] – features only (e.g. on the Box Swap page)
 */
add_shortcode( 'sbnz_plans', function ( $atts = array() ) {
	return sbnz_plans_render( 'listing', $atts );
} );

/** [sbnz_swap_plans] – Box Swap page: Basic / Premium / Extreme features only, no prices or buttons. */
add_shortcode( 'sbnz_swap_plans', function () {
	return sbnz_plans_render( 'listing', array( 'show' => 'basic,premium,extreme', 'mode' => 'features' ) );
} );

/** [sbnz_marketplace_plans] – Premium / Extreme Marketplace upgrades for existing vendors (AU). */
add_shortcode( 'sbnz_marketplace_plans', function ( $atts = array() ) {
	return sbnz_plans_render( 'marketplace', $atts );
} );

function sbnz_plans_render( $set, $atts = array() ) {
	$atts     = shortcode_atts( array( 'show' => '', 'mode' => 'full' ), $atts, 'sbnz_plans' );
	$show     = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $atts['show'] ) ) ) );
	$features = 'features' === $atts['mode'];
	if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Subscriptions_Product' ) ) {
		return '<!-- sbnz_plans: WooCommerce or Subscriptions not loaded -->';
	}
	$GLOBALS['sbnz_plans_why'] = array();

	$cards = array();
	$trial = '';
	foreach ( sbnz_plans_config( $set ) as $product_id => $cfg ) {
		if ( $show && ! in_array( $cfg['key'], $show, true ) ) {
			continue;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			$GLOBALS['sbnz_plans_why'][] = 'product ' . $product_id . ( $product ? ' status=' . $product->get_status() : ' not found' );
			continue;
		}
		$vars = sbnz_plans_variations( $product );
		if ( empty( $vars['monthly'] ) && empty( $vars['yearly'] ) ) {
			$GLOBALS['sbnz_plans_why'][] = 'product ' . $product_id . ' has no usable monthly/yearly variations (' . count( $product->get_children() ) . ' children)';
			continue;
		}
		foreach ( $vars as $v ) {
			if ( ! $trial ) {
				$trial = sbnz_plans_trial_text( $v['trial_length'], $v['trial_period'] );
			}
		}
		$cards[] = array( 'name' => preg_replace( '/\s+(Subscription|Marketplace)$/i', '', $product->get_name() ), 'cfg' => $cfg, 'vars' => $vars );
	}
	if ( ! $cards ) {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return '<!-- sbnz_plans: nothing to show. host=' . esc_html( $host ) . ' set=' . esc_html( $set ) . ' ids=' . esc_html( implode( ',', array_keys( sbnz_plans_config( $set ) ) ) ) . ' | ' . esc_html( implode( ' | ', $GLOBALS['sbnz_plans_why'] ) ) . ' -->';
	}

	// Yearly pill: "2 months free" only if every plan saves the same whole number of months.
	$months = array();
	foreach ( $cards as $c ) {
		if ( ! empty( $c['vars']['monthly'] ) && ! empty( $c['vars']['yearly'] ) && $c['vars']['monthly']['price'] > 0 ) {
			$months[] = ( $c['vars']['monthly']['price'] * 12 - $c['vars']['yearly']['price'] ) / $c['vars']['monthly']['price'];
		}
	}
	$months    = array_unique( array_map( function ( $n ) { return round( $n, 2 ); }, $months ) );
	$save_pill = '';
	if ( 1 === count( $months ) ) {
		$n = reset( $months );
		if ( $n >= 1 && floor( $n ) == $n ) {
			$save_pill = sprintf( '%d month%s free', $n, $n > 1 ? 's' : '' );
		}
	} elseif ( $months && min( $months ) > 0 ) {
		$save_pill = 'Save more';
	}

	$uid = 'sbnz-' . wp_rand( 1000, 9999 );
	ob_start();
	?>
	<div class="sbnz-plans<?php echo $features ? ' sbnz-features-only' : ''; ?>" id="<?php echo esc_attr( $uid ); ?>" style="--sbnz-cols:<?php echo (int) count( $cards ); ?>">
		<input type="radio" name="<?php echo esc_attr( $uid ); ?>-period" id="<?php echo esc_attr( $uid ); ?>-m" class="sbnz-r sbnz-r-m" checked>
		<input type="radio" name="<?php echo esc_attr( $uid ); ?>-period" id="<?php echo esc_attr( $uid ); ?>-y" class="sbnz-r sbnz-r-y">

		<?php if ( ! $features ) : ?>
		<div class="sbnz-head">
			<?php if ( $trial ) : ?>
				<p class="sbnz-trial">Every plan starts with <strong><?php echo esc_html( $trial ); ?></strong>. Cancel anytime.</p>
			<?php endif; ?>
			<div class="sbnz-toggle" role="group" aria-label="Billing period">
				<label for="<?php echo esc_attr( $uid ); ?>-m" class="sbnz-t sbnz-t-m">Monthly</label>
				<label for="<?php echo esc_attr( $uid ); ?>-y" class="sbnz-t sbnz-t-y">Yearly<?php if ( $save_pill ) : ?> <span class="sbnz-save-pill"><?php echo esc_html( $save_pill ); ?></span><?php endif; ?></label>
			</div>
		</div>

		<?php endif; ?>
		<div class="sbnz-grid">
			<?php foreach ( $cards as $c ) :
				$featured = ! empty( $c['cfg']['featured'] );
				$m        = isset( $c['vars']['monthly'] ) ? $c['vars']['monthly'] : null;
				$y        = isset( $c['vars']['yearly'] ) ? $c['vars']['yearly'] : null;
				?>
				<div class="sbnz-card<?php echo $featured ? ' is-featured' : ''; ?>">
					<?php if ( $featured && ! empty( $c['cfg']['badge'] ) ) : ?>
						<span class="sbnz-badge"><?php echo esc_html( $c['cfg']['badge'] ); ?></span>
					<?php endif; ?>
					<h3 class="sbnz-name"><?php echo esc_html( $c['name'] ); ?></h3>
					<?php if ( ! empty( $c['cfg']['tagline'] ) ) : ?>
						<p class="sbnz-tag"><?php echo esc_html( $c['cfg']['tagline'] ); ?></p>
					<?php endif; ?>

					<?php if ( $m && ! $features ) : ?>
						<div class="sbnz-price sbnz-only-m">
							<span class="sbnz-amt"><?php echo esc_html( sbnz_plans_money( $m['price'] ) ); ?></span><span class="sbnz-per">/month</span>
							<span class="sbnz-sub">billed monthly</span>
						</div>
					<?php endif; ?>
					<?php if ( $y && ! $features ) :
						$saving = $m ? ( $m['price'] * 12 ) - $y['price'] : 0;
						?>
						<div class="sbnz-price sbnz-only-y">
							<span class="sbnz-amt"><?php echo esc_html( sbnz_plans_money( $y['price'] ) ); ?></span><span class="sbnz-per">/year</span>
							<span class="sbnz-sub">
								<?php if ( $saving > 0 ) : ?>
									Save <?php echo esc_html( sbnz_plans_money( $saving ) ); ?> a year
								<?php else : ?>
									billed yearly
								<?php endif; ?>
							</span>
						</div>
					<?php endif; ?>

					<ul class="sbnz-feat">
						<?php foreach ( $c['cfg']['features'] as $f ) : ?>
							<li><?php echo esc_html( $f ); ?></li>
						<?php endforeach; ?>
					</ul>

					<?php if ( $m && ! $features ) : ?>
						<a class="sbnz-btn sbnz-only-m" href="<?php echo esc_url( $m['url'] ); ?>" rel="nofollow">
							<?php echo $m['trial_length'] ? 'Start free trial' : esc_html( ( 'marketplace' === $set ? 'Upgrade to ' : 'Choose ' ) . $c['name'] ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $y && ! $features ) : ?>
						<a class="sbnz-btn sbnz-only-y" href="<?php echo esc_url( $y['url'] ); ?>" rel="nofollow">
							<?php echo $y['trial_length'] ? 'Start free trial' : esc_html( ( 'marketplace' === $set ? 'Upgrade to ' : 'Choose ' ) . $c['name'] ); ?>
						</a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if ( ! $features ) : ?>
		<?php if ( 'marketplace' === $set ) : ?>
		<?php if ( sbt_get( 'mkt_footer' ) ) : ?><p class="sbnz-foot"><?php echo wp_kses_post( sbt_get( 'mkt_footer' ) ); ?></p><?php endif; ?>
		<?php else : ?>
		<?php if ( sbt_get( 'plans_footer' ) ) : ?><p class="sbnz-foot"><?php echo wp_kses_post( sbt_get( 'plans_footer' ) ); ?></p><?php endif; ?>
		<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Plan button handler: /checkout/?sbnz_plan=<variation id>
 * Replaces any other plan already in the cart, adds this one, then shows checkout.
 */
add_action( 'wp_loaded', function () {
	if ( is_admin() || empty( $_GET['sbnz_plan'] ) || ! function_exists( 'WC' ) ) {
		return;
	}
	$variation = wc_get_product( absint( $_GET['sbnz_plan'] ) );
	if ( ! $variation || ! $variation->is_type( 'subscription_variation' ) ) {
		return;
	}
	$parent_id = $variation->get_parent_id();
	$plan_ids  = sbnz_all_plan_ids();
	if ( ! in_array( $parent_id, $plan_ids, true ) ) {
		return;
	}
	if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
		wc_load_cart();
	}
	if ( ! WC()->cart ) {
		return;
	}
	foreach ( WC()->cart->get_cart() as $key => $item ) {
		if ( in_array( (int) $item['product_id'], $plan_ids, true ) ) {
			WC()->cart->remove_cart_item( $key );
		}
	}
	WC()->cart->add_to_cart( $parent_id, 1, $variation->get_id(), $variation->get_variation_attributes() );
	nocache_headers();
	wp_safe_redirect( wc_get_checkout_url() );
	exit;
}, 25 );

/** Styles, only on pages that use the shortcode. */
add_action( 'wp_enqueue_scripts', function () {
	global $post;
	$content = $post ? (string) $post->post_content : '';
	if ( ! $post || ( ! has_shortcode( $content, 'sbnz_plans' ) && ! has_shortcode( $content, 'sbnz_marketplace_plans' ) && ! has_shortcode( $content, 'sbnz_swap_plans' ) ) ) {
		return;
	}
	wp_register_style( 'sbnz-plans', false, array(), '1.0.0' );
	wp_enqueue_style( 'sbnz-plans' );
	wp_add_inline_style( 'sbnz-plans', sbnz_plans_css() );
} );

function sbnz_plans_css() {
	return <<<'CSS'
.sbnz-plans{--sbnz-accent:#7e4fc4;--sbnz-accent-soft:#f3ecfc;--sbnz-ink:#1f1a2b;--sbnz-muted:#6b6478;--sbnz-line:#e6e1ee;--sbnz-card:#fff;font-size:16px;color:var(--sbnz-ink);margin:2rem 0}
.sbnz-plans *{box-sizing:border-box}
.sbnz-r{position:absolute;opacity:0;pointer-events:none}
.sbnz-head{display:flex;flex-direction:column;align-items:center;gap:.75rem;margin-bottom:1.75rem;text-align:center}
.sbnz-trial{margin:0;color:var(--sbnz-muted)}
.sbnz-trial strong{color:var(--sbnz-ink)}
.sbnz-toggle{display:inline-flex;background:var(--sbnz-accent-soft);border-radius:999px;padding:4px}
.sbnz-t{cursor:pointer;padding:.55rem 1.1rem;border-radius:999px;font-weight:600;color:var(--sbnz-muted);display:flex;align-items:center;gap:.4rem;margin:0;user-select:none;transition:background .15s,color .15s}
.sbnz-save-pill{font-size:.72em;font-weight:700;background:#ffe9a8;color:#6a4b00;border-radius:999px;padding:.12rem .45rem}
.sbnz-r-m:checked~.sbnz-head .sbnz-t-m,.sbnz-r-y:checked~.sbnz-head .sbnz-t-y{background:#fff;color:var(--sbnz-ink);box-shadow:0 1px 3px rgba(30,20,50,.15)}
.sbnz-r-m:focus-visible~.sbnz-head .sbnz-t-m,.sbnz-r-y:focus-visible~.sbnz-head .sbnz-t-y{outline:2px solid var(--sbnz-accent);outline-offset:2px}
.sbnz-r-m:checked~.sbnz-grid .sbnz-only-y,.sbnz-r-y:checked~.sbnz-grid .sbnz-only-m{display:none}
.sbnz-grid{display:grid;grid-template-columns:repeat(var(--sbnz-cols,4),minmax(0,1fr));gap:1rem;align-items:stretch}
.sbnz-features-only .sbnz-only-y{display:none}
.sbnz-features-only .sbnz-feat{margin-bottom:0}
.sbnz-card{position:relative;display:flex;flex-direction:column;background:var(--sbnz-card);border:1px solid var(--sbnz-line);border-radius:14px;padding:1.5rem 1.25rem}
.sbnz-card.is-featured{border:2px solid var(--sbnz-accent);box-shadow:0 8px 24px rgba(126,79,196,.15)}
.sbnz-badge{position:absolute;top:-.75rem;left:50%;transform:translateX(-50%);background:var(--sbnz-accent);color:#fff;font-size:.75rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:.25rem .7rem;border-radius:999px;white-space:nowrap}
.sbnz-name{margin:0;font-size:1.25rem;line-height:1.2}
.sbnz-tag{margin:.25rem 0 1rem;color:var(--sbnz-muted);font-size:.9rem}
.sbnz-price{display:flex;flex-wrap:wrap;align-items:baseline;margin-bottom:1rem}
.sbnz-amt{font-size:2.25rem;font-weight:800;line-height:1;letter-spacing:-.02em}
.sbnz-per{margin-left:.2rem;color:var(--sbnz-muted);font-weight:600}
.sbnz-sub{flex-basis:100%;margin-top:.35rem;font-size:.85rem;color:var(--sbnz-muted)}
.sbnz-only-y .sbnz-sub{color:#2f7d4f;font-weight:600}
.sbnz-feat{list-style:none;margin:0 0 1.25rem;padding:0;flex:1}
.sbnz-feat li{position:relative;padding:.35rem 0 .35rem 1.6rem;font-size:.92rem;line-height:1.35;border-top:1px solid var(--sbnz-line)}
.sbnz-feat li:first-child{border-top:0}
.sbnz-feat li:before{content:"";position:absolute;left:.15rem;top:.62rem;width:.85rem;height:.45rem;border-left:2px solid var(--sbnz-accent);border-bottom:2px solid var(--sbnz-accent);transform:rotate(-45deg)}
.sbnz-btn{display:block;text-align:center;text-decoration:none!important;font-weight:700;padding:.85rem 1rem;border-radius:10px;border:2px solid var(--sbnz-accent);color:var(--sbnz-accent)!important;background:#fff;transition:background .15s,color .15s}
.sbnz-btn:hover,.sbnz-btn:focus-visible{background:var(--sbnz-accent);color:#fff!important}
.is-featured .sbnz-btn{background:var(--sbnz-accent);color:#fff!important}
.is-featured .sbnz-btn:hover{filter:brightness(1.08)}
.sbnz-foot{text-align:center;color:var(--sbnz-muted);font-size:.85rem;margin:1.25rem 0 0}
@media (max-width:1024px){.sbnz-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:1.25rem}}
@media (max-width:600px){.sbnz-grid{grid-template-columns:1fr}.sbnz-card{padding:1.25rem 1rem}.sbnz-amt{font-size:2rem}.sbnz-card.is-featured{margin-top:.5rem}}
CSS;
}

