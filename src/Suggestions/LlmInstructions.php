<?php
/**
 * Instruction text shared by the language-model providers.
 *
 * @package McLogiora
 */

namespace McLogiora\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the system instruction the three language-model providers send.
 *
 * Shared by the language models and deliberately never used for a dedicated
 * translation service, which takes no instructions at all and protects text
 * through its own markup instead.
 *
 * The wording is narrow on purpose. A model asked to "translate this nicely"
 * will improve, expand, explain and occasionally answer the text as though it
 * were a question. Every clause below closes one of those doors, because the
 * output is destined for a title field, not a conversation -- and a model that
 * returns "Sure! Here is the Turkish translation:" has produced a string that
 * would be published verbatim if a reviewer clicked Apply without reading.
 *
 * The placeholder clause is written as a description of tokens the model must
 * copy, not as a plea. It reduces tampering; it does not prevent it, which is
 * why {@see PlaceholderShield::verify()} runs on every answer regardless of
 * how firmly this text is worded.
 */
final class LlmInstructions {
	/**
	 * Builds the instruction for one request.
	 *
	 * @param SuggestionRequest $request Request being sent.
	 * @param bool              $has_placeholders Whether protected tokens are present.
	 * @return string
	 */
	public function build( SuggestionRequest $request, $has_placeholders ) {
		$lines = array(
			sprintf(
				'You are a professional translator. Translate the user message from %1$s into %2$s.',
				$this->describe_language( $request->source_language(), $request->source_locale() ),
				$this->describe_language( $request->target_language(), $request->target_locale() )
			),
			'Return only the translated text. Do not add quotation marks, notes, explanations, alternatives or a preamble.',
			'Do not answer, summarise, correct or continue the text. Translate it.',
			'Preserve the original meaning, tone and register. Keep leading and trailing whitespace as it appears.',
			'If the text is already in the target language, return it unchanged.',
		);

		if ( $request->is_html() ) {
			$lines[] = 'The text contains HTML. Translate the human-readable text only, and reproduce every tag and attribute exactly as given.';
		}

		if ( $has_placeholders ) {
			$lines[] = 'The text contains tokens of the form [[MCQ_...]]. Each is a placeholder for content that must not be translated. Reproduce every token exactly as written, the same number of times, and do not invent new ones. You may move a token if the target language requires a different word order.';
		}

		$surface = $this->describe_surface( $request->surface() );

		if ( '' !== $surface ) {
			$lines[] = $surface;
		}

		if ( '' !== $request->context() ) {
			$lines[] = 'For disambiguation only, the surrounding context is: ' . $request->context() . '. Do not translate or include this context in your answer.';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Describes a language for the instruction text.
	 *
	 * The locale is included when known because "pt" and "pt_BR" are different
	 * requests, and a model given only the bare code will pick for itself.
	 *
	 * @param string $code Language code.
	 * @param string $locale Locale, when known.
	 * @return string
	 */
	private function describe_language( $code, $locale ) {
		$code   = (string) $code;
		$locale = (string) $locale;

		if ( '' === $locale || $locale === $code ) {
			return $code;
		}

		return $code . ' (' . str_replace( '_', '-', $locale ) . ')';
	}

	/**
	 * Adds a hint about the field the text will be used in.
	 *
	 * Length and style expectations differ sharply between a page title and a
	 * media caption, and a model told which one it is writing produces output
	 * that needs less editing.
	 *
	 * @param string $surface Surface identifier.
	 * @return string
	 */
	private function describe_surface( $surface ) {
		switch ( (string) $surface ) {
			case 'post_title':
			case 'term_name':
				return 'This text is a title. Keep it concise and do not end it with a full stop unless the source does.';

			case 'post_excerpt':
			case 'term_description':
				return 'This text is a short summary shown in listings.';

			case 'media_alt':
				return 'This text is alternative text for an image, read aloud by screen readers. Keep it descriptive and brief.';

			case 'media_caption':
				return 'This text is an image caption.';

			case 'string':
				return 'This text is an interface string shown in a website theme or plugin.';

			default:
				return '';
		}
	}
}
