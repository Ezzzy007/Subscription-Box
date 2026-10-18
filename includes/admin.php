<?php
/** wp-admin → SB Toolkit: guide, health checks and settings. */
defined( 'ABSPATH' ) || exit;

function sbt_tabs() {
	return array(
		'guide'    => 'Guide',
		'plans'    => 'Listing plans',
		'mkt'      => 'Marketplace upgrades',
		'tiles'    => 'Category tiles',
		'carousel' => 'Carousels',
		'listings' => 'Vendor listings',
		'disc'     => 'Discontinued',
		'fx'       => 'Currency',
		'tweaks'   => 'Site tweaks',
		'updates'  => 'Updates',
	);
}

/** Which saved keys belong to which tab (used for saving and "reset to defaults"). */
function sbt_tab_keys( $tab ) {
	$map = array(
		'plans'    => array( 'plans', 'plans_recommended', 'plans_badge', 'plans_footer' ),
		'mkt'      => array( 'mkt', 'mkt_recommended', 'mkt_badge', 'mkt_footer' ),
		'tiles'    => array( 'cats_items', 'cats_sub', 'cats_look', 'cats_all_url' ),
		'carousel' => array( 'popular_cat', 'exclude_cats' ),
		'listings' => array( 'listing_limits', 'listing_button', 'listing_max_mb', 'modules' ),
		'tweaks'   => array( 'modules' ),
		'disc'     => array( 'disc_upsells', 'disc_message', 'disc_link_text', 'disc_on_plan_end', 'modules' ),
		'fx'       => array( 'fx_overrides', 'modules' ),
	);
	return isset( $map[ $tab ] ) ? $map[ $tab ] : array();
}

add_action( 'admin_menu', function () {
	add_menu_page( 'Subscription Box Toolkit', 'SB Toolkit', 'manage_options', 'sb-toolkit', 'sbt_admin_page', 'dashicons-archive', 1 );
} );

add_filter( 'plugin_action_links_' . plugin_basename( SBT_FILE ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=sb-toolkit' ) ) . '">Settings &amp; guide</a>' );
	return $links;
} );

add_action( 'admin_init', function () {
	register_setting( 'sbt', 'sbt_settings', array( 'type' => 'array', 'sanitize_callback' => 'sbt_sanitize', 'default' => array() ) );
} );

/** Only the submitted tab's keys change; other tabs keep what was saved. */
function sbt_sanitize( $input ) {
	$saved = get_option( 'sbt_settings', array() );
	$saved = is_array( $saved ) ? $saved : array();
	$input = is_array( $input ) ? $input : array();
	if ( ! isset( $input['_tab'] ) ) {
		return $input; // already clean (WordPress runs this twice when the option is first created)
	}
	$tab   = sanitize_key( $input['_tab'] );
	$keys  = sbt_tab_keys( $tab );
	if ( ! $keys ) {
		return $saved;
	}
	if ( ! empty( $input['_reset'] ) ) {
		foreach ( $keys as $k ) {
			if ( 'modules' === $k ) {
				foreach ( sbt_tab_modules( $tab ) as $m ) {
					unset( $saved['modules'][ $m ] );
				}
				continue;
			}
			unset( $saved[ $k ] );
		}
		return $saved;
	}
	$text = function ( $v ) { return sanitize_text_field( wp_unslash( (string) $v ) ); };
	$area = function ( $v ) { return sanitize_textarea_field( wp_unslash( (string) $v ) ); };
	foreach ( $keys as $k ) {
		if ( ! array_key_exists( $k, $input ) ) {
			continue;
		}
		$v = $input[ $k ];
		if ( 'modules' === $k ) {
			$mods = isset( $saved['modules'] ) && is_array( $saved['modules'] ) ? $saved['modules'] : array();
			foreach ( sbt_tab_modules( $tab ) as $m ) {
				$mods[ $m ] = empty( $v[ $m ] ) ? 0 : 1;
			}
			$saved['modules'] = $mods;
		} elseif ( 'listing_limits' === $k ) {
			$clean = array();
			foreach ( (array) $v as $plan => $row ) {
				$clean[ sanitize_key( $plan ) ] = array( 'images' => max( 1, absint( $row['images'] ) ), 'cats' => absint( $row['cats'] ), 'order' => intval( $row['order'] ) );
			}
			$saved[ $k ] = $clean;
		} elseif ( 'disc_upsells' === $k ) {
			$saved[ $k ] = max( 1, min( 8, absint( $v ) ) );
		} elseif ( 'disc_on_plan_end' === $k ) {
			$saved[ $k ] = 'discontinue' === $v ? 'discontinue' : 'draft';
		} elseif ( 'listing_max_mb' === $k ) {
			$saved[ $k ] = max( 1, min( 50, absint( $v ) ) );
		} elseif ( in_array( $k, array( 'plans', 'mkt' ), true ) ) {
			$clean = array();
			foreach ( (array) $v as $plan => $row ) {
				$clean[ sanitize_key( $plan ) ] = array(
					'id'       => absint( isset( $row['id'] ) ? $row['id'] : 0 ),
					'tagline'  => $text( isset( $row['tagline'] ) ? $row['tagline'] : '' ),
					'features' => $area( isset( $row['features'] ) ? $row['features'] : '' ),
				);
			}
			$saved[ $k ] = $clean;
		} elseif ( in_array( $k, array( 'plans_footer', 'mkt_footer', 'cats_items', 'exclude_cats', 'fx_overrides' ), true ) ) {
			$saved[ $k ] = $area( $v );
		} elseif ( 'cats_look' === $k ) {
			$saved[ $k ] = in_array( $v, array( 'nz', 'au' ), true ) ? $v : 'nz';
		} else {
			$saved[ $k ] = $text( $v );
		}
	}
	return $saved;
}

