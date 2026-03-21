<?php
/**
 * PedagoLens_Core_Settings
 *
 * Page de réglages unifiée avec 4 onglets :
 * 1. IA & Bedrock
 * 2. Profils d'apprenants
 * 3. Comportement
 * 4. Avancé
 */

defined( 'ABSPATH' ) || exit;

class PedagoLens_Core_Settings {

    private const MENU_SLUG  = 'pl-core-settings';
    private const NONCE_SAVE = 'pl_core_settings_save';
    private const NONCE_AJAX = 'pl_core_settings_ajax';

    // =========================================================================
    // Bootstrap
    // =========================================================================

    public static function register(): void {
        add_action( 'admin_menu', [ self::class, 'add_menu' ], 20 );
        add_action( 'admin_post_pl_save_core_settings', [ self::class, 'handle_save' ] );
        add_action( 'wp_ajax_pl_test_bedrock',          [ self::class, 'ajax_test_bedrock' ] );
        add_action( 'wp_ajax_pl_reset_profiles',        [ self::class, 'ajax_reset_profiles' ] );
        add_action( 'wp_ajax_pl_clear_logs',            [ self::class, 'ajax_clear_logs' ] );
        add_action( 'wp_ajax_pl_export_config',         [ self::class, 'ajax_export_config' ] );
        add_action( 'wp_ajax_pl_import_config',         [ self::class, 'ajax_import_config' ] );
        add_action( 'admin_enqueue_scripts',            [ self::class, 'enqueue_assets' ] );
    }

