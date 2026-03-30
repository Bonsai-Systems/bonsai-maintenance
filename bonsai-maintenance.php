<?php
/**
 * Plugin Name: Bonsai Digital Maintenance Mode
 * Description: Displays a customisable maintenance page for non-logged-in users, and can replace the standard WordPress maintenance screen.
 * Version: 1.15
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
	'bonsai-maintenance'
);

$cmm_update_checker->setBranch( 'main' );
$cmm_update_checker->setUpdateCheckInterval( 6 );
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
	if ( ! get_option( 'cmm_enabled' ) ) {
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

	status_header( 503 );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Retry-After: 3600' );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped inside render function
	echo cmm_render_maintenance_page();
	exit;
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
	add_settings_section( 'cmm_section_status',  __( 'Status', 'bonsai-maintenance' ),       '__return_false', 'cmm-settings' );
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
	 * Fields — Design
	 * -------------------------------------------------------------------------
	 */
	add_settings_field( 'cmm_logo', __( 'Header Logo (URL)', 'bonsai-maintenance' ), function () {
		printf(
			'<input type="url" name="cmm_logo" value="%s" class="regular-text">',
			esc_attr( get_option( 'cmm_logo', '' ) )
		);
	}, 'cmm-settings', 'cmm_section_design' );

	add_settings_field( 'cmm_background_image', __( 'Background Image (URL)', 'bonsai-maintenance' ), function () {
		printf(
			'<input type="url" name="cmm_background_image" value="%s" class="regular-text" placeholder="%s"><p class="description">%s</p>',
			esc_attr( get_option( 'cmm_background_image', '' ) ),
			esc_attr__( 'https://example.com/background.jpg', 'bonsai-maintenance' ),
			esc_html__( 'Full-page background image. Overrides the background colour below.', 'bonsai-maintenance' )
		);
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
