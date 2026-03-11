/**
 * Logo Moderation Admin JavaScript
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

( function( $ ) {
    'use strict';

    const LogoModeration = {
        /**
         * Initialize the module.
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            // Approve button click.
            $( document ).on( 'click', '.sd-approve-btn', this.handleApprove.bind( this ) );

            // Reject button click - open modal.
            $( document ).on( 'click', '.sd-reject-btn', this.openRejectModal.bind( this ) );

            // Modal cancel.
            $( document ).on( 'click', '.sd-modal-cancel', this.closeModal.bind( this ) );

            // Modal confirm rejection.
            $( document ).on( 'click', '.sd-modal-confirm', this.handleReject.bind( this ) );

            // Close modal on background click.
            $( document ).on( 'click', '.sd-modal', function( e ) {
                if ( $( e.target ).hasClass( 'sd-modal' ) ) {
                    LogoModeration.closeModal();
                }
            } );

            // Close modal on escape key.
            $( document ).on( 'keydown', function( e ) {
                if ( e.key === 'Escape' && $( '#sd-reject-modal' ).is( ':visible' ) ) {
                    LogoModeration.closeModal();
                }
            } );

            // Select all checkbox.
            $( '#cb-select-all-1, #cb-select-all-2' ).on( 'change', function() {
                $( 'input[name="membership_ids[]"]' ).prop( 'checked', $( this ).prop( 'checked' ) );
            } );
        },

        /**
         * Handle approve button click.
         *
         * @param {Event} e Click event.
         */
        handleApprove: function( e ) {
            e.preventDefault();

            const $btn = $( e.currentTarget );
            const $card = $btn.closest( '.sd-logo-card' );
            const membershipId = $btn.data( 'id' );

            if ( $btn.hasClass( 'disabled' ) ) {
                return;
            }

            // Show loading state.
            $btn.addClass( 'disabled' ).text( sdLogoMod.approving );

            $.ajax( {
                url: sdLogoMod.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sd_approve_logo',
                    nonce: sdLogoMod.nonce,
                    membership_id: membershipId
                },
                success: function( response ) {
                    if ( response.success ) {
                        // Show success and fade out card.
                        $card.addClass( 'sd-card-approved' );
                        $btn.text( sdLogoMod.approved );
                        
                        setTimeout( function() {
                            $card.fadeOut( 300, function() {
                                $card.remove();
                                LogoModeration.checkEmptyState();
                            } );
                        }, 500 );
                    } else {
                        alert( response.data.message || sdLogoMod.error );
                        $btn.removeClass( 'disabled' ).html( '<span class="dashicons dashicons-yes"></span> Approve' );
                    }
                },
                error: function() {
                    alert( sdLogoMod.error );
                    $btn.removeClass( 'disabled' ).html( '<span class="dashicons dashicons-yes"></span> Approve' );
                }
            } );
        },

        /**
         * Open the rejection modal.
         *
         * @param {Event} e Click event.
         */
        openRejectModal: function( e ) {
            e.preventDefault();

            const membershipId = $( e.currentTarget ).data( 'id' );
            
            // Reset modal.
            $( '#sd-reject-reason' ).val( '' );
            $( '#sd-reject-notes' ).val( '' );
            $( '#sd-reject-membership-id' ).val( membershipId );

            // Show modal.
            $( '#sd-reject-modal' ).fadeIn( 200 );
            $( '#sd-reject-reason' ).focus();
        },

        /**
         * Close the rejection modal.
         */
        closeModal: function() {
            $( '#sd-reject-modal' ).fadeOut( 200 );
        },

        /**
         * Handle rejection confirmation.
         */
        handleReject: function() {
            const membershipId = $( '#sd-reject-membership-id' ).val();
            const reason = $( '#sd-reject-reason' ).val();
            const notes = $( '#sd-reject-notes' ).val();

            if ( ! reason ) {
                alert( 'Please select a rejection reason.' );
                $( '#sd-reject-reason' ).focus();
                return;
            }

            const $confirmBtn = $( '.sd-modal-confirm' );
            $confirmBtn.addClass( 'disabled' ).text( sdLogoMod.rejecting );

            $.ajax( {
                url: sdLogoMod.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sd_reject_logo',
                    nonce: sdLogoMod.nonce,
                    membership_id: membershipId,
                    reason: reason,
                    notes: notes
                },
                success: function( response ) {
                    if ( response.success ) {
                        LogoModeration.closeModal();

                        // Find and update the card.
                        const $card = $( '.sd-logo-card[data-membership-id="' + membershipId + '"]' );
                        
                        setTimeout( function() {
                            $card.fadeOut( 300, function() {
                                $card.remove();
                                LogoModeration.checkEmptyState();
                            } );
                        }, 300 );
                    } else {
                        alert( response.data.message || sdLogoMod.error );
                    }
                    
                    $confirmBtn.removeClass( 'disabled' ).text( 'Reject Logo' );
                },
                error: function() {
                    alert( sdLogoMod.error );
                    $confirmBtn.removeClass( 'disabled' ).text( 'Reject Logo' );
                }
            } );
        },

        /**
         * Check if grid is empty and show empty state.
         */
        checkEmptyState: function() {
            if ( $( '.sd-logo-card' ).length === 0 ) {
                $( '.sd-logo-grid' ).replaceWith( 
                    '<div class="sd-empty-state">' +
                        '<span class="dashicons dashicons-yes-alt"></span>' +
                        '<p>No logos pending review. Great job!</p>' +
                    '</div>'
                );
                
                // Hide bulk actions.
                $( '.tablenav.top' ).hide();
            }
        }
    };

    // Initialize on document ready.
    $( document ).ready( function() {
        LogoModeration.init();
    } );

} )( jQuery );
