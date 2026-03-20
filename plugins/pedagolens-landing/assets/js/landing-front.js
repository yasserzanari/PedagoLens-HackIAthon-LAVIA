/**
 * PédagoLens Landing — Front-end JS
 *
 * - IntersectionObserver pour animations au scroll
 * - Smooth scroll pour les ancres
 * - Counter animation pour les stats
 * - Score bars animation
 */
( function () {
    'use strict';

    // =========================================================================
    // 1. INTERSECTION OBSERVER — Fade-in au scroll
    // =========================================================================

    function initScrollAnimations() {
        if ( ! ( 'IntersectionObserver' in window ) ) {
            document.querySelectorAll( '.pl-animate-in' ).forEach( function ( el ) {
                el.classList.add( 'pl-visible' );
            } );
            return;
        }

        var observer = new IntersectionObserver( function ( entries ) {
            entries.forEach( function ( entry ) {
                if ( entry.isIntersecting ) {
                    entry.target.classList.add( 'pl-visible' );
                    observer.unobserve( entry.target );
                }
            } );
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' } );

        document.querySelectorAll( '.pl-animate-in' ).forEach( function ( el ) {
            observer.observe( el );
        } );
    }

    // =========================================================================
    // 2. COUNTER ANIMATION — Animate numbers from 0 to target
    // =========================================================================

    function animateCounters() {
        if ( ! ( 'IntersectionObserver' in window ) ) {
            document.querySelectorAll( '[data-count-to]' ).forEach( function ( el ) {
                el.textContent = el.getAttribute( 'data-count-to' );
            } );
            return;
        }

        var counterObserver = new IntersectionObserver( function ( entries ) {
            entries.forEach( function ( entry ) {
                if ( entry.isIntersecting ) {
                    var el = entry.target;
                    var target = parseInt( el.getAttribute( 'data-count-to' ), 10 );
                    var suffix = el.getAttribute( 'data-count-suffix' ) || '';
                    var duration = 2000;
                    var startTime = null;

                    function step( timestamp ) {
                        if ( ! startTime ) startTime = timestamp;
                        var progress = Math.min( ( timestamp - startTime ) / duration, 1 );
                        var eased = 1 - Math.pow( 1 - progress, 3 );
                        var current = Math.floor( eased * target );
                        el.textContent = current + suffix;
                        if ( progress < 1 ) {
                            window.requestAnimationFrame( step );
                        } else {
                            el.textContent = target + suffix;
                        }
                    }

                    window.requestAnimationFrame( step );
                    counterObserver.unobserve( el );
                }
            } );
        }, { threshold: 0.3 } );

        document.querySelectorAll( '[data-count-to]' ).forEach( function ( el ) {
            el.textContent = '0';
            counterObserver.observe( el );
        } );
    }

    // =========================================================================
    // 3. SCORE BARS ANIMATION
    // =========================================================================

    function animateScoreBars() {
        if ( ! ( 'IntersectionObserver' in window ) ) {
            document.querySelectorAll( '.pl-score-bar' ).forEach( function ( bar ) {
                bar.classList.add( 'pl-animated' );
            } );
            return;
        }

        var barObserver = new IntersectionObserver( function ( entries ) {
            entries.forEach( function ( entry ) {
                if ( entry.isIntersecting ) {
                    var bars = entry.target.querySelectorAll( '.pl-score-bar' );
                    bars.forEach( function ( bar, index ) {
                        setTimeout( function () {
                            bar.classList.add( 'pl-animated' );
                        }, index * 150 );
                    } );
                    barObserver.unobserve( entry.target );
                }
            } );
        }, { threshold: 0.2 } );

        document.querySelectorAll( '.pl-score-bars' ).forEach( function ( container ) {
            barObserver.observe( container );
        } );
    }

    // =========================================================================
    // 4. SMOOTH SCROLL — Anchor links
    // =========================================================================

    function initSmoothScroll() {
        document.querySelectorAll( 'a[href^="#"]' ).forEach( function ( anchor ) {
            anchor.addEventListener( 'click', function ( e ) {
                var targetId = this.getAttribute( 'href' );
                if ( targetId === '#' ) return;
                var targetEl = document.querySelector( targetId );
                if ( targetEl ) {
                    e.preventDefault();
                    var navHeight = 64;
                    var targetPosition = targetEl.getBoundingClientRect().top + window.pageYOffset - navHeight;
                    window.scrollTo( {
                        top: targetPosition,
                        behavior: 'smooth'
                    } );
                }
            } );
        } );
    }

    // =========================================================================
    // 5. INIT
    // =========================================================================

    function init() {
        initScrollAnimations();
        animateCounters();
        animateScoreBars();
        initSmoothScroll();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();
