<?php
/**
 * PedagoLens_Twin_Admin
 *
 * Interface admin du jumeau numérique :
 * - Page de configuration (garde-fous, comportement)
 * - Interface de conversation étudiant (shortcode + page admin)
 * - Historique des sessions
 */

defined( 'ABSPATH' ) || exit;

class PedagoLens_Twin_Admin {

    private const MENU_SLUG  = 'pl-student-twin';
    private const NONCE_AJAX = 'pl_twin_ajax';
    private const NONCE_SAVE = 'pl_twin_settings_save';

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function register(): void {
        add_action( 'admin_menu',                        [ self::class, 'add_menu' ] );
        add_action( 'admin_post_pl_save_twin_settings',  [ self::class, 'handle_save_settings' ] );
        add_action( 'wp_ajax_pl_twin_start_session',     [ self::class, 'ajax_start_session' ] );
        add_action( 'wp_ajax_pl_twin_send_message',      [ self::class, 'ajax_send_message' ] );
        add_action( 'wp_ajax_pl_twin_end_session',       [ self::class, 'ajax_end_session' ] );
        add_action( 'wp_ajax_pl_twin_get_history',       [ self::class, 'ajax_get_history' ] );
        add_action( 'wp_ajax_nopriv_pl_twin_start_session', [ self::class, 'ajax_start_session' ] );
        add_action( 'wp_ajax_nopriv_pl_twin_send_message',  [ self::class, 'ajax_send_message' ] );
        add_action( 'wp_ajax_nopriv_pl_twin_end_session',   [ self::class, 'ajax_end_session' ] );
        add_action( 'admin_enqueue_scripts',             [ self::class, 'enqueue_assets' ] );
        add_shortcode( 'pedagolens_twin',                [ self::class, 'render_shortcode' ] );
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

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
            __( 'Jumeau Étudiant', 'pedagolens-student-twin' ),
            __( 'Jumeau Étudiant', 'pedagolens-student-twin' ),
            'manage_options',
            self::MENU_SLUG,
            [ self::class, 'render_page' ]
        );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public static function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, self::MENU_SLUG ) ) {
            return;
        }

        wp_enqueue_script(
            'pl-twin-admin',
            PL_TWIN_PLUGIN_URL . 'assets/js/twin-admin.js',
            [ 'jquery' ],
            PL_TWIN_VERSION,
            true
        );

        wp_localize_script( 'pl-twin-admin', 'plTwin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
        ] );

        wp_enqueue_style(
            'pl-twin-admin',
            PL_TWIN_PLUGIN_URL . 'assets/css/twin-admin.css',
            [],
            PL_TWIN_VERSION
        );
    }

    // -------------------------------------------------------------------------
    // Page principale (onglets)
    // -------------------------------------------------------------------------

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'pedagolens-student-twin' ) );
        }

        $tab = sanitize_key( $_GET['tab'] ?? 'settings' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Jumeau Numérique Étudiant', 'pedagolens-student-twin' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php
                $tabs = [
                    'settings' => __( 'Configuration', 'pedagolens-student-twin' ),
                    'demo'     => __( 'Démo conversation', 'pedagolens-student-twin' ),
                    'sessions' => __( 'Sessions', 'pedagolens-student-twin' ),
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

            <div class="pl-twin-tab-content">
                <?php
                match ( $tab ) {
                    'demo'     => self::render_demo_tab(),
                    'sessions' => self::render_sessions_tab(),
                    default    => self::render_settings_tab(),
                };
                ?>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Onglet Configuration
    // -------------------------------------------------------------------------

    private static function render_settings_tab(): void {
        $raw    = get_option( 'pl_guardrails_config', [] );
        $config = is_string( $raw ) ? ( json_decode( $raw, true ) ?? [] ) : (array) $raw;

        $max_length       = (int) ( $config['max_length'] ?? 2000 );
        $forbidden_topics = implode( "\n", (array) ( $config['forbidden_topics'] ?? [] ) );
        $ai_enabled       = ! empty( $config['ai_guardrail_enabled'] );

        $twin_raw      = get_option( 'pl_student_twin_settings', [] );
        $twin_settings = is_string( $twin_raw ) ? ( json_decode( $twin_raw, true ) ?? [] ) : (array) $twin_raw;
        $twin_name     = sanitize_text_field( $twin_settings['twin_name'] ?? 'Léa' );
        $twin_intro    = sanitize_textarea_field( $twin_settings['intro_message'] ?? '' );

        if ( isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Paramètres enregistrés.', 'pedagolens-student-twin' ) . '</p></div>';
        }
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( self::NONCE_SAVE, '_pl_twin_nonce' ); ?>
            <input type="hidden" name="action" value="pl_save_twin_settings">

            <h2><?php esc_html_e( 'Comportement du jumeau', 'pedagolens-student-twin' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="twin_name"><?php esc_html_e( 'Nom du jumeau', 'pedagolens-student-twin' ); ?></label></th>
                    <td><input type="text" id="twin_name" name="twin_name" value="<?php echo esc_attr( $twin_name ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="intro_message"><?php esc_html_e( "Message d'introduction", 'pedagolens-student-twin' ); ?></label></th>
                    <td>
                        <textarea id="intro_message" name="intro_message" rows="4" class="large-text"><?php echo esc_textarea( $twin_intro ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Message affiché au début de chaque session.', 'pedagolens-student-twin' ); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Garde-fous', 'pedagolens-student-twin' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="max_length"><?php esc_html_e( 'Longueur max du message (caractères)', 'pedagolens-student-twin' ); ?></label></th>
                    <td><input type="number" id="max_length" name="max_length" value="<?php echo esc_attr( $max_length ); ?>" min="100" max="10000" class="small-text"></td>
                </tr>
                <tr>
                    <th><label for="forbidden_topics"><?php esc_html_e( 'Sujets interdits', 'pedagolens-student-twin' ); ?></label></th>
                    <td>
                        <textarea id="forbidden_topics" name="forbidden_topics" rows="6" class="large-text"><?php echo esc_textarea( $forbidden_topics ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Un mot-clé par ligne.', 'pedagolens-student-twin' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Vérification IA', 'pedagolens-student-twin' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="ai_guardrail_enabled" value="1" <?php checked( $ai_enabled ); ?>>
                            <?php esc_html_e( 'Activer la vérification IA des messages (appel Bedrock supplémentaire)', 'pedagolens-student-twin' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Enregistrer', 'pedagolens-student-twin' ) ); ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Onglet Démo
    // -------------------------------------------------------------------------

    private static function render_demo_tab(): void {
        $twin_raw      = get_option( 'pl_student_twin_settings', [] );
        $twin_settings = is_string( $twin_raw ) ? ( json_decode( $twin_raw, true ) ?? [] ) : (array) $twin_raw;
        $twin_name     = esc_html( $twin_settings['twin_name'] ?? 'Léa' );
        $intro_message = esc_html( $twin_settings['intro_message'] ?? "Bonjour ! Je suis ton jumeau numérique. Comment puis-je t'aider avec ton cours ?" );

        $courses = get_posts( [ 'post_type' => 'pl_course', 'posts_per_page' => -1, 'post_status' => 'publish' ] );
        ?>
        <div class="pl-twin-demo-wrap">
            <div class="pl-twin-setup">
                <h3><?php esc_html_e( 'Démarrer une session de démo', 'pedagolens-student-twin' ); ?></h3>
                <label for="pl-demo-course"><?php esc_html_e( 'Cours :', 'pedagolens-student-twin' ); ?></label>
                <select id="pl-demo-course">
                    <option value="0"><?php esc_html_e( '— Sélectionner un cours —', 'pedagolens-student-twin' ); ?></option>
                    <?php foreach ( $courses as $c ) : ?>
                        <option value="<?php echo esc_attr( $c->ID ); ?>"><?php echo esc_html( $c->post_title ); ?></option>
                    <?php endforeach; ?>
                    <?php if ( empty( $courses ) ) : ?>
                        <option value="1"><?php esc_html_e( 'Français 101 (démo)', 'pedagolens-student-twin' ); ?></option>
                    <?php endif; ?>
                </select>
                <button type="button" id="pl-twin-start" class="button button-primary"><?php esc_html_e( 'Démarrer', 'pedagolens-student-twin' ); ?></button>
            </div>

            <div id="pl-twin-chat" style="display:none;">
                <div class="pl-chat-header">
                    <strong><?php echo $twin_name; ?></strong>
                    <button type="button" id="pl-twin-end" class="button button-small"><?php esc_html_e( 'Terminer la session', 'pedagolens-student-twin' ); ?></button>
                </div>
                <div id="pl-chat-messages" class="pl-chat-messages">
                    <div class="pl-chat-bubble pl-bubble-assistant"><?php echo $intro_message; ?></div>
                </div>
                <div class="pl-chat-input-row">
                    <textarea id="pl-chat-input" rows="2" placeholder="<?php esc_attr_e( 'Pose ta question…', 'pedagolens-student-twin' ); ?>"></textarea>
                    <button type="button" id="pl-chat-send" class="button button-primary"><?php esc_html_e( 'Envoyer', 'pedagolens-student-twin' ); ?></button>
                </div>
                <div id="pl-chat-follow-ups" class="pl-chat-follow-ups"></div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Onglet Sessions
    // -------------------------------------------------------------------------

    private static function render_sessions_tab(): void {
        $sessions = get_posts( [ 'post_type' => 'pl_interaction', 'posts_per_page' => 20, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC' ] );
        ?>
        <h2><?php esc_html_e( 'Sessions récentes', 'pedagolens-student-twin' ); ?></h2>
        <?php if ( empty( $sessions ) ) : ?>
            <p><?php esc_html_e( 'Aucune session enregistrée.', 'pedagolens-student-twin' ); ?></p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Session ID', 'pedagolens-student-twin' ); ?></th>
                    <th><?php esc_html_e( 'Étudiant', 'pedagolens-student-twin' ); ?></th>
                    <th><?php esc_html_e( 'Cours', 'pedagolens-student-twin' ); ?></th>
                    <th><?php esc_html_e( 'Démarrée', 'pedagolens-student-twin' ); ?></th>
                    <th><?php esc_html_e( 'Terminée', 'pedagolens-student-twin' ); ?></th>
                    <th><?php esc_html_e( 'Messages', 'pedagolens-student-twin' ); ?></th>
                    <th><?php esc_html_e( 'Garde-fous', 'pedagolens-student-twin' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $sessions as $s ) :
                        $session_id = get_post_meta( $s->ID, '_pl_session_id', true );
                        $student_id = (int) get_post_meta( $s->ID, '_pl_student_id', true );
                        $course_id  = (int) get_post_meta( $s->ID, '_pl_course_id',  true );
                        $started    = get_post_meta( $s->ID, '_pl_started_at', true );
                        $ended      = get_post_meta( $s->ID, '_pl_ended_at',   true );
                        $raw_msgs   = get_post_meta( $s->ID, '_pl_messages', true );
                        $msgs       = is_string( $raw_msgs ) ? json_decode( $raw_msgs, true ) : [];
                        $msg_count  = is_array( $msgs ) ? count( $msgs ) : 0;
                        $raw_grd    = get_post_meta( $s->ID, '_pl_guardrails_applied', true );
                        $grd        = is_string( $raw_grd ) ? json_decode( $raw_grd, true ) : [];
                        $grd_count  = is_array( $grd ) ? count( $grd ) : 0;
                        $student    = $student_id ? get_userdata( $student_id ) : null;
                        $course     = $course_id  ? get_post( $course_id )      : null;
                        ?>
                        <tr>
                            <td><code><?php echo esc_html( mb_substr( $session_id, 0, 8 ) . '…' ); ?></code></td>
                            <td><?php echo esc_html( $student ? $student->display_name : "#{$student_id}" ); ?></td>
                            <td><?php echo esc_html( $course ? $course->post_title : "#{$course_id}" ); ?></td>
                            <td><?php echo esc_html( $started ? wp_date( 'Y-m-d H:i', strtotime( $started ) ) : '—' ); ?></td>
                            <td><?php echo esc_html( $ended ? wp_date( 'Y-m-d H:i', strtotime( $ended ) ) : '—' ); ?></td>
                            <td><?php echo esc_html( $msg_count ); ?></td>
                            <td><?php echo $grd_count > 0 ? '<span style="color:#d63638;font-weight:600;">' . esc_html( $grd_count ) . '</span>' : '0'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    // -------------------------------------------------------------------------
    // Sauvegarde des settings
    // -------------------------------------------------------------------------

    public static function handle_save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'pedagolens-student-twin' ) );
        }
        check_admin_referer( self::NONCE_SAVE, '_pl_twin_nonce' );

        $forbidden_raw = sanitize_textarea_field( $_POST['forbidden_topics'] ?? '' );
        $forbidden     = array_filter( array_map( 'trim', explode( "\n", $forbidden_raw ) ) );

        $guardrail_config = [
            'max_length'           => max( 100, (int) ( $_POST['max_length'] ?? 2000 ) ),
            'forbidden_topics'     => array_values( $forbidden ),
            'ai_guardrail_enabled' => ! empty( $_POST['ai_guardrail_enabled'] ),
        ];
        update_option( 'pl_guardrails_config', wp_json_encode( $guardrail_config ) );

        $twin_settings = [
            'twin_name'     => sanitize_text_field( $_POST['twin_name'] ?? 'Léa' ),
            'intro_message' => sanitize_textarea_field( $_POST['intro_message'] ?? '' ),
        ];
        update_option( 'pl_student_twin_settings', wp_json_encode( $twin_settings ) );

        wp_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=settings&saved=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // AJAX
    // -------------------------------------------------------------------------

    public static function ajax_start_session(): void {
        self::verify_nonce();
        $course_id  = (int) ( $_POST['course_id']  ?? 0 );
        $student_id = get_current_user_id();
        $session_id = PedagoLens_Student_Twin::start_session( $student_id, $course_id );
        if ( empty( $session_id ) ) {
            wp_send_json_error( [ 'message' => 'Impossible de démarrer la session.' ] );
        }
        wp_send_json_success( [ 'session_id' => $session_id ] );
    }

    public static function ajax_send_message(): void {
        self::verify_nonce();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $message    = sanitize_textarea_field( $_POST['message'] ?? '' );
        if ( empty( $session_id ) || empty( $message ) ) {
            wp_send_json_error( [ 'message' => 'Paramètres manquants.' ] );
        }
        $result = PedagoLens_Student_Twin::send_message( $session_id, $message );
        if ( empty( $result['success'] ) ) {
            wp_send_json_error( [ 'message' => $result['error_message'] ?? 'Erreur.' ] );
        }
        wp_send_json_success( $result );
    }

    public static function ajax_end_session(): void {
        self::verify_nonce();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $ok         = PedagoLens_Student_Twin::end_session( $session_id );
        $ok ? wp_send_json_success() : wp_send_json_error( [ 'message' => 'Session introuvable.' ] );
    }

    public static function ajax_get_history(): void {
        self::verify_nonce();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $result     = PedagoLens_Student_Twin::get_history( $session_id );
        if ( empty( $result['success'] ) ) {
            wp_send_json_error( [ 'message' => $result['error_message'] ?? 'Erreur.' ] );
        }
        wp_send_json_success( $result );
    }

    // -------------------------------------------------------------------------
    // Shortcode [pedagolens_twin] — Page complète avec header/footer
    // -------------------------------------------------------------------------

    
        public static function render_shortcode( array $atts ): string {
            $atts = shortcode_atts( [ 'course_id' => 0 ], $atts );

            // --- Navigation links ---
            $home_url      = esc_url( home_url( '/' ) );
            $dashboard_pg  = get_page_by_path( 'dashboard-enseignant' );
            $courses_pg    = get_page_by_path( 'cours-projets' );
            $twin_pg       = get_page_by_path( 'dashboard-etudiant' );
            $account_pg    = get_page_by_path( 'compte' );
            $nav_links = [
                'Accueil'   => $home_url,
                'Dashboard' => $dashboard_pg ? get_permalink( $dashboard_pg ) : admin_url( 'admin.php?page=pl-teacher-dashboard' ),
                'Cours'     => $courses_pg   ? get_permalink( $courses_pg )   : admin_url( 'admin.php?page=pl-course-workbench' ),
                'Jumeau'    => $twin_pg      ? get_permalink( $twin_pg )      : '#',
                'Compte'    => $account_pg   ? get_permalink( $account_pg )   : '#',
            ];

            // --- Not logged in ---
            if ( ! is_user_logged_in() ) {
                ob_start();
                echo self::stitch_inline_styles();
                ?>
                <div class="pls-page">
                    <?php echo self::stitch_nav( $nav_links, $home_url ); ?>
                    <main class="pls-main">
                        <div style="display:flex;align-items:center;justify-content:center;min-height:60vh;">
                            <div style="background:rgba(255,255,255,0.7);backdrop-filter:blur(16px);border:1px solid rgba(0,35,111,0.08);border-radius:1.25rem;padding:3rem;text-align:center;max-width:420px;box-shadow:0 10px 40px rgba(25,28,30,0.06);">
                                <div style="font-size:2.5rem;margin-bottom:1rem;">🔒</div>
                                <h2 style="font-family:'Manrope',sans-serif;font-weight:800;color:#00236F;font-size:1.5rem;margin:0 0 .75rem;"><?php esc_html_e( 'Accès restreint', 'pedagolens-student-twin' ); ?></h2>
                                <p style="color:#444651;font-size:.9rem;line-height:1.6;margin:0 0 1.5rem;"><?php esc_html_e( "Connectez-vous pour accéder à votre jumeau numérique et commencer à apprendre.", 'pedagolens-student-twin' ); ?></p>
                                <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" style="display:inline-flex;align-items:center;gap:.5rem;padding:.75rem 2rem;background:linear-gradient(135deg,#00236F,#1E3A8A);color:#fff;border-radius:.75rem;text-decoration:none;font-weight:700;font-size:.9rem;transition:opacity .2s;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                                    <?php esc_html_e( 'Se connecter', 'pedagolens-student-twin' ); ?>
                                </a>
                            </div>
                        </div>
                    </main>
                    <?php echo self::stitch_footer( $nav_links ); ?>
                </div>
                <?php
                return ob_get_clean();
            }

            // --- Logged-in student ---
            $twin_raw      = get_option( 'pl_student_twin_settings', [] );
            $twin_settings = is_string( $twin_raw ) ? ( json_decode( $twin_raw, true ) ?? [] ) : (array) $twin_raw;
            $twin_name     = esc_html( $twin_settings['twin_name'] ?? 'Léa' );
            $intro         = esc_html( $twin_settings['intro_message'] ?? "Bonjour ! Je suis ton jumeau numérique. Comment puis-je t'aider avec ton cours ?" );

            $courses = get_posts( [ 'post_type' => 'pl_course', 'posts_per_page' => -1, 'post_status' => 'publish' ] );

            $current_user_id = get_current_user_id();
            $current_user    = wp_get_current_user();
            $sessions = get_posts( [
                'post_type' => 'pl_interaction', 'posts_per_page' => 20, 'post_status' => 'publish',
                'orderby' => 'date', 'order' => 'DESC',
                'meta_query' => [ [ 'key' => '_pl_student_id', 'value' => $current_user_id ] ],
            ] );

            $sessions_data = [];
            foreach ( $sessions as $s ) {
                $sid    = get_post_meta( $s->ID, '_pl_session_id', true );
                $cid    = (int) get_post_meta( $s->ID, '_pl_course_id', true );
                $ended  = get_post_meta( $s->ID, '_pl_ended_at', true );
                $sessions_data[] = [
                    'session_id' => $sid,
                    'course_id'  => $cid,
                    'ended'      => ! empty( $ended ),
                ];
            }

            wp_enqueue_script( 'pl-twin-front', PL_TWIN_PLUGIN_URL . 'assets/js/twin-admin.js', [ 'jquery' ], PL_TWIN_VERSION, true );
            wp_localize_script( 'pl-twin-front', 'plTwin', [
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'nonce'         => wp_create_nonce( self::NONCE_AJAX ),
                'courseId'      => (int) $atts['course_id'],
                'twinName'      => $twin_name,
                'introMessage'  => $intro,
                'studentName'   => esc_html( $current_user->display_name ),
                'sessionsData'  => $sessions_data,
                'i18n'          => [
                    'send'           => __( 'Envoyer', 'pedagolens-student-twin' ),
                    'typing'         => __( "est en train d'écrire…", 'pedagolens-student-twin' ),
                    'sessionEnded'   => __( 'Session terminée. À bientôt !', 'pedagolens-student-twin' ),
                    'networkError'   => __( 'Erreur réseau. Réessaie.', 'pedagolens-student-twin' ),
                    'guardrailLabel' => __( 'Garde-fou déclenché', 'pedagolens-student-twin' ),
                    'newSession'     => __( 'Nouvelle session', 'pedagolens-student-twin' ),
                    'endSession'     => __( 'Terminer la session', 'pedagolens-student-twin' ),
                    'welcome'        => __( 'Bienvenue', 'pedagolens-student-twin' ),
                    'chooseCourse'   => __( 'Choisis un cours pour commencer ta session', 'pedagolens-student-twin' ),
                    'resuming'       => __( 'Reprise de ta session précédente…', 'pedagolens-student-twin' ),
                    'starting'       => __( 'Démarrage de la session…', 'pedagolens-student-twin' ),
                ],
            ] );

            $user_initial = esc_html( mb_substr( $current_user->display_name, 0, 1 ) );
            $user_name    = esc_html( $current_user->display_name );

            ob_start();
            echo self::stitch_inline_styles();
            ?>
            <div class="pls-page" data-twin-name="<?php echo esc_attr( $twin_name ); ?>" data-intro="<?php echo esc_attr( $intro ); ?>">

                <?php echo self::stitch_nav( $nav_links, $home_url, $user_initial, $user_name, 'Jumeau' ); ?>

                <main class="pls-main">
                    <!-- Hero header -->
                    <section style="padding:2.5rem 3rem 1.5rem;max-width:1280px;margin:0 auto;width:100%;">
                        <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem;">
                            <div>
                                <h2 style="font-family:'Manrope',sans-serif;font-size:2rem;font-weight:800;color:#00236F;letter-spacing:-.02em;margin:0 0 .25rem;">
                                    <?php esc_html_e( 'Assistant Pédagogique', 'pedagolens-student-twin' ); ?>
                                </h2>
                                <p style="color:#444651;font-size:.95rem;margin:0;"><?php esc_html_e( 'Comprendre sans faire le travail à votre place', 'pedagolens-student-twin' ); ?></p>
                            </div>
                            <div style="display:flex;align-items:center;gap:.75rem;">
                                <label for="pl-twin-course-select" style="font-family:'Inter',sans-serif;font-size:.8rem;font-weight:600;color:#444651;"><?php esc_html_e( 'Cours :', 'pedagolens-student-twin' ); ?></label>
                                <select id="pl-twin-course-select" style="padding:.5rem 1rem;border:1px solid #C5C5D3;border-radius:.5rem;font-size:.85rem;font-family:'Inter',sans-serif;background:#fff;color:#191C1E;outline:none;">
                                    <option value="0"><?php esc_html_e( '— Choisir un cours —', 'pedagolens-student-twin' ); ?></option>
                                    <?php foreach ( $courses as $c ) : ?>
                                        <option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( (int) $atts['course_id'], $c->ID ); ?>><?php echo esc_html( $c->post_title ); ?></option>
                                    <?php endforeach; ?>
                                    <?php if ( empty( $courses ) ) : ?>
                                        <option value="1"><?php esc_html_e( 'Cours démo', 'pedagolens-student-twin' ); ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                    </section>

                    <!-- Bento grid -->
                    <section style="padding:0 3rem 3rem;max-width:1280px;margin:0 auto;width:100%;">
                        <div style="display:grid;grid-template-columns:1fr 340px;gap:2rem;">

                            <!-- LEFT: Chat column -->
                            <div style="display:flex;flex-direction:column;gap:1.5rem;">

                                <!-- Ethical guardrail banner -->
                                <div style="background:#F2F4F6;padding:1.25rem 1.5rem;border-radius:.75rem;display:flex;align-items:flex-start;gap:1rem;border-left:4px solid #712AE2;">
                                    <span style="color:#712AE2;font-size:1.25rem;line-height:1;">ℹ️</span>
                                    <div>
                                        <h4 style="font-family:'Inter',sans-serif;font-weight:700;color:#712AE2;font-size:.75rem;margin:0 0 .25rem;text-transform:uppercase;letter-spacing:.08em;"><?php esc_html_e( 'Note Éthique', 'pedagolens-student-twin' ); ?></h4>
                                        <p style="font-size:.8rem;color:#444651;margin:0;line-height:1.5;"><?php esc_html_e( "Cette IA aide à comprendre et organiser, elle ne produit pas la réponse finale. Son but est de stimuler votre réflexion critique.", 'pedagolens-student-twin' ); ?></p>
                                    </div>
                                </div>

                                <!-- Welcome state -->
                                <div id="pl-twin-welcome" style="background:#fff;border-radius:.75rem;box-shadow:0 10px 40px rgba(25,28,30,0.06);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4rem 2rem;text-align:center;min-height:500px;">
                                    <div style="font-size:3rem;margin-bottom:1rem;">👋</div>
                                    <h2 style="font-family:'Manrope',sans-serif;font-weight:800;color:#00236F;font-size:1.5rem;margin:0 0 .75rem;">
                                        <?php printf( esc_html__( 'Bonjour %s !', 'pedagolens-student-twin' ), $user_name ); ?>
                                    </h2>
                                    <p style="color:#444651;font-size:.9rem;max-width:400px;line-height:1.6;margin:0 0 .5rem;"><?php esc_html_e( "Sélectionne un cours dans le menu ci-dessus pour démarrer une conversation avec ton jumeau numérique.", 'pedagolens-student-twin' ); ?></p>
                                    <p style="color:#712AE2;font-size:.85rem;font-weight:600;margin:0;"><?php echo $twin_name; ?> <?php esc_html_e( "est prêt à t'accompagner.", 'pedagolens-student-twin' ); ?></p>
                                </div>

                                <!-- Chat container (hidden until course selected) -->
                                <div id="pl-twin-chat-wrap" style="display:none;">
                                    <div class="pls-chat-card">
                                        <!-- Chat header -->
                                        <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.5rem;border-bottom:1px solid rgba(197,197,211,0.2);">
                                            <div style="display:flex;align-items:center;gap:.75rem;">
                                                <div style="width:2.5rem;height:2.5rem;border-radius:50%;background:#EADDFF;display:flex;align-items:center;justify-content:center;font-size:1.1rem;">🧠</div>
                                                <div>
                                                    <strong style="font-family:'Manrope',sans-serif;font-size:.9rem;color:#00236F;" class="pl-twin-chat-name"><?php echo $twin_name; ?></strong>
                                                    <div style="display:flex;align-items:center;gap:.35rem;">
                                                        <span style="width:6px;height:6px;border-radius:50%;background:#22C55E;display:inline-block;"></span>
                                                        <span style="font-size:.7rem;color:#444651;"><?php esc_html_e( 'En ligne', 'pedagolens-student-twin' ); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div style="display:flex;align-items:center;gap:.75rem;">
                                                <span id="pl-twin-course-badge" style="padding:.25rem .75rem;background:rgba(76,215,246,0.15);color:#004E5C;font-size:.65rem;font-weight:700;border-radius:1rem;text-transform:uppercase;"></span>
                                                <button type="button" id="pl-twin-end-session" style="width:2rem;height:2rem;border-radius:.5rem;border:1px solid #C5C5D3;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#BA1A1A;transition:background .2s;" title="<?php esc_attr_e( 'Terminer la session', 'pedagolens-student-twin' ); ?>">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Messages area -->
                                        <div id="pl-twin-messages" class="pls-messages" role="log" aria-live="polite"></div>

                                        <!-- Follow-ups -->
                                        <div id="pl-twin-follow-ups" style="display:flex;flex-wrap:wrap;gap:.5rem;padding:0 1.5rem;"></div>

                                        <!-- Quick suggestion buttons -->
                                        <div style="display:flex;flex-wrap:wrap;gap:.5rem;padding:.75rem 1.5rem;">
                                            <button type="button" class="pls-suggestion-btn" data-suggestion="<?php esc_attr_e( 'Reformuler la consigne', 'pedagolens-student-twin' ); ?>">
                                                ✏️ <?php esc_html_e( 'Reformuler la consigne', 'pedagolens-student-twin' ); ?>
                                            </button>
                                            <button type="button" class="pls-suggestion-btn" data-suggestion="<?php esc_attr_e( "M'expliquer l'objectif", 'pedagolens-student-twin' ); ?>">
                                                🎯 <?php esc_html_e( "M'expliquer l'objectif", 'pedagolens-student-twin' ); ?>
                                            </button>
                                            <button type="button" class="pls-suggestion-btn" data-suggestion="<?php esc_attr_e( 'Me guider étape par étape', 'pedagolens-student-twin' ); ?>">
                                                🗺️ <?php esc_html_e( 'Me guider étape par étape', 'pedagolens-student-twin' ); ?>
                                            </button>
                                            <button type="button" class="pls-suggestion-btn" data-suggestion="<?php esc_attr_e( 'Vérifier ma compréhension', 'pedagolens-student-twin' ); ?>">
                                                ✅ <?php esc_html_e( 'Vérifier ma compréhension', 'pedagolens-student-twin' ); ?>
                                            </button>
                                        </div>

                                        <!-- Input area -->
                                        <div style="padding:.75rem 1.5rem 1.25rem;border-top:1px solid rgba(197,197,211,0.15);">
                                            <div style="position:relative;display:flex;align-items:center;">
                                                <textarea id="pl-twin-input" rows="1" placeholder="<?php esc_attr_e( 'Posez une question sur le cours...', 'pedagolens-student-twin' ); ?>" disabled aria-label="<?php esc_attr_e( 'Message', 'pedagolens-student-twin' ); ?>" style="width:100%;background:#F2F4F6;border:none;border-radius:.75rem;padding:1rem 3.5rem 1rem 1.5rem;font-size:.9rem;font-family:'Inter',sans-serif;resize:none;outline:none;color:#191C1E;line-height:1.5;"></textarea>
                                                <button type="button" id="pl-twin-send" disabled aria-label="<?php esc_attr_e( 'Envoyer', 'pedagolens-student-twin' ); ?>" style="position:absolute;right:.5rem;width:2.25rem;height:2.25rem;border-radius:.5rem;background:linear-gradient(135deg,#00236F,#1E3A8A);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:opacity .2s;">
                                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- RIGHT: Sidebar column -->
                            <div style="display:flex;flex-direction:column;gap:1.5rem;">

                                <!-- Sessions history card -->
                                <div style="background:#fff;border-radius:.75rem;padding:1.5rem;box-shadow:0 10px 40px rgba(25,28,30,0.06);">
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
                                        <h3 style="font-family:'Manrope',sans-serif;font-weight:700;font-size:1rem;color:#00236F;margin:0;"><?php esc_html_e( 'Sessions précédentes', 'pedagolens-student-twin' ); ?></h3>
                                    </div>
                                    <ul id="pl-twin-history-list" style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.5rem;">
                                        <?php if ( empty( $sessions ) ) : ?>
                                            <li style="font-size:.8rem;color:#757682;text-align:center;padding:1.5rem 0;"><?php esc_html_e( 'Aucune session pour le moment.', 'pedagolens-student-twin' ); ?></li>
                                        <?php else : ?>
                                            <?php foreach ( $sessions as $s ) :
                                                $sid     = get_post_meta( $s->ID, '_pl_session_id', true );
                                                $cid     = (int) get_post_meta( $s->ID, '_pl_course_id', true );
                                                $started = get_post_meta( $s->ID, '_pl_started_at', true );
                                                $ended   = get_post_meta( $s->ID, '_pl_ended_at', true );
                                                $course  = $cid ? get_post( $cid ) : null;
                                                $raw_m   = get_post_meta( $s->ID, '_pl_messages', true );
                                                $msgs    = is_string( $raw_m ) ? json_decode( $raw_m, true ) : [];
                                                $count   = is_array( $msgs ) ? count( $msgs ) : 0;
                                                ?>
                                                <li class="pl-twin-history-item" data-session-id="<?php echo esc_attr( $sid ); ?>" style="display:flex;align-items:center;gap:.75rem;padding:.75rem;border-radius:.5rem;cursor:pointer;transition:background .15s;background:#F7F9FB;">
                                                    <div style="width:2.5rem;height:2.5rem;border-radius:.5rem;background:#ECEEF0;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                                        <span style="font-size:.9rem;">💬</span>
                                                    </div>
                                                    <div style="flex:1;min-width:0;">
                                                        <span style="display:block;font-size:.8rem;font-weight:700;color:#191C1E;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html( $course ? $course->post_title : '#' . $cid ); ?></span>
                                                        <span style="font-size:.7rem;color:#757682;">
                                                            <?php echo esc_html( $started ? wp_date( 'd/m H:i', strtotime( $started ) ) : '—' ); ?>
                                                            · <?php echo esc_html( $count ); ?> msg
                                                            <?php if ( $ended ) : ?><span style="color:#22C55E;font-weight:700;">✓</span><?php endif; ?>
                                                        </span>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </ul>
                                </div>

                                <!-- Guardrail status card -->
                                <div style="background:#fff;border-radius:.75rem;padding:1.5rem;box-shadow:0 10px 40px rgba(25,28,30,0.06);border-top:4px solid rgba(186,26,26,0.15);">
                                    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;">
                                        <span style="font-size:1.1rem;">🛡️</span>
                                        <h3 style="font-family:'Manrope',sans-serif;font-weight:700;font-size:1rem;color:#191C1E;margin:0;"><?php esc_html_e( 'Garde-fous', 'pedagolens-student-twin' ); ?></h3>
                                    </div>
                                    <div id="pl-twin-guardrail-status" style="background:rgba(186,26,26,0.05);padding:1rem;border-radius:.5rem;">
                                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.25rem;">
                                            <span style="font-size:.75rem;font-weight:700;color:#BA1A1A;"><?php esc_html_e( 'Statut', 'pedagolens-student-twin' ); ?></span>
                                            <span style="font-size:.65rem;color:#757682;"><?php esc_html_e( 'Actif', 'pedagolens-student-twin' ); ?></span>
                                        </div>
                                        <p style="font-size:.75rem;color:#444651;margin:.5rem 0 0;line-height:1.5;"><?php esc_html_e( "Les réponses sont filtrées pour garantir un cadre pédagogique sûr.", 'pedagolens-student-twin' ); ?></p>
                                    </div>
                                </div>

                                <!-- AI Library card -->
                                <div style="background:linear-gradient(135deg,#1E3A8A,#00236F);padding:1.5rem;border-radius:.75rem;color:#fff;">
                                    <h3 style="font-family:'Manrope',sans-serif;font-weight:700;font-size:1rem;margin:0 0 1rem;"><?php esc_html_e( 'Bibliothèque IA', 'pedagolens-student-twin' ); ?></h3>
                                    <div style="display:flex;flex-direction:column;gap:.5rem;">
                                        <div style="padding:.75rem;background:rgba(255,255,255,0.1);border-radius:.5rem;display:flex;justify-content:space-between;align-items:center;cursor:pointer;transition:background .15s;">
                                            <span style="font-size:.85rem;"><?php esc_html_e( 'Glossaire des termes', 'pedagolens-student-twin' ); ?></span>
                                            <span style="font-size:.75rem;">↗</span>
                                        </div>
                                        <div style="padding:.75rem;background:rgba(255,255,255,0.1);border-radius:.5rem;display:flex;justify-content:space-between;align-items:center;cursor:pointer;transition:background .15s;">
                                            <span style="font-size:.85rem;"><?php esc_html_e( 'Chronologie interactive', 'pedagolens-student-twin' ); ?></span>
                                            <span style="font-size:.75rem;">↗</span>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </section>
                </main>

                <?php echo self::stitch_footer( $nav_links ); ?>
            </div>
            <?php
            return ob_get_clean();
        }



    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private static function verify_nonce(): void {
        check_ajax_referer( self::NONCE_AJAX, 'nonce' );
    }
}
