<?php
/**
 * Updates from GitHub. Each GitHub release (tag v2.1.0 etc. with an sb-toolkit.zip asset) shows up
 * under Plugins / Dashboard → Updates like any other plugin, and installs by itself if auto-updates
 * are switched on for this plugin. Works with a private repo using a read-only token
 * (SB Toolkit → Updates).
 */
defined( 'ABSPATH' ) || exit;

const SBT_ASSET_NAME = 'sb-toolkit.zip';

function sbt_gh_repo() {
	$r = trim( (string) sbt_get( 'gh_repo' ) );
	return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $r ) ? $r : '';
}

function sbt_gh_token() {
	return trim( (string) get_option( 'sbt_gh_token', '' ) );
}

function sbt_gh_headers( $accept = 'application/vnd.github+json' ) {
	$h = array( 'Accept' => $accept, 'User-Agent' => 'sb-toolkit/' . SBT_VERSION, 'X-GitHub-Api-Version' => '2022-11-28' );
	if ( sbt_gh_token() ) {
		$h['Authorization'] = 'Bearer ' . sbt_gh_token();
	}
	return $h;
}

/**
 * Latest release: array( version, asset_api_url, notes, html_url, published ) or WP_Error.
 * Cached 6 hours; $force skips the cache.
 */
function sbt_gh_latest( $force = false ) {
	$repo = sbt_gh_repo();
	if ( ! $repo ) {
		return new WP_Error( 'sbt_no_repo', 'No GitHub repository set.' );
	}
	$cached = get_site_transient( 'sbt_gh_latest' );
	if ( ! $force && is_array( $cached ) && isset( $cached['repo'] ) && $cached['repo'] === $repo ) {
		return $cached;
	}
	if ( ! sbt_gh_token() ) {
		return sbt_gh_latest_public( $repo );
	}
	$res = wp_remote_get( 'https://api.github.com/repos/' . $repo . '/releases/latest', array( 'timeout' => 12, 'headers' => sbt_gh_headers() ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $body ) ) {
		$msg = 404 === $code ? 'Repository or release not found (for a private repo, check the token).' : 'GitHub said ' . $code . ( isset( $body['message'] ) ? ': ' . $body['message'] : '' );
		// Remember the failure briefly so a bad token doesn't hit GitHub on every admin page.
		set_site_transient( 'sbt_gh_latest', array( 'repo' => $repo, 'error' => $msg ), HOUR_IN_SECONDS );
		return new WP_Error( 'sbt_gh', $msg );
	}
	$asset = '';
	foreach ( (array) ( isset( $body['assets'] ) ? $body['assets'] : array() ) as $a ) {
		if ( isset( $a['name'] ) && SBT_ASSET_NAME === $a['name'] ) {
			$asset = $a['url']; // API url – works for private repos with the token
		}
	}
	$out = array(
		'repo'      => $repo,
		'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
		'asset'     => $asset,
		'notes'     => (string) ( isset( $body['body'] ) ? $body['body'] : '' ),
		'html_url'  => (string) ( isset( $body['html_url'] ) ? $body['html_url'] : '' ),
		'published' => (string) ( isset( $body['published_at'] ) ? $body['published_at'] : '' ),
	);
	set_site_transient( 'sbt_gh_latest', $out, 6 * HOUR_IN_SECONDS );
	return $out;
}

/**
 * Public repo, no token: github.com/…/releases/latest redirects to …/tag/vX.Y.Z, and the zip is at
 * …/releases/download/vX.Y.Z/sb-toolkit.zip. Doesn't use the GitHub API, so no rate limits on shared hosting.
 */
