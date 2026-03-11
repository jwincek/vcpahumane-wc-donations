/**
 * Memorial Form Store
 *
 * Handles memorial/tribute donation form state.
 * Dedicated form for In Memory Of / In Honor Of gifts.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

import { store, getContext } from '@wordpress/interactivity';
import { getSharedConfig, formatCurrency, parseAmount, __, sanitizeText, isValidEmail } from './utils.js';

const { state, actions } = store( 'starter-shelter/memorial-form', {
    state: {
        forms: {},
    },

    actions: {
        /**
         * Initialize a memorial form instance.
         */
        initForm() {
            const ctx = getContext();
            const { formId, defaultAmount = 50 } = ctx;

            if ( ! state.forms[ formId ] ) {
                state.forms[ formId ] = {
                    amount: defaultAmount,
                    customAmount: '',
                    isAnonymous: false,
                    // Memorial-specific fields (required)
                    dedicationType: 'memory', // memory | honor
                    honoreeType: 'person', // person | pet
                    honoreeName: '',
                    tributeMessage: '',
                    // Family notification
                    notifyFamily: false,
                    familyName: '',
                    familyEmail: '',
                    familyAddress: '',
                    sendCard: false,
                    // State
                    isProcessing: false,
                    error: null,
                    success: null,
                };
            }
        },

        // Amount actions
        selectAmount() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form && ctx.buttonAmount > 0 ) {
                form.amount = ctx.buttonAmount;
                form.customAmount = '';
                form.error = null;
            }
        },

        clearPresetAmount() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) form.amount = 0;
        },

        setCustomAmount( event ) {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) {
                form.customAmount = event.target.value;
                form.amount = 0;
                form.error = null;
            }
        },

        toggleAnonymous() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) form.isAnonymous = ! form.isAnonymous;
        },

        // Memorial-specific actions
        setDedicationType( event ) {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) form.dedicationType = event.target.value;
        },

        setHonoreeType( event ) {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) form.honoreeType = event.target.value;
        },

        setHonoreeName( event ) {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) {
                form.honoreeName = sanitizeText( event.target.value ).substring( 0, 100 );
                form.error = null;
            }
        },

        setTributeMessage( event ) {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) {
                form.tributeMessage = sanitizeText( event.target.value ).substring( 0, 500 );
            }
        },

        // Family notification actions
        toggleNotifyFamily() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) form.notifyFamily = ! form.notifyFamily;
        },

        setFamilyName( event ) {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) form.familyName = sanitizeText( event.target.value ).substring( 0, 100 );
        },

        setFamilyEmail( event ) {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) form.familyEmail = event.target.value.trim();
        },

        setFamilyAddress( event ) {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) form.familyAddress = sanitizeText( event.target.value ).substring( 0, 500 );
        },

        toggleSendCard() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( form ) form.sendCard = ! form.sendCard;
        },

        /**
         * Submit memorial donation to cart.
         */
        *submitToCart() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            const config = getSharedConfig();

            if ( ! form || form.isProcessing ) return;

            const amount = form.amount || parseAmount( form.customAmount );
            
            // Validation
            if ( amount < ( ctx.minAmount || 1 ) ) {
                form.error = __( 'errorMinAmount', 'Please enter a valid amount.' );
                return;
            }

            if ( ! form.honoreeName.trim() ) {
                form.error = __( 'errorHonoreeName', 'Please enter the name of who this gift honors.' );
                return;
            }

            if ( form.notifyFamily && ! form.familyName.trim() ) {
                form.error = __( 'errorFamilyName', 'Please enter the family contact name.' );
                return;
            }

            if ( form.notifyFamily && form.familyEmail && ! isValidEmail( form.familyEmail ) ) {
                form.error = __( 'errorInvalidEmail', 'Please enter a valid email address.' );
                return;
            }

            form.isProcessing = true;
            form.error = null;
            form.success = null;

            try {
                const formData = new FormData();
                formData.append( 'action', 'sd_add_to_cart' );
                formData.append( 'nonce', config.cartNonce );
                formData.append( 'product_type', 'memorial' );
                formData.append( 'amount', amount );
                
                if ( form.isAnonymous ) {
                    formData.append( 'is_anonymous', '1' );
                }

                // Memorial fields (always enabled for this form)
                formData.append( 'dedication_enabled', '1' );
                formData.append( 'dedication_type', form.dedicationType );
                formData.append( 'honoree_name', form.honoreeName );
                formData.append( 'honoree_type', form.honoreeType );
                
                if ( form.tributeMessage ) {
                    formData.append( 'tribute_message', form.tributeMessage );
                }

                // Family notification
                if ( form.notifyFamily ) {
                    formData.append( 'notify_family', '1' );
                    formData.append( 'family_name', form.familyName );
                    if ( form.familyEmail ) formData.append( 'family_email', form.familyEmail );
                    if ( form.familyAddress ) formData.append( 'family_address', form.familyAddress );
                    if ( form.sendCard ) formData.append( 'send_card', '1' );
                }

                const response = yield fetch( config.ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                } );

                const result = yield response.json();

                if ( result.success ) {
                    form.success = result.data?.message || __( 'addedToCart', 'Memorial gift added to cart!' );
                    
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
                    amount: ctx.defaultAmount || 50,
                    customAmount: '',
                    isAnonymous: false,
                    dedicationType: 'memory',
                    honoreeType: 'person',
                    honoreeName: '',
                    tributeMessage: '',
                    notifyFamily: false,
                    familyName: '',
                    familyEmail: '',
                    familyAddress: '',
                    sendCard: false,
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
            const hasHonoree = form.honoreeName.trim().length > 0;
            const familyValid = ! form.notifyFamily || form.familyName.trim().length > 0;
            
            return amount >= ( ctx.minAmount || 1 ) && hasHonoree && familyValid && ctx.productConfigured;
        },

        isAmountSelected() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            return form?.amount === ctx.buttonAmount && ! form?.customAmount;
        },

        getHonoreeLabel() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( ! form ) return 'Name';
            return form.honoreeType === 'pet' 
                ? __( 'petName', "Pet's Name" ) 
                : __( 'personName', "Person's Name" );
        },

        getTributeCharCount() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            return form?.tributeMessage?.length || 0;
        },

        getDedicationSummary() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            if ( ! form || ! form.honoreeName ) return '';

            const typeLabel = form.dedicationType === 'memory' 
                ? __( 'inMemoryOf', 'In Memory Of' )
                : __( 'inHonorOf', 'In Honor Of' );
            const honoreeLabel = form.honoreeType === 'pet' ? ` (${ __( 'pet', 'Pet' ) })` : '';
            
            return `${ typeLabel }: ${ form.honoreeName }${ honoreeLabel }`;
        },

        getDedicationTypeLabel() {
            const ctx = getContext();
            const form = state.forms[ ctx.formId ];
            return form?.dedicationType === 'memory' 
                ? __( 'inMemoryOf', 'In Memory Of' )
                : __( 'inHonorOf', 'In Honor Of' );
        },
    },
} );

export { state, actions };
