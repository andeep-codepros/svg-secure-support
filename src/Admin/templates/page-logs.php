<?php
/**
 * Security log viewer template.
 *
 * Rendered by Admin::render_logs_page().
 */

defined( 'ABSPATH' ) || exit;

use CodePros\SVGSecureSupport\Admin\Admin;
use CodePros\SVGSecureSupport\Database;

// Purge notice.
$purged = isset( $_GET['cpsvgss_purged'] ) ? (int) $_GET['cpsvgss_purged'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Active filters.
$filter_severity   = isset( $_GET['severity'] )   ? sanitize_text_field( wp_unslash( $_GET['severity'] ) )   : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$filter_event_type = isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$current_page      = isset( $_GET['paged'] )      ? max( 1, (int) $_GET['paged'] )                          : 1;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$per_page          = 20;

$filters = array_filter( [
	'severity'   => $filter_severity,
	'event_type' => $filter_event_type,
] );

$result     = Database::get_instance()->get_logs( $filters, $per_page, $current_page );
$rows       = $result['rows'];
$total      = $result['total'];
$page_count = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

$severity_colors = [
	'info'     => '#2271b1',
	'warning'  => '#dba617',
	'critical' => '#d63638',
];

$event_types = [
	'upload_blocked',
	'upload_sanitized',
	'upload_allowed',
	'suspicious_payload',
	'tag_removed',
	'attribute_removed',
];

$base_url = add_query_arg(
	array_filter( [
		'page'       => Admin::settings_page_slug(),
		'tab'        => 'logs',
		'severity'   => $filter_severity,
		'event_type' => $filter_event_type,
	] ),
	admin_url( 'options-general.php' )
);
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:20px;">
		<a href="<?php echo esc_url( Admin::tab_url( 'settings' ) ); ?>" class="nav-tab">
			<?php esc_html_e( 'Settings', 'codepros-svg-secure-support' ); ?>
		</a>
		<a href="<?php echo esc_url( Admin::tab_url( 'logs' ) ); ?>" class="nav-tab nav-tab-active">
			<?php esc_html_e( 'Security Logs', 'codepros-svg-secure-support' ); ?>
		</a>
	</nav>

	<?php if ( $purged >= 0 ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %d: number of log entries deleted */
					esc_html__( 'Purged %d log entries.', 'codepros-svg-secure-support' ),
					absint( $purged )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:16px;">

		<?php // Filter form ?>
		<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin::settings_page_slug() ); ?>">
			<input type="hidden" name="tab" value="logs">

			<select name="severity">
				<option value=""><?php esc_html_e( 'All Severities', 'codepros-svg-secure-support' ); ?></option>
				<?php foreach ( array_keys( $severity_colors ) as $sev ) : ?>
					<option value="<?php echo esc_attr( $sev ); ?>"<?php selected( $filter_severity, $sev ); ?>>
						<?php echo esc_html( ucfirst( $sev ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="event_type">
				<option value=""><?php esc_html_e( 'All Event Types', 'codepros-svg-secure-support' ); ?></option>
				<?php foreach ( $event_types as $evt ) : ?>
					<option value="<?php echo esc_attr( $evt ); ?>"<?php selected( $filter_event_type, $evt ); ?>>
						<?php echo esc_html( $evt ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Filter', 'codepros-svg-secure-support' ), 'secondary', '', false ); ?>

			<?php if ( $filter_severity || $filter_event_type ) : ?>
				<a href="<?php echo esc_url( Admin::tab_url( 'logs' ) ); ?>" class="button">
					<?php esc_html_e( 'Clear', 'codepros-svg-secure-support' ); ?>
				</a>
			<?php endif; ?>
		</form>

		<?php // Purge form ?>
		<form method="post"
		      action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		      onsubmit="return confirm( '<?php echo esc_js( __( 'Delete log entries older than the configured retention period?', 'codepros-svg-secure-support' ) ); ?>' );">
			<?php wp_nonce_field( 'cpsvgss_purge_logs' ); ?>
			<input type="hidden" name="action" value="svgss_purge_logs">
			<?php
			submit_button(
				sprintf(
					/* translators: %d: retention days */
					__( 'Purge Entries Older Than %d Days', 'codepros-svg-secure-support' ),
					(int) get_option( 'cpsvgss_log_retention_days', 30 )
				),
				'delete',
				'',
				false
			);
			?>
		</form>

	</div>

	<p class="description" style="margin-bottom:8px;">
		<?php
		printf(
			/* translators: %d: total log entries */
			esc_html__( '%d total entries', 'codepros-svg-secure-support' ),
			absint( $total )
		);
		?>
	</p>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:155px"><?php esc_html_e( 'Date / Time (UTC)', 'codepros-svg-secure-support' ); ?></th>
				<th style="width:85px"><?php esc_html_e( 'Severity', 'codepros-svg-secure-support' ); ?></th>
				<th style="width:150px"><?php esc_html_e( 'Event', 'codepros-svg-secure-support' ); ?></th>
				<th style="width:180px"><?php esc_html_e( 'File', 'codepros-svg-secure-support' ); ?></th>
				<th><?php esc_html_e( 'Details', 'codepros-svg-secure-support' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'User', 'codepros-svg-secure-support' ); ?></th>
				<th style="width:115px"><?php esc_html_e( 'IP Address', 'codepros-svg-secure-support' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $rows ) ) : ?>
			<tr>
				<td colspan="7" style="text-align:center; padding:20px;">
					<em><?php esc_html_e( 'No log entries found.', 'codepros-svg-secure-support' ); ?></em>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$sev   = $row['severity'] ?? 'info';
				$color = $severity_colors[ $sev ] ?? '#666';
				?>
				<tr>
					<td><?php echo esc_html( $row['created_at'] ?? '' ); ?></td>
					<td>
						<span style="display:inline-block; padding:1px 7px; border-radius:3px; background:<?php echo esc_attr( $color ); ?>; color:#fff; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.4px;">
							<?php echo esc_html( $sev ); ?>
						</span>
					</td>
					<td><code style="font-size:11px;"><?php echo esc_html( $row['event_type'] ?? '' ); ?></code></td>
					<td style="word-break:break-all;"><?php echo esc_html( $row['filename'] ?? '' ); ?></td>
					<td><?php echo esc_html( $row['details'] ?? '' ); ?></td>
					<td>
						<?php $login = $row['user_login'] ?? ''; ?>
						<?php if ( $login && 'guest' !== $login ) : ?>
							<?php echo esc_html( $login ); ?>
							<br><small style="color:#999;">ID:<?php echo absint( $row['user_id'] ?? 0 ); ?></small>
						<?php else : ?>
							<em style="color:#999;"><?php esc_html_e( 'guest', 'codepros-svg-secure-support' ); ?></em>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $row['ip_address'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $page_count > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages" style="margin:8px 0;">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: 1: current page, 2: total pages */
						esc_html__( 'Page %1$d of %2$d', 'codepros-svg-secure-support' ),
						absint( $current_page ),
						absint( $page_count )
					);
					?>
				</span>
				&nbsp;
				<?php if ( $current_page > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ); ?>">
						&laquo; <?php esc_html_e( 'Previous', 'codepros-svg-secure-support' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $current_page < $page_count ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ); ?>">
						<?php esc_html_e( 'Next', 'codepros-svg-secure-support' ); ?> &raquo;
					</a>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

</div>
