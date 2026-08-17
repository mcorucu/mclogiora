<?php
/**
 * Portable package export integration tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Integration;

use McLogiora\Core\Application;
use McLogiora\Core\RuntimeReadiness;
use McLogiora\Database\Installer;
use McLogiora\Database\TableNames;
use McLogiora\ImportExport\PackageEncoder;
use McLogiora\ImportExport\PackageExporter;
use McLogiora\ImportExport\PackageFormat;
use McLogiora\ImportExport\PackageParser;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageRepositoryInterface;
use McLogiora\Languages\LanguageStatus;
use McLogiora\Relations\TranslationRelationRepositoryInterface;
use McLogiora\Workflows\TranslationWorkflowService;
use WP_UnitTestCase;

/**
 * Exports a real multilingual site and inspects what came out.
 *
 * The fixture is built through the shipped workflows against real posts, real
 * terms and a real hierarchy, because the questions this file has to answer --
 * what slug does WordPress give this page, what does its ancestor path look
 * like, what status did the workflow record -- have no meaningful answer
 * against hand-written arrays.
 *
 * Two properties matter more than the projections and are asserted hardest:
 * exporting changes nothing, and exporting reaches nothing.
 */
final class PackageExportIntegrationTest extends WP_UnitTestCase {
	/**
	 * Service container.
	 *
	 * @var \McLogiora\Core\Container
	 */
	private $container;

	/**
	 * Exporter under test.
	 *
	 * @var PackageExporter
	 */
	private $exporter;

	/**
	 * Fixture identifiers.
	 *
	 * @var array<string,int>
	 */
	private $fixture = array();

	/**
	 * Builds an installed, translated site.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->container = Application::instance( dirname( __DIR__, 2 ) . '/mclogiora.php' )->container();

		delete_option( 'mclogiora_db_version' );
		$this->container->get( Installer::class )->install();
		$this->container->get( RuntimeReadiness::class )->reset();

		create_initial_taxonomies();

		$languages = $this->container->get( LanguageRepositoryInterface::class );

		if ( ! $languages->find_by_code( 'en' ) instanceof Language ) {
			$languages->create( new Language( 'en', 'en_US', 'English', 'English', 'ltr', LanguageStatus::ACTIVE, 0, false ) );
			$languages->set_default( 'en' );
		}

		if ( ! $languages->find_by_code( 'tr' ) instanceof Language ) {
			$languages->create( new Language( 'tr', 'tr_TR', 'Turkce', 'Turkish', 'ltr', LanguageStatus::ACTIVE, 1, false ) );
		}

		if ( ! $languages->find_by_code( 'ar' ) instanceof Language ) {
			$languages->create( new Language( 'ar', 'ar', 'Arabic', 'Arabic', 'rtl', LanguageStatus::INACTIVE, 2, false ) );
		}

		$this->build_fixture();

		$this->exporter = $this->container->get( PackageExporter::class );
	}

	/* --------------------------------------------------------------------
	 * Projection
	 * ----------------------------------------------------------------- */

	/**
	 * The language section reports the site's real configuration.
	 *
	 * @return void
	 */
	public function test_languages_are_exported_from_real_configuration() {
		$languages = $this->exporter->export()->to_array()['payload']['languages'];

		$this->assertSame( array( 'ar', 'en', 'tr' ), array_column( $languages, 'code' ) );
		$this->assertSame(
			array(
				'code'         => 'en',
				'locale'       => 'en_US',
				'native_name'  => 'English',
				'english_name' => 'English',
				'direction'    => 'ltr',
				'is_active'    => true,
				'is_default'   => true,
				'order'        => 0,
			),
			$languages[1]
		);
		$this->assertSame( 'rtl', $languages[0]['direction'] );
		$this->assertFalse( $languages[0]['is_active'] );
	}

	/**
	 * A hierarchical page exports the slugs WordPress actually gave it.
	 *
	 * @return void
	 */
	public function test_a_hierarchical_page_exports_its_ancestor_path() {
		$item = $this->exported_item( $this->group_key_for( $this->fixture['page_en'] ), 'en' );

		$this->assertSame(
			array(
				'kind'      => 'post',
				'post_type' => 'page',
				'slug'      => get_post_field( 'post_name', $this->fixture['page_en'] ),
				'ancestors' => array( 'company' ),
			),
			$item['locator']
		);
		$this->assertSame( 'original', $item['status'] );
		$this->assertTrue( $item['is_source'] );
	}

