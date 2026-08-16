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
	 * Registers the attachment field hooks.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		if ( ! is_admin() ) {
			return;
		}

		$capabilities     = $container->get( CapabilityRegistry::class );
		$this->capability = $capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS );
		$this->languages  = $container->get( LanguageServiceInterface::class );
		$this->media      = $container->get( MediaTranslationService::class );

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
							<input type="text" form="<?php echo esc_attr( $form_id ); ?>" name="translated_title" class="widefat" value="<?php echo esc_attr( $existing instanceof MediaTranslation ? $existing->title() : '' ); ?>">
						</label>
					</p>
					<p>
						<label>
							<span><?php esc_html_e( 'Alternative text', 'mclogiora' ); ?></span>
							<input type="text" form="<?php echo esc_attr( $form_id ); ?>" name="translated_alt_text" class="widefat" value="<?php echo esc_attr( $existing instanceof MediaTranslation ? $existing->alt_text() : '' ); ?>">
						</label>
					</p>
					<p>
						<label>
							<span><?php esc_html_e( 'Caption', 'mclogiora' ); ?></span>
							<textarea form="<?php echo esc_attr( $form_id ); ?>" name="translated_caption" class="widefat" rows="2"><?php echo esc_textarea( $existing instanceof MediaTranslation ? $existing->caption() : '' ); ?></textarea>
						</label>
					</p>
					<p>
						<label>
							<span><?php esc_html_e( 'Description', 'mclogiora' ); ?></span>
							<textarea form="<?php echo esc_attr( $form_id ); ?>" name="translated_description" class="widefat" rows="3"><?php echo esc_textarea( $existing instanceof MediaTranslation ? $existing->description() : '' ); ?></textarea>
						</label>
					</p>
					<button type="submit" form="<?php echo esc_attr( $form_id ); ?>" class="button"><?php esc_html_e( 'Save Translation', 'mclogiora' ); ?></button>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
