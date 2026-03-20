<?php
/**
 * PedagoLens_Teacher_Dashboard
 *
 * Logique métier : analyse de cours, persistance des analyses,
 * lecture des scores/recommandations, gestion des projets.
 */

defined( 'ABSPATH' ) || exit;

class PedagoLens_Teacher_Dashboard {

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function init(): void {
        PedagoLens_Dashboard_Admin::register();
    }

    // -------------------------------------------------------------------------
    // Analyse
    // -------------------------------------------------------------------------

    /**
     * Déclenche une analyse IA du cours et persiste le résultat.
     *
     * @return array  Résultat structuré ou tableau d'erreur.
     */
    public static function analyze_course( int $course_id ): array {
        $course = get_post( $course_id );
        if ( ! $course || $course->post_type !== 'pl_course' ) {
            return self::error( 'pl_course_not_found', "Cours introuvable : {$course_id}" );
        }

        $profiles = self::get_active_profiles();
        if ( empty( $profiles ) ) {
            return self::error( 'pl_no_profiles_configured', 'Aucun profil actif configuré.' );
        }

        do_action( 'pedagolens_before_analysis', $course_id );

        $sections = get_post_meta( $course_id, '_pl_sections', true );
        $params   = [
            'course_id'    => $course_id,
            'course_title' => $course->post_title,
            'content'      => $course->post_content,
            'sections'     => is_array( $sections ) ? wp_json_encode( $sections ) : '',
            'course_type'  => get_post_meta( $course_id, '_pl_course_type', true ) ?: 'magistral',
            'profiles'     => wp_json_encode( array_column( $profiles, 'slug' ) ),
        ];

        $result = PedagoLens_API_Bridge::invoke( 'course_analysis', $params );

        if ( empty( $result['success'] ) ) {
            do_action( 'pedagolens_after_analysis', $course_id, $result );
            return $result;
        }

        // Garantir que les scores couvrent exactement les profils actifs
        $result = self::normalize_scores( $result, $profiles );

        $analysis_id = self::save_analysis( $course_id, $result );
        $result['analysis_id'] = $analysis_id;

        do_action( 'pedagolens_after_analysis', $course_id, $result );

        return $result;
    }

    /**
     * Persiste un résultat d'analyse dans un CPT pl_analysis.
     */
    public static function save_analysis( int $course_id, array $result ): int {
        $course = get_post( $course_id );
        $title  = sprintf(
            'Analyse — %s — %s',
            $course ? $course->post_title : "Cours #{$course_id}",
            wp_date( 'Y-m-d H:i' )
        );

        $post_id = wp_insert_post( [
            'post_type'   => 'pl_analysis',
            'post_title'  => $title,
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $post_id ) ) {
            PedagoLens_Core::log( 'error', 'save_analysis — wp_insert_post échoué', [ 'course_id' => $course_id ] );
            return 0;
        }

        update_post_meta( $post_id, '_pl_course_id',        $course_id );
        update_post_meta( $post_id, '_pl_profile_scores',   wp_json_encode( $result['profile_scores']   ?? [] ) );
        update_post_meta( $post_id, '_pl_recommendations',  wp_json_encode( $result['recommendations']  ?? [] ) );
        update_post_meta( $post_id, '_pl_raw_response',     wp_json_encode( $result ) );
        update_post_meta( $post_id, '_pl_analyzed_at',      gmdate( 'c' ) );
        update_post_meta( $post_id, '_pl_summary',          sanitize_textarea_field( $result['summary'] ?? '' ) );
        update_post_meta( $post_id, '_pl_impact_estimates', wp_json_encode( $result['impact_estimates'] ?? [] ) );

        return $post_id;
    }

    /**
     * Retourne les scores par profil d'une analyse.
     */
    public static function get_profile_scores( int $analysis_id ): array {
        $raw = get_post_meta( $analysis_id, '_pl_profile_scores', true );
        return is_string( $raw ) ? (array) json_decode( $raw, true ) : [];
    }

    /**
     * Retourne les recommandations d'une analyse, triées par priorité décroissante.
     */
    public static function get_recommendations( int $analysis_id ): array {
        $raw  = get_post_meta( $analysis_id, '_pl_recommendations', true );
        $recs = is_string( $raw ) ? (array) json_decode( $raw, true ) : [];

        usort( $recs, fn( $a, $b ) => ( $a['priority'] ?? 99 ) <=> ( $b['priority'] ?? 99 ) );

        return $recs;
    }

