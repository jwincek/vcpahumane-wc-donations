/**
 * Memorials Store — Unified
 *
 * Single Interactivity store for the Memorial Wall block.
 *
 * Architecture:
 * - Instance state lives in data-wp-context (set by render.php).
 * - Context is split: mutable state at root, immutable config in ctx.config.
 * - Derived getters use getContext() — multi-instance safe.
 * - Filter/pagination changes use @wordpress/interactivity-router navigate()
 *   so the server re-renders HTML inside the data-wp-router-region.
 * - "Load More" mode uses REST + append when paginationStyle = 'load-more'.
 * - Search uses an immediate reactive update (context.filters.search)
 *   with debounced navigation via a data-wp-watch callback.
 * - Highlighting is applied client-side via a data-wp-watch callback
 *   that reads the search term and applies <mark> tags via DOM manipulation.
 *
 * @package Starter_Shelter
 * @since   2.1.0
 */

import { store, getContext, getElement, withSyncEvent } from '@wordpress/interactivity';

/**
 * Monotonically increasing navigation counter.
 * When a new navigation starts, the previous one is considered stale and
 * its finally{} block skips resetting isLoading (the new nav owns that).
 *
 * @type {number}
 */
let navCounter = 0;


const { state, actions } = store( 'starter-shelter/memorials', {

    state: {
        /**
         * Derived: are results empty (and not loading)?
         */
        get isEmpty() {
            const ctx = getContext();
            return ! ctx.isLoading && ( ! ctx.items || ctx.items.length === 0 );
        },

        /**
         * Derived: has more pages to load.
         */
        get hasMore() {
            const ctx = getContext();
            return ( ctx.page ?? 0 ) < ( ctx.totalPages ?? 0 );
        },

        /**
         * Derived: has a previous page.
         */
        get hasPrev() {
            const ctx = getContext();
            return ( ctx.page ?? 0 ) > 1;
        },

        /**
         * Derived: has a next page.
         */
        get hasNext() {
            const ctx = getContext();
            return ( ctx.page ?? 0 ) < ( ctx.totalPages ?? 0 );
        },

        /**
         * Derived: total pages (used in template binding).
         */
        get totalPages() {
            const ctx = getContext();
            return ctx.totalPages ?? 0;
        },

        /**
         * Derived: should the paged pagination nav be visible?
         *
         * The Interactivity API directive processor only supports path
         * references and ! negation — no inline comparison expressions.
         */
        get showPagedPagination() {
            const ctx = getContext();
            return ( ctx.totalPages ?? 0 ) > 1;
        },

        /**
         * Derived: any active filter?
         */
        get hasActiveFilters() {
            const ctx = getContext();
            const f   = ctx.filters ?? {};
            return !!( f.search || ( f.type && f.type !== 'all' ) || f.year );
        },

        /**
         * Derived: human-readable results summary.
         */
        get resultsText() {
            const ctx = getContext();
            if ( ctx.isLoading && ( ! ctx.items || ctx.items.length === 0 ) ) {
                return 'Loading…';
            }
            const t = ctx.total ?? 0;
            return t === 1 ? '1 memorial' : `${ t } memorials`;
        },

        /**
         * Derived: pagination info for load-more mode.
         */
        get paginationInfo() {
            const ctx     = getContext();
            const showing = ctx.items?.length ?? 0;
            return `Showing ${ showing } of ${ ctx.total ?? 0 }`;
        },

        /**
         * Derived: previous page URL.
         */
        get prevPageUrl() {
            const ctx = getContext();
            if ( ( ctx.page ?? 0 ) <= 1 ) return '';
            return buildFilterUrl( ctx, ctx.page - 1 );
        },

        /**
         * Derived: next page URL.
         */
        get nextPageUrl() {
            const ctx = getContext();
            if ( ( ctx.page ?? 0 ) >= ( ctx.totalPages ?? 0 ) ) return '';
            return buildFilterUrl( ctx, ctx.page + 1 );
        },

        /**
         * Derived: truncated tribute for the current item in data-wp-each.
         */
        get truncatedTribute() {
            const ctx  = getContext();
            const text = ctx.item?.tribute_message ?? '';
            if ( ! text ) return '';

            const max = ctx.config?.truncateLength || 100;
            if ( text.length <= max ) return text;

            const cut       = text.substring( 0, max );
            const lastSpace = cut.lastIndexOf( ' ' );
            return ( lastSpace > max * 0.7 ? cut.substring( 0, lastSpace ) : cut ) + '…';
        },

        /**
         * Derived: type badge label for the current item.
         */
        get typeBadgeLabel() {
            const ctx = getContext();
            return ctx.item?.memorial_type === 'pet' ? 'Pet' : 'Person';
        },

        /**
         * Derived: is the current item a person memorial?
         */
        get isTypePerson() {
            const ctx = getContext();
            return ctx.item?.memorial_type === 'person';
        },

        /**
         * Derived: is the current item a pet memorial?
         */
        get isTypePet() {
            const ctx = getContext();
            return ctx.item?.memorial_type === 'pet';
        },
    },

    actions: {
        /**
         * Handle filter <select> changes.
         *
         * Reads `name` from the element to derive the filter key, resets
         * page to 1, and navigates via the Interactivity router.
         */
        handleFilterChange: function* ( event ) {
            const ctx       = getContext();
            const filterKey = event.target.name.replace( 'memorial-', '' );

            ctx.filters = {
                ...ctx.filters,
                [ filterKey ]: event.target.value,
            };
            ctx.page = 1;

            yield* doNavigate( ctx );
        },

        /**
         * Submit search — reads the input value and navigates.
         *
         * Triggered by the search button click. Reads the input's
         * current value directly from the DOM (no reactive binding
         * needed), updates context, and navigates.
         */
        submitSearch: function* () {
            const ctx     = getContext();
            const { ref } = getElement();
            const region  = ref?.closest( '[data-wp-router-region]' );
            const input   = region?.querySelector( '.sd-search-input' );
            const value   = input?.value ?? '';

            ctx.filters = { ...ctx.filters, search: value };
            ctx.page    = 1;

            yield* doNavigate( ctx );
        },

        /**
         * Handle Enter key in the search input.
         *
         * Submits the search on Enter. Wrapped in withSyncEvent
         * to call preventDefault synchronously and prevent form
         * submission if the block is inside a <form>.
         */
        handleSearchKeydown: withSyncEvent( function* ( event ) {
            if ( event.key !== 'Enter' ) return;
            event.preventDefault();

            const ctx   = getContext();
            const value = event.target.value ?? '';

            ctx.filters = { ...ctx.filters, search: value };
            ctx.page    = 1;

            yield* doNavigate( ctx );
        } ),

        /**
         * Clear all active filters and navigate to the unfiltered URL.
         *
         * Also clears the search input directly since it no longer has
         * a data-wp-bind--value binding (removed to fix iOS composition).
         */
        clearFilters: function* () {
            const ctx    = getContext();
            ctx.filters  = { type: 'all', year: '', search: '' };
            ctx.page     = 1;

            // Clear the search input DOM element directly.
            const { ref } = getElement();
            const region  = ref?.closest( '[data-wp-router-region]' );
            const input   = region?.querySelector( '.sd-search-input' );
            if ( input ) input.value = '';

            yield* doNavigate( ctx );
        },

        /**
         * Handle pagination link clicks (paged mode).
         *
         * Reads the target page from the href to stay URL-consistent.
         * Wrapped in withSyncEvent() because we need event.preventDefault().
         */
        handlePagination: withSyncEvent( function* ( event ) {
            event.preventDefault();

            const { ref } = getElement();
            const href    = ref.getAttribute( 'href' );

            if ( ! href || ref.getAttribute( 'aria-disabled' ) === 'true' ) {
                return;
            }

            const url     = new URL( href, window.location.origin );
            const newPage = parseInt( url.searchParams.get( 'memorial-page' ) || '1', 10 );
            const ctx     = getContext();

            ctx.page = newPage;
            yield* doNavigate( ctx );
        } ),

        /**
         * Load More button (load-more mode).
         *
         * Increments page, fetches via the Abilities REST endpoint,
         * and appends items to the context array.
         */
        loadMore: function* () {
            const ctx = getContext();
            if ( ctx.isLoading || ctx.page >= ctx.totalPages ) return;

            ctx.page     += 1;
            ctx.isLoading = true;

            try {
                const filters = ctx.filters || {};
                const input   = {
                    page:     ctx.page,
                    per_page: ctx.config?.perPage || 12,
                };

                if ( filters.type && filters.type !== 'all' ) input.type = filters.type;
                if ( filters.year )   input.year   = parseInt( filters.year, 10 );
                if ( filters.search ) input.search = filters.search;

                // Abilities API REST endpoint (WordPress 6.9).
                // Namespace: wp-abilities/v1, route: /abilities/{name}/run.
                const restRoot = wpApiSettings?.root ?? '/wp-json/';
                const response = yield fetch(
                    `${ restRoot }wp-abilities/v1/abilities/shelter-memorials%2Flist/run`,
                    {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce':  wpApiSettings?.nonce ?? '',
                        },
                        body: JSON.stringify( { input } ),
                    }
                );

                if ( ! response.ok ) throw new Error( `HTTP ${ response.status }` );

                const data = yield response.json();

                // The Abilities REST endpoint returns the callback's output
                // directly, or wrapped in an 'output' key.
                const result = data.output ?? data;
                ctx.items = [ ...ctx.items, ...( result.items || [] ) ];

            } catch ( error ) {
                ctx.page -= 1; // Rollback on failure.
                console.error( 'Memorial Wall: Load more failed', error );
            } finally {
                ctx.isLoading = false;
            }
        },
    },

    callbacks: {
        /**
         * Client-side search highlighting.
         *
         * Bound via data-wp-watch on the grid container. Reads
         * context.filters.search and context.items to create reactive
         * dependencies on both the search term and the rendered cards.
         * After each change, walks the DOM and wraps matching text
         * in <mark> tags.
         *
         * Runs in a requestAnimationFrame to ensure the DOM has been
         * updated by data-wp-text before we manipulate innerHTML.
         */
        applyHighlights() {
            const ctx    = getContext();
            const search = ( ctx.filters?.search ?? '' ).trim();
            // Read items to create a reactive dep — when items change
            // (after navigation), this callback re-runs.
            const items  = ctx.items;

            const { ref } = getElement();
            if ( ! ref ) return;

            // Schedule after the current reactive flush so data-wp-text
            // has written its textContent before we read it.
            requestAnimationFrame( () => {
                // Highlightable elements — honoree, tribute, donor spans.
                const targets = ref.querySelectorAll(
                    '.sd-memorial-name, .sd-memorial-tribute, .sd-donor span'
                );

                if ( ! search ) {
                    // Clear any existing highlights.
                    for ( const el of targets ) {
                        const marks = el.querySelectorAll( 'mark.sd-search-highlight' );
                        if ( marks.length ) {
                            el.textContent = el.textContent;
                        }
                    }
                    return;
                }

                const safeSearch = search.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
                const regex      = new RegExp( `(${ safeSearch })`, 'gi' );

                for ( const el of targets ) {
                    // Read textContent (safe, no HTML injection).
                    const text = el.textContent;
                    if ( ! text || ! regex.test( text ) ) continue;

                    // Reset lastIndex (regex with 'g' flag is stateful).
                    regex.lastIndex = 0;

                    // Build highlighted HTML from escaped text.
                    const escaped = text
                        .replace( /&/g, '&amp;' )
                        .replace( /</g, '&lt;' )
                        .replace( />/g, '&gt;' );

                    el.innerHTML = escaped.replace(
                        regex,
                        '<mark class="sd-search-highlight">$1</mark>'
                    );
                }
            } );
        },
    },
} );