/** Module switches shown on each tab. */
function sbt_tab_modules( $tab ) {
	$map = array( 'listings' => array( 'listings' ), 'tweaks' => array( 'price_guard', 'products_tidy', 'fields' ), 'disc' => array( 'discontinue' ), 'fx' => array( 'fx' ) );
	return isset( $map[ $tab ] ) ? $map[ $tab ] : array();
}

function sbt_module_box( $key, $title, $desc, $s ) {
	$on = ! empty( $s['modules'][ $key ] );
	echo '<p><input type="hidden" name="sbt_settings[modules][' . esc_attr( $key ) . ']" value="0"><label><input type="checkbox" name="sbt_settings[modules][' . esc_attr( $key ) . ']" value="1" ' . checked( $on, true, false ) . '> <strong>' . esc_html( $title ) . '</strong></label><br><span class="sbt-muted">' . wp_kses_post( $desc ) . '</span></p>';
}

/** Pages/posts that use a shortcode, for the guide. */
function sbt_where_used( $tag ) {
	global $wpdb;
	$like = '%' . $wpdb->esc_like( '[' . $tag ) . '%';
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_status IN ('publish','private','draft') AND post_type NOT IN ('revision','nav_menu_item') AND post_content LIKE %s ORDER BY post_title LIMIT 12", $like ) ); // phpcs:ignore
	if ( ! $rows ) {
		return '<span class="sbt-muted">Not used on any page yet</span>';
	}
	$out = array();
	foreach ( $rows as $r ) {
		$out[] = '<a href="' . esc_url( get_edit_post_link( $r->ID ) ) . '">' . esc_html( $r->post_title ? $r->post_title : '#' . $r->ID ) . '</a>';
	}
	return implode( ', ', $out );
}

/** "✓ Name (published)" / "✗ not found" for a plan product ID. */
function sbt_product_status( $id ) {
	if ( ! $id ) {
		return '<span class="sbt-muted">Not set – this plan is hidden</span>';
	}
	$p = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
	if ( ! $p ) {
		return '<span class="sbt-bad">✗ No product with ID ' . (int) $id . '</span>';
	}
	$ok   = 'publish' === $p->get_status();
	$vars = count( $p->get_children() );
	return '<span class="' . ( $ok ? 'sbt-ok' : 'sbt-bad' ) . '">' . ( $ok ? '✓ ' : '✗ ' ) . '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( $p->get_name() ) . '</a>'
		. ( $ok ? '' : ' (' . esc_html( $p->get_status() ) . ')' ) . ' · ' . (int) $vars . ' variation' . ( 1 === $vars ? '' : 's' ) . '</span>';
}

/** Category slugs in the tile list that don't exist on this site. */
function sbt_missing_cats() {
	$missing = array();
	foreach ( sbt_lines( sbt_get( 'cats_items' ) ) as $line ) {
		$slug = sanitize_title( trim( strtok( $line, ':' ) ) );
		if ( $slug && 'all' !== $slug && ! get_term_by( 'slug', $slug, 'product_cat' ) ) {
			$missing[] = $slug;
		}
	}
	return $missing;
}

