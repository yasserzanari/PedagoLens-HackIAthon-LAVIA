<?php
/**
 * PedagoLens_Student_Twin
 *
 * Logique métier du jumeau numérique :
 * - Gestion des sessions (start / end / history)
 * - Envoi de messages avec garde-fous
 * - Persistance dans le CPT pl_interaction
 */

defined( 'ABSPATH' ) || exit;

class PedagoLens_Student_Twin {

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function init(): void {
        PedagoLens_Twin_Admin::register();
    }

    // -------------------------------------------------------------------------
    // Sessions
    // -------------------------------------------------------------------------

    /**
     * Démarre une nouvelle session et crée le CPT pl_interaction.
     *
     * @return string  UUID de session unique.
     */
    public static function start_session( int $student_id, int $course_id ): string {
        $session_id = wp_generate_uuid4();

        $post_id = wp_insert_post( [
            'post_type'   => 'pl_interaction',
            'post_status' => 'publish',
            'post_title'  => "Session {$session_id}",
            'post_author' => $student_id,
        ] );

        if ( is_wp_error( $post_id ) ) {
            PedagoLens_Core::log( 'error', 'start_session — wp_insert_post échoué', [ 'student_id' => $student_id ] );
            return '';
        }

        update_post_meta( $post_id, '_pl_session_id',   $session_id );
        update_post_meta( $post_id, '_pl_student_id',   $student_id );
        update_post_meta( $post_id, '_pl_course_id',    $course_id );
        update_post_meta( $post_id, '_pl_started_at',   gmdate( 'c' ) );
        update_post_meta( $post_id, '_pl_ended_at',     '' );
        update_post_meta( $post_id, '_pl_messages',     wp_json_encode( [] ) );
        update_post_meta( $post_id, '_pl_guardrails_applied', wp_json_encode( [] ) );

        return $session_id;
    }

    /**
     * Termine une session.
     */
    public static function end_session( string $session_id ): bool {
        $post_id = self::get_post_id_by_session( $session_id );
        if ( ! $post_id ) {
            return false;
        }

        update_post_meta( $post_id, '_pl_ended_at', gmdate( 'c' ) );
        return true;
    }

    /**
     * Retourne les messages d'une session en ordre chronologique.
     */
    public static function get_history( string $session_id ): array {
        $post_id = self::get_post_id_by_session( $session_id );
        if ( ! $post_id ) {
            return self::error( 'pl_session_not_found', "Session introuvable : {$session_id}" );
        }

        $raw      = get_post_meta( $post_id, '_pl_messages', true );
        $messages = is_string( $raw ) ? (array) json_decode( $raw, true ) : [];

        // Tri chronologique par timestamp
        usort( $messages, fn( $a, $b ) => strcmp( $a['sent_at'] ?? '', $b['sent_at'] ?? '' ) );

        return [
            'success'  => true,
            'messages' => $messages,
        ];
    }

    // -------------------------------------------------------------------------
    // Envoi de message
    // -------------------------------------------------------------------------

    /**
     * Envoie un message dans une session active.
     * Applique les garde-fous avant l'appel IA.
     */
    public static function send_message( string $session_id, string $message ): array {
        $post_id = self::get_post_id_by_session( $session_id );
        if ( ! $post_id ) {
            return self::error( 'pl_session_not_found', "Session introuvable : {$session_id}" );
        }

        // Vérifier que la session n'est pas terminée
        $ended_at = get_post_meta( $post_id, '_pl_ended_at', true );
        if ( ! empty( $ended_at ) ) {
            return self::error( 'pl_session_ended', "Session terminée : {$session_id}" );
        }

        $course_id  = (int) get_post_meta( $post_id, '_pl_course_id',  true );
        $student_id = (int) get_post_meta( $post_id, '_pl_student_id', true );

        // Garde-fous
        $guardrail_config = self::get_guardrail_config();
        $guardrail_result = self::apply_guardrails( $message, $guardrail_config );

        if ( $guardrail_result['guardrail_triggered'] ) {
            do_action( 'pedagolens_guardrail_triggered', $session_id, $message, $guardrail_result['reason'] );
            self::log_guardrail( $post_id, $message, $guardrail_result['reason'] );

            $reply = [
                'success'             => true,
                'reply'               => __( 'Je ne peux pas répondre à cette demande. Essaie de reformuler ta question en lien avec le cours.', 'pedagolens-student-twin' ),
                'guardrail_triggered' => true,
                'guardrail_reason'    => $guardrail_result['reason'],
                'follow_up_questions' => [],
            ];

            self::append_message( $post_id, $message, $reply['reply'], true );
            return $reply;
        }

        // Appel IA
        $params = [
            'session_id' => $session_id,
            'student_id' => $student_id,
            'course_id'  => $course_id,
            'message'    => $message,
            'history'    => self::get_recent_history( $post_id, 10 ),
        ];

        $result = PedagoLens_API_Bridge::invoke( 'student_twin_response', $params );

        if ( empty( $result['success'] ) ) {
            return $result;
        }

        self::append_message( $post_id, $message, $result['reply'] ?? '', false );

        return $result;
    }

