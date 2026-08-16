<?php
/**
 * Attachment translation fields.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Media\MediaTranslation;
use McLogiora\Media\MediaTranslationService;
use McLogiora\Suggestions\SuggestionSurface;

defined( 'ABSPATH' ) || exit;

/**
 * Adds language-specific metadata fields to the attachment edit screen.
 *
 * Uses the native attachment editing screen rather than a separate media
 * translation UI, so translators work where they already edit alt text.
 * Nothing is written by simply viewing the screen.
 *
 * `edit_form_after_editor` fires inside WordPress's own `<form id="post">`, and
 * HTML does not allow a nested form: the parser discards the inner start tag and
 * adopts its fields into the post form. So the fields are declared here and
 * associated with forms printed after the post form closes, using the HTML
 * `form` attribute -- the same approach the Classic translation metabox uses for
 * its create-translation button.
 */
final class MediaTranslationFields implements ModuleInterface {
	const SUGGESTIONS_HANDLE = 'mclogiora-admin-suggestions';
	/**
	 * Effective capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * Save forms waiting to be printed outside the post form.
	 *
	 * @var array<int,array<string,string>>
	 */
	private $pending_forms = array();

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface|null
	 */
	private $languages = null;

	/**
	 * Media translation service.
	 *
	 * @var MediaTranslationService|null
	 */
	private $media = null;

	/**
	 * Suggestion state provider.
	 *
	 * @var SuggestionAdminState|null
	 */
	private $suggestions = null;

	/**
	 * Registers the attachment field hooks.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		if ( ! is_admin() ) {
			return;
		}

		$capabilities      = $container->get( CapabilityRegistry::class );
		$this->capability  = $capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS );
		$this->languages   = $container->get( LanguageServiceInterface::class );
		$this->media       = $container->get( MediaTranslationService::class );
		$this->suggestions = $container->get( SuggestionAdminState::class );

		add_action( 'edit_form_after_editor', array( $this, 'render_fields' ) );
		add_action( 'admin_footer', array( $this, 'print_pending_forms' ) );
	}

	/**
	 * Prints the save forms outside the post form.
	 *
	 * @return void
	 */
	public function print_pending_forms() {
		foreach ( $this->pending_forms as $form ) {
			printf(
				'<form id="%1$s" method="post" action="%2$s" class="mclogiora-media-translations__form">',
				esc_attr( $form['id'] ),
				esc_url( admin_url( 'admin-post.php' ) )
			);

			printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $form['action'] ) );
			printf( '<input type="hidden" name="attachment_id" value="%s" />', esc_attr( $form['attachment_id'] ) );
			printf( '<input type="hidden" name="language" value="%s" />', esc_attr( $form['language'] ) );

			echo wp_kses(
				$form['nonce'],
				array(
					'input' => array(
						'type'  => true,
						'name'  => true,
						'value' => true,
					),
				)
			);