function sbt_admin_page() {
	$tabs = sbt_tabs();
	$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'guide'; // phpcs:ignore
	$s    = sbt_get();
	$site = 'au' === sbt_site() ? 'Subscription Box Australia' : 'Subscription Boxes NZ';
	?>
	<div class="wrap sbt">
		<h1>Subscription Box Toolkit <span class="sbt-site"><?php echo esc_html( $site ); ?> · v<?php echo esc_html( SBT_VERSION ); ?></span></h1>
		<p class="sbt-lead">The custom bits of this site in one place: pricing tables, vendor listings, category tiles, carousels and small fixes. Same plugin on both sites – it picks the right products and links for each. Changes here apply straight away (purge LiteSpeed and Cloudflare to see them on the live pages).</p>
		<?php settings_errors( 'sbt_settings' ); ?>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $k => $label ) : ?>
				<a class="nav-tab<?php echo $k === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=sb-toolkit&tab=' . $k ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		if ( 'guide' === $tab ) {
			sbt_tab_guide( $s );
		} elseif ( 'updates' === $tab ) {
			sbt_tab_updates( $s );
		} else {
			echo '<form method="post" action="options.php" class="sbt-form">';
			settings_fields( 'sbt' );
			echo '<input type="hidden" name="sbt_settings[_tab]" value="' . esc_attr( $tab ) . '">';
			call_user_func( 'sbt_tab_' . $tab, $s );
			echo '<p class="submit">';
			submit_button( 'Save changes', 'primary', 'submit', false );
			echo ' <label class="sbt-reset"><input type="checkbox" name="sbt_settings[_reset]" value="1"> Reset this tab to the original settings</label></p></form>';
			if ( 'fx' === $tab ) {
				sbt_fx_status_box();
			}
			if ( 'disc' === $tab && function_exists( 'sbt_disc_paste_box' ) ) {
				sbt_disc_paste_box();
			}
		}
		?>
	</div>
	<style>
		.sbt .sbt-site{font-size:13px;font-weight:400;color:#646970;margin-left:8px}
		.sbt-lead{max-width:860px;font-size:14px}
		.sbt-muted{color:#8c8f94}.sbt-ok{color:#008a20}.sbt-bad{color:#d63638;font-weight:600}
		.sbt-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin:16px 0;max-width:1000px}
		.sbt-card h2{margin:0 0 6px;font-size:16px}.sbt-card p{margin:.4em 0}
		.sbt code.sbt-sc{display:inline-block;padding:4px 8px;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:4px;cursor:copy;user-select:all}
		.sbt-table{border-collapse:collapse;width:100%;max-width:1000px;margin-top:8px}.sbt-table th,.sbt-table td{text-align:left;padding:6px 8px;border-top:1px solid #f0f0f1;vertical-align:top}
		.sbt-table th{width:200px;font-weight:600}
		.sbt-plan{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;max-width:1100px}
		.sbt-plan .sbt-card{margin:0}.sbt-plan label{display:block;font-weight:600;margin:8px 0 3px}
		.sbt-plan input[type=text],.sbt-plan input[type=number],.sbt-plan textarea{width:100%}
		.sbt-form textarea.large{width:100%;max-width:640px;font-family:Menlo,Consolas,monospace;font-size:13px}
		.sbt-form .sbt-card label.inline{font-weight:600;display:inline-block;min-width:180px}
		.sbt-reset{margin-left:14px;color:#646970}
		.sbt details{margin-top:8px}.sbt details ul{columns:2;max-width:760px}
	</style>
	<script>document.addEventListener('click',function(e){var c=e.target.closest('code.sbt-sc');if(!c||!navigator.clipboard)return;navigator.clipboard.writeText(c.textContent);c.style.background='#d7f5dd';setTimeout(function(){c.style.background='';},600);});</script>
	<?php
}

function sbt_sc( $text ) {
	return '<code class="sbt-sc" title="Click to copy">' . esc_html( $text ) . '</code>';
}

function sbt_tab_guide( $s ) {
	$missing = sbt_missing_cats();
	$popular = get_term_by( 'slug', sbt_get( 'popular_cat' ), 'product_cat' );
	$items   = array(
		array(
			'title' => 'Listing plans table',
			'tag'   => 'sbnz_plans',
			'codes' => array( '[sbnz_plans]' ),
			'what'  => 'Mini / Basic / Premium / Extreme cards with a Monthly–Yearly switch. Prices, free trial and "months free" are read live from the plan products, so changing a price in WooCommerce updates the table. The button empties any other plan from the cart, adds this one and goes straight to checkout. After checkout the vendor gets the "Set up your listing" link (see Vendor listings below).',
			'edit'  => 'Taglines, features, the Recommended plan and which products are used: <a href="' . esc_url( admin_url( 'admin.php?page=sb-toolkit&tab=plans' ) ) . '">Listing plans</a>.',
		),
		array(
			'title' => 'Box Swap features table',
			'tag'   => 'sbnz_swap_plans',
			'codes' => array( '[sbnz_swap_plans]' ),
			'what'  => 'Basic, Premium and Extreme features only – no prices or buttons. For the Box Swap product page, so people can see what each tier gets.',
			'edit'  => 'Uses the same features as the listing plans.',
		),
		array(
			'title' => 'Marketplace upgrades table',
			'tag'   => 'sbnz_marketplace_plans',
			'codes' => array( '[sbnz_marketplace_plans]' ),
			'what'  => 'Premium / Extreme Marketplace upgrades for existing WCFM vendors, with "Upgrade to …" buttons. Once bought, the perks apply to all of that vendor\'s products.',
			'edit'  => '<a href="' . esc_url( admin_url( 'admin.php?page=sb-toolkit&tab=mkt' ) ) . '">Marketplace upgrades</a>.',
		),
		array(
			'title' => 'Shop by category tiles',
			'tag'   => 'sb_categories',
			'codes' => array( '[sb_categories]' ),
			'what'  => 'The "Shop By Category" grid: 5 per row on desktop, 2 on phones, one block for both. Text tiles in the Archivo Black font, so they stay sharp on any screen.',
			'edit'  => 'Which categories, their labels and order, and the colour style: <a href="' . esc_url( admin_url( 'admin.php?page=sb-toolkit&tab=tiles' ) ) . '">Category tiles</a>.' . ( $missing ? ' <span class="sbt-bad">These categories don\'t exist on this site and are skipped: ' . esc_html( implode( ', ', $missing ) ) . '</span>' : '' ),
		),
		array(
			'title' => 'Product carousel',
			'tag'   => 'sb_products',
			'codes' => array( '[sb_products cat="slider" limit="8" orderby="rand" autoplay="2.3"]', '[sb_products featured="yes" limit="8" orderby="rand" autoplay="2.3"]', '[sb_products limit="10" show="7"]' ),
			'what'  => 'First example: the top slider – boxes in the Slider category, random order, scrolls by itself every 2.3 seconds (pauses on hover/touch). Second: Recently Added Boxes – newest first, arrows only. Boxes in the Popular category get the yellow POPULAR! ribbon. Drafts, hidden products and the excluded categories (Cancelled, Plans) never show.<br><strong>Options:</strong> <code>cat</code> category slug(s) · <code>featured="yes"</code> featured boxes only (Extreme listings are featured automatically – use this on NZ, which has no Slider category) · <code>limit</code> how many · <code>orderby</code> date / rand / menu_order / title · <code>show</code> cards visible on desktop · <code>autoplay</code> seconds (0 = off).',
			'edit'  => '<a href="' . esc_url( admin_url( 'admin.php?page=sb-toolkit&tab=carousel' ) ) . '">Carousels</a>.' . ( $popular ? '' : ' <span class="sbt-bad">The Popular category slug isn\'t found – no ribbons will show.</span>' ),
		),
		array(
			'title' => 'Latest posts / box reviews',
			'tag'   => 'sb_posts',
			'codes' => array( '[sb_posts limit="4"]', '[sb_posts limit="4" cat="category-slug"]', '[sb_posts search="this" limit="10"]' ),
			'what'  => 'Latest blog posts with their featured image: a row on desktop, swipeable on phones. Add <code>cat</code> to show one post category only. <code>search="this"</code> on a box listing shows that box\'s own review posts. Old Content Views <code>[pt_view]</code> shortcodes do the same automatically once Content Views is switched off.',
			'edit'  => 'No settings – change the numbers in the shortcode.',
		),
	);
	$items[] = array(
		'title' => 'Vendor listings',
		'tag'   => 'sbl_none',
		'codes' => array(),
		'what'  => 'No shortcode – runs by itself. Vendors set up and edit their listing at My Account → My Listing after buying a plan; you approve by email or in the "Vendor listing" box on the product edit screen.',
		'edit'  => '<a href="' . esc_url( admin_url( 'admin.php?page=sb-toolkit&tab=listings' ) ) . '">Vendor listings</a> · <a href="' . esc_url( admin_url( 'tools.php?page=sbl-order' ) ) . '">Tools → Listing order</a>',
	);
	$items[] = array(
		'title' => 'Discontinue a listing',
		'tag'   => 'sbt_none',
		'codes' => array(),
		'what'  => 'No shortcode – a "Discontinue listing" box on each product edit screen, plus a bulk action on the products list. Keeps the page live, removes the vendor link, hides it from the shop and suggests similar boxes. Restorable.',
		'edit'  => '<a href="' . esc_url( admin_url( 'admin.php?page=sb-toolkit&tab=disc' ) ) . '">Discontinued</a>',
	);
	foreach ( $items as $it ) {
		?>
		<div class="sbt-card">
			<h2><?php echo esc_html( $it['title'] ); ?></h2>
			<?php if ( $it['codes'] ) : ?><p><?php foreach ( $it['codes'] as $c ) { echo sbt_sc( $c ) . ' '; } // phpcs:ignore ?></p><?php endif; ?>
			<p><?php echo wp_kses_post( $it['what'] ); ?></p>
			<table class="sbt-table">
				<tr><th>Change it</th><td><?php echo wp_kses_post( $it['edit'] ); ?></td></tr>
				<?php if ( $it['codes'] ) : ?><tr><th>Used on</th><td><?php echo wp_kses_post( sbt_where_used( $it['tag'] ) ); ?></td></tr><?php endif; ?>
			</table>
		</div>
		<?php
	}
	?>
	<div class="sbt-card">
		<h2>Plan products on this site</h2>
		<table class="sbt-table">
			<?php foreach ( array( 'plans' => 'Listing', 'mkt' => 'Marketplace' ) as $g => $label ) : foreach ( $s[ $g ] as $k => $p ) : ?>
				<tr><th><?php echo esc_html( $label . ' ' . ucfirst( $k ) ); ?></th><td><?php echo wp_kses_post( sbt_product_status( $p['id'] ) ); ?></td></tr>
			<?php endforeach; endforeach; ?>
		</table>
		<p class="sbt-muted">Each plan product needs published monthly and yearly variations (attribute "Choose your subscription": Pay Monthly / Pay Yearly).</p>
	</div>
	<div class="sbt-card">
		<h2>Using a shortcode</h2>
		<p>In the page editor click <strong>+</strong>, add a <strong>Shortcode</strong> block and paste the code (click any code above to copy it). Save, then purge LiteSpeed (top bar) and Cloudflare.</p>
	</div>
	<?php
}

function sbt_plan_fields( $group, $s ) {
	echo '<div class="sbt-plan">';
	foreach ( $s[ $group ] as $k => $p ) {
		$n = 'sbt_settings[' . $group . '][' . $k . ']';
		?>
		<div class="sbt-card">
			<h2><?php echo esc_html( ucfirst( $k ) ); ?></h2>
			<label>Product ID</label>
			<input type="number" min="0" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo esc_attr( $p['id'] ); ?>">
			<div style="margin-top:4px"><?php echo wp_kses_post( sbt_product_status( $p['id'] ) ); ?></div>
			<label>Tagline</label>
			<input type="text" name="<?php echo esc_attr( $n ); ?>[tagline]" value="<?php echo esc_attr( $p['tagline'] ); ?>">
			<label>Features <span class="sbt-muted">(one per line)</span></label>
			<textarea rows="9" name="<?php echo esc_attr( $n ); ?>[features]"><?php echo esc_textarea( $p['features'] ); ?></textarea>
		</div>
		<?php
	}
	echo '</div>';
}

function sbt_highlight_fields( $prefix, $s ) {
	?>
	<div class="sbt-card">
		<p><label class="inline">Highlighted plan</label>
			<select name="sbt_settings[<?php echo esc_attr( $prefix ); ?>_recommended]">
				<option value="">None</option>
				<?php foreach ( array_keys( $s[ 'plans' === $prefix ? 'plans' : 'mkt' ] ) as $k ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s[ $prefix . '_recommended' ], $k ); ?>><?php echo esc_html( ucfirst( $k ) ); ?></option>
				<?php endforeach; ?>
			</select></p>
		<p><label class="inline">Badge text</label> <input type="text" class="regular-text" name="sbt_settings[<?php echo esc_attr( $prefix ); ?>_badge]" value="<?php echo esc_attr( $s[ $prefix . '_badge' ] ); ?>"></p>
		<p><label class="inline">Note under the table</label></p>
		<textarea class="large" rows="2" name="sbt_settings[<?php echo esc_attr( $prefix ); ?>_footer]"><?php echo esc_textarea( $s[ $prefix . '_footer' ] ); ?></textarea>
	</div>
	<?php
}

function sbt_tab_plans( $s ) {
	echo '<p>Used by ' . sbt_sc( '[sbnz_plans]' ) . ' and ' . sbt_sc( '[sbnz_swap_plans]' ) . '. Prices and trials come from the products themselves – edit those in WooCommerce. Clear a Product ID (0) to hide that plan.</p>'; // phpcs:ignore
	sbt_highlight_fields( 'plans', $s );
	sbt_plan_fields( 'plans', $s );
}

function sbt_tab_mkt( $s ) {
	echo '<p>Used by ' . sbt_sc( '[sbnz_marketplace_plans]' ) . '. Leave the Product IDs at 0 on a site without marketplace upgrades.</p>'; // phpcs:ignore
	sbt_highlight_fields( 'mkt', $s );
	sbt_plan_fields( 'mkt', $s );
}

function sbt_tab_tiles( $s ) {
	$missing = sbt_missing_cats();
	$terms   = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0 ) );
	?>
	<p>Used by <?php echo sbt_sc( '[sb_categories]' ); // phpcs:ignore ?>.</p>
	<div class="sbt-card">
		<h2>Tiles</h2>
		<p>One tile per line, in display order: <code>category-slug : LABEL</code>. Use <code>all</code> as the slug for the "All boxes" tile. Labels over 8 letters shrink to fit.</p>
		<textarea class="large" rows="22" name="sbt_settings[cats_items]"><?php echo esc_textarea( $s['cats_items'] ); ?></textarea>
		<?php if ( $missing ) : ?>
			<p class="sbt-bad">Not found on this site (skipped): <?php echo esc_html( implode( ', ', $missing ) ); ?></p>
		<?php endif; ?>
		<?php if ( $terms && ! is_wp_error( $terms ) ) : ?>
			<details><summary>Category slugs on this site</summary><ul>
				<?php foreach ( $terms as $t ) : ?>
					<li><code><?php echo esc_html( $t->slug ); ?></code> – <?php echo esc_html( html_entity_decode( $t->name ) ); ?></li>
				<?php endforeach; ?>
			</ul></details>
		<?php endif; ?>
	</div>
	<div class="sbt-card">
		<p><label class="inline">Second line</label> <input type="text" name="sbt_settings[cats_sub]" value="<?php echo esc_attr( $s['cats_sub'] ); ?>"> <span class="sbt-muted">the outlined word under each label</span></p>
		<p><label class="inline">Colours</label>
			<select name="sbt_settings[cats_look]">
				<option value="nz" <?php selected( $s['cats_look'], 'nz' ); ?>>Pink / blue / peach (NZ)</option>
				<option value="au" <?php selected( $s['cats_look'], 'au' ); ?>>Teal / aqua / gold with teal border (AU)</option>
			</select></p>
		<p><label class="inline">"All" tile goes to</label> <input type="text" class="regular-text" name="sbt_settings[cats_all_url]" value="<?php echo esc_attr( $s['cats_all_url'] ); ?>" placeholder="blank = shop page"> <span class="sbt-muted">e.g. /directory/</span></p>
	</div>
	<?php
}

