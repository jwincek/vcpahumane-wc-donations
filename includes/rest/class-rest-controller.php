<?php
/**
 * REST API Controller for Shelter Donations.
 *
 * Provides additional REST endpoints beyond auto-generated ability endpoints.
 *
 * @package Starter_Shelter
 * @subpackage REST
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\REST;

use Starter_Shelter\Core\{ Config, Entity_Hydrator, Query };
use Starter_Shelter\Helpers;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Register REST API routes.
 *
 * @since 1.0.0
 */
function register_routes(): void {
    $namespace = 'starter-shelter/v1';

    // Campaign endpoints.
    register_rest_route( $namespace, '/campaigns', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => __NAMESPACE__ . '\\get_campaigns',
            'permission_callback' => '__return_true',
        ],
    ] );

    register_rest_route( $namespace, '/campaign/(?P<id>\d+)', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => __NAMESPACE__ . '\\get_campaign',
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn( $param ) => is_numeric( $param ),
                ],
            ],
        ],
    ] );

    // Donor portal endpoints.
    register_rest_route( $namespace, '/donor/me', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => __NAMESPACE__ . '\\get_current_donor',
            'permission_callback' => 'is_user_logged_in',
        ],
    ] );

    register_rest_route( $namespace, '/donor/me/summary', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => __NAMESPACE__ . '\\get_donor_summary',
            'permission_callback' => 'is_user_logged_in',
        ],
    ] );

    register_rest_route( $namespace, '/donor/me/donations', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => __NAMESPACE__ . '\\get_donor_donations',
            'permission_callback' => 'is_user_logged_in',
            'args'                => get_pagination_args(),
        ],
    ] );

    register_rest_route( $namespace, '/donor/me/memberships', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => __NAMESPACE__ . '\\get_donor_memberships',
            'permission_callback' => 'is_user_logged_in',
        ],
    ] );

    register_rest_route( $namespace, '/donor/me/memorials', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => __NAMESPACE__ . '\\get_donor_memorials',
            'permission_callback' => 'is_user_logged_in',
            'args'                => get_pagination_args(),
        ],
    ] );

    register_rest_route( $namespace, '/donor/me/statement/(?P<year>\d{4})', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => __NAMESPACE__ . '\\get_annual_statement',
            'permission_callback' => 'is_user_logged_in',
            'args'                => [
                'year' => [
                    'required'          => true,
                    'validate_callback' => fn( $param ) => is_numeric( $param ) && $param >= 2000 && $param <= (int) wp_date( 'Y' ),
                ],
            ],
        ],
    ] );

    // Public stats endpoint.
    register_rest_route( $namespace, '/stats', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => __NAMESPACE__ . '\\get_public_stats',
            'permission_callback' => '__return_true',
            'args'                => [
                'period' => [
                    'default'           => 'all_time',
                    'enum'              => [ 'all_time', 'this_year', 'this_month', 'fiscal_year' ],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ],
    ] );

    // Allocation options endpoint.
    register_rest_route( $namespace, '/allocations', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => __NAMESPACE__ . '\\get_allocations',
            'permission_callback' => '__return_true',
        ],
    ] );

    // Membership tiers endpoint.
    register_rest_route( $namespace, '/tiers', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => __NAMESPACE__ . '\\get_tiers',
            'permission_callback' => '__return_true',
            'args'                => [
                'type' => [
                    'default'           => 'individual',
                    'enum'              => [ 'individual', 'business' ],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ],
    ] );

    // Public memorials endpoint (for memorial wall).
    register_rest_route( $namespace, '/memorials', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => __NAMESPACE__ . '\\get_public_memorials',
            'permission_callback' => '__return_true',
            'args'                => array_merge(
                get_pagination_args(),
                [
                    'type' => [
                        'default'           => 'all',
                        'enum'              => [ 'all', 'human', 'pet' ],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'year' => [
                        'default'           => '',
                        'sanitize_callback' => 'absint',
                    ],
                    'search' => [
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ]
            ),
        ],
    ] );
}

/**
 * Get common pagination args.
 *
 * @since 1.0.0
 *
 * @return array Pagination argument definitions.
 */
function get_pagination_args(): array {
    return [
        'page'     => [
            'default'           => 1,
            'sanitize_callback' => 'absint',
        ],
        'per_page' => [
            'default'           => 10,
            'sanitize_callback' => fn( $value ) => min( 100, max( 1, absint( $value ) ) ),
        ],
    ];
}

/**
 * Get all active campaigns.
 *
 * @since 1.0.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response Response object.
 */
