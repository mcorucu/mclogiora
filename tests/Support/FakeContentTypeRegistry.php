<?php
/**
 * In-memory content type registry for tests.
 *
 * @package McLogiora
 */

namespace McLogiora\Tests\Support;

use McLogiora\Content\ContentTypeRegistryInterface;
use McLogiora\Content\TranslatableContentType;

/**
 * Serves a fixed set of content types.
 */
final class FakeContentTypeRegistry implements ContentTypeRegistryInterface {
	/**
	 * Translatable types.
	 *
	 * @var TranslatableContentType[]
	 */
	private $translatable;

	/**
	 * Excluded types.
	 *
	 * @var TranslatableContentType[]
	 */
	private $excluded;

	/**
	 * Constructor.
	 *
	 * @param TranslatableContentType[] $translatable Translatable types.
	 * @param TranslatableContentType[] $excluded Excluded types.
	 */
	public function __construct( array $translatable, array $excluded = array() ) {
		$this->translatable = $translatable;
		$this->excluded     = $excluded;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return TranslatableContentType[]
	 */
	public function all() {
		return array_merge( $this->translatable, $this->excluded );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return TranslatableContentType[]
	 */
	public function translatable() {
		return $this->translatable;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return TranslatableContentType[]
	 */
	public function excluded() {
		return $this->excluded;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function is_translatable( $post_type ) {
		foreach ( $this->translatable as $type ) {
			if ( $type->name() === (string) $post_type ) {
				return true;
			}
		}

		return false;
	}
}
