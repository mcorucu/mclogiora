<?php
/**
 * McLogiora System Status screen.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Diagnostics\DiagnosticsService;

defined( 'ABSPATH' ) || exit;

/**
 * Presents the shared diagnostics projection without mutation controls.
 */
final class SystemStatusDashboard implements ModuleInterface {
	/**
	 * Shared diagnostics service.
	 *
	 * @var DiagnosticsService|null
	 */
	private $diagnostics;

	/**
	 * Effective access capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * Registers the read-only submenu screen.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->diagnostics = $container->get( DiagnosticsService::class );
		$this->capability  = $container->get( CapabilityRegistry::class )->resolve( CapabilityRegistry::MANAGE );

		$container->get( AdminScreenRegistry::class )->add(
			new AdminScreen(
				static function () {
					return __( 'mcLogiora System Status', 'mclogiora' );
				},
				static function () {
					return __( 'System Status', 'mclogiora' );
				},
				$this->capability,
				'mclogiora-system-status',
				array( $this, 'render' )
			)
		);
	}

	/**
	 * Renders the status report.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mclogiora' ) );
		}

		$report       = $this->diagnostics instanceof DiagnosticsService ? $this->diagnostics->collect() : array();
		$status       = isset( $report['meta']['overall'] ) ? (string) $report['meta']['overall'] : DiagnosticsService::CRITICAL;
		$status_class = DiagnosticsService::CRITICAL === $status ? 'mclogiora-status-card--action-required' : 'mclogiora-status-card--notice';
		?>
		<div class="wrap mclogiora-admin">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-system-status-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Diagnostics', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-system-status-title"><?php esc_html_e( 'System Status', 'mclogiora' ); ?></h1>
				<p class="mclogiora-lede"><?php esc_html_e( 'A read-only view of mcLogiora configuration and runtime prerequisites. It performs no repairs, cache resets, provider requests, or other writes.', 'mclogiora' ); ?></p>
				<div class="mclogiora-status-card <?php echo esc_attr( $status_class ); ?>" role="status" data-diagnostic-status="<?php echo esc_attr( $status ); ?>">
					<div>
						<span class="mclogiora-status-card__label"><?php esc_html_e( 'Status', 'mclogiora' ); ?></span>
						<strong><?php echo esc_html( $this->status_label( $status ) ); ?></strong>
					</div>
					<?php if ( DiagnosticsService::CRITICAL === $status ) : ?>
						<p><?php esc_html_e( 'Review the first action-required finding below before relying on translated routing.', 'mclogiora' ); ?></p>
					<?php endif; ?>
				</div>
			</section>

			<?php $this->render_findings( isset( $report['findings'] ) && is_array( $report['findings'] ) ? $report['findings'] : array() ); ?>
			<?php $this->render_rows( 'mclogiora-environment', __( 'Environment', 'mclogiora' ), isset( $report['environment'] ) ? $report['environment'] : array() ); ?>
			<?php $this->render_rows( 'mclogiora-languages', __( 'Languages', 'mclogiora' ), isset( $report['languages'] ) ? $report['languages'] : array() ); ?>
			<?php $this->render_persistence( isset( $report['persistence'] ) ? $report['persistence'] : array() ); ?>
			<?php $this->render_rows( 'mclogiora-routing', __( 'Routing and object cache', 'mclogiora' ), array_merge( isset( $report['routing'] ) ? $report['routing'] : array(), isset( $report['cache'] ) ? $report['cache'] : array() ) ); ?>
			<?php $this->render_suggestions( isset( $report['suggestions'] ) ? $report['suggestions'] : array() ); ?>
			<?php $this->render_compatibility( isset( $report['compatibility'] ) ? $report['compatibility'] : array() ); ?>
			<?php $this->render_rows( 'mclogiora-import-export', __( 'Import and Export', 'mclogiora' ), isset( $report['import_export'] ) ? $report['import_export'] : array() ); ?>
		</div>
		<?php
	}

	/**
	 * Renders findings as text, not colour-only badges.
	 *
	 * @param array<int,array<string,string>> $findings Findings.
	 * @return void
	 */
	private function render_findings( array $findings ) {
		?>
		<section class="mclogiora-panel" aria-labelledby="mclogiora-findings-title">
			<h2 id="mclogiora-findings-title"><?php esc_html_e( 'Findings', 'mclogiora' ); ?></h2>
			<ul>
				<?php foreach ( $findings as $finding ) : ?>
					<li class="mclogiora-finding mclogiora-finding--<?php echo esc_attr( isset( $finding['status'] ) ? $finding['status'] : DiagnosticsService::INFORMATIONAL ); ?>">
						<strong><?php echo esc_html( isset( $finding['label'] ) ? $finding['label'] : '' ); ?></strong>
						<span> — <?php echo esc_html( $this->status_label( isset( $finding['status'] ) ? $finding['status'] : DiagnosticsService::INFORMATIONAL ) ); ?></span>
						<?php if ( ! empty( $finding['detail'] ) ) : ?>
							<span>: <?php echo esc_html( $finding['detail'] ); ?></span>
						<?php endif; ?>
						<?php if ( isset( $finding['id'], $finding['status'] ) && 'default_language' === $finding['id'] && DiagnosticsService::CRITICAL === $finding['status'] ) : ?>
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-setup&step=default_language' ) ); ?>"><?php esc_html_e( 'Configure languages', 'mclogiora' ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * Renders scalar rows.
	 *
	 * @param string              $id Section ID.
	 * @param string              $title Section title.
	 * @param array<string,mixed> $rows Rows.
	 * @return void
	 */
	private function render_rows( $id, $title, array $rows ) {
		?>
		<section class="mclogiora-panel" aria-labelledby="<?php echo esc_attr( $id ); ?>-title">
			<h2 id="<?php echo esc_attr( $id ); ?>-title"><?php echo esc_html( $title ); ?></h2>
			<table class="widefat striped">
				<caption class="screen-reader-text"><?php echo esc_html( $title ); ?></caption>
				<tbody>
				<?php foreach ( $rows as $key => $value ) : ?>
					<?php if ( is_array( $value ) ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php echo esc_html( $this->label( $key ) ); ?></th>
						<td><?php echo esc_html( $this->value( $value ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Renders persistence data and table readiness.
	 *
	 * @param array<string,mixed> $persistence Persistence data.
	 * @return void
	 */
	private function render_persistence( array $persistence ) {
		$rows   = $persistence;
		$tables = isset( $rows['tables'] ) && is_array( $rows['tables'] ) ? $rows['tables'] : array();
		unset( $rows['tables'] );
		$this->render_rows( 'mclogiora-persistence', __( 'Translation persistence', 'mclogiora' ), $rows );

		$table_rows = array();
		foreach ( $tables as $name => $table ) {
			$table_rows[ $name ] = isset( $table['present'] ) && $table['present'] ? __( 'Present', 'mclogiora' ) : __( 'Missing', 'mclogiora' );
		}
		$this->render_rows( 'mclogiora-tables', __( 'Required tables', 'mclogiora' ), $table_rows );
	}

	/**
	 * Renders provider state without displaying credentials or model values.
	 *
	 * @param array<string,mixed> $suggestions Suggestion data.
	 * @return void
	 */
	private function render_suggestions( array $suggestions ) {
		$providers = isset( $suggestions['providers'] ) && is_array( $suggestions['providers'] ) ? $suggestions['providers'] : array();
		$rows      = $suggestions;
		unset( $rows['providers'] );
		$this->render_rows( 'mclogiora-suggestions', __( 'Translation Suggestions', 'mclogiora' ), $rows );

		$provider_rows = array();
		foreach ( $providers as $id => $provider ) {
			$provider_rows[ $id ] = isset( $provider['readiness'] ) ? $provider['readiness'] : '';
		}
		$this->render_rows( 'mclogiora-providers', __( 'Provider readiness', 'mclogiora' ), $provider_rows );
	}

	/**
	 * Renders compatibility projections.
	 *
	 * @param array<string,mixed> $compatibility Compatibility data.
	 * @return void
	 */
	private function render_compatibility( array $compatibility ) {
		$editors      = isset( $compatibility['editors'] ) && is_array( $compatibility['editors'] ) ? $compatibility['editors'] : array();
		$builders     = isset( $compatibility['builders'] ) && is_array( $compatibility['builders'] ) ? $compatibility['builders'] : array();
		$editor_rows  = array();
		$builder_rows = array();

		foreach ( $editors as $editor ) {
			$editor_rows[] = isset( $editor['label'] ) ? $editor['label'] : '';
		}

		foreach ( $builders as $builder ) {
			$builder_rows[ isset( $builder['label'] ) ? $builder['label'] : '' ] = isset( $builder['qualification'] ) ? $builder['qualification'] : '';
		}

		$this->render_rows(
			'mclogiora-compatibility',
			__( 'Editors and builders', 'mclogiora' ),
			array(
				'detected_editors'      => implode( ', ', $editor_rows ),
				'builder_qualification' => implode(
					', ',
					array_map(
						static function ( $label, $qualification ) {
							return $label . ': ' . $qualification; },
						array_keys( $builder_rows ),
						$builder_rows
					)
				),
			)
		);
	}

	/**
	 * Returns a human-readable status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function status_label( $status ) {
		$labels = array(
			DiagnosticsService::GOOD          => __( 'Good', 'mclogiora' ),
			DiagnosticsService::RECOMMENDED   => __( 'Recommended', 'mclogiora' ),
			DiagnosticsService::CRITICAL      => __( 'Action required', 'mclogiora' ),
			DiagnosticsService::INFORMATIONAL => __( 'Informational', 'mclogiora' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Informational', 'mclogiora' );
	}

	/**
	 * Returns a safe scalar display value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function value( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'mclogiora' ) : __( 'No', 'mclogiora' );
		}

		if ( null === $value ) {
			return __( 'Unavailable', 'mclogiora' );
		}

		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}

	/**
	 * Returns a translated label for a machine key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function label( $key ) {
		return ucwords( str_replace( '_', ' ', (string) $key ) );
	}
}
