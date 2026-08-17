<?php
/**
 * Portable object locator.
 *
 * @package McLogiora
 */

namespace McLogiora\ImportExport;

use McLogiora\Relations\ContentType;

defined( 'ABSPATH' ) || exit;

/**
 * Names a WordPress object in a way that survives leaving the site.
 *
 * A post ID and a term ID are facts about one database. Post 41 on the site
 * that produced a package and post 41 on the site reading it have nothing to
 * do with each other, so a package that carried IDs would either import into
 * the wrong content or refuse to import at all. mcLogiora therefore never puts
 * an object ID in a package, in either direction: the locator below is the
 * only identity a package has, and the destination resolves it against its own
 * content.
 *
 * The locator is deliberately made of fields WordPress itself treats as
 * addressing rather than storage. A post is found by its type and slug, and
 * inside a hierarchical type also by the slugs of its ancestors, because
 * WordPress allows `team` to exist once under `about` and again under
 * `company`. A term is found by its taxonomy and slug, which WordPress keeps
 * unique within a taxonomy.
 *
 * None of that makes resolution guaranteed, and the design does not pretend
 * otherwise. A locator can match nothing, and it can match more than one
 * object; both outcomes are reported by name rather than resolved by guessing.
 */
final class ObjectLocator {
	/**
	 * Locator kind for posts and every custom post type.
	 */
	const KIND_POST = 'post';

	/**
	 * Locator kind for taxonomy terms.
	 */
	const KIND_TERM = 'term';

	/**
	 * Locator kind.
	 *
	 * @var string
	 */
	private $kind;

	/**
	 * Post type, for post locators.
	 *
	 * @var string
	 */
	private $post_type;

	/**
	 * Taxonomy, for term locators.
	 *
	 * @var string
	 */
	private $taxonomy;

	/**
	 * Object slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Ancestor slugs from the root downwards, for hierarchical post types.
	 *
	 * @var string[]|null
	 */
	private $ancestors;

	/**
	 * Constructor.
	 *
	 * @param string        $kind Locator kind.
	 * @param string        $post_type Post type.
	 * @param string        $taxonomy Taxonomy.
	 * @param string        $slug Object slug.
	 * @param string[]|null $ancestors Ancestor slugs, or null when the type is
	 *                                 not hierarchical.
	 */
	private function __construct( $kind, $post_type, $taxonomy, $slug, ?array $ancestors = null ) {
		$this->kind      = (string) $kind;
		$this->post_type = (string) $post_type;
		$this->taxonomy  = (string) $taxonomy;
		$this->slug      = (string) $slug;
		$this->ancestors = null === $ancestors ? null : array_values( array_map( 'strval', $ancestors ) );
	}

	/**
	 * Creates a post locator.
	 *
	 * @param string        $post_type Post type.
	 * @param string        $slug Post slug.
	 * @param string[]|null $ancestors Ancestor slugs for hierarchical types.
	 * @return self
	 */
	public static function for_post( $post_type, $slug, ?array $ancestors = null ) {
		return new self( self::KIND_POST, $post_type, '', $slug, $ancestors );
	}

	/**
	 * Creates a term locator.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug Term slug.
	 * @return self
	 */
	public static function for_term( $taxonomy, $slug ) {
		return new self( self::KIND_TERM, '', $taxonomy, $slug, null );
	}

