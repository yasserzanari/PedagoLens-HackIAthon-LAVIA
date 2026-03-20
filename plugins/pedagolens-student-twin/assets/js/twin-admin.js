/**
 * PédagoLens Student Twin — JS
 * Front-end student dashboard (shortcode) + admin demo page.
 */
( function( $ ) {
    'use strict';

    var ajaxUrl = plTwin.ajaxUrl;
    var plNonce = plTwin.nonce;
    var currentSessionId = null;

    function ajax( action, data ) {
        data = data || {};
        data.action = action;
        data.nonce  = plNonce;
        return $.post( ajaxUrl, data );
    }

    function escHtml( str ) {
        if ( ! str ) return '';
        var div = document.createElement( 'div' );
        div.appendChild( document.createTextNode( str ) );
        return div.innerHTML;
    }

    // =========================================================================
    // FRONT-END STUDENT DASHBOARD
    // =========================================================================

    var $dashboard = $( '.pl-twin-dashboard' ).not( '.pl-twin-logged-out' );

    if ( $dashboard.length ) {
        var $messages     = $( '#pl-twin-messages' );
        var $input        = $( '#pl-twin-input' );
        var $sendBtn      = $( '#pl-twin-send' );
        var $followUps    = $( '#pl-twin-follow-ups' );
        var $newSession   = $( '#pl-twin-new-session' );
        var $endSession   = $( '#pl-twin-end-session' );
        var $courseSelect = $( '#pl-twin-course-select' );
        var twinName      = plTwin.twinName || 'Léa';
        var introMsg      = plTwin.introMessage || 'Bonjour !';
        var i18n          = plTwin.i18n || {};

        $newSession.on( 'click', function() {
            var courseId = parseInt( $courseSelect.val(), 10 );
            if ( ! courseId ) { shakeElement( $courseSelect ); return; }
            if ( currentSessionId ) {
                endCurrentSession( function() { startNewSession( courseId ); } );
            } else { startNewSession( courseId ); }
        } );

        function startNewSession( courseId ) {
            $newSession.prop( 'disabled', true );
            ajax( 'pl_twin_start_session', { course_id: courseId } )
                .done( function( res ) {
                    if ( res.success ) {
                        currentSessionId = res.data.session_id;
                        $messages.empty(); $followUps.empty();
                        addBubble( 'welcome',
                            '<span class="pl-twin-welcome-name">' + escHtml( twinName ) + '</span> — ' + escHtml( introMsg ), true );
                        enableChat( true ); $endSession.prop( 'disabled', false ); $input.trigger( 'focus' );
                    } else { addBubble( 'system', ( res.data && res.data.message ) || 'Erreur.' ); }
                } )
                .fail( function() { addBubble( 'system', i18n.networkError || 'Erreur réseau.' ); } )
                .always( function() { $newSession.prop( 'disabled', false ); } );
        }

        $endSession.on( 'click', function() { endCurrentSession(); } );

        function endCurrentSession( callback ) {
            if ( ! currentSessionId ) { if ( callback ) callback(); return; }
            $endSession.prop( 'disabled', true );
            ajax( 'pl_twin_end_session', { session_id: currentSessionId } )
                .done( function() {
                    addBubble( 'system', '✓ ' + ( i18n.sessionEnded || 'Session terminée.' ) );
                    currentSessionId = null; enableChat( false ); $followUps.empty();
                } )
                .always( function() { if ( callback ) callback(); } );
        }

        $sendBtn.on( 'click', sendDashboardMessage );
        $input.on( 'keydown', function( e ) {
            if ( e.key === 'Enter' && ! e.shiftKey ) { e.preventDefault(); sendDashboardMessage(); }
        } );
        $input.on( 'input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min( this.scrollHeight, 120 ) + 'px';
        } );

        function sendDashboardMessage() {
            if ( ! currentSessionId ) return;
            var message = $input.val().trim();
            if ( ! message ) return;
            $input.val( '' ).css( 'height', 'auto' );
            addBubble( 'user', escHtml( message ) );
            $followUps.empty(); enableChat( false );
            var $typing = showTypingIndicator();
            ajax( 'pl_twin_send_message', { session_id: currentSessionId, message: message } )
                .done( function( res ) {
                    $typing.remove();
                    if ( res.success ) {
                        addBubble( 'ai', escHtml( res.data.reply ) );
                        if ( res.data.guardrail_triggered ) {
                            addBubble( 'guardrail',
                                ( i18n.guardrailLabel || 'Garde-fou déclenché' ) +
                                ( res.data.guardrail_reason ? ' : ' + escHtml( res.data.guardrail_reason ) : '' ) );
                        }
                        renderFollowUps( res.data.follow_up_questions || [] );
                    } else { addBubble( 'ai', '✗ ' + ( ( res.data && res.data.message ) || 'Erreur.' ) ); }
                } )
                .fail( function() { $typing.remove(); addBubble( 'ai', '✗ ' + ( i18n.networkError || 'Erreur réseau.' ) ); } )
                .always( function() { enableChat( true ); $input.trigger( 'focus' ); } );
        }

        $followUps.on( 'click', '.pl-twin-follow-btn', function() {
            $input.val( $( this ).text() ); sendDashboardMessage();
        } );

        $( '#pl-twin-history-list' ).on( 'click', '.pl-twin-history-item', function() {
            var sessionId = $( this ).data( 'session-id' );
            if ( ! sessionId ) return;
            $( '.pl-twin-history-item' ).removeClass( 'active' );
            $( this ).addClass( 'active' );
            $messages.empty(); $followUps.empty(); enableChat( false );
            var $typing = showTypingIndicator();
            ajax( 'pl_twin_get_history', { session_id: sessionId } )
                .done( function( res ) {
                    $typing.remove();
                    if ( res.success && res.data.messages ) {
                        res.data.messages.forEach( function( m ) {
                            if ( m.role === 'user' ) addBubble( 'user', escHtml( m.content ) );
                            else if ( m.role === 'assistant' ) addBubble( 'ai', escHtml( m.content ) );
                            if ( m.guardrail_triggered ) addBubble( 'guardrail', i18n.guardrailLabel || 'Garde-fou déclenché' );
                        } );
                    } else { addBubble( 'system', ( res.data && res.data.message ) || 'Historique introuvable.' ); }
                } )
                .fail( function() { $typing.remove(); addBubble( 'system', i18n.networkError || 'Erreur réseau.' ); } );
        } );
