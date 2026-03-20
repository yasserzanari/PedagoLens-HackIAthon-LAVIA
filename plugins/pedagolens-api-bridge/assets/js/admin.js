/**
 * PédagoLens API Bridge — Admin JS
 * Gère : toggle afficher/masquer les secrets, test de connexion Bedrock.
 */
( function ( $ ) {
    'use strict';

    // -------------------------------------------------------------------------
    // Toggle Afficher / Masquer les champs password
    // -------------------------------------------------------------------------
    $( document ).on( 'click', '.pl-toggle-secret', function () {
        const targetId = $( this ).data( 'target' );
        const $input   = $( '#' + targetId );

        if ( $input.attr( 'type' ) === 'password' ) {
            $input.attr( 'type', 'text' );
            $( this ).text( plBridgeAdmin.i18n.hide || 'Masquer' );
        } else {
            $input.attr( 'type', 'password' );
            $( this ).text( plBridgeAdmin.i18n.show || 'Afficher' );
        }
    } );

    // -------------------------------------------------------------------------
    // Test de connexion Bedrock
    // -------------------------------------------------------------------------
    $( '#pl-test-connection' ).on( 'click', function () {
        const $btn    = $( this );
        const $result = $( '#pl-test-result' );

        $btn.prop( 'disabled', true );
        $result.text( plBridgeAdmin.i18n.testing ).css( 'color', '#666' );

        $.post( plBridgeAdmin.ajaxUrl, {
            action: 'pl_test_bedrock_connection',
            nonce:  plBridgeAdmin.nonce,
        } )
        .done( function ( response ) {
            if ( response.success ) {
                $result.text( '✓ ' + response.data.message ).css( 'color', '#00a32a' );
            } else {
                $result.text( '✗ ' + response.data.message ).css( 'color', '#d63638' );
            }
        } )
        .fail( function () {
            $result.text( plBridgeAdmin.i18n.error ).css( 'color', '#d63638' );
        } )
        .always( function () {
            $btn.prop( 'disabled', false );
        } );
    } );

} )( jQuery );
