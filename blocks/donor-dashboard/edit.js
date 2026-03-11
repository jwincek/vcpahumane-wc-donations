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
                el( PanelBody, { title: __( 'Display Options', 'starter-shelter' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Layout', 'starter-shelter' ),
                        value: attributes.layout || 'cards',
                        options: [
                            { value: 'cards', label: __( 'Cards', 'starter-shelter' ) },
                            { value: 'list', label: __( 'List', 'starter-shelter' ) },
                            { value: 'compact', label: __( 'Compact', 'starter-shelter' ) },
                        ],
                        onChange: function( value ) { setAttributes( { layout: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Stats', 'starter-shelter' ),
                        checked: attributes.showStats !== false,
                        onChange: function( value ) { setAttributes( { showStats: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Recent Gifts', 'starter-shelter' ),
                        checked: attributes.showRecentGifts !== false,
                        onChange: function( value ) { setAttributes( { showRecentGifts: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Membership', 'starter-shelter' ),
                        checked: attributes.showMembership !== false,
                        onChange: function( value ) { setAttributes( { showMembership: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Level', 'starter-shelter' ),
                        checked: attributes.showDonorLevel !== false,
                        onChange: function( value ) { setAttributes( { showDonorLevel: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Content', 'starter-shelter' ), initialOpen: false },
                    el( RangeControl, {
                        label: __( 'Recent Gifts Count', 'starter-shelter' ),
                        value: attributes.recentGiftsCount || 5,
                        onChange: function( value ) { setAttributes( { recentGiftsCount: value } ); },
                        min: 1,
                        max: 20,
                    } ),
                    el( TextControl, {
                        label: __( 'Guest Message', 'starter-shelter' ),
                        value: attributes.guestMessage || '',
                        onChange: function( value ) { setAttributes( { guestMessage: value } ); },
                        help: __( 'Message shown to logged-out visitors.', 'starter-shelter' ),
                    } )
                )
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'starter-shelter/donor-dashboard',
                    attributes: attributes,
                } )
            )
        );
    };

    wp.blocks.registerBlockType( 'starter-shelter/donor-dashboard', {
        edit: Edit,
    } );
} )( window.wp );
