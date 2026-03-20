<?php
/**
 * PedagoLens_Dashboard_Admin
 *
 * Interface admin du tableau de bord enseignant :
 * liste des cours, déclenchement d'analyse (AJAX), affichage des scores/recommandations,
 * gestion des projets.
 */

defined( 'ABSPATH' ) || exit;

class PedagoLens_Dashboard_Admin {

    private const MENU_SLUG  = 'pl-teacher-dashboard';
    private const NONCE_AJAX = 'pl_dashboard_ajax';

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function register(): void {
        add_action( 'admin_menu',                          [ self::class, 'add_menu' ] );
        add_action( 'wp_ajax_pl_analyze_course',           [ self::class, 'ajax_analyze' ] );
        add_action( 'wp_ajax_pl_create_project',           [ self::class, 'ajax_create_project' ] );
        add_action( 'admin_enqueue_scripts',               [ self::class, 'enqueue_assets' ] );
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
            __( 'Tableau de bord enseignant', 'pedagolens-teacher-dashboard' ),
            __( 'Dashboard', 'pedagolens-teacher-dashboard' ),
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
            'pl-dashboard-admin',
            PL_DASHBOARD_PLUGIN_URL . 'assets/js/dashboard-admin.js',
            [ 'jquery' ],
            PL_DASHBOARD_VERSION,
            true
        );

