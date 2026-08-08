<?php
/**
 * Content translation service.
 *
 * @package McLogiora
 */

namespace McLogiora\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Placeholder content translation service.
 */
final class ContentTranslationService implements ContentTranslationServiceInterface {
	/**
	 * Content type registry.
	 *
	 * @var ContentTypeRegistryInterface
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param ContentTypeRegistryInterface $registry Content type registry.
	 */
	public function __construct( ContentTypeRegistryInterface $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Returns whether a content type is translatable.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function is_content_type_translatable( $post_type ) {
		return $this->registry->is_translatable( $post_type );
	}

	/**
	 * Returns translatable content types.
	 *
	 * @return TranslatableContentType[]
	 */
	public function get_translatable_content_types() {
		return $this->registry->translatable();
	}

	/**
	 * Returns excluded content types.
	 *
	 * @return TranslatableContentType[]
	 */
	public function get_excluded_content_types() {
		return $this->registry->excluded();
	}

	/**
	 * Returns a read-only support overview.
	 *
	 * @return array<string, int>
	 */
	public function get_support_overview() {
		return array(
			'translatable' => count( $this->get_translatable_content_types() ),
			'excluded'     => count( $this->get_excluded_content_types() ),
		);
	}
}
