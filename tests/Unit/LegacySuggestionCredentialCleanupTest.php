<?php
/**
 * Legacy suggestion credential cleanup tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Suggestions\CredentialStore;
use McLogiora\Suggestions\LegacySuggestionCredentialCleanup;
use McLogiora\Suggestions\SuggestionSettings;
use PHPUnit\Framework\TestCase;

/**
 * Ensures the upgrade removes only retired mcLogiora-owned state.
 */
final class LegacySuggestionCredentialCleanupTest extends TestCase {
	/**
	 * Clears migration state between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( array( 'openai', 'anthropic', 'gemini', 'deepl' ) as $provider_id ) {
			delete_option( CredentialStore::OPTION_PREFIX . $provider_id );
			delete_option( 'mclogiora_suggestion_model_' . $provider_id );
		}

		delete_option( SuggestionSettings::OPTION_PROVIDER );
		delete_option( LegacySuggestionCredentialCleanup::OPTION_COMPLETED );

		parent::tearDown();
	}

	/**
	 * Retired credentials and selections are removed, while DeepL survives.
	 *
	 * @return void
	 */
	public function test_cleanup_removes_retired_state_without_touching_deepl() {
		update_option( 'mclogiora_suggestion_key_openai', 'retired-secret' );
		update_option( 'mclogiora_suggestion_key_anthropic', 'retired-secret' );
		update_option( 'mclogiora_suggestion_key_gemini', 'retired-secret' );
		update_option( 'mclogiora_suggestion_model_openai', 'retired-model' );
		update_option( SuggestionSettings::OPTION_PROVIDER, 'gemini' );
		update_option( CredentialStore::OPTION_PREFIX . 'deepl', 'keep-this-secret' );

		$this->assertTrue( ( new LegacySuggestionCredentialCleanup() )->run() );
		$this->assertFalse( $this->option_exists( 'mclogiora_suggestion_key_openai' ) );
		$this->assertFalse( $this->option_exists( 'mclogiora_suggestion_key_anthropic' ) );
		$this->assertFalse( $this->option_exists( 'mclogiora_suggestion_key_gemini' ) );
		$this->assertFalse( $this->option_exists( 'mclogiora_suggestion_model_openai' ) );
		$this->assertSame( '', ( new SuggestionSettings() )->provider_id() );
		$this->assertSame( 'keep-this-secret', get_option( CredentialStore::OPTION_PREFIX . 'deepl' ) );
		$this->assertSame( 1, get_option( LegacySuggestionCredentialCleanup::OPTION_COMPLETED ) );
	}

	/**
	 * The marker makes the migration idempotent.
	 *
	 * @return void
	 */
	public function test_cleanup_runs_only_once() {
		$cleanup = new LegacySuggestionCredentialCleanup();

		$this->assertTrue( $cleanup->run() );
		$this->assertFalse( $cleanup->run() );
	}

	/**
	 * Checks whether a test option exists, including falsey values.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private function option_exists( $name ) {
		return array_key_exists( $name, $GLOBALS['mclogiora_test_options'] );
	}
}
