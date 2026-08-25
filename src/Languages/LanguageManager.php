<?php
/**
 * Language manager module.
 *
 * @package McLogiora
 */

namespace McLogiora\Languages;

use McLogiora\Admin\AdminScreen;
use McLogiora\Admin\AdminScreenRegistry;
use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Helpers\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the persistence-backed Language Manager screen.
 */
final class LanguageManager implements ModuleInterface {
	const NONCE_ACTION = 'mclogiora_manage_languages';
	const NONCE_FIELD  = 'mclogiora_language_nonce';

	/**
	 * Language service.
	 *
	 * @var LanguageServiceInterface|null
	 */
	private $language_service = null;

	/**
	 * Effective admin capability.
	 *
	 * @var string
	 */
	private $capability = 'manage_options';

	/**
	 * Registers the Languages admin screen.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		$capabilities           = $container->get( CapabilityRegistry::class );
		$this->capability       = $capabilities->resolve( CapabilityRegistry::MANAGE_LANGUAGES );
		$this->language_service = $container->get( LanguageServiceInterface::class );

		$registry = $container->get( AdminScreenRegistry::class );
		$registry->add(
			new AdminScreen(
				static function () {
					return __( 'mcLogiora Languages', 'mclogiora' );
				},
				static function () {
					return __( 'Languages', 'mclogiora' );
				},
				$this->capability,
				'mclogiora-languages',
				array( $this, 'render' )
			)
		);
	}

	/**
	 * Renders the Languages admin screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mclogiora' ) );
		}

		$notice = $this->maybe_handle_post();

		$default_language = $this->language_service instanceof LanguageServiceInterface ? $this->language_service->get_default_language() : null;
		$active_languages = $this->language_service instanceof LanguageServiceInterface ? $this->language_service->get_active_languages() : array();
		$languages        = $this->language_service instanceof LanguageServiceInterface ? $this->language_service->get_languages() : array();

		?>
		<div class="wrap mclogiora-admin">
			<section class="mclogiora-panel" aria-labelledby="mclogiora-languages-title">
				<p class="mclogiora-eyebrow"><?php esc_html_e( 'Language Manager', 'mclogiora' ); ?></p>
				<h1 id="mclogiora-languages-title"><?php esc_html_e( 'Languages', 'mclogiora' ); ?></h1>
				<p class="mclogiora-lede"><?php esc_html_e( 'Add and manage the languages used by translated content, language-aware URLs, and the language switcher.', 'mclogiora' ); ?></p>

				<?php $this->render_notice( $notice ); ?>

				<div class="mclogiora-card-grid">
					<?php $this->render_default_language_card( $default_language ); ?>
					<?php $this->render_active_languages_card( $active_languages ); ?>
					<?php $this->render_add_language_card(); ?>
				</div>

				<?php $this->render_reorder_card( $languages ); ?>
				<?php $this->render_language_table( $languages ); ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Handles posted language actions.
	 *
	 * @return array<string, string>|null
	 */
	private function maybe_handle_post() {
		if ( empty( $_POST['mclogiora_language_action'] ) ) {
			return null;
		}

		if ( ! Security::current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'mclogiora' ) );
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! Security::verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return $this->notice( 'error', __( 'Security check failed. Please try again.', 'mclogiora' ) );
		}

		if ( ! $this->language_service instanceof LanguageServiceInterface ) {
			return $this->notice( 'error', __( 'Language services are not available.', 'mclogiora' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['mclogiora_language_action'] ) );
		$result = null;

		switch ( $action ) {
			case 'add':
				$result = $this->language_service->create_language( $this->posted_language_data() );
				break;

			case 'edit':
				$result = $this->language_service->update_language( $this->posted_code(), $this->posted_language_data() );
				break;

			case 'enable':
				$result = $this->language_service->enable_language( $this->posted_code() );
				break;

			case 'disable':
				$result = $this->language_service->disable_language( $this->posted_code() );
				break;

			case 'set_default':
				$result = $this->language_service->set_default_language( $this->posted_code() );
				break;

			case 'reorder':
				$orders = isset( $_POST['mclogiora_language_order'] ) && is_array( $_POST['mclogiora_language_order'] ) ? wp_unslash( $_POST['mclogiora_language_order'] ) : array();
				$result = $this->language_service->reorder_languages( $orders );
				break;

			default:
				return $this->notice( 'error', __( 'Unknown language action.', 'mclogiora' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $this->notice( 'error', $result->get_error_message() );
		}

		return $this->notice( 'success', $this->success_message( $action ) );
	}

	/**
	 * Returns language data from POST.
	 *
	 * @return array<string, mixed>
	 */
	private function posted_language_data() {
		return array(
			'code'         => isset( $_POST['language_code'] ) ? wp_unslash( $_POST['language_code'] ) : '',
			'locale'       => isset( $_POST['locale'] ) ? wp_unslash( $_POST['locale'] ) : '',
			'native_name'  => isset( $_POST['native_name'] ) ? wp_unslash( $_POST['native_name'] ) : '',
			'english_name' => isset( $_POST['english_name'] ) ? wp_unslash( $_POST['english_name'] ) : '',
			'direction'    => isset( $_POST['direction'] ) ? wp_unslash( $_POST['direction'] ) : '',
			'status'       => isset( $_POST['status'] ) ? wp_unslash( $_POST['status'] ) : LanguageStatus::ACTIVE,
			'order'        => isset( $_POST['sort_order'] ) ? wp_unslash( $_POST['sort_order'] ) : 0,
			'default'      => ! empty( $_POST['is_default'] ),
		);
	}

	/**
	 * Returns posted language code.
	 *
	 * @return string
	 */
	private function posted_code() {
		return isset( $_POST['language_code'] ) ? sanitize_key( wp_unslash( $_POST['language_code'] ) ) : '';
	}

	/**
	 * Creates a notice payload.
	 *
	 * @param string $type Notice type.
	 * @param string $message Notice message.
	 * @return array<string, string>
	 */
	private function notice( $type, $message ) {
		return array(
			'type'    => $type,
			'message' => $message,
		);
	}

	/**
	 * Returns a success message for an action.
	 *
	 * @param string $action Action key.
	 * @return string
	 */
	private function success_message( $action ) {
		$messages = array(
			'add'         => __( 'Language added.', 'mclogiora' ),
			'edit'        => __( 'Language updated.', 'mclogiora' ),
			'enable'      => __( 'Language enabled.', 'mclogiora' ),
			'disable'     => __( 'Language disabled.', 'mclogiora' ),
			'set_default' => __( 'Default language updated.', 'mclogiora' ),
			'reorder'     => __( 'Language order saved.', 'mclogiora' ),
		);

		return isset( $messages[ $action ] ) ? $messages[ $action ] : __( 'Language action completed.', 'mclogiora' );
	}

	/**
	 * Renders a screen notice.
	 *
	 * @param array<string, string>|null $notice Notice payload.
	 * @return void
	 */
	private function render_notice( $notice ) {
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		$class = 'success' === $notice['type'] ? 'mclogiora-notice mclogiora-notice--success' : 'mclogiora-notice mclogiora-notice--error';
		?>
		<div class="<?php echo esc_attr( $class ); ?>" role="status">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the default language card.
	 *
	 * @param Language|null $language Default language.
	 * @return void
	 */
	private function render_default_language_card( $language ) {
		?>
		<article class="mclogiora-info-card">
			<h2><?php esc_html_e( 'Default Language', 'mclogiora' ); ?></h2>
			<?php if ( $language instanceof Language ) : ?>
				<p class="mclogiora-card-value"><?php echo esc_html( $language->english_name() ); ?></p>
				<p><?php echo esc_html( sprintf( '%s / %s', $language->code(), $language->locale() ) ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'No default language has been configured yet.', 'mclogiora' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=mclogiora-setup' ) ); ?>"><?php esc_html_e( 'Run Setup Wizard', 'mclogiora' ); ?></a>
			<?php endif; ?>
		</article>
		<?php
	}

	/**
	 * Renders the active languages card.
	 *
	 * @param Language[] $languages Active languages.
	 * @return void
	 */
	private function render_active_languages_card( array $languages ) {
		?>
		<article class="mclogiora-info-card">
			<h2><?php esc_html_e( 'Active Languages', 'mclogiora' ); ?></h2>
			<p class="mclogiora-card-value"><?php echo esc_html( (string) count( $languages ) ); ?></p>
			<ul class="mclogiora-inline-list">
				<?php foreach ( $languages as $language ) : ?>
					<li><?php echo esc_html( $language->english_name() ); ?></li>
				<?php endforeach; ?>
			</ul>
		</article>
		<?php
	}

	/**
	 * Renders the add language form.
	 *
	 * @return void
	 */
	private function render_add_language_card() {
		?>
		<article class="mclogiora-info-card">
			<h2><?php esc_html_e( 'Add Language', 'mclogiora' ); ?></h2>
			<form class="mclogiora-language-form" method="post">
				<?php echo Security::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="mclogiora_language_action" value="add">
				<?php $this->render_language_fields(); ?>
				<button type="submit" class="button button-primary mclogiora-button"><?php esc_html_e( 'Add Language', 'mclogiora' ); ?></button>
			</form>
		</article>
		<?php
	}

	/**
	 * Renders the reorder form.
	 *
	 * @param Language[] $languages Languages.
	 * @return void
	 */
	private function render_reorder_card( array $languages ) {
		if ( empty( $languages ) ) {
			return;
		}

		?>
		<div class="mclogiora-table-card">
			<h2><?php esc_html_e( 'Display Order', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'Lower numbers appear first in language lists and switcher choices.', 'mclogiora' ); ?></p>
			<form class="mclogiora-order-form" method="post">
				<?php echo Security::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="mclogiora_language_action" value="reorder">
				<div class="mclogiora-order-grid">
					<?php foreach ( $languages as $language ) : ?>
						<label>
							<span><?php echo esc_html( $language->english_name() ); ?></span>
							<input type="number" min="1" name="mclogiora_language_order[<?php echo esc_attr( $language->code() ); ?>]" value="<?php echo esc_attr( (string) $language->order() ); ?>">
						</label>
					<?php endforeach; ?>
				</div>
				<button type="submit" class="button button-primary mclogiora-button"><?php esc_html_e( 'Save Order', 'mclogiora' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the language directory.
	 *
	 * @param Language[] $languages Languages.
	 * @return void
	 */
	private function render_language_table( array $languages ) {
		?>
		<div class="mclogiora-table-card">
			<h2><?php esc_html_e( 'Language Directory', 'mclogiora' ); ?></h2>
			<p><?php esc_html_e( 'These languages are stored by mcLogiora and are available to its translation and routing features.', 'mclogiora' ); ?></p>
			<div class="mclogiora-table-scroll">
				<table class="widefat striped mclogiora-language-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Language Code', 'mclogiora' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Locale', 'mclogiora' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Native Name', 'mclogiora' ); ?></th>
							<th scope="col"><?php esc_html_e( 'English Name', 'mclogiora' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Direction', 'mclogiora' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'mclogiora' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Order', 'mclogiora' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'mclogiora' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $languages ) ) : ?>
							<tr>
								<td colspan="8"><?php esc_html_e( 'No languages have been added yet.', 'mclogiora' ); ?></td>
							</tr>
						<?php endif; ?>
						<?php foreach ( $languages as $language ) : ?>
							<tr>
								<td><code><?php echo esc_html( $language->code() ); ?></code></td>
								<td><?php echo esc_html( $language->locale() ); ?></td>
								<td><?php echo esc_html( $language->native_name() ); ?></td>
								<td><?php echo esc_html( $language->english_name() ); ?></td>
								<td><?php echo esc_html( strtoupper( $language->direction() ) ); ?></td>
								<td><?php $this->render_status_pill( $language ); ?></td>
								<td><?php echo esc_html( (string) $language->order() ); ?></td>
								<td><?php $this->render_language_actions( $language ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders row actions.
	 *
	 * @param Language $language Language entity.
	 * @return void
	 */
	private function render_language_actions( Language $language ) {
		?>
		<div class="mclogiora-action-row" aria-label="<?php esc_attr_e( 'Language actions', 'mclogiora' ); ?>">
			<details class="mclogiora-inline-details">
				<summary class="button"><?php esc_html_e( 'Edit', 'mclogiora' ); ?></summary>
				<form class="mclogiora-language-form mclogiora-language-form--inline" method="post">
					<?php echo Security::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="mclogiora_language_action" value="edit">
					<?php $this->render_language_fields( $language ); ?>
					<button type="submit" class="button button-primary mclogiora-button"><?php esc_html_e( 'Save Changes', 'mclogiora' ); ?></button>
				</form>
			</details>

			<?php if ( $language->is_active() ) : ?>
				<?php $this->render_action_form( 'disable', $language->code(), __( 'Disable', 'mclogiora' ), ! $language->is_default() ); ?>
			<?php else : ?>
				<?php $this->render_action_form( 'enable', $language->code(), __( 'Enable', 'mclogiora' ), true ); ?>
			<?php endif; ?>

			<?php $this->render_action_form( 'set_default', $language->code(), __( 'Set Default', 'mclogiora' ), ! $language->is_default() ); ?>
			<button type="button" class="button" disabled title="<?php esc_attr_e( 'Delete is intentionally disabled until integrity rules are visible in the UI.', 'mclogiora' ); ?>"><?php esc_html_e( 'Delete', 'mclogiora' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Renders a small action form.
	 *
	 * @param string $action Action key.
	 * @param string $code Language code.
	 * @param string $label Button label.
	 * @param bool   $enabled Whether the button is enabled.
	 * @return void
	 */
	private function render_action_form( $action, $code, $label, $enabled ) {
		?>
		<form method="post">
			<?php echo Security::nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<input type="hidden" name="mclogiora_language_action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="language_code" value="<?php echo esc_attr( $code ); ?>">
			<button type="submit" class="button" <?php disabled( ! $enabled ); ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Renders language form fields.
	 *
	 * @param Language|null $language Existing language.
	 * @return void
	 */
	private function render_language_fields( $language = null ) {
		$is_edit = $language instanceof Language;
		?>
		<label>
			<span><?php esc_html_e( 'Language code', 'mclogiora' ); ?></span>
			<input type="text" name="language_code" value="<?php echo esc_attr( $is_edit ? $language->code() : '' ); ?>" placeholder="<?php esc_attr_e( 'en', 'mclogiora' ); ?>" <?php wp_readonly( $is_edit ); ?> required>
		</label>
		<label>
			<span><?php esc_html_e( 'Locale', 'mclogiora' ); ?></span>
			<input type="text" name="locale" value="<?php echo esc_attr( $is_edit ? $language->locale() : '' ); ?>" placeholder="<?php esc_attr_e( 'en_US', 'mclogiora' ); ?>" required>
		</label>
		<label>
			<span><?php esc_html_e( 'Native name', 'mclogiora' ); ?></span>
			<input type="text" name="native_name" value="<?php echo esc_attr( $is_edit ? $language->native_name() : '' ); ?>" placeholder="<?php esc_attr_e( 'English', 'mclogiora' ); ?>" required>
		</label>
		<label>
			<span><?php esc_html_e( 'English name', 'mclogiora' ); ?></span>
			<input type="text" name="english_name" value="<?php echo esc_attr( $is_edit ? $language->english_name() : '' ); ?>" placeholder="<?php esc_attr_e( 'English', 'mclogiora' ); ?>" required>
		</label>
		<label>
			<span><?php esc_html_e( 'Direction', 'mclogiora' ); ?></span>
			<select name="direction">
				<option value="ltr" <?php selected( $is_edit ? $language->direction() : 'ltr', 'ltr' ); ?>><?php esc_html_e( 'Left to right', 'mclogiora' ); ?></option>
				<option value="rtl" <?php selected( $is_edit ? $language->direction() : 'ltr', 'rtl' ); ?>><?php esc_html_e( 'Right to left', 'mclogiora' ); ?></option>
			</select>
		</label>
		<label>
			<span><?php esc_html_e( 'Status', 'mclogiora' ); ?></span>
			<select name="status">
				<option value="<?php echo esc_attr( LanguageStatus::ACTIVE ); ?>" <?php selected( $is_edit ? $language->status() : LanguageStatus::ACTIVE, LanguageStatus::ACTIVE ); ?>><?php esc_html_e( 'Active', 'mclogiora' ); ?></option>
				<option value="<?php echo esc_attr( LanguageStatus::INACTIVE ); ?>" <?php selected( $is_edit ? $language->status() : LanguageStatus::ACTIVE, LanguageStatus::INACTIVE ); ?>><?php esc_html_e( 'Inactive', 'mclogiora' ); ?></option>
			</select>
		</label>
		<label>
			<span><?php esc_html_e( 'Order', 'mclogiora' ); ?></span>
			<input type="number" name="sort_order" min="0" value="<?php echo esc_attr( $is_edit ? (string) $language->order() : '0' ); ?>">
		</label>
		<?php if ( ! $is_edit ) : ?>
			<label class="mclogiora-checkbox-label">
				<input type="checkbox" name="is_default" value="1">
				<span><?php esc_html_e( 'Make this the default language', 'mclogiora' ); ?></span>
			</label>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders a language status pill.
	 *
	 * @param Language $language Language entity.
	 * @return void
	 */
	private function render_status_pill( Language $language ) {
		$class = $language->is_active() ? 'mclogiora-pill mclogiora-pill--active' : 'mclogiora-pill';
		$text  = $language->is_active() ? __( 'Active', 'mclogiora' ) : __( 'Inactive', 'mclogiora' );

		if ( $language->is_default() ) {
			$class = 'mclogiora-pill mclogiora-pill--active';
			$text  = __( 'Default', 'mclogiora' );
		}

		echo '<span class="' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
	}
}
