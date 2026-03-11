/**
 * Shelter Donations - Block Editor Scripts (No-Build)
 *
 * Provides ServerSideRender-based editing with InspectorControls
 * for all shelter donation blocks without requiring a build step.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

( function( wp ) {
    'use strict';

    const { registerBlockType, getBlockType } = wp.blocks;
    const { createElement: el, Fragment } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const {
        PanelBody,
        TextControl,
        NumberControl,
        ToggleControl,
        SelectControl,
        RangeControl,
        ColorPicker,
        Placeholder,
        Spinner,
    } = wp.components;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;

    // Get localized data.
    const blockData = window.starterShelterBlocks || {};

    /* ==========================================================================
       Donation Form Block
       ========================================================================== */

    const donationFormEdit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Form Settings', 'starter-shelter' ), initialOpen: true },
                    el( TextControl, {
                        label: __( 'Title', 'starter-shelter' ),
                        value: attributes.title,
                        onChange: function( value ) { setAttributes( { title: value } ); },
                    } ),
                    el( TextControl, {
                        label: __( 'Subtitle', 'starter-shelter' ),
                        value: attributes.subtitle,
                        onChange: function( value ) { setAttributes( { subtitle: value } ); },
                    } ),
                    el( TextControl, {
                        label: __( 'Submit Button Text', 'starter-shelter' ),
                        value: attributes.submitButtonText,
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
                            } ).filter( function( n ) { return ! isNaN( n ); } );
                            setAttributes( { presetAmounts: amounts } );
                        },
                        help: __( 'Example: 25, 50, 100, 250, 500', 'starter-shelter' ),
                    } ),
                    el( NumberControl, {
                        label: __( 'Default Amount', 'starter-shelter' ),
                        value: attributes.defaultAmount,
                        onChange: function( value ) { setAttributes( { defaultAmount: parseInt( value, 10 ) } ); },
                        min: 1,
                    } ),
                    el( NumberControl, {
                        label: __( 'Minimum Amount', 'starter-shelter' ),
                        value: attributes.minAmount,
                        onChange: function( value ) { setAttributes( { minAmount: parseInt( value, 10 ) } ); },
                        min: 1,
                    } ),
                    el( NumberControl, {
                        label: __( 'Maximum Amount', 'starter-shelter' ),
                        value: attributes.maxAmount,
                        onChange: function( value ) { setAttributes( { maxAmount: parseInt( value, 10 ) } ); },
                        min: 1,
                    } )
                ),
                el( PanelBody, { title: __( 'Display Options', 'starter-shelter' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Show Allocation Selector', 'starter-shelter' ),
                        checked: attributes.showAllocation,
                        onChange: function( value ) { setAttributes( { showAllocation: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Dedication Field', 'starter-shelter' ),
                        checked: attributes.showDedication,
                        onChange: function( value ) { setAttributes( { showDedication: value } ); },
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
                el( PanelBody, { title: __( 'Campaign', 'starter-shelter' ), initialOpen: false },
                    el( SelectControl, {
                        label: __( 'Link to Campaign', 'starter-shelter' ),
                        value: attributes.campaignId || 0,
                        options: blockData.campaigns || [],
                        onChange: function( value ) { setAttributes( { campaignId: parseInt( value, 10 ) || null } ); },
                    } )
                )
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'starter-shelter/donation-form',
                    attributes: attributes,
                    EmptyResponsePlaceholder: function() {
                        return el( Placeholder, {
                            icon: 'money-alt',
                            label: __( 'Donation Form', 'starter-shelter' ),
                        }, __( 'Configure the form settings in the sidebar.', 'starter-shelter' ) );
                    },
                    LoadingResponsePlaceholder: function() {
                        return el( Placeholder, {
                            icon: 'money-alt',
                            label: __( 'Donation Form', 'starter-shelter' ),
                        }, el( Spinner ) );
                    },
                } )
            )
        );
    };

    // Re-register with edit function (block.json handles the rest).
    wp.domReady( function() {
        var block = getBlockType( 'starter-shelter/donation-form' );
        if ( block && ! block.edit ) {
            wp.blocks.unregisterBlockType( 'starter-shelter/donation-form' );
            registerBlockType( 'starter-shelter/donation-form', Object.assign( {}, block, {
                edit: donationFormEdit,
            } ) );
        }
    } );

    /* ==========================================================================
       Memorial Wall Block
       ========================================================================== */

    const memorialWallEdit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Layout', 'starter-shelter' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Layout Style', 'starter-shelter' ),
                        value: attributes.layout,
                        options: [
                            { value: 'grid', label: __( 'Grid', 'starter-shelter' ) },
                            { value: 'masonry', label: __( 'Masonry', 'starter-shelter' ) },
                            { value: 'list', label: __( 'List', 'starter-shelter' ) },
                        ],
                        onChange: function( value ) { setAttributes( { layout: value } ); },
                    } ),
                    el( RangeControl, {
                        label: __( 'Columns', 'starter-shelter' ),
                        value: attributes.columns,
                        onChange: function( value ) { setAttributes( { columns: value } ); },
                        min: 1,
                        max: 6,
                    } ),
                    el( SelectControl, {
                        label: __( 'Card Style', 'starter-shelter' ),
                        value: attributes.cardStyle,
                        options: [
                            { value: 'elevated', label: __( 'Elevated (Shadow)', 'starter-shelter' ) },
                            { value: 'flat', label: __( 'Flat', 'starter-shelter' ) },
                            { value: 'bordered', label: __( 'Bordered', 'starter-shelter' ) },
                        ],
                        onChange: function( value ) { setAttributes( { cardStyle: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Content', 'starter-shelter' ), initialOpen: false },
                    el( NumberControl, {
                        label: __( 'Items Per Page', 'starter-shelter' ),
                        value: attributes.perPage,
                        onChange: function( value ) { setAttributes( { perPage: parseInt( value, 10 ) } ); },
                        min: 1,
                        max: 50,
                    } ),
                    el( SelectControl, {
                        label: __( 'Default Type Filter', 'starter-shelter' ),
                        value: attributes.defaultType,
                        options: [
                            { value: 'all', label: __( 'All', 'starter-shelter' ) },
                            { value: 'human', label: __( 'People', 'starter-shelter' ) },
                            { value: 'pet', label: __( 'Pets', 'starter-shelter' ) },
                        ],
                        onChange: function( value ) { setAttributes( { defaultType: value } ); },
                    } ),
                    el( NumberControl, {
                        label: __( 'Truncate Tribute (characters)', 'starter-shelter' ),
                        value: attributes.truncateTribute,
                        onChange: function( value ) { setAttributes( { truncateTribute: parseInt( value, 10 ) } ); },
                        min: 50,
                        max: 500,
                    } )
                ),
                el( PanelBody, { title: __( 'Display Options', 'starter-shelter' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Show Search', 'starter-shelter' ),
                        checked: attributes.showSearch,
                        onChange: function( value ) { setAttributes( { showSearch: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Filters', 'starter-shelter' ),
                        checked: attributes.showFilters,
                        onChange: function( value ) { setAttributes( { showFilters: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Year Filter', 'starter-shelter' ),
                        checked: attributes.showYearFilter,
                        onChange: function( value ) { setAttributes( { showYearFilter: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Pagination', 'starter-shelter' ),
                        checked: attributes.showPagination,
                        onChange: function( value ) { setAttributes( { showPagination: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Images', 'starter-shelter' ),
                        checked: attributes.showImage,
                        onChange: function( value ) { setAttributes( { showImage: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Name', 'starter-shelter' ),
                        checked: attributes.showDonorName,
                        onChange: function( value ) { setAttributes( { showDonorName: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Date', 'starter-shelter' ),
                        checked: attributes.showDate,
                        onChange: function( value ) { setAttributes( { showDate: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Advanced', 'starter-shelter' ), initialOpen: false },
                    el( ToggleControl, {
                        label: __( 'Enable Router Navigation', 'starter-shelter' ),
                        checked: attributes.enableRouterNavigation,
                        onChange: function( value ) { setAttributes( { enableRouterNavigation: value } ); },
                        help: __( 'SPA-like navigation without full page reloads.', 'starter-shelter' ),
                    } ),
                    el( TextControl, {
                        label: __( 'Empty Message', 'starter-shelter' ),
                        value: attributes.emptyMessage,
                        onChange: function( value ) { setAttributes( { emptyMessage: value } ); },
                    } )
                )
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'starter-shelter/memorial-wall',
                    attributes: attributes,
                    EmptyResponsePlaceholder: function() {
                        return el( Placeholder, {
                            icon: 'heart',
                            label: __( 'Memorial Wall', 'starter-shelter' ),
                        }, __( 'No memorials found. Add some memorial donations first.', 'starter-shelter' ) );
                    },
                    LoadingResponsePlaceholder: function() {
                        return el( Placeholder, {
                            icon: 'heart',
                            label: __( 'Memorial Wall', 'starter-shelter' ),
                        }, el( Spinner ) );
                    },
                } )
            )
        );
    };

    wp.domReady( function() {
        var block = getBlockType( 'starter-shelter/memorial-wall' );
        if ( block && ! block.edit ) {
            wp.blocks.unregisterBlockType( 'starter-shelter/memorial-wall' );
            registerBlockType( 'starter-shelter/memorial-wall', Object.assign( {}, block, {
                edit: memorialWallEdit,
            } ) );
        }
    } );

    /* ==========================================================================
       Campaign Progress Block
       ========================================================================== */

    const campaignProgressEdit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Campaign', 'starter-shelter' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Select Campaign', 'starter-shelter' ),
                        value: attributes.campaignId || 0,
                        options: blockData.campaigns || [],
                        onChange: function( value ) { setAttributes( { campaignId: parseInt( value, 10 ) || null } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Display Options', 'starter-shelter' ), initialOpen: false },
                    el( SelectControl, {
                        label: __( 'Layout', 'starter-shelter' ),
                        value: attributes.layout,
                        options: [
                            { value: 'horizontal', label: __( 'Horizontal', 'starter-shelter' ) },
                            { value: 'vertical', label: __( 'Vertical', 'starter-shelter' ) },
                            { value: 'compact', label: __( 'Compact', 'starter-shelter' ) },
                        ],
                        onChange: function( value ) { setAttributes( { layout: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Title', 'starter-shelter' ),
                        checked: attributes.showTitle,
                        onChange: function( value ) { setAttributes( { showTitle: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Description', 'starter-shelter' ),
                        checked: attributes.showDescription,
                        onChange: function( value ) { setAttributes( { showDescription: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Count', 'starter-shelter' ),
                        checked: attributes.showDonorCount,
                        onChange: function( value ) { setAttributes( { showDonorCount: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show End Date', 'starter-shelter' ),
                        checked: attributes.showEndDate,
                        onChange: function( value ) { setAttributes( { showEndDate: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Percentage', 'starter-shelter' ),
                        checked: attributes.showPercentage,
                        onChange: function( value ) { setAttributes( { showPercentage: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Progress Bar', 'starter-shelter' ), initialOpen: false },
                    el( RangeControl, {
                        label: __( 'Bar Height (px)', 'starter-shelter' ),
                        value: attributes.progressBarHeight,
                        onChange: function( value ) { setAttributes( { progressBarHeight: value } ); },
                        min: 8,
                        max: 48,
                    } ),
                    el( 'div', { style: { marginBottom: '16px' } },
                        el( 'label', { style: { display: 'block', marginBottom: '8px' } },
                            __( 'Progress Bar Color', 'starter-shelter' )
                        ),
                        el( ColorPicker, {
                            color: attributes.progressBarColor,
                            onChangeComplete: function( value ) { setAttributes( { progressBarColor: value.hex } ); },
                        } )
                    )
                ),
                el( PanelBody, { title: __( 'Advanced', 'starter-shelter' ), initialOpen: false },
                    el( NumberControl, {
                        label: __( 'Auto-Refresh Interval (seconds)', 'starter-shelter' ),
                        value: attributes.refreshInterval,
                        onChange: function( value ) { setAttributes( { refreshInterval: parseInt( value, 10 ) } ); },
                        min: 0,
                        help: __( 'Set to 0 to disable. Recommended: 30-60 seconds.', 'starter-shelter' ),
                    } )
                )
            ),
            el( 'div', blockProps,
                attributes.campaignId
                    ? el( ServerSideRender, {
                        block: 'starter-shelter/campaign-progress',
                        attributes: attributes,
                        LoadingResponsePlaceholder: function() {
                            return el( Placeholder, {
                                icon: 'chart-line',
                                label: __( 'Campaign Progress', 'starter-shelter' ),
                            }, el( Spinner ) );
                        },
                    } )
                    : el( Placeholder, {
                        icon: 'chart-line',
                        label: __( 'Campaign Progress', 'starter-shelter' ),
                    }, __( 'Select a campaign in the sidebar.', 'starter-shelter' ) )
            )
        );
    };

    wp.domReady( function() {
        var block = getBlockType( 'starter-shelter/campaign-progress' );
        if ( block && ! block.edit ) {
            wp.blocks.unregisterBlockType( 'starter-shelter/campaign-progress' );
            registerBlockType( 'starter-shelter/campaign-progress', Object.assign( {}, block, {
                edit: campaignProgressEdit,
            } ) );
        }
    } );

    /* ==========================================================================
       Donor Dashboard Block
       ========================================================================== */

    const donorDashboardEdit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        return el( Fragment, {},
            el( InspectorControls, {},
                el( PanelBody, { title: __( 'Display Options', 'starter-shelter' ), initialOpen: true },
                    el( SelectControl, {
                        label: __( 'Layout', 'starter-shelter' ),
                        value: attributes.layout,
                        options: [
                            { value: 'cards', label: __( 'Cards', 'starter-shelter' ) },
                            { value: 'list', label: __( 'List', 'starter-shelter' ) },
                            { value: 'compact', label: __( 'Compact', 'starter-shelter' ) },
                        ],
                        onChange: function( value ) { setAttributes( { layout: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Stats', 'starter-shelter' ),
                        checked: attributes.showStats,
                        onChange: function( value ) { setAttributes( { showStats: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Recent Gifts', 'starter-shelter' ),
                        checked: attributes.showRecentGifts,
                        onChange: function( value ) { setAttributes( { showRecentGifts: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Membership', 'starter-shelter' ),
                        checked: attributes.showMembership,
                        onChange: function( value ) { setAttributes( { showMembership: value } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Level', 'starter-shelter' ),
                        checked: attributes.showDonorLevel,
                        onChange: function( value ) { setAttributes( { showDonorLevel: value } ); },
                    } )
                ),
                el( PanelBody, { title: __( 'Content', 'starter-shelter' ), initialOpen: false },
                    el( NumberControl, {
                        label: __( 'Recent Gifts Count', 'starter-shelter' ),
                        value: attributes.recentGiftsCount,
                        onChange: function( value ) { setAttributes( { recentGiftsCount: parseInt( value, 10 ) } ); },
                        min: 1,
                        max: 20,
                    } ),
                    el( TextControl, {
                        label: __( 'Guest Message', 'starter-shelter' ),
                        value: attributes.guestMessage,
                        onChange: function( value ) { setAttributes( { guestMessage: value } ); },
                        help: __( 'Message shown to logged-out visitors.', 'starter-shelter' ),
                    } )
                )
            ),
            el( 'div', blockProps,
                el( ServerSideRender, {
                    block: 'starter-shelter/donor-dashboard',
                    attributes: attributes,
                    LoadingResponsePlaceholder: function() {
                        return el( Placeholder, {
                            icon: 'id-alt',
                            label: __( 'Donor Dashboard', 'starter-shelter' ),
                        }, el( Spinner ) );
                    },
                } )
            )
        );
    };

    wp.domReady( function() {
        var block = getBlockType( 'starter-shelter/donor-dashboard' );
        if ( block && ! block.edit ) {
            wp.blocks.unregisterBlockType( 'starter-shelter/donor-dashboard' );
            registerBlockType( 'starter-shelter/donor-dashboard', Object.assign( {}, block, {
                edit: donorDashboardEdit,
            } ) );
        }
    } );

} )( window.wp );
