<?php
/**
 * String Translation admin screen.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Suggestions\SuggestionSurface;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Languages\Language;
use McLogiora\Languages\LanguageServiceInterface;
use McLogiora\Strings\StringRepositoryInterface;
use McLogiora\Strings\StringSource;
use McLogiora\Strings\StringSourceType;
use McLogiora\Strings\StringTranslation;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the String Translation screen.
 *
 * Follows the existing admin patterns: the same panel, filter bar, card, and
 * table classes used by the other mcLogiora screens. No new visual language
 * is introduced, and screen-specific scripts are not enqueued here; shared
 * admin behavior is loaded conditionally by AssetLoader.
 */
final class StringManager implements ModuleInterface {
	const SUGGESTIONS_HANDLE = 'mclogiora-admin-suggestions';
	const PAGE_SLUG          = 'mclogiora-string-translation';

	/**
	 * Effective admin capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * String repository.
	 *
	 * @var StringRepositoryInterface|null
	 */
	private $strings = null;

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface|null
	 */
	private $languages = null;

	/**
	 * Suggestion state provider.
	 *
	 * @var SuggestionAdminState|null
	 */
	private $suggestions = null;

	/**
	 * Registers the screen.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$capabilities      = $container->get( CapabilityRegistry::class );
		$this->capability  = $capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS );
		$this->strings     = $container->get( StringRepositoryInterface::class );
		$this->languages   = $container->get( LanguageServiceInterface::class );
		$this->suggestions = $container->get( SuggestionAdminState::class );

		$registry = $container->get( AdminScreenRegistry::class );
		$registry->add(
			new AdminScreen(
				static function () {
					return __( 'mcLogiora String Translation', 'mclogiora' );
				},
				static function () {
					return __( 'String Translation', 'mclogiora' );
				},
				$this->capability,
				self::PAGE_SLUG,
				array( $this, 'render' )
			)
		);
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mclogiora' ) );
		}

		$this->enqueue_suggestions();

		$filters   = $this->read_filters();
		$strings   = $this->strings->query( $filters );
		$languages = $this->languages->get_active_languages();
		$total     = $this->strings->count_strings();

		?>
		<div class="wrap mclogiora-admin">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-string-manager-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Translation Data', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-string-manager-title"><?php esc_html_e( 'String Translation', 'mclogiora' ); ?></h1>
				<p class="mclogiora-lede"><?php esc_html_e( 'Manage translations for interface strings found in your theme and plugins. Scanning only runs when you ask for it, and never during normal site traffic.', 'mclogiora' ); ?></p>

				<?php $this->render_notice(); ?>
				<?php $this->render_scan_panel(); ?>

				<div class="mclogiora-filter-bar" aria-label="<?php esc_attr_e( 'String filters', 'mclogiora' ); ?>">
					<form method="get" action="">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
						<label>
							<span><?php esc_html_e( 'Search', 'mclogiora' ); ?></span>
							<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>">
						</label>
						<label>
							<span><?php esc_html_e( 'Text domain', 'mclogiora' ); ?></span>
							<input type="text" name="text_domain" value="<?php echo esc_attr( $filters['text_domain'] ); ?>">
						</label>
						<label>
							<span><?php esc_html_e( 'Origin', 'mclogiora' ); ?></span>
							<select name="source_type">
								<option value=""><?php esc_html_e( 'Any origin', 'mclogiora' ); ?></option>
								<?php foreach ( StringSourceType::all() as $type ) : ?>
									<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['source_type'], $type ); ?>>
										<?php echo esc_html( $type ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
						<button type="submit" class="button"><?php esc_html_e( 'Filter', 'mclogiora' ); ?></button>
					</form>
				</div>

				<div class="mclogiora-card-grid mclogiora-card-grid--two">
					<article class="mclogiora-info-card">
						<h2><?php esc_html_e( 'Registered Strings', 'mclogiora' ); ?></h2>
						<p class="mclogiora-card-value"><?php echo esc_html( (string) $total ); ?></p>
						<p><?php esc_html_e( 'Source strings discovered by scanning or registered manually.', 'mclogiora' ); ?></p>
					</article>
					<article class="mclogiora-info-card">
						<h2><?php esc_html_e( 'Active Languages', 'mclogiora' ); ?></h2>
						<p class="mclogiora-card-value"><?php echo esc_html( (string) count( $languages ) ); ?></p>
						<p><?php esc_html_e( 'Languages available as translation targets.', 'mclogiora' ); ?></p>
					</article>
				</div>

				<?php $this->render_table( $strings, $languages, $filters ); ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Renders the scan panel.
	 *
	 * @return void
	 */
	private function render_scan_panel() {
		?>
		<article class="mclogiora-info-card">
			<h2><?php esc_html_e( 'Scan for Strings', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'Reads the source files of a theme or plugin and registers the translatable strings it finds. This runs only when you submit this form.', 'mclogiora' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mclogiora_scan_strings">
				<?php wp_nonce_field( StringActionController::NONCE_ACTION, StringActionController::NONCE_NAME ); ?>
				<label>
					<span><?php esc_html_e( 'Scope', 'mclogiora' ); ?></span>
					<select name="scope_kind" required>
						<option value="<?php echo esc_attr( StringSourceType::THEME ); ?>"><?php esc_html_e( 'Theme', 'mclogiora' ); ?></option>
						<option value="<?php echo esc_attr( StringSourceType::PLUGIN ); ?>"><?php esc_html_e( 'Plugin', 'mclogiora' ); ?></option>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Directory name', 'mclogiora' ); ?></span>
					<input type="text" name="scope_slug" pattern="[A-Za-z0-9_.\-]+" required>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Scan', 'mclogiora' ); ?></button>
			</form>
		</article>
		<?php
	}

	/**
	 * Renders the string table.
	 *
	 * @param StringSource[]      $strings Source strings.
	 * @param Language[]          $languages Active languages.
	 * @param array<string,mixed> $filters Current filters.
	 * @return void
	 */
	private function render_table( array $strings, array $languages, array $filters ) {
		if ( empty( $languages ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Add and activate at least one language before translating strings.', 'mclogiora' )
			);

			return;
		}

		if ( empty( $strings ) ) {
			printf(
				'<p class="mclogiora-muted-line">%s</p>',
				esc_html__( 'No strings match these filters yet. Run a scan to discover strings.', 'mclogiora' )
			);

			return;
		}

		$target = $filters['language'];

		if ( '' === $target ) {
			$target = $languages[0] instanceof Language ? $languages[0]->code() : '';
		}

		?>
		<form method="get" action="" class="mclogiora-inline-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<label>
				<span><?php esc_html_e( 'Target language', 'mclogiora' ); ?></span>
				<select name="language" data-mclogiora-submit-on-change="1">
					<?php foreach ( $languages as $language ) : ?>
						<?php if ( $language instanceof Language ) : ?>
							<option value="<?php echo esc_attr( $language->code() ); ?>" <?php selected( $target, $language->code() ); ?>>
								<?php echo esc_html( $language->native_name() ); ?>
							</option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'mclogiora' ); ?></button>
		</form>

		<table class="widefat striped mclogiora-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Source string', 'mclogiora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Text domain', 'mclogiora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Context', 'mclogiora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Origin', 'mclogiora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Translation', 'mclogiora' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $strings as $string ) : ?>
					<?php $this->render_row( $string, $target ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders one string row.
	 *
	 * @param StringSource $string Source string.
	 * @param string       $language_code Target language code.
	 * @return void
	 */
	private function render_row( StringSource $string, $language_code ) {
		$translation = $this->strings->find_translation( $string->id(), $language_code );
		$value       = $translation instanceof StringTranslation ? $translation->text() : '';
		$reference   = $string->source_reference();

		?>
		<tr>
			<td>
				<code><?php echo esc_html( $string->text() ); ?></code>
				<?php if ( '' !== $reference ) : ?>
					<p class="mclogiora-muted-line"><?php echo esc_html( $reference ); ?></p>
				<?php endif; ?>
				<?php if ( $string->is_stale() ) : ?>
					<p class="mclogiora-muted-line"><?php esc_html_e( 'Not seen in the last scan. The translation is kept.', 'mclogiora' ); ?></p>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $string->text_domain() ); ?></td>
			<td><?php echo esc_html( $string->context() ); ?></td>
			<td><?php echo esc_html( $string->source_type() ); ?></td>
			<td>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mclogiora_save_string_translation">
					<input type="hidden" name="string_id" value="<?php echo esc_attr( (string) $string->id() ); ?>">
					<input type="hidden" name="language" value="<?php echo esc_attr( $language_code ); ?>">
					<?php wp_nonce_field( StringActionController::NONCE_ACTION, StringActionController::NONCE_NAME ); ?>
					<textarea id="<?php echo esc_attr( $this->field_id( $string, $language_code ) ); ?>" name="translated_text" rows="2" cols="40"><?php echo esc_textarea( $value ); ?></textarea>
					<button type="submit" class="button"><?php esc_html_e( 'Save', 'mclogiora' ); ?></button>
				</form>
				<?php $this->render_suggestion_control( $string, $language_code ); ?>
			</td>
		</tr>
		<?php
	}


	/**
	 * Loads the suggestion script for this screen only.
	 *
	 * Enqueued from render(), so the asset never reaches another admin page and
	 * never reaches the front end. Nothing is shipped when the feature is
	 * unavailable: the row markup already explains why, and handing the browser
	 * an action list for something it may not do would be worse than useless.
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
	 * Renders the suggestion control for one string and target language.
	 *
	 * The control carries an id and a language, never the source text: the
	 * endpoint resolves the string itself, so the browser cannot choose what the
	 * owner pays to translate. Two identical source strings registered under
	 * different text domains are different rows and are named by different ids
	 * here, so they translate independently.
	 *
	 * No form is opened. The row already contains one for saving by hand, and a
	 * control that submitted it would save the wrong thing.
	 *
	 * @param StringSource $source Source string.
	 * @param string       $language_code Target language code.
	 * @return void
	 */
	private function render_suggestion_control( StringSource $source, $language_code ) {
		if ( ! $this->suggestions instanceof SuggestionAdminState ) {
			return;
		}

		$default = $this->languages instanceof LanguageServiceInterface
			? $this->languages->get_default_language()
			: null;

		if ( $default instanceof Language && $default->code() === $language_code ) {
			/*
			 * This column is showing the default language, which is what
			 * everything else is translated from. There is nothing to translate
			 * into, so no control is offered rather than one that would always
			 * be refused.
			 */
			return;
		}

		$state = $this->suggestions->current();

		echo '<div class="mclogiora-editor__suggestions">';

		if ( empty( $state['available'] ) ) {
			printf( '<p class="mclogiora-editor__meta">%s</p>', esc_html( $state['reason'] ) );

			if ( ! empty( $state['settingsUrl'] ) ) {
				printf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_url( $state['settingsUrl'] ),
					esc_html__( 'Translation Suggestions settings', 'mclogiora' )
				);
			}

			echo '</div>';

			return;
		}

		printf(
			'<div data-mclogiora-suggest data-surface="%1$s" data-object="%2$s" data-language="%3$s" data-field="%4$s" data-field-label="%5$s">',
			esc_attr( SuggestionSurface::STRING ),
			esc_attr( (string) $source->id() ),
			esc_attr( $language_code ),
			esc_attr( $this->field_id( $source, $language_code ) ),
			esc_attr__( 'String', 'mclogiora' )
		);

		printf(
			'<button type="button" class="button button-secondary" data-mclogiora-generate aria-label="%1$s">%2$s</button>',
			esc_attr__( 'Generate String suggestion', 'mclogiora' ),
			esc_html__( 'Generate suggestion', 'mclogiora' )
		);

		echo '<div class="mclogiora-editor__feedback" data-mclogiora-feedback></div>';

		echo '</div></div>';
	}

