<?php
/**
 * Plugin Name: Bonsai Digital Maintenance Mode
 * Description: Displays a customisable maintenance page for non-logged-in users, and can replace the standard WordPress maintenance screen.
 * Version: 1.20
 * Author: Ben Ervine / The Bonsai Digital Collective
 * Author URI: https://thebonsaidigitalcollective.co.uk
 * Text Domain: bonsai-maintenance
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

// Keep in step with the Version header above.
define( 'CMM_VERSION', '1.20' );
define( 'CMM_URL', plugin_dir_url( __FILE__ ) );

require_once plugin_dir_path( __FILE__ ) . 'includes/admin-ui.php';

/*
|--------------------------------------------------------------------------
| Plugin Update Checker (via Composer)
|--------------------------------------------------------------------------
*/
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$cmm_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/Bonsai-Systems/bonsai-maintenance',
	__FILE__,
	'bonsai-maintenance',
	6
);

$cmm_update_checker->setBranch( 'main' );
$cmm_update_checker->getVcsApi()->enableReleaseAssets();

/*
|--------------------------------------------------------------------------
| Bootstrap (i18n)
|--------------------------------------------------------------------------
*/
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'bonsai-maintenance', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/*
|--------------------------------------------------------------------------
| Front-end gate (maintenance mode)
|--------------------------------------------------------------------------
*/
add_action( 'template_redirect', 'cmm_enable_maintenance_mode', 1 );

/**
 * Intercepts front-end requests and serves the maintenance page.
 * Passes through admin, REST, AJAX, cron, feed, and admin users.
 */
function cmm_enable_maintenance_mode() {
	if ( ! cmm_is_maintenance_active() ) {
		return;
	}
	if ( is_admin() ) {
		return;
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		return;
	}
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return;
	}
	if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( is_preview() ) {
		return;
	}
	if ( is_feed() ) {
		return;
	}
	if ( cmm_check_preview_bypass() ) {
		return;
	}
	if ( cmm_check_ip_allowlist() ) {
		return;
	}
	if ( cmm_has_password_access() ) {
		return;
	}

	// Redirects and exits on a correct password; otherwise returns an error message (or '').
	$password_error = cmm_handle_password_submission();

	status_header( 503 );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Retry-After: ' . cmm_get_retry_after() );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped inside render function
	echo cmm_render_maintenance_page(
		[
			'password_form'  => true,
			'password_error' => $password_error,
		]
	);
	exit;
}

/**
 * Determines whether maintenance mode is currently active, accounting for
 * the manual toggle and the optional start/end schedule.
 *
 * @return bool True if visitors should see the maintenance page.
 */
function cmm_is_maintenance_active() {
	if ( ! get_option( 'cmm_schedule_enabled' ) ) {
		return (bool) get_option( 'cmm_enabled' );
	}

	$now   = time();
	$start = get_option( 'cmm_schedule_start', '' );
	$end   = get_option( 'cmm_schedule_end', '' );

	$start_ts = $start ? strtotime( $start ) : false;
	$end_ts   = $end ? strtotime( $end ) : false;

	if ( $start_ts && $now < $start_ts ) {
		return false;
	}
	if ( $end_ts && $now > $end_ts ) {
		return false;
	}
	if ( ! $start_ts && ! $end_ts ) {
		// Schedule enabled but no dates set — fall back to the manual toggle.
		return (bool) get_option( 'cmm_enabled' );
	}

	return true;
}

/**
 * Calculates a Retry-After value in seconds. Uses the scheduled end time
 * when available, otherwise falls back to a default of one hour.
 *
 * @return int Seconds until the client should retry.
 */
function cmm_get_retry_after() {
	if ( get_option( 'cmm_schedule_enabled' ) ) {
		$end    = get_option( 'cmm_schedule_end', '' );
		$end_ts = $end ? strtotime( $end ) : false;

		if ( $end_ts && $end_ts > time() ) {
			return max( 60, $end_ts - time() );
		}
	}

	return 3600;
}

/**
 * Returns the visitor's IP address, sanitised.
 *
 * @return string Sanitised IP address, or an empty string if unavailable.
 */
function cmm_visitor_ip() {
	if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
		return '';
	}
	$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * Checks the current visitor's IP against the configured allowlist.
 *
 * @return bool True if the visitor's IP is on the allowlist.
 */
