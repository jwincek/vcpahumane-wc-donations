/**
 * Membership Form Store
 *
 * Handles both individual and business membership forms.
 * Tier selection with price display and cart submission.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

import { store, getContext } from '@wordpress/interactivity';
import { getSharedConfig, formatCurrency, __, sanitizeText } from './utils.js';

const { state, actions } = store( 'starter-shelter/membership-form', {
    state: {
        forms: {},
    },

    actions: {
        /**
         * Initialize a membership form instance.
         */
        initForm() {
            const ctx = getContext();
            const { formId, membershipType = 'individual', defaultTier = null } = ctx;

            if ( ! state.forms[ formId ] ) {
                state.forms[ formId ] = {
                    membershipType, // individual | business
                    selectedTier: defaultTier,
                    isAnonymous: false,
                    // Business-specific
                    businessName: '',
                    // State
                    isProcessing: false,
                    error: null,
                    success: null,
                };
            }
        },

        /**
         * Select a membership tier.
         */
        selectTier() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form && ctx.tierSlug ) {
                form.selectedTier = ctx.tierSlug;
                form.error = null;
            }
        },

        /**
         * Set membership type.
         */
        setMembershipType( event ) {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) {
                form.membershipType = event.target.value;
                form.selectedTier = null; // Reset tier when type changes
            }
        },

        toggleAnonymous() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) form.isAnonymous = ! form.isAnonymous;
        },

        setBusinessName( event ) {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) {
                form.businessName = sanitizeText( event.target.value ).substring( 0, 200 );
                form.error = null;
            }
        },

        /**
         * Submit membership to cart.
         */
        *submitToCart() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            const config = getSharedConfig();

            if ( ! form || form.isProcessing ) return;

            // Validation
            if ( ! form.selectedTier ) {
                form.error = __( 'errorSelectTier', 'Please select a membership level.' );
                return;
            }

            if ( form.membershipType === 'business' && ! form.businessName.trim() ) {
                form.error = __( 'errorBusinessName', 'Please enter your business name.' );
                return;
            }

            // Get tier price
            const tiers = ctx.tiers?.[ form.membershipType ] || {};
            const tier = tiers[ form.selectedTier ];
            if ( ! tier ) {
                form.error = __( 'errorInvalidTier', 'Selected tier is not available.' );
                return;
            }

            form.isProcessing = true;
            form.error = null;
            form.success = null;

            try {
                const productType = form.membershipType === 'business' ? 'business_membership' : 'membership';
                
                const formData = new FormData();
                formData.append( 'action', 'sd_add_to_cart' );
                formData.append( 'nonce', config.cartNonce );
                formData.append( 'product_type', productType );
                formData.append( 'amount', tier.price || tier.amount || 0 );
                formData.append( 'tier', form.selectedTier );

                if ( form.isAnonymous ) {
                    formData.append( 'is_anonymous', '1' );
                }

                if ( form.membershipType === 'business' && form.businessName ) {
                    formData.append( 'business_name', form.businessName );
                }

                const response = yield fetch( config.ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                } );

                const result = yield response.json();

                if ( result.success ) {
                    form.success = result.data?.message || __( 'addedToCart', 'Membership added to cart!' );
                    
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

        reset() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) {
                Object.assign( form, {
                    selectedTier: ctx.defaultTier || null,
                    isAnonymous: false,
                    businessName: '',
                    error: null,
                    success: null,
                    isProcessing: false,
                } );
            }
        },
    },

    callbacks: {
        /**
         * Get currently selected tier object.
         */
        getSelectedTier() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( ! form?.selectedTier ) return null;
            
            const tiers = ctx.tiers?.[ form.membershipType ] || {};
            return tiers[ form.selectedTier ] || null;
        },

        /**
         * Get display price for selected tier.
         */
        getDisplayPrice() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( ! form?.selectedTier ) return '$0';
            
            const tiers = ctx.tiers?.[ form.membershipType ] || {};
            const tier = tiers[ form.selectedTier ];
            return formatCurrency( tier?.price || tier?.amount || 0 );
        },

        /**
         * Check if form can proceed.
         */
        canProceed() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( ! form || form.isProcessing ) return false;
            
            const hasTier = !! form.selectedTier;
            const businessValid = form.membershipType !== 'business' || form.businessName.trim().length > 0;
            
            return hasTier && businessValid && ctx.productConfigured;
        },

        /**
         * Check if a tier is selected.
         */
        isTierSelected() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            return form?.selectedTier === ctx.tierSlug;
        },

        /**
         * Get tier price for display in tier card.
         */
        getTierPrice() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            const tiers = ctx.tiers?.[ form?.membershipType || 'individual' ] || {};
            const tier = tiers[ ctx.tierSlug ];
            return formatCurrency( tier?.price || tier?.amount || 0 );
        },

        /**
         * Get tier benefits list.
         */
        getTierBenefits() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            const tiers = ctx.tiers?.[ form?.membershipType || 'individual' ] || {};
            const tier = tiers[ ctx.tierSlug ];
            return tier?.benefits || [];
        },

        /**
         * Check if business membership.
         */
        isBusinessMembership() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            return form?.membershipType === 'business';
        },

        /**
         * Get membership type label.
         */
        getMembershipTypeLabel() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            return form?.membershipType === 'business'
                ? __( 'businessMembership', 'Business Membership' )
                : __( 'individualMembership', 'Individual Membership' );
        },
    },
} );

export { state, actions };
