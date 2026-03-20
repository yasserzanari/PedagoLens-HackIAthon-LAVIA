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
    // Helpers
    // -------------------------------------------------------------------------

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
