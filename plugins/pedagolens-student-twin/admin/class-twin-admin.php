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
        // Permettre aux étudiants connectés (non-admin) d'utiliser le shortcode
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
        $parent = 'pl-pedagolens';

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
                    <td>
                        <input type="text" id="twin_name" name="twin_name"
                               value="<?php echo esc_attr( $twin_name ); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="intro_message"><?php esc_html_e( 'Message d\'introduction', 'pedagolens-student-twin' ); ?></label></th>
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
                    <td>
                        <input type="number" id="max_length" name="max_length"
                               value="<?php echo esc_attr( $max_length ); ?>" min="100" max="10000" class="small-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="forbidden_topics"><?php esc_html_e( 'Sujets interdits', 'pedagolens-student-twin' ); ?></label></th>
                    <td>
                        <textarea id="forbidden_topics" name="forbidden_topics" rows="6" class="large-text"><?php echo esc_textarea( $forbidden_topics ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Un mot-clé par ligne. Si le message contient ce mot, le garde-fou se déclenche.', 'pedagolens-student-twin' ); ?></p>
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
        $intro_message = esc_html( $twin_settings['intro_message'] ?? 'Bonjour ! Je suis ton jumeau numérique. Comment puis-je t\'aider avec ton cours ?' );

        // Cours disponibles pour la démo
        $courses = get_posts( [
            'post_type'      => 'pl_course',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ] );
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
                <button type="button" id="pl-twin-start" class="button button-primary">
                    <?php esc_html_e( 'Démarrer', 'pedagolens-student-twin' ); ?>
                </button>
            </div>

            <div id="pl-twin-chat" style="display:none;">
                <div class="pl-chat-header">
                    <strong><?php echo $twin_name; ?></strong>
                    <button type="button" id="pl-twin-end" class="button button-small">
                        <?php esc_html_e( 'Terminer la session', 'pedagolens-student-twin' ); ?>
                    </button>
                </div>

                <div id="pl-chat-messages" class="pl-chat-messages">
                    <div class="pl-chat-bubble pl-bubble-assistant">
                        <?php echo $intro_message; ?>
                    </div>
                </div>

                <div class="pl-chat-input-row">
                    <textarea id="pl-chat-input" rows="2" placeholder="<?php esc_attr_e( 'Pose ta question…', 'pedagolens-student-twin' ); ?>"></textarea>
                    <button type="button" id="pl-chat-send" class="button button-primary">
                        <?php esc_html_e( 'Envoyer', 'pedagolens-student-twin' ); ?>
                    </button>
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
        $sessions = get_posts( [
            'post_type'      => 'pl_interaction',
            'posts_per_page' => 20,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );
        ?>
        <h2><?php esc_html_e( 'Sessions récentes', 'pedagolens-student-twin' ); ?></h2>
        <?php if ( empty( $sessions ) ) : ?>
            <p><?php esc_html_e( 'Aucune session enregistrée.', 'pedagolens-student-twin' ); ?></p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Session ID', 'pedagolens-student-twin' ); ?></th>
                        <th><?php esc_html_e( 'Étudiant', 'pedagolens-student-twin' ); ?></th>
                        <th><?php esc_html_e( 'Cours', 'pedagolens-student-twin' ); ?></th>
                        <th><?php esc_html_e( 'Démarrée', 'pedagolens-student-twin' ); ?></th>
                        <th><?php esc_html_e( 'Terminée', 'pedagolens-student-twin' ); ?></th>
                        <th><?php esc_html_e( 'Messages', 'pedagolens-student-twin' ); ?></th>
                        <th><?php esc_html_e( 'Garde-fous', 'pedagolens-student-twin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $sessions as $s ) :
                        $session_id = get_post_meta( $s->ID, '_pl_session_id', true );
                        $student_id = (int) get_post_meta( $s->ID, '_pl_student_id', true );
                        $course_id  = (int) get_post_meta( $s->ID, '_pl_course_id',  true );
                        $started    = get_post_meta( $s->ID, '_pl_started_at', true );
                        $ended      = get_post_meta( $s->ID, '_pl_ended_at',   true );

                        $raw_msgs  = get_post_meta( $s->ID, '_pl_messages', true );
                        $msgs      = is_string( $raw_msgs ) ? json_decode( $raw_msgs, true ) : [];
                        $msg_count = is_array( $msgs ) ? count( $msgs ) : 0;

                        $raw_grd  = get_post_meta( $s->ID, '_pl_guardrails_applied', true );
                        $grd      = is_string( $raw_grd ) ? json_decode( $raw_grd, true ) : [];
                        $grd_count = is_array( $grd ) ? count( $grd ) : 0;

                        $student = $student_id ? get_userdata( $student_id ) : null;
                        $course  = $course_id  ? get_post( $course_id )      : null;
                        ?>
                        <tr>
                            <td><code><?php echo esc_html( mb_substr( $session_id, 0, 8 ) . '…' ); ?></code></td>
                            <td><?php echo esc_html( $student ? $student->display_name : "#{$student_id}" ); ?></td>
                            <td><?php echo esc_html( $course ? $course->post_title : "#{$course_id}" ); ?></td>
                            <td><?php echo esc_html( $started ? wp_date( 'Y-m-d H:i', strtotime( $started ) ) : '—' ); ?></td>
                            <td><?php echo esc_html( $ended  ? wp_date( 'Y-m-d H:i', strtotime( $ended ) )  : '—' ); ?></td>
                            <td><?php echo esc_html( $msg_count ); ?></td>
                            <td><?php echo $grd_count > 0 ? '<span style="color:#d63638;font-weight:600;">' . esc_html( $grd_count ) . '</span>' : '0'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    // -------------------------------------------------------------------------
    // Sauvegarde des settings
    // -------------------------------------------------------------------------

    public static function handle_save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'pedagolens-student-twin' ) );
        }

        check_admin_referer( self::NONCE_SAVE, '_pl_twin_nonce' );

        // Garde-fous
        $forbidden_raw = sanitize_textarea_field( $_POST['forbidden_topics'] ?? '' );
        $forbidden     = array_filter( array_map( 'trim', explode( "\n", $forbidden_raw ) ) );

        $guardrail_config = [
            'max_length'           => max( 100, (int) ( $_POST['max_length'] ?? 2000 ) ),
            'forbidden_topics'     => array_values( $forbidden ),
            'ai_guardrail_enabled' => ! empty( $_POST['ai_guardrail_enabled'] ),
        ];
        update_option( 'pl_guardrails_config', wp_json_encode( $guardrail_config ) );

        // Comportement du jumeau
        $twin_settings = [
            'twin_name'     => sanitize_text_field( $_POST['twin_name'] ?? 'Léa' ),
            'intro_message' => sanitize_textarea_field( $_POST['intro_message'] ?? '' ),
        ];
        update_option( 'pl_student_twin_settings', wp_json_encode( $twin_settings ) );

        wp_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=settings&saved=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // AJAX — Session
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
    // Shortcode [pedagolens_twin]
    // -------------------------------------------------------------------------

    public static function render_shortcode( array $atts ): string {
        $atts = shortcode_atts( [ 'course_id' => 0 ], $atts );

        $twin_raw      = get_option( 'pl_student_twin_settings', [] );
        $twin_settings = is_string( $twin_raw ) ? ( json_decode( $twin_raw, true ) ?? [] ) : (array) $twin_raw;
        $twin_name     = esc_html( $twin_settings['twin_name'] ?? 'Léa' );
        $intro         = esc_html( $twin_settings['intro_message'] ?? 'Bonjour ! Comment puis-je t\'aider ?' );

        wp_enqueue_script(
            'pl-twin-front',
            PL_TWIN_PLUGIN_URL . 'assets/js/twin-admin.js',
            [ 'jquery' ],
            PL_TWIN_VERSION,
            true
        );
        wp_localize_script( 'pl-twin-front', 'plTwin', [
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( self::NONCE_AJAX ),
            'courseId' => (int) $atts['course_id'],
        ] );
        wp_enqueue_style( 'pl-twin-admin', PL_TWIN_PLUGIN_URL . 'assets/css/twin-admin.css', [], PL_TWIN_VERSION );

        ob_start();
        ?>
        <div class="pl-twin-widget" data-course-id="<?php echo esc_attr( $atts['course_id'] ); ?>">
            <div class="pl-chat-header">
                <strong><?php echo $twin_name; ?></strong>
                <button type="button" class="pl-twin-start-btn button">
                    <?php esc_html_e( 'Démarrer', 'pedagolens-student-twin' ); ?>
                </button>
            </div>
            <div class="pl-chat-messages" style="display:none;">
                <div class="pl-chat-bubble pl-bubble-assistant"><?php echo $intro; ?></div>
            </div>
            <div class="pl-chat-input-row" style="display:none;">
                <textarea class="pl-chat-input" rows="2" placeholder="<?php esc_attr_e( 'Pose ta question…', 'pedagolens-student-twin' ); ?>"></textarea>
                <button type="button" class="pl-chat-send button button-primary">
                    <?php esc_html_e( 'Envoyer', 'pedagolens-student-twin' ); ?>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private static function verify_nonce(): void {
        // Les étudiants non-admin peuvent utiliser le jumeau via shortcode
        check_ajax_referer( self::NONCE_AJAX, 'nonce' );
    }
}
