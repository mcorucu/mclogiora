<?php
/**
 * The fields translation suggestions may be generated for.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * The allow-list of translatable fields, and what may be sent for each.
 *
 * An allow-list rather than a deny-list. A deny-list would mean that adding a
 * new field somewhere in the plugin silently makes it translatable by a third
 * party, and the failure mode of getting that wrong is content leaving the
 * site that nobody meant to send.
 *
 * ## Why `post_content` is absent
 *
 * Raw post content is deliberately not here, and its absence is a decision
 * rather than an omission. A post body is serialised Gutenberg blocks, or an
 * Elementor document, or a Beaver Builder payload -- structures where the
 * human-readable text is interleaved with markup, attribute JSON and
 * block delimiters that must survive byte-for-byte. Phase 15 proved those
 * structures survive translation *because mcLogiora copies them untouched*.
 * Handing one to a language model and reassembling the answer is a different
 * problem, and one that fails silently: a corrupted block delimiter does not
 * throw, it renders the page as literal HTML comments.
 *
 * That work needs an extraction and reassembly layer proven on its own terms,
 * against real serialised blocks from every builder Phase 15 qualified. Until
 * that exists, offering document translation would trade a proven guarantee
 * for a feature count. It is documented as deferred rather than shipped
 * partially, because "we translate your pages, mostly" is a worse promise than
 * "we translate these fields, exactly".
 */
final class SuggestionSurface {
	/**
	 * Post, page or custom post type title.
	 */
	const POST_TITLE = 'post_title';

	/**
	 * Post, page or custom post type excerpt.
	 */
	const POST_EXCERPT = 'post_excerpt';

	/**
	 * Taxonomy term name.
	 */
	const TERM_NAME = 'term_name';

	/**
	 * Taxonomy term description.
	 */
	const TERM_DESCRIPTION = 'term_description';

	/**
	 * String Manager translation.
	 */
	const STRING = 'string';

	/**
	 * Attachment title.
	 */
	const MEDIA_TITLE = 'media_title';

	/**
	 * Attachment alternative text.
	 */
	const MEDIA_ALT = 'media_alt';

	/**
	 * Attachment caption.
	 */
	const MEDIA_CAPTION = 'media_caption';

	/**
	 * Attachment description.
	 */
	const MEDIA_DESCRIPTION = 'media_description';

	/**
	 * Returns every supported surface.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array(
			self::POST_TITLE,
			self::POST_EXCERPT,
			self::TERM_NAME,
			self::TERM_DESCRIPTION,
			self::STRING,
			self::MEDIA_TITLE,
			self::MEDIA_ALT,
			self::MEDIA_CAPTION,
			self::MEDIA_DESCRIPTION,
		);
	}

	/**
	 * Returns whether a surface may be suggested for.
	 *
	 * @param string $surface Surface identifier.
	 * @return bool
	 */
	public static function is_supported( $surface ) {
		return in_array( (string) $surface, self::all(), true );
	}

	/**
	 * Returns whether a surface's text may contain markup.
	 *
	 * Only the long-form fields do. Treating a title as HTML would make a
	 * model preserve angle brackets a user typed as literal characters.
	 *
	 * @param string $surface Surface identifier.
	 * @return bool
	 */
	public static function allows_html( $surface ) {
		return in_array(
			(string) $surface,
			array( self::POST_EXCERPT, self::TERM_DESCRIPTION, self::MEDIA_CAPTION, self::MEDIA_DESCRIPTION ),
			true
		);
	}
}