function sbt_gh_latest_public( $repo ) {
	$res = wp_remote_head( 'https://github.com/' . $repo . '/releases/latest', array( 'timeout' => 12, 'redirection' => 0, 'headers' => array( 'User-Agent' => 'sb-toolkit/' . SBT_VERSION ) ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$to = (string) wp_remote_retrieve_header( $res, 'location' );
	if ( ! preg_match( '#/releases/tag/v?([0-9][0-9.]*)$#', $to, $m ) ) {
		$msg = 'No release found. If the repository is private, make it public or add a token.';
		set_site_transient( 'sbt_gh_latest', array( 'repo' => $repo, 'error' => $msg ), HOUR_IN_SECONDS );
		return new WP_Error( 'sbt_gh', $msg );
	}
	$tag = basename( wp_parse_url( $to, PHP_URL_PATH ) );
	$out = array(
		'repo'      => $repo,
		'version'   => $m[1],
		'asset'     => 'https://github.com/' . $repo . '/releases/download/' . rawurlencode( $tag ) . '/' . SBT_ASSET_NAME,
		'notes'     => '',
		'html_url'  => $to,
		'published' => '',
	);
	set_site_transient( 'sbt_gh_latest', $out, 6 * HOUR_IN_SECONDS );
	return $out;
}

/** Tell WordPress about a newer release. */
add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
	if ( ! is_object( $transient ) || ! sbt_gh_repo() ) {
		return $transient;
	}
	$file = plugin_basename( SBT_FILE );
	$rel  = sbt_gh_latest();
	if ( is_wp_error( $rel ) || ! empty( $rel['error'] ) || empty( $rel['asset'] ) ) {
		// Still list the plugin so the "Enable auto-updates" link shows on the Plugins page.
		$transient->no_update[ $file ] = (object) array( 'id' => $file, 'slug' => dirname( $file ), 'plugin' => $file, 'new_version' => SBT_VERSION, 'url' => '', 'package' => '' );
		return $transient;
	}
	$item = (object) array(
		'id'          => $file,
		'slug'        => dirname( $file ),
		'plugin'      => $file,
		'new_version' => $rel['version'],
		'url'         => $rel['html_url'],
		'package'     => $rel['asset'],
		'tested'      => get_bloginfo( 'version' ),
		'icons'       => array(),
	);
	if ( version_compare( $rel['version'], SBT_VERSION, '>' ) ) {
		$transient->response[ $file ] = $item;
		unset( $transient->no_update[ $file ] );
	} else {
		$transient->no_update[ $file ] = $item; // lets the "Enable auto-updates" link show
	}
	return $transient;
} );

/** "View details" popup on the Plugins page. */
add_filter( 'plugins_api', function ( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( plugin_basename( SBT_FILE ) ) !== $args->slug ) {
		return $result;
	}
	$rel = sbt_gh_latest();
	$ver = ( ! is_wp_error( $rel ) && ! empty( $rel['version'] ) ) ? $rel['version'] : SBT_VERSION;
	return (object) array(
		'name'          => 'Subscription Box Toolkit',
		'slug'          => $args->slug,
		'version'       => $ver,
		'author'        => 'Subscription Box Australia',
		'last_updated'  => is_wp_error( $rel ) ? '' : $rel['published'],
		'sections'      => array(
			'description' => 'Pricing tables, vendor listings, category tiles, carousels and site fixes for Subscription Boxes NZ and Subscription Box Australia. Guide: wp-admin → SB Toolkit.',
			'changelog'   => is_wp_error( $rel ) ? '' : nl2br( esc_html( $rel['notes'] ) ),
		),
		'download_link' => is_wp_error( $rel ) ? '' : $rel['asset'],
	);
}, 10, 3 );

/**
 * Download our release zip ourselves: GitHub needs the token on the API call, then redirects to a
 * signed storage URL that must be fetched WITHOUT the token.
 */
add_filter( 'upgrader_pre_download', function ( $reply, $package ) {
	$repo = sbt_gh_repo();
	if ( ! $repo || 0 !== strpos( (string) $package, 'https://api.github.com/repos/' . $repo . '/releases/assets/' ) ) {
		return $reply;
	}
	$res = wp_remote_get( $package, array( 'timeout' => 20, 'redirection' => 0, 'headers' => sbt_gh_headers( 'application/octet-stream' ) ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = wp_remote_retrieve_response_code( $res );
	$to   = wp_remote_retrieve_header( $res, 'location' );
	if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) && $to ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		return download_url( $to, 120 );
	}
	if ( 200 === $code ) { // served directly
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( SBT_ASSET_NAME );
		file_put_contents( $tmp, wp_remote_retrieve_body( $res ) ); // phpcs:ignore
		return $tmp;
	}
	return new WP_Error( 'sbt_download', 'Could not download the update from GitHub (HTTP ' . $code . ').' );
}, 10, 2 );

/** Keep the folder name (sbnz-plans) whatever GitHub calls the folder inside the zip. */
add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $extra = array() ) {
	global $wp_filesystem;
	if ( empty( $extra['plugin'] ) || plugin_basename( SBT_FILE ) !== $extra['plugin'] ) {
		return $source;
	}
	$want = trailingslashit( $remote_source ) . dirname( plugin_basename( SBT_FILE ) ) . '/';
	if ( untrailingslashit( $source ) === untrailingslashit( $want ) || ! $wp_filesystem ) {
		return $source;
	}
	return $wp_filesystem->move( $source, $want, true ) ? $want : $source;
}, 10, 4 );

/** Clear the cache after an update so the Updates tab is current. */
add_action( 'upgrader_process_complete', function () {
	delete_site_transient( 'sbt_gh_latest' );
} );

