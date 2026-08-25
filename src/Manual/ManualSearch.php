<?php
/**
 * Local manual search.
 *
 * @package McLogiora
 */

namespace McLogiora\Manual;

defined( 'ABSPATH' ) || exit;

/**
 * Searches the bundled registry without a database or network request.
 */
final class ManualSearch {
	/**
	 * Searches title, summary, keywords, and structured article text.
	 *
	 * @param ManualArticle[] $articles Articles.
	 * @param string          $query Query.
	 * @return ManualArticle[]
	 */
	public static function search( array $articles, $query ) {
		$query = self::normalize( $query );

		if ( '' === $query ) {
			return $articles;
		}

		$terms  = preg_split( '/\s+/', $query );
		$ranked = array();

		foreach ( $articles as $article ) {
			if ( ! $article instanceof ManualArticle ) {
				continue;
			}

			$text  = self::normalize( $article->searchable_text() );
			$score = 0;

			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( '' === $term || false === strpos( $text, $term ) ) {
					$score = -1;
					break;
				}

				if ( false !== strpos( self::normalize( $article->title() ), $term ) ) {
					$score += 3;
				} else {
					++$score;
				}
			}

			if ( $score >= 0 ) {
				$ranked[] = array(
					'score'   => $score,
					'article' => $article,
				);
			}
		}

		usort(
			$ranked,
			static function ( $left, $right ) {
				if ( $left['score'] === $right['score'] ) {
					return strcmp( $left['article']->title(), $right['article']->title() );
				}

				return $left['score'] < $right['score'] ? 1 : -1;
			}
		);

		return array_map(
			static function ( $entry ) {
				return $entry['article'];
			},
			$ranked
		);
	}

	/**
	 * Normalizes text for deterministic local matching.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function normalize( $value ) {
		$value = (string) $value;

		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$value = wp_strip_all_tags( $value );
		}

		$value = strtolower( trim( $value ) );

		return function_exists( 'remove_accents' ) ? remove_accents( $value ) : $value;
	}
}
