<?php
/**
 * Request language context contract.
 *
 * @package McLogiora
 */

namespace McLogiora\Routing;

use McLogiora\Languages\Language;

defined( 'ABSPATH' ) || exit;

/**
 * The single authoritative answer to "what language is this request?".
 *
 * Phases 10 and 11 deliberately never asked this question: every service they
 * built takes an explicitly named language. This interface is where that
 * question is finally answered, and it must remain the only place. A second
 * resolver would eventually disagree with this one, and the two would produce
 * different languages for the same request -- content in one language, menu in
 * another, strings in a third.
 *
 * Everything that needs the current language depends on this contract.
 */
interface LanguageContextInterface {
	/**
	 * Returns the language for the current request.
	 *
	 * Falls back to the default language, so this never returns null on a
	 * configured site.
	 *
	 * @return Language|null
	 */
	public function current();

	/**
	 * Returns the current language code, or an empty string when unconfigured.
	 *
	 * @return string
	 */
	public function current_code();

	/**
	 * Returns the site default language.
	 *
	 * @return Language|null
	 */
	public function default_language();

	/**
	 * Returns the language code the request explicitly asked for.
	 *
	 * Empty when the request carried no language prefix, which is how a
	 * default-language URL is distinguished from an explicit one.
	 *
	 * @return string
	 */
	public function requested_code();

	/**
	 * Returns whether the current language is the site default.
	 *
	 * @return bool
	 */
	public function is_default();

	/**
	 * Returns the active languages available for routing.
	 *
	 * @return Language[]
	 */
	public function available();

	/**
	 * Returns whether a language code is an active, routable language.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public function is_routable( $code );
}
