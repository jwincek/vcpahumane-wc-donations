/**
 * Donations Store
 *
 * Handles donation listing, filtering, and pagination.
 * Used for admin views and donor history.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

import { store, getContext } from '@wordpress/interactivity';
import { apiRequest, formatCurrency, __ } from './utils.js';

const { state, actions } = store( 'starter-shelter/donations', {
    state: {
        isLoading: false,
        donations: [],
        total: 0,
        totalPages: 0,
        page: 1,
        error: null,
        filters: {
            allocation: '',
            campaign: '',
            dateFrom: '',
            dateTo: '',
        },
    },

    actions: {
        /**
         * Fetch donations.
         */
        *fetchDonations() {
            state.isLoading = true;
            state.error = null;

            try {
                const params = new URLSearchParams( {
                    page: state.page,
                    per_page: 12,
                } );

                const { filters } = state;
                if ( filters.allocation ) params.set( 'allocation', filters.allocation );
                if ( filters.campaign ) params.set( 'campaign_id', filters.campaign );
                if ( filters.dateFrom ) params.set( 'date_from', filters.dateFrom );
                if ( filters.dateTo ) params.set( 'date_to', filters.dateTo );

                const result = yield apiRequest( `donations?${ params }` );

                state.donations = result.items || [];
                state.total = result.total || 0;
                state.totalPages = result.total_pages || 0;
            } catch ( error ) {
                state.error = error.message;
            } finally {
                state.isLoading = false;
            }
        },

        /**
         * Load more donations.
         */
        *loadMore() {
            if ( state.isLoading || state.page >= state.totalPages ) return;

            state.page += 1;
            state.isLoading = true;

            try {
                const params = new URLSearchParams( {
                    page: state.page,
                    per_page: 12,
                } );

                const { filters } = state;
                if ( filters.allocation ) params.set( 'allocation', filters.allocation );
                if ( filters.campaign ) params.set( 'campaign_id', filters.campaign );
                if ( filters.dateFrom ) params.set( 'date_from', filters.dateFrom );
                if ( filters.dateTo ) params.set( 'date_to', filters.dateTo );

                const result = yield apiRequest( `donations?${ params }` );
                state.donations = [ ...state.donations, ...( result.items || [] ) ];
            } catch ( error ) {
                state.page -= 1;
                state.error = error.message;
            } finally {
                state.isLoading = false;
            }
        },

        /**
         * Set filter value.
         */
        *setFilter( event ) {
            const ctx = getContext();
            state.filters[ ctx.filterName ] = event.target.value;
            state.page = 1;
            yield actions.fetchDonations();
        },

        /**
         * Clear all filters.
         */
        *clearFilters() {
            state.filters = {
                allocation: '',
                campaign: '',
                dateFrom: '',
                dateTo: '',
            };
            state.page = 1;
            yield actions.fetchDonations();
        },

        /**
         * Go to page.
         */
        *goToPage() {
            const ctx = getContext();
            if ( ctx.targetPage && ctx.targetPage !== state.page ) {
                state.page = ctx.targetPage;
                yield actions.fetchDonations();
            }
        },
    },

    callbacks: {
        getDonations() {
            return state.donations;
        },

        isLoading() {
            return state.isLoading;
        },

        hasFilters() {
            const { filters } = state;
            return !! ( filters.allocation || filters.campaign || filters.dateFrom || filters.dateTo );
        },

        isEmpty() {
            return ! state.isLoading && state.donations.length === 0;
        },

        canLoadMore() {
            return state.page < state.totalPages && ! state.isLoading;
        },

        getTotalFormatted() {
            return state.total.toLocaleString();
        },

        getResultsSummary() {
            if ( state.isLoading && state.donations.length === 0 ) {
                return __( 'loading', 'Loading...' );
            }
            if ( state.total === 0 ) {
                return __( 'noResults', 'No donations found' );
            }
            return __( 'showingMany', 'Showing %d donations' ).replace( '%d', state.total );
        },

        formatDonationAmount() {
            const ctx = getContext();
            return formatCurrency( ctx.donation?.amount || 0 );
        },

        formatDonationDate() {
            const ctx = getContext();
            if ( ! ctx.donation?.date ) return '';
            return new Date( ctx.donation.date ).toLocaleDateString();
        },
    },
} );

export { state, actions };
