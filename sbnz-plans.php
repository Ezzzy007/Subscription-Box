<?php
/**
 * Plugin Name: Subscription Box Toolkit
 * Description: Everything custom for Subscription Boxes NZ and Subscription Box Australia in one plugin: listing plan and marketplace pricing tables, self-serve vendor listings with approval, category tiles, product carousels, latest-posts grid and small site fixes. Updates itself from GitHub. Guide and settings: wp-admin → SB Toolkit.
 * Version: 2.5.2
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'SBT_VERSION', '2.5.2' );
define( 'SBT_FILE', __FILE__ );
define( 'SBT_DIR', plugin_dir_path( __FILE__ ) . 'includes/' );

require SBT_DIR . 'settings.php';
require SBT_DIR . 'admin.php';
require SBT_DIR . 'plans.php';
require SBT_DIR . 'categories.php';
require SBT_DIR . 'carousels.php';
require SBT_DIR . 'updater.php';

// Vendor listings – skipped while the old separate "Subscription Box Listings" plugin is still active (it loads first).
if ( sbt_module_on( 'listings' ) && ! function_exists( 'sbl_plans' ) ) {
	define( 'SBT_LISTINGS_LOADED', true );
	require SBT_DIR . 'listings.php';
}

// Discontinue listings.
if ( sbt_module_on( 'discontinue' ) ) {
	require SBT_DIR . 'discontinue.php';
}

// Site tweaks – skipped while the old sb-site-tweaks.php must-use file is still there.
if ( ! function_exists( 'sbt_drop_labels' ) ) {
	require SBT_DIR . 'tweaks.php';
}

/** Nudge to remove the old copies once this is installed. */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$old = array();
	if ( function_exists( 'sbl_plans' ) && ! defined( 'SBT_LISTINGS_LOADED' ) && sbt_module_on( 'listings' ) ) {
		$old[] = 'deactivate and delete the <strong>Subscription Box Listings</strong> plugin';
	}
	if ( function_exists( 'sbt_drop_labels' ) ) {
		$old[] = 'delete <code>wp-content/mu-plugins/sb-site-tweaks.php</code>';
	}
	if ( $old ) {
		echo '<div class="notice notice-warning"><p><strong>Subscription Box Toolkit</strong> now includes these – please ' . wp_kses_post( implode( ' and ', $old ) ) . '.</p></div>';
	}
} );
