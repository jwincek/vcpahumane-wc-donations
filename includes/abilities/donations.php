<?php
/**
 * Donation ability callbacks.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Abilities\Donations;

use Starter_Shelter\Core\{ Query, Entity_Hydrator };
use Starter_Shelter\Helpers;
use WP_Error;

/**
 * Create a new donation.
 *
 * @since 1.0.0
 *
 * @param array $input Donation input data.
 * @return array|WP_Error Created donation data or error.
 */
function create( array $input ): array|WP_Error {
    // Get or create donor.
    // Accept pre-resolved donor_id (from importers, sync) or resolve from email.
    if ( ! empty( $input['donor_id'] ) ) {
        $donor_id = (int) $input['donor_id'];
    } else {
        $donor_id = Helpers\get_or_create_donor(
            $input['donor_email'] ?? '',
            $input['donor_name'] ?? ''
        );
        if ( is_wp_error( $donor_id ) ) {
            return $donor_id;
        }
    }

    // Use provided date or current time.
    $donation_date = $input['date'] ?? wp_date( 'Y-m-d H:i:s' );
    $display_date = wp_date( 'Y-m-d', strtotime( $donation_date ) );

    // Create donation post.
    $donation_id = wp_insert_post( [
        'post_type'   => 'sd_donation',
        'post_title'  => sprintf(
            '%s - %s',
            $input['donor_name'] ?? $input['donor_email'],
            $display_date
        ),
        'post_status' => 'publish',
        'post_date'   => $donation_date,
        'meta_input'  => [
            '_sd_donor_id'      => $donor_id,
            '_sd_amount'        => (float) $input['amount'],
            '_sd_wc_order_id'   => $input['order_id'] ?? 0,
            '_sd_allocation'    => $input['allocation'] ?? 'general-fund',
            '_sd_is_anonymous'  => $input['is_anonymous'] ?? false,
            '_sd_dedication'    => $input['dedication'] ?? '',
            '_sd_donation_date' => $donation_date,
            '_sd_date'          => $donation_date,
        ],
    ], true );

    if ( is_wp_error( $donation_id ) ) {
        return $donation_id;
    }

    // Assign campaign if specified.
    if ( ! empty( $input['campaign_id'] ) ) {
        wp_set_object_terms( $donation_id, [ (int) $input['campaign_id'] ], 'sd_campaign' );
    }

    // Update donor lifetime giving.
    Helpers\update_donor_lifetime_giving( $donor_id, (float) $input['amount'] );

    do_action( 'starter_shelter_donation_created', $donation_id, $donor_id, $input );

    return [
        'donation_id' => $donation_id,
        'donor_id'    => $donor_id,
        'status'      => 'created',
    ];
}

/**
 * Get a donation by ID.
 *
 * @since 1.0.0
 *
 * @param array $input Input with donation_id.
 * @return array|WP_Error Donation data or error.
 */
function get( array $input ): array|WP_Error {
    $result = Entity_Hydrator::get( 'sd_donation', $input['donation_id'] );

    if ( ! $result ) {
        return new WP_Error(
            'donation_not_found',
            __( 'Donation not found.', 'starter-shelter' ),
            [ 'status' => 404 ]
        );
    }

    return $result;
}

/**
 * List donations with filtering.
 *
 * @since 1.0.0
 *
 * @param array $input Filter and pagination options.
 * @return array Paginated donation list.
 */
function list_donations( array $input = [] ): array {
    return Query::for( 'sd_donation' )
        ->where( 'donor_id', $input['donor_id'] ?? null )
        ->where( 'allocation', $input['allocation'] ?? null )
        ->whereDateBetween(
            'donation_date',
            $input['date_from'] ?? null,
            $input['date_to'] ?? null
        )
        ->whereInTaxonomy( 'sd_campaign', $input['campaign_id'] ?? null )
        ->orderBy( 'donation_date', 'DESC' )
        ->paginate( $input['page'] ?? 1, $input['per_page'] ?? 10 );
}

/**
 * Get donation statistics.
 *
 * @since 1.0.0
 *
 * @param array $input Period and filter options.
 * @return array Statistics data.
 */
function get_stats( array $input = [] ): array {
    $range = Helpers\get_date_range_for_period(
        $input['period'] ?? 'fiscal_year',
        null,
        $input['fiscal_year'] ?? null
    );

    global $wpdb;

    $sql = $wpdb->prepare(
        "SELECT 
            COUNT(*) as donation_count,
            COALESCE(SUM(pm_amount.meta_value), 0) as total_amount,
            COUNT(DISTINCT pm_donor.meta_value) as donor_count
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm_amount ON p.ID = pm_amount.post_id AND pm_amount.meta_key = '_sd_amount'
        INNER JOIN {$wpdb->postmeta} pm_donor ON p.ID = pm_donor.post_id AND pm_donor.meta_key = '_sd_donor_id'
        INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_sd_donation_date'
        WHERE p.post_type = 'sd_donation'
        AND p.post_status = 'publish'
        AND pm_date.meta_value BETWEEN %s AND %s",
        $range['start'],
        $range['end']
    );

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $stats = $wpdb->get_row( $sql );

    $total  = (float) $stats->total_amount;
    $count  = (int) $stats->donation_count;
    $donors = (int) $stats->donor_count;

    return [
        'total_amount'    => $total,
        'total_formatted' => Helpers\format_currency( $total ),
        'donation_count'  => $count,
        'donor_count'     => $donors,
        'average_amount'  => $count > 0 ? round( $total / $count, 2 ) : 0,
        'period'          => $input['period'] ?? 'fiscal_year',
        'date_range'      => $range,
    ];
}