function get_campaigns( WP_REST_Request $request ): WP_REST_Response {
    $terms = get_terms( [
        'taxonomy'   => 'sd_campaign',
        'hide_empty' => false,
    ] );

    if ( is_wp_error( $terms ) ) {
        return new WP_REST_Response( [], 200 );
    }

    $campaigns = array_map( __NAMESPACE__ . '\\format_campaign', $terms );

    // Filter to active only.
    $campaigns = array_filter( $campaigns, fn( $c ) => $c['is_active'] );

    return new WP_REST_Response( array_values( $campaigns ), 200 );
}

/**
 * Get single campaign.
 *
 * @since 1.0.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function get_campaign( WP_REST_Request $request ) {
    $campaign_id = (int) $request->get_param( 'id' );
    $term = get_term( $campaign_id, 'sd_campaign' );

    if ( ! $term || is_wp_error( $term ) ) {
        return new WP_Error( 'not_found', __( 'Campaign not found.', 'starter-shelter' ), [ 'status' => 404 ] );
    }

    return new WP_REST_Response( format_campaign( $term ), 200 );
}

/**
 * Format campaign data.
 *
 * @since 1.0.0
 *
 * @param WP_Term $term Campaign term.
 * @return array Formatted campaign data.
 */
function format_campaign( \WP_Term $term ): array {
    global $wpdb;

    $goal = (float) get_term_meta( $term->term_id, '_sd_goal', true );
    $end_date = get_term_meta( $term->term_id, '_sd_end_date', true );

    // Calculate raised.
    $raised = (float) $wpdb->get_var( $wpdb->prepare( "
        SELECT COALESCE(SUM(pm.meta_value), 0)
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_amount'
        JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        WHERE p.post_type = 'sd_donation'
        AND p.post_status = 'publish'
        AND tr.term_taxonomy_id = %d
    ", $term->term_id ) );

    // Calculate donors.
    $donors = (int) $wpdb->get_var( $wpdb->prepare( "
        SELECT COUNT(DISTINCT pm.meta_value)
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_donor_id'
        JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        WHERE p.post_type = 'sd_donation'
        AND p.post_status = 'publish'
        AND tr.term_taxonomy_id = %d
    ", $term->term_id ) );

    $progress = $goal > 0 ? min( 100, ( $raised / $goal ) * 100 ) : 0;
    $is_active = ! $end_date || strtotime( $end_date ) >= time();

    return [
        'id'                  => $term->term_id,
        'name'                => $term->name,
        'slug'                => $term->slug,
        'description'         => $term->description,
        'goal'                => $goal,
        'goal_formatted'      => Helpers\format_currency( $goal ),
        'raised'              => $raised,
        'raised_formatted'    => Helpers\format_currency( $raised ),
        'remaining'           => max( 0, $goal - $raised ),
        'remaining_formatted' => Helpers\format_currency( max( 0, $goal - $raised ) ),
        'progress'            => round( $progress, 1 ),
        'progress_formatted'  => round( $progress, 1 ) . '%',
        'donor_count'         => $donors,
        'end_date'            => $end_date ?: null,
        'end_date_formatted'  => $end_date ? Helpers\format_date( $end_date ) : null,
        'days_remaining'      => $end_date && $is_active ? max( 0, (int) ( ( strtotime( $end_date ) - time() ) / DAY_IN_SECONDS ) ) : null,
        'is_active'           => $is_active,
    ];
}

/**
 * Get current user's donor profile.
 *
 * @since 1.0.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function get_current_donor( WP_REST_Request $request ) {
    $donor_id = get_donor_id_for_current_user();

    if ( ! $donor_id ) {
        return new WP_Error( 'no_donor', __( 'No donor profile found.', 'starter-shelter' ), [ 'status' => 404 ] );
    }

    $donor = Entity_Hydrator::get( 'sd_donor', $donor_id );

    if ( ! $donor ) {
        return new WP_Error( 'not_found', __( 'Donor not found.', 'starter-shelter' ), [ 'status' => 404 ] );
    }

    return new WP_REST_Response( $donor, 200 );
}

/**
 * Get current donor's summary statistics.
 *
 * @since 1.0.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function get_donor_summary( WP_REST_Request $request ) {
    $donor_id = get_donor_id_for_current_user();

    if ( ! $donor_id ) {
        return new WP_Error( 'no_donor', __( 'No donor profile found.', 'starter-shelter' ), [ 'status' => 404 ] );
    }

    // Use ability for summary.
    $ability = wp_get_ability( 'shelter-reports/donor-summary' );

    if ( $ability ) {
        $result = $ability->execute( [ 'donor_id' => $donor_id ] );

        if ( ! is_wp_error( $result ) ) {
            return new WP_REST_Response( $result, 200 );
        }
    }

    // Fallback calculation.
    global $wpdb;

    $this_year = wp_date( 'Y-01-01' );

    $summary = $wpdb->get_row( $wpdb->prepare( "
        SELECT 
            COUNT(*) as donation_count,
            COALESCE(SUM(pm.meta_value), 0) as lifetime_giving,
            COALESCE(SUM(CASE WHEN pmd.meta_value >= %s THEN pm.meta_value ELSE 0 END), 0) as ytd_giving
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_amount'
        JOIN {$wpdb->postmeta} pmd ON p.ID = pmd.post_id AND pmd.meta_key = '_sd_donation_date'
        JOIN {$wpdb->postmeta} pdi ON p.ID = pdi.post_id AND pdi.meta_key = '_sd_donor_id'
        WHERE p.post_type = 'sd_donation'
        AND p.post_status = 'publish'
        AND pdi.meta_value = %d
    ", $this_year, $donor_id ) );

    return new WP_REST_Response( [
        'donation_count'           => (int) $summary->donation_count,
        'lifetime_giving'          => (float) $summary->lifetime_giving,
        'lifetime_giving_formatted' => Helpers\format_currency( (float) $summary->lifetime_giving ),
        'ytd_giving'               => (float) $summary->ytd_giving,
        'ytd_giving_formatted'     => Helpers\format_currency( (float) $summary->ytd_giving ),
    ], 200 );
}

/**
 * Get current donor's donations.
 *
 * @since 1.0.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function get_donor_donations( WP_REST_Request $request ) {
    $donor_id = get_donor_id_for_current_user();

    if ( ! $donor_id ) {
        return new WP_Error( 'no_donor', __( 'No donor profile found.', 'starter-shelter' ), [ 'status' => 404 ] );
    }

    $result = Query::for( 'sd_donation' )
        ->where( 'donor_id', $donor_id )
        ->orderBy( 'donation_date', 'DESC' )
        ->paginate(
            (int) $request->get_param( 'page' ),
            (int) $request->get_param( 'per_page' )
        );

    return new WP_REST_Response( $result, 200 );
}

/**
 * Get current donor's memberships.
 *
 * @since 1.0.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function get_donor_memberships( WP_REST_Request $request ) {
    $donor_id = get_donor_id_for_current_user();

    if ( ! $donor_id ) {
        return new WP_Error( 'no_donor', __( 'No donor profile found.', 'starter-shelter' ), [ 'status' => 404 ] );
    }

    $memberships = Query::for( 'sd_membership' )
        ->where( 'donor_id', $donor_id )
        ->orderBy( 'start_date', 'DESC' )
        ->get();

    // Add tier details.
    $tiers_individual = Config::get_item( 'tiers', 'individual', [] );
    $tiers_business = Config::get_item( 'tiers', 'business', [] );

    foreach ( $memberships as &$membership ) {
        $tier_slug = $membership['tier'] ?? '';
        $type = $membership['membership_type'] ?? 'individual';
        $tiers = 'business' === $type ? $tiers_business : $tiers_individual;

        $membership['tier_details'] = $tiers[ $tier_slug ] ?? null;
    }

    return new WP_REST_Response( [
        'items' => $memberships,
        'total' => count( $memberships ),
    ], 200 );
}

/**
 * Get current donor's memorials.
 *
 * @since 1.0.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function get_donor_memorials( WP_REST_Request $request ) {
    $donor_id = get_donor_id_for_current_user();

    if ( ! $donor_id ) {
        return new WP_Error( 'no_donor', __( 'No donor profile found.', 'starter-shelter' ), [ 'status' => 404 ] );
    }

    $result = Query::for( 'sd_memorial' )
        ->where( 'donor_id', $donor_id )
        ->orderBy( 'donation_date', 'DESC' )
        ->paginate(
            (int) $request->get_param( 'page' ),
            (int) $request->get_param( 'per_page' )
        );

    // Add permalinks.
    foreach ( $result['items'] as &$item ) {
        $item['permalink'] = get_permalink( $item['id'] );
    }

    return new WP_REST_Response( $result, 200 );
}

/**
 * Get annual statement for current donor.
 *
 * @since 1.0.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function get_annual_statement( WP_REST_Request $request ) {
    $donor_id = get_donor_id_for_current_user();

    if ( ! $donor_id ) {
        return new WP_Error( 'no_donor', __( 'No donor profile found.', 'starter-shelter' ), [ 'status' => 404 ] );
    }

    $year = (int) $request->get_param( 'year' );

    // Use ability.
    $ability = wp_get_ability( 'shelter-reports/annual-statement' );

    if ( $ability ) {
        $result = $ability->execute( [
            'donor_id' => $donor_id,
            'year'     => $year,
        ] );

        if ( ! is_wp_error( $result ) ) {
            return new WP_REST_Response( $result, 200 );
        }
    }

    return new WP_Error( 'generation_failed', __( 'Failed to generate statement.', 'starter-shelter' ), [ 'status' => 500 ] );
}

/**
 * Get public statistics.
 *
 * @since 1.0.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response Response object.
 */
function get_public_stats( WP_REST_Request $request ): WP_REST_Response {
    $period = $request->get_param( 'period' );

    // Use ability.
    $ability = wp_get_ability( 'shelter-donations/get-stats' );

    if ( $ability ) {
        $result = $ability->execute( [ 'period' => $period ] );

        if ( ! is_wp_error( $result ) ) {
            return new WP_REST_Response( $result, 200 );
        }
    }

    // Fallback basic stats.
    global $wpdb;

    $total = (float) $wpdb->get_var( "
        SELECT COALESCE(SUM(pm.meta_value), 0)
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_amount'
        WHERE p.post_type = 'sd_donation'
        AND p.post_status = 'publish'
    " );

    $donors = (int) $wpdb->get_var( "
        SELECT COUNT(DISTINCT pm.meta_value)
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_donor_id'
        WHERE p.post_type = 'sd_donation'
        AND p.post_status = 'publish'
    " );

    return new WP_REST_Response( [
        'total_donations'          => $total,
        'total_donations_formatted' => Helpers\format_currency( $total ),
        'donor_count'              => $donors,
    ], 200 );
}

/**
 * Get allocation options.
 *
 * @since 1.0.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response Response object.
 */
function get_allocations( WP_REST_Request $request ): WP_REST_Response {
    $allocations = Config::get_item( 'settings', 'allocations', [] );

    if ( empty( $allocations ) ) {
        $allocations = [
            'general-fund'       => __( 'General Fund', 'starter-shelter' ),
            'medical-care'       => __( 'Medical Care', 'starter-shelter' ),
            'food-supplies'      => __( 'Food & Supplies', 'starter-shelter' ),
            'facility'           => __( 'Facility Improvements', 'starter-shelter' ),
            'rescue-operations'  => __( 'Rescue Operations', 'starter-shelter' ),
        ];
    }

    $result = [];
    foreach ( $allocations as $value => $label ) {
        $result[] = [
            'value' => $value,
            'label' => $label,
        ];
    }

    return new WP_REST_Response( $result, 200 );
}

/**
 * Get membership tiers.
 *
 * @since 1.0.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response Response object.
 */
function get_tiers( WP_REST_Request $request ): WP_REST_Response {
    $type = $request->get_param( 'type' );
    $tiers = Config::get_item( 'tiers', $type, [] );

    $result = [];
    foreach ( $tiers as $slug => $tier ) {
        $result[] = array_merge(
            [ 'slug' => $slug ],
            $tier,
            [ 'price_formatted' => Helpers\format_currency( $tier['price'] ?? 0 ) ]
        );
    }

    return new WP_REST_Response( $result, 200 );
}

/**
 * Get public memorials.
 *
 * @since 1.0.0
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response Response object.
 */
function get_public_memorials( WP_REST_Request $request ): WP_REST_Response {
    $query = Query::for( 'sd_memorial' )
        ->orderBy( 'donation_date', 'DESC' );

    $type = $request->get_param( 'type' );
    if ( $type && 'all' !== $type ) {
        $query->where( 'memorial_type', $type );
    }

    $year = $request->get_param( 'year' );
    if ( $year ) {
        $query->whereYear( 'donation_date', (int) $year );
    }

    $search = $request->get_param( 'search' );
    if ( $search ) {
        $query->search( 'honoree_name', $search );
    }

    $result = $query->paginate(
        (int) $request->get_param( 'page' ),
        (int) $request->get_param( 'per_page' )
    );

    // Add formatted fields and permalinks.
    foreach ( $result['items'] as &$item ) {
        $item['permalink'] = get_permalink( $item['id'] );
        $item['memorial_type_label'] = Helpers\get_memorial_type_label( $item['memorial_type'] ?? '' );
        $item['date_formatted'] = Helpers\format_date( $item['donation_date'] ?? '' );
        $item['tribute_excerpt'] = ! empty( $item['tribute_message'] )
            ? wp_trim_words( $item['tribute_message'], 20 )
            : '';
    }

    return new WP_REST_Response( $result, 200 );
}

/**
 * Get donor ID for current user.
 *
 * @since 1.0.0
 *
 * @return int|null Donor ID or null.
 */
function get_donor_id_for_current_user(): ?int {
    $user_id = get_current_user_id();

    if ( ! $user_id ) {
        return null;
    }

    $donors = get_posts( [
        'post_type'      => 'sd_donor',
        'posts_per_page' => 1,
        'meta_query'     => [
            [
                'key'   => '_sd_user_id',
                'value' => $user_id,
            ],
        ],
        'fields'         => 'ids',
    ] );

    return $donors[0] ?? null;
}

// Register routes.
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
