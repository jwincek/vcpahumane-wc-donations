/**
 * Memorial Wall Block — Editor Component
 *
 * No-build approach using wp.* globals.
 * Uses ServerSideRender for live preview in the editor.
 *
 * @package Starter_Shelter
 * @since   2.1.0
 */

( function( wp ) {
    const { createElement: el, Fragment } = wp.element;
    const {
        InspectorControls,
        BlockControls,
        useBlockProps,
    } = wp.blockEditor;
    const {
        PanelBody,
        TextControl,
        ToggleControl,
        SelectControl,
        RangeControl,
        ToolbarGroup,
        ToolbarButton,
        Placeholder,
        Tip,
    } = wp.components;
    const { __ } = wp.i18n;
    const { useSelect } = wp.data;
    const ServerSideRender = wp.serverSideRender;

    // Layout icons as SVG paths.
    const layoutIcons = {
        grid: el( 'svg', { viewBox: '0 0 24 24', width: 24, height: 24 },
            el( 'path', { fill: 'currentColor', d: 'M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h6v6h-6v-6z' } )
        ),
        masonry: el( 'svg', { viewBox: '0 0 24 24', width: 24, height: 24 },
            el( 'path', { fill: 'currentColor', d: 'M4 4h6v8H4V4zm10 0h6v4h-6V4zM4 14h6v6H4v-6zm10 6h6v-8h-6v8z' } )
        ),
        list: el( 'svg', { viewBox: '0 0 24 24', width: 24, height: 24 },
            el( 'path', { fill: 'currentColor', d: 'M4 5h16v3H4V5zm0 5h16v3H4v-3zm0 5h16v3H4v-3z' } )
        ),
    };

    const Edit = function( props ) {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps();

        // Check if memorials exist.
        const hasMemorials = useSelect( function( select ) {
            const posts = select( 'core' ).getEntityRecords( 'postType', 'sd_memorial', {
                per_page: 1,
                status: 'publish',
            } );
            // null means still loading, empty array means no posts.
            return posts === null ? null : posts.length > 0;
        }, [] );

        const currentLayout = attributes.layout || 'grid';

        return el( Fragment, {},

            // ─── Block Toolbar ─────────────────────────────────────────
            el( BlockControls, {},
                el( ToolbarGroup, {},
                    el( ToolbarButton, {
                        icon: layoutIcons.grid,
                        title: __( 'Grid Layout', 'starter-shelter' ),
                        isPressed: currentLayout === 'grid',
                        onClick: function() { setAttributes( { layout: 'grid' } ); },
                    } ),
                    el( ToolbarButton, {
                        icon: layoutIcons.masonry,
                        title: __( 'Masonry Layout', 'starter-shelter' ),
                        isPressed: currentLayout === 'masonry',
                        onClick: function() { setAttributes( { layout: 'masonry' } ); },
                    } ),
                    el( ToolbarButton, {
                        icon: layoutIcons.list,
                        title: __( 'List Layout', 'starter-shelter' ),
                        isPressed: currentLayout === 'list',
                        onClick: function() { setAttributes( { layout: 'list' } ); },
                    } )
                )
            ),

            // ─── Inspector Controls ────────────────────────────────────
            el( InspectorControls, {},

                // Quick tip.
                el( 'div', { style: { padding: '0 16px 16px' } },
                    el( Tip, {},
                        __( 'Visitors can search by honoree name or donor name. Use the toolbar to quickly switch layouts.', 'starter-shelter' )
                    )
                ),

                // Layout panel.
                el( PanelBody, {
                    title: __( 'Layout', 'starter-shelter' ),
                    initialOpen: true,
                },
                    el( SelectControl, {
                        label: __( 'Layout Style', 'starter-shelter' ),
                        value: attributes.layout || 'grid',
                        options: [
                            { value: 'grid',    label: __( 'Grid', 'starter-shelter' ) },
                            { value: 'masonry', label: __( 'Masonry', 'starter-shelter' ) },
                            { value: 'list',    label: __( 'List', 'starter-shelter' ) },
                        ],
                        onChange: function( val ) { setAttributes( { layout: val } ); },
                        help: __( 'Grid: uniform cards. Masonry: variable heights. List: single column.', 'starter-shelter' ),
                    } ),
                    currentLayout !== 'list' && el( RangeControl, {
                        label: __( 'Columns', 'starter-shelter' ),
                        value: attributes.columns || 3,
                        onChange: function( val ) { setAttributes( { columns: val } ); },
                        min: 1,
                        max: 6,
                        help: __( 'Number of columns on desktop. Adjusts responsively on smaller screens.', 'starter-shelter' ),
                    } ),
                    el( SelectControl, {
                        label: __( 'Card Style', 'starter-shelter' ),
                        value: attributes.cardStyle || 'elevated',
                        options: [
                            { value: 'elevated', label: __( 'Elevated (Shadow)', 'starter-shelter' ) },
                            { value: 'flat',     label: __( 'Flat', 'starter-shelter' ) },
                            { value: 'bordered', label: __( 'Bordered', 'starter-shelter' ) },
                        ],
                        onChange: function( val ) { setAttributes( { cardStyle: val } ); },
                    } )
                ),

                // Content panel.
                el( PanelBody, {
                    title: __( 'Content', 'starter-shelter' ),
                    initialOpen: false,
                },
                    el( RangeControl, {
                        label: __( 'Items Per Page', 'starter-shelter' ),
                        value: attributes.perPage || 12,
                        onChange: function( val ) { setAttributes( { perPage: val } ); },
                        min: 1,
                        max: 50,
                    } ),
                    el( SelectControl, {
                        label: __( 'Default Type Filter', 'starter-shelter' ),
                        value: attributes.defaultType || 'all',
                        options: [
                            { value: 'all',    label: __( 'All', 'starter-shelter' ) },
                            { value: 'person', label: __( 'People', 'starter-shelter' ) },
                            { value: 'pet',    label: __( 'Pets', 'starter-shelter' ) },
                        ],
                        onChange: function( val ) { setAttributes( { defaultType: val } ); },
                        help: __( 'Which memorials to show when the page first loads.', 'starter-shelter' ),
                    } ),
                    el( RangeControl, {
                        label: __( 'Truncate Tribute (characters)', 'starter-shelter' ),
                        value: attributes.truncateTribute || 100,
                        onChange: function( val ) { setAttributes( { truncateTribute: val } ); },
                        min: 50,
                        max: 500,
                        help: __( 'Maximum characters shown in card. Full text visible on single memorial page.', 'starter-shelter' ),
                    } )
                ),

                // Display options panel.
                el( PanelBody, {
                    title: __( 'Display Options', 'starter-shelter' ),
                    initialOpen: false,
                },
                    el( ToggleControl, {
                        label: __( 'Show Search', 'starter-shelter' ),
                        checked: attributes.showSearch !== false,
                        onChange: function( val ) { setAttributes( { showSearch: val } ); },
                        help: __( 'Allow visitors to search tributes by honoree or donor name.', 'starter-shelter' ),
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Filters', 'starter-shelter' ),
                        checked: attributes.showFilters !== false,
                        onChange: function( val ) { setAttributes( { showFilters: val } ); },
                    } ),
                    // Only show year filter toggle if filters are enabled.
                    attributes.showFilters !== false && el( ToggleControl, {
                        label: __( 'Show Year Filter', 'starter-shelter' ),
                        checked: attributes.showYearFilter !== false,
                        onChange: function( val ) { setAttributes( { showYearFilter: val } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Pagination', 'starter-shelter' ),
                        checked: attributes.showPagination !== false,
                        onChange: function( val ) { setAttributes( { showPagination: val } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Images', 'starter-shelter' ),
                        checked: attributes.showImage !== false,
                        onChange: function( val ) { setAttributes( { showImage: val } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Donor Name', 'starter-shelter' ),
                        checked: attributes.showDonorName !== false,
                        onChange: function( val ) { setAttributes( { showDonorName: val } ); },
                    } ),
                    el( ToggleControl, {
                        label: __( 'Show Date', 'starter-shelter' ),
                        checked: attributes.showDate !== false,
                        onChange: function( val ) { setAttributes( { showDate: val } ); },
                    } )
                ),

                // Pagination panel.
                el( PanelBody, {
                    title: __( 'Pagination', 'starter-shelter' ),
                    initialOpen: false,
                },
                    el( SelectControl, {
                        label: __( 'Pagination Style', 'starter-shelter' ),
                        value: attributes.paginationStyle || 'paged',
                        options: [
                            { value: 'paged',     label: __( 'Previous / Next', 'starter-shelter' ) },
                            { value: 'load-more', label: __( 'Load More Button', 'starter-shelter' ) },
                        ],
                        onChange: function( val ) { setAttributes( { paginationStyle: val } ); },
                        help: __(
                            'Previous/Next updates the page via smooth navigation. Load More appends items without page reload.',
                            'starter-shelter'
                        ),
                    } )
                ),

                // Advanced panel.
                el( PanelBody, {
                    title: __( 'Advanced', 'starter-shelter' ),
                    initialOpen: false,
                },
                    el( TextControl, {
                        label: __( 'Empty Message', 'starter-shelter' ),
                        value: attributes.emptyMessage || '',
                        onChange: function( val ) { setAttributes( { emptyMessage: val } ); },
                        help: __( 'Custom message when no memorials match. Default: "No tributes found."', 'starter-shelter' ),
                    } )
                )
            ),

            // ─── Block preview ─────────────────────────────────────────
            el( 'div', blockProps,
                // Show placeholder if no memorials exist yet.
                hasMemorials === false
                    ? el( Placeholder, {
                        icon: el( 'svg', { viewBox: '0 0 24 24', width: 48, height: 48 },
                            el( 'path', {
                                fill: 'currentColor',
                                d: 'M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z'
                            } )
                        ),
                        label: __( 'Memorial Wall', 'starter-shelter' ),
                        instructions: __( 'No memorial tributes have been created yet. Once donors submit tributes through the Memorial Form block or WooCommerce checkout, they will appear here.', 'starter-shelter' ),
                    } )
                    : el( ServerSideRender, {
                        block: 'starter-shelter/memorial-wall',
                        attributes: attributes,
                    } )
            )
        );
    };

    wp.blocks.registerBlockType( 'starter-shelter/memorial-wall', {
        edit: Edit,
    } );
} )( window.wp );
