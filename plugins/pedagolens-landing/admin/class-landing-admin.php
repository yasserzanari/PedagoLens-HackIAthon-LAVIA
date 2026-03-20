<?php
/**
 * PedagoLens_Landing_Admin
 *
 * Page de settings admin pour la landing page.
 */

defined( 'ABSPATH' ) || exit;

class PedagoLens_Landing_Admin {

    private const MENU_SLUG  = 'pl-landing-settings';
    private const NONCE_SAVE = 'pl_landing_settings_save';

    public static function register(): void {
        add_action( 'admin_menu',                          [ self::class, 'add_menu' ] );
        add_action( 'admin_post_pl_save_landing_settings', [ self::class, 'handle_save' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'pl-pedagolens',
            __( 'Landing Page', 'pedagolens-landing' ),
            __( 'Landing Page', 'pedagolens-landing' ),
            'manage_options',
            self::MENU_SLUG,
            [ self::class, 'render_page' ]
        );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'pedagolens-landing' ) );
        }

        $s = PedagoLens_Landing::get_settings();

        if ( isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Paramètres enregistrés.', 'pedagolens-landing' ) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Landing Page — Configuration', 'pedagolens-landing' ); ?></h1>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( self::NONCE_SAVE, '_pl_landing_nonce' ); ?>
                <input type="hidden" name="action" value="pl_save_landing_settings">

                <h2><?php esc_html_e( 'Section Hero', 'pedagolens-landing' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="hero_title"><?php esc_html_e( 'Titre', 'pedagolens-landing' ); ?></label></th>
                        <td><input type="text" id="hero_title" name="hero_title" value="<?php echo esc_attr( $s['hero_title'] ); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th><label for="hero_subtitle"><?php esc_html_e( 'Sous-titre', 'pedagolens-landing' ); ?></label></th>
                        <td><input type="text" id="hero_subtitle" name="hero_subtitle" value="<?php echo esc_attr( $s['hero_subtitle'] ); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th><label for="cta_text"><?php esc_html_e( 'Texte CTA', 'pedagolens-landing' ); ?></label></th>
                        <td><input type="text" id="cta_text" name="cta_text" value="<?php echo esc_attr( $s['cta_text'] ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="cta_url"><?php esc_html_e( 'URL CTA', 'pedagolens-landing' ); ?></label></th>
                        <td><input type="url" id="cta_url" name="cta_url" value="<?php echo esc_attr( $s['cta_url'] ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="primary_color"><?php esc_html_e( 'Couleur principale', 'pedagolens-landing' ); ?></label></th>
                        <td><input type="color" id="primary_color" name="primary_color" value="<?php echo esc_attr( $s['primary_color'] ); ?>"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Sections visibles', 'pedagolens-landing' ); ?></h2>
                <table class="form-table">
                    <?php
                    $section_labels = [
                        'hero'         => __( 'Hero', 'pedagolens-landing' ),
                        'features'     => __( 'Fonctionnalités', 'pedagolens-landing' ),
                        'pricing'      => __( 'Tarifs', 'pedagolens-landing' ),
                        'testimonials' => __( 'Témoignages', 'pedagolens-landing' ),
                    ];
                    foreach ( $section_labels as $key => $label ) :
                        $checked = ! empty( $s['sections'][ $key ] );
                        ?>
                        <tr>
                            <th><?php echo esc_html( $label ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sections[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?>>
                                    <?php esc_html_e( 'Afficher', 'pedagolens-landing' ); ?>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2><?php esc_html_e( 'Shortcodes disponibles', 'pedagolens-landing' ); ?></h2>
                <table class="widefat striped">
                    <thead><tr><th>Page</th><th>Shortcode</th><th>Slug</th></tr></thead>
                    <tbody>
                        <tr><td>Landing</td><td><code>[pedagolens_landing]</code></td><td>/</td></tr>
                        <tr><td>Dashboard enseignant</td><td><code>[pedagolens_teacher_dashboard]</code></td><td>/dashboard-enseignant</td></tr>
                        <tr><td>Dashboard étudiant</td><td><code>[pedagolens_student_dashboard]</code></td><td>/dashboard-etudiant</td></tr>
                        <tr><td>Cours &amp; Projets</td><td><code>[pedagolens_courses]</code></td><td>/cours-projets</td></tr>
                        <tr><td>Workbench</td><td><code>[pedagolens_workbench]</code></td><td>/workbench</td></tr>
                        <tr><td>Compte</td><td><code>[pedagolens_account]</code></td><td>/compte</td></tr>
                    </tbody>
                </table>

                <?php submit_button( __( 'Enregistrer', 'pedagolens-landing' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'pedagolens-landing' ) );
        }

        check_admin_referer( self::NONCE_SAVE, '_pl_landing_nonce' );

        $raw = get_option( 'pl_landing_settings', [] );
        $s   = is_string( $raw ) ? ( json_decode( $raw, true ) ?? [] ) : (array) $raw;

        $s['hero_title']    = sanitize_text_field( $_POST['hero_title']    ?? '' );
        $s['hero_subtitle'] = sanitize_text_field( $_POST['hero_subtitle'] ?? '' );
        $s['cta_text']      = sanitize_text_field( $_POST['cta_text']      ?? '' );
        $s['cta_url']       = esc_url_raw( $_POST['cta_url'] ?? '' );
        $s['primary_color'] = sanitize_hex_color( $_POST['primary_color'] ?? '#2271b1' ) ?: '#2271b1';

        $sections_post = (array) ( $_POST['sections'] ?? [] );
        $s['sections'] = [
            'hero'         => ! empty( $sections_post['hero'] ),
            'features'     => ! empty( $sections_post['features'] ),
            'pricing'      => ! empty( $sections_post['pricing'] ),
            'testimonials' => ! empty( $sections_post['testimonials'] ),
        ];

        update_option( 'pl_landing_settings', wp_json_encode( $s ) );

        wp_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&saved=1' ) );
        exit;
    }
}
