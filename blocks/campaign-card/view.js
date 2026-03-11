/**
 * Campaign Card Block - Interactivity View Module
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

/**
 * Campaign Card Store
 */
const { state, actions } = store( 'starter-shelter/campaign-card', {
    state: {
        // Server-provided state:
        // - campaign: { id, name, goal, raised, progress, etc. }
        // - isAnimated: boolean

        /**
         * Derived state: Progress bar width.
         */
        get progressWidth() {
            return `${state.campaign?.progress || 0}%`;
        },

        /**
         * Derived state: Progress text.
         */
        get progressText() {
            return `${state.campaign?.progress || 0}%`;
        },
    },

    actions: {
        /**
         * Handle donate button click.
         */
        handleDonate( event ) {
            // Track the click if analytics is available.
            if ( window.gtag ) {
                window.gtag( 'event', 'donate_click', {
                    campaign_id: state.campaign?.id,
                    campaign_name: state.campaign?.name,
                } );
            }
            
            // Let the default link behavior proceed.
        },

        /**
         * Animate progress bar.
         */
        animateProgress() {
            if ( state.isAnimated ) return;
            
            const target = state.campaign?.progress || 0;
            let current = 0;
            
            const animate = () => {
                if ( current < target ) {
                    current = Math.min( current + 1, target );
                    state.campaign.progress = current;
                    requestAnimationFrame( animate );
                } else {
                    state.isAnimated = true;
                }
            };
            
            requestAnimationFrame( animate );
        },

        /**
         * Refresh campaign data from server.
         */
        *refreshCampaign() {
            const context = getContext();
            
            if ( ! context.campaignId ) return;
            
            try {
                const response = yield fetch(
                    `/wp-json/starter-shelter/v1/campaign/${context.campaignId}`
                );
                
                if ( ! response.ok ) {
                    throw new Error( 'Failed to fetch campaign' );
                }
                
                const data = yield response.json();
                
                // Update campaign state.
                state.campaign = {
                    ...state.campaign,
                    raised: data.raised || 0,
                    raised_formatted: data.raised_formatted || '$0',
                    progress: data.progress || 0,
                    donor_count: data.donor_count || 0,
                };
            } catch ( error ) {
                console.error( 'Failed to refresh campaign:', error );
            }
        },
    },

    callbacks: {
        /**
         * Initialize the campaign card.
         */
        init() {
            const { ref } = getElement();
            
            // Animate progress bar when visible.
            const observer = new IntersectionObserver(
                ( entries ) => {
                    entries.forEach( ( entry ) => {
                        if ( entry.isIntersecting && ! state.isAnimated ) {
                            actions.animateProgress();
                            observer.disconnect();
                        }
                    } );
                },
                { threshold: 0.3 }
            );
            
            observer.observe( ref );
            
            return () => observer.disconnect();
        },
    },
} );

export { state, actions };
