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
 * translation UI, so translators work where they already edit alt text. The
 * form posts to the standard secured handler; nothing is written by simply
 * viewing the screen.
 */
final class MediaTranslationFields implements ModuleInterface {
	/**
	 * Effective capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

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
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mclogiora-info-card">
					<h3><?php echo esc_html( $language->native_name() ); ?></h3>
					<input type="hidden" name="action" value="mclogiora_save_media_translation">
					<input type="hidden" name="attachment_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
					<input type="hidden" name="language" value="<?php echo esc_attr( $language->code() ); ?>">
					<?php wp_nonce_field( StringActionController::NONCE_ACTION, StringActionController::NONCE_NAME ); ?>
					<p>
						<label>
							<span><?php esc_html_e( 'Title', 'mclogiora' ); ?></span>
							<input type="text" name="translated_title" class="widefat" value="<?php echo esc_attr( $existing instanceof MediaTranslation ? $existing->title() : '' ); ?>">
						</label>
					</p>
					<p>
						<label>
							<span><?php esc_html_e( 'Alternative text', 'mclogiora' ); ?></span>
							<input type="text" name="translated_alt_text" class="widefat" value="<?php echo esc_attr( $existing instanceof MediaTranslation ? $existing->alt_text() : '' ); ?>">
						</label>
					</p>
					<p>
						<label>
							<span><?php esc_html_e( 'Caption', 'mclogiora' ); ?></span>
							<textarea name="translated_caption" class="widefat" rows="2"><?php echo esc_textarea( $existing instanceof MediaTranslation ? $existing->caption() : '' ); ?></textarea>
						</label>
					</p>
					<p>
						<label>
							<span><?php esc_html_e( 'Description', 'mclogiora' ); ?></span>
							<textarea name="translated_description" class="widefat" rows="3"><?php echo esc_textarea( $existing instanceof MediaTranslation ? $existing->description() : '' ); ?></textarea>
						</label>
					</p>
					<button type="submit" class="button"><?php esc_html_e( 'Save Translation', 'mclogiora' ); ?></button>
				</form>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
