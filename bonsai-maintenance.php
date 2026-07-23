<?php
/**
 * Plugin Name: Bonsai Digital Maintenance Mode
 * Description: Displays a customisable maintenance page for non-logged-in users, and can replace the standard WordPress maintenance screen.
 * Version: 1.17
 * Author: Ben Ervine / The Bonsai Digital Collective
 * Author URI: https://thebonsaidigitalcollective.co.uk
 * Text Domain: bonsai-maintenance
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

/*
|--------------------------------------------------------------------------
| Plugin Update Checker (via Composer)
|--------------------------------------------------------------------------
*/
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$cmm_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/gakdesign/bonsai-maintenance',
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

	status_header( 503 );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Retry-After: ' . cmm_get_retry_after() );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped inside render function
	echo cmm_render_maintenance_page();
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
| Maintenance page template
|--------------------------------------------------------------------------
*/

/**
 * Renders and returns the full HTML maintenance page.
 *
 * @return string Complete HTML document.
 */
function cmm_render_maintenance_page() {
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
		: $site_name . ' \u2013 ' . __( 'Scheduled Maintenance', 'bonsai-maintenance' );

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
 * Loads the WP media library JS only on our settings screen.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 */
add_action( 'admin_enqueue_scripts', function ( $hook_suffix ) {
	if ( 'settings_page_cmm-settings' !== $hook_suffix ) {
		return;
	}
	wp_enqueue_media();
	wp_add_inline_script( 'media-editor', cmm_media_picker_js() );
} );

/**
 * Returns the inline JS that powers the "Choose Image" / "Remove" buttons
 * for the logo and background image fields.
 *
 * @return string JS source.
 */
function cmm_media_picker_js() {
	return <<<'JS'
( function ( $ ) {
	$( document ).on( 'click', '.cmm-media-select', function ( e ) {
		e.preventDefault();
		var button    = $( this );
		var fieldName = button.data( 'field' );
		var input     = $( '#' + fieldName );
		var preview   = $( '#' + fieldName + '_preview' );
		var removeBtn = $( '#' + fieldName + '_remove' );

		var frame = wp.media( {
			title: 'Select Image',
			button: { text: 'Use this image' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			input.val( attachment.url );
			preview.attr( 'src', attachment.url ).show();
			removeBtn.show();
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.cmm-media-remove', function ( e ) {
		e.preventDefault();
		var fieldName = $( this ).data( 'field' );
		$( '#' + fieldName ).val( '' );
		$( '#' + fieldName + '_preview' ).hide().attr( 'src', '' );
		$( this ).hide();
	} );
} )( jQuery );
JS;
}

/**
 * Renders a URL text field paired with a media-library picker button,
 * remove button, and thumbnail preview.
 *
 * @param string $field_name Option/field name.
 * @param string $value      Current field value (image URL).
 */
function cmm_render_media_field( $field_name, $value ) {
	printf(
		'<input type="url" name="%1$s" id="%1$s" value="%2$s" class="regular-text" placeholder="%3$s">
		<button type="button" class="button cmm-media-select" data-field="%1$s">%4$s</button>
		<button type="button" class="button cmm-media-remove" data-field="%1$s" id="%1$s_remove" style="%5$s">%6$s</button>
		<br>
		<img id="%1$s_preview" src="%2$s" style="max-width:150px;height:auto;margin-top:8px;%7$s">',
		esc_attr( $field_name ),
		esc_attr( $value ),
		esc_attr__( 'https://example.com/image.jpg', 'bonsai-maintenance' ),
		esc_html__( 'Choose Image', 'bonsai-maintenance' ),
		$value ? '' : 'display:none;',
		esc_html__( 'Remove', 'bonsai-maintenance' ),
		$value ? '' : 'display:none;'
	);
}

/**
 * Renders the settings page wrapper.
 */
function cmm_settings_page() {
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Maintenance Mode Settings', 'bonsai-maintenance' ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'cmm_settings' );
			do_settings_sections( 'cmm-settings' );
			submit_button();
			?>
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
			'<input type="datetime-local" name="cmm_schedule_start" value="%s"><p class="description">%s</p>',
			esc_attr( get_option( 'cmm_schedule_start', '' ) ),
			esc_html__( 'Leave blank to start immediately once enabled.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_schedule' );

	add_settings_field( 'cmm_schedule_end', __( 'End', 'bonsai-maintenance' ), function () {
		printf(
			'<input type="datetime-local" name="cmm_schedule_end" value="%s"><p class="description">%s</p>',
			esc_attr( get_option( 'cmm_schedule_end', '' ) ),
			esc_html__( 'Leave blank to require manual turn-off. Also used to calculate the Retry-After header.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_schedule' );

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
				'<p class="description"><strong>%s</strong> <input type="text" readonly value="%s" class="regular-text" onclick="this.select();"></p>',
				esc_html__( 'Preview link:', 'bonsai-maintenance' ),
				esc_url( $link )
			);
		}
		?>
		<script>
		( function () {
			var btn = document.getElementById( 'cmm_generate_token' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
				var token = '';
				for ( var i = 0; i < 24; i++ ) {
					token += chars.charAt( Math.floor( Math.random() * chars.length ) );
				}
				document.getElementById( 'cmm_preview_token' ).value = token;
			} );
		} )();
		</script>
		<?php
	}, 'cmm-settings', 'cmm_section_access' );

	add_settings_field( 'cmm_ip_allowlist', __( 'IP Allowlist', 'bonsai-maintenance' ), function () {
		printf(
			'<textarea name="cmm_ip_allowlist" class="large-text" rows="3" placeholder="%s">%s</textarea><p class="description">%s</p>',
			esc_attr__( '203.0.113.10, 203.0.113.11', 'bonsai-maintenance' ),
			esc_textarea( get_option( 'cmm_ip_allowlist', '' ) ),
			esc_html__( 'Comma or newline separated IP addresses that always bypass maintenance mode.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_access' );

	/*
	 * Fields — Design
	 * -------------------------------------------------------------------------
	 */
	add_settings_field( 'cmm_logo', __( 'Header Logo', 'bonsai-maintenance' ), function () {
		cmm_render_media_field( 'cmm_logo', get_option( 'cmm_logo', '' ) );
	}, 'cmm-settings', 'cmm_section_design' );

	add_settings_field( 'cmm_background_image', __( 'Background Image', 'bonsai-maintenance' ), function () {
		cmm_render_media_field( 'cmm_background_image', get_option( 'cmm_background_image', '' ) );
		printf( '<p class="description">%s</p>', esc_html__( 'Full-page background image. Overrides the background colour below.', 'bonsai-maintenance' ) );
	}, 'cmm-settings', 'cmm_section_design' );

	add_settings_field( 'cmm_bg_colour', __( 'Background Colour', 'bonsai-maintenance' ), function () {
		$val = sanitize_hex_color( get_option( 'cmm_bg_colour', '#ffffff' ) ) ?: '#ffffff';
		printf(
			'<input type="color" name="cmm_bg_colour" value="%s"><p class="description">%s</p>',
			esc_attr( $val ),
			esc_html__( 'Page background colour. Used when no background image is set.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_design' );

	add_settings_field( 'cmm_font_colour', __( 'Font Colour', 'bonsai-maintenance' ), function () {
		$val = sanitize_hex_color( get_option( 'cmm_font_colour', '#111111' ) ) ?: '#111111';
		printf(
			'<input type="color" name="cmm_font_colour" value="%s"><p class="description">%s</p>',
			esc_attr( $val ),
			esc_html__( 'Main text colour for headings, body copy, and social links.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_design' );

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
			'<input type="text" name="cmm_badge_text" value="%s" class="regular-text">',
			esc_attr( $val )
		);
	}, 'cmm-settings', 'cmm_section_content' );

	add_settings_field( 'cmm_header_text', __( 'Header Text', 'bonsai-maintenance' ), function () {
		printf(
			'<input type="text" name="cmm_header_text" value="%s" class="regular-text">',
			esc_attr( get_option( 'cmm_header_text', '' ) )
		);
	}, 'cmm-settings', 'cmm_section_content' );

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
			'<input type="text" name="cmm_footer_text" value="%s" class="regular-text"><p class="description">%s</p>',
			esc_attr( get_option( 'cmm_footer_text', '' ) ),
			esc_html__( 'Appears after the \u00a9 year. Leave blank to show year only.', 'bonsai-maintenance' )
		);
	}, 'cmm-settings', 'cmm_section_content' );

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
				'<input type="url" name="%s" value="%s" class="regular-text">',
				esc_attr( $key ),
				esc_attr( get_option( $key, '' ) )
			);
		}, 'cmm-settings', 'cmm_section_social' );
	}

	/*
	 * Fields — SEO
	 * -------------------------------------------------------------------------
	 */
	add_settings_field( 'cmm_seo_title', __( 'Page Title (SEO)', 'bonsai-maintenance' ), function () {
		printf(
			'<input type="text" name="cmm_seo_title" value="%s" class="regular-text" placeholder="%s">',
			esc_attr( get_option( 'cmm_seo_title', '' ) ),
			esc_attr( get_bloginfo( 'name' ) . ' \u2013 ' . __( 'Scheduled Maintenance', 'bonsai-maintenance' ) )
		);
	}, 'cmm-settings', 'cmm_section_seo' );

	add_settings_field( 'cmm_seo_description', __( 'Meta Description (SEO)', 'bonsai-maintenance' ), function () {
		printf(
			'<textarea name="cmm_seo_description" class="large-text" rows="3" maxlength="320" placeholder="%s">%s</textarea>',
			esc_attr__( 'Optional short summary of the page', 'bonsai-maintenance' ),
			esc_textarea( get_option( 'cmm_seo_description', '' ) )
		);
	}, 'cmm-settings', 'cmm_section_seo' );

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
 * De-bounced regeneration — runs once at the end of each admin request.
 */
add_action( 'shutdown', 'cmm_generate_static_maintenance_page_once' );

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
