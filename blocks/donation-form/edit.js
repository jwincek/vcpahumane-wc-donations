( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, TextControl, ToggleControl, SelectControl, RangeControl, __experimentalNumberControl: NumberControl } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

    const blockData = window.starterShelterBlocks || {};

    const Edit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Form Settings', 'starter-shelter' ), initialOpen: true },
                    el( TextControl, {
                        label: __( 'Title', 'starter-shelter' ),
                        value: attributes.title || '',
                        onChange: function( value ) { setAttributes( { title: value } ); },
                    } ),
                    el( TextControl, {
                        label: __( 'Subtitle', 'starter-shelter' ),
                        value: attributes.subtitle || '',
                        onChange: function( value ) { setAttributes( { subtitle: value } ); },
                    } ),
                    el( TextControl, {
                        label: __( 'Submit Button Text', 'starter-shelter' ),
                        value: attributes.submitButtonText || '',
                        onChange: function( value ) { setAttributes( { submitButtonText: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Amount Options', 'starter-shelter' ), initialOpen: false },
                    el( TextControl, {
                        label: __( 'Preset Amounts (comma-separated)', 'starter-shelter' ),
                        value: ( attributes.presetAmounts || [] ).join( ', ' ),
                        onChange: function( value ) {
                            var amounts = value.split( ',' ).map( function( n ) {
                                return parseInt( n.trim(), 10 );
                            } ).filter( function( n ) { return ! isNaN( n ) && n > 0; } );
                            setAttributes( { presetAmounts: amounts } );
                        },
                        help: __( 'Example: 25, 50, 100, 250, 500', 'starter-shelter' ),
                    } ),
                    NumberControl ? el( NumberControl, {
                        label: __( 'Default Amount', 'starter-shelter' ),
                        value: attributes.defaultAmount,
                        onChange: function( value ) { setAttributes( { defaultAmount: parseInt( value, 10 ) || 50 } ); },
                        min: 1,
                    } ) : el( TextControl, {
                        label: __( 'Default Amount', 'starter-shelter' ),
                        type: 'number',
                        value: attributes.defaultAmount,
                        onChange: function( value ) { setAttributes( { defaultAmount: parseInt( value, 10 ) || 50 } ); },
                    } ),
                    NumberControl ? el( NumberControl, {
                        label: __( 'Minimum Amount', 'starter-shelter' ),
                        value: attributes.minAmount,
                        onChange: function( value ) { setAttributes( { minAmount: parseInt( value, 10 ) || 1 } ); },
                        min: 1,
                    } ) : null,
                    NumberControl ? el( NumberControl, {
                        label: __( 'Maximum Amount', 'starter-shelter' ),
                        value: attributes.maxAmount,
                        onChange: function( value ) { setAttributes( { maxAmount: parseInt( value, 10 ) || 100000 } ); },
                        min: 1,
                    } ) : null
                ),
                el( PanelBody, { title: __( 'Display Options', 'starter-shelter' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Show Allocation Selector', 'starter-shelter' ),
                        checked: attributes.showAllocation,
                        onChange: function( value ) { setAttributes( { showAllocation: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Anonymous Option', 'starter-shelter' ),
                        checked: attributes.showAnonymous,
                        onChange: function( value ) { setAttributes( { showAnonymous: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Secure Badge', 'starter-shelter' ),
                        checked: attributes.showSecureBadge,
                        onChange: function( value ) { setAttributes( { showSecureBadge: value } ); },
                    } )
                ),
                blockData.campaigns && blockData.campaigns.length > 1 ? el( PanelBody, { title: __( 'Campaign', 'starter-shelter' ), initialOpen: false },
                    el( SelectControl, {
                        label: __( 'Link to Campaign', 'starter-shelter' ),
                        value: attributes.campaignId || 0,
                        options: blockData.campaigns,
                        onChange: function( value ) { setAttributes( { campaignId: parseInt( value, 10 ) || null } ); },
                    } )
                ) : null
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'starter-shelter/donation-form',
                    attributes: attributes,
                } )
            )
        );
    };

    wp.blocks.registerBlockType( 'starter-shelter/donation-form', {
        edit: Edit,
    } );
} )( window.wp );