function cmm_check_ip_allowlist() {
	$list = get_option( 'cmm_ip_allowlist', '' );
	if ( '' === trim( $list ) ) {
		return false;
	}

	$visitor_ip = cmm_visitor_ip();
	if ( '' === $visitor_ip ) {
		return false;
	}

	$allowed = preg_split( '/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY );

	return in_array( $visitor_ip, $allowed, true );
}

/**
 * Checks for a valid preview bypass token, either via the `cmm_preview`
 * query parameter or a cookie set by a previous valid request. Sets the
 * bypass cookie on successful token match so the client doesn't need to
 * keep the query parameter on every page.
 *
 * @return bool True if the visitor should bypass maintenance mode.
 */
function cmm_check_preview_bypass() {
	$token = get_option( 'cmm_preview_token', '' );
	if ( '' === $token ) {
		return false;
	}

	$cookie_name  = 'cmm_preview_bypass';
	$expected_val = hash( 'sha256', $token );

	if ( isset( $_GET['cmm_preview'] ) ) {
		$supplied = sanitize_text_field( wp_unslash( $_GET['cmm_preview'] ) );
		if ( hash_equals( $token, $supplied ) ) {
			if ( ! headers_sent() ) {
				setcookie( $cookie_name, $expected_val, time() + DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
			}
			return true;
		}
	}

	if ( isset( $_COOKIE[ $cookie_name ] ) ) {
		$cookie_val = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
		if ( hash_equals( $expected_val, $cookie_val ) ) {
			return true;
		}
	}

	return false;
}

/*
|--------------------------------------------------------------------------
| Password access
|--------------------------------------------------------------------------
*/

define( 'CMM_PASSWORD_COOKIE', 'cmm_password_access' );
define( 'CMM_PASSWORD_MAX_ATTEMPTS', 5 );
define( 'CMM_PASSWORD_LOCKOUT', 15 * MINUTE_IN_SECONDS );

/**
 * Builds the HMAC signature for a password access cookie. The stored password
 * hash is part of the signed data, so changing or removing the password
 * invalidates every cookie previously issued.
 *
 * @param int    $expiry Unix timestamp the cookie is valid until.
 * @param string $hash   Stored password hash.
 * @return string Hex HMAC signature.
 */
function cmm_password_cookie_signature( $expiry, $hash ) {
	return hash_hmac( 'sha256', $expiry . '|' . $hash, wp_salt( 'auth' ) );
}

/**
 * Checks whether the visitor holds a valid, unexpired password access cookie.
 *
 * @return bool True if the visitor has already entered the correct password.
 */
function cmm_has_password_access() {
	$hash = get_option( 'cmm_site_password', '' );
	if ( '' === $hash || empty( $_COOKIE[ CMM_PASSWORD_COOKIE ] ) ) {
		return false;
	}

	$raw   = sanitize_text_field( wp_unslash( $_COOKIE[ CMM_PASSWORD_COOKIE ] ) );
	$parts = explode( '|', $raw );
	if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
		return false;
	}

	$expiry = (int) $parts[0];
	if ( $expiry < time() ) {
		return false;
	}

	return hash_equals( cmm_password_cookie_signature( $expiry, $hash ), $parts[1] );
}

/**
 * Issues the password access cookie. A "remember" setting of 0 days gives a
 * browser-session cookie, still capped server-side at 24 hours.
 *
 * @param string $hash Stored password hash.
 */
function cmm_set_password_cookie( $hash ) {
	if ( headers_sent() ) {
		return;
	}

	$days   = (int) get_option( 'cmm_password_days', 7 );
	$expiry = time() + ( $days > 0 ? $days * DAY_IN_SECONDS : DAY_IN_SECONDS );

	setcookie(
		CMM_PASSWORD_COOKIE,
		$expiry . '|' . cmm_password_cookie_signature( $expiry, $hash ),
		[
			'expires'  => $days > 0 ? $expiry : 0,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => (string) COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		]
	);
}

/**
 * Processes a password form submission from the maintenance page. On success
 * it sets the access cookie and redirects back to the requested URL (so a
 * refresh doesn't resubmit the form). Failed attempts are throttled per IP.
 *
 * @return string Error message to show on the form, or '' if nothing was submitted.
 */
function cmm_handle_password_submission() {
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['cmm_access_password'] ) ) {
		return '';
	}

	$hash = get_option( 'cmm_site_password', '' );
	if ( '' === $hash ) {
		return '';
	}

	if ( ! isset( $_POST['cmm_access_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cmm_access_nonce'] ) ), 'cmm_access_password' ) ) {
		return __( 'Your session expired. Please try again.', 'bonsai-maintenance' );
	}

	$lock_key = 'cmm_pw_fail_' . md5( cmm_visitor_ip() );
	$failures = (int) get_transient( $lock_key );
	if ( $failures >= CMM_PASSWORD_MAX_ATTEMPTS ) {
		return __( 'Too many attempts. Please try again in 15 minutes.', 'bonsai-maintenance' );
	}

	// Not sanitised: any character is valid in a password and it is only ever compared against the hash.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$supplied = wp_unslash( $_POST['cmm_access_password'] );

	if ( is_string( $supplied ) && '' !== $supplied && wp_check_password( $supplied, $hash ) ) {
		delete_transient( $lock_key );
		cmm_set_password_cookie( $hash );

		$redirect = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		wp_safe_redirect( $redirect, 303 );
		exit;
	}

	set_transient( $lock_key, $failures + 1, CMM_PASSWORD_LOCKOUT );

	return __( 'Incorrect password. Please try again.', 'bonsai-maintenance' );
}

/**
 * Returns the "Have a password?" disclosure and form shown on the maintenance
 * page. Uses a native <details> element so no JavaScript is needed.
 *
 * @param string $error Error message from a failed attempt, or ''.
 * @return string Escaped HTML.
 */
function cmm_render_password_form( $error ) {
	ob_start();
	?>
	<details class="cmm-access"<?php echo $error ? ' open' : ''; ?>>
		<summary><?php esc_html_e( 'Have a password?', 'bonsai-maintenance' ); ?></summary>
		<form method="post" action="" class="cmm-access__form">
			<label for="cmm_access_password"><?php esc_html_e( 'Site password', 'bonsai-maintenance' ); ?></label>
			<div class="cmm-access__row">
				<input type="password" name="cmm_access_password" id="cmm_access_password" autocomplete="current-password" required<?php echo $error ? ' aria-invalid="true" aria-describedby="cmm_access_error" autofocus' : ''; ?>>
				<button type="submit"><?php esc_html_e( 'Enter site', 'bonsai-maintenance' ); ?></button>
			</div>
			<?php if ( $error ) : ?>
			<p class="cmm-access__error" id="cmm_access_error" role="alert"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<?php wp_nonce_field( 'cmm_access_password', 'cmm_access_nonce', false ); ?>
		</form>
	</details>
	<?php
	return ob_get_clean();
}

/*
|--------------------------------------------------------------------------
| Maintenance page template
|--------------------------------------------------------------------------
*/

/**
 * Renders and returns the full HTML maintenance page.
 *
 * @param array $args {
 *     Optional. Render options.
 *
 *     @type bool   $password_form  Whether to include the password form (if a password is set).
 *                                  False for the static snapshot, which can't process a POST.
 *     @type string $password_error Error message from a failed password attempt.
 * }
 * @return string Complete HTML document.
 */
