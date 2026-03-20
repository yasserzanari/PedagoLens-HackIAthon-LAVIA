/**
 * PédagoLens Core — Admin Profiles JS
 * Gère : drag-and-drop réordonnancement, toggle actif, suppression, duplication,
 *        export/import JSON, prévisualisation du prompt.
 */
( function ( $ ) {
    'use strict';

    const ajax = ( action, data, onSuccess, onError ) => {
        $.post( plCoreAdmin.ajaxUrl, { action, nonce: plCoreAdmin.nonce, ...data } )
            .done( res => res.success ? onSuccess( res.data ) : ( onError || defaultError )( res.data ) )
            .fail( () => alert( 'Erreur réseau.' ) );
    };

    const defaultError = data => alert( data?.message || 'Erreur.' );

    // -------------------------------------------------------------------------
    // Drag-and-drop réordonnancement
    // -------------------------------------------------------------------------
    $( '#pl-profiles-sortable' ).sortable( {
        handle: '.pl-drag-handle',
        update() {
            const slugs = $( '#pl-profiles-sortable tr' ).map( ( _, el ) => $( el ).data( 'slug' ) ).get();
            ajax( 'pl_reorder_profiles', { slugs }, () => {} );
        },
    } );

    // -------------------------------------------------------------------------
    // Toggle actif / inactif
    // -------------------------------------------------------------------------
    $( document ).on( 'click', '.pl-toggle-profile', function () {
        const $btn = $( this );
        const slug = $btn.data( 'slug' );

        ajax( 'pl_toggle_profile', { slug }, data => {
            const isActive = data.is_active;
            $btn.data( 'active', isActive ? '1' : '0' )
                .text( isActive ? 'Désactiver' : 'Activer' );

            const $badge = $btn.closest( 'tr' ).find( '.pl-status-badge' );
            $badge.removeClass( 'pl-status-active pl-status-inactive' )
                  .addClass( isActive ? 'pl-status-active' : 'pl-status-inactive' )
                  .text( isActive ? 'Actif' : 'Inactif' );
        } );
    } );

    // -------------------------------------------------------------------------
    // Suppression
    // -------------------------------------------------------------------------
    $( document ).on( 'click', '.pl-delete-profile', function () {
        if ( ! confirm( plCoreAdmin.confirmDelete ) ) return;

        const $btn = $( this );
        const slug = $btn.data( 'slug' );

        ajax( 'pl_delete_profile', { slug },
            () => $btn.closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } ),
            data => alert( data?.message || 'Suppression impossible.' )
        );
    } );

    // -------------------------------------------------------------------------
    // Duplication
    // -------------------------------------------------------------------------
    $( document ).on( 'click', '.pl-duplicate-profile', function () {
        const slug = $( this ).data( 'slug' );

        ajax( 'pl_duplicate_profile', { slug }, data => {
            window.location.href = plCoreAdmin.editUrl + encodeURIComponent( data.new_slug );
        } );
    } );

    // -------------------------------------------------------------------------
    // Export JSON
    // -------------------------------------------------------------------------
    $( '#pl-export-profiles' ).on( 'click', function () {
        ajax( 'pl_export_profiles', {}, data => {
            const blob = new Blob( [ JSON.stringify( data.profiles, null, 2 ) ], { type: 'application/json' } );
            const url  = URL.createObjectURL( blob );
            const a    = document.createElement( 'a' );
            a.href     = url;
            a.download = 'pedagolens-profiles.json';
            a.click();
            URL.revokeObjectURL( url );
        } );
    } );

    // -------------------------------------------------------------------------
    // Import JSON
    // -------------------------------------------------------------------------
    $( '#pl-import-profile' ).on( 'change', function () {
        const file = this.files[0];
        if ( ! file ) return;

        const reader = new FileReader();
        reader.onload = e => {
            ajax( 'pl_import_profile', { json: e.target.result },
                () => window.location.reload(),
                data => {
                    if ( data?.conflict ) {
                        if ( confirm( `Le profil "${data.slug}" existe déjà. Remplacer ?` ) ) {
                            ajax( 'pl_import_profile', { json: e.target.result, overwrite: 1 },
                                () => window.location.reload(),
                                defaultError
                            );
                        }
                    } else {
                        defaultError( data );
                    }
                }
            );
        };
        reader.readAsText( file );
    } );

    // -------------------------------------------------------------------------
    // Auto-génération du slug depuis le nom
    // -------------------------------------------------------------------------
    $( '#pl_name' ).on( 'input', function () {
        const $slug = $( '#pl_slug' );
        if ( $slug.val() !== '' && $slug.data( 'manual' ) ) return;

        const slug = $( this ).val()
            .toLowerCase()
            .normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ) // enlever accents
            .replace( /[^a-z0-9]+/g, '-' )
            .replace( /^-+|-+$/g, '' );

        $slug.val( slug );
    } );

    $( '#pl_slug' ).on( 'input', function () {
        $( this ).data( 'manual', true );
    } );

    // -------------------------------------------------------------------------
    // Prévisualisation du prompt
    // -------------------------------------------------------------------------
    $( '#pl-preview-prompt' ).on( 'click', function () {
        const systemPrompt = $( '#pl_system_prompt' ).val();
        const resources    = $( '#pl_resources' ).val();
        const injectRes    = $( '[name=pl_inject_resources]' ).is( ':checked' );
        const injectScore  = $( '[name=pl_inject_scoring]' ).is( ':checked' );

        let full = systemPrompt;
        if ( injectRes && resources ) full += '\n\n--- Ressources ---\n' + resources;
        if ( injectScore ) full += '\n\n--- Grille de scoring injectée ---';

        const tokens = Math.ceil( full.length / 4 ); // estimation grossière

        $( '#pl-preview-content' ).text( full );
        $( '#pl-preview-tokens' ).text( `Estimation : ~${tokens} tokens` );
        $( '#pl-preview-modal' ).slideToggle( 200 );
    } );

} )( jQuery );