    // -------------------------------------------------------------------------
    // Garde-fous
    // -------------------------------------------------------------------------

    /**
     * Applique les garde-fous sur un message.
     *
     * @return array{guardrail_triggered: bool, reason: string|null}
     */
    public static function apply_guardrails( string $message, array $config ): array {
        // 1. Longueur maximale
        $max_length = (int) ( $config['max_length'] ?? 2000 );
        if ( mb_strlen( $message ) > $max_length ) {
            return [ 'guardrail_triggered' => true, 'reason' => 'message_too_long' ];
        }

        // 2. Sujets interdits (liste de mots-clés)
        $forbidden = $config['forbidden_topics'] ?? [];
        if ( is_array( $forbidden ) ) {
            $lower = mb_strtolower( $message );
            foreach ( $forbidden as $topic ) {
                if ( str_contains( $lower, mb_strtolower( (string) $topic ) ) ) {
                    return [ 'guardrail_triggered' => true, 'reason' => "forbidden_topic:{$topic}" ];
                }
            }
        }

        // 3. Vérification IA optionnelle (si activée dans la config)
        if ( ! empty( $config['ai_guardrail_enabled'] ) ) {
            $ai_result = PedagoLens_API_Bridge::invoke( 'student_guardrail_check', [
                'message' => $message,
                'config'  => $config,
            ] );

            if ( ! empty( $ai_result['success'] ) && ! empty( $ai_result['guardrail_triggered'] ) ) {
                return [
                    'guardrail_triggered' => true,
                    'reason'              => $ai_result['reason'] ?? 'ai_guardrail',
                ];
            }
        }

        return [ 'guardrail_triggered' => false, 'reason' => null ];
    }

    // -------------------------------------------------------------------------
    // Helpers privés
    // -------------------------------------------------------------------------

    private static function get_post_id_by_session( string $session_id ): int {
        $query = new WP_Query( [
            'post_type'      => 'pl_interaction',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [ [
                'key'   => '_pl_session_id',
                'value' => $session_id,
            ] ],
        ] );

        return $query->have_posts() ? (int) $query->posts[0] : 0;
    }

    private static function append_message(
        int    $post_id,
        string $user_message,
        string $ai_reply,
        bool   $guardrail_triggered
    ): void {
        $raw      = get_post_meta( $post_id, '_pl_messages', true );
        $messages = is_string( $raw ) ? (array) json_decode( $raw, true ) : [];

        $messages[] = [
            'role'                => 'user',
            'content'             => $user_message,
            'sent_at'             => gmdate( 'c' ),
            'guardrail_triggered' => $guardrail_triggered,
        ];
        $messages[] = [
            'role'    => 'assistant',
            'content' => $ai_reply,
            'sent_at' => gmdate( 'c' ),
        ];

        update_post_meta( $post_id, '_pl_messages', wp_json_encode( $messages ) );
    }

    private static function get_recent_history( int $post_id, int $limit ): array {
        $raw      = get_post_meta( $post_id, '_pl_messages', true );
        $messages = is_string( $raw ) ? (array) json_decode( $raw, true ) : [];
        return array_slice( $messages, -$limit );
    }

    private static function log_guardrail( int $post_id, string $message, ?string $reason ): void {
        $raw  = get_post_meta( $post_id, '_pl_guardrails_applied', true );
        $logs = is_string( $raw ) ? (array) json_decode( $raw, true ) : [];

        $logs[] = [
            'reason'     => $reason,
            'message'    => mb_substr( $message, 0, 100 ), // tronquer pour les logs
            'triggered_at' => gmdate( 'c' ),
        ];

        update_post_meta( $post_id, '_pl_guardrails_applied', wp_json_encode( $logs ) );
    }

    private static function get_guardrail_config(): array {
        $raw = get_option( 'pl_guardrails_config', [] );
        if ( is_string( $raw ) ) {
            $raw = json_decode( $raw, true ) ?? [];
        }

        return wp_parse_args( $raw, [
            'max_length'          => 2000,
            'forbidden_topics'    => [],
            'ai_guardrail_enabled' => false,
        ] );
    }

    private static function error( string $code, string $message, array $context = [] ): array {
        PedagoLens_Core::log( 'error', $message, $context );
        return [
            'success'       => false,
            'error_code'    => $code,
            'error_message' => $message,
        ];
    }
}