	/**
	 * A flat post type exports without an ancestors member.
	 *
	 * @return void
	 */
	public function test_a_flat_post_exports_without_ancestors() {
		$item = $this->exported_item( $this->group_key_for( $this->fixture['post_en'] ), 'tr' );

		$this->assertSame(
			array(
				'kind'      => 'post',
				'post_type' => 'post',
				'slug'      => get_post_field( 'post_name', $this->fixture['post_tr'] ),
			),
			$item['locator']
		);
		$this->assertArrayNotHasKey( 'ancestors', $item['locator'] );
	}

	/**
	 * A term exports its taxonomy and slug.
	 *
	 * @return void
	 */
	public function test_a_term_exports_its_taxonomy_and_slug() {
		$item = $this->exported_item( $this->group_key_for( $this->fixture['term_en'], 'term' ), 'tr' );

		$this->assertSame(
			array(
				'kind'     => 'term',
				'taxonomy' => 'category',
				'slug'     => get_term( $this->fixture['term_tr'] )->slug,
			),
			$item['locator']
		);
		$this->assertSame( 'needs_review', $item['status'] );
	}

	/**
	 * A draft translation has no slug yet, and the package says so honestly.
	 *
	 * @return void
	 */
	public function test_a_draft_translation_exports_an_empty_slug() {
		$created = $this->container->get( TranslationWorkflowService::class )
			->content()
			->create_translation( $this->fixture['draft_source'], 'tr' );

		$this->assertIsArray( $created );
		$this->assertSame( 'draft', get_post_status( $created['post_id'] ) );
		$this->assertSame( '', get_post_field( 'post_name', $created['post_id'] ) );

		$item = $this->exported_item( $created['group_key'], 'tr' );

		$this->assertSame( '', $item['locator']['slug'] );
	}

	/**
	 * The manifest counts match what the payload contains.
	 *
	 * @return void
	 */
	public function test_manifest_counts_match_the_payload() {
		$package = $this->exporter->export();
		$payload = $package->to_array()['payload'];
		$items   = 0;

		foreach ( $payload['relations'] as $group ) {
			$items += count( $group['items'] );
		}

		$this->assertSame(
			array(
				'languages'       => count( $payload['languages'] ),
				'relation_groups' => count( $payload['relations'] ),
				'relation_items'  => $items,
			),
			$package->manifest()->counts()
		);
		$this->assertSame( PackageFormat::VERSION, $package->manifest()->format_version() );
		$this->assertSame( MCLOGIORA_VERSION, $package->manifest()->generator_version() );
	}

	/**
	 * The plugin's own parser accepts the plugin's own export.
	 *
	 * @return void
	 */
	public function test_an_export_reads_back_through_the_parser() {
		$encoded = ( new PackageEncoder() )->encode( $this->exporter->export() );
		$parsed  = ( new PackageParser() )->parse( $encoded );

		$this->assertNotInstanceOf( \WP_Error::class, $parsed );
		$this->assertSame(
			json_decode( $encoded, true )['payload'],
			$parsed->to_array()['payload']
		);
	}

	/* --------------------------------------------------------------------
	 * Determinism
	 * ----------------------------------------------------------------- */

	/**
	 * Two exports of unchanged state carry the same payload bytes.
	 *
	 * @return void
	 */
	public function test_two_exports_of_unchanged_state_are_identical() {
		$encoder = new PackageEncoder();
		$first   = json_decode( $encoder->encode( $this->exporter->export() ), true );
		$second  = json_decode( $encoder->encode( $this->exporter->export() ), true );

		$this->assertSame( $first['payload'], $second['payload'] );
		$this->assertSame(
			array_diff_key( $first['manifest'], array( 'created_at' => true ) ),
			array_diff_key( $second['manifest'], array( 'created_at' => true ) )
		);
	}