    // -------------------------------------------------------------------------
    // Projets
    // -------------------------------------------------------------------------

    /**
     * Crée un projet pl_project rattaché à un cours.
     */
    public static function create_project( int $course_id, string $type, string $title ): int {
        $allowed_types = [ 'magistral', 'exercice', 'evaluation', 'travail_equipe' ];
        if ( ! in_array( $type, $allowed_types, true ) ) {
            return 0;
        }

        $post_id = wp_insert_post( [
            'post_type'   => 'pl_project',
            'post_title'  => sanitize_text_field( $title ),
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $post_id ) ) {
            return 0;
        }

        $now = gmdate( 'c' );
        update_post_meta( $post_id, '_pl_course_id',         $course_id );
        update_post_meta( $post_id, '_pl_project_type',      $type );
        update_post_meta( $post_id, '_pl_content_sections',  wp_json_encode( [] ) );
        update_post_meta( $post_id, '_pl_profile_scores',    wp_json_encode( [] ) );
        update_post_meta( $post_id, '_pl_recommendations',   wp_json_encode( [] ) );
        update_post_meta( $post_id, '_pl_impact_estimates',  wp_json_encode( [] ) );
        update_post_meta( $post_id, '_pl_versions',          wp_json_encode( [] ) );
        update_post_meta( $post_id, '_pl_created_at',        $now );
        update_post_meta( $post_id, '_pl_updated_at',        $now );

        return $post_id;
    }

    /**
     * Retourne les projets d'un cours, triés par date décroissante.
     */
    public static function get_projects( int $course_id ): array {
        $query = new WP_Query( [
            'post_type'      => 'pl_project',
            'posts_per_page' => -1,
            'meta_query'     => [ [
                'key'   => '_pl_course_id',
                'value' => $course_id,
                'type'  => 'NUMERIC',
            ] ],
            'orderby' => 'date',
            'order'   => 'DESC',
        ] );

        $projects = [];
        foreach ( $query->posts as $post ) {
            $projects[] = [
                'id'           => $post->ID,
                'title'        => $post->post_title,
                'type'         => get_post_meta( $post->ID, '_pl_project_type', true ),
                'created_at'   => get_post_meta( $post->ID, '_pl_created_at',   true ),
                'updated_at'   => get_post_meta( $post->ID, '_pl_updated_at',   true ),
            ];
        }

        return $projects;
    }

    // -------------------------------------------------------------------------
    // Rendu front-end (shortcode délégué depuis pedagolens-landing)
    // -------------------------------------------------------------------------

