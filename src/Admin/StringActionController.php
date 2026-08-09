<?php
/**
 * String and media translation action controller.
 *
 * @package McLogiora
 */

namespace McLogiora\Admin;

use McLogiora\Contracts\ModuleInterface;
use McLogiora\Core\Container;
use McLogiora\Media\MediaTranslationService;
use McLogiora\Menus\MenuTranslationWorkflow;
use McLogiora\Strings\StringRegistry;
use McLogiora\Strings\StringTranslationService;
use McLogiora\Widgets\WidgetTranslationService;

defined( 'ABSPATH' ) || exit;

/**
 * Handles secured admin-post requests for the Phase 11 translation domains.
 *
 * Coordinates requests only: verifies the nonce, sanitizes input, delegates,
 * and redirects. Capability is checked again inside every service, so
 * authorisation never depends on this class alone.
 */
final class StringActionController implements ModuleInterface {
	const NONCE_ACTION = 'mclogiora_string_action';
	const NONCE_NAME   = 'mclogiora_string_nonce';

	/**
	 * String registry.
	 *
	 * @var StringRegistry|null
	 */
	private $registry = null;

	/**
	 * String translation service.
	 *
	 * @var StringTranslationService|null
	 */
	private $strings = null;

	/**
	 * Media translation service.
	 *
	 * @var MediaTranslationService|null
	 */
	private $media = null;

	/**
	 * Menu translation workflow.
	 *
	 * @var MenuTranslationWorkflow|null
	 */
	private $menus = null;

	/**
	 * Widget translation service.
	 *
	 * @var WidgetTranslationService|null
	 */
	private $widgets = null;

	/**
	 * Registers admin-post handlers.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	public function register( Container $container ) {
		if ( ! is_admin() ) {
			return;
		}

		$this->registry = $container->get( StringRegistry::class );
		$this->strings  = $container->get( StringTranslationService::class );
		$this->media    = $container->get( MediaTranslationService::class );
		$this->menus    = $container->get( MenuTranslationWorkflow::class );
		$this->widgets  = $container->get( WidgetTranslationService::class );

		add_action( 'admin_post_mclogiora_scan_strings', array( $this, 'handle_scan' ) );
		add_action( 'admin_post_mclogiora_save_string_translation', array( $this, 'handle_save_string' ) );
		add_action( 'admin_post_mclogiora_save_media_translation', array( $this, 'handle_save_media' ) );
		add_action( 'admin_post_mclogiora_create_menu_translation', array( $this, 'handle_create_menu' ) );
		add_action( 'admin_post_mclogiora_save_widget_translation', array( $this, 'handle_save_widget' ) );
	}

	/**
	 * Handles an explicit scan request.
	 *
	 * @return void
	 */
	public function handle_scan() {
		$this->verify_request();

		$result = $this->registry->scan( $this->request_key( 'scope_kind' ), $this->request_slug( 'scope_slug' ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result, StringManager::PAGE_SLUG );
		}

		$this->redirect_notice( 'scanned', StringManager::PAGE_SLUG );
	}

	/**
	 * Handles saving a string translation.
	 *
	 * @return void
	 */
	public function handle_save_string() {
		$this->verify_request();

		$result = $this->strings->save_translation(
			$this->request_int( 'string_id' ),
			$this->request_key( 'language' ),
			$this->request_textarea( 'translated_text' )
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result, StringManager::PAGE_SLUG );
		}