	/**
	 * Group order does not follow the order groups were last touched.
	 *
	 * @return void
	 */
	public function test_export_order_survives_a_relation_being_touched() {
		$before = $this->exporter->export()->to_array()['payload']['relations'];

		$this->container->get( TranslationWorkflowService::class )
			->change_status( 'post', $this->fixture['post_tr'], 'tr', 'translated' );

		$after = $this->exporter->export()->to_array()['payload']['relations'];

		$this->assertSame( array_column( $before, 'group_key' ), array_column( $after, 'group_key' ) );
	}

	/* --------------------------------------------------------------------
	 * Read-only
	 * ----------------------------------------------------------------- */

	/**
	 * Exporting changes nothing about the site it describes.
	 *
	 * @return void
	 */
	public function test_export_leaves_the_site_byte_identical() {
		$before = $this->site_snapshot();

		$this->exporter->export();
		$this->exporter->export();
		( new PackageEncoder() )->encode( $this->exporter->export() );

		$this->assertSame( $before, $this->site_snapshot() );
	}

	/**
	 * Exporting contacts nothing.
	 *
	 * @return void
	 */
	public function test_export_makes_no_outbound_request() {
		$requests = 0;

		$counter = static function ( $preempt, $args, $url ) use ( &$requests ) {
			unset( $args, $url );
			++$requests;

			return $preempt;
		};

		add_filter( 'pre_http_request', $counter, 10, 3 );

		( new PackageEncoder() )->encode( $this->exporter->export() );

		remove_filter( 'pre_http_request', $counter, 10 );

		$this->assertSame( 0, $requests );
	}

	/* --------------------------------------------------------------------
	 * Disclosure
	 * ----------------------------------------------------------------- */

	/**
	 * A package produced on a configured site carries no secret material.
	 *
	 * @return void
	 */
	public function test_an_export_carries_no_credential_or_provider_material() {
		update_option( 'mclogiora_suggestion_key_openai', 'sk-test-not-a-real-key-000' );
		update_option( 'mclogiora_suggestions_enabled', 1 );
		update_option( 'mclogiora_suggestions_provider', 'openai' );
		update_option( 'mclogiora_suggestion_model_openai', 'gpt-test' );

		$encoded = ( new PackageEncoder() )->encode( $this->exporter->export() );

		foreach (
			array(
				'sk-test-not-a-real-key-000',
				'api_key',
				'apikey',
				'credential',
				'Authorization',
				'DeepL-Auth-Key',
				'MCLOGIORA_OPENAI_API_KEY',
				'MCLOGIORA_ANTHROPIC_API_KEY',
				'MCLOGIORA_GEMINI_API_KEY',
				'MCLOGIORA_DEEPL_API_KEY',
				'mclogiora_suggestion_key_',
				'mclogiora_suggestions_provider',
				'nonce',
				'token',
				'secret',
				'password',
			) as $needle
		) {
			$this->assertStringNotContainsString( $needle, $encoded, $needle . ' must never appear in a package' );
		}

		delete_option( 'mclogiora_suggestion_key_openai' );
		delete_option( 'mclogiora_suggestions_enabled' );
		delete_option( 'mclogiora_suggestions_provider' );
		delete_option( 'mclogiora_suggestion_model_openai' );
	}

	/**
	 * A package carries no internal identifier, table name or class name.
	 *
	 * @return void
	 */
	public function test_an_export_carries_no_internal_identity() {
		global $wpdb;

		$encoded = ( new PackageEncoder() )->encode( $this->exporter->export() );

		foreach (
			array(
				'source_hash',
				'translated_source_hash',
				'source_modified',
				'translation_modified',
				'object_id',
				'object_key',
				'term_taxonomy_id',
				'McLogiora\\',
				'wpdb',
				$wpdb->prefix . 'mclogiora',
				$wpdb->posts,
				ABSPATH,
			) as $needle
		) {
			$this->assertStringNotContainsString( $needle, $encoded, $needle . ' must never appear in a package' );
		}
	}