// ─── Private helpers ──────────────────────────────────────────────────

/**
 * Build a URL with current filter parameters.
 *
 * Derives from the current window.location (not a stored baseUrl) so that
 * non-memorial query params added by other blocks or plugins are preserved.
 * Then strips only the memorial-* params and re-adds the current values.
 *
 * @param {Object} ctx  - The block context.
 * @param {number} page - Target page number.
 * @return {string} Absolute URL.
 */
function buildFilterUrl( ctx, page = 1 ) {
    const url = new URL( window.location.href );

    // Strip all memorial-specific params (we'll re-add what's needed).
    url.searchParams.delete( 'memorial-page' );
    url.searchParams.delete( 'memorial-type' );
    url.searchParams.delete( 'memorial-year' );
    url.searchParams.delete( 'memorial-search' );

    if ( page > 1 ) {
        url.searchParams.set( 'memorial-page', page );
    }

    const f = ctx.filters ?? {};
    if ( f.type && f.type !== 'all' ) {
        url.searchParams.set( 'memorial-type', f.type );
    }
    if ( f.year ) {
        url.searchParams.set( 'memorial-year', f.year );
    }
    if ( f.search ) {
        url.searchParams.set( 'memorial-search', f.search );
    }

    return url.toString();
}

/**
 * Navigate to the filtered URL via the Interactivity Router.
 *
 * Uses a monotonic counter to detect stale navigations: if a second
 * navigate fires while the first is in-flight, the first's finally{}
 * block detects the counter has advanced and skips resetting isLoading.
 *
 * @param {Object} ctx - The block context.
 */
function* doNavigate( ctx ) {
    const myNavId = ++navCounter;
    ctx.isLoading = true;

    try {
        const { actions: routerActions } = yield import(
            '@wordpress/interactivity-router'
        );

        const newUrl = buildFilterUrl( ctx, ctx.page );

        yield routerActions.navigate( newUrl, { force: false } );

        // Smooth-scroll to the top of this archive.
        const region = document.querySelector(
            `[data-wp-router-region="${ ctx.archiveId }"]`
        );
        if ( region ) {
            region.scrollIntoView( { behavior: 'smooth', block: 'start' } );
        }
    } catch ( error ) {
        console.error( 'Memorial Wall: Navigation failed, falling back', error );
        // Hard navigation as fallback.
        window.location.href = buildFilterUrl( ctx, ctx.page );
    } finally {
        // Only reset isLoading if we're still the latest navigation.
        // If a newer navigation started, it owns isLoading now.
        if ( navCounter === myNavId ) {
            ctx.isLoading = false;
        }
    }
}


export { state, actions };
