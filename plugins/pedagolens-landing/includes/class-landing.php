<?php
/**
 * PedagoLens_Landing
 *
 * Shortcodes front-end pour toutes les pages publiques PédagoLens.
 * - Landing page    : [pedagolens_landing]
 * - Dashboard prof  : [pedagolens_teacher_dashboard]
 * - Dashboard étud  : [pedagolens_student_dashboard]
 * - Cours & Projets : [pedagolens_courses]
 * - Workbench       : [pedagolens_workbench]
 * - Compte          : [pedagolens_account]
 */

defined( 'ABSPATH' ) || exit;

class PedagoLens_Landing {

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function init(): void {
        PedagoLens_Landing_Admin::register();

        add_shortcode( 'pedagolens_landing',           [ self::class, 'shortcode_landing' ] );
        add_shortcode( 'pedagolens_teacher_dashboard', [ self::class, 'shortcode_teacher_dashboard' ] );
        add_shortcode( 'pedagolens_student_dashboard', [ self::class, 'shortcode_student_dashboard' ] );
        add_shortcode( 'pedagolens_courses',           [ self::class, 'shortcode_courses' ] );
        add_shortcode( 'pedagolens_workbench',         [ self::class, 'shortcode_workbench' ] );
        add_shortcode( 'pedagolens_account',           [ self::class, 'shortcode_account' ] );

        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_front_assets' ] );
    }

    // -------------------------------------------------------------------------
    // Assets front-end
    // -------------------------------------------------------------------------

    public static function enqueue_front_assets(): void {
        wp_enqueue_style(
            'pl-landing',
            PL_LANDING_PLUGIN_URL . 'assets/css/landing.css',
            [],
            PL_LANDING_VERSION
        );
    }

    // -------------------------------------------------------------------------
    // [pedagolens_landing] — Page d'accueil marketing
    // -------------------------------------------------------------------------

    public static function shortcode_landing( array $atts ): string {
        $s        = self::get_settings();
        $color    = esc_attr( $s['primary_color'] ?? '#2271b1' );
        $title    = esc_html( $s['hero_title']    ?? 'PédagoLens' );
        $subtitle = esc_html( $s['hero_subtitle'] ?? "L'IA p&eacute;dagogique pour les enseignants du C&Eacute;GEP." );
        $cta_text = esc_html( $s['cta_text']      ?? 'Demander une d&eacute;mo' );
        $cta_url  = esc_url(  $s['cta_url']       ?? '#' );

        // Badges profils
        $profiles_html = '';
        if ( class_exists( 'PedagoLens_Profile_Manager' ) ) {
            foreach ( PedagoLens_Profile_Manager::get_all( active_only: true ) as $p ) {
                $name          = esc_html( $p['name'] ?? $p['slug'] );
                $profiles_html .= "<span class=\"pl-hero-profile-badge\">{$name}</span>";
            }
        }
        $profiles_section = $profiles_html
            ? "<div class=\"pl-hero-profiles\"><span class=\"pl-hero-profiles-label\">7 profils d'apprenants :</span>{$profiles_html}</div>"
            : '';

        // Features
        $features      = $s['features'] ?? self::default_features();
        $features_html = '';
        foreach ( $features as $f ) {
            $icon   = esc_html( $f['icon']  ?? '' );
            $ftitle = esc_html( $f['title'] ?? '' );
            $desc   = esc_html( $f['desc']  ?? '' );
            $features_html .= "<div class=\"pl-feature-card\"><span class=\"pl-feature-icon\">{$icon}</span><h3>{$ftitle}</h3><p>{$desc}</p></div>";
        }

        return <<<HTML
        <div class="pl-landing-page" style="--pl-primary:{$color};">
            <section class="pl-hero">
                <div class="pl-hero-inner">
                    <div class="pl-hero-badge">&#10022; Propuls&eacute; par AWS Bedrock</div>
                    <h1 class="pl-hero-title">{$title}</h1>
                    <p class="pl-hero-subtitle">{$subtitle}</p>
                    {$profiles_section}
                    <div class="pl-hero-cta-group">
                        <a href="{$cta_url}" class="pl-btn-cta">{$cta_text}</a>
                        <span class="pl-hero-note">Mode d&eacute;mo disponible &mdash; aucun compte requis</span>
                    </div>
                </div>
            </section>
            <section class="pl-features">
                <div class="pl-section-header-text"><h2>Tout ce dont vous avez besoin</h2></div>
                <div class="pl-features-grid">{$features_html}</div>
            </section>
        </div>
        HTML;
    }

