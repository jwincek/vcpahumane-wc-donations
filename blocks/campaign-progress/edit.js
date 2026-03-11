( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, ToggleControl, SelectControl, RangeControl, ColorPicker, Placeholder, __experimentalNumberControl: NumberControl } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

    const blockData = window.starterShelterBlocks || {};

    const Edit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Campaign', 'starter-shelter' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Select Campaign', 'starter-shelter' ),
                        value: attributes.campaignId || 0,
                        options: blockData.campaigns || [ { value: 0, label: __( '— Select Campaign —', 'starter-shelter' ) } ],
                        onChange: function( value ) { setAttributes( { campaignId: parseInt( value, 10 ) || null } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Display Options', 'starter-shelter' ), initialOpen: false },
                    el( SelectControl, {
                        label: __( 'Layout', 'starter-shelter' ),
                        value: attributes.layout || 'horizontal',
                        options: [
                            { value: 'horizontal', label: __( 'Horizontal', 'starter-shelter' ) },
                            { value: 'vertical', label: __( 'Vertical', 'starter-shelter' ) },
                            { value: 'compact', label: __( 'Compact', 'starter-shelter' ) },
                        ],
                        onChange: function( value ) { setAttributes( { layout: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Title', 'starter-shelter' ),
                        checked: attributes.showTitle !== false,
                        onChange: function( value ) { setAttributes( { showTitle: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Description', 'starter-shelter' ),
                        checked: attributes.showDescription === true,
                        onChange: function( value ) { setAttributes( { showDescription: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Count', 'starter-shelter' ),
                        checked: attributes.showDonorCount !== false,
                        onChange: function( value ) { setAttributes( { showDonorCount: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show End Date', 'starter-shelter' ),
                        checked: attributes.showEndDate !== false,
                        onChange: function( value ) { setAttributes( { showEndDate: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Percentage', 'starter-shelter' ),
                        checked: attributes.showPercentage !== false,
                        onChange: function( value ) { setAttributes( { showPercentage: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Progress Bar', 'starter-shelter' ), initialOpen: false },
                    el( RangeControl, {
                        label: __( 'Bar Height (px)', 'starter-shelter' ),
                        value: attributes.progressBarHeight || 24,
                        onChange: function( value ) { setAttributes( { progressBarHeight: value } ); },
                        min: 8,
                        max: 48,
                    } )
                ),
                el( PanelBody, { title: __( 'Advanced', 'starter-shelter' ), initialOpen: false },
                    NumberControl ? el( NumberControl, {
                        label: __( 'Auto-Refresh Interval (seconds)', 'starter-shelter' ),
                        value: attributes.refreshInterval || 0,
                        onChange: function( value ) { setAttributes( { refreshInterval: parseInt( value, 10 ) || 0 } ); },
                        min: 0,
                        help: __( 'Set to 0 to disable. Recommended: 30-60 seconds.', 'starter-shelter' ),
                    } ) : null
                )
            ),
            el( 'div', blockProps,
                attributes.campaignId
                    ? el( ServerSideRender, {
                        block: 'starter-shelter/campaign-progress',
                        attributes: attributes,
                    } )
                    : el( Placeholder, {
                        icon: 'chart-line',
                        label: __( 'Campaign Progress', 'starter-shelter' ),
                    }, __( 'Select a campaign in the sidebar.', 'starter-shelter' ) )
            )
        );
    };

    wp.blocks.registerBlockType( 'starter-shelter/campaign-progress', {
        edit: Edit,
    } );
} )( window.wp );