		$this->redirect_notice( 'saved', StringManager::PAGE_SLUG );
	}

	/**
	 * Handles saving media metadata translations.
	 *
	 * @return void
	 */
	public function handle_save_media() {
		$this->verify_request();

		$attachment_id = $this->request_int( 'attachment_id' );

		$result = $this->media->save(
			$attachment_id,
			$this->request_key( 'language' ),
			array(
				'title'       => $this->request_text( 'translated_title' ),
				'alt_text'    => $this->request_text( 'translated_alt_text' ),
				'caption'     => $this->request_textarea( 'translated_caption' ),
				'description' => $this->request_textarea( 'translated_description' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_error_to( $result, get_edit_post_link( $attachment_id, 'raw' ) );
		}

		$this->redirect_notice_to( 'saved', get_edit_post_link( $attachment_id, 'raw' ) );
	}

	/**
	 * Handles creating a translated menu.
	 *
	 * @return void
	 */
	public function handle_create_menu() {
		$this->verify_request();

		$result = $this->menus->create_translation(
			$this->request_int( 'menu_id' ),
			$this->request_key( 'language' ),
			$this->request_text( 'translated_name' )
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result, WidgetTranslationManager::PAGE_SLUG );
		}

		$this->redirect_notice( 'created', WidgetTranslationManager::PAGE_SLUG );
	}

	/**
	 * Handles saving widget field translations.
	 *
	 * @return void
	 */
	public function handle_save_widget() {
		$this->verify_request();

		$fields = array();

		foreach ( $this->submitted_fields() as $key => $value ) {
			$fields[ sanitize_key( $key ) ] = (string) $value;
		}

		$result = $this->widgets->save(
			$this->request_key( 'widget_type' ),
			$this->request_key( 'instance_id' ),
			$this->request_key( 'language' ),
			$fields
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_error( $result, WidgetTranslationManager::PAGE_SLUG );
		}

		$this->redirect_notice( 'saved', WidgetTranslationManager::PAGE_SLUG );
	}

	/**
	 * Returns the submitted widget fields, unslashed and sanitized.
	 *
	 * The whole array is unslashed and passed through sanitize_textarea_field
	 * with map_deep, which sanitizes every scalar leaf. Non-scalar leaves are
	 * dropped afterwards, so a nested array cannot smuggle a raw value
	 * through. The sniff cannot follow a callback passed to map_deep, which is
	 * the only reason it is silenced here.
	 *
	 * @return array<string,string>
	 */
	private function submitted_fields() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- nonce checked in verify_request(); sanitized by map_deep() on the next line, which the sniff cannot follow.
		$raw = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? $_POST['fields'] : array();

		$clean  = map_deep( wp_unslash( $raw ), 'sanitize_textarea_field' );
		$fields = array();

		foreach ( $clean as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$fields[ (string) $key ] = (string) $value;
			}
		}

		return $fields;
	}

	/**
	 * Verifies the nonce for the current request.
	 *
	 * @return void
	 */
	private function verify_request() {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			wp_die( esc_html__( 'The request could not be verified.', 'mclogiora' ), '', array( 'response' => 400 ) );
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'The request could not be verified.', 'mclogiora' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Returns a sanitized integer request value.
	 *
	 * @param string $key Request key.
	 * @return int
	 */
	private function request_int( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() runs first in every handler.
		return isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;
	}

	/**
	 * Returns a sanitized key request value.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function request_key( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() runs first in every handler.
		return isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Returns a sanitized directory-slug request value.
	 *
	 * Only a single path segment is ever accepted, and ScanScope re-validates
	 * it before touching the filesystem.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function request_slug( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() runs first in every handler.
		$raw = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';

		return (string) preg_replace( '/[^A-Za-z0-9_.\-]/', '', $raw );
	}

	/**
	 * Returns a sanitized text request value.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function request_text( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() runs first in every handler.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Returns a sanitized textarea request value.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function request_textarea( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_request() runs first in every handler.
		return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Redirects to a plugin screen with an error notice.
	 *
	 * @param \WP_Error $error Error.
	 * @param string    $page Page slug.
	 * @return void
	 */
	private function redirect_error( \WP_Error $error, $page ) {
		$this->redirect_error_to( $error, admin_url( 'admin.php?page=' . $page ) );
	}

	/**
	 * Redirects to a plugin screen with a success notice.
	 *
	 * @param string $notice Notice key.
	 * @param string $page Page slug.
	 * @return void
	 */
	private function redirect_notice( $notice, $page ) {
		$this->redirect_notice_to( $notice, admin_url( 'admin.php?page=' . $page ) );
	}

	/**
	 * Redirects to a URL with an error notice.
	 *
	 * @param \WP_Error $error Error.
	 * @param string    $url Destination URL.
	 * @return void
	 */
	private function redirect_error_to( \WP_Error $error, $url ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'mclogiora_notice'  => 'error',
					'mclogiora_message' => rawurlencode( $error->get_error_message() ),
				),
				(string) $url
			)
		);
		exit;
	}

	/**
	 * Redirects to a URL with a success notice.
	 *
	 * @param string $notice Notice key.
	 * @param string $url Destination URL.
	 * @return void
	 */
	private function redirect_notice_to( $notice, $url ) {
		wp_safe_redirect( add_query_arg( array( 'mclogiora_notice' => sanitize_key( $notice ) ), (string) $url ) );
		exit;
	}
}