function sbt_tab_carousel( $s ) {
	?>
	<p>Used by <?php echo sbt_sc( '[sb_products]' ); // phpcs:ignore ?>. How many, which category, order and speed are set in each shortcode – see the <a href="<?php echo esc_url( admin_url( 'admin.php?page=sb-toolkit' ) ); ?>">Guide</a>.</p>
	<div class="sbt-card">
		<p><label class="inline">Popular category slug</label> <input type="text" class="regular-text" name="sbt_settings[popular_cat]" value="<?php echo esc_attr( $s['popular_cat'] ); ?>"> <span class="sbt-muted">boxes in it get the POPULAR! ribbon</span></p>
		<p><label class="inline">Never show categories</label> <input type="text" class="regular-text" name="sbt_settings[exclude_cats]" value="<?php echo esc_attr( $s['exclude_cats'] ); ?>"> <span class="sbt-muted">slugs, comma separated</span></p>
	</div>
	<?php
}

function sbt_tab_listings( $s ) {
	$labels = array( 'mini' => 'Mini', 'basic' => 'Basic', 'premium' => 'Premium', 'extreme' => 'Extreme', 'boxswap' => 'Box Swap' );
	?>
	<div class="sbt-card">
		<?php sbt_module_box( 'listings', 'Vendor listings on', 'After buying a listing plan the vendor sets up their own listing at My Account → My Listing. New listings are saved as drafts and you get an email with Approve / Reject. Edits to a live listing wait for your OK; edits to a draft apply straight away and you get an email listing what changed. When the plan subscription is cancelled the listing stays live until the paid period ends, then goes to draft and into the Cancelled category (it comes back if they resubscribe). Box Swap listings: you pick Basic / Premium / Extreme when approving. Marketplace upgrades apply the perks to all of that WCFM vendor\'s products.', $s ); ?>
		<?php if ( function_exists( 'sbl_plans' ) && ! defined( 'SBT_LISTINGS_LOADED' ) ) : ?>
			<p class="sbt-bad">The old "Subscription Box Listings" plugin is still active, so the toolkit isn't loading its own copy. Deactivate and delete that plugin – nothing is lost, listings and settings carry over.</p>
		<?php endif; ?>
	</div>
	<div class="sbt-card">
		<h2>What each plan gets</h2>
		<table class="sbt-table" style="max-width:640px">
			<tr><th>Plan</th><th>Images</th><th>Categories <span class="sbt-muted">(0 = unlimited)</span></th><th>Menu order</th></tr>
			<?php foreach ( $labels as $k => $label ) : $l = $s['listing_limits'][ $k ]; $n = 'sbt_settings[listing_limits][' . $k . ']'; ?>
				<tr><td><?php echo esc_html( $label ); ?></td>
					<td><input type="number" min="1" style="width:70px" name="<?php echo esc_attr( $n ); ?>[images]" value="<?php echo esc_attr( $l['images'] ); ?>"></td>
					<td><input type="number" min="0" style="width:70px" name="<?php echo esc_attr( $n ); ?>[cats]" value="<?php echo esc_attr( $l['cats'] ); ?>"></td>
					<td><input type="number" style="width:70px" name="<?php echo esc_attr( $n ); ?>[order]" value="<?php echo esc_attr( $l['order'] ); ?>"></td></tr>
			<?php endforeach; ?>
		</table>
		<p class="sbt-muted">Menu order: lower shows first (WCFM vendors get 3, affiliate links 4, everything else 7). Social links, Popular, Slider and the featured badge follow the plan: Premium and Extreme get social links and Popular; Extreme also gets Slider, featured and the badge.
		After changing menu orders run <a href="<?php echo esc_url( admin_url( 'tools.php?page=sbl-order' ) ); ?>">Tools → Listing order</a> to re-apply them to existing products.</p>
	</div>
	<div class="sbt-card">
		<p><label class="inline">Listing button text</label> <input type="text" class="regular-text" name="sbt_settings[listing_button]" value="<?php echo esc_attr( $s['listing_button'] ); ?>"></p>
		<p><label class="inline">Max image size (MB)</label> <input type="number" min="1" max="50" style="width:70px" name="sbt_settings[listing_max_mb]" value="<?php echo esc_attr( $s['listing_max_mb'] ); ?>"></p>
	</div>
	<?php
}