    /**
     * Rendu HTML du dashboard enseignant pour le front-end (shortcode).
     * Layout professionnel : header, sidebar, main, footer.
     */
    public static function render_front(): string {
        if ( ! is_user_logged_in() ) {
            $login_url = esc_url( wp_login_url( get_permalink() ) );
            return "<div class=\"pl-notice pl-notice-warning\"><p>Vous devez être connecté pour accéder au tableau de bord enseignant. <a href=\"{$login_url}\">Se connecter</a></p></div>";
        }

        $user       = wp_get_current_user();
        $is_teacher = in_array( 'pedagolens_teacher', (array) $user->roles, true )
                   || in_array( 'administrator',      (array) $user->roles, true );

        if ( ! $is_teacher ) {
            return '<div class="pl-notice pl-notice-error"><p>Accès réservé aux enseignants.</p></div>';
        }

        wp_enqueue_style(
            'pl-dashboard-front',
            PL_DASHBOARD_PLUGIN_URL . 'assets/css/dashboard-admin.css',
            [],
            PL_DASHBOARD_VERSION
        );
        wp_enqueue_script(
            'pl-dashboard-front',
            PL_DASHBOARD_PLUGIN_URL . 'assets/js/dashboard-admin.js',
            [],
            PL_DASHBOARD_VERSION,
            true
        );

        $courses = get_posts( [
            'post_type'      => 'pl_course',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        $mode      = get_option( 'pl_ai_mode', 'mock' );
        $firstname = esc_html( $user->first_name ?: $user->display_name );

        $total_courses  = count( $courses );
        $total_projects = 0;
        $all_scores     = [];
        $courses_data   = [];

        foreach ( $courses as $course ) {
            $course_type = get_post_meta( $course->ID, '_pl_course_type', true ) ?: 'magistral';
            $projects    = self::get_projects( $course->ID );
            $total_projects += count( $projects );

            $analysis = self::get_latest_analysis_front( $course->ID );
            if ( $analysis ) {
                foreach ( ( $analysis['profile_scores'] ?? [] ) as $s ) {
                    $all_scores[] = (int) $s;
                }
            }

            $courses_data[] = [
                'post'     => $course,
                'type'     => $course_type,
                'projects' => $projects,
                'analysis' => $analysis,
            ];
        }

        $total_analyses = self::count_all_analyses();
        $avg_score      = ! empty( $all_scores ) ? (int) round( array_sum( $all_scores ) / count( $all_scores ) ) : 0;

        // Resolve page URLs
        $workbench_page = get_page_by_path( 'workbench' );
        $workbench_url  = $workbench_page ? get_permalink( $workbench_page ) : admin_url( 'admin.php?page=pl-course-workbench' );
        $twin_page      = get_page_by_path( 'dashboard-etudiant' );
        $twin_url       = $twin_page ? get_permalink( $twin_page ) : admin_url( 'admin.php?page=pl-student-twin' );
        $account_page   = get_page_by_path( 'compte' );
        $account_url    = $account_page ? get_permalink( $account_page ) : home_url( '/compte' );
        $settings_url   = admin_url( 'admin.php?page=pl-api-bridge-settings' );
        $logout_url     = wp_logout_url( home_url( '/' ) );

        ob_start();
        ?>
        <div class="pl-dash-wrap">

            <!-- ============ TOP HEADER ============ -->
            <header class="pl-dash-header">
                <div class="pl-dash-header-inner">
                    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="pl-dash-header-logo">PédagoLens</a>
                    <nav class="pl-dash-header-nav">
                        <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Accueil</a>
                        <a href="<?php echo esc_url( $twin_url ); ?>">Jumeau</a>
                        <a href="<?php echo esc_url( $account_url ); ?>">Compte</a>
                    </nav>
                    <button class="pl-dash-hamburger" id="pl-hamburger" aria-label="Menu">
                        <span></span><span></span><span></span>
                    </button>
                </div>
            </header>

            <div class="pl-dash-body">

                <!-- ============ SIDEBAR ============ -->
                <aside class="pl-dash-sidebar" id="pl-sidebar">
                    <div class="pl-sidebar-logo">
                        <span class="pl-sidebar-logo-icon">🎓</span>
                        <span class="pl-sidebar-logo-text">PédagoLens</span>
                    </div>
                    <nav class="pl-sidebar-nav">
                        <a href="#" class="pl-sidebar-link pl-sidebar-active" data-view="overview">
                            <span class="pl-sidebar-icon">📊</span> Vue d'ensemble
                        </a>
                        <a href="#" class="pl-sidebar-link" data-view="courses">
                            <span class="pl-sidebar-icon">📚</span> Mes cours
                        </a>
                        <a href="<?php echo esc_url( $workbench_url ); ?>" class="pl-sidebar-link">
                            <span class="pl-sidebar-icon">📝</span> Workbench
                        </a>
                        <a href="<?php echo esc_url( $twin_url ); ?>" class="pl-sidebar-link">
                            <span class="pl-sidebar-icon">🤖</span> Jumeau numérique
                        </a>
                        <a href="<?php echo esc_url( $settings_url ); ?>" class="pl-sidebar-link">
                            <span class="pl-sidebar-icon">⚙️</span> Paramètres API
                        </a>
                        <a href="<?php echo esc_url( $account_url ); ?>" class="pl-sidebar-link">
                            <span class="pl-sidebar-icon">👤</span> Mon compte
                        </a>
                    </nav>
                    <div class="pl-sidebar-bottom">
                        <div class="pl-sidebar-divider"></div>
                        <span class="pl-mode-badge pl-mode-badge--<?php echo esc_attr( $mode ); ?>">
                            <span class="pl-pulse-dot"></span>
                            <?php echo $mode === 'mock' ? 'Mode Mock' : 'AWS Bedrock'; ?>
                        </span>
                        <a href="<?php echo esc_url( $logout_url ); ?>" class="pl-sidebar-logout">🚪 Déconnexion</a>
                    </div>
                </aside>

                <!-- ============ MAIN CONTENT ============ -->
                <main class="pl-dash-main">

                    <!-- VIEW: Overview -->
                    <section class="pl-dash-view pl-dash-view--active" id="pl-view-overview">
                        <div class="pl-welcome pl-animate-in">Bonjour, <?php echo $firstname; ?> 👋</div>

                        <div class="pl-stats-grid">
                            <div class="pl-stat-card pl-animate-in" data-accent="courses">
                                <span class="pl-stat-icon">📚</span>
                                <div class="pl-stat-number" data-target="<?php echo (int) $total_courses; ?>">0</div>
                                <div class="pl-stat-label">Cours</div>
                            </div>
                            <div class="pl-stat-card pl-animate-in" data-accent="analyses">
                                <span class="pl-stat-icon">🔍</span>
                                <div class="pl-stat-number" data-target="<?php echo (int) $total_analyses; ?>">0</div>
                                <div class="pl-stat-label">Analyses</div>
                            </div>
                            <div class="pl-stat-card pl-animate-in" data-accent="projects">
                                <span class="pl-stat-icon">📄</span>
                                <div class="pl-stat-number" data-target="<?php echo (int) $total_projects; ?>">0</div>
                                <div class="pl-stat-label">Projets</div>
                            </div>
                            <div class="pl-stat-card pl-animate-in" data-accent="score">
                                <span class="pl-stat-icon">🏆</span>
                                <div class="pl-stat-number" data-target="<?php echo (int) $avg_score; ?>">0</div>
                                <div class="pl-stat-label">Score moyen</div>
                            </div>
                        </div>

                        <div class="pl-section-header-row">
                            <h3 class="pl-section-title">
                                <span class="pl-section-icon">📚</span> Mes cours
                                <span class="pl-section-count"><?php echo (int) $total_courses; ?></span>
                            </h3>
                        </div>

                        <div class="pl-add-course-zone pl-animate-in">
                            <button type="button" class="pl-btn-add-course" id="pl-btn-add-course">
                                <span class="pl-add-icon">➕</span> Ajouter un cours
                            </button>
                        </div>

                        <?php if ( ! empty( $courses_data ) ) : ?>
                        <div class="pl-courses-grid">
                            <?php foreach ( $courses_data as $cd ) :
                                $course      = $cd['post'];
                                $course_type = $cd['type'];
                                $projects    = $cd['projects'];
                                $analysis    = $cd['analysis'];
                                $last_date   = '';
                                if ( $analysis && ! empty( $analysis['analysis_id'] ) ) {
                                    $a_post = get_post( $analysis['analysis_id'] );
                                    if ( $a_post ) { $last_date = wp_date( 'j M Y', strtotime( $a_post->post_date ) ); }
                                }
                            ?>
                            <div class="pl-course-card pl-animate-in" data-course-id="<?php echo (int) $course->ID; ?>">
                                <div class="pl-course-card-body">
                                    <div class="pl-course-header">
                                        <h3><?php echo esc_html( $course->post_title ); ?></h3>
                                        <span class="pl-badge pl-type-<?php echo esc_attr( $course_type ); ?>"><?php echo esc_html( $course_type ); ?></span>
                                    </div>
                                    <div class="pl-course-meta">
                                        <span>📅 <?php echo esc_html( get_the_date( 'j M Y', $course ) ); ?></span>
                                        <span>📄 <?php echo count( $projects ); ?> projet(s)</span>
                                        <?php if ( $last_date ) : ?><span>🔍 <?php echo esc_html( $last_date ); ?></span><?php endif; ?>
                                    </div>
                                    <div class="pl-course-actions">
                                        <button class="pl-btn-glow pl-btn-sm pl-btn-analyze-front" data-course-id="<?php echo (int) $course->ID; ?>">🔍 Analyser</button>
                                        <button class="pl-btn-ghost pl-btn-sm pl-btn-open-course" data-course-id="<?php echo (int) $course->ID; ?>">📂 Ouvrir</button>
                                    </div>
                                </div>
                                <div id="pl-analysis-result-<?php echo (int) $course->ID; ?>" class="pl-analysis-front-result">
                                    <?php if ( $analysis ) : ?><?php PedagoLens_Dashboard_Admin::render_analysis_result( $analysis ); ?><?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </section>

                    <!-- VIEW: Course detail -->
                    <section class="pl-dash-view" id="pl-view-courses">
                        <div class="pl-view-back-row">
                            <button class="pl-btn-ghost pl-btn-sm pl-btn-back-overview">← Retour</button>
                        </div>
                        <div id="pl-course-detail-content"></div>
                    </section>

                </main>
            </div>

            <!-- ============ FOOTER ============ -->
            <footer class="pl-dash-footer">
                <div class="pl-dash-footer-inner">
                    <span class="pl-footer-logo">PédagoLens</span>
                    <p class="pl-footer-copy">© 2026 PédagoLens — Propulsé par AWS Bedrock</p>
                </div>
            </footer>
        </div>

        <script type="application/json" id="pl-courses-json"><?php
            $json_data = [];
            foreach ( $courses_data as $cd ) {
                $c = $cd['post'];
                $json_data[] = [
                    'id'       => $c->ID,
                    'title'    => $c->post_title,
                    'type'     => $cd['type'],
                    'date'     => get_the_date( 'j M Y', $c ),
                    'projects' => array_map( function( $p ) use ( $workbench_page ) {
                        $wb = $workbench_page
                            ? get_permalink( $workbench_page ) . '?project_id=' . $p['id']
                            : admin_url( 'admin.php?page=pl-course-workbench&project_id=' . $p['id'] );
                        return [
                            'id'    => $p['id'],
                            'title' => $p['title'],
                            'type'  => $p['type'],
                            'date'  => $p['created_at'] ? wp_date( 'j M Y', strtotime( $p['created_at'] ) ) : '',
                            'url'   => $wb,
                        ];
                    }, $cd['projects'] ),
                ];
            }
            echo wp_json_encode( $json_data );
        ?></script>
        <?php
        return ob_get_clean();
    }

    /**
     * Retourne la dernière analyse d'un cours (pour le front).
     */
    private static function get_latest_analysis_front( int $course_id ): ?array {
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
            'analysis_id'     => $id,
            'profile_scores'  => self::get_profile_scores( $id ),
            'recommendations' => self::get_recommendations( $id ),
            'summary'         => get_post_meta( $id, '_pl_summary', true ),
        ];
    }

    /**
     * Compte les analyses d'un cours.
     */
    private static function count_analyses( int $course_id ): int {
        $q = new WP_Query( [
            'post_type'      => 'pl_analysis',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [ [
                'key'   => '_pl_course_id',
                'value' => $course_id,
                'type'  => 'NUMERIC',
            ] ],
        ] );
        return $q->found_posts;
    }

    /**
     * Compte toutes les analyses.
     */
    private static function count_all_analyses(): int {
        $q = new WP_Query( [
            'post_type'      => 'pl_analysis',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );
        return $q->found_posts;
    }

    /**
     * Retourne les profils actifs via Profile_Manager si disponible.
     */
    public static function get_active_profiles(): array {
        if ( class_exists( 'PedagoLens_Profile_Manager' ) ) {
            return PedagoLens_Profile_Manager::get_all( active_only: true );
        }
        return [];
    }

    /**
     * S'assure que profile_scores contient exactement les slugs des profils actifs.
     */
    private static function normalize_scores( array $result, array $profiles ): array {
        $scores      = $result['profile_scores'] ?? [];
        $normalized  = [];

        foreach ( $profiles as $profile ) {
            $slug              = $profile['slug'];
            $normalized[$slug] = isset( $scores[$slug] ) ? (int) $scores[$slug] : 0;
        }

        $result['profile_scores'] = $normalized;
        return $result;
    }

    private static function error( string $code, string $message, array $context = [] ): array {
        PedagoLens_Core::log( 'error', $message, $context );
        return [
            'success'       => false,
            'error_code'    => $code,
            'error_message' => $message,
            'context'       => $context,
        ];
    }
}
