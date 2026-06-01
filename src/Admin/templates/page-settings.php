<?php
/**
 * Settings page template.
 *
 * Rendered by Admin::render_settings_page().
 * All output escaping follows WordPress coding standards.
 */

defined( 'ABSPATH' ) || exit;

use CodePros\SVGSecureSupport\Admin\Admin;
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:20px;">
		<a href="<?php echo esc_url( Admin::tab_url( 'settings' ) ); ?>" class="nav-tab nav-tab-active">
			<?php esc_html_e( 'Settings', 'codepros-svg-secure-support' ); ?>
		</a>
		<a href="<?php echo esc_url( Admin::tab_url( 'logs' ) ); ?>" class="nav-tab">
			<?php esc_html_e( 'Security Logs', 'codepros-svg-secure-support' ); ?>
		</a>
	</nav>

	<?php settings_errors( Admin::option_group() ); ?>

	<form method="post" action="options.php">
		<?php
		settings_fields( Admin::option_group() );
		do_settings_sections( Admin::settings_page_slug() );
		submit_button( __( 'Save Settings', 'codepros-svg-secure-support' ) );
		?>
	</form>
</div>