function sbt_tab_tweaks( $s ) {
	?>
	<div class="sbt-card">
		<?php sbt_module_box( 'price_guard', 'Currency per Product price guard', 'Stops Currency per Product Pro crashing the cart and product pages when a price is blank (e.g. a subscription with no sale price). Only does anything if that plugin is active.', $s ); ?>
		<?php sbt_module_box( 'fields', 'Product fields (replaces Advanced Custom Fields)', 'Shows the <strong>Subtitle</strong> on shop cards and the <strong>Before price</strong> and <strong>Shop Price</strong> lines on product pages, instead of the child theme snippets that needed ACF. Once ACF is deactivated, Subtitle and Before price boxes appear under the product name when editing. Same data as before – nothing to move.' . ( function_exists( 'acf_get_field_groups' ) ? sbt_acf_report() : '' ), $s ); ?>
		<?php sbt_module_box( 'products_tidy', 'Tidy Products list', 'Products → All Products: removes the SKU, GTIN, Tags, Badge, Synced to Meta catalog, Yoast SEO and Product brands columns, gives Name more room and stops the WooCommerce "please rate us" footer covering the table. Screen Options still controls the rest.', $s ); ?>
		<?php if ( function_exists( 'sbt_drop_labels' ) ) : ?>
			<p class="sbt-bad">sb-site-tweaks.php is still in wp-content/mu-plugins, so these are running from that file instead. Delete it in FileZilla.</p>
		<?php endif; ?>
	</div>
	<?php
}

