( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, TextControl, ToggleControl, SelectControl, RangeControl } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

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
                el( PanelBody, { title: __( 'Membership Type', 'starter-shelter' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Default Type', 'starter-shelter' ),
                        value: attributes.membershipType || 'individual',
                        options: [
                            { value: 'individual', label: __( 'Individual', 'starter-shelter' ) },
                            { value: 'business', label: __( 'Business / Sponsor', 'starter-shelter' ) },
                        ],
                        onChange: function( value ) { setAttributes( { membershipType: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Type Toggle', 'starter-shelter' ),
                        help: __( 'Allow users to switch between individual and business.', 'starter-shelter' ),
                        checked: attributes.showTypeToggle,
                        onChange: function( value ) { setAttributes( { showTypeToggle: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Layout', 'starter-shelter' ), initialOpen: false },
                    el( SelectControl, {
                        label: __( 'Tier Display', 'starter-shelter' ),
                        value: attributes.layout || 'cards',
                        options: [
                            { value: 'cards', label: __( 'Cards', 'starter-shelter' ) },
                            { value: 'table', label: __( 'Comparison Table', 'starter-shelter' ) },
                            { value: 'list', label: __( 'Simple List', 'starter-shelter' ) },
                        ],
                        onChange: function( value ) { setAttributes( { layout: value } ); },
                    } ),
                    el( RangeControl, {
                        label: __( 'Columns (Cards)', 'starter-shelter' ),
                        value: attributes.columns || 3,
                        onChange: function( value ) { setAttributes( { columns: value } ); },
                        min: 1,
                        max: 4,
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Benefits List', 'starter-shelter' ),
                        checked: attributes.showBenefits,
                        onChange: function( value ) { setAttributes( { showBenefits: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Options', 'starter-shelter' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Show Anonymous Option', 'starter-shelter' ),
                        checked: attributes.showAnonymous,
                        onChange: function( value ) { setAttributes( { showAnonymous: value } ); },
                    } )
                )
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'starter-shelter/membership-form',
                    attributes: attributes,
                } )
            )
        );
    };

    wp.blocks.registerBlockType( 'starter-shelter/membership-form', {
        edit: Edit,
    } );
} )( window.wp );
