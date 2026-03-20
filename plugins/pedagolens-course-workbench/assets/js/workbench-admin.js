/**
 * PédagoLens Course Workbench — Admin JS
 */
( function ( $ ) {
    'use strict';

    const { ajaxUrl, nonce, projectId } = plWorkbench;

    const ajax = ( action, data ) =>
        $.post( ajaxUrl, { action, nonce, project_id: projectId, ...data } );

    // -------------------------------------------------------------------------
    // Suggestions IA
    // -------------------------------------------------------------------------
    $( document ).on( 'click', '.pl-btn-suggestions', function () {
        const $btn      = $( this );
        const sectionId = $btn.data( 'section-id' );
        const $zone     = $( '#pl-suggestions-' + sectionId );

        if ( $zone.is( ':visible' ) ) {
            $zone.slideUp( 200 );
            return;
        }

        $zone.html( '<p class="pl-loading">⏳ Chargement des suggestions…</p>' ).slideDown( 200 );
        $btn.prop( 'disabled', true );

        ajax( 'pl_get_suggestions', { section_id: sectionId } )
            .done( res => {
                if ( res.success ) {
                    $zone.html( res.data.html );
                    if ( res.data.scores_html ) {
                        $( '#pl-sidebar-scores' ).html( res.data.scores_html );
                    }
                } else {
                    $zone.html( '<p class="pl-error">✗ ' + ( res.data?.message || 'Erreur.' ) + '</p>' );
                }
            } )
            .fail( () => $zone.html( '<p class="pl-error">Erreur réseau.</p>' ) )
            .always( () => $btn.prop( 'disabled', false ) );
    } );

    // -------------------------------------------------------------------------
    // Appliquer une suggestion
    // -------------------------------------------------------------------------
    $( document ).on( 'click', '.pl-btn-apply', function () {
        const $btn        = $( this );
        const sectionId   = $btn.data( 'section-id' );
        const suggestionId = $btn.data( 'suggestion-id' );

        $btn.prop( 'disabled', true ).text( 'Application…' );

        ajax( 'pl_apply_suggestion', { section_id: sectionId, suggestion_id: suggestionId } )
            .done( res => {
                if ( res.success ) {
                    // Mettre à jour le textarea de la section
                    $( `.pl-section-content[data-section-id="${sectionId}"]` ).val( res.data.new_content );
                    // Masquer la suggestion appliquée
                    $btn.closest( '.pl-suggestion-card' ).fadeOut( 300 );
                    showStatus( sectionId, '✓ Suggestion appliquée' );
                } else {
                    alert( res.data?.message || 'Erreur.' );
                    $btn.prop( 'disabled', false ).text( '✓ Appliquer' );
                }
            } )
            .fail( () => {
                alert( 'Erreur réseau.' );
                $btn.prop( 'disabled', false ).text( '✓ Appliquer' );
            } );
    } );

    // -------------------------------------------------------------------------
    // Rejeter une suggestion
    // -------------------------------------------------------------------------
    $( document ).on( 'click', '.pl-btn-reject', function () {
        const $btn        = $( this );
        const sectionId   = $btn.data( 'section-id' );
        const suggestionId = $btn.data( 'suggestion-id' );

        ajax( 'pl_reject_suggestion', { section_id: sectionId, suggestion_id: suggestionId } )
            .done( () => $btn.closest( '.pl-suggestion-card' ).fadeOut( 200 ) );
    } );

    // -------------------------------------------------------------------------
    // Sauvegarder une section
    // -------------------------------------------------------------------------
    $( document ).on( 'click', '.pl-btn-save-section', function () {
        const $btn      = $( this );
        const sectionId = $btn.data( 'section-id' );
        const content   = $( `.pl-section-content[data-section-id="${sectionId}"]` ).val();

        $btn.prop( 'disabled', true );

        ajax( 'pl_save_section', { section_id: sectionId, content } )
            .done( res => {
                if ( res.success ) {
                    showStatus( sectionId, '✓ Enregistré' );
                } else {
                    showStatus( sectionId, '✗ Erreur', true );
                }
            } )
            .fail( () => showStatus( sectionId, '✗ Erreur réseau', true ) )
            .always( () => $btn.prop( 'disabled', false ) );
    } );

    // -------------------------------------------------------------------------
    // Historique des versions
    // -------------------------------------------------------------------------
    $( document ).on( 'click', '.pl-btn-history', function () {
        const sectionId = $( this ).data( 'section-id' );
        const $modal    = $( '#pl-versions-modal' );
        const $content  = $( '#pl-versions-content' );

        $content.html( '<p>Chargement…</p>' );
        $modal.show();

        ajax( 'pl_get_versions', { section_id: sectionId } )
            .done( res => {
                $content.html( res.success ? res.data.html : '<p>Erreur.</p>' );
            } );
    } );

    $( document ).on( 'click', '#pl-versions-close', () => $( '#pl-versions-modal' ).hide() );

    // -------------------------------------------------------------------------
    // Ajouter une section
    // -------------------------------------------------------------------------
    $( '#pl-add-section' ).on( 'click', function () {
        const title = prompt( 'Titre de la nouvelle section :' );
        if ( ! title ) return;

        ajax( 'pl_add_section', { title } )
            .done( res => {
                if ( res.success ) {
                    $( '.pl-workbench-main' ).append( res.data.html );
                    $( '.pl-empty-sections' ).hide();
                }
            } );
    } );

    // -------------------------------------------------------------------------
    // Helper : message de statut inline
    // -------------------------------------------------------------------------
    function showStatus( sectionId, msg, isError = false ) {
        const $status = $( `#pl-section-${sectionId} .pl-save-status` );
        $status.text( msg ).css( 'color', isError ? '#d63638' : '#00a32a' );
        setTimeout( () => $status.text( '' ), 3000 );
    }

} )( jQuery );