/* --- One-click "Update now": check GitHub and install the latest release ---- */

add_action( 'admin_post_sbt_update_now', function () {
	if ( ! current_user_can( 'update_plugins' ) || ! check_admin_referer( 'sbt_update_now' ) ) {
		wp_die( 'Not allowed.' );
	}
	delete_site_transient( 'sbt_gh_latest' );
	$rel = sbt_gh_latest( true );
	if ( is_wp_error( $rel ) || ! empty( $rel['error'] ) || empty( $rel['asset'] ) ) {
		$why = is_wp_error( $rel ) ? $rel->get_error_message() : ( ! empty( $rel['error'] ) ? $rel['error'] : 'The latest release has no zip yet – try again in a minute.' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'sb-toolkit', 'tab' => 'updates', 'sbt_err' => rawurlencode( $why ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
	if ( ! version_compare( $rel['version'], SBT_VERSION, '>' ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=sb-toolkit&tab=updates&sbt_msg=current' ) );
		exit;
	}
	// Make sure WordPress knows about the release, then run the normal plugin updater.
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$file     = plugin_basename( SBT_FILE );
	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->upgrade( $file );
	if ( true === $result || ( is_array( $result ) && ! empty( $result ) ) ) {
		if ( ! is_plugin_active( $file ) ) {
			activate_plugin( $file );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=sb-toolkit&tab=updates&sbt_msg=updated&sbt_v=' . rawurlencode( $rel['version'] ) ) );
		exit;
	}
	$errors = $skin->get_errors();
	$why    = is_wp_error( $result ) ? $result->get_error_message() : ( $errors->has_errors() ? $errors->get_error_message() : 'WordPress could not install the update (check file permissions).' );
	wp_safe_redirect( add_query_arg( array( 'page' => 'sb-toolkit', 'tab' => 'updates', 'sbt_err' => rawurlencode( $why ) ), admin_url( 'admin.php' ) ) );
	exit;
} );

/** Button HTML, used on the Updates tab and in the "update available" notice. */
function sbt_update_button( $label = 'Update now from GitHub' ) {
	return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
		. '<input type="hidden" name="action" value="sbt_update_now">' . wp_nonce_field( 'sbt_update_now', '_wpnonce', true, false )
		. '<button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></form>';
}

/** Notice on SB Toolkit pages and the dashboard when a newer release exists. */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'toplevel_page_sb-toolkit', 'plugins' ), true ) || ( isset( $_GET['tab'] ) && 'updates' === $_GET['tab'] ) ) { // phpcs:ignore
		return;
	}
	$rel = get_site_transient( 'sbt_gh_latest' );
	if ( is_array( $rel ) && ! empty( $rel['asset'] ) && version_compare( $rel['version'], SBT_VERSION, '>' ) ) {
		echo '<div class="notice notice-info"><p><strong>Subscription Box Toolkit v' . esc_html( $rel['version'] ) . '</strong> is ready (you have v' . esc_html( SBT_VERSION ) . '). ' . sbt_update_button( 'Update now' ) . '</p></div>'; // phpcs:ignore
	}
} );

/* --- SB Toolkit → Updates: save token / check now ------------------------- */

add_action( 'admin_post_sbt_updates', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sbt_updates' ) ) {
		wp_die( 'Not allowed.' );
	}
	$msg = 'checked';
	if ( isset( $_POST['sbt_gh_repo'] ) ) {
		$o            = get_option( 'sbt_settings', array() );
		$o            = is_array( $o ) ? $o : array();
		$o['gh_repo'] = sanitize_text_field( wp_unslash( $_POST['sbt_gh_repo'] ) );
		update_option( 'sbt_settings', $o ); // no _tab, so the settings sanitizer keeps it as-is
		$msg = 'saved';
	}
	if ( ! empty( $_POST['sbt_gh_token'] ) ) {
		update_option( 'sbt_gh_token', sanitize_text_field( wp_unslash( $_POST['sbt_gh_token'] ) ), false );
		$msg = 'saved';
	}
	if ( ! empty( $_POST['sbt_gh_token_clear'] ) ) {
		delete_option( 'sbt_gh_token' );
		$msg = 'saved';
	}
	delete_site_transient( 'sbt_gh_latest' );
	sbt_gh_latest( true );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	wp_safe_redirect( admin_url( 'admin.php?page=sb-toolkit&tab=updates&sbt_msg=' . $msg ) );
	exit;
} );

