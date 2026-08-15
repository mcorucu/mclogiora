<?php
/**
 * A stored, reviewable translation suggestion.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * One generated suggestion, held server-side until it is applied or discarded.
 *
 * ## Why the text is kept on the server at all
 *
 * The obvious design is to send the suggestion to the browser, let the user
 * look at it, and post it back on Apply. That design is wrong, and quietly so:
 * the text that comes back is whatever the browser chose to send. Anyone able
 * to make a request as the logged-in user could hold a legitimate token and
 * substitute completely different content, and the server would write it
 * because the token checked out.
 *
 * So the browser is told a token and shown the text; the token is what comes
 * back. The server applies what it generated, not what it was handed.
 *
 * ## The binding is the authorization, not the token
 *
 * A token proves someone saw a suggestion. It does not prove they may write it
 * to a particular field of a particular object. Every fact needed for that
 * decision is bound here at creation, and revalidated on Apply: a preview for
 * one user cannot be applied by another, one for a term cannot be applied to
 * the post with the same numeric ID, and one for a title cannot be redirected
 * into an excerpt.
 *
 * `object_type` earns its place on that last point. Without it, target ID 5
 * means "post 5" and "term 5" and "attachment 5" at once, and a preview
 * generated for one could be applied to another.
 */
final class SuggestionPreview {
	/**
	 * Opaque token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Suggested text, as generated and verified server-side.
	 *
	 * @var string
	 */
	private $text;

	/**
	 * Provider identifier.
	 *
	 * @var string
	 */
	private $provider_id;

	/**
	 * Model identifier, empty for providers without one.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Surface, which in Phase 16 is also the target field.
	 *
	 * @var string
	 */
	private $surface;

	/**
	 * Kind of object being translated.
	 *
	 * @var string
	 */
	private $object_type;

	/**
	 * Source object identifier.
	 *
	 * @var string
	 */
	private $source_id;

	/**
	 * Target object identifier.
	 *
	 * @var string
	 */
	private $target_id;

	/**
	 * Source language code.
	 *
	 * @var string
	 */
	private $source_language;

	/**
	 * Target language code.
	 *
	 * @var string
	 */
	private $target_language;

	/**
	 * Identifier of the user who generated it.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Creation timestamp.
	 *
	 * @var int
	 */
	private $created_at;

	/**
	 * Expiry timestamp.
	 *
	 * @var int
	 */
	private $expires_at;

	/**
	 * Builds a preview.
	 *
	 * @param array<string,mixed> $data Preview fields.
	 */
	public function __construct( array $data ) {
		$this->token           = isset( $data['token'] ) ? (string) $data['token'] : '';
		$this->text            = isset( $data['text'] ) ? (string) $data['text'] : '';
		$this->provider_id     = isset( $data['provider_id'] ) ? (string) $data['provider_id'] : '';
		$this->model           = isset( $data['model'] ) ? (string) $data['model'] : '';
		$this->surface         = isset( $data['surface'] ) ? (string) $data['surface'] : '';
		$this->object_type     = isset( $data['object_type'] ) ? (string) $data['object_type'] : '';
		$this->source_id       = isset( $data['source_id'] ) ? (string) $data['source_id'] : '';
		$this->target_id       = isset( $data['target_id'] ) ? (string) $data['target_id'] : '';
		$this->source_language = isset( $data['source_language'] ) ? (string) $data['source_language'] : '';
		$this->target_language = isset( $data['target_language'] ) ? (string) $data['target_language'] : '';
		$this->user_id         = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
		$this->created_at      = isset( $data['created_at'] ) ? (int) $data['created_at'] : 0;
		$this->expires_at      = isset( $data['expires_at'] ) ? (int) $data['expires_at'] : 0;
	}

	/**
	 * Returns the token.
	 *
	 * @return string
	 */
	public function token() {
		return $this->token;
	}

	/**
	 * Returns the suggested text.
	 *
	 * @return string
	 */
	public function text() {
		return $this->text;
	}

	/**
	 * Returns the provider identifier.
	 *
	 * @return string
	 */
	public function provider_id() {
		return $this->provider_id;
	}

	/**
	 * Returns the model identifier.
	 *
	 * @return string
	 */
	public function model() {
		return $this->model;
	}

	/**
	 * Returns the surface, which is also the target field.
	 *
	 * @return string
	 */
	public function surface() {
		return $this->surface;
	}

	/**
	 * Returns the kind of object being translated.
	 *
	 * @return string
	 */
	public function object_type() {
		return $this->object_type;
	}

	/**
	 * Returns the source object identifier.
	 *
	 * @return string
	 */
	public function source_id() {
		return $this->source_id;
	}

	/**
	 * Returns the target object identifier.
	 *
	 * @return string
	 */
	public function target_id() {
		return $this->target_id;
	}

	/**
	 * Returns the source language code.
	 *
	 * @return string
	 */
	public function source_language() {
		return $this->source_language;
	}

	/**
	 * Returns the target language code.
	 *
	 * @return string
	 */
	public function target_language() {
		return $this->target_language;
	}

	/**
	 * Returns the identifier of the user who generated it.
	 *
	 * @return int
	 */
	public function user_id() {
		return $this->user_id;
	}

	/**
	 * Returns the creation timestamp.
	 *
	 * @return int
	 */
	public function created_at() {
		return $this->created_at;
	}

	/**
	 * Returns the expiry timestamp.
	 *
	 * @return int
	 */
	public function expires_at() {
		return $this->expires_at;
	}

	/**
	 * Returns whether the preview belongs to the given context.
	 *
	 * Every fact is compared, and the comparison is strict. A caller that gets
	 * any one of them wrong is either confused or hostile, and the two are
	 * indistinguishable from here, so both are refused identically.
	 *
	 * @param int    $user_id Current user identifier.
	 * @param string $object_type Expected object kind.
	 * @param string $target_id Expected target identifier.
	 * @param string $surface Expected surface.
	 * @param string $target_language Expected target language.
	 * @return bool
	 */
	public function belongs_to( $user_id, $object_type, $target_id, $surface, $target_language ) {
		return $this->user_id === (int) $user_id
			&& $this->object_type === (string) $object_type
			&& $this->target_id === (string) $target_id
			&& $this->surface === (string) $surface
			&& $this->target_language === (string) $target_language;
	}

	/**
	 * Returns the preview as a storable array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'token'           => $this->token,
			'text'            => $this->text,
			'provider_id'     => $this->provider_id,
			'model'           => $this->model,
			'surface'         => $this->surface,
			'object_type'     => $this->object_type,
			'source_id'       => $this->source_id,
			'target_id'       => $this->target_id,
			'source_language' => $this->source_language,
			'target_language' => $this->target_language,
			'user_id'         => $this->user_id,
			'created_at'      => $this->created_at,
			'expires_at'      => $this->expires_at,
		);
	}
}
