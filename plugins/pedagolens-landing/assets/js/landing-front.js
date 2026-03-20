/**
 * PédagoLens Landing — Front-end JS
 *
 * Gère les interactions AJAX pour les pages front-end :
 *   - /dashboard-enseignant  (Teacher Dashboard)
 *   - /dashboard-etudiant    (Student Twin)
 /workbench             (Course Workbench)
 *
 * Localisé via wp_localize_script sous l'objet `plFront` :
 *   plFront.ajaxUrl
 *   plFront.nonces.dashboard | .twin | .workbench
 *   plFront.i18n.*
 */
( function ( $ ) {
    'use strict';

    // =========================================================================
    // RATE LIMITING — Map des requêtes en cours par action
    // =========================================================================

    /** @type {Map<string, boolean>} */
    const pendingequests = new Map();

    /** Délai minimum (ms) entre deux requêtes identiques */
    const MIN_DELAY = 300;

    /** Timestamps de la dernière requête par action */
    const lastRequestTime = new Map();

    /**
     * Vérifie si une requête peut être lancée (pas déjà en cours + délai respecté).
     * @param  {string}  action
     * @return {boolean}
     */
    function canRequest( action ) {
        if ( pendingRequests.get( action ) ) return false;
        const last = lastRequestTime.get( action ) || 0;
        return ( Date.now() - last ) >= MIN_DELAY;
    }

    /**
     * Marque une action comme en cours.
     * @param {string} action
     */
    function lockRequest( action ) {
        pendingRequests.set( action, true );
        lastRequestTime.set( action, Date.now() );
    }

    /**
     * Libère le verrou d'une action.
     * @param {string} action
     */
    function unlockRequest( action ) {
        pendingRequests.set( action, false );
    }

    // =====================================
    // DEBOUNCE
    // =========================================================================

    /** @type {Map<string, number>} */
    const debounceTimers = new Map();

    /**
     * Debounce d'une fonction par clé.
     * @param {string}   key
     * @param {Function} fn
     * @param {number}   [delay=500]
     */
    function debounce( key, fn, delay ) {
        clearTimeout( debounceTimers.get( key ) );
        debounceTimers.set( key, setTimeout( fn, ? delay : 500 ) );
    }

    // =========================================================================
    // AJAX HELPER
    // =========================================================================

    /**
     * Lance une requête AJAX WordPress.
     * @param  {string} action
     * @param  {string} nonce
     * @param  {Object} data
     * @return {jQuery.jqXHR}
     */
    function ajax( action, nonce, data ) {
        return $.post( plFront.ajaxUrl, { action, nonce, ...data } );
    }

    // =========================================================================
    // 1. ANIMATIONS D'ENTRÉE — IntersectionObserver sur .pl-feature-card
    // =========================================================================

    if ( 'IntersectionObserver' in window ) {
        const observer = new IntersectionObserver( function ( entries ) {
            entries.forEach( function ( entry ) {
                if ( entry.isIntersecting ) {
                    $( entry.target ).addClass( 'pl-visible' );
                    observer.unobserve( entry.target );
                }
            } );
        }, { threshold: 0.15 } );

        $( '.pl-feature-card, .pl-animate-in' ).each( function () {
            observer.observe( this );
        } );
    } else {
        $( '.pl-feature-card, .pl-animate-in' ).addClass( 'pl-visible' );
    }

    // =========================================================================
    // 2. TEACHER DASHBOARD — Analyser un cours
    //  
    //    AJAX   : pl_analyze_course  (nonce: dashboard)
    // =========================================================================

    $( document ).on( 'click', '.pl-btn-analyze', function () {
        const $btn     = $( this );
        const courseId = $btn.data( 'course-id' );
        const action   = 'pl_analyze_course';
        const debKey   = action + '_' + courseId;

        debounce( debKey, function () {
            if ( ! canRequest( action ) ) return;

            // Créer le conteneur de résultat s'il n'existe pas
            let $result = $( '.pl-analysis-result-' + courseId );
            if ( ! $result.length ) {
                $result = $( '<div>' ).addClass( 'pl-analysis-result-' + courseId );
                $btn.after( $result );
            }

            lockRequest( action );
            $btn.prop( 'disabled', true );
            $result.html( '<p class="pl-loading">' + spinnerHtml() + plFront.i18n.analyzing + '</p>' );

id: courseId } )
                .done( function ( res ) {
                    if ( res.success ) {
                        $result.html( res.data.html );
                        animateScoreBars( $result );
                        smoothScrollTo( $result );
                    } else {
                        $result.html( errorHtml( res.data?.message || plFront.i18n.analyzeError ) );
                    }
                } )
                .fail( function () {
                    $resulplFront.i18n.analyzeError ) );
                } )
                .always( function () {
                    unlockRequest( action );
                    $btn.prop( 'disabled', false );
                } );
        } );
    } );

    // =========================================================================
    // 3. TEACHER DASHBOARD — Créer un projet
    //    Bouton : .pl-btn-create-project[data-course-id]
    //    AJAX   : pl_create_project  (nonce: dashboard)
    // =========================================================================

    $( document ).on( 'click', '.pl-btn-create-project', function () {
        const $btn        = $( this );
        const courseId    = $btn.data( 'course-id' );
        const courseTitle = $btn.data( 'course-title' ) || '';

        $( '#pl-project-modal-front' ).remove();

        const modal = '<div id="pl-project-modal-front" role="dialog" aria-modal="true" aria-labelledby="pl-modal-title" style="' +
            'position:fixed;top:0;left:0;right:0;bottom:0;' +
            'background:rgba(0,0,0,.55);z-index:99999;' +
            'display:flex;align-items:center;justify-content:center;">' +
            '<div style="background:#fff;padding:28px 32px;border-radius:8px;' +
            'min-width:340px;max-width:480px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.2);">' +
            '<h2 id="pl-modal-title" style="margin-top:0;font-size:1.2rem;">' +
            'Nouveau projet' + ( courseTitle ? ' — ' + escHtml( courseTitle ) : '' ) +
            '</h2>' +
            '<p><label style="font-weight:600;display:block;margin-bottom:4px;">Titre du projet</label>' +
            '<input type="text" id="pl-front-project-title" style="width:100%;padding:8px;box-sizing:border-box;" ' +
            'placeholder="Ex. Analyse du plan de cours"></p>' +
            '<p><label style="font-weight:600;display:block;margin-bottom:4px;">Type</label>' +
            '<select id="pl-front-project-type" style="width:100%;padding:8px;box-sizing:border-box;">' +
            '<option value="magistral">Magistral (diapositives, plan de cours)</option>' +
            '<option value="exercice">Exercice (consigne, TP)</option>' +
            '<option value="evaluation">Évaluation (examen, dissertation)</option>' +
            '<option value="travail_equipe">Travail d\'équipe</option>' +
            '</select></p>' +
            '<p id="pl-front-project-error" style="color:#d63638;display:none;margin:0 0 12px;"></p>' +
            '<div style="display:flex;gap:8px;justify-content:flex-end;">' +
 type="button" id="pl-front-project-cancel" style="padding:8px 16px;cursor:pointer;">Annuler</button>' +
            '<button type="button" id="pl-front-project-create" data-course-id="' + courseId + '" ' +
            'style="padding:8px 16px;background:#2271b1;color:#fff;border:none;border-radius:4px;cursor:pointer;">' +
            plFront.i18n.saving.replace( 'Enregistrement', 'Créer' ).replace( '…', '' ) + 'Créer' +
            '</button></div></div></div>';

        // Reconstruction propre du bouton Créer
        const $modal = $(
            '<div id="pl-project-modal-front" role="dialog" aria-modal="true" aria-labelledby="pl-modal-title">' +
            '</div>'
        ).css( {
            position: 'fixed', top: 0, left: 0, right: 0, bottom: 0,
            background: 'rgba(0,0,0,.55)', zIndex: 99999,
            display: 'flex', alignItems: 'center', justifyContent: 'center',
        } );

        const $inner = $( '<div>' ).css( {
            back
            minWidth: '340px', maxWidth: '480px', width: '90%',
            boxShadow: '0 8px 32px rgba(0,0,0,.2)',
        } );

        $inner.append(
            $( '<h2 id="pl-modal-title">' ).css( { marginTop: 0, fontSize: '1.2rem' } )
                .text( 'Nouveau projet' + ( courseTitle ? ' — ' + courseTitle : '' ) ),
            buildField( 'Titre du projet',
                $( '<input type="text" id="pl-front-project-title">' )
                    .css( { width: '100%', padding: '8px',: 'border-box' } )
                    .attr( 'placeholder', 'Ex. Analyse du plan de cours' )
            ),
            buildField( 'Type',
                $( '<select id="pl-front-project-type">' )
                    .css( { width: '100%', padding: '8px', boxSizing: 'border-box' } )
                    .append(
                        $( '<option value="magistral">' ).text( 'Magistral (diapositives, plan de cours)' ),
                        $( '<option value="exer' ),
                        $( '<option value="evaluation">' ).text( 'Évaluation (examen, dissertation)' ),
                        $( '<option value="travail_equipe">' ).text( "Travail d'équipe" )
                    )
            ),
            $( '<p id="pl-front-project-error">' ).css( { color: '#d63638', display: 'none', margin: '0 0 12px' } ),
            $( '<div>' ).css( { display: 'flex', gap: '8px', justifyContent: 'flex-end' } ).append(
                $( '<button type="button" id="pl-front-project-cancel">' )
                    .css( { padding: '8px 16px', cursor: 'pointer' } ).text( 'Annuler' ),
                $( '<button type="button" id="pl-front-project-create">' )
                    .css( { padding: '8px 16px', background: '#2271b1', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer' } )
                    .attr( 'data-course-id', courseId )
                    .text( 'Créer' )
            )
        );

        $modal.append( $inner );
        $( 'body' ).append(;
        $( '#pl-front-project-title' ).trigger( 'focus' );
    } );

    function buildField( label, $input ) {
        return $( '<p>' ).append(
            $( '<label>' ).css( { fontWeight: 600, display: 'block', marginBottom: '4px' } ).text( label ),
            $input
        );
    }

    $( document ).on( 'click', '#pl-front-project-cancel', function () {
        $( '#pl-project-modal-front' ).remove();
    } );

    $( document ).on( 'keydown', function ( e ) {
        project-modal-front' ).remove();
    } );

    $( document ).on( 'click', '#pl-front-project-create', function () {
        const $btn     = $( this );
        const courseId = $btn.data( 'course-id' );
        const title    = $( '#pl-front-project-title' ).val().trim();
        const type     = $( '#pl-front-project-type' ).val();
        const $error   = $( '#pl-front-project-error' );
        const action   = 'pl_create_project';

        if ( ! title ) {
            $error.text( 'Le titre est requis.' ).show();
            return;
        }

        if ( ! canRequest( action ) ) return;

        lockRequest( action );
        $btn.prop( 'disabled', true ).text( 'Création…' );
        $error.hide();

        ajax( action, plFront.nonces.dashboard, { course_id: courseId, title, type } )
            .done( function ( res ) {
                if ( res.success ) {
                    $( '#pl-project-modal-front' ).remove();
                    window.location.href = res.data.workbench_url;
                } else {
                    $error.text( res.data?.message || 'Erreur.' ).show();
                    $btn.prop( 'disabled', false ).text( 'Créer' );
                }
            } )
            .fail( function () {
                $error.text( 'Erreur réseau.' ).show();
                $btn.prop( 'disabled', false ).text( 'Créer' );
            } )
            .always( function () {
                unlockRequest( action );
            } );
    } );