    // -------------------------------------------------------------------------
    // [pedagolens_teacher_dashboard] — Dashboard enseignant front-end
    // -------------------------------------------------------------------------

    public static function shortcode_teacher_dashboard( array $atts ): string {
        if ( ! is_user_logged_in() ) {
            return self::render_login_notice( 'Vous devez &ecirc;tre connect&eacute; pour acc&eacute;der au tableau de bord enseignant.' );
        }

        $user = wp_get_current_user();
        $is_teacher = in_array( 'pedagolens_teacher', (array) $user->roles, true )
                   || in_array( 'administrator',      (array) $user->roles, true );

        if ( ! $is_teacher ) {
            return '<div class="pl-notice pl-notice-error"><p>Acc&egrave;s r&eacute;serv&eacute; aux enseignants.</p></div>';
        }

        $courses = get_posts( [
            'post_type'      => 'pl_course',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        $mode = get_option( 'pl_ai_mode', 'mock' );

        ob_start();
        ?>
        <div class="pl-front-dashboard pl-teacher-dashboard">
            <?php if ( $mode === 'mock' ) : ?>
                <div class="pl-notice pl-notice-info">
                    <p>Mode mock actif &mdash; les analyses utilisent des donn&eacute;es de d&eacute;monstration.</p>
                </div>
            <?php endif; ?>

            <div class="pl-dashboard-header">
                <h2>Mes cours</h2>
                <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=pl_course' ) ); ?>" class="pl-btn pl-btn-primary">
                    + Nouveau cours
                </a>
            </div>

            <?php if ( empty( $courses ) ) : ?>
                <div class="pl-notice pl-notice-warning">
                    <p>Aucun cours trouv&eacute;. <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=pl_course' ) ); ?>">Cr&eacute;er un cours</a></p>
                </div>
            <?php else : ?>
                <div class="pl-courses-grid">
                    <?php foreach ( $courses as $course ) :
                        $course_type = get_post_meta( $course->ID, '_pl_course_type', true ) ?: 'magistral';
                        $projects    = class_exists( 'PedagoLens_Teacher_Dashboard' )
                            ? PedagoLens_Teacher_Dashboard::get_projects( $course->ID )
                            : [];
                        ?>
                        <div class="pl-course-card">
                            <div class="pl-course-header">
                                <h3><?php echo esc_html( $course->post_title ); ?></h3>
                                <span class="pl-badge pl-type-<?php echo esc_attr( $course_type ); ?>">
                                    <?php echo esc_html( $course_type ); ?>
                                </span>
                            </div>
                            <div class="pl-course-meta">
                                <span><?php echo count( $projects ); ?> projet(s)</span>
                            </div>
                            <div class="pl-course-actions">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=pl-teacher-dashboard' ) ); ?>" class="pl-btn pl-btn-primary pl-btn-sm">
                                    Analyser
                                </a>
                                <a href="<?php echo esc_url( get_permalink( get_page_by_path( 'cours-projets' ) ) . '?course_id=' . $course->ID ); ?>" class="pl-btn pl-btn-sm">
                                    Projets
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // [pedagolens_student_dashboard] — Dashboard étudiant (jumeau numérique)
    // -------------------------------------------------------------------------

    public static function shortcode_student_dashboard( array $atts ): string {
        $atts = shortcode_atts( [ 'course_id' => 0 ], $atts );

        if ( ! class_exists( 'PedagoLens_Twin_Admin' ) ) {
            return '<div class="pl-notice pl-notice-error"><p>Le plugin Student Twin n\'est pas activ&eacute;.</p></div>';
        }

        // Déléguer au shortcode du jumeau numérique
        return PedagoLens_Twin_Admin::render_shortcode( $atts );
    }

    // -------------------------------------------------------------------------
    // [pedagolens_courses] — Liste des cours et projets
    // -------------------------------------------------------------------------

    public static function shortcode_courses( array $atts ): string {
        if ( ! is_user_logged_in() ) {
            return self::render_login_notice( 'Vous devez &ecirc;tre connect&eacute; pour acc&eacute;der &agrave; vos cours.' );
        }

        $course_id = (int) ( $_GET['course_id'] ?? 0 );

        $courses = get_posts( [
            'post_type'      => 'pl_course',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        ob_start();
        ?>
        <div class="pl-front-courses">
            <div class="pl-page-header">
                <h2>Cours &amp; Projets</h2>
            </div>

            <?php if ( empty( $courses ) ) : ?>
                <div class="pl-notice pl-notice-warning">
                    <p>Aucun cours disponible.</p>
                </div>
            <?php else : ?>
                <div class="pl-courses-list">
                    <?php foreach ( $courses as $course ) :
                        $course_type = get_post_meta( $course->ID, '_pl_course_type', true ) ?: 'magistral';
                        $projects    = class_exists( 'PedagoLens_Teacher_Dashboard' )
                            ? PedagoLens_Teacher_Dashboard::get_projects( $course->ID )
                            : [];
                        $is_open     = $course_id === $course->ID;
                        ?>
                        <div class="pl-course-item <?php echo $is_open ? 'pl-course-open' : ''; ?>">
                            <div class="pl-course-row">
                                <div class="pl-course-info">
                                    <h3><?php echo esc_html( $course->post_title ); ?></h3>
                                    <span class="pl-badge pl-type-<?php echo esc_attr( $course_type ); ?>">
                                        <?php echo esc_html( $course_type ); ?>
                                    </span>
                                </div>
                                <a href="?course_id=<?php echo (int) $course->ID; ?>" class="pl-btn pl-btn-sm">
                                    <?php echo $is_open ? 'Fermer' : 'Voir les projets'; ?>
                                </a>
                            </div>

                            <?php if ( $is_open ) : ?>
                                <div class="pl-projects-panel">
                                    <?php if ( empty( $projects ) ) : ?>
                                        <p class="pl-empty">Aucun projet pour ce cours.</p>
                                    <?php else : ?>
                                        <div class="pl-projects-grid">
                                            <?php foreach ( $projects as $project ) :
                                                $workbench_page = get_page_by_path( 'workbench' );
                                                $workbench_url  = $workbench_page
                                                    ? get_permalink( $workbench_page ) . '?project_id=' . $project['id']
                                                    : admin_url( 'admin.php?page=pl-course-workbench&project_id=' . $project['id'] );
                                                ?>
                                                <div class="pl-project-card">
                                                    <h4><?php echo esc_html( $project['title'] ); ?></h4>
                                                    <span class="pl-badge"><?php echo esc_html( $project['type'] ); ?></span>
                                                    <a href="<?php echo esc_url( $workbench_url ); ?>" class="pl-btn pl-btn-primary pl-btn-sm">
                                                        Ouvrir le Workbench
                                                    </a>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // [pedagolens_workbench] — Atelier d'édition de cours avec conseils IA
    // -------------------------------------------------------------------------

    public static function shortcode_workbench( array $atts ): string {
        if ( ! is_user_logged_in() ) {
            return self::render_login_notice( 'Vous devez &ecirc;tre connect&eacute; pour acc&eacute;der au workbench.' );
        }

        $project_id = (int) ( $_GET['project_id'] ?? 0 );

        if ( ! $project_id ) {
            return '<div class="pl-notice pl-notice-warning"><p>Aucun projet s&eacute;lectionn&eacute;. <a href="' . esc_url( get_permalink( get_page_by_path( 'cours-projets' ) ) ) . '">Retour aux cours</a></p></div>';
        }

        $project = get_post( $project_id );
        if ( ! $project || $project->post_type !== 'pl_project' ) {
            return '<div class="pl-notice pl-notice-error"><p>Projet introuvable.</p></div>';
        }

        // Rediriger vers l'admin workbench pour l'édition complète
        $admin_url = admin_url( 'admin.php?page=pl-course-workbench&project_id=' . $project_id );

        $project_title = esc_html( $project->post_title );
        $project_type  = esc_html( get_post_meta( $project_id, '_pl_project_type', true ) ?: 'magistral' );

        $sections = class_exists( 'PedagoLens_Course_Workbench' )
            ? PedagoLens_Course_Workbench::get_content_sections( $project_id )
            : [];

        $raw_scores = get_post_meta( $project_id, '_pl_profile_scores', true );
        $scores     = is_string( $raw_scores ) ? (array) json_decode( $raw_scores, true ) : [];

        ob_start();
        ?>
        <div class="pl-front-workbench">
            <div class="pl-workbench-header">
                <div>
                    <h2><?php echo $project_title; ?></h2>
                    <span class="pl-badge pl-type-<?php echo esc_attr( $project_type ); ?>"><?php echo $project_type; ?></span>
                </div>
                <a href="<?php echo esc_url( $admin_url ); ?>" class="pl-btn pl-btn-primary">
                    &#9998; &Eacute;diter dans l'atelier complet
                </a>
            </div>

            <?php if ( ! empty( $scores ) ) : ?>
                <div class="pl-scores-summary">
                    <h3>Scores par profil</h3>
                    <div class="pl-scores-bars">
                        <?php foreach ( $scores as $slug => $score ) :
                            $score = max( 0, min( 100, (int) $score ) );
                            $color = self::score_color( $score );
                            ?>
                            <div class="pl-score-row">
                                <span class="pl-score-label"><?php echo esc_html( $slug ); ?></span>
                                <div class="pl-score-bar-wrap">
                                    <div class="pl-score-bar" style="width:<?php echo $score; ?>%;background:<?php echo esc_attr( $color ); ?>;"></div>
                                </div>
                                <span class="pl-score-value"><?php echo $score; ?>/100</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( empty( $sections ) ) : ?>
                <div class="pl-notice pl-notice-info">
                    <p>Ce projet n'a pas encore de sections. <a href="<?php echo esc_url( $admin_url ); ?>">Ajouter du contenu</a></p>
                </div>
            <?php else : ?>
                <div class="pl-sections-preview">
                    <h3>Sections (<?php echo count( $sections ); ?>)</h3>
                    <?php foreach ( $sections as $section ) : ?>
                        <div class="pl-section-preview-card">
                            <h4><?php echo esc_html( $section['title'] ?? 'Section' ); ?></h4>
                            <p class="pl-section-excerpt">
                                <?php echo esc_html( mb_substr( $section['content'] ?? '', 0, 200 ) ); ?>
                                <?php if ( mb_strlen( $section['content'] ?? '' ) > 200 ) echo '&hellip;'; ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // [pedagolens_account] — Page de compte utilisateur
    // -------------------------------------------------------------------------

    public static function shortcode_account( array $atts ): string {
        if ( ! is_user_logged_in() ) {
            $login_url = wp_login_url( get_permalink() );
            return <<<HTML
            <div class="pl-account-login">
                <h2>Connexion</h2>
                <p>Connectez-vous pour acc&eacute;der &agrave; votre compte PédagoLens.</p>
                <a href="{$login_url}" class="pl-btn pl-btn-primary">Se connecter</a>
            </div>
            HTML;
        }

        $user    = wp_get_current_user();
        $name    = esc_html( $user->display_name );
        $email   = esc_html( $user->user_email );
        $roles   = (array) $user->roles;
        $is_admin   = in_array( 'administrator',      $roles, true );
        $is_teacher = in_array( 'pedagolens_teacher', $roles, true );
        $is_student = in_array( 'pedagolens_student', $roles, true );

        $role_label = match ( true ) {
            $is_admin   => 'Administrateur',
            $is_teacher => 'Enseignant',
            $is_student => 'Étudiant',
            default     => 'Utilisateur',
        };

        $logout_url = wp_logout_url( home_url() );

        // Liens rapides selon le rôle
        $quick_links = '';
        if ( $is_admin || $is_teacher ) {
            $teacher_page  = get_page_by_path( 'dashboard-enseignant' );
            $courses_page  = get_page_by_path( 'cours-projets' );
            $teacher_url   = $teacher_page ? get_permalink( $teacher_page ) : admin_url( 'admin.php?page=pl-teacher-dashboard' );
            $courses_url   = $courses_page ? get_permalink( $courses_page ) : admin_url( 'admin.php?page=pl-course-workbench' );
            $quick_links  .= "<a href=\"{$teacher_url}\" class=\"pl-btn pl-btn-sm\">&#128202; Dashboard enseignant</a> ";
            $quick_links  .= "<a href=\"{$courses_url}\" class=\"pl-btn pl-btn-sm\">&#128218; Cours &amp; Projets</a> ";
        }
        if ( $is_student || $is_admin ) {
            $student_page = get_page_by_path( 'dashboard-etudiant' );
            $student_url  = $student_page ? get_permalink( $student_page ) : '#';
            $quick_links .= "<a href=\"{$student_url}\" class=\"pl-btn pl-btn-sm\">&#129302; Jumeau num&eacute;rique</a> ";
        }

        return <<<HTML
        <div class="pl-account-page">
            <div class="pl-account-card">
                <div class="pl-account-avatar">&#128100;</div>
                <h2>{$name}</h2>
                <span class="pl-badge">{$role_label}</span>
                <p class="pl-account-email">{$email}</p>
                <div class="pl-account-links">
                    {$quick_links}
                </div>
                <div class="pl-account-footer">
                    <a href="{$logout_url}" class="pl-btn pl-btn-outline">D&eacute;connexion</a>
                </div>
            </div>
        </div>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function render_login_notice( string $message ): string {
        $login_url = esc_url( wp_login_url( get_permalink() ) );
        return "<div class=\"pl-notice pl-notice-warning\"><p>{$message} <a href=\"{$login_url}\">Se connecter</a></p></div>";
    }

    private static function score_color( int $score ): string {
        if ( $score >= 80 ) return '#00a32a';
        if ( $score >= 60 ) return '#2271b1';
        if ( $score >= 40 ) return '#dba617';
        return '#d63638';
    }

    public static function get_settings(): array {
        $raw = get_option( 'pl_landing_settings', [] );
        if ( is_string( $raw ) ) {
            $raw = json_decode( $raw, true ) ?? [];
        }

        return wp_parse_args( (array) $raw, [
            'hero_title'    => 'PédagoLens',
            'hero_subtitle' => "L'IA pédagogique pour les enseignants du CÉGEP.",
            'cta_text'      => 'Demander une démo',
            'cta_url'       => '#',
            'primary_color' => '#2271b1',
            'features'      => self::default_features(),
        ] );
    }

    private static function default_features(): array {
        return [
            [ 'icon' => '&#128269;', 'title' => 'Analyse pédagogique IA',    'desc' => "Analysez vos cours selon 7 profils d'apprenants en quelques secondes." ],
            [ 'icon' => '&#9999;',   'title' => 'Atelier de cours',           'desc' => "Recevez des suggestions concrètes pour améliorer l'accessibilité de vos contenus." ],
            [ 'icon' => '&#129302;', 'title' => 'Jumeau numérique étudiant', 'desc' => "Simulez l'expérience d'un étudiant avec des garde-fous pédagogiques intégrés." ],
            [ 'icon' => '&#128202;', 'title' => 'Tableau de bord',           'desc' => "Visualisez les scores par profil et suivez l'évolution de vos cours." ],
        ];
    }
}
