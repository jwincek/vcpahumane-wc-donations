<?php
/**
 * Memorial Wall Block — Server-side render.
 *
 * WordPress 6.9 Interactivity API patterns:
 * - data-wp-router-region for client-side navigation
 * - data-wp-context for instance-scoped state
 * - data-wp-each with server-rendered fallback children
 * - Ability-backed data fetching
 *
 * @package    Starter_Shelter
 * @subpackage Blocks
 * @since      2.1.0
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

declare( strict_types = 1 );

// ─── Ensure the router is in the import map ──────────────────────────
// The memorials.js store dynamically imports @wordpress/interactivity-router.
// For the dynamic import to resolve, the router must be in the browser's
// import map. WordPress only adds modules to the import map if they're
// enqueued or are dependencies of enqueued modules.
//
// Our 'starter-shelter/memorials' module is registered with the router as
// a dynamic dependency. By enqueueing it here, WordPress will:
// 1. Add the router to the import map (so dynamic imports resolve)
// 2. NOT modulepreload the router (because it's marked 'dynamic')
//
// The view.js also imports memorials.js via relative URL for editor
// compatibility, but this enqueue ensures the import map is correct.
wp_enqueue_script_module( 'starter-shelter/memorials' );

// ─── Block attributes ────────────────────────────────────────────────
$archive_id       = $attributes['archiveId'] ?: wp_unique_id( 'sd-wall-' );
$per_page         = (int) ( $attributes['perPage'] ?? 12 );
$columns          = (int) ( $attributes['columns'] ?? 3 );
$show_search      = $attributes['showSearch'] ?? true;
$show_filters     = $attributes['showFilters'] ?? true;
$show_pagination  = $attributes['showPagination'] ?? true;
$show_year_filter = $attributes['showYearFilter'] ?? true;
$default_type     = $attributes['defaultType'] ?? 'all';
$pagination_style = $attributes['paginationStyle'] ?? 'paged';
$layout           = $attributes['layout'] ?? 'grid';
$card_style       = $attributes['cardStyle'] ?? 'elevated';
$show_donor       = $attributes['showDonorName'] ?? true;
$show_date        = $attributes['showDate'] ?? true;
$show_image       = $attributes['showImage'] ?? true;
$truncate_length  = (int) ( $attributes['truncateTribute'] ?? 100 );
$empty_message    = $attributes['emptyMessage'] ?: __( 'No memorials found.', 'starter-shelter' );

// ─── URL parameters (for SSR + bookmarkable URLs) ────────────────────
$current_page = max( 1, absint( $_GET['memorial-page'] ?? 1 ) );
$search_term  = sanitize_text_field( $_GET['memorial-search'] ?? '' );
$type_filter  = sanitize_key( $_GET['memorial-type'] ?? $default_type );
$year_filter  = sanitize_text_field( $_GET['memorial-year'] ?? '' );

$has_active_filters = $search_term || ( $type_filter && 'all' !== $type_filter ) || $year_filter;

// ─── Fetch data via Ability ──────────────────────────────────────────
$ability = function_exists( 'wp_get_ability' )
    ? wp_get_ability( 'shelter-memorials/list' )
    : null;

if ( $ability ) {
    $result = $ability->execute( [
        'type'     => $type_filter,
        'year'     => $year_filter ? (int) $year_filter : null,
        'search'   => $search_term ?: null,
        'page'     => $current_page,
        'per_page' => $per_page,
    ] );

    if ( is_wp_error( $result ) ) {
        $result = [ 'items' => [], 'total' => 0, 'total_pages' => 0, 'page' => 1 ];
    }
} else {
    // Fallback: Query builder (for environments without Abilities API).
    $query = \Starter_Shelter\Core\Query::for( 'sd_memorial' )
        ->orderBy( 'donation_date', 'DESC' );

    if ( 'all' !== $type_filter ) {
        $query->where( 'memorial_type', $type_filter );
    }
    if ( $year_filter ) {
        $query->whereInTaxonomy( 'sd_memorial_year', $year_filter, 'slug' );
    }
    if ( $search_term ) {
        $query->searchMultiple( [ 'honoree_name', 'donor_display_name' ], $search_term );
    }

    $result = $query->paginate( $current_page, $per_page );
}

$items       = $result['items'] ?? [];
$total       = (int) ( $result['total'] ?? 0 );
$total_pages = (int) ( $result['total_pages'] ?? 0 );

// ─── Available years for filter dropdown ─────────────────────────────
$years = [];
if ( $show_year_filter ) {
    global $wpdb;
    $years = $wpdb->get_col( "
        SELECT DISTINCT YEAR( pm.meta_value ) AS y
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_donation_date'
        WHERE p.post_type = 'sd_memorial'
          AND p.post_status = 'publish'
        ORDER BY y DESC
    " );
}

// ─── Base URL for filter navigation ──────────────────────────────────
$base_url = remove_query_arg(
    [ 'memorial-page', 'memorial-type', 'memorial-year', 'memorial-search' ]
);

// ─── Pagination URLs (server-rendered for no-JS) ─────────────────────
$prev_url = '';
$next_url = '';

if ( $current_page > 1 ) {
    $prev_params = array_filter( [
        'memorial-page'   => $current_page - 1 > 1 ? $current_page - 1 : null,
        'memorial-type'   => 'all' !== $type_filter ? $type_filter : null,
        'memorial-year'   => $year_filter ?: null,
        'memorial-search' => $search_term ?: null,
    ] );
    $prev_url = add_query_arg( $prev_params, $base_url );
}

if ( $current_page < $total_pages ) {
    $next_params = array_filter( [
        'memorial-page'   => $current_page + 1,
        'memorial-type'   => 'all' !== $type_filter ? $type_filter : null,
        'memorial-year'   => $year_filter ?: null,
        'memorial-search' => $search_term ?: null,
    ] );
    $next_url = add_query_arg( $next_params, $base_url );
}

// ─── Context (instance-scoped, replaces state.archives[id]) ──────────
// Split into config (immutable render-time values) and mutable state.
// Config values don't change after render so they're in a sub-object
// to make intent clear and keep the mutable surface small.
$context = [
    // Instance identity.
    'archiveId'       => $archive_id,

    // Mutable listing state — changes on filter/paginate/load-more.
    'items'           => $items,
    'total'           => $total,
    'totalPages'      => $total_pages,
    'page'            => $current_page,
    'isLoading'       => false,

    // Mutable filter state — changes on user interaction.
    'filters'         => [
        'type'   => $type_filter,
        'year'   => $year_filter,
        'search' => $search_term,
    ],

    // Render config — set once at render time, read-only after that.
    'config'          => [
        'baseUrl'         => $base_url,
        'perPage'         => $per_page,
        'paginationStyle' => $pagination_style,
        'truncateLength'  => $truncate_length,
        'showDonor'       => $show_donor,
        'showDate'        => $show_date,
        'showImage'       => $show_image,
        'columns'         => $columns,
        'years'           => array_map( 'strval', $years ),
    ],
];

// ─── Wrapper attributes ──────────────────────────────────────────────
$classes = [
    'sd-memorial-wall',
    "sd-layout--{$layout}",
    "sd-style--{$card_style}",
];

$wrapper_attributes = get_block_wrapper_attributes( [
    'class'            => implode( ' ', $classes ),
    'id'               => $archive_id,
    'data-sd-columns'  => (int) $columns,
] );
?>

<div
    <?php echo $wrapper_attributes; ?>
    data-wp-interactive="starter-shelter/memorials"
    <?php echo wp_interactivity_data_wp_context( $context ); ?>
    data-wp-router-region="<?php echo esc_attr( $archive_id ); ?>"
    data-wp-key="<?php echo esc_attr( $archive_id ); ?>"
>

    <?php // ─── Controls: Search + Filters ─────────────────────────── ?>
    <?php if ( $show_search || $show_filters ) : ?>
    <div class="sd-memorial-controls" role="search" aria-label="<?php esc_attr_e( 'Filter memorials', 'starter-shelter' ); ?>">

        <?php if ( $show_search ) : ?>
        <div class="sd-search-box">
            <input
                type="search"
                class="sd-search-input"
                name="memorial-search"
                placeholder="<?php esc_attr_e( 'Search tributes…', 'starter-shelter' ); ?>"
                value="<?php echo esc_attr( $search_term ); ?>"
                data-wp-on--keydown="actions.handleSearchKeydown"
            >
            <button
                type="button"
                class="sd-search-button"
                data-wp-on--click="actions.submitSearch"
                aria-label="<?php esc_attr_e( 'Search', 'starter-shelter' ); ?>"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
                </svg>
            </button>
        </div>
        <?php endif; ?>

        <?php if ( $show_filters ) : ?>
        <div class="sd-filters">

            <select
                name="memorial-type"
                class="sd-filter-select"
                data-wp-bind--value="context.filters.type"
                data-wp-on--change="actions.handleFilterChange"
            >
                <option value="all" <?php selected( $type_filter, 'all' ); ?>><?php esc_html_e( 'All Types', 'starter-shelter' ); ?></option>
                <option value="person" <?php selected( $type_filter, 'person' ); ?>><?php esc_html_e( 'People', 'starter-shelter' ); ?></option>
                <option value="pet" <?php selected( $type_filter, 'pet' ); ?>><?php esc_html_e( 'Pets', 'starter-shelter' ); ?></option>
            </select>

            <?php if ( $show_year_filter && ! empty( $years ) ) : ?>
            <select
                name="memorial-year"
                class="sd-filter-select"
                data-wp-bind--value="context.filters.year"
                data-wp-on--change="actions.handleFilterChange"
            >
                <option value=""><?php esc_html_e( 'All Years', 'starter-shelter' ); ?></option>
                <?php foreach ( $years as $year ) : ?>
                <option value="<?php echo esc_attr( $year ); ?>" <?php selected( $year_filter, $year ); ?>>
                    <?php echo esc_html( $year ); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <button
                type="button"
                class="sd-clear-filters <?php echo $has_active_filters ? '' : 'sd-clear-filters--hidden'; ?>"
                data-wp-on--click="actions.clearFilters"
                data-wp-class--sd-clear-filters--hidden="!state.hasActiveFilters"
                data-wp-bind--aria-hidden="!state.hasActiveFilters"
            >
                <?php esc_html_e( 'Clear', 'starter-shelter' ); ?>
            </button>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <?php // ─── Results summary ────────────────────────────────────── ?>
    <div class="sd-memorial-count" aria-live="polite">
        <span data-wp-text="state.resultsText">
            <?php
            printf(
                /* translators: %d: number of memorials */
                _n( '%d memorial', '%d memorials', $total, 'starter-shelter' ),
                $total
            );
            ?>
        </span>
    </div>

    <?php // ─── Grid ───────────────────────────────────────────────── ?>
    <div
        class="sd-memorial-grid"
        role="list"
        aria-label="<?php esc_attr_e( 'Memorials', 'starter-shelter' ); ?>"
        data-wp-class--is-loading="context.isLoading"
        data-wp-watch--highlight="callbacks.applyHighlights"
    >
        <?php // Loading overlay — hidden by default, shown when isLoading is true ?>
        <div
            class="sd-loading-overlay"
            hidden
            data-wp-bind--hidden="!context.isLoading"
            aria-hidden="true"
        >
            <span class="sd-spinner"></span>
            <?php esc_html_e( 'Loading…', 'starter-shelter' ); ?>
        </div>

        <?php // ─── Client-side template (data-wp-each) ─────────── ?>
        <template data-wp-each="context.items" data-wp-each-key="context.item.id">
            <article class="sd-memorial-card" role="listitem">
                <a class="sd-memorial-link" data-wp-bind--href="context.item.permalink">

                    <?php if ( $show_image ) : ?>
                    <div class="sd-memorial-image" data-wp-bind--hidden="!context.item.photo_url">
                        <img
                            data-wp-bind--src="context.item.photo_url"
                            data-wp-bind--alt="context.item.honoree_name"
                            loading="lazy"
                        >
                        <span
                            class="sd-type-badge"
                            data-wp-class--type-person="state.isTypePerson"
                            data-wp-class--type-pet="state.isTypePet"
                        >
                            <svg class="sd-badge-icon sd-icon-heart" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                            </svg>
                            <svg class="sd-badge-icon sd-icon-paw" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M4.5 12.5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5-2.5-1.12-2.5-2.5zm10-2.5c1.38 0 2.5 1.12 2.5 2.5s-1.12 2.5-2.5 2.5-2.5-1.12-2.5-2.5 1.12-2.5 2.5-2.5zM5 7c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm6-1c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm8 1c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zM12 19c-2.76 0-5-1.79-5-4s2.24-4 5-4 5 1.79 5 4-2.24 4-5 4z"/>
                            </svg>
                            <span class="sd-badge-text" data-wp-text="state.typeBadgeLabel"></span>
                        </span>
                    </div>
                    <?php endif; ?>

                    <div class="sd-memorial-content">
                        <span
                            class="sd-type-badge sd-type-badge--inline"
                            data-wp-class--type-person="state.isTypePerson"
                            data-wp-class--type-pet="state.isTypePet"
                            <?php if ( $show_image ) : ?>
                            data-wp-bind--hidden="context.item.photo_url"
                            <?php endif; ?>
                        >
                            <svg class="sd-badge-icon sd-icon-heart" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                            </svg>
                            <svg class="sd-badge-icon sd-icon-paw" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M4.5 12.5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5-2.5-1.12-2.5-2.5zm10-2.5c1.38 0 2.5 1.12 2.5 2.5s-1.12 2.5-2.5 2.5-2.5-1.12-2.5-2.5 1.12-2.5 2.5-2.5zM5 7c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm6-1c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm8 1c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zM12 19c-2.76 0-5-1.79-5-4s2.24-4 5-4 5 1.79 5 4-2.24 4-5 4z"/>
                            </svg>
                            <span class="sd-badge-text" data-wp-text="state.typeBadgeLabel"></span>
                        </span>
                        <h3 class="sd-memorial-name" data-wp-text="context.item.honoree_name"></h3>
                        <p class="sd-memorial-tribute" data-wp-text="state.truncatedTribute"></p>

                        <footer class="sd-memorial-meta">
                            <?php if ( $show_donor ) : ?>
                            <span class="sd-donor">
                                <svg class="sd-meta-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                </svg>
                                <span data-wp-text="context.item.donor_name"></span>
                            </span>
                            <?php endif; ?>
                            <?php if ( $show_date ) : ?>
                            <time data-wp-bind--datetime="context.item.donation_date">
                                <svg class="sd-meta-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm-8 4H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z"/>
                                </svg>
                                <span data-wp-text="context.item.date_formatted"></span>
                            </time>
                            <?php endif; ?>
                        </footer>
                    </div>

                </a>
            </article>
        </template>

        <?php // ─── Server-rendered fallback children ────────────── ?>
        <?php foreach ( $items as $item ) :
            $is_pet   = 'pet' === ( $item['memorial_type'] ?? '' );
            $has_photo = ! empty( $item['photo_url'] );
            $tribute   = ! empty( $item['tribute_message'] )
                ? wp_trim_words( $item['tribute_message'], 15, '…' )
                : '';
        ?>
        <article class="sd-memorial-card" role="listitem" data-wp-each-child>
            <a href="<?php echo esc_url( get_permalink( $item['id'] ) ); ?>" class="sd-memorial-link">

                <?php if ( $show_image && $has_photo ) : ?>
                <div class="sd-memorial-image">
                    <img
                        src="<?php echo esc_url( $item['photo_url'] ); ?>"
                        alt="<?php echo esc_attr( $item['honoree_name'] ?? '' ); ?>"
                        loading="lazy"
                    >
                    <span class="sd-type-badge type-<?php echo esc_attr( $item['memorial_type'] ?? '' ); ?>">
                        <svg class="sd-badge-icon sd-icon-heart" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                        </svg>
                        <svg class="sd-badge-icon sd-icon-paw" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M4.5 12.5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5-2.5-1.12-2.5-2.5zm10-2.5c1.38 0 2.5 1.12 2.5 2.5s-1.12 2.5-2.5 2.5-2.5-1.12-2.5-2.5 1.12-2.5 2.5-2.5zM5 7c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm6-1c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm8 1c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zM12 19c-2.76 0-5-1.79-5-4s2.24-4 5-4 5 1.79 5 4-2.24 4-5 4z"/>
                        </svg>
                        <span class="sd-badge-text"><?php echo $is_pet ? esc_html__( 'Pet', 'starter-shelter' ) : esc_html__( 'Person', 'starter-shelter' ); ?></span>
                    </span>
                </div>
                <?php endif; ?>

                <div class="sd-memorial-content">
                    <?php if ( ! $show_image || ! $has_photo ) : ?>
                    <span class="sd-type-badge sd-type-badge--inline type-<?php echo esc_attr( $item['memorial_type'] ?? '' ); ?>">
                        <svg class="sd-badge-icon sd-icon-heart" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                        </svg>
                        <svg class="sd-badge-icon sd-icon-paw" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M4.5 12.5c0-1.38 1.12-2.5 2.5-2.5s2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5-2.5-1.12-2.5-2.5zm10-2.5c1.38 0 2.5 1.12 2.5 2.5s-1.12 2.5-2.5 2.5-2.5-1.12-2.5-2.5 1.12-2.5 2.5-2.5zM5 7c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm6-1c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm8 1c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zM12 19c-2.76 0-5-1.79-5-4s2.24-4 5-4 5 1.79 5 4-2.24 4-5 4z"/>
                        </svg>
                        <span class="sd-badge-text"><?php echo $is_pet ? esc_html__( 'Pet', 'starter-shelter' ) : esc_html__( 'Person', 'starter-shelter' ); ?></span>
                    </span>
                    <?php endif; ?>
                    <h3 class="sd-memorial-name"><?php echo esc_html( $item['honoree_name'] ?? '' ); ?></h3>
                    <?php if ( $tribute ) : ?>
                    <p class="sd-memorial-tribute"><?php echo esc_html( $tribute ); ?></p>
                    <?php endif; ?>

                    <footer class="sd-memorial-meta">
                        <?php if ( $show_donor ) : ?>
                        <span class="sd-donor">
                            <svg class="sd-meta-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                            </svg>
                            <?php echo esc_html( $item['donor_name'] ?? __( 'A Friend', 'starter-shelter' ) ); ?>
                        </span>
                        <?php endif; ?>
                        <?php if ( $show_date ) : ?>
                        <time datetime="<?php echo esc_attr( $item['donation_date'] ?? '' ); ?>">
                            <svg class="sd-meta-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm-8 4H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z"/>
                            </svg>
                            <?php echo esc_html( $item['date_formatted'] ?? '' ); ?>
                        </time>
                        <?php endif; ?>
                    </footer>
                </div>

            </a>
        </article>
        <?php endforeach; ?>

        <?php // ─── Empty state ──────────────────────────────────── ?>
        <?php if ( empty( $items ) ) : ?>
        <div class="sd-empty-state" data-wp-bind--hidden="!state.isEmpty">
            <svg viewBox="0 0 24 24" fill="currentColor" class="sd-empty-icon" aria-hidden="true">
                <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
            </svg>
            <p><?php echo esc_html( $empty_message ); ?></p>
            <?php if ( $search_term || 'all' !== $type_filter || $year_filter ) : ?>
            <a href="<?php echo esc_url( $base_url ); ?>" class="sd-clear-filters wp-element-button">
                <?php esc_html_e( 'Clear Filters', 'starter-shelter' ); ?>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php // ─── Pagination ─────────────────────────────────────────── ?>
    <?php if ( $show_pagination && $total_pages > 1 ) : ?>

    <?php if ( 'load-more' === $pagination_style ) : ?>
    <?php // ─── Load More style ──────────────────────────────────── ?>
    <nav class="sd-pagination sd-pagination--load-more" data-wp-bind--hidden="!state.hasMore">
        <button
            type="button"
            class="sd-load-more wp-element-button"
            data-wp-on--click="actions.loadMore"
            data-wp-bind--disabled="context.isLoading"
        >
            <span data-wp-bind--hidden="context.isLoading">
                <?php esc_html_e( 'Load More', 'starter-shelter' ); ?>
            </span>
            <span data-wp-bind--hidden="!context.isLoading">
                <span class="sd-spinner"></span>
                <?php esc_html_e( 'Loading…', 'starter-shelter' ); ?>
            </span>
        </button>
        <p class="sd-pagination-info" data-wp-text="state.paginationInfo"></p>
    </nav>

    <?php else : ?>
    <?php // ─── Paged style ──────────────────────────────────────── ?>
    <nav
        class="sd-pagination sd-pagination--paged"
        aria-label="<?php esc_attr_e( 'Memorial pagination', 'starter-shelter' ); ?>"
        data-wp-bind--hidden="!state.showPagedPagination"
    >
        <a
            href="<?php echo esc_url( $prev_url ?: '#' ); ?>"
            class="sd-pagination-link sd-pagination-prev wp-element-button"
            <?php echo $current_page <= 1 ? 'aria-disabled="true"' : ''; ?>
            data-wp-bind--href="state.prevPageUrl"
            data-wp-bind--aria-disabled="!state.hasPrev"
            data-wp-on--click="actions.handlePagination"
        >
            <span aria-hidden="true">←</span>
            <?php esc_html_e( 'Previous', 'starter-shelter' ); ?>
        </a>

        <span class="sd-pagination-info">
            <?php
            printf(
                /* translators: 1: current page, 2: total pages */
                esc_html__( 'Page %1$d of %2$d', 'starter-shelter' ),
                $current_page,
                $total_pages
            );
            ?>
        </span>

        <a
            href="<?php echo esc_url( $next_url ?: '#' ); ?>"
            class="sd-pagination-link sd-pagination-next wp-element-button"
            <?php echo $current_page >= $total_pages ? 'aria-disabled="true"' : ''; ?>
            data-wp-bind--href="state.nextPageUrl"
            data-wp-bind--aria-disabled="!state.hasNext"
            data-wp-on--click="actions.handlePagination"
        >
            <?php esc_html_e( 'Next', 'starter-shelter' ); ?>
            <span aria-hidden="true">→</span>
        </a>
    </nav>
    <?php endif; ?>

    <?php // ─── No-JS fallback ───────────────────────────────────── ?>
    <noscript>
        <nav class="sd-noscript-pagination">
            <?php
            echo paginate_links( [
                'total'   => $total_pages,
                'current' => $current_page,
                'format'  => '?memorial-page=%#%',
            ] );
            ?>
        </nav>
    </noscript>

    <?php endif; ?>
</div>
