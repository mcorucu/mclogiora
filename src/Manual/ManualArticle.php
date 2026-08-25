<?php
/**
 * Structured local manual article.
 *
 * @package McLogiora
 */

namespace McLogiora\Manual;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only article value object.
 */
final class ManualArticle {
	/** @var array<string,mixed> */
	private $data;

	/**
	 * @param array<string,mixed> $data Article data.
	 */
	public function __construct( array $data ) {
		$this->data = array(
			'slug'             => sanitize_key( isset( $data['slug'] ) ? $data['slug'] : '' ),
			'title'            => isset( $data['title'] ) ? (string) $data['title'] : '',
			'summary'          => isset( $data['summary'] ) ? (string) $data['summary'] : '',
			'category'         => isset( $data['category'] ) ? (string) $data['category'] : 'Help',
			'keywords'         => isset( $data['keywords'] ) && is_array( $data['keywords'] ) ? array_values( $data['keywords'] ) : array(),
			'sections'         => isset( $data['sections'] ) && is_array( $data['sections'] ) ? array_values( $data['sections'] ) : array(),
			'related_articles' => isset( $data['related_articles'] ) && is_array( $data['related_articles'] ) ? array_values( $data['related_articles'] ) : array(),
		);
	}

	/** @return string */
	public function slug() { return $this->data['slug']; }
	/** @return string */
	public function title() { return $this->data['title']; }
	/** @return string */
	public function summary() { return $this->data['summary']; }
	/** @return string */
	public function category() { return $this->data['category']; }
	/** @return string[] */
	public function keywords() { return $this->data['keywords']; }
	/** @return array<int,array<string,mixed>> */
	public function sections() { return $this->data['sections']; }
	/** @return string[] */
	public function related_articles() { return $this->data['related_articles']; }

	/**
	 * Returns plain text used by the local index.
	 *
	 * @return string
	 */
	public function searchable_text() {
		$parts = array( $this->title(), $this->summary(), implode( ' ', $this->keywords() ) );

		foreach ( $this->sections() as $section ) {
			if ( isset( $section['heading'] ) ) {
				$parts[] = (string) $section['heading'];
			}

			if ( isset( $section['text'] ) ) {
				$parts[] = (string) $section['text'];
			}

			if ( isset( $section['items'] ) && is_array( $section['items'] ) ) {
				$parts[] = implode( ' ', array_map( 'strval', $section['items'] ) );
			}
		}

		return implode( ' ', $parts );
	}
}
