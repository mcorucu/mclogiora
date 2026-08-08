<?php
/**
 * Uninstall bootstrap.
 *
 * Phase 02 creates no plugin data, options, tables, scheduled events, or files.
 *
 * @package McLogiora
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

do_action( 'mclogiora_uninstall' );
