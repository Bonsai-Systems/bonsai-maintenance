<?php
/**
 * Bonsai admin UI: shared header and stylesheet for this plugin's screens.
 *
 * Markup and CSS follow the Bonsai admin design system
 * (assets/bonsai-admin-ui.css, canonical copy in bonsai-seo-geo-checker).
 * Self-contained: nothing here depends on another Bonsai plugin.
 *
 * @package Bonsai_Maintenance
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue the design-system stylesheet. Call from admin_enqueue_scripts on
 * this plugin's screens only.
 *
 * @return void
 */
function cmm_enqueue_admin_ui() {
	wp_enqueue_style( 'cmm-bonsai-admin-ui', CMM_URL . 'assets/bonsai-admin-ui.css', array(), CMM_VERSION );
}

/**
 * Print the Bonsai page header, followed by the marker core uses to place
 * admin notices, so notices sit below the header rather than above it.
 *
 * @param string $title Page title (plain text).
 * @param string $lead  Short description. May contain links, code and emphasis.
 * @param array  $links Extra links shown before the standard ones: each array( 'label' => '', 'url' => '' ).
 * @return void
 */
function cmm_render_admin_header( $title, $lead = '', $links = array() ) {
	$repo  = 'https://github.com/Bonsai-Systems/bonsai-maintenance';
	$links = array_merge(
		$links,
		array(
			array(
				'label'    => __( 'GitHub', 'bonsai-maintenance' ),
				'url'      => $repo,
				'external' => true,
			),
			array(
				'label'    => __( 'Changelog', 'bonsai-maintenance' ),
				'url'      => $repo . '/releases',
				'external' => true,
			),
			array(
				'label'    => __( 'The Bonsai Digital Collective', 'bonsai-maintenance' ),
				'url'      => 'https://bonsaidigitalcollective.co.uk/',
				'external' => true,
			),
		)
	);
	$allowed = array(
		'a'      => array( 'href' => true ),
		'code'   => array(),
		'strong' => array(),
		'em'     => array(),
	);
	?>
	<header class="bonsai-ui-header">
		<div class="bonsai-ui-header__main">
			<img class="bonsai-ui-header__logo" src="<?php echo esc_url( CMM_URL . 'assets/bonsai-avatar.jpg' ); ?>" width="412" height="108" alt="<?php esc_attr_e( 'The Bonsai Digital Collective', 'bonsai-maintenance' ); ?>">
			<h1 class="bonsai-ui-header__title"><?php echo esc_html( $title ); ?></h1>
			<?php if ( '' !== $lead ) : ?>
				<p class="bonsai-ui-header__lead"><?php echo wp_kses( $lead, $allowed ); ?></p>
			<?php endif; ?>
			<ul class="bonsai-ui-header__links">
				<?php foreach ( $links as $link ) : ?>
					<li>
						<?php if ( ! empty( $link['external'] ) ) : ?>
							<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link['label'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'bonsai-maintenance' ); ?></span></a>
						<?php else : ?>
							<a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<div class="bonsai-ui-header__meta">
			<span class="bonsai-ui-version">v<?php echo esc_html( CMM_VERSION ); ?></span>
		</div>
	</header>
	<hr class="wp-header-end">
	<?php
}