    public static function add_menu(): void {
        global $menu;

        $bridge_menu_exists = false;
        if ( is_array( $menu ) ) {
            foreach ( $menu as $item ) {
                if ( isset( $item[2] ) && $item[2] === 'pl-api-bridge-settings' ) {
                    $bridge_menu_exists = true;
                    break;
                }
            }
        }

        $parent = $bridge_menu_exists ? 'pl-api-bridge-settings' : 'pl-pedagolens';

        add_submenu_page(
            $parent,
            __( 'Réglages PédagoLens', 'pedagolens-core' ),
            __( 'Réglages', 'pedagolens-core' ),
            'manage_options',
            self::MENU_SLUG,
            [ self::class, 'render_page' ]
        );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, self::MENU_SLUG ) ) {
            return;
        }
        wp_enqueue_script(
            'pl-core-settings',
            PEDAGOLENS_PLUGIN_URL . 'assets/js/core-settings.js',
            [ 'jquery' ],
            PEDAGOLENS_VERSION,
            true
        );
        wp_localize_script( 'pl-core-settings', 'plCoreSettings', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
        ] );
        wp_enqueue_style(
            'pl-core-settings',
            PEDAGOLENS_PLUGIN_URL . 'assets/css/core-settings.css',
            [],
            PEDAGOLENS_VERSION
        );
    }

    // =========================================================================
    // Page principale avec 4 onglets
    // =========================================================================

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'pedagolens-core' ) );
        }

        $tab = sanitize_key( $_GET['tab'] ?? 'ia' );
        $updated = isset( $_GET['updated'] );
        ?>
        <div class="wrap pl-settings-wrap">
            <h1><?php esc_html_e( 'Réglages PédagoLens', 'pedagolens-core' ); ?></h1>

            <?php if ( $updated ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Réglages enregistrés.', 'pedagolens-core' ); ?></p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <?php
                $tabs = [
                    'ia'       => __( 'IA & Bedrock', 'pedagolens-core' ),
                    'profiles' => __( 'Profils d\'apprenants', 'pedagolens-core' ),
                    'behavior' => __( 'Comportement', 'pedagolens-core' ),
                    'advanced' => __( 'Avancé', 'pedagolens-core' ),
                ];
                foreach ( $tabs as $key => $label ) :
                    $active = $tab === $key ? ' nav-tab-active' : '';
                    $url    = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $key );
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="nav-tab<?php echo $active; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="pl-tab-content">
                <?php
                match ( $tab ) {
                    'profiles' => self::render_tab_profiles(),
                    'behavior' => self::render_tab_behavior(),
                    'advanced' => self::render_tab_advanced(),
                    default    => self::render_tab_ia(),
                };
                ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Tab 1 — IA & Bedrock
    // =========================================================================

    private static function render_tab_ia(): void {
        $mode   = PedagoLens_API_Bridge::get_ai_mode();
        $creds  = PedagoLens_API_Bridge::get_aws_credentials();
        $config = PedagoLens_API_Bridge::get_bedrock_config();
        $models = PedagoLens_API_Bridge::get_available_models();
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( self::NONCE_SAVE ); ?>
            <input type="hidden" name="action" value="pl_save_core_settings">
            <input type="hidden" name="pl_active_tab" value="ia">

            <h3><?php esc_html_e( 'Mode IA', 'pedagolens-core' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="pl_ai_mode"><?php esc_html_e( 'Mode actif', 'pedagolens-core' ); ?></label></th>
                    <td>
                        <select name="pl_ai_mode" id="pl_ai_mode">
                            <option value="mock" <?php selected( $mode, 'mock' ); ?>><?php esc_html_e( 'Mock (démo sans AWS)', 'pedagolens-core' ); ?></option>
                            <option value="bedrock" <?php selected( $mode, 'bedrock' ); ?>><?php esc_html_e( 'Bedrock (appels AWS réels)', 'pedagolens-core' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'En mode mock, aucun appel AWS n\'est effectué.', 'pedagolens-core' ); ?></p>
                    </td>
                </tr>
            </table>

            <hr>
            <h3><?php esc_html_e( 'Credentials AWS', 'pedagolens-core' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="pl_aws_access_key_id"><?php esc_html_e( 'Access Key ID', 'pedagolens-core' ); ?></label></th>
                    <td>
                        <div class="pl-password-field">
                            <input type="password" name="pl_aws_access_key_id" id="pl_aws_access_key_id" value="<?php echo esc_attr( $creds['access_key_id'] ); ?>" class="regular-text pl-secret-input" autocomplete="off">
                            <button type="button" class="button button-small pl-toggle-password" data-target="pl_aws_access_key_id" aria-label="<?php esc_attr_e( 'Afficher / Masquer', 'pedagolens-core' ); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="pl_aws_secret_access_key"><?php esc_html_e( 'Secret Access Key', 'pedagolens-core' ); ?></label></th>
                    <td>
                        <div class="pl-password-field">
                            <input type="password" name="pl_aws_secret_access_key" id="pl_aws_secret_access_key" value="<?php echo esc_attr( $creds['secret_access_key'] ); ?>" class="regular-text pl-secret-input" autocomplete="new-password">
                            <button type="button" class="button button-small pl-toggle-password" data-target="pl_aws_secret_access_key" aria-label="<?php esc_attr_e( 'Afficher / Masquer', 'pedagolens-core' ); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pl_aws_session_token"><?php esc_html_e( 'Session Token', 'pedagolens-core' ); ?></label>
                        <span class="description" style="font-weight:normal;font-size:12px;">(<?php esc_html_e( 'optionnel', 'pedagolens-core' ); ?>)</span>
                    </th>
                    <td>
                        <div class="pl-password-field">
                            <input type="password" name="pl_aws_session_token" id="pl_aws_session_token" value="<?php echo esc_attr( $creds['session_token'] ); ?>" class="regular-text pl-secret-input" autocomplete="new-password">
                            <button type="button" class="button button-small pl-toggle-password" data-target="pl_aws_session_token" aria-label="<?php esc_attr_e( 'Afficher / Masquer', 'pedagolens-core' ); ?>">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                        </div>
                        <p class="description"><?php esc_html_e( 'Requis pour les credentials temporaires AWS SSO.', 'pedagolens-core' ); ?></p>
                    </td>
                </tr>
            </table>

            <hr>
            <h3><?php esc_html_e( 'Configuration Bedrock', 'pedagolens-core' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="pl_bedrock_region"><?php esc_html_e( 'Région AWS', 'pedagolens-core' ); ?></label></th>
                    <td><input type="text" name="pl_bedrock_region" id="pl_bedrock_region" value="<?php echo esc_attr( $config['region'] ); ?>" class="regular-text" placeholder="us-east-1"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pl_bedrock_model_id"><?php esc_html_e( 'Modèle Claude', 'pedagolens-core' ); ?></label></th>
                    <td>
                        <select name="pl_bedrock_model_id" id="pl_bedrock_model_id">
                            <?php foreach ( $models as $model ) : ?>
                                <option value="<?php echo esc_attr( $model ); ?>" <?php selected( $config['model_id'], $model ); ?>><?php echo esc_html( $model ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="pl_bedrock_max_tokens"><?php esc_html_e( 'Max Tokens', 'pedagolens-core' ); ?></label></th>
                    <td><input type="number" name="pl_bedrock_max_tokens" id="pl_bedrock_max_tokens" value="<?php echo esc_attr( $config['max_tokens'] ); ?>" min="100" max="10000" step="100" class="small-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pl_bedrock_temperature"><?php esc_html_e( 'Température', 'pedagolens-core' ); ?></label></th>
                    <td>
                        <input type="number" name="pl_bedrock_temperature" id="pl_bedrock_temperature" value="<?php echo esc_attr( $config['temperature'] ); ?>" min="0" max="1" step="0.05" class="small-text">
                        <span class="description">(0.0 – 1.0)</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="pl_bedrock_timeout"><?php esc_html_e( 'Timeout (secondes)', 'pedagolens-core' ); ?></label></th>
                    <td><input type="number" name="pl_bedrock_timeout" id="pl_bedrock_timeout" value="<?php echo esc_attr( $config['timeout'] ); ?>" min="5" max="120" step="5" class="small-text"></td>
                </tr>
            </table>

            <hr>
            <h3><?php esc_html_e( 'Test de connexion', 'pedagolens-core' ); ?></h3>
            <p>
                <button type="button" id="pl-test-bedrock-btn" class="button button-secondary"><?php esc_html_e( 'Tester la connexion Bedrock', 'pedagolens-core' ); ?></button>
                <span id="pl-test-bedrock-result" class="pl-ajax-feedback"></span>
            </p>

            <?php submit_button( __( 'Enregistrer les réglages IA', 'pedagolens-core' ) ); ?>
        </form>
        <?php
    }

    // =========================================================================
    // Tab 2 — Profils d'apprenants
    // =========================================================================

    private static function render_tab_profiles(): void {
        $profiles     = PedagoLens_Profile_Manager::get_all( active_only: false );
        $total        = count( $profiles );
        $active_count = count( array_filter( $profiles, fn( $p ) => $p['is_active'] ) );
        ?>
        <h3><?php esc_html_e( 'Profils pédagogiques', 'pedagolens-core' ); ?></h3>
        <p class="pl-profile-summary">
            <?php
            printf(
                /* translators: %1$d total profiles, %2$d active, %3$d inactive */
                esc_html__( '%1$d profil(s) au total — %2$d actif(s), %3$d inactif(s)', 'pedagolens-core' ),
                $total,
                $active_count,
                $total - $active_count
            );
            ?>
        </p>

        <table class="widefat striped pl-profiles-list">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Nom', 'pedagolens-core' ); ?></th>
                    <th><?php esc_html_e( 'Slug', 'pedagolens-core' ); ?></th>
                    <th><?php esc_html_e( 'Statut', 'pedagolens-core' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'pedagolens-core' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'pedagolens-core' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $profiles ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'Aucun profil trouvé.', 'pedagolens-core' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $profiles as $profile ) : ?>
                        <tr class="pl-profile-row" data-slug="<?php echo esc_attr( $profile['slug'] ); ?>">
                            <td><strong><?php echo esc_html( $profile['name'] ); ?></strong></td>
                            <td><code><?php echo esc_html( $profile['slug'] ); ?></code></td>
                            <td>
                                <?php if ( $profile['is_active'] ) : ?>
                                    <span class="pl-status-badge pl-status-active"><?php esc_html_e( 'Actif', 'pedagolens-core' ); ?></span>
                                <?php else : ?>
                                    <span class="pl-status-badge pl-status-inactive"><?php esc_html_e( 'Inactif', 'pedagolens-core' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $profile['description'] ); ?></td>
                            <td class="pl-profile-actions">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-profile-edit&slug=' . urlencode( $profile['slug'] ) ) ); ?>" class="button button-small">
                                    <?php esc_html_e( 'Modifier', 'pedagolens-core' ); ?>
                                </a>
                                <button type="button" class="button button-small pl-toggle-profile-btn" data-slug="<?php echo esc_attr( $profile['slug'] ); ?>" data-active="<?php echo $profile['is_active'] ? '1' : '0'; ?>">
                                    <?php echo $profile['is_active']
                                        ? esc_html__( 'Désactiver', 'pedagolens-core' )
                                        : esc_html__( 'Activer', 'pedagolens-core' ); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="pl-profiles-actions-bar">
            <button type="button" id="pl-reset-profiles-btn" class="button button-secondary"><?php esc_html_e( 'Réinitialiser les profils par défaut', 'pedagolens-core' ); ?></button>
            <span id="pl-reset-profiles-result" class="pl-ajax-feedback"></span>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-profiles' ) ); ?>" class="button button-primary" style="margin-left:8px;"><?php esc_html_e( 'Gérer les profils en détail →', 'pedagolens-core' ); ?></a>
        </div>
        <?php
    }

    // =========================================================================
    // Tab 3 — Comportement
    // =========================================================================

    private static function render_tab_behavior(): void {
        $guardrail_level      = get_option( 'pl_guardrail_level', 'moderate' );
        $twin_max_messages    = get_option( 'pl_twin_max_messages', 50 );
        $autosave_interval    = get_option( 'pl_autosave_interval', 30 );
        $enable_notifications = get_option( 'pl_enable_notifications', true );
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( self::NONCE_SAVE ); ?>
            <input type="hidden" name="action" value="pl_save_core_settings">
            <input type="hidden" name="pl_active_tab" value="behavior">

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="pl_guardrail_level"><?php esc_html_e( 'Sensibilité des guardrails', 'pedagolens-core' ); ?></label></th>
                    <td>
                        <select name="pl_guardrail_level" id="pl_guardrail_level">
                            <option value="strict" <?php selected( $guardrail_level, 'strict' ); ?>><?php esc_html_e( 'Strict', 'pedagolens-core' ); ?></option>
                            <option value="moderate" <?php selected( $guardrail_level, 'moderate' ); ?>><?php esc_html_e( 'Modéré', 'pedagolens-core' ); ?></option>
                            <option value="permissive" <?php selected( $guardrail_level, 'permissive' ); ?>><?php esc_html_e( 'Permissif', 'pedagolens-core' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Contrôle la rigueur du filtrage des demandes étudiantes.', 'pedagolens-core' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="pl_twin_max_messages"><?php esc_html_e( 'Messages max par session', 'pedagolens-core' ); ?></label></th>
                    <td>
                        <input type="number" name="pl_twin_max_messages" id="pl_twin_max_messages" value="<?php echo esc_attr( $twin_max_messages ); ?>" min="5" max="500" step="5" class="small-text">
                        <p class="description"><?php esc_html_e( 'Nombre maximum de messages du jumeau par session.', 'pedagolens-core' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="pl_autosave_interval"><?php esc_html_e( 'Intervalle auto-save (secondes)', 'pedagolens-core' ); ?></label></th>
                    <td>
                        <input type="number" name="pl_autosave_interval" id="pl_autosave_interval" value="<?php echo esc_attr( $autosave_interval ); ?>" min="10" max="300" step="5" class="small-text">
                        <p class="description"><?php esc_html_e( 'Fréquence de sauvegarde automatique en secondes.', 'pedagolens-core' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Notifications', 'pedagolens-core' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="pl_enable_notifications" value="1" <?php checked( $enable_notifications ); ?>>
                            <?php esc_html_e( 'Activer les notifications', 'pedagolens-core' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Notifications admin lors d\'événements importants.', 'pedagolens-core' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Enregistrer le comportement', 'pedagolens-core' ) ); ?>
        </form>
        <?php
    }

    // =========================================================================
    // Tab 4 — Avancé
    // =========================================================================

    private static function render_tab_advanced(): void {
        $debug_mode   = get_option( 'pl_debug_mode', false );
        $ai_mode      = PedagoLens_API_Bridge::get_ai_mode();
        $active_plugins = get_option( 'active_plugins', [] );
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( self::NONCE_SAVE ); ?>
            <input type="hidden" name="action" value="pl_save_core_settings">
            <input type="hidden" name="pl_active_tab" value="advanced">

            <h3><?php esc_html_e( 'Debug', 'pedagolens-core' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Mode debug', 'pedagolens-core' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="pl_debug_mode" value="1" <?php checked( $debug_mode ); ?>>
                            <?php esc_html_e( 'Activer le mode debug (logs détaillés)', 'pedagolens-core' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Enregistrer', 'pedagolens-core' ) ); ?>
        </form>

        <hr>
        <h3><?php esc_html_e( 'Maintenance', 'pedagolens-core' ); ?></h3>
        <p>
            <button type="button" id="pl-clear-logs-btn" class="button button-secondary"><?php esc_html_e( 'Vider les logs', 'pedagolens-core' ); ?></button>
            <span id="pl-clear-logs-result" class="pl-ajax-feedback"></span>
        </p>

        <hr>
        <h3><?php esc_html_e( 'Export / Import de configuration', 'pedagolens-core' ); ?></h3>
        <p>
            <button type="button" id="pl-export-config-btn" class="button button-secondary"><?php esc_html_e( 'Exporter la configuration (JSON)', 'pedagolens-core' ); ?></button>
        </p>
        <p>
            <label for="pl-import-config-input" class="button button-secondary" style="cursor:pointer;">
                <?php esc_html_e( 'Importer une configuration', 'pedagolens-core' ); ?>
            </label>
            <input type="file" id="pl-import-config-input" accept=".json" style="display:none;">
            <span id="pl-import-config-result" class="pl-ajax-feedback"></span>
        </p>

        <hr>
        <h3><?php esc_html_e( 'Informations système', 'pedagolens-core' ); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e( 'Version du plugin', 'pedagolens-core' ); ?></th>
                <td><code><?php echo esc_html( PEDAGOLENS_VERSION ); ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Mode IA actuel', 'pedagolens-core' ); ?></th>
                <td>
                    <span class="pl-status-badge <?php echo $ai_mode === 'bedrock' ? 'pl-status-active' : 'pl-status-inactive'; ?>">
                        <?php echo esc_html( strtoupper( $ai_mode ) ); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Version PHP', 'pedagolens-core' ); ?></th>
                <td><code><?php echo esc_html( phpversion() ); ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Version WordPress', 'pedagolens-core' ); ?></th>
                <td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Plugins actifs', 'pedagolens-core' ); ?></th>
                <td><code><?php echo (int) count( $active_plugins ); ?></code></td>
            </tr>
        </table>
        <?php
    }

    // =========================================================================
    // handle_save() — Save IA tab + Behavior tab + Advanced tab
    // =========================================================================

    public static function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'pedagolens-core' ) );
        }

        check_admin_referer( self::NONCE_SAVE );

        $tab = sanitize_key( $_POST['pl_active_tab'] ?? 'ia' );

        switch ( $tab ) {
            case 'ia':
                // Mode IA
                $mode = sanitize_text_field( $_POST['pl_ai_mode'] ?? 'mock' );
                update_option( 'pl_ai_mode', in_array( $mode, [ 'mock', 'bedrock' ], true ) ? $mode : 'mock' );

                // AWS Credentials — don't overwrite with empty values
                $access_key = sanitize_text_field( $_POST['pl_aws_access_key_id'] ?? '' );
                $secret_key = sanitize_text_field( $_POST['pl_aws_secret_access_key'] ?? '' );
                $session    = sanitize_text_field( $_POST['pl_aws_session_token'] ?? '' );

                if ( $access_key !== '' ) {
                    update_option( 'pl_aws_access_key_id', $access_key );
                }
                if ( $secret_key !== '' ) {
                    update_option( 'pl_aws_secret_access_key', $secret_key );
                }
                if ( $session !== '' ) {
                    update_option( 'pl_aws_session_token', $session );
                }

                // Bedrock config
                update_option( 'pl_bedrock_region',      sanitize_text_field( $_POST['pl_bedrock_region'] ?? 'us-east-1' ) );
                update_option( 'pl_bedrock_model_id',    sanitize_text_field( $_POST['pl_bedrock_model_id'] ?? '' ) );
                update_option( 'pl_bedrock_max_tokens',  absint( $_POST['pl_bedrock_max_tokens'] ?? 1500 ) );
                update_option( 'pl_bedrock_temperature', floatval( $_POST['pl_bedrock_temperature'] ?? 0.3 ) );
                update_option( 'pl_bedrock_timeout',     absint( $_POST['pl_bedrock_timeout'] ?? 30 ) );
                break;

            case 'behavior':
                $level = sanitize_text_field( $_POST['pl_guardrail_level'] ?? 'moderate' );
                update_option( 'pl_guardrail_level', in_array( $level, [ 'strict', 'moderate', 'permissive' ], true ) ? $level : 'moderate' );
                update_option( 'pl_twin_max_messages',    absint( $_POST['pl_twin_max_messages'] ?? 50 ) );
                update_option( 'pl_autosave_interval',    absint( $_POST['pl_autosave_interval'] ?? 30 ) );
                update_option( 'pl_enable_notifications', ! empty( $_POST['pl_enable_notifications'] ) );
                break;

            case 'advanced':
                update_option( 'pl_debug_mode', ! empty( $_POST['pl_debug_mode'] ) );
                break;
        }

        set_transient( 'pl_core_settings_saved', true, 30 );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $tab . '&updated=1' ) );
        exit;
    }

    // =========================================================================
    // AJAX — Test Bedrock connection
    // =========================================================================

    public static function ajax_test_bedrock(): void {
        check_ajax_referer( self::NONCE_AJAX, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'pedagolens-core' ) ] );
        }

        // Delegate to API Bridge if available, otherwise do a simple endpoint check
        if ( class_exists( 'PedagoLens_API_Bridge' ) ) {
            $credentials = PedagoLens_API_Bridge::get_aws_credentials();
            $config      = PedagoLens_API_Bridge::get_bedrock_config();

            if ( empty( $credentials['access_key_id'] ) || empty( $credentials['secret_access_key'] ) ) {
                wp_send_json_error( [
                    'message' => __( 'Credentials AWS manquants. Renseignez Access Key ID et Secret Access Key.', 'pedagolens-core' ),
                ] );
            }

            $endpoint = "https://bedrock.{$config['region']}.amazonaws.com/foundation-models";
            $response = wp_remote_get( $endpoint, [ 'timeout' => 10 ] );

            if ( is_wp_error( $response ) ) {
                wp_send_json_error( [
                    'message' => sprintf( __( 'Erreur réseau : %s', 'pedagolens-core' ), $response->get_error_message() ),
                ] );
            }

            $code = wp_remote_retrieve_response_code( $response );

            if ( $code === 200 || $code === 403 ) {
                wp_send_json_success( [
                    'message' => sprintf(
                        __( 'Endpoint Bedrock accessible (HTTP %d). Région : %s — Modèle : %s', 'pedagolens-core' ),
                        $code,
                        esc_html( $config['region'] ),
                        esc_html( $config['model_id'] )
                    ),
                ] );
            }

            wp_send_json_error( [
                'message' => sprintf( __( 'Réponse inattendue : HTTP %d', 'pedagolens-core' ), $code ),
            ] );
        }

        wp_send_json_error( [ 'message' => __( 'API Bridge non disponible.', 'pedagolens-core' ) ] );
    }

    // =========================================================================
    // AJAX — Reset profiles to defaults
    // =========================================================================

    public static function ajax_reset_profiles(): void {
        check_ajax_referer( self::NONCE_AJAX, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'pedagolens-core' ) ] );
        }

        // Delete all existing profile options
        $index = PedagoLens_Profile_Manager::get_index();
        foreach ( $index as $slug ) {
            delete_option( 'pl_profile_' . $slug );
        }
        delete_option( 'pl_profile_index' );

        // Re-seed defaults
        PedagoLens_Profile_Manager::seed_defaults();

        wp_send_json_success( [ 'message' => __( 'Profils réinitialisés avec succès.', 'pedagolens-core' ) ] );
    }

    // =========================================================================
    // AJAX — Clear debug logs
    // =========================================================================

    public static function ajax_clear_logs(): void {
        check_ajax_referer( self::NONCE_AJAX, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'pedagolens-core' ) ] );
        }

        $log_file = WP_CONTENT_DIR . '/debug.log';

        if ( file_exists( $log_file ) && is_writable( $log_file ) ) {
            file_put_contents( $log_file, '' );
            wp_send_json_success( [ 'message' => __( 'Logs vidés avec succès.', 'pedagolens-core' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Fichier de log introuvable ou non accessible en écriture.', 'pedagolens-core' ) ] );
        }
    }

    // =========================================================================
    // AJAX — Export config as JSON
    // =========================================================================

    public static function ajax_export_config(): void {
        check_ajax_referer( self::NONCE_AJAX, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'pedagolens-core' ) ] );
        }

        // Collect all pl_* options
        $option_keys = [
            'pl_ai_mode',
            'pl_bedrock_region',
            'pl_bedrock_model_id',
            'pl_bedrock_max_tokens',
            'pl_bedrock_temperature',
            'pl_bedrock_timeout',
            'pl_guardrail_level',
            'pl_twin_max_messages',
            'pl_autosave_interval',
            'pl_enable_notifications',
            'pl_debug_mode',
        ];

        $config = [];
        foreach ( $option_keys as $key ) {
            $config[ $key ] = get_option( $key );
        }

        // Include profiles
        $profiles = PedagoLens_Profile_Manager::get_all( active_only: false );
        $config['_profiles'] = $profiles;

        $config['_exported_at'] = gmdate( 'c' );
        $config['_version']     = PEDAGOLENS_VERSION;

        wp_send_json_success( [ 'config' => $config ] );
    }

    // =========================================================================
    // AJAX — Import config from JSON
    // =========================================================================

    public static function ajax_import_config(): void {
        check_ajax_referer( self::NONCE_AJAX, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Accès refusé.', 'pedagolens-core' ) ] );
        }

        $json = wp_unslash( $_POST['json'] ?? '' );
        $data = json_decode( $json, true );

        if ( ! is_array( $data ) ) {
            wp_send_json_error( [ 'message' => __( 'JSON invalide.', 'pedagolens-core' ) ] );
        }

        $allowed_keys = [
            'pl_ai_mode',
            'pl_bedrock_region',
            'pl_bedrock_model_id',
            'pl_bedrock_max_tokens',
            'pl_bedrock_temperature',
            'pl_bedrock_timeout',
            'pl_guardrail_level',
            'pl_twin_max_messages',
            'pl_autosave_interval',
            'pl_enable_notifications',
            'pl_debug_mode',
        ];

        $updated = 0;
        foreach ( $data as $key => $value ) {
            if ( in_array( $key, $allowed_keys, true ) ) {
                update_option( $key, $value );
                $updated++;
            }
        }

        // Import profiles if present
        if ( ! empty( $data['_profiles'] ) && is_array( $data['_profiles'] ) ) {
            foreach ( $data['_profiles'] as $profile ) {
                if ( ! empty( $profile['slug'] ) ) {
                    // Overwrite existing or create new
                    $existing = PedagoLens_Profile_Manager::get( $profile['slug'] );
                    if ( $existing ) {
                        delete_option( 'pl_profile_' . $profile['slug'] );
                        $index = PedagoLens_Profile_Manager::get_index();
                        $index = array_values( array_diff( $index, [ $profile['slug'] ] ) );
                        update_option( 'pl_profile_index', $index );
                    }
                    PedagoLens_Profile_Manager::save( $profile );
                    $updated++;
                }
            }
        }

        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %d: number of items imported */
                __( '%d éléments importés avec succès.', 'pedagolens-core' ),
                $updated
            ),
        ] );
    }
}
