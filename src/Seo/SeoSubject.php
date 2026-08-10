<?php
/**
 * The thing a request is about, for SEO purposes.
 *
 * @package McLogiora
 */

namespace McLogiora\Seo;

use McLogiora\Relations\ContentType;

defined( 'ABSPATH' ) || exit;

/**
 * Names what the current request resolves to, or says it resolves to nothing.
 *
 * Both canonical and `hreflang` need the same answer, and they must agree. A
 * page whose canonical says it is one thing and whose alternates describe
 * another is worse than a page with neither, so the question is asked once and
 * the answer is shared.
 *
 * Deliberately narrow. Search results, date archives, author archives, and
 * paginated feeds are not represented: mcLogiora has no translated equivalent
 * to point at for them, and inventing one would produce URLs that either do not
 * resolve or resolve to the wrong content.
 */
final class SeoSubject {
	const HOME = 'home';

	/**
	 * Subject kind: a relation content type, or HOME.
	 *
	 * @var string
	 */
	private $kind;

	/**
	 * Object identifier, zero for the home subject.
	 *
	 * @var int
	 */
	private $object_id;

	/**
	 * Taxonomy name for term subjects.
	 *
	 * @var string
	 */
	private $taxonomy;

	/**
	 * Constructor.
	 *
	 * @param string $kind Subject kind.
	 * @param int    $object_id Object identifier.
	 * @param string $taxonomy Taxonomy name.
	 */
	private function __construct( $kind, $object_id = 0, $taxonomy = '' ) {
		$this->kind      = (string) $kind;
		$this->object_id = (int) $object_id;
		$this->taxonomy  = (string) $taxonomy;
	}

	/**
	 * Returns a post subject.
	 *
	 * @param int $post_id Post identifier.
	 * @return self
	 */
	public static function post( $post_id ) {
		return new self( ContentType::POST, (int) $post_id );
	}

	/**
	 * Returns a term subject.
	 *
	 * @param int    $term_id Term identifier.
	 * @param string $taxonomy Taxonomy name.
	 * @return self
	 */
	public static function term( $term_id, $taxonomy ) {
		return new self( ContentType::TERM, (int) $term_id, (string) $taxonomy );
	}

	/**
	 * Returns the site home subject.
	 *
	 * @return self
	 */
	public static function home() {
		return new self( self::HOME );
	}

	/**
	 * Returns the subject kind.
	 *
	 * @return string
	 */
	public function kind() {
		return $this->kind;
	}

	/**
	 * Returns the object identifier.
	 *
	 * @return int
	 */
	public function object_id() {
		return $this->object_id;
	}

	/**
	 * Returns the taxonomy name.
	 *
	 * @return string
	 */
	public function taxonomy() {
		return $this->taxonomy;
	}

	/**
	 * Returns whether this is the home subject.
	 *
	 * @return bool
	 */
	public function is_home() {
		return self::HOME === $this->kind;
	}
}
