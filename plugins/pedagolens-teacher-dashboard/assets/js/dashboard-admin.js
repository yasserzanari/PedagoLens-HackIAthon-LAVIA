/**
 * PédagoLens Teacher Dashboard — Admin JS
 */
( function ( $ ) {
    'use strict';

    // -------------------------------------------------------------------------
    // Analyser un cours
    // -------------------------------------------------------------------------
    $( document ).on( 'click', '.pl-btn-analyze', function () {
        const $btn      = $( this );
        const courseId  = $btn.data( 'course-id' );
        const $result   = $( '#pl-analysis-' + courseId );

        $btn.prop( 'disabled', true ).text( plDashboard.i18n.analyzing );
        $result.html( '<p class="pl-loading">⏳ ' + plDashboard.i18n.analyzing + '</p>' );

        $.post( plDashboard.ajaxUrl, {
            action:    'pl_analyze_course',
            nonce:     plDashboard.nonce,
            course_id: courseId,
        } )
        .done( res => {
            if ( res.success ) {
                $result.html( res.data.html );
            } else {
                $result.html(
                    '<div class="notice notice-error inline"><p>' +
                    ( res.data?.message || plDashboard.i18n.analyzeError ) +
                    '</p></div>'
                );
            }
        } )
        .fail( () => {
            $result.html( '<div class="notice notice-error inline"><p>' + plDashboard.i18n.analyzeError + '</p></div>' );
        } )
        .always( () => {
            $btn.prop( 'disabled', false ).text( 'Analyser' );
        } );
    } );

    // -------------------------------------------------------------------------
    // Nouveau projet — modale inline
    // -------------------------------------------------------------------------
    $( document ).on( 'click', '.pl-btn-new-project', function () {
        const $btn        = $( this );
        const courseId    = $btn.data( 'course-id' );
        const courseTitle = $btn.data( 'course-title' );

        // Supprimer une modale existante
        $( '#pl-project-modal' ).remove();

        const modal = `
            <div id="pl-project-modal" style="
                position:fixed;top:0;left:0;right:0;bottom:0;
                background:rgba(0,0,0,.5);z-index:9999;
                display:flex;align-items:center;justify-content:center;">
                <div style="background:#fff;padding:24px;border-radius:6px;min-width:400px;max-width:500px;">
                    <h2 style="margin-top:0;">Nouveau projet — ${escHtml( courseTitle )}</h2>
                    <p>
                        <label><strong>Titre du projet</strong></label><br>
                        <input type="text" id="pl-project-title" class="regular-text" style="width:100%;" placeholder="Ex. Analyse du plan de cours">
                    </p>
                    <p>
                        <label><strong>Type</strong></label><br>
                        <select id="pl-project-type" style="width:100%;">
                            <option value="magistral">Magistral (diapositives, plan de cours)</option>
                            <option value="exercice">Exercice (consigne, TP)</option>
                            <option value="evaluation">Évaluation (examen, dissertation)</option>
                            <option value="travail_equipe">Travail d'équipe</option>
                        </select>
                    </p>
                    <div style="display:flex;gap:8px;justify-content:flex-end;">
                        <button type="button" id="pl-project-cancel" class="button">Annuler</button>
                        <button type="button" id="pl-project-create" class="button button-primary" data-course-id="${courseId}">Créer</button>
                    </div>
                    <p id="pl-project-error" style="color:#d63638;display:none;"></p>
                </div>
            </div>`;

        $( 'body' ).append( modal );
        $( '#pl-project-title' ).focus();
    } );

    $( document ).on( 'click', '#pl-project-cancel', () => $( '#pl-project-modal' ).remove() );

    $( document ).on( 'click', '#pl-project-create', function () {
        const $btn     = $( this );
        const courseId = $btn.data( 'course-id' );
        const title    = $( '#pl-project-title' ).val().trim();
        const type     = $( '#pl-project-type' ).val();

        if ( ! title ) {
            $( '#pl-project-error' ).text( 'Le titre est requis.' ).show();
            return;
        }

        $btn.prop( 'disabled', true ).text( 'Création…' );

        $.post( plDashboard.ajaxUrl, {
            action:    'pl_create_project',
            nonce:     plDashboard.nonce,
            course_id: courseId,
            type,
            title,
        } )
        .done( res => {
            if ( res.success ) {
                $( '#pl-project-modal' ).remove();
                window.location.href = res.data.workbench_url;
            } else {
                $( '#pl-project-error' ).text( res.data?.message || 'Erreur.' ).show();
                $btn.prop( 'disabled', false ).text( 'Créer' );
            }
        } )
        .fail( () => {
            $( '#pl-project-error' ).text( 'Erreur réseau.' ).show();
            $btn.prop( 'disabled', false ).text( 'Créer' );
        } );
    } );

    // Fermer la modale avec Échap
    $( document ).on( 'keydown', e => {
        if ( e.key === 'Escape' ) $( '#pl-project-modal' ).remove();
    } );

    // Helper XSS
    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

} )( jQuery );
