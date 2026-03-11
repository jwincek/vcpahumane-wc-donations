/**
 * Meta Boxes Admin JavaScript
 *
 * Handles image uploads, searchable selects, conditional fields, and tier selection.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

( function( $, wp ) {
    'use strict';

    const MetaBoxes = {
        /**
         * Initialize the module.
         */
        init: function() {
            this.initSelect2();
            this.initImageUploads();
            this.initConditionalFields();
            this.initTierSelect();
        },

        /**
         * Initialize Select2 for searchable post selects.
         */
        initSelect2: function() {
            $( '.sd-post-select' ).each( function() {
                const $select = $( this );
                const postType = $select.data( 'post-type' );

                $select.select2( {
                    width: '100%',
                    placeholder: $select.data( 'placeholder' ) || 'Search...',
                    allowClear: true,
                    minimumInputLength: 2,
                    ajax: {
                        url: sdMetaBoxes.restUrl + postType,
                        dataType: 'json',
                        delay: 300,
                        headers: {
                            'X-WP-Nonce': sdMetaBoxes.nonce
                        },
                        data: function( params ) {
                            return {
                                search: params.term,
                                per_page: 10
                            };
                        },
                        processResults: function( data ) {
                            return {
                                results: data.map( function( item ) {
                                    return {
                                        id: item.id,
                                        text: item.title.rendered
                                    };
                                } )
                            };
                        },
                        cache: true
                    }
                } );
            } );
        },

        /**
         * Initialize image upload buttons.
         */
        initImageUploads: function() {
            let mediaFrame;

            // Handle upload button click.
            $( document ).on( 'click', '.sd-upload-image', function( e ) {
                e.preventDefault();

                const $wrapper = $( this ).closest( '.sd-image-upload' );
                const $input = $wrapper.find( 'input[type="hidden"]' );
                const $preview = $wrapper.find( '.sd-image-preview' );
                const $removeBtn = $wrapper.find( '.sd-remove-image' );

                // Create media frame if it doesn't exist.
                if ( ! mediaFrame ) {
                    mediaFrame = wp.media( {
                        title: sdMetaBoxes.selectImage,
                        button: {
                            text: sdMetaBoxes.useImage
                        },
                        multiple: false
                    } );
                }

                // Handle selection.
                mediaFrame.off( 'select' ).on( 'select', function() {
                    const attachment = mediaFrame.state().get( 'selection' ).first().toJSON();
                    
                    $input.val( attachment.id );
                    
                    const imageUrl = attachment.sizes && attachment.sizes.thumbnail 
                        ? attachment.sizes.thumbnail.url 
                        : attachment.url;
                    
                    $preview.html( '<img src="' + imageUrl + '" />' );
                    $removeBtn.show();
                } );

                mediaFrame.open();
            } );

            // Handle remove button click.
            $( document ).on( 'click', '.sd-remove-image', function( e ) {
                e.preventDefault();

                const $wrapper = $( this ).closest( '.sd-image-upload' );
                
                $wrapper.find( 'input[type="hidden"]' ).val( '' );
                $wrapper.find( '.sd-image-preview' ).html( '' );
                $( this ).hide();
            } );
        },

        /**
         * Initialize conditional field visibility.
         */
        initConditionalFields: function() {
            const $conditionalElements = $( '[data-show-when]' );

            if ( ! $conditionalElements.length ) {
                return;
            }

            // Build dependency map.
            const dependencies = {};

            $conditionalElements.each( function() {
                const conditions = $( this ).data( 'show-when' );
                
                Object.keys( conditions ).forEach( function( fieldId ) {
                    if ( ! dependencies[ fieldId ] ) {
                        dependencies[ fieldId ] = [];
                    }
                    dependencies[ fieldId ].push( this );
                }.bind( this ) );
            } );

            // Bind change handlers.
            Object.keys( dependencies ).forEach( function( fieldId ) {
                const $field = $( '#sd_' + fieldId );
                
                if ( ! $field.length ) {
                    return;
                }

                // Determine field type.
                const isCheckbox = $field.is( ':checkbox' );

                const updateDependents = function() {
                    const fieldValue = isCheckbox ? $field.is( ':checked' ) : $field.val();

                    dependencies[ fieldId ].forEach( function( element ) {
                        const $element = $( element );
                        const conditions = $element.data( 'show-when' );
                        const requiredValue = conditions[ fieldId ];
                        
                        const shouldShow = isCheckbox 
                            ? ( requiredValue === true && fieldValue ) || ( requiredValue === false && ! fieldValue )
                            : fieldValue === requiredValue;

                        if ( shouldShow ) {
                            $element.slideDown( 200 );
                        } else {
                            $element.slideUp( 200 );
                        }
                    } );
                };

                // Bind event.
                $field.on( 'change', updateDependents );

                // Initial state.
                updateDependents();
            } );
        },

        /**
         * Initialize tier select that depends on membership type.
         */
        initTierSelect: function() {
            const $tierSelect = $( 'select[data-tier-select]' );
            const $typeSelect = $( '#sd_membership_type' );

            if ( ! $tierSelect.length || ! $typeSelect.length ) {
                return;
            }

            const updateTierOptions = function() {
                const selectedType = $typeSelect.val();
                const currentValue = $tierSelect.val();
                
                $tierSelect.find( 'option[data-type]' ).each( function() {
                    const $option = $( this );
                    const optionType = $option.data( 'type' );
                    
                    if ( optionType === selectedType ) {
                        $option.show();
                    } else {
                        $option.hide();
                        // Deselect if currently selected.
                        if ( $option.val() === currentValue ) {
                            $tierSelect.val( '' );
                        }
                    }
                } );
            };

            $typeSelect.on( 'change', updateTierOptions );
            
            // Initial state.
            updateTierOptions();
        }
    };

    // Initialize on document ready.
    $( document ).ready( function() {
        MetaBoxes.init();
    } );

} )( jQuery, wp );
