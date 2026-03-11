/**
 * Donation Form Store
 *
 * Handles general donation form state and cart submission.
 * Multi-instance capable via form IDs.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

import { store, getContext } from '@wordpress/interactivity';
import { getSharedConfig, formatCurrency, parseAmount, __, sanitizeText } from './utils.js';

const { state, actions } = store( 'starter-shelter/donation-form', {
    state: {
        forms: {},
    },

    actions: {
        /**
         * Initialize a form instance.
         */
        initForm() {
            const ctx = getContext();
            const { formId, defaultAmount = 50, campaignId = null } = ctx;

            if ( ! state.forms[ formId ] ) {
                state.forms[ formId ] = {
                    amount: defaultAmount,
                    customAmount: '',
                    allocation: 'general-fund',
                    isAnonymous: false,
                    dedication: '',
                    campaignId,
                    isProcessing: false,
                    error: null,
                    success: null,
                };
            }
        },

        /**
         * Select a preset amount.
         */
        selectAmount() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form && ctx.buttonAmount > 0 ) {
                form.amount = ctx.buttonAmount;
                form.customAmount = '';
                form.error = null;
            }
        },

        /**
         * Clear preset on custom focus.
         */
        clearPresetAmount() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) {
                form.amount = 0;
            }
        },

        /**
         * Set custom amount.
         */
        setCustomAmount( event ) {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) {
                form.customAmount = event.target.value;
                form.amount = 0;
                form.error = null;
            }
        },

        /**
         * Set allocation.
         */
        setAllocation( event ) {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) {
                form.allocation = event.target.value;
            }
        },

        /**
         * Toggle anonymous.
         */
        toggleAnonymous() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) {
                form.isAnonymous = ! form.isAnonymous;
            }
        },

        /**
         * Set dedication.
         */
        setDedication( event ) {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) {
                form.dedication = sanitizeText( event.target.value );
            }
        },

        /**
         * Submit to cart via AJAX.
         */
        *submitToCart() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            const config = getSharedConfig();

            if ( ! form || form.isProcessing ) return;

            const amount = form.amount || parseAmount( form.customAmount );
            
            if ( amount < ( ctx.minAmount || 1 ) ) {
                form.error = __( 'errorMinAmount', 'Please enter a valid amount.' );
                return;
            }

            if ( amount > ( ctx.maxAmount || 100000 ) ) {
                form.error = __( 'errorMaxAmount', 'Amount exceeds maximum allowed.' );
                return;
            }

            form.isProcessing = true;
            form.error = null;
            form.success = null;

            try {
                const formData = new FormData();
                formData.append( 'action', 'sd_add_to_cart' );
                formData.append( 'nonce', config.cartNonce );
                formData.append( 'product_type', 'donation' );
                formData.append( 'amount', amount );
                formData.append( 'allocation', form.allocation );

                if ( form.campaignId ) {
                    formData.append( 'campaign_id', form.campaignId );
                }
                if ( form.isAnonymous ) {
                    formData.append( 'is_anonymous', '1' );
                }
                if ( form.dedication ) {
                    formData.append( 'dedication', form.dedication );
                }

                const response = yield fetch( config.ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                } );

                const result = yield response.json();

                if ( result.success ) {
                    form.success = result.data?.message || __( 'addedToCart', 'Added to cart!' );
                    
                    if ( config.autoRedirectToCheckout && result.data?.checkout_url ) {
                        window.location.href = result.data.checkout_url;
                    }
                } else {
                    form.error = result.data?.message || __( 'errorGeneric', 'Could not add to cart.' );
                }
            } catch ( error ) {
                form.error = __( 'errorNetwork', 'Network error. Please try again.' );
            } finally {
                form.isProcessing = false;
            }
        },

        /**
         * Reset form.
         */
        reset() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) {
                Object.assign( form, {
                    amount: ctx.defaultAmount || 50,
                    customAmount: '',
                    allocation: 'general-fund',
                    isAnonymous: false,
                    dedication: '',
                    error: null,
                    success: null,
                    isProcessing: false,
                } );
            }
        },
    },

    callbacks: {
        getEffectiveAmount() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            return form ? ( form.amount || parseAmount( form.customAmount ) ) : 0;
        },

        getDisplayAmount() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            const amount = form ? ( form.amount || parseAmount( form.customAmount ) ) : 0;
            return formatCurrency( amount );
        },

        canProceed() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( ! form || form.isProcessing ) return false;
            const amount = form.amount || parseAmount( form.customAmount );
            return amount >= ( ctx.minAmount || 1 ) && ctx.productConfigured;
        },

        isAmountSelected() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            return form?.amount === ctx.buttonAmount && ! form?.customAmount;
        },
    },
} );

export { state, actions };