	/* --------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Creates the translated content the assertions read.
	 *
	 * @return void
	 */
	private function build_fixture() {
		$workflows = $this->container->get( TranslationWorkflowService::class );

		$parent_en = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Company',
				'post_name'   => 'company',
			)
		);

		$this->fixture['page_en'] = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'About',
				'post_name'   => 'about',
				'post_parent' => $parent_en,
			)
		);

		$this->fixture['page_tr'] = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Hakkimizda',
				'post_name'   => 'hakkimizda',
				'post_parent' => $parent_en,
			)
		);

		$this->fixture['post_en'] = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Hello',
				'post_name'   => 'hello',
			)
		);

		$this->fixture['post_tr'] = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Merhaba',
				'post_name'   => 'merhaba',
			)
		);

		$this->fixture['draft_source'] = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Roadmap',
				'post_name'   => 'roadmap',
			)
		);

		$term_en = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'News',
				'slug'     => 'news',
			)
		);
		$term_tr = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Haberler',
				'slug'     => 'haberler',
			)
		);

		$this->fixture['term_en'] = (int) $term_en;
		$this->fixture['term_tr'] = (int) $term_tr;

		$this->assertIsArray( $workflows->content()->link_existing( $this->fixture['page_en'], $this->fixture['page_tr'], 'tr' ) );
		$this->assertIsArray( $workflows->content()->link_existing( $this->fixture['post_en'], $this->fixture['post_tr'], 'tr' ) );
		$this->assertIsArray( $workflows->taxonomy()->link_existing( $this->fixture['term_en'], 'category', $this->fixture['term_tr'], 'tr' ) );
	}

	/**
	 * Returns the group key an object belongs to.
	 *
	 * @param int    $object_id Object identifier.
	 * @param string $object_type Relation object type.
	 * @return string
	 */
	private function group_key_for( $object_id, $object_type = 'post' ) {
		$repository = $this->container->get( TranslationRelationRepositoryInterface::class );

		foreach ( $repository->active_group_keys( 100, 0 ) as $key ) {
			foreach ( $repository->find_group( $key )->items() as $item ) {
				if ( $item->content_type() === $object_type && (int) $item->object_key() === (int) $object_id ) {
					return $key;
				}
			}
		}

		$this->fail( 'No group found for ' . $object_type . ' ' . $object_id );
	}

	/**
	 * Returns one exported relation item.
	 *
	 * @param string $group_key Group key.
	 * @param string $language Language code.
	 * @return array<string,mixed>
	 */
	private function exported_item( $group_key, $language ) {
		foreach ( $this->exporter->export()->to_array()['payload']['relations'] as $group ) {
			if ( $group['group_key'] !== $group_key ) {
				continue;
			}

			foreach ( $group['items'] as $item ) {
				if ( $item['language'] === $language ) {
					return $item;
				}
			}
		}

		$this->fail( 'No exported ' . $language . ' item in group ' . $group_key );
	}

	/**
	 * Returns a deterministic snapshot of everything an export could disturb.
	 *
	 * @return array<string,mixed>
	 */
	private function site_snapshot() {
		global $wpdb;

		$tables   = $this->container->get( TableNames::class );
		$snapshot = array();

		foreach ( $tables->all() as $table ) {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . $table, ARRAY_A ); // phpcs:ignore WordPress.DB -- integration snapshot.

			$snapshot[ $table ] = md5( (string) wp_json_encode( is_array( $rows ) ? $rows : array() ) );
		}

		$snapshot['posts'] = md5(
			(string) wp_json_encode(
				$wpdb->get_results( "SELECT ID, post_type, post_status, post_name, post_parent, post_title, post_modified_gmt FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ) // phpcs:ignore WordPress.DB -- integration snapshot.
			)
		);
		$snapshot['terms'] = md5(
			(string) wp_json_encode(
				$wpdb->get_results( "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.parent, tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id ORDER BY t.term_id", ARRAY_A ) // phpcs:ignore WordPress.DB -- integration snapshot.
			)
		);
		$snapshot['options'] = md5(
			(string) wp_json_encode(
				$wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'mclogiora%' ORDER BY option_name", ARRAY_A ) // phpcs:ignore WordPress.DB -- integration snapshot.
			)
		);

		return $snapshot;
	}
}