/** Lists ACF field groups, so it's clear whether anything besides these three fields still uses ACF. */
function sbt_acf_report() {
	$known  = array( 'subtitle', 'intprice', 'before_price' );
	$extra  = array();
	foreach ( (array) acf_get_field_groups() as $g ) {
		foreach ( (array) acf_get_fields( $g ) as $f ) {
			if ( ! in_array( $f['name'], $known, true ) ) {
				$extra[] = $g['title'] . ' → ' . ( $f['label'] ? $f['label'] : $f['name'] );
			}
		}
	}
	return $extra
		? '<br><span class="sbt-bad">ACF is still used for: ' . esc_html( implode( ', ', $extra ) ) . ' – keep ACF until those are sorted.</span>'
		: '<br><span class="sbt-ok">ACF is only used for Subtitle, Shop Price and Before price, so it can be deactivated and deleted.</span>';
}

function sbt_tab_fx( $s ) {
	?>
	<div class="sbt-card">
		<?php sbt_module_box( 'fx', 'Overseas prices on', 'For boxes sold in another currency. On the product, pick the <strong>Box currency</strong> and type the <strong>Overseas price</strong> (Product data → General) and the normal price fills itself in ' . esc_html( sbt_fx_base() ) . ' using today\'s exchange rate. Rates update once a day and every overseas box is re-priced. The page shows the ' . esc_html( sbt_fx_base() ) . ' price with "' . esc_html( sbt_fx_base() ) . '" after it, plus the Shop Price line. Replaces Currency per Product and External Products Currency – use <strong>Switch over</strong> below rather than ticking this by hand.', $s ); ?>
	</div>
	<div class="sbt-card">
		<p><label><strong>Fixed rates</strong> (optional – one per line, e.g. <code>GBP = 0.52</code> meaning 1 <?php echo esc_html( sbt_fx_base() ); ?> = 0.52 GBP). Leave empty to use the daily rates.</label></p>
		<textarea name="sbt_settings[fx_overrides]" rows="3" style="width:100%;max-width:420px;font-family:monospace"><?php echo esc_textarea( $s['fx_overrides'] ); ?></textarea>
	</div>
	<?php
}

