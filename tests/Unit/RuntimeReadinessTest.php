<?php
/**
 * Runtime readiness tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Core\RuntimeReadiness;
use PHPUnit\Framework\TestCase;
use WP_Query;

/**
 * Pins the lifecycle boundary between installation and multilingual runtime.
 *
 * Phase 12 hung a whole WordPress installation because one question --
 * "may I call a conditional query tag yet?" -- was answered in the wrong
 * place. These tests state each answer explicitly so the boundary cannot
 * drift back.
 */
final class RuntimeReadinessTest extends TestCase {
	/**
	 * Puts the environment into an ordinary, ready front-end request.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['mclogiora_test_installing'] = false;
		$GLOBALS['mclogiora_test_is_admin']   = false;
		$GLOBALS['mclogiora_test_doing_ajax'] = false;
		$GLOBALS['mclogiora_test_doing_cron'] = false;
		$GLOBALS['mclogiora_test_is_preview'] = false;
		$GLOBALS['wp_query']                  = new WP_Query();

		update_option( 'mclogiora_db_version', '2', false );
	}

	/**
	 * Restores the global environment.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_query'] );
		delete_option( 'mclogiora_db_version' );

		parent::tearDown();
	}

	/**
	 * Asserts an ordinary ready front-end request enables the runtime.
	 *
	 * @return void
	 */
	public function test_ready_frontend_request_enables_the_runtime() {
		$this->assertTrue( ( new RuntimeReadiness() )->is_frontend_runtime() );
	}

	/**
	 * Asserts installation disables the runtime.
	 *
	 * @return void
	 */
	public function test_installing_wordpress_disables_the_runtime() {
		$GLOBALS['mclogiora_test_installing'] = true;

		$readiness = new RuntimeReadiness();

		$this->assertTrue( $readiness->is_installing() );
		$this->assertFalse( $readiness->is_schema_ready(), 'Installation must never report a usable schema.' );
		$this->assertFalse( $readiness->is_frontend_runtime() );
	}

	/**
	 * Asserts a missing schema disables the runtime.
	 *
	 * @return void
	 */
	public function test_missing_schema_disables_the_runtime() {
		delete_option( 'mclogiora_db_version' );

		$readiness = new RuntimeReadiness();

		$this->assertFalse( $readiness->is_schema_ready() );
		$this->assertFalse( $readiness->is_frontend_runtime() );
	}

	/**
	 * Asserts an unmigrated schema version counts as no schema.
	 *
	 * @return void
	 */
	public function test_zero_schema_version_counts_as_missing() {
		update_option( 'mclogiora_db_version', '0', false );

		$this->assertFalse( ( new RuntimeReadiness() )->is_schema_ready() );
	}

	/**
	 * Asserts the runtime stays inert before WordPress builds the main query.
	 *
	 * This is the exact condition that made the plugin recurse into `gettext`
	 * forever: at `plugins_loaded` the query does not exist, and asking
	 * WordPress a conditional question there produces a translated notice.
	 *
	 * @return void
	 */
	public function test_runtime_is_inert_before_the_main_query_exists() {
		unset( $GLOBALS['wp_query'] );

		$readiness = new RuntimeReadiness();

		$this->assertFalse( $readiness->is_query_available() );
		$this->assertFalse( $readiness->is_frontend_runtime() );
	}

	/**
	 * Asserts admin requests are excluded.
	 *
	 * @return void
	 */
	public function test_admin_requests_are_excluded() {
		$GLOBALS['mclogiora_test_is_admin'] = true;

		$readiness = new RuntimeReadiness();

		$this->assertTrue( $readiness->is_admin_request() );
		$this->assertFalse( $readiness->is_frontend_runtime() );
	}

	/**
	 * Asserts cron runs are excluded.
	 *
	 * @return void
	 */
	public function test_cron_runs_are_excluded() {
		$GLOBALS['mclogiora_test_doing_cron'] = true;

		$readiness = new RuntimeReadiness();

		$this->assertTrue( $readiness->is_cron() );
		$this->assertFalse( $readiness->is_frontend_runtime() );
	}

	/**
	 * Asserts admin-ajax requests are excluded.
	 *
	 * @return void
	 */
	public function test_ajax_requests_are_excluded() {
		$GLOBALS['mclogiora_test_doing_ajax'] = true;

		$this->assertFalse( ( new RuntimeReadiness() )->is_frontend_runtime() );
	}

	/**
	 * Asserts previews are excluded.
	 *
	 * @return void
	 */
	public function test_previews_are_excluded() {
		$GLOBALS['mclogiora_test_is_preview'] = true;

		$this->assertFalse( ( new RuntimeReadiness() )->is_frontend_runtime() );
	}

	/**
	 * Asserts WP-CLI runs are excluded.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_cli_runs_are_excluded() {
		define( 'WP_CLI', true );

		$readiness = new RuntimeReadiness();

		$this->assertTrue( $readiness->is_cli() );
		$this->assertFalse( $readiness->is_frontend_runtime() );
	}

	/**
	 * Asserts REST requests are excluded.
	 *
	 * Phase 12 translates rendered front-end output. A REST response is the
	 * block editor's own data as often as it is a visitor-facing payload, and
	 * rewriting it would change what editors see. Multilingual REST output is
	 * a deliberate non-goal of this phase, not an oversight.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_rest_requests_are_excluded() {
		define( 'REST_REQUEST', true );

		$readiness = new RuntimeReadiness();

		$this->assertTrue( $readiness->is_rest_request() );
		$this->assertFalse( $readiness->is_frontend_runtime() );
	}

	/**
	 * Asserts autosaves are excluded.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_autosaves_are_excluded() {
		define( 'DOING_AUTOSAVE', true );

		$readiness = new RuntimeReadiness();

		$this->assertTrue( $readiness->is_autosave() );
		$this->assertFalse( $readiness->is_frontend_runtime() );
	}

	/**
	 * Asserts a positive schema answer is memoised and resettable.
	 *
	 * The memoisation is deliberately one-directional: a request can install
	 * the schema, but nothing removes it mid-request, so only the affirmative
	 * answer is safe to keep.
	 *
	 * @return void
	 */
	public function test_schema_readiness_is_memoised_and_resettable() {
		$readiness = new RuntimeReadiness();

		$this->assertTrue( $readiness->is_schema_ready() );

		delete_option( 'mclogiora_db_version' );

		$this->assertTrue( $readiness->is_schema_ready(), 'A positive answer is memoised for the request.' );

		$readiness->reset();

		$this->assertFalse( $readiness->is_schema_ready(), 'Resetting re-reads the stored version.' );
	}

	/**
	 * Asserts a negative schema answer is never cached.
	 *
	 * @return void
	 */
	public function test_schema_readiness_is_rechecked_until_it_is_ready() {
		delete_option( 'mclogiora_db_version' );

		$readiness = new RuntimeReadiness();

		$this->assertFalse( $readiness->is_schema_ready() );

		update_option( 'mclogiora_db_version', '2', false );

		$this->assertTrue( $readiness->is_schema_ready(), 'Activation within a request must take effect.' );
	}
}
