<?php
/**
 * Local manual search tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Unit;

use McLogiora\Manual\ManualArticle;
use McLogiora\Manual\ManualSearch;
use PHPUnit\Framework\TestCase;

/**
 * Verifies local, deterministic article search.
 */
final class ManualSearchTest extends TestCase {
	/**
	 * Search matches title, body, and keywords without a network.
	 *
	 * @return void
	 */
	public function test_search_matches_article_content_and_prioritizes_title() {
		$languages = new ManualArticle(
			array(
				'slug'     => 'languages',
				'title'    => 'Choosing languages',
				'summary'  => 'Select primary and target languages.',
				'keywords' => array( 'tr_TR', 'en_US' ),
				'sections' => array( array( 'type' => 'paragraph', 'text' => 'The picker resolves locale metadata.' ) ),
			)
		);
		$manager = new ManualArticle(
			array(
				'slug'     => 'manager',
				'title'    => 'Translation Manager',
				'summary'  => 'Inspect missing translations.',
				'sections' => array( array( 'type' => 'paragraph', 'text' => 'Search the inventory.' ) ),
			)
		);

		$results = ManualSearch::search( array( $manager, $languages ), 'en_US' );
		$this->assertCount( 1, $results );
		$this->assertSame( 'languages', $results[0]->slug() );

		$results = ManualSearch::search( array( $manager, $languages ), 'translation manager' );
		$this->assertSame( 'manager', $results[0]->slug() );
	}
}
