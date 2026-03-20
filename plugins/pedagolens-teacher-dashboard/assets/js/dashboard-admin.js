/**
 * PédagoLens Teacher Dashboard — Front JS
 * Counter animations, IntersectionObserver, AJAX analyse/projet
 */
( function () {
    'use strict';

    /* =====================================================================
       Helpers
       ===================================================================== */

    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

    function getAjaxConfig() {
        // Front-end context (plFront from landing plugin)
        if ( typeof plFront !== 'undefined' ) {
            return {
                url:   plFront.ajaxUrl,
                nonce: plFront.nonces?.dashboard || '',
                i18n:  plFront.i18n || {},
            };
        }
        // Admin context (plDashboard from dashboard plugin)
        if ( typeof plDashboard !== 'undefined' ) {
            return {
                url:   plDashboard.ajaxUrl,
                nonce: plDashboard.nonce,
                i18n:  plDashboard.i18n || {},
            };
        }
        return null;
    }

    /* =====================================================================
       Counter Animation
       ===================================================================== */

    function animateCounter( el ) {
        const target = parseInt( el.dataset.target, 10 ) || 0;
        if ( target === 0 ) { el.textContent = '0'; return; }

        const duration = 1200;
        const start    = performance.now();

        function step( now ) {
            const elapsed  = now - start;
            const progress = Math.min( elapsed / duration, 1 );
            // ease-out cubic
            const ease     = 1 - Math.pow( 1 - progress, 3 );
            el.textContent = Math.round( ease * target );
            if ( progress < 1 ) requestAnimationFrame( step );
        }

        requestAnimationFrame( step );
    }

    /* =====================================================================
       IntersectionObserver — fade-in + counters + bars
       ===================================================================== */

    function initObservers() {
        // Animate-in elements
        const animateEls = document.querySelectorAll( '.pl-animate-in' );
        if ( animateEls.length ) {
            const obs = new IntersectionObserver( ( entries ) => {
                entries.forEach( entry => {
                    if ( entry.isIntersecting ) {
                        entry.target.classList.add( 'pl-visible' );
                        obs.unobserve( entry.target );
                    }
                } );
            }, { threshold: 0.1 } );

            animateEls.forEach( el => obs.observe( el ) );
        }

        // Counter elements
        const counters = document.querySelectorAll( '.pl-stat-number[data-target]' );
        if ( counters.length ) {
            const cObs = new IntersectionObserver( ( entries ) => {
                entries.forEach( entry => {
                    if ( entry.isIntersecting ) {
                        animateCounter( entry.target );
                        cObs.unobserve( entry.target );
                    }
                } );
            }, { threshold: 0.3 } );

            counters.forEach( el => cObs.observe( el ) );
        }

        // Score bars — animate width
        animateScoreBars( document );
    }

    function animateScoreBars( container ) {
        const bars = container.querySelectorAll( '.pl-score-bar[data-score]' );
        if ( ! bars.length ) return;

        const bObs = new IntersectionObserver( ( entries ) => {
            entries.forEach( entry => {
                if ( entry.isIntersecting ) {
                    const bar   = entry.target;
                    const score = parseInt( bar.dataset.score, 10 ) || 0;
                    // Small delay for visual effect
                    setTimeout( () => { bar.style.width = score + '%'; }, 100 );
                    bObs.unobserve( bar );
                }
            } );
        }, { threshold: 0.1 } );

        bars.forEach( el => bObs.observe( el ) );
    }

    /* =====================================================================
       AJAX — Analyze Course
       ===================================================================== */

    function initAnalyzeButtons() {
        document.addEventListener( 'click', function ( e ) {
            const btn = e.target.closest( '.pl-btn-analyze-front' ) || e.target.closest( '.pl-btn-analyze' );
            if ( ! btn ) return;

            const cfg = getAjaxConfig();
            if ( ! cfg ) return;

            const courseId = btn.dataset.courseId;
            const resultEl = document.getElementById( 'pl-analysis-result-' + courseId )
                          || document.getElementById( 'pl-analysis-' + courseId );
            if ( ! resultEl ) return;

            btn.disabled = true;
            const origText = btn.textContent;
            btn.textContent = cfg.i18n.analyzing || 'Analyse en cours…';
            resultEl.innerHTML = '<div class="pl-loading-pulse">' + ( cfg.i18n.analyzing || 'Analyse en cours…' ) + '</div>';

            const fd = new FormData();
            fd.append( 'action', 'pl_analyze_course' );
            fd.append( 'nonce', cfg.nonce );
            fd.append( 'course_id', courseId );

            fetch( cfg.url, { method: 'POST', body: fd, credentials: 'same-origin' } )
                .then( r => r.json() )
                .then( res => {
                    if ( res.success && res.data?.html ) {
                        resultEl.innerHTML = res.data.html;
                        // Re-animate new bars
                        animateScoreBars( resultEl );
                    } else {
                        const msg = res.data?.message || cfg.i18n.analyzeError || 'Erreur.';
                        resultEl.innerHTML = '<div class="pl-notice pl-notice-error"><p>' + escHtml( msg ) + '</p></div>';
                    }
                } )
                .catch( () => {
                    resultEl.innerHTML = '<div class="pl-notice pl-notice-error"><p>' + escHtml( cfg.i18n.analyzeError || 'Erreur réseau.' ) + '</p></div>';
                } )
                .finally( () => {
                    btn.disabled = false;
                    btn.textContent = origText;
                } );
        } );
    }

    /* =====================================================================
       AJAX — Create Project (modal)
       ===================================================================== */

    function initProjectModal() {
        // Open modal
        document.addEventListener( 'click', function ( e ) {
            const btn = e.target.closest( '.pl-btn-create-project' ) || e.target.closest( '.pl-btn-new-project' );
            if ( ! btn ) return;

            const courseId    = btn.dataset.courseId;
            const courseTitle = btn.dataset.courseTitle || '';

            // Remove existing modal
            const existing = document.getElementById( 'pl-project-modal' );
            if ( existing ) existing.remove();

            const modal = document.createElement( 'div' );
            modal.id = 'pl-project-modal';
            modal.className = 'pl-modal-overlay';
            modal.innerHTML = `
                <div class="pl-modal-box">
                    <h2>Nouveau projet — ${ escHtml( courseTitle ) }</h2>
                    <label>Titre du projet</label>
                    <input type="text" id="pl-project-title" placeholder="Ex. Analyse du plan de cours">
                    <label>Type</label>
                    <select id="pl-project-type">
                        <option value="magistral">Magistral (diapositives, plan de cours)</option>
                        <option value="exercice">Exercice (consigne, TP)</option>
                        <option value="evaluation">Évaluation (examen, dissertation)</option>
                        <option value="travail_equipe">Travail d'équipe</option>
                    </select>
                    <div class="pl-modal-actions">
                        <button type="button" class="pl-btn-ghost" id="pl-project-cancel">Annuler</button>
                        <button type="button" class="pl-btn-glow" id="pl-project-create" data-course-id="${ courseId }">Créer</button>
                    </div>
                    <p class="pl-modal-error" id="pl-project-error"></p>
                </div>`;

            document.body.appendChild( modal );
            document.getElementById( 'pl-project-title' ).focus();
        } );

        // Cancel
        document.addEventListener( 'click', function ( e ) {
            if ( e.target.id === 'pl-project-cancel' || ( e.target.classList.contains( 'pl-modal-overlay' ) && e.target === e.currentTarget ) ) {
                const m = document.getElementById( 'pl-project-modal' );
                if ( m ) m.remove();
            }
        } );

        // Close on overlay click
        document.addEventListener( 'click', function ( e ) {
            if ( e.target.id === 'pl-project-modal' ) {
                e.target.remove();
            }
        } );

        // Create
        document.addEventListener( 'click', function ( e ) {
            if ( e.target.id !== 'pl-project-create' ) return;

            const cfg = getAjaxConfig();
            if ( ! cfg ) return;

            const btn      = e.target;
            const courseId  = btn.dataset.courseId;
            const title    = document.getElementById( 'pl-project-title' ).value.trim();
            const type     = document.getElementById( 'pl-project-type' ).value;
            const errorEl  = document.getElementById( 'pl-project-error' );

            if ( ! title ) {
                errorEl.textContent = 'Le titre est requis.';
                errorEl.style.display = 'block';
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Création…';

            const fd = new FormData();
            fd.append( 'action', 'pl_create_project' );
            fd.append( 'nonce', cfg.nonce );
            fd.append( 'course_id', courseId );
            fd.append( 'type', type );
            fd.append( 'title', title );

            fetch( cfg.url, { method: 'POST', body: fd, credentials: 'same-origin' } )
                .then( r => r.json() )
                .then( res => {
                    if ( res.success ) {
                        const m = document.getElementById( 'pl-project-modal' );
                        if ( m ) m.remove();
                        window.location.href = res.data.workbench_url;
                    } else {
                        errorEl.textContent = res.data?.message || 'Erreur.';
                        errorEl.style.display = 'block';
                        btn.disabled = false;
                        btn.textContent = 'Créer';
                    }
                } )
                .catch( () => {
                    errorEl.textContent = 'Erreur réseau.';
                    errorEl.style.display = 'block';
                    btn.disabled = false;
                    btn.textContent = 'Créer';
                } );
        } );

        // Escape key
        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' ) {
                const m = document.getElementById( 'pl-project-modal' );
                if ( m ) m.remove();
            }
        } );
    }

    /* =====================================================================
       Init
       ===================================================================== */

    function init() {
        initObservers();
        initAnalyzeButtons();
        initProjectModal();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();
