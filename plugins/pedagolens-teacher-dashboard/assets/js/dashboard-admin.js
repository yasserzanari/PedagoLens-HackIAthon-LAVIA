/**
 * PédagoLens Teacher Dashboard — Professional JS v3
 * Sidebar navigation, course detail view, modals, AJAX, animations
 */
( function () {
    'use strict';

    /* =====================================================================
       Helpers
       ===================================================================== */

    function esc( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

    function getAjaxConfig() {
        if ( typeof plFront !== 'undefined' ) {
            return { url: plFront.ajaxUrl, nonce: plFront.nonces?.dashboard || '', i18n: plFront.i18n || {} };
        }
        if ( typeof plDashboard !== 'undefined' ) {
            return { url: plDashboard.ajaxUrl, nonce: plDashboard.nonce, i18n: plDashboard.i18n || {} };
        }
        return null;
    }

    function getCoursesData() {
        try {
            const el = document.getElementById( 'pl-courses-json' );
            return el ? JSON.parse( el.textContent ) : [];
        } catch { return []; }
    }

    /* =====================================================================
       Sidebar Navigation
       ===================================================================== */

    function initSidebar() {
        const hamburger = document.getElementById( 'pl-hamburger' );
        const sidebar   = document.getElementById( 'pl-sidebar' );
        if ( ! hamburger || ! sidebar ) return;

        // Create overlay
        let overlay = document.querySelector( '.pl-sidebar-overlay' );
        if ( ! overlay ) {
            overlay = document.createElement( 'div' );
            overlay.className = 'pl-sidebar-overlay';
            sidebar.parentNode.insertBefore( overlay, sidebar.nextSibling );
        }

        function toggleSidebar() {
            const open = sidebar.classList.toggle( 'pl-sidebar-open' );
            hamburger.classList.toggle( 'active', open );
            overlay.classList.toggle( 'active', open );
        }

        hamburger.addEventListener( 'click', toggleSidebar );
        overlay.addEventListener( 'click', toggleSidebar );

        // Sidebar view links
        document.querySelectorAll( '.pl-sidebar-link[data-view]' ).forEach( link => {
            link.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                const view = this.dataset.view;
                switchView( view );

                // Update active state
                document.querySelectorAll( '.pl-sidebar-link' ).forEach( l => l.classList.remove( 'pl-sidebar-active' ) );
                this.classList.add( 'pl-sidebar-active' );

                // Close mobile sidebar
                if ( window.innerWidth <= 768 ) {
                    sidebar.classList.remove( 'pl-sidebar-open' );
                    hamburger.classList.remove( 'active' );
                    overlay.classList.remove( 'active' );
                }
            } );
        } );
    }

    function switchView( viewName ) {
        document.querySelectorAll( '.pl-dash-view' ).forEach( v => v.classList.remove( 'pl-dash-view--active' ) );
        const target = document.getElementById( 'pl-view-' + viewName );
        if ( target ) {
            target.classList.add( 'pl-dash-view--active' );
            // Re-trigger animations
            target.querySelectorAll( '.pl-animate-in' ).forEach( el => {
                el.classList.remove( 'pl-visible' );
                void el.offsetWidth;
            } );
            initObservers();
        }
    }

    /* =====================================================================
       Course Detail View (Open button)
       ===================================================================== */

    function initCourseDetail() {
        document.addEventListener( 'click', function ( e ) {
            const btn = e.target.closest( '.pl-btn-open-course' );
            if ( ! btn ) return;

            const courseId = parseInt( btn.dataset.courseId, 10 );
            const courses  = getCoursesData();
            const course   = courses.find( c => c.id === courseId );
            if ( ! course ) return;

            renderCourseDetail( course );
            switchView( 'courses' );

            // Update sidebar active
            document.querySelectorAll( '.pl-sidebar-link' ).forEach( l => l.classList.remove( 'pl-sidebar-active' ) );
            const coursesLink = document.querySelector( '.pl-sidebar-link[data-view="courses"]' );
            if ( coursesLink ) coursesLink.classList.add( 'pl-sidebar-active' );
        } );

        // Back button
        document.addEventListener( 'click', function ( e ) {
            if ( ! e.target.closest( '.pl-btn-back-overview' ) ) return;
            switchView( 'overview' );
            document.querySelectorAll( '.pl-sidebar-link' ).forEach( l => l.classList.remove( 'pl-sidebar-active' ) );
            const overviewLink = document.querySelector( '.pl-sidebar-link[data-view="overview"]' );
            if ( overviewLink ) overviewLink.classList.add( 'pl-sidebar-active' );
        } );
    }

    function renderCourseDetail( course ) {
        const container = document.getElementById( 'pl-course-detail-content' );
        if ( ! container ) return;

        const typeBadge = `<span class="pl-badge pl-type-${ esc( course.type ) }">${ esc( course.type ) }</span>`;

        let projectsHtml = '';
        if ( course.projects.length ) {
            projectsHtml = `<h3 class="pl-detail-projects-title">📄 Projets (${course.projects.length})</h3>
            <div class="pl-detail-project-list">`;
            course.projects.forEach( p => {
                projectsHtml += `
                <div class="pl-detail-project-row">
                    <div class="pl-detail-project-info">
                        <span class="pl-detail-project-title">${ esc( p.title ) }</span>
                        <span class="pl-badge pl-type-${ esc( p.type ) }">${ esc( p.type ) }</span>
                        ${ p.date ? `<span class="pl-detail-project-date">${ esc( p.date ) }</span>` : '' }
                    </div>
                    <a href="${ esc( p.url ) }" class="pl-btn-glow pl-btn-sm">📝 Ouvrir dans le Workbench</a>
                </div>`;
            } );
            projectsHtml += '</div>';
        } else {
            projectsHtml = '<div class="pl-detail-empty">Aucun projet pour ce cours. Créez-en un !</div>';
        }

        container.innerHTML = `
            <div class="pl-detail-header">
                <div>
                    <h2>${ esc( course.title ) }</h2>
                    <div style="margin-top:8px">${ typeBadge } <span style="color:var(--pl-text-muted);font-size:13px;margin-left:12px">📅 ${ esc( course.date ) }</span></div>
                </div>
                <div class="pl-detail-actions">
                    <button class="pl-btn-glow pl-btn-sm pl-btn-analyze-front" data-course-id="${ course.id }">🔍 Analyser</button>
                    <button class="pl-btn-ghost pl-btn-sm pl-btn-create-project" data-course-id="${ course.id }" data-course-title="${ esc( course.title ) }">➕ Nouveau projet</button>
                </div>
            </div>
            <div id="pl-analysis-result-${ course.id }" class="pl-analysis-front-result"></div>
            ${ projectsHtml }
        `;

        // Load existing analysis if present on the overview card
        const existingResult = document.querySelector( '#pl-view-overview #pl-analysis-result-' + course.id );
        if ( existingResult && existingResult.innerHTML.trim() ) {
            const detailResult = container.querySelector( '#pl-analysis-result-' + course.id );
            if ( detailResult ) {
                detailResult.innerHTML = existingResult.innerHTML;
                animateScoreBars( detailResult );
            }
        }
    }

    /* =====================================================================
       Counter Animation
       ===================================================================== */

    function animateCounter( el ) {
        const target = parseInt( el.dataset.target, 10 ) || 0;
        if ( target === 0 ) { el.textContent = '0'; return; }
        const duration = 1600;
        const start    = performance.now();
        function step( now ) {
            const elapsed  = now - start;
            const progress = Math.min( elapsed / duration, 1 );
            const ease = progress === 1 ? 1 : 1 - Math.pow( 2, -10 * progress );
            el.textContent = Math.round( ease * target );
            if ( progress < 1 ) requestAnimationFrame( step );
        }
        requestAnimationFrame( step );
    }

    /* =====================================================================
       Tilt Effect on stat cards
       ===================================================================== */

    function initTiltEffect() {
        document.querySelectorAll( '.pl-stat-card' ).forEach( card => {
            card.addEventListener( 'mousemove', ( e ) => {
                const rect = card.getBoundingClientRect();
                const x = ( e.clientX - rect.left ) / rect.width - 0.5;
                const y = ( e.clientY - rect.top ) / rect.height - 0.5;
                card.style.transform = `translateY(-4px) perspective(600px) rotateX(${ -y * 6 }deg) rotateY(${ x * 6 }deg)`;
            } );
            card.addEventListener( 'mouseleave', () => { card.style.transform = ''; } );
        } );
    }

    /* =====================================================================
       IntersectionObserver
       ===================================================================== */

    function initObservers() {
        const animateEls = document.querySelectorAll( '.pl-animate-in:not(.pl-visible)' );
        if ( animateEls.length ) {
            const obs = new IntersectionObserver( entries => {
                entries.forEach( entry => {
                    if ( entry.isIntersecting ) {
                        entry.target.classList.add( 'pl-visible' );
                        obs.unobserve( entry.target );
                    }
                } );
            }, { threshold: 0.08 } );
            animateEls.forEach( el => obs.observe( el ) );
        }

        const counters = document.querySelectorAll( '.pl-stat-number[data-target]' );
        if ( counters.length ) {
            const cObs = new IntersectionObserver( entries => {
                entries.forEach( entry => {
                    if ( entry.isIntersecting ) {
                        const card = entry.target.closest( '.pl-stat-card' );
                        const idx = card ? Array.from( card.parentNode.children ).indexOf( card ) : 0;
                        setTimeout( () => animateCounter( entry.target ), idx * 120 );
                        cObs.unobserve( entry.target );
                    }
                } );
            }, { threshold: 0.3 } );
            counters.forEach( el => cObs.observe( el ) );
        }

        animateScoreBars( document );
    }

    function animateScoreBars( container ) {
        const bars = container.querySelectorAll( '.pl-score-bar[data-score]' );
        if ( ! bars.length ) return;
        const bObs = new IntersectionObserver( entries => {
            entries.forEach( entry => {
                if ( entry.isIntersecting ) {
                    const bar   = entry.target;
                    const score = parseInt( bar.dataset.score, 10 ) || 0;
                    const row   = bar.closest( '.pl-score-row' );
                    const idx   = row ? Array.from( row.parentNode.children ).indexOf( row ) : 0;
                    setTimeout( () => { bar.style.width = score + '%'; }, 150 + idx * 100 );
                    bObs.unobserve( bar );
                }
            } );
        }, { threshold: 0.1 } );
        bars.forEach( el => bObs.observe( el ) );
    }