function cmm_render_maintenance_page( $args = [] ) {
	$args = wp_parse_args(
		$args,
		[
			'password_form'  => false,
			'password_error' => '',
		]
	);

	$password_html = ( $args['password_form'] && '' !== get_option( 'cmm_site_password', '' ) )
		? cmm_render_password_form( $args['password_error'] )
		: '';

	$site_name    = get_bloginfo( 'name' );
	$logo         = get_option( 'cmm_logo', '' );
	$bg_image     = get_option( 'cmm_background_image', '' );
	$bg_colour    = sanitize_hex_color( get_option( 'cmm_bg_colour', '#ffffff' ) ) ?: '#ffffff';
	$font_colour  = sanitize_hex_color( get_option( 'cmm_font_colour', '#111111' ) ) ?: '#111111';
	$badge_text   = get_option( 'cmm_badge_text', '' );
	$header       = get_option( 'cmm_header_text', __( "We'll be back soon!", 'bonsai-maintenance' ) );
	$main_raw     = get_option( 'cmm_main_content', '' );
	$embed_raw    = get_option( 'cmm_embed_content', '' );
	$facebook     = get_option( 'cmm_facebook', '' );
	$instagram    = get_option( 'cmm_instagram', '' );
	$linkedin     = get_option( 'cmm_linkedin', '' );
	$footer_text  = get_option( 'cmm_footer_text', '' );
	$show_content = (int) get_option( 'cmm_show_main_content', 1 );
	$seo_title    = get_option( 'cmm_seo_title', '' );
	$seo_desc     = get_option( 'cmm_seo_description', '' );

	// Sanitise rich content at point of use.
	$main_safe  = wp_kses_post( $main_raw );
	$embed_safe = wp_kses_post( $embed_raw );

	$page_title = '' !== trim( $seo_title )
		? $seo_title
		: $site_name . ' – ' . __( 'Scheduled Maintenance', 'bonsai-maintenance' );

	if ( '' === trim( $badge_text ) ) {
		$badge_text = __( 'Scheduled maintenance', 'bonsai-maintenance' );
	}

	$lang = substr( get_locale(), 0, 2 );
	if ( ! $lang ) {
		$lang = 'en';
	}

	ob_start();
	?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $lang ); ?>">
