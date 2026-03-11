/**
 * Donor Stats Block - Interactivity View Module
 *
 * Provides animated number counting and optional auto-refresh.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

// Animation frame ID for cleanup.
let animationFrameId = null;
let refreshIntervalId = null;

/**
 * Donor Stats Store
 */
const { state, actions } = store( 'starter-shelter/donor-stats', {
    state: {
        // Server-provided state:
        // - stats: { total, count, donors, members }
        // - formatted: { total, count, donors, members }
        // - animated: { total, count, donors, members }
        // - isAnimated: boolean
        
        /**
         * Whether animation is currently running.
         */
        isAnimating: false,
    },

    actions: {
        /**
         * Animate numbers from 0 to their target values.
         */
        animateNumbers() {
            if ( state.isAnimated ) return;
            
            state.isAnimating = true;
            
            const duration = 2000; // 2 seconds
            const startTime = performance.now();
            
            const targets = {
                total: state.stats.total,
                count: state.stats.count,
                donors: state.stats.donors,
                members: state.stats.members,
            };
            
            function animate( currentTime ) {
                const elapsed = currentTime - startTime;
                const progress = Math.min( elapsed / duration, 1 );
                
                // Easing function (ease-out-cubic).
                const eased = 1 - Math.pow( 1 - progress, 3 );
                
                // Update animated values.
                state.animated.total = Math.round( targets.total * eased );
                state.animated.count = Math.round( targets.count * eased );
                state.animated.donors = Math.round( targets.donors * eased );
                state.animated.members = Math.round( targets.members * eased );
                
                // Update formatted values.
                state.formatted.total = formatCurrency( state.animated.total );
                state.formatted.count = formatNumber( state.animated.count );
                state.formatted.donors = formatNumber( state.animated.donors );
                state.formatted.members = formatNumber( state.animated.members );
                
                if ( progress < 1 ) {
                    animationFrameId = requestAnimationFrame( animate );
                } else {
                    // Ensure we end with exact values.
                    state.formatted.total = formatCurrency( targets.total );
                    state.formatted.count = formatNumber( targets.count );
                    state.formatted.donors = formatNumber( targets.donors );
                    state.formatted.members = formatNumber( targets.members );
                    state.isAnimated = true;
                    state.isAnimating = false;
                }
            }
            
            animationFrameId = requestAnimationFrame( animate );
        },

        /**
         * Refresh stats from the server.
         */
        *refreshStats() {
            const context = getContext();
            
            try {
                const response = yield fetch( '/wp-json/starter-shelter/v1/stats', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                } );
                
                if ( ! response.ok ) {
                    throw new Error( 'Failed to fetch stats' );
                }
                
                const data = yield response.json();
                
                // Update stats.
                state.stats.total = data.total_donations || 0;
                state.stats.count = data.donation_count || 0;
                state.stats.donors = data.donor_count || 0;
                state.stats.members = data.active_members || 0;
                
                // Update formatted values without animation.
                state.formatted.total = formatCurrency( state.stats.total );
                state.formatted.count = formatNumber( state.stats.count );
                state.formatted.donors = formatNumber( state.stats.donors );
                state.formatted.members = formatNumber( state.stats.members );
            } catch ( error ) {
                console.error( 'Failed to refresh stats:', error );
            }
        },
    },

    callbacks: {
        /**
         * Initialize the block.
         */
        init() {
            const context = getContext();
            
            // Set up auto-refresh if configured.
            if ( context.refreshInterval > 0 ) {
                refreshIntervalId = setInterval(
                    () => actions.refreshStats(),
                    context.refreshInterval * 1000
                );
            }
            
            // Clean up on unmount (if the element is removed).
            return () => {
                if ( animationFrameId ) {
                    cancelAnimationFrame( animationFrameId );
                }
                if ( refreshIntervalId ) {
                    clearInterval( refreshIntervalId );
                }
            };
        },

        /**
         * Watch for visibility changes to trigger animation.
         */
        watchViewport() {
            const context = getContext();
            
            if ( ! context.animateNumbers || state.isAnimated ) {
                return;
            }
            
            const { ref } = getElement();
            
            // Use IntersectionObserver to trigger animation when visible.
            const observer = new IntersectionObserver(
                ( entries ) => {
                    entries.forEach( ( entry ) => {
                        if ( entry.isIntersecting && ! state.isAnimated ) {
                            actions.animateNumbers();
                            observer.disconnect();
                        }
                    } );
                },
                { threshold: 0.2 }
            );
            
            observer.observe( ref );
            
            // Cleanup function.
            return () => observer.disconnect();
        },
    },
} );

/**
 * Format a number as currency.
 */
function formatCurrency( value ) {
    // Use simple formatting - in production, get from config.
    return '$' + value.toLocaleString( 'en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    } );
}

/**
 * Format a number with thousands separators.
 */
function formatNumber( value ) {
    return value.toLocaleString( 'en-US' );
}

export { state, actions };
