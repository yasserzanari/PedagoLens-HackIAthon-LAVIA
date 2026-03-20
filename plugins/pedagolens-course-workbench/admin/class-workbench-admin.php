<?php
/**
 * PedagoLens_Workbench_Admin
 *
 * Interface admin du workbench :
 * - Vue projet avec sections et panneau latéral de scores
 * - Suggestions IA avec delta d'impact par profil
 * - Actions apply / reject / historique
 * - Éditeur de sections inline
 */

defined( 'ABSPATH' ) || exit;

class PedagoLens_Workbench_Admin {

    private const MENU_SLUG  = 'pl-course-workbench';
    private const NONCE_AJAX = 'pl_workbench_ajax';

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function register(): void {
        add_action( 'admin_menu',                            [ self::class, 'add_menu' ] );
        add_action( 'wp_ajax_pl_get_suggestions',            [ self::class, 'ajax_get_suggestions' ] );
        add_action( 'wp_ajax_pl_apply_suggestion',           [ self::class, 'ajax_apply_suggestion' ] );
        add_action( 'wp_ajax_pl_reject_suggestion',          [ self::class, 'ajax_reject_suggestion' ] );
        add_action( 'wp_ajax_pl_save_section',               [ self::class, 'ajax_save_section' ] );
        add_action( 'wp_ajax_pl_get_versions',               [ self::class, 'ajax_get_versions' ] );
        add_action( 'wp_ajax_pl_add_section',                [ self::class, 'ajax_add_section' ] );
        add_action( 'admin_enqueue_scripts',                 [ self::class, 'enqueue_assets' ] );
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
            __( 'Workbench', 'pedagolens-course-workbench' ),
            __( 'Workbench', 'pedagolens-course-workbench' ),
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
            'pl-workbench-admin',
            PL_WORKBENCH_PLUGIN_URL . 'assets/js/workbench-admin.js',
            [ 'jquery' ],
            PL_WORKBENCH_VERSION,
            true
        );

