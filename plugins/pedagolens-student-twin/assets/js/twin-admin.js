/**
 * PédagoLens Student Twin — JS
 * Front-end student page (shortcode) + admin demo page.
 * Auto-session: selecting a course auto-starts or resumes a session.
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
    // FRONT-END STUDENT PAGE
    // =========================================================================

    var $page = $( '.pl-twin-page' ).first();

    if ( $page.length && $page.find( '#pl-twin-messages' ).length ) {

        var $messages      = $( '#pl-twin-messages' );
        var $input         = $( '#pl-twin-input' );
        var $sendBtn       = $( '#pl-twin-send' );
        var $followUps     = $( '#pl-twin-follow-ups' );
        var $endSession    = $( '#pl-twin-end-session' );
        var $courseSelect   = $( '#pl-twin-course-select' );
        var $welcome       = $( '#pl-twin-welcome' );
        var $chatWrap      = $( '#pl-twin-chat-wrap' );
        var $courseBadge   = $( '#pl-twin-course-badge' );
        var twinName       = plTwin.twinName || 'Léa';
        var introMsg       = plTwin.introMessage || 'Bonjour !';
        var i18n           = plTwin.i18n || {};
        var sessionsData   = plTwin.sessionsData || [];

        // -----------------------------------------------------------------
        // Auto-session on course change
        // -----------------------------------------------------------------
        $courseSelect.on( 'change', function() {
            var courseId = parseInt( $courseSelect.val(), 10 );
            if ( ! courseId ) {
                // Back to welcome
                $chatWrap.fadeOut( 200, function() { $welcome.fadeIn( 300 ); } );
                if ( currentSessionId ) {
                    endCurrentSession();
                }
                return;
            }
            autoStartOrResume( courseId );
        } );

        function autoStartOrResume( courseId ) {
            // Check if there's an active (non-ended) session for this course
            var existing = null;
            for ( var i = 0; i < sessionsData.length; i++ ) {
                if ( sessionsData[i].course_id === courseId && ! sessionsData[i].ended ) {
                    existing = sessionsData[i];
                    break;
                }
            }

            // End current session if switching courses
            if ( currentSessionId ) {
                endCurrentSession( function() {
                    if ( existing ) {
                        resumeSession( existing.session_id, courseId );
                    } else {
                        startNewSession( courseId );
                    }
                } );
            } else {
                if ( existing ) {
                    resumeSession( existing.session_id, courseId );
                } else {
                    startNewSession( courseId );
                }
            }
        }

        function showChat( courseId ) {
            // Update course badge
            var courseName = $courseSelect.find( 'option:selected' ).text();
            $courseBadge.text( courseName );

            $welcome.hide();
            $chatWrap.fadeIn( 300 );
        }

        function startNewSession( courseId ) {
            showChat( courseId );
            $messages.empty();
            $followUps.empty();
            enableChat( false );

            addBubble( 'system', i18n.starting || 'Démarrage de la session…' );

            ajax( 'pl_twin_start_session', { course_id: courseId } )
                .done( function( res ) {
                    $messages.empty();
                    if ( res.success ) {
                        currentSessionId = res.data.session_id;
                        addBubble( 'welcome',
                            '<span class="pl-twin-welcome-name">' + escHtml( twinName ) + '</span> — ' + escHtml( introMsg ), true );
                        enableChat( true );
                        $input.trigger( 'focus' );
                    } else {
                        addBubble( 'system', ( res.data && res.data.message ) || 'Erreur.' );
                    }
                } )
                .fail( function() {
                    $messages.empty();
                    addBubble( 'system', i18n.networkError || 'Erreur réseau.' );
                } );
        }

        function resumeSession( sessionId, courseId ) {
            showChat( courseId );
            $messages.empty();
            $followUps.empty();
            enableChat( false );

            currentSessionId = sessionId;
            addBubble( 'system', i18n.resuming || 'Reprise de ta session précédente…' );

            var $typing = showTypingIndicator();
            ajax( 'pl_twin_get_history', { session_id: sessionId } )
                .done( function( res ) {
                    $typing.remove();
                    $messages.find( '.pl-twin-bubble--system' ).first().remove();
                    if ( res.success && res.data.messages && res.data.messages.length ) {
                        res.data.messages.forEach( function( m ) {
                            if ( m.role === 'user' ) addBubble( 'user', escHtml( m.content ) );
                            else if ( m.role === 'assistant' ) addBubble( 'ai', escHtml( m.content ) );
                            if ( m.guardrail_triggered ) addBubble( 'guardrail', i18n.guardrailLabel || 'Garde-fou déclenché' );
                        } );
                    } else {
                        addBubble( 'welcome',
                            '<span class="pl-twin-welcome-name">' + escHtml( twinName ) + '</span> — ' + escHtml( introMsg ), true );
                    }
                    enableChat( true );
                    $input.trigger( 'focus' );
                } )
                .fail( function() {
                    $typing.remove();
                    addBubble( 'system', i18n.networkError || 'Erreur réseau.' );
                } );
        }

        // -----------------------------------------------------------------
        // End session
        // -----------------------------------------------------------------
        $endSession.on( 'click', function() {
            endCurrentSession( function() {
                $courseSelect.val( '0' );
                $chatWrap.fadeOut( 200, function() { $welcome.fadeIn( 300 ); } );
            } );
        } );

        function endCurrentSession( callback ) {
            if ( ! currentSessionId ) { if ( callback ) callback(); return; }
            ajax( 'pl_twin_end_session', { session_id: currentSessionId } )
                .done( function() {
                    addBubble( 'system', '✓ ' + ( i18n.sessionEnded || 'Session terminée.' ) );
                    currentSessionId = null;
                    enableChat( false );
                    $followUps.empty();
                } )
                .always( function() { if ( callback ) callback(); } );
        }

        // -----------------------------------------------------------------
        // Add bubble to chat
        // -----------------------------------------------------------------
        function addBubble( type, content, isHtml ) {
            var cls = 'pl-twin-bubble pl-twin-bubble--' + type;
            var $bubble = $( '<div>' ).addClass( cls );
            if ( isHtml ) {
                $bubble.html( content );
            } else {
                $bubble.text( content );
            }
            $messages.append( $bubble );
            $messages.scrollTop( $messages[0].scrollHeight );
        }

        // -----------------------------------------------------------------
        // Enable / disable chat input
        // -----------------------------------------------------------------
        function enableChat( enabled ) {
            $input.prop( 'disabled', ! enabled );
            $sendBtn.prop( 'disabled', ! enabled );
        }

        // -----------------------------------------------------------------
        // Typing indicator
        // -----------------------------------------------------------------
        function showTypingIndicator() {
            var $typing = $( '<div class="pl-twin-typing">' +
                '<span class="pl-twin-typing-label">' + escHtml( twinName ) + ' ' + escHtml( i18n.typing || "est en train d\u0027écrire\u2026" ) + '</span>' +
                '<span class="pl-twin-typing-dots"><span></span><span></span><span></span></span>' +
                '</div>' );
            $messages.append( $typing );
            $messages.scrollTop( $messages[0].scrollHeight );
            return $typing;
        }

        // -----------------------------------------------------------------
        // Send message
        // -----------------------------------------------------------------
        function sendMessage() {
            var text = $.trim( $input.val() );
            if ( ! text || ! currentSessionId ) return;

            addBubble( 'user', text );
            $input.val( '' );
            $followUps.empty();
            enableChat( false );

            var $typing = showTypingIndicator();

            ajax( 'pl_twin_send_message', { session_id: currentSessionId, message: text } )
                .done( function( res ) {
                    $typing.remove();
                    if ( res.success ) {
                        var data = res.data;
                        if ( data.guardrail_triggered ) {
                            addBubble( 'guardrail', data.guardrail_reason || ( i18n.guardrailLabel || 'Garde-fou déclenché' ) );
                        }
                        if ( data.reply ) {
                            addBubble( 'ai', data.reply );
                        }
                        if ( data.follow_up_questions && data.follow_up_questions.length ) {
                            renderFollowUps( data.follow_up_questions );
                        }
                    } else {
                        addBubble( 'system', ( res.data && res.data.message ) || 'Erreur.' );
                    }
                    enableChat( true );
                    $input.trigger( 'focus' );
                } )
                .fail( function() {
                    $typing.remove();
                    addBubble( 'system', i18n.networkError || 'Erreur réseau.' );
                    enableChat( true );
                } );
        }

        $sendBtn.on( 'click', sendMessage );

        $input.on( 'keydown', function( e ) {
            if ( e.key === 'Enter' && ! e.shiftKey ) {
                e.preventDefault();
                sendMessage();
            }
        } );

        // -----------------------------------------------------------------
        // Follow-up questions
        // -----------------------------------------------------------------
        function renderFollowUps( questions ) {
            $followUps.empty();
            questions.forEach( function( q ) {
                var $btn = $( '<button type="button" class="pl-twin-follow-btn">' ).text( q );
                $btn.on( 'click', function() {
                    $input.val( q );
                    sendMessage();
                } );
                $followUps.append( $btn );
            } );
        }

        // -----------------------------------------------------------------
        // Auto-start if courseId is pre-set
        // -----------------------------------------------------------------
        if ( plTwin.courseId ) {
            $courseSelect.val( plTwin.courseId ).trigger( 'change' );
        }
    }

    // =========================================================================
    // ADMIN DEMO PAGE
    // =========================================================================

    var $startBtn  = $( '#pl-twin-start' );
    var $endBtn    = $( '#pl-twin-end' );
    var $demoChat  = $( '#pl-twin-chat' );
    var $demoCourse = $( '#pl-demo-course' );
    var $chatMsgs  = $( '#pl-chat-messages' );
    var $chatInput = $( '#pl-chat-input' );
    var $chatSend  = $( '#pl-chat-send' );
    var $chatFollowUps = $( '#pl-chat-follow-ups' );

    if ( $startBtn.length ) {

        var demoSessionId = null;

        function demoAddBubble( cls, text ) {
            var $b = $( '<div class="pl-chat-bubble ' + cls + '">' ).text( text );
            $chatMsgs.append( $b );
            $chatMsgs.scrollTop( $chatMsgs[0].scrollHeight );
        }

        function demoShowTyping() {
            var $t = $( '<div class="pl-chat-bubble pl-bubble-typing"><div class="pl-typing-dots"><span></span><span></span><span></span></div></div>' );
            $chatMsgs.append( $t );
            $chatMsgs.scrollTop( $chatMsgs[0].scrollHeight );
            return $t;
        }

        function demoEnableInput( enabled ) {
            $chatInput.prop( 'disabled', ! enabled );
            $chatSend.prop( 'disabled', ! enabled );
        }

        function demoSendMessage() {
            var text = $.trim( $chatInput.val() );
            if ( ! text || ! demoSessionId ) return;

            demoAddBubble( 'pl-bubble-user', text );
            $chatInput.val( '' );
            $chatFollowUps.empty();
            demoEnableInput( false );

            var $typing = demoShowTyping();

            ajax( 'pl_twin_send_message', { session_id: demoSessionId, message: text } )
                .done( function( res ) {
                    $typing.remove();
                    if ( res.success ) {
                        var data = res.data;
                        if ( data.guardrail_triggered ) {
                            demoAddBubble( 'pl-bubble-system', '\u26A0\uFE0F ' + ( data.guardrail_reason || 'Garde-fou déclenché' ) );
                        }
                        if ( data.reply ) {
                            demoAddBubble( 'pl-bubble-assistant', data.reply );
                        }
                        if ( data.follow_up_questions && data.follow_up_questions.length ) {
                            data.follow_up_questions.forEach( function( q ) {
                                var $btn = $( '<button type="button" class="button button-small pl-follow-up-btn">' ).text( q );
                                $btn.on( 'click', function() {
                                    $chatInput.val( q );
                                    demoSendMessage();
                                } );
                                $chatFollowUps.append( $btn );
                            } );
                        }
                    } else {
                        demoAddBubble( 'pl-bubble-system', ( res.data && res.data.message ) || 'Erreur.' );
                    }
                    demoEnableInput( true );
                    $chatInput.trigger( 'focus' );
                } )
                .fail( function() {
                    $typing.remove();
                    demoAddBubble( 'pl-bubble-system', 'Erreur réseau.' );
                    demoEnableInput( true );
                } );
        }

        // Start session
        $startBtn.on( 'click', function() {
            var courseId = parseInt( $demoCourse.val(), 10 );
            if ( ! courseId ) {
                alert( 'Sélectionnez un cours.' );
                return;
            }

            $startBtn.prop( 'disabled', true ).text( 'Démarrage\u2026' );

            ajax( 'pl_twin_start_session', { course_id: courseId } )
                .done( function( res ) {
                    if ( res.success ) {
                        demoSessionId = res.data.session_id;
                        $demoChat.slideDown( 300 );
                        demoEnableInput( true );
                        $chatInput.trigger( 'focus' );
                    } else {
                        alert( ( res.data && res.data.message ) || 'Erreur.' );
                    }
                } )
                .fail( function() { alert( 'Erreur réseau.' ); } )
                .always( function() {
                    $startBtn.prop( 'disabled', false ).text( 'Démarrer' );
                } );
        } );

        // End session
        $endBtn.on( 'click', function() {
            if ( ! demoSessionId ) return;
            ajax( 'pl_twin_end_session', { session_id: demoSessionId } )
                .done( function() {
                    demoAddBubble( 'pl-bubble-system', '\u2713 Session terminée.' );
                    demoSessionId = null;
                    demoEnableInput( false );
                } );
        } );

        // Send button + Enter
        $chatSend.on( 'click', demoSendMessage );
        $chatInput.on( 'keydown', function( e ) {
            if ( e.key === 'Enter' && ! e.shiftKey ) {
                e.preventDefault();
                demoSendMessage();
            }
        } );
    }

} )( jQuery );
