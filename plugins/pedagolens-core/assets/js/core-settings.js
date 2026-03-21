/**
 * PédagoLens Core — Settings Page JS
 *
 * AJAX handlers for: test bedrock, reset profiles, clear logs,
 * export config (JSON download), import config (file read + AJAX).
 * Password toggle for AWS credential fields.
 * Loading states on buttons during AJAX.
 */
( function ( $ ) {
    'use strict';

    var ajaxUrl = plCoreSettings.ajaxUrl;
    var nonce   = plCoreSettings.nonce;

    // =========================================================================
    // Generic AJAX helper with button loading + feedback span
    // =========================================================================

    function plAjax( action, data, $btn, $feedback, onSuccess ) {
        $btn.addClass( 'pl-is-loading' );
        $feedback
            .removeClass( 'pl-success pl-error pl-loading' )
            .addClass( 'pl-loading' )
            .text( 'Chargement…' )
            .show();

        $.post( ajaxUrl, $.extend( { action: action, nonce: nonce }, data ) )
            .done( function ( res ) {
                $feedback.removeClass( 'pl-loading' );
                if ( res.success ) {
                    $feedback.addClass( 'pl-success' ).text( res.data.message || 'OK' );
                    if ( typeof onSuccess === 'function' ) {
                        onSuccess( res.data );
                    }
                } else {
                    $feedback.addClass( 'pl-error' ).text( res.data && res.data.message ? res.data.message : 'Erreur.' );
                }
            } )
            .fail( function () {
                $feedback.removeClass( 'pl-loading' ).addClass( 'pl-error' ).text( 'Erreur réseau.' );
            } )
            .always( function () {
                $btn.removeClass( 'pl-is-loading' );
            } );
    }

    // =========================================================================
    // Password toggle for AWS credential fields
    // =========================================================================

    $( document ).on( 'click', '.pl-toggle-password', function () {
        var $btn    = $( this );
        var targetId = $btn.data( 'target' );
        var $input  = $( '#' + targetId );

        if ( ! $input.length ) {
            return;
        }

        if ( $input.attr( 'type' ) === 'password' ) {
            $input.attr( 'type', 'text' );
            $btn.find( '.dashicons' )
                .removeClass( 'dashicons-visibility' )
                .addClass( 'dashicons-hidden' );
        } else {
            $input.attr( 'type', 'password' );
            $btn.find( '.dashicons' )
                .removeClass( 'dashicons-hidden' )
                .addClass( 'dashicons-visibility' );
        }
    } );

    // =========================================================================
    // Test Bedrock connection
    // =========================================================================

    $( '#pl-test-bedrock-btn' ).on( 'click', function () {
        plAjax( 'pl_test_bedrock', {}, $( this ), $( '#pl-test-bedrock-result' ) );
    } );

    // =========================================================================
    // Reset profiles to defaults
    // =========================================================================

    $( '#pl-reset-profiles-btn' ).on( 'click', function () {
        if ( ! confirm( 'Réinitialiser tous les profils aux valeurs par défaut ? Les profils personnalisés seront supprimés.' ) ) {
            return;
        }
        plAjax( 'pl_reset_profiles', {}, $( this ), $( '#pl-reset-profiles-result' ), function () {
            // Reload after short delay so user sees the success message
            setTimeout( function () {
                window.location.reload();
            }, 1200 );
        } );
    } );