        wp_localize_script( 'pl-dashboard-admin', 'plDashboard', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
            'i18n'    => [
                'analyzing'    => __( 'Analyse en cours…', 'pedagolens-teacher-dashboard' ),
                'analyzeError' => __( 'Erreur lors de l\'analyse.', 'pedagolens-teacher-dashboard' ),
            ],
        ] );

        wp_enqueue_style(
            'pl-dashboard-admin',
            PL_DASHBOARD_PLUGIN_URL . 'assets/css/dashboard-admin.css',
            [],
            PL_DASHBOARD_VERSION
        );
    }

    // -------------------------------------------------------------------------
    // Page principale
    // -------------------------------------------------------------------------

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'pedagolens-teacher-dashboard' ) );
        }

        $courses = self::get_courses();
        $mode    = get_option( 'pl_ai_mode', 'mock' );
        ?>
        <div class="wrap pl-dashboard">
            <h1><?php esc_html_e( 'Tableau de bord enseignant', 'pedagolens-teacher-dashboard' ); ?></h1>

            <?php if ( $mode === 'mock' ) : ?>
                <div class="notice notice-info inline">
                    <p><?php esc_html_e( 'Mode mock actif — les analyses utilisent des données de démonstration.', 'pedagolens-teacher-dashboard' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( empty( $courses ) ) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php esc_html_e( 'Aucun cours trouvé.', 'pedagolens-teacher-dashboard' ); ?>
                        <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=pl_course' ) ); ?>">
                            <?php esc_html_e( 'Créer un cours', 'pedagolens-teacher-dashboard' ); ?>
                        </a>
                    </p>
                </div>
            <?php else : ?>
                <div class="pl-courses-grid">
                    <?php foreach ( $courses as $course ) : ?>
                        <?php self::render_course_card( $course ); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Carte cours
    // -------------------------------------------------------------------------

    private static function render_course_card( WP_Post $course ): void {
        $course_type = get_post_meta( $course->ID, '_pl_course_type', true ) ?: 'magistral';
        $analyses    = self::get_latest_analysis( $course->ID );
        $projects    = PedagoLens_Teacher_Dashboard::get_projects( $course->ID );
        ?>
        <div class="pl-course-card" id="pl-course-<?php echo (int) $course->ID; ?>">

            <div class="pl-course-header">
                <h2><?php echo esc_html( $course->post_title ); ?></h2>
                <span class="pl-course-type-badge pl-type-<?php echo esc_attr( $course_type ); ?>">
                    <?php echo esc_html( $course_type ); ?>
                </span>
            </div>

            <div class="pl-course-actions">
                <button
                    type="button"
                    class="button button-primary pl-btn-analyze"
                    data-course-id="<?php echo (int) $course->ID; ?>"
                >
                    <?php esc_html_e( 'Analyser', 'pedagolens-teacher-dashboard' ); ?>
                </button>
                <button
                    type="button"
                    class="button pl-btn-new-project"
                    data-course-id="<?php echo (int) $course->ID; ?>"
                    data-course-title="<?php echo esc_attr( $course->post_title ); ?>"
                >
                    + <?php esc_html_e( 'Nouveau projet', 'pedagolens-teacher-dashboard' ); ?>
                </button>
                <a href="<?php echo esc_url( get_edit_post_link( $course->ID ) ); ?>" class="button button-small">
                    <?php esc_html_e( 'Modifier', 'pedagolens-teacher-dashboard' ); ?>
                </a>
            </div>

            <!-- Zone résultats d'analyse (remplie par AJAX) -->
            <div class="pl-analysis-result" id="pl-analysis-<?php echo (int) $course->ID; ?>">
                <?php if ( $analyses ) : ?>
                    <?php self::render_analysis_result( $analyses ); ?>
                <?php endif; ?>
            </div>

            <!-- Liste des projets -->
            <?php if ( ! empty( $projects ) ) : ?>
                <div class="pl-projects-list">
                    <h3><?php esc_html_e( 'Projets', 'pedagolens-teacher-dashboard' ); ?></h3>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Titre', 'pedagolens-teacher-dashboard' ); ?></th>
                                <th><?php esc_html_e( 'Type', 'pedagolens-teacher-dashboard' ); ?></th>
                                <th><?php esc_html_e( 'Créé le', 'pedagolens-teacher-dashboard' ); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $projects as $project ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $project['title'] ); ?></td>
                                    <td><code><?php echo esc_html( $project['type'] ); ?></code></td>
                                    <td><?php echo esc_html( wp_date( 'Y-m-d', strtotime( $project['created_at'] ) ) ); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-course-workbench&project_id=' . (int) $project['id'] ) ); ?>" class="button button-small">
                                            <?php esc_html_e( 'Ouvrir', 'pedagolens-teacher-dashboard' ); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Rendu résultat d'analyse
    // -------------------------------------------------------------------------

    public static function render_analysis_result( array $analysis ): void {
        $scores  = $analysis['profile_scores']  ?? [];
        $recs    = $analysis['recommendations'] ?? [];
        $summary = $analysis['summary']         ?? '';

        if ( empty( $scores ) ) {
            return;
        }
        ?>
        <div class="pl-scores-block">
            <h4>&#127919; Scores par profil</h4>
            <div class="pl-scores-bars">
                <?php foreach ( $scores as $slug => $score ) :
                    $score      = max( 0, min( 100, (int) $score ) );
                    $color_cls  = self::score_color_class( $score );
                    ?>
                    <div class="pl-score-row">
                        <span class="pl-score-label"><?php echo esc_html( $slug ); ?></span>
                        <div class="pl-score-bar-wrap">
                            <div class="pl-score-bar <?php echo esc_attr( $color_cls ); ?>"
                                 data-score="<?php echo $score; ?>"
                                 style="width:0%;"></div>
                        </div>
                        <span class="pl-score-value"><?php echo $score; ?>/100</span>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ( $summary ) : ?>
                <p class="pl-summary"><?php echo esc_html( $summary ); ?></p>
            <?php endif; ?>
        </div>

        <?php if ( ! empty( $recs ) ) : ?>
            <div class="pl-recs-block">
                <h4>&#128161; Recommandations</h4>
                <div class="pl-recs-list">
                    <?php foreach ( $recs as $rec ) :
                        $priority     = (int) ( $rec['priority'] ?? 99 );
                        $priority_cls = $priority <= 2 ? 'high' : ( $priority <= 4 ? 'medium' : 'low' );
                        ?>
                        <div class="pl-rec-item">
                            <span class="pl-rec-priority pl-rec-priority--<?php echo esc_attr( $priority_cls ); ?>">
                                <?php echo $priority; ?>
                            </span>
                            <div class="pl-rec-content">
                                <span class="pl-rec-section"><?php echo esc_html( $rec['section'] ?? '' ); ?></span>
                                — <?php echo esc_html( $rec['text'] ?? '' ); ?>
                                <?php if ( ! empty( $rec['profile_target'] ) ) : ?>
                                    <span class="pl-rec-profile"><?php echo esc_html( $rec['profile_target'] ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX — Analyser un cours
    // -------------------------------------------------------------------------

    public static function ajax_analyze(): void {
        check_ajax_referer( self::NONCE_AJAX, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }

        $course_id = (int) ( $_POST['course_id'] ?? 0 );
        if ( ! $course_id ) {
            wp_send_json_error( [ 'message' => 'course_id manquant.' ] );
        }

        $result = PedagoLens_Teacher_Dashboard::analyze_course( $course_id );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( [
                'message'    => $result['error_message'] ?? 'Analyse échouée.',
                'error_code' => $result['error_code']    ?? 'pl_analysis_failed',
            ] );
        }

        // Générer le HTML du résultat pour injection directe
        ob_start();
        self::render_analysis_result( $result );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    // -------------------------------------------------------------------------
    // AJAX — Créer un projet
    // -------------------------------------------------------------------------

    public static function ajax_create_project(): void {
        check_ajax_referer( self::NONCE_AJAX, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }

        $course_id = (int) ( $_POST['course_id'] ?? 0 );
        $type      = sanitize_text_field( $_POST['type']  ?? 'magistral' );
        $title     = sanitize_text_field( $_POST['title'] ?? '' );

        if ( ! $course_id || ! $title ) {
            wp_send_json_error( [ 'message' => 'Paramètres manquants.' ] );
        }

        $project_id = PedagoLens_Teacher_Dashboard::create_project( $course_id, $type, $title );

        if ( ! $project_id ) {
            wp_send_json_error( [ 'message' => 'Création du projet échouée.' ] );
        }

        wp_send_json_success( [
            'project_id'  => $project_id,
            'workbench_url' => admin_url( 'admin.php?page=pl-course-workbench&project_id=' . $project_id ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function get_courses(): array {
        return get_posts( [
            'post_type'      => 'pl_course',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );
    }

    private static function get_latest_analysis( int $course_id ): ?array {
        $posts = get_posts( [
            'post_type'      => 'pl_analysis',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [ [
                'key'   => '_pl_course_id',
                'value' => $course_id,
                'type'  => 'NUMERIC',
            ] ],
        ] );

        if ( empty( $posts ) ) {
            return null;
        }

        $id = $posts[0]->ID;
        return [
            'analysis_id'    => $id,
            'profile_scores' => PedagoLens_Teacher_Dashboard::get_profile_scores( $id ),
            'recommendations' => PedagoLens_Teacher_Dashboard::get_recommendations( $id ),
            'summary'        => get_post_meta( $id, '_pl_summary', true ),
        ];
    }

    private static function score_color( int $score ): string {
        if ( $score >= 80 ) return '#00a32a';
        if ( $score >= 60 ) return '#2271b1';
        if ( $score >= 40 ) return '#dba617';
        return '#d63638';
    }

    private static function score_color_class( int $score ): string {
        if ( $score >= 80 ) return 'pl-score-green';
        if ( $score >= 60 ) return 'pl-score-blue';
        if ( $score >= 40 ) return 'pl-score-yellow';
        return 'pl-score-red';
    }
}
