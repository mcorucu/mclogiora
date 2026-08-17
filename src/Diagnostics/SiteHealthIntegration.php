<?php
/**
 * WordPress Site Health integration.
 *
 * @package McLogiora
 */

namespace McLogiora\Diagnostics;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Adds sanitized debug information and actionable direct tests.
 */
final class SiteHealthIntegration implements ModuleInterface {
	/**
	 * Shared diagnostics service.
	 *
	 * @var DiagnosticsService|null
	 */
	private $diagnostics;

	/**
	 * Registers native WordPress Site Health filters.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$this->diagnostics = $container->get( DiagnosticsService::class );
		add_filter( 'debug_information', array( $this, 'debug_information' ) );
		add_filter( 'site_status_tests', array( $this, 'site_status_tests' ) );
	}

	/**
	 * Adds one safe mcLogiora section to Site Health Info.
	 *
	 * @param array<string,mixed> $info Existing debug information.
	 * @return array<string,mixed>
	 */
	public function debug_information( array $info ) {
		$report = $this->report();

		$info['mclogiora'] = array(
			'label'  => __( 'mcLogiora', 'mclogiora' ),
			'fields' => array(
				'version'          => $this->field( __( 'Version', 'mclogiora' ), $report['environment']['plugin_version'] ),
				'wordpress'        => $this->field( __( 'WordPress version', 'mclogiora' ), $report['environment']['wordpress_version'] ),
				'php'              => $this->field( __( 'PHP version', 'mclogiora' ), $report['environment']['php_version'] ),
				'languages'        => $this->field( __( 'Languages', 'mclogiora' ), sprintf( '%d configured, %d active', $report['languages']['configured_count'], $report['languages']['active_count'] ) ),
				'default_language' => $this->field( __( 'Default language', 'mclogiora' ), '' !== $report['languages']['default_code'] ? $report['languages']['default_code'] : __( 'Not configured', 'mclogiora' ) ),
				'schema'           => $this->field( __( 'Schema ready', 'mclogiora' ), $report['persistence']['schema_ready'] ? __( 'Yes', 'mclogiora' ) : __( 'No', 'mclogiora' ) ),
				'package_format'   => $this->field( __( 'Package format', 'mclogiora' ), $report['import_export']['format'] . ' v' . $report['import_export']['format_version'] ),
				'suggestions'      => $this->field( __( 'Translation Suggestions', 'mclogiora' ), $report['suggestions']['enabled'] ? __( 'Enabled', 'mclogiora' ) : __( 'Disabled', 'mclogiora' ) ),
				'object_cache'     => $this->field( __( 'Persistent object cache', 'mclogiora' ), $report['cache']['persistent'] ? __( 'Yes', 'mclogiora' ) : __( 'No', 'mclogiora' ) ),
			),
		);

		return $info;
	}

	/**
	 * Registers only direct, actionable tests.
	 *
	 * @param array<string,mixed> $tests Existing tests.
	 * @return array<string,mixed>
	 */
	public function site_status_tests( array $tests ) {
		$tests['direct']['mclogiora_default_language'] = array(
			'label' => __( 'mcLogiora has a valid default language', 'mclogiora' ),
			'test'  => array( $this, 'test_default_language' ),
		);
		$tests['direct']['mclogiora_schema']           = array(
			'label' => __( 'mcLogiora database schema is ready', 'mclogiora' ),
			'test'  => array( $this, 'test_schema' ),
		);
		$tests['direct']['mclogiora_permalinks']       = array(
			'label' => __( 'mcLogiora translated routing has suitable permalinks', 'mclogiora' ),
			'test'  => array( $this, 'test_permalinks' ),
		);

		$report = $this->report();
		if ( $report['suggestions']['enabled'] ) {
			$tests['direct']['mclogiora_suggestions'] = array(
				'label' => __( 'mcLogiora Translation Suggestions are configured', 'mclogiora' ),
				'test'  => array( $this, 'test_suggestions' ),
			);
		}

		return $tests;
	}

	/**
	 * Runs the default-language test.
	 *
	 * @return array<string,mixed>
	 */
	public function test_default_language() {
		return $this->result( $this->finding( 'default_language' ), __( 'Configure one active default language in mcLogiora.', 'mclogiora' ) );
	}

	/**
	 * Runs the schema test.
	 *
	 * @return array<string,mixed>
	 */
	public function test_schema() {
		return $this->result( $this->finding( 'schema' ), __( 'Use the normal plugin activation or migration path to restore the schema; diagnostics never repairs it.', 'mclogiora' ) );
	}

	/**
	 * Runs the permalink test.
	 *
	 * @return array<string,mixed>
	 */
	public function test_permalinks() {
		return $this->result( $this->finding( 'permalinks' ), __( 'Review WordPress Settings → Permalinks and choose a pretty permalink structure.', 'mclogiora' ) );
	}

	/**
	 * Runs the provider configuration test.
	 *
	 * @return array<string,mixed>
	 */
	public function test_suggestions() {
		return $this->result( $this->finding( 'suggestions' ), __( 'Select a provider and configure its credential/model locally; no connection test is performed.', 'mclogiora' ) );
	}

	/**
	 * Returns the current diagnostics projection.
	 *
	 * @return array<string,mixed>
	 */
	private function report() {
		return $this->diagnostics instanceof DiagnosticsService ? $this->diagnostics->collect() : array(
			'environment'   => array(
				'plugin_version'    => '',
				'wordpress_version' => '',
				'php_version'       => '',
			),
			'languages'     => array(
				'configured_count' => 0,
				'active_count'     => 0,
				'default_code'     => '',
			),
			'persistence'   => array( 'schema_ready' => false ),
			'import_export' => array(
				'format'         => '',
				'format_version' => 0,
			),
			'suggestions'   => array( 'enabled' => false ),
			'cache'         => array( 'persistent' => false ),
			'findings'      => array(),
		);
	}

	/**
	 * Finds one diagnostic result by ID.
	 *
	 * @param string $id Finding ID.
	 * @return array<string,string>
	 */
	private function finding( $id ) {
		foreach ( $this->report()['findings'] as $finding ) {
			if ( isset( $finding['id'] ) && $id === $finding['id'] ) {
				return $finding;
			}
		}

		return array(
			'status' => DiagnosticsService::CRITICAL,
			'label'  => __( 'mcLogiora diagnostic unavailable', 'mclogiora' ),
			'detail' => '',
		);
	}

	/**
	 * Maps a finding into WordPress's direct-test contract.
	 *
	 * @param array<string,string> $finding Finding.
	 * @param string               $action Operator action.
	 * @return array<string,mixed>
	 */
	private function result( array $finding, $action ) {
		$status = in_array( $finding['status'], array( DiagnosticsService::GOOD, DiagnosticsService::RECOMMENDED, DiagnosticsService::CRITICAL ), true ) ? $finding['status'] : DiagnosticsService::RECOMMENDED;

		return array(
			'label'       => $finding['label'],
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'mcLogiora', 'mclogiora' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $finding['detail'] ) . '</p>',
			'actions'     => DiagnosticsService::GOOD === $status ? '' : '<p>' . esc_html( $action ) . '</p>',
		);
	}

	/**
	 * Builds a private-safe debug field.
	 *
	 * @param string $label Field label.
	 * @param mixed  $value Field value.
	 * @return array<string,mixed>
	 */
	private function field( $label, $value ) {
		return array(
			'label'   => $label,
			'value'   => (string) $value,
			'private' => false,
		);
	}
}
