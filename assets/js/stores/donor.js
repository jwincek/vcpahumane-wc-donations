/**
 * Donor Store
 *
 * Handles donor dashboard data: stats, history, membership.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

import { store, getContext } from '@wordpress/interactivity';
import { getSharedConfig, apiRequest, formatCurrency, __ } from './utils.js';

const config = getSharedConfig();

const { state, actions } = store( 'starter-shelter/donor', {
    state: {
        isLoading: true,
        isLoggedIn: !! config?.userId,
        donor: null,
        stats: null,
        recentGifts: [],
        membership: null,
        error: null,
    },

    actions: {
        /**
         * Fetch donor data.
         */
        *fetchDonorData() {
            if ( ! state.isLoggedIn || ! config?.donorId ) {
                state.isLoading = false;
                return;
            }

            state.isLoading = true;
            state.error = null;

            try {
                const [ donorData, statsData, giftsData, membershipData ] = yield Promise.all( [
                    apiRequest( `donors/${ config.donorId }` ).catch( () => null ),
                    apiRequest( `donors/${ config.donorId }/stats` ).catch( () => null ),
                    apiRequest( `donors/${ config.donorId }/gifts?per_page=5` ).catch( () => ( { items: [] } ) ),
                    apiRequest( `donors/${ config.donorId }/membership` ).catch( () => null ),
                ] );

                state.donor = donorData;
                state.stats = statsData;
                state.recentGifts = giftsData?.items || [];
                state.membership = membershipData;
            } catch ( error ) {
                state.error = error.message;
            } finally {
                state.isLoading = false;
            }
        },

        /**
         * Refresh donor stats.
         */
        *refreshStats() {
            if ( ! config?.donorId ) return;

            try {
                state.stats = yield apiRequest( `donors/${ config.donorId }/stats` );
            } catch ( error ) {
                console.error( 'Failed to refresh stats:', error );
            }
        },

        /**
         * Initialize dashboard (called on mount).
         */
        init() {
            if ( state.isLoggedIn && config?.donorId ) {
                actions.fetchDonorData();
            } else {
                state.isLoading = false;
            }
        },
    },

    callbacks: {
        getDonorName() {
            return state.donor?.display_name || state.donor?.name || __( 'donor', 'Donor' );
        },

        getTotalGiving() {
            return formatCurrency( state.stats?.total_giving || 0 );
        },

        getYearToDateGiving() {
            return formatCurrency( state.stats?.year_to_date || 0 );
        },

        getGiftCount() {
            return state.stats?.gift_count || 0;
        },

        getConsecutiveYears() {
            return state.stats?.consecutive_years || 0;
        },

        getDonorLevel() {
            const levels = {
                new: __( 'donorLevelNew', 'New Donor' ),
                bronze: __( 'donorLevelBronze', 'Bronze' ),
                silver: __( 'donorLevelSilver', 'Silver' ),
                gold: __( 'donorLevelGold', 'Gold' ),
                platinum: __( 'donorLevelPlatinum', 'Platinum' ),
            };
            return levels[ state.donor?.donor_level ] || __( 'donor', 'Donor' );
        },

        hasMembership() {
            return !! state.membership && state.membership.status === 'active';
        },

        getMembershipTier() {
            return state.membership?.tier_label || state.membership?.tier || '';
        },

        getMembershipExpiry() {
            if ( ! state.membership?.expires_at ) return '';
            return new Date( state.membership.expires_at ).toLocaleDateString();
        },

        getMembershipDaysRemaining() {
            if ( ! state.membership?.expires_at ) return null;
            const expires = new Date( state.membership.expires_at );
            const now = new Date();
            return Math.max( 0, Math.ceil( ( expires - now ) / ( 1000 * 60 * 60 * 24 ) ) );
        },

        isMembershipExpiringSoon() {
            const days = actions.callbacks?.getMembershipDaysRemaining?.();
            return days !== null && days <= 30;
        },

        getRecentGifts() {
            return state.recentGifts || [];
        },

        hasRecentGifts() {
            return state.recentGifts && state.recentGifts.length > 0;
        },

        isLoading() {
            return state.isLoading;
        },

        isLoggedIn() {
            return state.isLoggedIn;
        },

        hasError() {
            return !! state.error;
        },

        getError() {
            return state.error;
        },
    },
} );

export { state, actions };
