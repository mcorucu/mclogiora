<?php
/**
 * Recording migration double.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Database\MigrationInterface;
use McLogiora\Database\SchemaBuilder;

/**
 * Records invocation and reports a configurable outcome.
 */
final class RecordingMigration implements MigrationInterface {
	/**
	 * Versions run, in order, across all instances.
	 *
	 * @var string[]
	 */
	public static $order = array();

	/**
	 * Migration version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Whether the migration should report success.
	 *
	 * @var bool
	 */
	private $succeeds;

	/**
	 * Number of times up() was called.
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * Constructor.
	 *
	 * @param string $version Migration version.
	 * @param bool   $succeeds Whether the migration succeeds.
	 */
	public function __construct( $version, $succeeds ) {
		$this->version  = (string) $version;
		$this->succeeds = (bool) $succeeds;

		if ( '1' === $this->version ) {
			self::$order = array();
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function version() {
		return $this->version;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string[]
	 */
	public function expected_tables() {
		return array( 'wp_mclogiora_fake_' . $this->version );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SchemaBuilder $schema_builder Schema builder.
	 * @return true|\WP_Error
	 */
	public function up( SchemaBuilder $schema_builder ) {
		unset( $schema_builder );

		++$this->calls;
		self::$order[] = $this->version;

		if ( ! $this->succeeds ) {
			return new \WP_Error(
				'mclogiora_migration_incomplete',
				'Migration ' . $this->version . ' did not create the expected tables.'
			);
		}

		return true;
	}
}