	/**
	 * Rebuilds a locator from its package representation.
	 *
	 * Returns null for anything that is not a locator of a supported kind, so
	 * a caller reading an untrusted package cannot end up with a half-built
	 * one. Structural rejection belongs to the parser; this guards the type.
	 *
	 * @param mixed $data Decoded locator.
	 * @return self|null
	 */
	public static function from_array( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['kind'] ) || ! is_string( $data['kind'] ) ) {
			return null;
		}

		$slug = isset( $data['slug'] ) && is_string( $data['slug'] ) ? $data['slug'] : '';

		if ( self::KIND_POST === $data['kind'] ) {
			$post_type = isset( $data['post_type'] ) && is_string( $data['post_type'] ) ? $data['post_type'] : '';
			$ancestors = null;

			if ( isset( $data['ancestors'] ) && is_array( $data['ancestors'] ) ) {
				$ancestors = array();

				foreach ( $data['ancestors'] as $ancestor ) {
					if ( ! is_string( $ancestor ) ) {
						return null;
					}

					$ancestors[] = $ancestor;
				}
			}

			return self::for_post( $post_type, $slug, $ancestors );
		}

		if ( self::KIND_TERM === $data['kind'] ) {
			$taxonomy = isset( $data['taxonomy'] ) && is_string( $data['taxonomy'] ) ? $data['taxonomy'] : '';

			return self::for_term( $taxonomy, $slug );
		}

		return null;
	}

	/**
	 * Returns the locator kind matching a relation content type.
	 *
	 * Relation records can name content types that format version 1 has no
	 * locator for. Returning an empty string rather than inventing one keeps
	 * that gap visible: the exporter writes a null locator, and the planner
	 * reports the item as unresolvable by name.
	 *
	 * @param string $content_type Relation content type.
	 * @return string Empty when the type has no portable locator.
	 */
	public static function kind_for_content_type( $content_type ) {
		if ( ContentType::POST === (string) $content_type ) {
			return self::KIND_POST;
		}

		if ( ContentType::TERM === (string) $content_type ) {
			return self::KIND_TERM;
		}

		return '';
	}

	/**
	 * Returns the locator kind.
	 *
	 * @return string
	 */
	public function kind() {
		return $this->kind;
	}

	/**
	 * Returns the post type.
	 *
	 * @return string
	 */
	public function post_type() {
		return $this->post_type;
	}

	/**
	 * Returns the taxonomy.
	 *
	 * @return string
	 */
	public function taxonomy() {
		return $this->taxonomy;
	}

	/**
	 * Returns the slug.
	 *
	 * @return string
	 */
	public function slug() {
		return $this->slug;
	}

	/**
	 * Returns the ancestor slugs, or null for a non-hierarchical type.
	 *
	 * @return string[]|null
	 */
	public function ancestors() {
		return $this->ancestors;
	}

	/**
	 * Returns whether the locator carries enough to be resolved at all.
	 *
	 * An empty slug is the case that matters in practice. WordPress leaves
	 * `post_name` empty on drafts until they are published, and mcLogiora
	 * creates translations as drafts, so a package can legitimately contain an
	 * object that has no portable address yet. That is a real limit of the
	 * format and is reported as one.
	 *
	 * @return bool
	 */
	public function is_complete() {
		if ( '' === $this->slug ) {
			return false;
		}

		if ( self::KIND_POST === $this->kind ) {
			return '' !== $this->post_type;
		}

		return '' !== $this->taxonomy;
	}

	/**
	 * Returns a short human-readable form for plan messages.
	 *
	 * @return string
	 */
	public function describe() {
		if ( self::KIND_POST === $this->kind ) {
			$path = null === $this->ancestors || array() === $this->ancestors
				? $this->slug
				: implode( '/', $this->ancestors ) . '/' . $this->slug;

			return $this->post_type . ':' . $path;
		}

		return $this->taxonomy . ':' . $this->slug;
	}

	/**
	 * Returns the package representation.
	 *
	 * Keys are written in a fixed order, and the optional `ancestors` key is
	 * present only for hierarchical post types. Two exports of unchanged state
	 * therefore produce identical bytes.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		if ( self::KIND_TERM === $this->kind ) {
			return array(
				'kind'     => $this->kind,
				'taxonomy' => $this->taxonomy,
				'slug'     => $this->slug,
			);
		}

		$data = array(
			'kind'      => $this->kind,
			'post_type' => $this->post_type,
			'slug'      => $this->slug,
		);

		if ( null !== $this->ancestors ) {
			$data['ancestors'] = $this->ancestors;
		}

		return $data;
	}
}