<head>
	<meta charset="UTF-8">
	<meta name="robots" content="noindex, nofollow">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo esc_html( $page_title ); ?></title>
	<?php if ( '' !== trim( $seo_desc ) ) : ?>
	<meta name="description" content="<?php echo esc_attr( $seo_desc ); ?>">
	<?php endif; ?>
	<style>
		:root {
			--brand: #ee4367;
			--cmm-bg: <?php echo esc_attr( $bg_colour ); ?>;
			--cmm-color: <?php echo esc_attr( $font_colour ); ?>;
		}
		* { box-sizing: border-box; }
		body {
			text-align: center;
			padding: 50px;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Helvetica Neue", Arial, sans-serif;
			font-size: 16px;
			color: var(--cmm-color);
			background: var(--cmm-bg);
			min-height: 100vh;
			margin: 0;
		}
		.cmm-bg {
			position: fixed;
			inset: 0;
			background-size: cover;
			background-position: center;
			background-repeat: no-repeat;
			z-index: -1;
		}
		h1 { font-size: 26px; line-height: 1.2; padding-bottom: 10px; margin: 0 0 10px; }
		article {
			display: block;
			text-align: left;
			max-width: 720px;
			margin: 0 auto;
			background: rgba(255, 255, 255, .85);
			border-radius: 16px;
			padding: 28px;
			box-shadow: 0 6px 24px rgba(0, 0, 0, .06);
			color: var(--cmm-color);
		}
		article p { margin: 0 0 16px; }
		.logo img { max-width: 220px; margin: 0 0 20px; height: auto; }
		.socials { margin-top: 16px; }
		.socials a { text-decoration: none; color: var(--cmm-color); }
		.socials a:hover { text-decoration: underline; }
		footer { margin-top: 40px; font-size: 13px; opacity: .8; }
		.badge {
			display: inline-block;
			padding: 4px 8px;
			background: var(--brand);
			color: #fff;
			border-radius: 999px;
			font-size: 12px;
			margin-bottom: 16px;
		}
		.cmm-empty { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
		.cmm-access { margin-top: 24px; font-size: 14px; }
		.cmm-access summary { cursor: pointer; text-decoration: underline; display: inline-block; }
		.cmm-access summary:focus-visible,
		.cmm-access input:focus-visible,
		.cmm-access button:focus-visible { outline: 2px solid var(--cmm-color); outline-offset: 2px; }
		.cmm-access__form { margin-top: 12px; }
		.cmm-access__form label { display: block; margin-bottom: 6px; font-weight: 600; }
		.cmm-access__row { display: flex; flex-wrap: wrap; gap: 8px; }
		.cmm-access__row input { flex: 1 1 180px; padding: 8px 10px; font-size: 16px; border: 1px solid #767676; border-radius: 6px; }
		.cmm-access__row button { padding: 8px 16px; font-size: 16px; border: 1px solid var(--cmm-color); border-radius: 6px; background: transparent; color: var(--cmm-color); cursor: pointer; }
		.cmm-access__error { margin: 8px 0 0; color: #b00020; }
		.cmm-access-card {
			position: fixed;
			left: 50%;
			bottom: 24px;
			transform: translateX(-50%);
			width: calc(100% - 32px);
			max-width: 400px;
			text-align: left;
			background: rgba(255, 255, 255, .9);
			border-radius: 12px;
			padding: 4px 20px 20px;
			box-shadow: 0 6px 24px rgba(0, 0, 0, .08);
			color: var(--cmm-color);
		}
	</style>
</head>
<body>

	<?php if ( ! empty( $bg_image ) ) : ?>
	<div class="cmm-bg" style="background-image:url('<?php echo esc_url( $bg_image ); ?>');" role="presentation" aria-hidden="true"></div>
	<?php endif; ?>

	<?php if ( $show_content ) : ?>
	<article>

		<?php if ( ! empty( $logo ) ) : ?>
		<div class="logo">
			<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $site_name ); ?>">
		</div>
		<?php endif; ?>

		<span class="badge"><?php echo esc_html( $badge_text ); ?></span>

		<h1><?php echo esc_html( $header ); ?></h1>

		<?php if ( ! empty( $main_safe ) ) : ?>
		<div class="cmm-main">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised via wp_kses_post()
			echo $main_safe;
			?>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $embed_safe ) ) : ?>
		<div class="cmm-embed-content">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised via wp_kses_post()
			echo $embed_safe;
			?>
		</div>
		<?php endif; ?>

		<?php
		$parts = [];
		if ( ! empty( $linkedin ) ) {
			$parts[] = '<a href="' . esc_url( $linkedin ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'LinkedIn', 'bonsai-maintenance' ) . '</a>';
		}
		if ( ! empty( $facebook ) ) {
			$parts[] = '<a href="' . esc_url( $facebook ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Facebook', 'bonsai-maintenance' ) . '</a>';
		}
		if ( ! empty( $instagram ) ) {
			$parts[] = '<a href="' . esc_url( $instagram ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Instagram', 'bonsai-maintenance' ) . '</a>';
		}
		if ( ! empty( $parts ) ) :
			?>
		<div class="socials">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- individual URLs escaped via esc_url() above
			echo implode( ' &nbsp;&bull;&nbsp; ', $parts );
			?>
		</div>
		<?php endif; ?>

		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside cmm_render_password_form()
		echo $password_html;
		?>

		<footer>
			<p>
				&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?>
				<?php if ( ! empty( $footer_text ) ) : ?>
				<?php echo ' ' . esc_html( $footer_text ); ?>
				<?php endif; ?>
			</p>
		</footer>

	</article>
	<?php else : ?>
	<div class="cmm-empty" aria-hidden="true"></div>
		<?php if ( $password_html ) : ?>
	<div class="cmm-access-card">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside cmm_render_password_form()
			echo $password_html;
			?>
	</div>
		<?php endif; ?>
	<?php endif; ?>

</body>
</html>
	<?php
	return ob_get_clean();
}

/*
|--------------------------------------------------------------------------
| Settings screen (Settings → Maintenance Mode)
|--------------------------------------------------------------------------
*/
add_action( 'admin_menu', function () {
	add_options_page(
		__( 'Maintenance Mode', 'bonsai-maintenance' ),
		__( 'Maintenance Mode', 'bonsai-maintenance' ),
		'manage_options',
		'cmm-settings',
		'cmm_settings_page'
	);
} );

/**
 * Loads the Bonsai admin styles, the media library and the settings-screen
 * JS (media pickers, preview-token generator) on our settings screen only.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 */
add_action( 'admin_enqueue_scripts', function ( $hook_suffix ) {
	if ( 'settings_page_cmm-settings' !== $hook_suffix ) {
		return;
	}
	wp_enqueue_media();
	cmm_enqueue_admin_ui();
	wp_enqueue_style( 'cmm-admin', CMM_URL . 'assets/css/admin.css', [ 'cmm-bonsai-admin-ui' ], CMM_VERSION );
	wp_enqueue_script( 'cmm-admin', CMM_URL . 'assets/js/admin.js', [ 'jquery' ], CMM_VERSION, true );
	wp_localize_script(
		'cmm-admin',
		'cmmAdmin',
		[
			'mediaTitle'  => __( 'Select image', 'bonsai-maintenance' ),
			'mediaButton' => __( 'Use this image', 'bonsai-maintenance' ),
		]
	);
} );

/**
 * Renders a URL text field paired with a media-library picker button,
 * remove button, and thumbnail preview.
 *
 * @param string $field_name Option/field name.
 * @param string $value      Current field value (image URL).
 */
function cmm_render_media_field( $field_name, $value ) {
	printf(
		'<div class="bonsai-ui-actions">
			<input type="url" name="%1$s" id="%1$s" value="%2$s" class="regular-text" placeholder="%3$s">
			<button type="button" class="button cmm-media-select" data-field="%1$s">%4$s</button>
			<button type="button" class="button cmm-media-remove" data-field="%1$s" id="%1$s_remove"%5$s>%6$s</button>
		</div>
		<img id="%1$s_preview" class="cmm-media-preview" src="%2$s" alt=""%5$s>',
		esc_attr( $field_name ),
		esc_attr( $value ),
		esc_attr__( 'https://example.com/image.jpg', 'bonsai-maintenance' ),
		esc_html__( 'Choose Image', 'bonsai-maintenance' ),
		$value ? '' : ' hidden',
		esc_html__( 'Remove', 'bonsai-maintenance' )
	);
}

/**
 * Renders the settings page wrapper.
 */
function cmm_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wp_settings_sections;

	$sections = isset( $wp_settings_sections['cmm-settings'] ) ? (array) $wp_settings_sections['cmm-settings'] : [];
	$active   = cmm_is_maintenance_active();
	?>
	<div class="wrap bonsai-ui bonsai-ui--narrow">
		<?php
		cmm_render_admin_header(
			__( 'Maintenance Mode Settings', 'bonsai-maintenance' ),
			__( 'Shows a customisable maintenance page to logged-out visitors, with scheduling, preview links, an IP allowlist and an optional site password.', 'bonsai-maintenance' ),
			[
				[
					'label'    => __( 'View site', 'bonsai-maintenance' ),
					'url'      => home_url( '/' ),
					'external' => true,
				],
			]
		);
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'cmm_settings' ); ?>

			<?php
			// Same output as do_settings_sections(), but one card per section.
			foreach ( $sections as $section ) :
				?>
				<section class="bonsai-ui-card" aria-labelledby="<?php echo esc_attr( $section['id'] ); ?>-title">
					<div class="bonsai-ui-card__head">
						<h2 class="bonsai-ui-card__title" id="<?php echo esc_attr( $section['id'] ); ?>-title"><?php echo esc_html( $section['title'] ); ?></h2>
						<?php if ( 'cmm_section_status' === $section['id'] ) : ?>
							<?php if ( $active ) : ?>
								<span class="bonsai-ui-badge bonsai-ui-badge--warning"><?php esc_html_e( 'Maintenance mode is on', 'bonsai-maintenance' ); ?></span>
							<?php else : ?>
								<span class="bonsai-ui-badge bonsai-ui-badge--success"><?php esc_html_e( 'Site is live', 'bonsai-maintenance' ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</div>
					<table class="form-table" role="presentation">
						<?php do_settings_fields( 'cmm-settings', $section['id'] ); ?>
					</table>
				</section>
			<?php endforeach; ?>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

add_action( 'admin_init', function () {

	/*
	 * Register settings
	 * -------------------------------------------------------------------------
	 */

	// Toggles.
	register_setting( 'cmm_settings', 'cmm_enabled',                   [ 'type' => 'boolean', 'sanitize_callback' => 'cmm_sanitize_checkbox', 'default' => 0 ] );
	register_setting( 'cmm_settings', 'cmm_override_wp_maintenance',    [ 'type' => 'boolean', 'sanitize_callback' => 'cmm_sanitize_checkbox', 'default' => 0 ] );
	register_setting( 'cmm_settings', 'cmm_show_main_content',          [ 'type' => 'boolean', 'sanitize_callback' => 'cmm_sanitize_checkbox', 'default' => 1 ] );

	// Schedule.
	register_setting( 'cmm_settings', 'cmm_schedule_enabled', [ 'type' => 'boolean', 'sanitize_callback' => 'cmm_sanitize_checkbox',  'default' => 0 ] );
	register_setting( 'cmm_settings', 'cmm_schedule_start',   [ 'type' => 'string',  'sanitize_callback' => 'cmm_sanitize_datetime', 'default' => '' ] );
	register_setting( 'cmm_settings', 'cmm_schedule_end',     [ 'type' => 'string',  'sanitize_callback' => 'cmm_sanitize_datetime', 'default' => '' ] );

	// Access.
	register_setting( 'cmm_settings', 'cmm_preview_token', [ 'type' => 'string', 'sanitize_callback' => 'cmm_sanitize_line',     'default' => '' ] );
	register_setting( 'cmm_settings', 'cmm_ip_allowlist',  [ 'type' => 'string', 'sanitize_callback' => 'cmm_sanitize_ip_list',  'default' => '' ] );
	register_setting( 'cmm_settings', 'cmm_site_password', [ 'type' => 'string',  'sanitize_callback' => 'cmm_sanitize_site_password', 'default' => '' ] );
	register_setting( 'cmm_settings', 'cmm_password_days', [ 'type' => 'integer', 'sanitize_callback' => 'cmm_sanitize_password_days', 'default' => 7 ] );

	// Design.
	register_setting( 'cmm_settings', 'cmm_logo',             [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw',        'default' => '' ] );
	register_setting( 'cmm_settings', 'cmm_background_image', [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw',        'default' => '' ] );
	register_setting( 'cmm_settings', 'cmm_bg_colour',        [ 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color', 'default' => '#ffffff' ] );
	register_setting( 'cmm_settings', 'cmm_font_colour',      [ 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color', 'default' => '#111111' ] );

	// Content.
	register_setting( 'cmm_settings', 'cmm_badge_text',   [ 'type' => 'string', 'sanitize_callback' => 'cmm_sanitize_line', 'default' => '' ] );
	register_setting( 'cmm_settings', 'cmm_header_text',  [ 'type' => 'string', 'sanitize_callback' => 'cmm_sanitize_line', 'default' => '' ] );
	register_setting( 'cmm_settings', 'cmm_main_content', [ 'type' => 'string', 'sanitize_callback' => 'wp_kses_post',      'default' => '' ] );
	register_setting( 'cmm_settings', 'cmm_embed_content',[ 'type' => 'string', 'sanitize_callback' => 'wp_kses_post',      'default' => '' ] );
	register_setting( 'cmm_settings', 'cmm_footer_text',  [ 'type' => 'string', 'sanitize_callback' => 'cmm_sanitize_line', 'default' => '' ] );

	// Social.
	register_setting( 'cmm_settings', 'cmm_facebook',  [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ] );
	register_setting( 'cmm_settings', 'cmm_instagram', [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ] );
	register_setting( 'cmm_settings', 'cmm_linkedin',  [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ] );

	// SEO.
	register_setting( 'cmm_settings', 'cmm_seo_title',       [ 'type' => 'string', 'sanitize_callback' => 'cmm_sanitize_line',     'default' => '' ] );
	register_setting( 'cmm_settings', 'cmm_seo_description', [ 'type' => 'string', 'sanitize_callback' => 'cmm_sanitize_meta_desc','default' => '' ] );

	/*
	 * Sections
	 * -------------------------------------------------------------------------
	 */
	add_settings_section( 'cmm_section_status',   __( 'Status', 'bonsai-maintenance' ),         '__return_false', 'cmm-settings' );
	add_settings_section( 'cmm_section_schedule', __( 'Schedule', 'bonsai-maintenance' ),       '__return_false', 'cmm-settings' );
	add_settings_section( 'cmm_section_access',   __( 'Preview & Access', 'bonsai-maintenance' ), '__return_false', 'cmm-settings' );
	add_settings_section( 'cmm_section_design',  __( 'Design', 'bonsai-maintenance' ),       '__return_false', 'cmm-settings' );
	add_settings_section( 'cmm_section_content', __( 'Content', 'bonsai-maintenance' ),      '__return_false', 'cmm-settings' );
	add_settings_section( 'cmm_section_social',  __( 'Social Links', 'bonsai-maintenance' ), '__return_false', 'cmm-settings' );
	add_settings_section( 'cmm_section_seo',     __( 'SEO', 'bonsai-maintenance' ),          '__return_false', 'cmm-settings' );

	/*
	 * Fields — Status
	 * -------------------------------------------------------------------------
	 */
	add_settings_field( 'cmm_enabled', __( 'Enable Maintenance Mode', 'bonsai-maintenance' ), function () {
		printf(
			'<label><input type="checkbox" name="cmm_enabled" value="1" %s> %s</label>',
			checked( 1, (int) get_option( 'cmm_enabled' ), false ),
			esc_html__( 'Enabled', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_status' );

	add_settings_field( 'cmm_override_wp_maintenance', __( 'Override WordPress Maintenance Page', 'bonsai-maintenance' ), function () {
		printf(
			'<label><input type="checkbox" name="cmm_override_wp_maintenance" value="1" %s> %s</label><p class="description">%s</p>',
			checked( 1, (int) get_option( 'cmm_override_wp_maintenance' ), false ),
			esc_html__( 'Override update screen', 'bonsai-maintenance' ),
			esc_html__( 'Copies maintenance.php to wp-content/ so this page also shows during WordPress core updates.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_status' );

	add_settings_field( 'cmm_show_main_content', __( 'Show Main Content', 'bonsai-maintenance' ), function () {
		printf(
			'<label><input type="checkbox" name="cmm_show_main_content" value="1" %s> %s</label><p class="description">%s</p>',
			checked( 1, (int) get_option( 'cmm_show_main_content', 1 ), false ),
			esc_html__( 'Display logo, badge, header, content, embeds, socials, and footer', 'bonsai-maintenance' ),
			esc_html__( 'Uncheck to show background only (background image or colour).', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_status' );

	/*
	 * Fields — Schedule
	 * -------------------------------------------------------------------------
	 */
	add_settings_field( 'cmm_schedule_enabled', __( 'Enable Scheduled Maintenance', 'bonsai-maintenance' ), function () {
		printf(
			'<label><input type="checkbox" name="cmm_schedule_enabled" value="1" %s> %s</label><p class="description">%s</p>',
			checked( 1, (int) get_option( 'cmm_schedule_enabled' ), false ),
			esc_html__( 'Auto on/off using the dates below', 'bonsai-maintenance' ),
			esc_html__( 'When enabled, the dates below control maintenance mode instead of the manual toggle above.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_schedule' );

	add_settings_field( 'cmm_schedule_start', __( 'Start', 'bonsai-maintenance' ), function () {
		printf(
			'<input type="datetime-local" name="cmm_schedule_start" id="cmm_schedule_start" value="%s"><p class="description">%s</p>',
			esc_attr( get_option( 'cmm_schedule_start', '' ) ),
			esc_html__( 'Leave blank to start immediately once enabled.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_schedule', [ 'label_for' => 'cmm_schedule_start' ] );

	add_settings_field( 'cmm_schedule_end', __( 'End', 'bonsai-maintenance' ), function () {
		printf(
			'<input type="datetime-local" name="cmm_schedule_end" id="cmm_schedule_end" value="%s"><p class="description">%s</p>',
			esc_attr( get_option( 'cmm_schedule_end', '' ) ),
			esc_html__( 'Leave blank to require manual turn-off. Also used to calculate the Retry-After header.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_schedule', [ 'label_for' => 'cmm_schedule_end' ] );

	/*
	 * Fields — Access
	 * -------------------------------------------------------------------------
	 */
	add_settings_field( 'cmm_preview_token', __( 'Preview Token', 'bonsai-maintenance' ), function () {
		$token = get_option( 'cmm_preview_token', '' );
		$link  = $token ? add_query_arg( 'cmm_preview', rawurlencode( $token ), home_url( '/' ) ) : '';
		printf(
			'<input type="text" name="cmm_preview_token" id="cmm_preview_token" value="%s" class="regular-text" placeholder="%s">
			<button type="button" class="button" id="cmm_generate_token">%s</button>
			<p class="description">%s</p>',
			esc_attr( $token ),
			esc_attr__( 'e.g. a random string', 'bonsai-maintenance' ),
			esc_html__( 'Generate', 'bonsai-maintenance' ),
			esc_html__( 'Set a token, then save changes, to get a shareable preview link that bypasses maintenance mode for anyone who has it.', 'bonsai-maintenance' )
		);
		if ( $token ) {
			printf(
				'<p class="description"><label for="cmm_preview_link"><strong>%s</strong></label> <input type="text" id="cmm_preview_link" readonly value="%s" class="regular-text cmm-select-on-focus"></p>',
				esc_html__( 'Preview link:', 'bonsai-maintenance' ),
				esc_url( $link )
			);
		}
	}, 'cmm-settings', 'cmm_section_access', [ 'label_for' => 'cmm_preview_token' ] );

	add_settings_field( 'cmm_ip_allowlist', __( 'IP Allowlist', 'bonsai-maintenance' ), function () {
		printf(
			'<textarea name="cmm_ip_allowlist" id="cmm_ip_allowlist" class="large-text" rows="3" placeholder="%s">%s</textarea><p class="description">%s</p>',
			esc_attr__( '203.0.113.10, 203.0.113.11', 'bonsai-maintenance' ),
			esc_textarea( get_option( 'cmm_ip_allowlist', '' ) ),
			esc_html__( 'Comma or newline separated IP addresses that always bypass maintenance mode.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_access', [ 'label_for' => 'cmm_ip_allowlist' ] );

	add_settings_field( 'cmm_site_password', __( 'Site Password', 'bonsai-maintenance' ), function () {
		$is_set = '' !== get_option( 'cmm_site_password', '' );
		printf(
			'<input type="password" name="cmm_site_password" id="cmm_site_password" value="" class="regular-text" autocomplete="new-password" placeholder="%s"><p class="description">%s</p>',
			esc_attr( $is_set ? __( 'Leave blank to keep the current password', 'bonsai-maintenance' ) : '' ),
			esc_html__( 'Visitors can click "Have a password?" on the maintenance page and enter this to access the site. Stored hashed, so it can\'t be shown again after saving. Changing it signs out everyone who used the old one.', 'bonsai-maintenance' )
		);
		if ( $is_set ) {
			printf(
				'<p><strong>%s</strong></p><label><input type="checkbox" name="cmm_site_password_clear" value="1"> %s</label>',
				esc_html__( 'A password is currently set.', 'bonsai-maintenance' ),
				esc_html__( 'Remove password', 'bonsai-maintenance' )
			);
		}
	}, 'cmm-settings', 'cmm_section_access', [ 'label_for' => 'cmm_site_password' ] );

	add_settings_field( 'cmm_password_days', __( 'Remember Password For', 'bonsai-maintenance' ), function () {
		printf(
			'<input type="number" name="cmm_password_days" id="cmm_password_days" value="%s" min="0" max="365" step="1" class="small-text"> %s<p class="description">%s</p>',
			esc_attr( (int) get_option( 'cmm_password_days', 7 ) ),
			esc_html__( 'days', 'bonsai-maintenance' ),
			esc_html__( 'How long access lasts after entering the password. Use 0 to end access when the browser closes (maximum 24 hours).', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_access', [ 'label_for' => 'cmm_password_days' ] );

	/*
	 * Fields — Design
	 * -------------------------------------------------------------------------
	 */
	add_settings_field( 'cmm_logo', __( 'Header Logo', 'bonsai-maintenance' ), function () {
		cmm_render_media_field( 'cmm_logo', get_option( 'cmm_logo', '' ) );
	}, 'cmm-settings', 'cmm_section_design', [ 'label_for' => 'cmm_logo' ] );

	add_settings_field( 'cmm_background_image', __( 'Background Image', 'bonsai-maintenance' ), function () {
		cmm_render_media_field( 'cmm_background_image', get_option( 'cmm_background_image', '' ) );
		printf( '<p class="description">%s</p>', esc_html__( 'Full-page background image. Overrides the background colour below.', 'bonsai-maintenance' ) );
	}, 'cmm-settings', 'cmm_section_design', [ 'label_for' => 'cmm_background_image' ] );

	add_settings_field( 'cmm_bg_colour', __( 'Background Colour', 'bonsai-maintenance' ), function () {
		$val = sanitize_hex_color( get_option( 'cmm_bg_colour', '#ffffff' ) ) ?: '#ffffff';
		printf(
			'<input type="color" name="cmm_bg_colour" id="cmm_bg_colour" value="%s"><p class="description">%s</p>',
			esc_attr( $val ),
			esc_html__( 'Page background colour. Used when no background image is set.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_design', [ 'label_for' => 'cmm_bg_colour' ] );

	add_settings_field( 'cmm_font_colour', __( 'Font Colour', 'bonsai-maintenance' ), function () {
		$val = sanitize_hex_color( get_option( 'cmm_font_colour', '#111111' ) ) ?: '#111111';
		printf(
			'<input type="color" name="cmm_font_colour" id="cmm_font_colour" value="%s"><p class="description">%s</p>',
			esc_attr( $val ),
			esc_html__( 'Main text colour for headings, body copy, and social links.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_design', [ 'label_for' => 'cmm_font_colour' ] );

	/*
	 * Fields — Content
	 * -------------------------------------------------------------------------
	 */
	add_settings_field( 'cmm_badge_text', __( 'Badge Text', 'bonsai-maintenance' ), function () {
		$val = get_option( 'cmm_badge_text', '' );
		if ( '' === $val ) {
			$val = __( 'Scheduled maintenance', 'bonsai-maintenance' );
		}
		printf(
			'<input type="text" name="cmm_badge_text" id="cmm_badge_text" value="%s" class="regular-text">',
			esc_attr( $val )
		);
	}, 'cmm-settings', 'cmm_section_content', [ 'label_for' => 'cmm_badge_text' ] );

	add_settings_field( 'cmm_header_text', __( 'Header Text', 'bonsai-maintenance' ), function () {
		printf(
			'<input type="text" name="cmm_header_text" id="cmm_header_text" value="%s" class="regular-text">',
			esc_attr( get_option( 'cmm_header_text', '' ) )
		);
	}, 'cmm-settings', 'cmm_section_content', [ 'label_for' => 'cmm_header_text' ] );

	add_settings_field( 'cmm_main_content', __( 'Main Content', 'bonsai-maintenance' ), function () {
		wp_editor( get_option( 'cmm_main_content', '' ), 'cmm_main_content', [
			'textarea_name' => 'cmm_main_content',
			'textarea_rows' => 10,
			'media_buttons' => false,
			'tinymce'       => [
				'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,link,unlink,blockquote',
				'toolbar2' => '',
			],
		] );
	}, 'cmm-settings', 'cmm_section_content' );

	add_settings_field( 'cmm_embed_content', __( 'Embed Code / HTML', 'bonsai-maintenance' ), function () {
		wp_editor( get_option( 'cmm_embed_content', '' ), 'cmm_embed_content', [
			'textarea_name' => 'cmm_embed_content',
			'textarea_rows' => 5,
			'media_buttons' => false,
		] );
	}, 'cmm-settings', 'cmm_section_content' );

	add_settings_field( 'cmm_footer_text', __( 'Footer Text', 'bonsai-maintenance' ), function () {
		printf(
			'<input type="text" name="cmm_footer_text" id="cmm_footer_text" value="%s" class="regular-text"><p class="description">%s</p>',
			esc_attr( get_option( 'cmm_footer_text', '' ) ),
			esc_html__( 'Appears after the © year. Leave blank to show year only.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_content', [ 'label_for' => 'cmm_footer_text' ] );

	/*
	 * Fields — Social
	 * -------------------------------------------------------------------------
	 */
	$social_fields = [
		'cmm_linkedin'  => __( 'LinkedIn URL', 'bonsai-maintenance' ),
		'cmm_facebook'  => __( 'Facebook URL', 'bonsai-maintenance' ),
		'cmm_instagram' => __( 'Instagram URL', 'bonsai-maintenance' ),
	];

	foreach ( $social_fields as $key => $label ) {
		add_settings_field( $key, $label, function () use ( $key ) {
			printf(
				'<input type="url" name="%1$s" id="%1$s" value="%2$s" class="regular-text">',
				esc_attr( $key ),
				esc_attr( get_option( $key, '' ) )
			);
		}, 'cmm-settings', 'cmm_section_social', [ 'label_for' => $key ] );
	}

	/*
	 * Fields — SEO
	 * -------------------------------------------------------------------------
	 */
	add_settings_field( 'cmm_seo_title', __( 'Page Title (SEO)', 'bonsai-maintenance' ), function () {
		printf(
			'<input type="text" name="cmm_seo_title" id="cmm_seo_title" value="%s" class="regular-text" placeholder="%s">',
			esc_attr( get_option( 'cmm_seo_title', '' ) ),
			esc_attr( get_bloginfo( 'name' ) . ' – ' . __( 'Scheduled Maintenance', 'bonsai-maintenance' ) )
		);
	}, 'cmm-settings', 'cmm_section_seo', [ 'label_for' => 'cmm_seo_title' ] );

	add_settings_field( 'cmm_seo_description', __( 'Meta Description (SEO)', 'bonsai-maintenance' ), function () {
		printf(
			'<textarea name="cmm_seo_description" id="cmm_seo_description" class="large-text" rows="3" maxlength="320" placeholder="%s">%s</textarea>',
			esc_attr__( 'Optional short summary of the page', 'bonsai-maintenance' ),
			esc_textarea( get_option( 'cmm_seo_description', '' ) )
		);
	}, 'cmm-settings', 'cmm_section_seo', [ 'label_for' => 'cmm_seo_description' ] );

} );

/*
|--------------------------------------------------------------------------
| WP core maintenance override (wp-content/maintenance.php)
|--------------------------------------------------------------------------
*/
register_activation_hook( __FILE__, 'cmm_on_activation' );
register_deactivation_hook( __FILE__, 'cmm_on_deactivation' );

/**
 * Runs on plugin activation.
 */
function cmm_on_activation() {
	cmm_generate_static_maintenance_page();
	if ( get_option( 'cmm_override_wp_maintenance' ) ) {
		cmm_copy_maintenance_file();
	}
}

/**
 * Runs on plugin deactivation.
 */
function cmm_on_deactivation() {
	cmm_cleanup_wp_maintenance();
}

/**
 * Writes a static HTML snapshot to wp-content/maintenance-template.html.
 */
function cmm_generate_static_maintenance_page() {
	$file_path = trailingslashit( WP_CONTENT_DIR ) . 'maintenance-template.html';
	$html      = cmm_render_maintenance_page();

	if ( is_writable( WP_CONTENT_DIR ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file_path, $html );
	}
}

/**
 * De-bounced regeneration — only queued when a cmm_* option is actually saved,
 * then runs once at the end of that request. Previously ran on every shutdown
 * (including front-end page loads), firing a 13-call get_option() burst on
 * every single request for no reason.
 */
add_action( 'updated_option', 'cmm_maybe_queue_regeneration' );
add_action( 'added_option', 'cmm_maybe_queue_regeneration' );

function cmm_maybe_queue_regeneration( $option ) {
	if ( 0 !== strpos( $option, 'cmm_' ) ) {
		return;
	}
	add_action( 'shutdown', 'cmm_generate_static_maintenance_page_once' );
}

function cmm_generate_static_maintenance_page_once() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	cmm_generate_static_maintenance_page();
}

/**
 * Copies the bundled custom-maintenance.php to wp-content/maintenance.php.
 */
function cmm_copy_maintenance_file() {
	$src  = plugin_dir_path( __FILE__ ) . 'custom-maintenance.php';
	$dest = trailingslashit( WP_CONTENT_DIR ) . 'maintenance.php';

	if ( ! file_exists( $src ) || ! is_writable( WP_CONTENT_DIR ) ) {
		return;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
	copy( $src, $dest );
}

/**
 * Removes wp-content/maintenance.php if present.
 */
function cmm_cleanup_wp_maintenance() {
	$dest = trailingslashit( WP_CONTENT_DIR ) . 'maintenance.php';

	if ( file_exists( $dest ) && is_writable( WP_CONTENT_DIR ) ) {
		wp_delete_file( $dest );
	}
}

/**
 * Automatically copies or removes maintenance.php when the toggle option changes.
 *
 * @param mixed $old Previous option value.
 * @param mixed $new New option value.
 */
add_action( 'update_option_cmm_override_wp_maintenance', function ( $old, $new ) {
	if ( 1 === (int) $new ) {
		cmm_copy_maintenance_file();
	} else {
		cmm_cleanup_wp_maintenance();
	}
}, 10, 2 );

/*
|--------------------------------------------------------------------------
| Sanitisation helpers
|--------------------------------------------------------------------------
*/

/**
 * Sanitises a checkbox value to 0 or 1.
 *
 * @param mixed $value Raw input.
 * @return int 0 or 1.
 */
function cmm_sanitize_checkbox( $value ) {
	return (int) (bool) $value;
}

/**
 * Sanitises a single-line text field: strips tags, collapses whitespace, trims.
 *
 * @param mixed $value Raw input.
 * @return string Sanitised string.
 */
function cmm_sanitize_line( $value ) {
	$value = is_string( $value ) ? $value : '';
	$value = wp_strip_all_tags( $value, true );
	$value = preg_replace( '/\s+/', ' ', $value );
	return trim( $value );
}

/**
 * Sanitises a meta description: strips tags, collapses whitespace, trims, caps at 320 chars.
 *
 * @param mixed $value Raw input.
 * @return string Sanitised string.
 */
function cmm_sanitize_meta_desc( $value ) {
	$value = is_string( $value ) ? $value : '';
	$value = wp_strip_all_tags( $value, true );
	$value = preg_replace( '/\s+/', ' ', $value );
	$value = trim( $value );

	if ( strlen( $value ) > 320 ) {
		$value = substr( $value, 0, 320 );
	}

	return $value;
}

/**
 * Sanitises a `datetime-local` input value, discarding anything that
 * doesn't parse to a valid timestamp.
 *
 * @param mixed $value Raw input.
 * @return string Sanitised datetime string, or empty string if invalid.
 */
function cmm_sanitize_datetime( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';
	if ( '' === $value ) {
		return '';
	}
	return strtotime( $value ) ? sanitize_text_field( $value ) : '';
}

/**
 * Sanitises a comma/newline separated list of IP addresses, discarding
 * anything that doesn't validate as a well-formed IP.
 *
 * @param mixed $value Raw input.
 * @return string Comma-separated list of valid IPs.
 */
function cmm_sanitize_ip_list( $value ) {
	$value = is_string( $value ) ? $value : '';
	$parts = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
	$valid = array_filter( $parts, function ( $ip ) {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP );
	} );
	return implode( ', ', $valid );
}

/**
 * Hashes a newly entered site password. A blank submission keeps the existing
 * hash, and the "Remove password" checkbox clears it.
 *
 * The result is cached per request because WordPress runs the sanitise
 * callback twice when an option is first created (update_option → add_option),
 * which would otherwise hash the hash.
 *
 * @param mixed $value Raw input (already unslashed by options.php).
 * @return string Password hash, or an empty string if no password is set.
 */
function cmm_sanitize_site_password( $value ) {
	static $result = null;
	if ( null !== $result ) {
		return $result;
	}

	// Nonce already verified by options.php before sanitise callbacks run.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! empty( $_POST['cmm_site_password_clear'] ) ) {
		$result = '';
	} elseif ( is_string( $value ) && '' !== $value ) {
		// Not sanitised: any character is valid in a password, and only the hash is stored.
		$result = wp_hash_password( $value );
	} else {
		$result = (string) get_option( 'cmm_site_password', '' );
	}

	return $result;
}

/**
 * Sanitises the "remember password" duration to a whole number of days (0–365).
 *
 * @param mixed $value Raw input.
 * @return int Number of days.
 */
function cmm_sanitize_password_days( $value ) {
	return min( 365, absint( $value ) );
}