function sbt_tab_updates( $s ) {
	$rel   = sbt_gh_latest();
	$token = sbt_gh_token();
	$file  = plugin_basename( SBT_FILE );
	$auto  = in_array( $file, (array) get_site_option( 'auto_update_plugins', array() ), true );
	$msgs = array( 'saved' => 'Saved and checked GitHub.', 'checked' => 'Checked GitHub.', 'current' => 'Already on the latest version – nothing to install.' );
	if ( isset( $_GET['sbt_msg'] ) && 'updated' === $_GET['sbt_msg'] ) { // phpcs:ignore
		echo '<div class="notice notice-success inline"><p>Updated to v' . esc_html( sanitize_text_field( wp_unslash( $_GET['sbt_v'] ?? '' ) ) ) . '. Purge LiteSpeed and Cloudflare if the change is on the front end.</p></div>'; // phpcs:ignore
	} elseif ( isset( $_GET['sbt_msg'] ) && isset( $msgs[ $_GET['sbt_msg'] ] ) ) { // phpcs:ignore
		echo '<div class="notice notice-success inline"><p>' . esc_html( $msgs[ $_GET['sbt_msg'] ] ) . '</p></div>'; // phpcs:ignore
	}
	if ( isset( $_GET['sbt_err'] ) ) { // phpcs:ignore
		echo '<div class="notice notice-error inline"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['sbt_err'] ) ) ) . '</p></div>'; // phpcs:ignore
	}
	?>
	<div class="sbt-card">
		<h2>Status</h2>
		<table class="sbt-table">
			<tr><th>Installed</th><td>v<?php echo esc_html( SBT_VERSION ); ?></td></tr>
			<tr><th>Latest on GitHub</th><td>
				<?php
				if ( is_wp_error( $rel ) ) {
					echo '<span class="sbt-bad">' . esc_html( $rel->get_error_message() ) . '</span>';
				} elseif ( ! empty( $rel['error'] ) ) {
					echo '<span class="sbt-bad">' . esc_html( $rel['error'] ) . '</span>';
				} elseif ( empty( $rel['asset'] ) ) {
					echo '<span class="sbt-bad">v' . esc_html( $rel['version'] ) . ' has no ' . esc_html( SBT_ASSET_NAME ) . ' attached yet (the build may still be running).</span>';
				} else {
					$newer = version_compare( $rel['version'], SBT_VERSION, '>' );
					echo '<span class="' . ( $newer ? 'sbt-bad' : 'sbt-ok' ) . '">v' . esc_html( $rel['version'] ) . ( $newer ? ' – update available' : ' ✓ up to date' ) . '</span>';
					if ( $newer ) {
						echo ' · <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Update on the Plugins page</a>';
					}
				}
				?>
			</td></tr>
			<tr><th>Auto-updates</th><td><?php echo $auto ? '<span class="sbt-ok">✓ On – new releases install by themselves (WordPress checks about twice a day)</span>' : '<span class="sbt-muted">Off – turn on with "Enable auto-updates" next to Subscription Box Toolkit on the <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Plugins page</a></span>'; // phpcs:ignore ?></td></tr>
		</table>
		<p style="margin-top:12px"><?php echo sbt_update_button(); // phpcs:ignore ?> <span class="sbt-muted">Checks GitHub right now and installs the newest version if there is one. Safe to click any time.</span></p>
		<?php if ( ! is_wp_error( $rel ) && ! empty( $rel['notes'] ) ) : ?>
			<details><summary>What's in v<?php echo esc_html( $rel['version'] ); ?></summary><div style="white-space:pre-wrap;margin-top:6px"><?php echo esc_html( $rel['notes'] ); ?></div></details>
		<?php endif; ?>
	</div>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sbt-card">
		<input type="hidden" name="action" value="sbt_updates">
		<?php wp_nonce_field( 'sbt_updates' ); ?>
		<h2>GitHub connection</h2>
		<p><label class="inline">Repository</label> <input type="text" class="regular-text" name="sbt_gh_repo" value="<?php echo esc_attr( sbt_get( 'gh_repo' ) ); ?>" placeholder="owner/repo"></p>
		<p><label class="inline">Access token</label>
			<input type="password" class="regular-text" name="sbt_gh_token" value="" autocomplete="new-password" placeholder="<?php echo $token ? esc_attr( 'saved – ends …' . substr( $token, -4 ) ) : 'github_pat_…'; ?>">
			<?php if ( $token ) : ?><label style="margin-left:10px"><input type="checkbox" name="sbt_gh_token_clear" value="1"> remove</label><?php endif; ?></p>
		<p class="sbt-muted">Only needed if the repository is private. With a public repository leave this empty – nothing to renew. For a private one use a fine-grained token limited to this repository with <strong>Contents: Read-only</strong>.</p>
		<p><?php submit_button( 'Save & check for updates', 'primary', 'submit', false ); ?></p>
	</form>
	<?php
}
