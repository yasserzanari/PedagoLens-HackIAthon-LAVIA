<?php
/**
 * Plugin Name: PedagoLens Migration
 * Plugin URI:  https://pedagolens.local
 * Description: Reset et recreation complete des pages PedagoLens + import des medias de demo.
 * Version:     1.0.0
 * Author:      PedagoLens Team
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Text Domain: pedagolens-migration
 */

defined( 'ABSPATH' ) || exit;

define( 'PL_MIGRATION_VERSION', '1.0.0' );
define( 'PL_MIGRATION_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register( function ( string $class ): void {
    $map = [
        'PedagoLens_Migration' => PL_MIGRATION_DIR . 'includes/class-migration.php',
    ];
    if ( isset( $map[ $class ] ) && file_exists( $map[ $class ] ) ) {
        require_once $map[ $class ];
    }
} );

add_action( 'plugins_loaded', [ 'PedagoLens_Migration', 'init' ] );