function sbt_fx_status_box() {
	if ( ! get_option( 'sbt_fx' ) ) {
		sbt_fx_fetch();
	}
	$fx    = get_option( 'sbt_fx', array() );
	$rates = sbt_fx_rates();
	$msg   = isset( $_GET['sbt_msg'] ) ? sanitize_key( $_GET['sbt_msg'] ) : '';
	$msgs  = array( 'rates' => 'Rates updated.', 'rates_fail' => 'Couldn\'t reach the rates service: ' . get_option( 'sbt_fx_error' ), 'done' => 'Done – every overseas box has been re-priced.' );
	if ( isset( $msgs[ $msg ] ) ) {
		echo '<div class="notice ' . ( 'rates_fail' === $msg ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $msgs[ $msg ] ) . '</p></div>';
	}
	$rows = sbt_fx_overview();
	$used = array_unique( wp_list_pluck( $rows, 'cur' ) );
	$old  = sbt_fx_old_plugins();
	$on   = sbt_module_on( 'fx' );
	$url  = function ( $do ) { return wp_nonce_url( admin_url( 'admin-post.php?action=sbt_fx&do=' . $do ), 'sbt_fx_' . $do ); };
	?>
	<div class="sbt-card">
		<h2 style="margin-top:0">Exchange rates</h2>
		<p><?php echo ! empty( $fx['updated'] ) ? 'Last updated ' . esc_html( human_time_diff( $fx['updated'] ) ) . ' ago.' : '<span class="sbt-bad">Not downloaded yet.</span>'; ?>
			<a class="button" href="<?php echo esc_url( $url( 'rates' ) ); ?>">Update rates now</a></p>
		<table class="widefat striped" style="max-width:420px"><thead><tr><th>Currency</th><th>1 <?php echo esc_html( sbt_fx_base() ); ?> =</th><th>1 unit = <?php echo esc_html( sbt_fx_base() ); ?></th></tr></thead><tbody>
		<?php foreach ( $used as $c ) : ?>
			<tr><td><?php echo esc_html( $c ); ?></td><td><?php echo isset( $rates[ $c ] ) ? esc_html( round( $rates[ $c ], 4 ) ) : '<span class="sbt-bad">missing</span>'; ?></td><td><?php echo isset( $rates[ $c ] ) ? esc_html( number_format( 1 / $rates[ $c ], 4 ) ) : ''; ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
	</div>
	<div class="sbt-card">
		<h2 style="margin-top:0"><?php echo $on ? 'Overseas boxes' : 'Switch over'; ?></h2>
		<?php if ( ! $on ) : ?>
			<p>This will <?php echo $old ? 'deactivate <strong>' . esc_html( implode( '</strong> and <strong>', $old ) ) . '</strong>, ' : ''; ?>turn this on and re-price the <?php echo count( $rows ); ?> overseas boxes below in <?php echo esc_html( sbt_fx_base() ); ?>. Their Shop Price lines are kept (and tidied).</p>
			<p><a class="button button-primary" href="<?php echo esc_url( $url( 'switch' ) ); ?>" onclick="return confirm('Switch over to the toolkit for overseas prices?')">Switch over now</a></p>
		<?php else : ?>
			<?php if ( $old ) : ?><p class="sbt-bad">Still active: <?php echo esc_html( implode( ', ', $old ) ); ?> – deactivate it, or it may convert prices a second time.</p><?php endif; ?>
			<p><a class="button" href="<?php echo esc_url( $url( 'recalc' ) ); ?>">Re-price all now</a> <span class="sbt-muted">Happens by itself every day after the rates update.</span></p>
		<?php endif; ?>
		<table class="widefat striped"><thead><tr><th>Box</th><th>Shop Price</th><th>Price now</th><th><?php echo $on ? 'Today' : 'After switch'; ?></th></tr></thead><tbody>
		<?php foreach ( $rows as $r ) : ?>
			<tr><td><a href="<?php echo esc_url( get_edit_post_link( $r['id'] ) ); ?>"><?php echo esc_html( $r['name'] ); ?></a><?php echo 'publish' !== $r['status'] ? ' <span class="sbt-muted">(' . esc_html( $r['status'] ) . ')</span>' : ''; ?></td>
			<td><?php echo esc_html( $r['shop'] ); ?></td><td><?php echo esc_html( $r['now'] ); ?></td>
			<td><?php echo null === $r['new'] ? '<span class="sbt-bad">no rate</span>' : esc_html( number_format( $r['new'], 2 ) . ' ' . sbt_fx_base() ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
	</div>
	<?php
}

function sbt_tab_disc( $s ) {
	?>
	<div class="sbt-card">
		<?php sbt_module_box( 'discontinue', 'Discontinue button on', 'Adds a <strong>Discontinue listing</strong> box to every product edit screen and a <strong>Discontinue + upsells</strong> bulk action on Products → All Products. Discontinuing keeps the page live (Google and old links still work) but removes the link to the vendor, hides it from the shop and search, shows the message below and suggests similar boxes – same categories, paying plans first. Everything it changes is remembered, so <strong>Restore listing</strong> puts it back exactly as it was. Replaces discontinue-product.php and the Barn2 Discontinued Products plugin.', $s ); ?>
	</div>
	<div class="sbt-card">
		<p><label class="inline">Similar boxes to suggest</label> <input type="number" min="1" max="8" style="width:70px" name="sbt_settings[disc_upsells]" value="<?php echo esc_attr( $s['disc_upsells'] ); ?>"></p>
		<p><label class="inline">Message on the page</label> <input type="text" class="regular-text" name="sbt_settings[disc_message]" value="<?php echo esc_attr( $s['disc_message'] ); ?>"></p>
		<p><label class="inline">When a listing plan ends</label>
			<select name="sbt_settings[disc_on_plan_end]">
				<option value="draft" <?php selected( $s['disc_on_plan_end'], 'draft' ); ?>>Hide it (draft + Cancelled category)</option>
				<option value="discontinue" <?php selected( $s['disc_on_plan_end'], 'discontinue' ); ?>>Discontinue it (page stays live with suggestions + Cancelled category)</option>
			</select><br><span class="sbt-muted">Either way it still waits until the paid period is over, you get the email, and it comes back by itself if they resubscribe.</span></p>
	</div>
	<?php
}
