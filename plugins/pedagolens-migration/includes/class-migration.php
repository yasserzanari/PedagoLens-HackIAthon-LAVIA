<?php

defined( 'ABSPATH' ) || exit;

class PedagoLens_Migration {

    private const PAGE_SLUG = 'pl-migration';
    private const ACTION    = 'pl_run_migration';

    private const REQUIRED_PLUGINS = [
        'pedagolens-core/pedagolens-core.php',
        'pedagolens-api-bridge/pedagolens-api-bridge.php',
        'pedagolens-landing/pedagolens-landing.php',
        'pedagolens-teacher-dashboard/pedagolens-teacher-dashboard.php',
        'pedagolens-course-workbench/pedagolens-course-workbench.php',
        'pedagolens-student-twin/pedagolens-student-twin.php',
    ];

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'add_menu' ] );
        add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle_run' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'tools.php',
            __( 'PedagoLens Migration', 'pedagolens-migration' ),
            __( 'PedagoLens Migration', 'pedagolens-migration' ),
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render_page' ]
        );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'pedagolens-migration' ) );
        }

        $status = self::plugins_status();
        $all_ok = ! in_array( false, $status, true );
        $report = get_transient( 'pl_migration_report' );
        if ( $report ) {
            delete_transient( 'pl_migration_report' );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'PedagoLens Migration', 'pedagolens-migration' ); ?></h1>
            <p><?php esc_html_e( 'This tool deletes all existing pages, recreates PedagoLens pages, and imports demo media.', 'pedagolens-migration' ); ?></p>

            <h2><?php esc_html_e( 'Required plugins', 'pedagolens-migration' ); ?></h2>
            <ul>
                <?php foreach ( $status as $plugin_file => $is_active ) : ?>
                    <li>
                        <code><?php echo esc_html( $plugin_file ); ?></code>
                        - <?php echo $is_active ? esc_html__( 'active', 'pedagolens-migration' ) : esc_html__( 'inactive', 'pedagolens-migration' ); ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ( ! $all_ok ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Activate all required plugins before running migration.', 'pedagolens-migration' ); ?></p></div>
            <?php endif; ?>

            <?php if ( is_array( $report ) ) : ?>
                <h2><?php esc_html_e( 'Last migration report', 'pedagolens-migration' ); ?></h2>
                <pre><?php echo esc_html( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
            <?php endif; ?>

            <?php $submit_attrs = $all_ok ? [] : [ 'disabled' => 'disabled' ]; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('This will DELETE all pages and recreate PedagoLens pages. Continue?');">
                <?php wp_nonce_field( self::ACTION, '_pl_nonce' ); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
                <?php submit_button( __( 'Run Full Migration', 'pedagolens-migration' ), 'primary', 'submit', false, $submit_attrs ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_run(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'pedagolens-migration' ) );
        }

        check_admin_referer( self::ACTION, '_pl_nonce' );

        $status = self::plugins_status();
        if ( in_array( false, $status, true ) ) {
            wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
            exit;
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $report = [
            'started_at' => gmdate( 'c' ),
            'deleted_pages' => 0,
            'created_pages' => [],
            'theme_used' => '',
            'imported_media' => 0,
            'logo_url' => '',
            'media_errors' => [],
        ];

        $report['theme_used'] = self::ensure_blank_theme();
        $report['deleted_pages'] = self::delete_all_pages();
        $report['created_pages'] = self::recreate_pedagolens_pages();
        self::apply_wp_settings( $report['created_pages'] );

        $report['imported_media'] = self::import_media_from_project( $report['media_errors'] );
        $report['logo_url'] = self::assign_logo();
        $report['finished_at'] = gmdate( 'c' );

        set_transient( 'pl_migration_report', $report, MINUTE_IN_SECONDS * 15 );

        wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
        exit;
    }

    private static function plugins_status(): array {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $status = [];
        foreach ( self::REQUIRED_PLUGINS as $plugin_file ) {
            $status[ $plugin_file ] = is_plugin_active( $plugin_file );
        }
        return $status;
    }

    private static function delete_all_pages(): int {
        $ids = get_posts( [
            'post_type'      => 'page',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        $count = 0;
        foreach ( $ids as $id ) {
            if ( wp_delete_post( (int) $id, true ) ) {
                $count++;
            }
        }

        return $count;
    }

    private static function recreate_pedagolens_pages(): array {
        $pages = [
            [ 'title' => 'Accueil', 'slug' => '', 'shortcode' => '[pedagolens_landing]' ],
            [ 'title' => 'Connexion', 'slug' => 'connexion', 'shortcode' => '[pedagolens_login]' ],
            [ 'title' => 'Dashboard Enseignant', 'slug' => 'dashboard-enseignant', 'shortcode' => '[pedagolens_teacher_dashboard]' ],
            [ 'title' => 'Dashboard Etudiant', 'slug' => 'dashboard-etudiant', 'shortcode' => '[pedagolens_student_dashboard]' ],
            [ 'title' => 'Cours Projets', 'slug' => 'cours-projets', 'shortcode' => '[pedagolens_courses]' ],
            [ 'title' => 'Atelier Pedagogique', 'slug' => 'workbench', 'shortcode' => '[pedagolens_workbench]' ],
            [ 'title' => 'Mon Compte', 'slug' => 'compte', 'shortcode' => '[pedagolens_account]' ],
            [ 'title' => 'Historique', 'slug' => 'historique', 'shortcode' => '[pedagolens_history]' ],
            [ 'title' => 'Parametres', 'slug' => 'parametres', 'shortcode' => '[pedagolens_settings]' ],
            [ 'title' => 'Vue Institutionnelle', 'slug' => 'institutionnel', 'shortcode' => '[pedagolens_institutional]' ],
            [ 'title' => 'Jumeau IA', 'slug' => 'jumeau-ia', 'shortcode' => '[pedagolens_jumeau_ia]' ],
        ];

        $created = [];
        foreach ( $pages as $p ) {
            $post_id = wp_insert_post( [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => $p['title'],
                'post_name'    => sanitize_title( $p['slug'] ?: $p['title'] ),
                'post_content' => $p['shortcode'],
            ] );

            if ( is_wp_error( $post_id ) ) {
                continue;
            }

            if ( $p['slug'] === '' ) {
                wp_update_post( [
                    'ID' => $post_id,
                    'post_name' => 'accueil',
                ] );
            }

            $created[ $p['slug'] ?: 'accueil' ] = (int) $post_id;
        }

        return $created;
    }

    private static function apply_wp_settings( array $created_pages ): void {
        if ( isset( $created_pages['accueil'] ) ) {
            update_option( 'show_on_front', 'page' );
            update_option( 'page_on_front', (int) $created_pages['accueil'] );
        }

        global $wp_rewrite;
        if ( is_object( $wp_rewrite ) ) {
            $wp_rewrite->set_permalink_structure( '/%postname%/' );
        }
        flush_rewrite_rules( false );
    }

    private static function import_media_from_project( array &$errors ): int {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $count = 0;
        $repo_root = dirname( dirname( dirname( PL_MIGRATION_DIR ) ) );
        $candidates = [
            $repo_root,
            $repo_root . '/screenshots',
            $repo_root . '/exemple/stitch',
        ];

        foreach ( $candidates as $dir ) {
            if ( ! is_dir( $dir ) ) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
            );

            foreach ( $iterator as $file ) {
                if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
                    continue;
                }

                $ext = strtolower( $file->getExtension() );
                if ( ! in_array( $ext, [ 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' ], true ) ) {
                    continue;
                }

                $source_path = $file->getPathname();
                $hash = sha1( $source_path );
                $existing = get_posts( [
                    'post_type'      => 'attachment',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_key'       => '_pl_source_hash',
                    'meta_value'     => $hash,
                ] );
                if ( ! empty( $existing ) ) {
                    continue;
                }

                $bits = @file_get_contents( $source_path );
                if ( $bits === false ) {
                    $errors[] = 'read_failed:' . $source_path;
                    continue;
                }

                $upload = wp_upload_bits( wp_basename( $source_path ), null, $bits );
                if ( ! empty( $upload['error'] ) ) {
                    $errors[] = 'upload_failed:' . $source_path . ':' . $upload['error'];
                    continue;
                }

                $filetype = wp_check_filetype( $upload['file'], null );
                $attach_id = wp_insert_attachment( [
                    'post_mime_type' => $filetype['type'] ?? 'application/octet-stream',
                    'post_title'     => sanitize_file_name( $file->getBasename( '.' . $ext ) ),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                ], $upload['file'] );

                if ( is_wp_error( $attach_id ) ) {
                    $errors[] = 'attachment_failed:' . $source_path;
                    continue;
                }

                $metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
                wp_update_attachment_metadata( $attach_id, $metadata );
                update_post_meta( $attach_id, '_pl_source_hash', $hash );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Ensure a minimal blank-like theme is active.
     */
    private static function ensure_blank_theme(): string {
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $candidates = [ 'blank-canvas', 'blank', 'twentytwentyfour' ];

        foreach ( $candidates as $slug ) {
            $theme = wp_get_theme( $slug );
            if ( $theme->exists() ) {
                switch_theme( $slug );
                return $slug;
            }
        }

        foreach ( $candidates as $slug ) {
            $zip = "https://downloads.wordpress.org/theme/{$slug}.latest-stable.zip";
            $upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
            $result = $upgrader->install( $zip );
            if ( $result === true ) {
                $theme = wp_get_theme( $slug );
                if ( $theme->exists() ) {
                    switch_theme( $slug );
                    return $slug;
                }
            }
        }

        $current = wp_get_theme();
        return $current->get_stylesheet();
    }

    /**
     * Find logo attachment and wire it as site/custom logo + PedagoLens brand logo option.
     */
    private static function assign_logo(): string {
        $logo_id = self::find_logo_attachment_id();
        if ( $logo_id <= 0 ) {
            return '';
        }

        $logo_url = (string) wp_get_attachment_url( $logo_id );
        if ( $logo_url === '' ) {
            return '';
        }

        set_theme_mod( 'custom_logo', $logo_id );
        update_option( 'pl_brand_logo_url', esc_url_raw( $logo_url ) );

        return $logo_url;
    }

    /**
     * Locate an uploaded logo.png (or logo-*.png) in attachment metadata.
     */
    private static function find_logo_attachment_id(): int {
        $ids = get_posts( [
            'post_type'      => 'attachment',
            'posts_per_page' => 10,
            'fields'         => 'ids',
            'post_status'    => 'inherit',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_wp_attached_file',
                    'value'   => 'logo.png',
                    'compare' => 'LIKE',
                ],
                [
                    'key'     => '_wp_attached_file',
                    'value'   => 'logo-',
                    'compare' => 'LIKE',
                ],
            ],
        ] );

        if ( ! empty( $ids ) ) {
            return (int) $ids[0];
        }

        return 0;
    }
}
