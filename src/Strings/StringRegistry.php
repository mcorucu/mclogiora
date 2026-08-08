<?php
/**
 * String registry.
 *
 * @package McLogiora
 */

namespace McLogiora\Strings;

use McLogiora\Capabilities\CapabilityRegistry;
use McLogiora\WordPress\ContentGatewayInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers discovered strings and coordinates explicit scans.
 *
 * A scan marks the existing strings in its scope stale, registers everything
 * it finds, and leaves anything not found still stored but flagged. Strings
 * are never deleted by a scan: deleting them would take their translations
 * with them, so deactivating a plugin for an afternoon would silently destroy
 * translation work. Stale is recoverable; deleted is not.
 */
final class StringRegistry {
	/**
	 * String repository.
	 *
	 * @var StringRepositoryInterface
	 */
	private $repository;

	/**
	 * Scanner.
	 *
	 * @var StringScanner
	 */
	private $scanner;

	/**
	 * Scope resolver.
	 *
	 * @var ScanScope
	 */
	private $scopes;

	/**
	 * Content gateway.
	 *
	 * @var ContentGatewayInterface
	 */
	private $gateway;

	/**
	 * Capability registry.
	 *
	 * @var CapabilityRegistry
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param StringRepositoryInterface $repository String repository.
	 * @param StringScanner             $scanner Scanner.
	 * @param ScanScope                 $scopes Scope resolver.
	 * @param ContentGatewayInterface   $gateway Content gateway.
	 * @param CapabilityRegistry        $capabilities Capability registry.
	 */
	public function __construct(
		StringRepositoryInterface $repository,
		StringScanner $scanner,
		ScanScope $scopes,
		ContentGatewayInterface $gateway,
		CapabilityRegistry $capabilities
	) {
		$this->repository   = $repository;
		$this->scanner      = $scanner;
		$this->scopes       = $scopes;
		$this->gateway      = $gateway;
		$this->capabilities = $capabilities;
	}

	/**
	 * Runs an explicit scan of a confined scope.
	 *
	 * Scanning reads third-party source files, so it requires a higher trust
	 * level than editing a translation. `install_plugins` is used because a
	 * user who can already add arbitrary code to the site is not escalated by
	 * being allowed to read it.
	 *
	 * @param string $kind Scope kind.
	 * @param string $slug Directory slug within the scope root.
	 * @return array{registered:int,files:int,skipped:int,unresolvable:int,stale:int}|\WP_Error
	 */
	public function scan( $kind, $slug ) {
		if ( ! $this->gateway->current_user_can( $this->capabilities->resolve( CapabilityRegistry::MANAGE_TRANSLATIONS ) ) ) {
			return new \WP_Error( 'mclogiora_cannot_manage_translations', __( 'You are not allowed to manage translations.', 'mclogiora' ) );
		}

		if ( ! $this->gateway->current_user_can( 'install_plugins' ) ) {
			return new \WP_Error(
				'mclogiora_cannot_scan_source',
				__( 'You are not allowed to scan plugin and theme source files.', 'mclogiora' )
			);
		}

		$directory = $this->scopes->resolve( $kind, $slug );

		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$reference  = (string) $slug;
		$stale      = $this->repository->mark_scope_stale( (string) $kind, $reference );
		$scanned    = $this->scanner->scan_directory( $directory, (string) $kind, $reference );
		$registered = 0;

		foreach ( $scanned['strings'] as $string ) {
			$result = $this->repository->register( $string );

			if ( ! is_wp_error( $result ) ) {
				++$registered;
			}
		}

		return array(
			'registered'   => $registered,
			'files'        => (int) $scanned['files'],
			'skipped'      => (int) $scanned['skipped'],
			'unresolvable' => (int) $scanned['unresolvable'],
			'stale'        => (int) $stale,
		);
	}

	/**
	 * Registers a single string manually.
	 *
	 * @param string $text Source text.
	 * @param string $text_domain Text domain.
	 * @param string $context Gettext context.
	 * @return StringSource|\WP_Error
	 */
	public function register_manual( $text, $text_domain = '', $context = '' ) {
		$text = (string) $text;

		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'mclogiora_empty_string', __( 'Enter the source string.', 'mclogiora' ) );
		}

		return $this->repository->register(
			new StringSource( 0, $text, $text_domain, $context, StringSourceType::MANUAL, 'manual' )
		);
	}
}