	/**
	 * Returns the id of the textarea a suggestion would be applied into.
	 *
	 * @param StringSource $source Source string.
	 * @param string       $language_code Target language code.
	 * @return string
	 */
	private function field_id( StringSource $source, $language_code ) {
		return 'mclogiora-string-' . (int) $source->id() . '-' . sanitize_key( $language_code );
	}

	/**
	 * Reads the current filters from the request.
	 *
	 * @return array<string,mixed>
	 */
	private function read_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen filters that change nothing.
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$text_domain = isset( $_GET['text_domain'] ) ? sanitize_text_field( wp_unslash( $_GET['text_domain'] ) ) : '';
		$source_type = isset( $_GET['source_type'] ) ? sanitize_key( wp_unslash( $_GET['source_type'] ) ) : '';
		$language    = isset( $_GET['language'] ) ? sanitize_key( wp_unslash( $_GET['language'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'search'      => $search,
			'text_domain' => $text_domain,
			'source_type' => StringSourceType::is_valid( $source_type ) ? $source_type : '',
			'language'    => $language,
			'limit'       => 50,
		);
	}

	/**
	 * Renders the action notice.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect feedback.
		$notice = isset( $_GET['mclogiora_notice'] ) ? sanitize_key( wp_unslash( $_GET['mclogiora_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		if ( 'error' === $notice ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect feedback.
			$message = isset( $_GET['mclogiora_message'] ) ? sanitize_text_field( wp_unslash( $_GET['mclogiora_message'] ) ) : '';

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( '' !== $message ? $message : __( 'The action could not be completed.', 'mclogiora' ) )
			);

			return;
		}

		$messages = array(
			'scanned' => __( 'The scan finished. Discovered strings are listed below.', 'mclogiora' ),
			'saved'   => __( 'The translation was saved.', 'mclogiora' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
	}
}