			echo '</form>';
		}

		$this->pending_forms = array();
	}


	/**
	 * Returns the id of the control a suggestion would be applied into.
	 *
	 * @param string $form_id Owning form identifier.
	 * @param string $field Field key.
	 * @return string
	 */
	private function field_id( $form_id, $field ) {
		return $form_id . '-' . sanitize_key( $field );
	}

	/**
	 * Loads the shared suggestion script for the attachment screen only.
	 *
	 * Nothing is shipped when the feature is unavailable: the controls already
	 * explain why, and handing the browser an action list for something it may
	 * not do would be worse than useless.
	 *
	 * @return void
	 */
	private function enqueue_suggestions() {
		if ( ! $this->suggestions instanceof SuggestionAdminState ) {
			return;
		}

		$state = $this->suggestions->current();

		if ( empty( $state['available'] ) ) {
			return;
		}

		$path = MCLOGIORA_PATH . 'assets/js/admin-suggestions.js';

		wp_enqueue_script(
			self::SUGGESTIONS_HANDLE,
			MCLOGIORA_URL . 'assets/js/admin-suggestions.js',
			array( 'wp-i18n' ),
			file_exists( $path ) ? (string) filemtime( $path ) : MCLOGIORA_VERSION,
			true
		);

		wp_set_script_translations( self::SUGGESTIONS_HANDLE, 'mclogiora', MCLOGIORA_PATH . 'languages' );

		wp_add_inline_script(
			self::SUGGESTIONS_HANDLE,
			'window.mcLogioraAdminSuggestions = ' . wp_json_encode(
				array(
					'ajaxUrl'       => $state['ajaxUrl'],
					'actions'       => $state['actions'],
					'nonce'         => $state['nonce'],
					'providerLabel' => $state['providerLabel'],
					'modelLabel'    => $state['modelLabel'],
				)
			) . ';',
			'before'
		);

		wp_enqueue_style(
			self::SUGGESTIONS_HANDLE,
			MCLOGIORA_URL . 'assets/css/editor-panel.css',
			array(),
			MCLOGIORA_VERSION
		);
	}

	/**
	 * Renders the suggestion control for one translated media field.
	 *
	 * Offered for the four metadata fields and nothing else. The file, its URL,
	 * its MIME type and its dimensions are shared across every language and are
	 * not text, so no control here implies they could be translated.
	 *
	 * The control carries the attachment id, the target language and the field,
	 * never any text: the endpoint reads the attachment's own metadata itself.
	 *
	 * Every button is `type="button"` and no form is created. This screen's
	 * per-language forms are printed after the post form closes and the fields
	 * join them through the HTML `form` attribute, so a control that submitted
	 * anything would break that arrangement.
	 *
	 * @param int    $attachment_id Attachment identifier.
	 * @param string $language_code Target language code.
	 * @param string $surface Suggestion surface.
	 * @param string $field_id Identifier of the control to fill in.
	 * @param string $label Visible field label.
	 * @param string $accessible_label Accessible name for the generate button.
	 * @return void
	 */
	private function render_media_suggestion( $attachment_id, $language_code, $surface, $field_id, $label, $accessible_label ) {
		if ( ! $this->suggestions instanceof SuggestionAdminState ) {
			return;
		}

		$default = $this->languages instanceof LanguageServiceInterface
			? $this->languages->get_default_language()
			: null;

		if ( $default instanceof Language && $default->code() === $language_code ) {
			/*
			 * The attachment's own metadata is this language already. There is
			 * nothing to translate into, so no control is offered rather than one
			 * that would always be refused.
			 */
			return;
		}

		$state = $this->suggestions->current();

		if ( empty( $state['available'] ) ) {
			return;
		}

		printf(
			'<div class="mclogiora-editor__suggestions" data-mclogiora-suggest data-surface="%1$s" data-object="%2$s" data-language="%3$s" data-field="%4$s" data-field-label="%5$s">',
			esc_attr( $surface ),
			esc_attr( (string) $attachment_id ),
			esc_attr( $language_code ),
			esc_attr( $field_id ),
			esc_attr( $label )
		);

		printf(
			'<button type="button" class="button button-secondary" data-mclogiora-generate aria-label="%1$s">%2$s</button>',
			esc_attr( $accessible_label ),
			esc_html__( 'Generate suggestion', 'mclogiora' )
		);

		echo '<div class="mclogiora-editor__feedback" data-mclogiora-feedback></div>';

		echo '</div>';
	}

	/**
	 * Renders the reason suggestions are unavailable, once per language card.
	 *
	 * @return void
	 */
	private function render_media_suggestion_notice() {
		if ( ! $this->suggestions instanceof SuggestionAdminState ) {
			return;
		}

		$state = $this->suggestions->current();

		if ( ! empty( $state['available'] ) ) {
			return;
		}

		echo '<div class="mclogiora-editor__suggestions">';

		printf( '<p class="mclogiora-editor__meta">%s</p>', esc_html( $state['reason'] ) );

		if ( ! empty( $state['settingsUrl'] ) ) {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( $state['settingsUrl'] ),
				esc_html__( 'Translation Suggestions settings', 'mclogiora' )
			);
		}

		echo '</div>';
	}

	/**
	 * Renders the translation fields on the attachment edit screen.
	 *
	 * @param mixed $post Current post.
	 * @return void
	 */
	public function render_fields( $post ) {
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return;
		}

		if ( ! current_user_can( $this->capability ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$languages = $this->languages->get_active_languages();

		if ( empty( $languages ) ) {
			return;
		}

		$this->enqueue_suggestions();

		$stored = array();

		foreach ( $this->media->all_for_attachment( (int) $post->ID ) as $translation ) {
			if ( $translation instanceof MediaTranslation ) {
				$stored[ $translation->language_code() ] = $translation;
			}
		}

		?>
		<div class="mclogiora-admin mclogiora-media-translations">
			<h2><?php esc_html_e( 'mcLogiora Translations', 'mclogiora' ); ?></h2>
			<p class="mclogiora-muted-line"><?php esc_html_e( 'The file itself is shared across languages. Only this text is translated.', 'mclogiora' ); ?></p>
			<?php foreach ( $languages as $language ) : ?>
				<?php
				if ( ! $language instanceof Language ) {
					continue;
				}

				$existing = isset( $stored[ $language->code() ] ) ? $stored[ $language->code() ] : null;
				?>
				<?php
				$form_id = 'mclogiora-media-' . sanitize_key( $language->code() );

				$this->pending_forms[] = array(
					'id'            => $form_id,
					'action'        => 'mclogiora_save_media_translation',
					'attachment_id' => (string) $post->ID,
					'language'      => (string) $language->code(),
					'nonce'         => wp_nonce_field( StringActionController::NONCE_ACTION, StringActionController::NONCE_NAME, true, false ),
				);
				?>
				<div class="mclogiora-info-card">
					<h3><?php echo esc_html( $language->native_name() ); ?></h3>
					<p>
						<label>
							<span><?php esc_html_e( 'Title', 'mclogiora' ); ?></span>
							<input type="text" form="<?php echo esc_attr( $form_id ); ?>" id="<?php echo esc_attr( $this->field_id( $form_id, 'title' ) ); ?>" name="translated_title" class="widefat" value="<?php echo esc_attr( $existing instanceof MediaTranslation ? $existing->title() : '' ); ?>">
						</label>
					</p>
					<?php
					$this->render_media_suggestion(
						(int) $post->ID,
						(string) $language->code(),
						SuggestionSurface::MEDIA_TITLE,
						$this->field_id( $form_id, 'title' ),
						__( 'Media title', 'mclogiora' ),
						__( 'Generate Media title suggestion', 'mclogiora' )
					);
					?>
					<p>
						<label>
							<span><?php esc_html_e( 'Alternative text', 'mclogiora' ); ?></span>
							<input type="text" form="<?php echo esc_attr( $form_id ); ?>" id="<?php echo esc_attr( $this->field_id( $form_id, 'alt_text' ) ); ?>" name="translated_alt_text" class="widefat" value="<?php echo esc_attr( $existing instanceof MediaTranslation ? $existing->alt_text() : '' ); ?>">
						</label>
					</p>
					<?php
					$this->render_media_suggestion(
						(int) $post->ID,
						(string) $language->code(),
						SuggestionSurface::MEDIA_ALT,
						$this->field_id( $form_id, 'alt_text' ),
						__( 'Media alt text', 'mclogiora' ),
						__( 'Generate Media alt suggestion', 'mclogiora' )
					);
					?>
					<p>
						<label>
							<span><?php esc_html_e( 'Caption', 'mclogiora' ); ?></span>
							<textarea form="<?php echo esc_attr( $form_id ); ?>" id="<?php echo esc_attr( $this->field_id( $form_id, 'caption' ) ); ?>" name="translated_caption" class="widefat" rows="2"><?php echo esc_textarea( $existing instanceof MediaTranslation ? $existing->caption() : '' ); ?></textarea>
						</label>
					</p>
					<?php
					$this->render_media_suggestion(
						(int) $post->ID,
						(string) $language->code(),
						SuggestionSurface::MEDIA_CAPTION,
						$this->field_id( $form_id, 'caption' ),
						__( 'Media caption', 'mclogiora' ),
						__( 'Generate Media caption suggestion', 'mclogiora' )
					);
					?>
					<p>
						<label>
							<span><?php esc_html_e( 'Description', 'mclogiora' ); ?></span>
							<textarea form="<?php echo esc_attr( $form_id ); ?>" id="<?php echo esc_attr( $this->field_id( $form_id, 'description' ) ); ?>" name="translated_description" class="widefat" rows="3"><?php echo esc_textarea( $existing instanceof MediaTranslation ? $existing->description() : '' ); ?></textarea>
						</label>
					</p>
					<?php
					$this->render_media_suggestion(
						(int) $post->ID,
						(string) $language->code(),
						SuggestionSurface::MEDIA_DESCRIPTION,
						$this->field_id( $form_id, 'description' ),
						__( 'Media description', 'mclogiora' ),
						__( 'Generate Media description suggestion', 'mclogiora' )
					);
					?>
					<?php $this->render_media_suggestion_notice(); ?>
					<button type="submit" form="<?php echo esc_attr( $form_id ); ?>" class="button"><?php esc_html_e( 'Save Translation', 'mclogiora' ); ?></button>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
