<?php
/**
 * Deferred admin screen title tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Admin\AdminScreen;
use PHPUnit\Framework\TestCase;

/**
 * Pins that a screen title can be named without being translated yet.
 *
 * Modules register on `plugins_loaded`. Translating there asks WordPress for a
 * text domain before `init`, which WordPress 6.7 and later reports as an error
 * on every page load. Deferring the call is what makes registration inert, and
 * a plain string still has to work for anything that is not translated.
 */
final class AdminScreenDeferredTitleTest extends TestCase {
	/**
	 * Asserts a plain string title still works.
	 *
	 * @return void
	 */
	public function test_string_titles_are_returned_unchanged() {
		$screen = new AdminScreen( 'Page', 'Menu', 'manage_options', 'slug', '__return_true' );

		$this->assertSame( 'Page', $screen->page_title() );
		$this->assertSame( 'Menu', $screen->menu_title() );
	}

	/**
	 * Asserts a callable title is not called during construction.
	 *
	 * @return void
	 */
	public function test_callable_titles_are_not_resolved_on_construction() {
		$calls = 0;

		$screen = new AdminScreen(
			static function () use ( &$calls ) {
				++$calls;

				return 'Deferred page';
			},
			'Menu',
			'manage_options',
			'slug',
			'__return_true'
		);

		$this->assertSame( 0, $calls, 'Registering a screen must not translate anything.' );

		$this->assertSame( 'Deferred page', $screen->page_title() );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Asserts a deferred title resolves each time it is asked for.
	 *
	 * The locale can change between the menu being built and a screen being
	 * rendered, so the value is not cached.
	 *
	 * @return void
	 */
	public function test_deferred_titles_follow_the_current_locale() {
		$locale = 'en';

		$screen = new AdminScreen(
			'Page',
			static function () use ( &$locale ) {
				return 'en' === $locale ? 'Languages' : 'Diller';
			},
			'manage_options',
			'slug',
			'__return_true'
		);

		$this->assertSame( 'Languages', $screen->menu_title() );

		$locale = 'tr';

		$this->assertSame( 'Diller', $screen->menu_title() );
	}

	/**
	 * Asserts the other accessors are unaffected.
	 *
	 * @return void
	 */
	public function test_remaining_accessors_are_unchanged() {
		$screen = new AdminScreen( 'Page', 'Menu', 'edit_posts', 'mclogiora-thing', '__return_true' );

		$this->assertSame( 'edit_posts', $screen->capability() );
		$this->assertSame( 'mclogiora-thing', $screen->slug() );
		$this->assertSame( '__return_true', $screen->callback() );
	}
}
