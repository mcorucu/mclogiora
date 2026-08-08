<?php
/**
 * Translation content type constants.
 *
 * @package McLogiora
 */

namespace McLogiora\Relations;

defined( 'ABSPATH' ) || exit;

/**
 * Defines translatable object types for relation records.
 */
final class ContentType {
	const POST = 'post';
	const TERM = 'term';
	const STRING = 'string';
	const MEDIA = 'media';
	const MENU = 'menu';
	const WIDGET = 'widget';
	const FUTURE = 'future';

	/**
	 * Returns foundation content types.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::POST,
			self::TERM,
			self::STRING,
			self::MEDIA,
			self::MENU,
			self::WIDGET,
			self::FUTURE,
		);
	}

	/**
	 * Returns whether a content type is supported.
	 *
	 * @param string $content_type Content type.
	 * @return bool
	 */
	public static function is_valid( $content_type ) {
		$content_type = sanitize_key( (string) $content_type );

		return 1 === preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $content_type );
	}
}