        wp_localize_script( 'pl-workbench-admin', 'plWorkbench', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( self::NONCE_AJAX ),
            'projectId'  => (int) ( $_GET['project_id'] ?? 0 ),
        ] );

        wp_enqueue_style(
            'pl-workbench-admin',
            PL_WORKBENCH_PLUGIN_URL . 'assets/css/workbench-admin.css',
            [],
            PL_WORKBENCH_VERSION
        );
    }

    // -------------------------------------------------------------------------
    // Page principale
    // -------------------------------------------------------------------------

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'pedagolens-course-workbench' ) );
        }

        $project_id = (int) ( $_GET['project_id'] ?? 0 );

        if ( ! $project_id ) {
            self::render_project_list();
            return;
        }

        $project = get_post( $project_id );
        if ( ! $project || $project->post_type !== 'pl_project' ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Projet introuvable.', 'pedagolens-course-workbench' ) . '</p></div>';
            return;
        }

        self::render_workbench( $project );
    }

    // -------------------------------------------------------------------------
    // Liste des projets
    // -------------------------------------------------------------------------

    private static function render_project_list(): void {
        $projects = get_posts( [
            'post_type'      => 'pl_project',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Workbench — Projets', 'pedagolens-course-workbench' ); ?></h1>

            <?php if ( empty( $projects ) ) : ?>
                <p><?php esc_html_e( 'Aucun projet. Créez-en un depuis le tableau de bord enseignant.', 'pedagolens-course-workbench' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Titre', 'pedagolens-course-workbench' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'pedagolens-course-workbench' ); ?></th>
                            <th><?php esc_html_e( 'Modifié le', 'pedagolens-course-workbench' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $projects as $p ) : ?>
                            <tr>
                                <td><?php echo esc_html( $p->post_title ); ?></td>
                                <td><code><?php echo esc_html( get_post_meta( $p->ID, '_pl_project_type', true ) ); ?></code></td>
                                <td><?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( get_post_meta( $p->ID, '_pl_updated_at', true ) ?: $p->post_modified ) ) ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&project_id=' . $p->ID ) ); ?>" class="button button-primary button-small">
                                        <?php esc_html_e( 'Ouvrir', 'pedagolens-course-workbench' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Vue workbench d'un projet
    // -------------------------------------------------------------------------

    private static function render_workbench( WP_Post $project ): void {
        $project_type = get_post_meta( $project->ID, '_pl_project_type', true ) ?: 'magistral';
        $sections     = PedagoLens_Course_Workbench::get_content_sections( $project->ID );
        $profiles     = class_exists( 'PedagoLens_Profile_Manager' )
            ? PedagoLens_Profile_Manager::get_all( active_only: true )
            : [];

        // Scores existants (dernière analyse du projet)
        $raw_scores   = get_post_meta( $project->ID, '_pl_profile_scores', true );
        $scores       = is_string( $raw_scores ) ? (array) json_decode( $raw_scores, true ) : [];
        ?>
        <div class="wrap pl-workbench-wrap">

            <div class="pl-workbench-header">
                <h1>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">←</a>
                    <?php echo esc_html( $project->post_title ); ?>
                    <span class="pl-project-type-badge pl-type-<?php echo esc_attr( $project_type ); ?>">
                        <?php echo esc_html( $project_type ); ?>
                    </span>
                </h1>
                <button type="button" id="pl-add-section" class="button">
                    + <?php esc_html_e( 'Ajouter une section', 'pedagolens-course-workbench' ); ?>
                </button>
            </div>

            <div class="pl-workbench-layout">

                <!-- Colonne principale : sections -->
                <div class="pl-workbench-main">
                    <?php if ( empty( $sections ) ) : ?>
                        <div class="pl-empty-sections">
                            <p><?php esc_html_e( 'Aucune section. Ajoutez du contenu pour commencer.', 'pedagolens-course-workbench' ); ?></p>
                        </div>
                    <?php else : ?>
                        <?php foreach ( $sections as $section ) : ?>
                            <?php self::render_section_block( $section, $project->ID ); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Panneau latéral : scores par profil -->
                <div class="pl-workbench-sidebar">
                    <div class="pl-sidebar-card">
                        <h3><?php esc_html_e( 'Scores par profil', 'pedagolens-course-workbench' ); ?></h3>
                        <div id="pl-sidebar-scores">
                            <?php if ( empty( $scores ) ) : ?>
                                <p class="pl-sidebar-empty">
                                    <?php esc_html_e( 'Analysez une section pour voir les scores.', 'pedagolens-course-workbench' ); ?>
                                </p>
                            <?php else : ?>
                                <?php self::render_score_bars( $scores ); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Modale historique des versions -->
        <div id="pl-versions-modal" style="display:none;">
            <div class="pl-modal-overlay">
                <div class="pl-modal-box">
                    <h2><?php esc_html_e( 'Historique des versions', 'pedagolens-course-workbench' ); ?></h2>
                    <div id="pl-versions-content"></div>
                    <button type="button" id="pl-versions-close" class="button">
                        <?php esc_html_e( 'Fermer', 'pedagolens-course-workbench' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Bloc section
    // -------------------------------------------------------------------------

    private static function render_section_block( array $section, int $project_id ): void {
        $section_id = esc_attr( $section['id'] ?? '' );
        $title      = esc_html( $section['title'] ?? 'Section' );
        $content    = esc_textarea( $section['content'] ?? '' );
        ?>
        <div class="pl-section-block" id="pl-section-<?php echo $section_id; ?>" data-section-id="<?php echo $section_id; ?>">

            <div class="pl-section-header">
                <h2 class="pl-section-title"><?php echo $title; ?></h2>
                <div class="pl-section-actions">
                    <button type="button" class="button button-small pl-btn-suggestions" data-section-id="<?php echo $section_id; ?>">
                        💡 <?php esc_html_e( 'Suggestions IA', 'pedagolens-course-workbench' ); ?>
                    </button>
                    <button type="button" class="button button-small pl-btn-history" data-section-id="<?php echo $section_id; ?>">
                        🕐 <?php esc_html_e( 'Historique', 'pedagolens-course-workbench' ); ?>
                    </button>
                </div>
            </div>

            <div class="pl-section-editor">
                <textarea
                    class="pl-section-content large-text"
                    data-section-id="<?php echo $section_id; ?>"
                    rows="8"
                ><?php echo $content; ?></textarea>
                <div class="pl-section-save-row">
                    <button type="button" class="button button-primary pl-btn-save-section" data-section-id="<?php echo $section_id; ?>">
                        <?php esc_html_e( 'Enregistrer', 'pedagolens-course-workbench' ); ?>
                    </button>
                    <span class="pl-save-status"></span>
                </div>
            </div>

            <!-- Zone suggestions (remplie par AJAX) -->
            <div class="pl-suggestions-zone" id="pl-suggestions-<?php echo $section_id; ?>" style="display:none;"></div>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Barres de scores
    // -------------------------------------------------------------------------

    public static function render_score_bars( array $scores ): void {
        foreach ( $scores as $slug => $score ) :
            $score = max( 0, min( 100, (int) $score ) );
            $color = self::score_color( $score );
            ?>
            <div class="pl-score-row">
                <span class="pl-score-label" title="<?php echo esc_attr( $slug ); ?>">
                    <?php echo esc_html( $slug ); ?>
                </span>
                <div class="pl-score-bar-wrap">
                    <div class="pl-score-bar" style="width:<?php echo $score; ?>%;background:<?php echo esc_attr( $color ); ?>;"></div>
                </div>
                <span class="pl-score-value"><?php echo $score; ?></span>
            </div>
        <?php endforeach;
    }

    // -------------------------------------------------------------------------
    // AJAX — Suggestions
    // -------------------------------------------------------------------------

    public static function ajax_get_suggestions(): void {
        self::verify_nonce();

        $project_id = (int) ( $_POST['project_id'] ?? 0 );
        $section_id = sanitize_text_field( $_POST['section_id'] ?? '' );

        $result = PedagoLens_Course_Workbench::get_suggestions( $project_id, $section_id );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( [ 'message' => $result['error_message'] ?? 'Erreur.' ] );
        }

        // Mettre en cache les suggestions pour apply
        $raw   = get_post_meta( $project_id, '_pl_last_suggestions', true );
        $cache = is_string( $raw ) ? (array) json_decode( $raw, true ) : [];
        $cache[ $section_id ] = $result['suggestions'] ?? [];
        update_post_meta( $project_id, '_pl_last_suggestions', wp_json_encode( $cache ) );

        ob_start();
        self::render_suggestions_html( $result['suggestions'] ?? [], $section_id );
        $html = ob_get_clean();

        // Mettre à jour les scores dans le sidebar si présents
        $scores_html = '';
        if ( ! empty( $result['profile_scores'] ) ) {
            ob_start();
            self::render_score_bars( $result['profile_scores'] );
            $scores_html = ob_get_clean();
            update_post_meta( $project_id, '_pl_profile_scores', wp_json_encode( $result['profile_scores'] ) );
        }

        wp_send_json_success( [ 'html' => $html, 'scores_html' => $scores_html ] );
    }

    private static function render_suggestions_html( array $suggestions, string $section_id ): void {
        if ( empty( $suggestions ) ) {
            echo '<p>' . esc_html__( 'Aucune suggestion pour cette section.', 'pedagolens-course-workbench' ) . '</p>';
            return;
        }
        ?>
        <div class="pl-suggestions-list">
            <h4><?php esc_html_e( 'Suggestions IA', 'pedagolens-course-workbench' ); ?></h4>
            <?php foreach ( $suggestions as $sug ) :
                $sug_id = esc_attr( $sug['id'] ?? '' );
                ?>
                <div class="pl-suggestion-card" id="pl-sug-<?php echo $sug_id; ?>">

                    <div class="pl-sug-meta">
                        <?php if ( ! empty( $sug['profile_target'] ) ) : ?>
                            <span class="pl-sug-profile"><?php echo esc_html( $sug['profile_target'] ); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="pl-sug-diff">
                        <div class="pl-sug-original">
                            <strong><?php esc_html_e( 'Original', 'pedagolens-course-workbench' ); ?></strong>
                            <p><?php echo esc_html( $sug['original'] ?? '' ); ?></p>
                        </div>
                        <div class="pl-sug-proposed">
                            <strong><?php esc_html_e( 'Proposé', 'pedagolens-course-workbench' ); ?></strong>
                            <p><?php echo esc_html( $sug['proposed'] ?? '' ); ?></p>
                        </div>
                    </div>

                    <?php if ( ! empty( $sug['rationale'] ) ) : ?>
                        <p class="pl-sug-rationale"><em><?php echo esc_html( $sug['rationale'] ); ?></em></p>
                    <?php endif; ?>

                    <?php
                    // Delta d'impact par profil (si disponible)
                    if ( ! empty( $sug['impact_delta'] ) ) :
                        ?>
                        <div class="pl-sug-deltas">
                            <?php foreach ( $sug['impact_delta'] as $slug => $delta ) :
                                $sign  = $delta >= 0 ? '+' : '';
                                $class = $delta >= 0 ? 'pl-delta-pos' : 'pl-delta-neg';
                                ?>
                                <span class="pl-delta <?php echo $class; ?>">
                                    <?php echo esc_html( "{$sign}{$delta} pts {$slug}" ); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="pl-sug-actions">
                        <button type="button"
                            class="button button-primary pl-btn-apply"
                            data-project-id="<?php echo esc_attr( $_POST['project_id'] ?? '' ); ?>"
                            data-section-id="<?php echo esc_attr( $section_id ); ?>"
                            data-suggestion-id="<?php echo $sug_id; ?>">
                            ✓ <?php esc_html_e( 'Appliquer', 'pedagolens-course-workbench' ); ?>
                        </button>
                        <button type="button"
                            class="button pl-btn-reject"
                            data-project-id="<?php echo esc_attr( $_POST['project_id'] ?? '' ); ?>"
                            data-section-id="<?php echo esc_attr( $section_id ); ?>"
                            data-suggestion-id="<?php echo $sug_id; ?>">
                            ✗ <?php esc_html_e( 'Rejeter', 'pedagolens-course-workbench' ); ?>
                        </button>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX — Apply / Reject
    // -------------------------------------------------------------------------

    public static function ajax_apply_suggestion(): void {
        self::verify_nonce();

        $project_id   = (int) ( $_POST['project_id']   ?? 0 );
        $section_id   = sanitize_text_field( $_POST['section_id']   ?? '' );
        $suggestion_id = sanitize_text_field( $_POST['suggestion_id'] ?? '' );

        $ok = PedagoLens_Course_Workbench::apply_suggestion( $project_id, $section_id, $suggestion_id );

        if ( $ok ) {
            // Retourner le nouveau contenu de la section
            $sections = PedagoLens_Course_Workbench::get_content_sections( $project_id );
            $content  = '';
            foreach ( $sections as $s ) {
                if ( ( $s['id'] ?? '' ) === $section_id ) {
                    $content = $s['content'] ?? '';
                    break;
                }
            }
            wp_send_json_success( [ 'new_content' => $content ] );
        } else {
            wp_send_json_error( [ 'message' => 'Application de la suggestion échouée.' ] );
        }
    }

    public static function ajax_reject_suggestion(): void {
        self::verify_nonce();

        $project_id   = (int) ( $_POST['project_id']   ?? 0 );
        $section_id   = sanitize_text_field( $_POST['section_id']   ?? '' );
        $suggestion_id = sanitize_text_field( $_POST['suggestion_id'] ?? '' );

        PedagoLens_Course_Workbench::reject_suggestion( $project_id, $section_id, $suggestion_id );
        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // AJAX — Sauvegarder une section
    // -------------------------------------------------------------------------

    public static function ajax_save_section(): void {
        self::verify_nonce();

        $project_id = (int) ( $_POST['project_id'] ?? 0 );
        $section_id = sanitize_text_field( $_POST['section_id'] ?? '' );
        $content    = sanitize_textarea_field( $_POST['content'] ?? '' );

        $sections = PedagoLens_Course_Workbench::get_content_sections( $project_id );
        $updated  = false;

        foreach ( $sections as &$s ) {
            if ( ( $s['id'] ?? '' ) === $section_id ) {
                // Sauvegarder une version avant modification
                PedagoLens_Course_Workbench::save_version( $project_id, $section_id, $s['content'] ?? '' );
                $s['content'] = $content;
                $updated = true;
                break;
            }
        }

        if ( ! $updated ) {
            wp_send_json_error( [ 'message' => 'Section introuvable.' ] );
        }

        PedagoLens_Course_Workbench::save_content_sections( $project_id, $sections );
        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // AJAX — Historique des versions
    // -------------------------------------------------------------------------

    public static function ajax_get_versions(): void {
        self::verify_nonce();

        $project_id = (int) ( $_POST['project_id'] ?? 0 );
        $section_id = sanitize_text_field( $_POST['section_id'] ?? '' );

        $versions = PedagoLens_Course_Workbench::compare_versions( $project_id, $section_id );

        ob_start();
        if ( empty( $versions ) ) {
            echo '<p>' . esc_html__( 'Aucune version sauvegardée.', 'pedagolens-course-workbench' ) . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html__( 'Version', 'pedagolens-course-workbench' ) . '</th>';
            echo '<th>' . esc_html__( 'Date', 'pedagolens-course-workbench' ) . '</th>';
            echo '<th>' . esc_html__( 'Aperçu', 'pedagolens-course-workbench' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( array_reverse( $versions ) as $v ) {
                echo '<tr>';
                echo '<td>' . esc_html( 'v' . ( $v['version_no'] ?? '?' ) ) . '</td>';
                echo '<td>' . esc_html( wp_date( 'Y-m-d H:i', strtotime( $v['saved_at'] ?? '' ) ) ) . '</td>';
                echo '<td><code>' . esc_html( mb_substr( $v['content'] ?? '', 0, 80 ) ) . '…</code></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    // -------------------------------------------------------------------------
    // AJAX — Ajouter une section
    // -------------------------------------------------------------------------

    public static function ajax_add_section(): void {
        self::verify_nonce();

        $project_id = (int) ( $_POST['project_id'] ?? 0 );
        $title      = sanitize_text_field( $_POST['title'] ?? 'Nouvelle section' );

        $sections   = PedagoLens_Course_Workbench::get_content_sections( $project_id );
        $new_section = [
            'id'      => 'section_' . uniqid(),
            'title'   => $title,
            'content' => '',
        ];
        $sections[] = $new_section;

        PedagoLens_Course_Workbench::save_content_sections( $project_id, $sections );

        ob_start();
        self::render_section_block( $new_section, $project_id );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function verify_nonce(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }
        check_ajax_referer( self::NONCE_AJAX, 'nonce' );
    }

    private static function score_color( int $score ): string {
        if ( $score >= 80 ) return '#00a32a';
        if ( $score >= 60 ) return '#2271b1';
        if ( $score >= 40 ) return '#dba617';
        return '#d63638';
    }
}
