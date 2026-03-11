/**
 * Campaigns Store
 *
 * Handles campaign progress display and refresh.
 * Multi-instance capable for multiple campaign blocks.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

import { store, getContext } from '@wordpress/interactivity';
import { getSharedConfig, apiRequest, formatCurrency } from './utils.js';

const { state, actions } = store( 'starter-shelter/campaign', {
    state: {
        campaigns: {},
        isLoading: false,
    },

    actions: {
        /**
         * Fetch campaign data.
         */
        *fetchCampaign() {
            const ctx = getContext();
            const campaignId = ctx.campaignId;
            if ( ! campaignId ) return;

            state.isLoading = true;

            try {
                const result = yield apiRequest( `campaigns/${ campaignId }` );
                state.campaigns[ campaignId ] = result;
            } catch ( error ) {
                console.error( 'Failed to fetch campaign:', error );
            } finally {
                state.isLoading = false;
            }
        },

        /**
         * Refresh campaign progress.
         */
        *refreshProgress() {
            const ctx = getContext();
            if ( ctx.campaignId ) {
                yield actions.fetchCampaign();
            }
        },

        /**
         * Start auto-refresh interval.
         */
        startAutoRefresh() {
            const ctx = getContext();
            if ( ctx.refreshInterval && ctx.refreshInterval > 0 ) {
                ctx._refreshTimer = setInterval( () => {
                    actions.fetchCampaign();
                }, ctx.refreshInterval * 1000 );
            }
        },

        /**
         * Stop auto-refresh interval.
         */
        stopAutoRefresh() {
            const ctx = getContext();
            if ( ctx._refreshTimer ) {
                clearInterval( ctx._refreshTimer );
                ctx._refreshTimer = null;
            }
        },
    },

    callbacks: {
        getCampaign() {
            const ctx = getContext();
            return state.campaigns[ ctx.campaignId ] || null;
        },

        getProgressPercentage() {
            const ctx = getContext();
            const campaign = state.campaigns[ ctx.campaignId ];
            if ( ! campaign?.goal ) return 0;
            return Math.min( 100, ( campaign.raised / campaign.goal ) * 100 );
        },

        getProgressWidth() {
            const ctx = getContext();
            const campaign = state.campaigns[ ctx.campaignId ];
            if ( ! campaign?.goal ) return 'width: 0%';
            const pct = Math.min( 100, ( campaign.raised / campaign.goal ) * 100 );
            return `width: ${ pct }%`;
        },

        getRaisedFormatted() {
            const ctx = getContext();
            const campaign = state.campaigns[ ctx.campaignId ];
            return formatCurrency( campaign?.raised || 0 );
        },

        getGoalFormatted() {
            const ctx = getContext();
            const campaign = state.campaigns[ ctx.campaignId ];
            return formatCurrency( campaign?.goal || 0 );
        },

        getRemainingFormatted() {
            const ctx = getContext();
            const campaign = state.campaigns[ ctx.campaignId ];
            if ( ! campaign?.goal ) return formatCurrency( 0 );
            const remaining = Math.max( 0, campaign.goal - ( campaign.raised || 0 ) );
            return formatCurrency( remaining );
        },

        getDonorCount() {
            const ctx = getContext();
            const campaign = state.campaigns[ ctx.campaignId ];
            return campaign?.donor_count || 0;
        },

        getDaysRemaining() {
            const ctx = getContext();
            const campaign = state.campaigns[ ctx.campaignId ];
            if ( ! campaign?.end_date ) return null;

            const end = new Date( campaign.end_date );
            const now = new Date();
            const diff = Math.ceil( ( end - now ) / ( 1000 * 60 * 60 * 24 ) );
            return Math.max( 0, diff );
        },

        isGoalReached() {
            const ctx = getContext();
            const campaign = state.campaigns[ ctx.campaignId ];
            return campaign && campaign.raised >= campaign.goal;
        },

        hasEnded() {
            const ctx = getContext();
            const campaign = state.campaigns[ ctx.campaignId ];
            if ( ! campaign?.end_date ) return false;
            return new Date( campaign.end_date ) < new Date();
        },
    },
} );

export { state, actions };
