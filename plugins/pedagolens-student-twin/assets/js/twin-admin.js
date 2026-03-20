/**
 * PédagoLens Student Twin — Admin & Front JS
 */
( function ( $ ) {
    'use strict';

    const { ajaxUrl, nonce } = plTwin;
    let currentSessionId = null;

    const ajax = ( action, data ) =>
        $.post( ajaxUrl, { action, nonce, ...data } );

    // -------------------------------------------------------------------------
    // Admin demo page
    // -------------------------------------------------------------------------

    // Démarrer une session (bouton admin)
    $( '#pl-twin-start' ).on( 'click', function () {
        const courseId = parseInt( $( '#pl-demo-course' ).val(), 10 );

        ajax( 'pl_twin_start_session', { course_id: courseId } )
            .done( res => {
                if ( res.success ) {
                    currentSessionId = res.data.session_id;
                    $( '#pl-twin-chat' ).show();
                    $( '#pl-twin-start' ).prop( 'disabled', true );
                } else {
                    alert( res.data?.message || 'Erreur.' );
                }
            } );
    } );

    // Terminer la session
    $( '#pl-twin-end' ).on( 'click', function () {
        if ( ! currentSessionId ) return;

        ajax( 'pl_twin_end_session', { session_id: currentSessionId } )
            .done( () => {
                appendBubble( 'assistant', '✓ Session terminée. À bientôt !' );
                $( '#pl-chat-input, #pl-chat-send' ).prop( 'disabled', true );
                $( '#pl-twin-end' ).prop( 'disabled', true );
                currentSessionId = null;
            } );
    } );

    // Envoyer un message (bouton admin)
    $( '#pl-chat-send' ).on( 'click', sendMessage );
    $( '#pl-chat-input' ).on( 'keydown', function ( e ) {
        if ( e.key === 'Enter' && ! e.shiftKey ) {
            e.preventDefault();
            sendMessage();
        }
    } );

    // -------------------------------------------------------------------------
    // Widget shortcode (front-end)
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.pl-twin-start-btn', function () {
        const $widget   = $( this ).closest( '.pl-twin-widget' );
        const courseId  = parseInt( $widget.data( 'course-id' ), 10 );

        ajax( 'pl_twin_start_session', { course_id: courseId } )
            .done( res => {
                if ( res.success ) {
                    $widget.data( 'session-id', res.data.session_id );
                    $widget.find( '.pl-chat-messages, .pl-chat-input-row' ).show();
                    $( this ).hide();
                }
            } );
    } );

    $( document ).on( 'click', '.pl-chat-send', function () {
        const $widget    = $( this ).closest( '.pl-twin-widget' );
        const sessionId  = $widget.data( 'session-id' );
        const $input     = $widget.find( '.pl-chat-input' );
        const message    = $input.val().trim();

        if ( ! message || ! sessionId ) return;

        $input.val( '' );
        appendBubbleIn( $widget, 'user', message );
        $( this ).prop( 'disabled', true );

        const $typing = showTyping( $widget.find( '.pl-chat-messages' ) );

        ajax( 'pl_twin_send_message', { session_id: sessionId, message } )
            .done( res => {
                $typing.remove();
                if ( res.success ) {
                    appendBubbleIn( $widget, 'assistant', res.data.reply );
                    renderFollowUps( $widget, res.data.follow_up_questions ?? [] );
                } else {
                    appendBubbleIn( $widget, 'assistant', '✗ ' + ( res.data?.message || 'Erreur.' ) );
                }
            } )
            .fail( () => {
                $typing.remove();
                appendBubbleIn( $widget, 'assistant', '✗ Erreur réseau.' );
            } )
            .always( () => $( this ).prop( 'disabled', false ) );
    } );

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function sendMessage() {
        if ( ! currentSessionId ) return;

        const $input  = $( '#pl-chat-input' );
        const message = $input.val().trim();
        if ( ! message ) return;

        $input.val( '' );
        appendBubble( 'user', message );
        $( '#pl-chat-send' ).prop( 'disabled', true );

        const $typing = showTyping( $( '#pl-chat-messages' ) );

        ajax( 'pl_twin_send_message', { session_id: currentSessionId, message } )
            .done( res => {
                $typing.remove();
                if ( res.success ) {
                    appendBubble( 'assistant', res.data.reply );
                    renderAdminFollowUps( res.data.follow_up_questions ?? [] );
                    if ( res.data.guardrail_triggered ) {
                        appendBubble( 'system', '⚠ Garde-fou déclenché : ' + ( res.data.guardrail_reason || '' ) );
                    }
                } else {
                    appendBubble( 'assistant', '✗ ' + ( res.data?.message || 'Erreur.' ) );
                }
            } )
            .fail( () => {
                $typing.remove();
                appendBubble( 'assistant', '✗ Erreur réseau.' );
            } )
            .always( () => $( '#pl-chat-send' ).prop( 'disabled', false ) );
    }

    function appendBubble( role, text ) {
        const cls = role === 'user' ? 'pl-bubble-user' : ( role === 'system' ? 'pl-bubble-system' : 'pl-bubble-assistant' );
        const $msg = $( '<div>' ).addClass( 'pl-chat-bubble ' + cls ).text( text );
        $( '#pl-chat-messages' ).append( $msg ).scrollTop( 99999 );
    }

    function appendBubbleIn( $widget, role, text ) {
        const cls = role === 'user' ? 'pl-bubble-user' : 'pl-bubble-assistant';
        const $msg = $( '<div>' ).addClass( 'pl-chat-bubble ' + cls ).text( text );
        $widget.find( '.pl-chat-messages' ).append( $msg ).scrollTop( 99999 );
    }

    function showTyping( $container ) {
        const $typing = $( '<div class="pl-chat-bubble pl-bubble-typing"><div class="pl-typing-dots"><span></span><span></span><span></span></div></div>' );
        $container.append( $typing ).scrollTop( 99999 );
        return $typing;
    }

    function renderAdminFollowUps( questions ) {
        const $zone = $( '#pl-chat-follow-ups' ).empty();
        if ( ! questions.length ) return;

        questions.forEach( q => {
            $( '<button>' )
                .addClass( 'button button-small pl-follow-up-btn' )
                .text( q )
                .on( 'click', function () {
                    $( '#pl-chat-input' ).val( q );
                    sendMessage();
                } )
                .appendTo( $zone );
        } );
    }

    function renderFollowUps( $widget, questions ) {
        const $zone = $widget.find( '.pl-chat-follow-ups' );
        if ( ! $zone.length || ! questions.length ) return;
        $zone.empty();
        questions.forEach( q => {
            $( '<button>' )
                .addClass( 'button button-small pl-follow-up-btn' )
                .text( q )
                .on( 'click', function () {
                    $widget.find( '.pl-chat-input' ).val( q );
                    $widget.find( '.pl-chat-send' ).trigger( 'click' );
                } )
                .appendTo( $zone );
        } );
    }

} )( jQuery );
