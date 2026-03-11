<?php
/**
 * Report ability callbacks.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Abilities\Reports;

use Starter_Shelter\Core\{ Query, Entity_Hydrator, Config };
use Starter_Shelter\Helpers;
use WP_Error;

/**
 * Generate annual donor summary.
 *
 * @since 1.0.0
 *
 * @param array $input Input with donor_id and year.
 * @return array|WP_Error Annual summary or error.
 */
function annual_summary( array $input ): array|WP_Error {
    $donor_id = $input['donor_id'] ?? 0;
    $year     = $input['year'] ?? (int) wp_date( 'Y' );

    if ( ! $donor_id ) {
        return new WP_Error(
            'invalid_donor_id',
            __( 'Valid donor ID is required.', 'starter-shelter' ),
            [ 'status' => 400 ]
        );
    }

    $donor = Entity_Hydrator::get( 'sd_donor', $donor_id );
    if ( ! $donor ) {
        return new WP_Error(
            'donor_not_found',
            __( 'Donor not found.', 'starter-shelter' ),
            [ 'status' => 404 ]
        );
    }

    $date_from = "$year-01-01";
    $date_to   = "$year-12-31";

    // Get donations for the year.
    $donations = Query::for( 'sd_donation' )
        ->where( 'donor_id', $donor_id )
        ->whereDateBetween( 'donation_date', $date_from, $date_to )
        ->orderBy( 'donation_date', 'ASC' )
        ->get();

    // Get memorials for the year.
    $memorials = Query::for( 'sd_memorial' )
        ->where( 'donor_id', $donor_id )
        ->whereDateBetween( 'donation_date', $date_from, $date_to )
        ->orderBy( 'donation_date', 'ASC' )
        ->get();

    // Get memberships active during the year.
    $memberships = Query::for( 'sd_membership' )
        ->where( 'donor_id', $donor_id )
        ->whereDateBetween( 'start_date', $date_from, $date_to )
        ->orderBy( 'start_date', 'ASC' )
        ->get();

    // Calculate totals.
    $donation_total   = array_sum( array_column( $donations, 'amount' ) );
    $memorial_total   = array_sum( array_column( $memorials, 'amount' ) );
    $membership_total = array_sum( array_column( $memberships, 'amount' ) );
    $grand_total      = $donation_total + $memorial_total + $membership_total;

    // Build summary by allocation.
    $by_allocation = [];
    foreach ( $donations as $donation ) {
        $alloc = $donation['allocation'] ?? 'general-fund';
        if ( ! isset( $by_allocation[ $alloc ] ) ) {
            $by_allocation[ $alloc ] = [
                'allocation' => $alloc,
                'label'      => Helpers\get_allocation_label( $alloc ),
                'amount'     => 0,
                'count'      => 0,
            ];
        }
        $by_allocation[ $alloc ]['amount'] += $donation['amount'];
        $by_allocation[ $alloc ]['count']++;
    }

    return [
        'donor'          => [
            'id'        => $donor_id,
            'name'      => $donor['full_name'] ?? 'Anonymous',
            'email'     => $donor['email'] ?? '',
            'address'   => $donor['formatted_address'] ?? '',
        ],
        'year'           => $year,
        'donations'      => [
            'items'      => $donations,
            'total'      => $donation_total,
            'formatted'  => Helpers\format_currency( $donation_total ),
            'count'      => count( $donations ),
        ],
        'memorials'      => [
            'items'      => $memorials,
            'total'      => $memorial_total,
            'formatted'  => Helpers\format_currency( $memorial_total ),
            'count'      => count( $memorials ),
        ],
        'memberships'    => [
            'items'      => $memberships,
            'total'      => $membership_total,
            'formatted'  => Helpers\format_currency( $membership_total ),
            'count'      => count( $memberships ),
        ],
        'by_allocation'  => array_values( $by_allocation ),
        'grand_total'    => $grand_total,
        'grand_formatted' => Helpers\format_currency( $grand_total ),
        'tax_deductible' => $grand_total, // Assuming all donations are tax-deductible.
        'generated_date' => wp_date( 'Y-m-d H:i:s' ),
    ];
}

/**
 * Get dashboard statistics.
 *
 * @since 1.0.0
 *
 * @param array $input Input with period and optional filters.
 * @return array Dashboard statistics.
 */
function dashboard_stats( array $input = [] ): array {
    $period = $input['period'] ?? 'fiscal_year';
    $range  = Helpers\get_date_range_for_period(
        $period,
        null,
        $input['fiscal_year'] ?? null
    );

    global $wpdb;

    // Donations stats.
    $donation_stats = $wpdb->get_row( $wpdb->prepare(
        "SELECT 
            COUNT(*) as count,
            COALESCE(SUM(pm.meta_value), 0) as total,
            COUNT(DISTINCT pd.meta_value) as donors
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_amount'
        INNER JOIN {$wpdb->postmeta} pd ON p.ID = pd.post_id AND pd.meta_key = '_sd_donor_id'
        INNER JOIN {$wpdb->postmeta} pdt ON p.ID = pdt.post_id AND pdt.meta_key = '_sd_donation_date'
        WHERE p.post_type = 'sd_donation'
        AND p.post_status = 'publish'
        AND pdt.meta_value BETWEEN %s AND %s",
        $range['start'],
        $range['end']
    ) );

    // Membership stats.
    $membership_stats = $wpdb->get_row( $wpdb->prepare(
        "SELECT 
            COUNT(*) as total_count,
            COALESCE(SUM(pm.meta_value), 0) as total_amount,
            SUM(CASE WHEN pe.meta_value >= %s THEN 1 ELSE 0 END) as active_count
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_amount'
        INNER JOIN {$wpdb->postmeta} pe ON p.ID = pe.post_id AND pe.meta_key = '_sd_end_date'
        INNER JOIN {$wpdb->postmeta} ps ON p.ID = ps.post_id AND ps.meta_key = '_sd_start_date'
        WHERE p.post_type = 'sd_membership'
        AND p.post_status = 'publish'
        AND ps.meta_value BETWEEN %s AND %s",
        wp_date( 'Y-m-d' ),
        $range['start'],
        $range['end']
    ) );

    // Memorial stats.
    $memorial_stats = $wpdb->get_row( $wpdb->prepare(
        "SELECT 
            COUNT(*) as count,
            COALESCE(SUM(pm.meta_value), 0) as total
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_amount'
        INNER JOIN {$wpdb->postmeta} pdt ON p.ID = pdt.post_id AND pdt.meta_key = '_sd_donation_date'
        WHERE p.post_type = 'sd_memorial'
        AND p.post_status = 'publish'
        AND pdt.meta_value BETWEEN %s AND %s",
        $range['start'],
        $range['end']
    ) );

    // New donors this period.
    $new_donors = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_created_date'
        WHERE p.post_type = 'sd_donor'
        AND p.post_status = 'publish'
        AND pm.meta_value BETWEEN %s AND %s",
        $range['start'],
        $range['end']
    ) );

    // Calculate totals.
    $donation_total   = (float) $donation_stats->total;
    $membership_total = (float) $membership_stats->total_amount;
    $memorial_total   = (float) $memorial_stats->total;
    $grand_total      = $donation_total + $membership_total + $memorial_total;

    return [
        'period'      => $period,
        'date_range'  => $range,
        'donations'   => [
            'count'          => (int) $donation_stats->count,
            'total'          => $donation_total,
            'total_formatted' => Helpers\format_currency( $donation_total ),
            'unique_donors'  => (int) $donation_stats->donors,
            'average'        => $donation_stats->count > 0 
                ? round( $donation_total / $donation_stats->count, 2 ) 
                : 0,
        ],
        'memberships' => [
            'new_count'       => (int) $membership_stats->total_count,
            'active_count'    => (int) $membership_stats->active_count,
            'total'           => $membership_total,
            'total_formatted' => Helpers\format_currency( $membership_total ),
        ],
        'memorials'   => [
            'count'          => (int) $memorial_stats->count,
            'total'          => $memorial_total,
            'total_formatted' => Helpers\format_currency( $memorial_total ),
        ],
        'totals'      => [
            'grand_total'     => $grand_total,
            'total_formatted' => Helpers\format_currency( $grand_total ),
            'new_donors'      => (int) $new_donors,
        ],
        'generated_at' => wp_date( 'Y-m-d H:i:s' ),
    ];
}

/**
 * Get campaign report.
 *
 * @since 1.0.0
 *
 * @param array $input Input with campaign_id.
 * @return array|WP_Error Campaign statistics or error.
 */
function campaign_report( array $input ): array|WP_Error {
    $campaign_id = $input['campaign_id'] ?? 0;

    if ( ! $campaign_id ) {
        return new WP_Error(
            'invalid_campaign_id',
            __( 'Valid campaign ID is required.', 'starter-shelter' ),
            [ 'status' => 400 ]
        );
    }

    $term = get_term( $campaign_id, 'sd_campaign' );
    if ( ! $term || is_wp_error( $term ) ) {
        return new WP_Error(
            'campaign_not_found',
            __( 'Campaign not found.', 'starter-shelter' ),
            [ 'status' => 404 ]
        );
    }

    // Get donations for this campaign.
    $donations = Query::for( 'sd_donation' )
        ->whereInTaxonomy( 'sd_campaign', $campaign_id )
        ->orderBy( 'donation_date', 'DESC' )
        ->get();

    // Calculate stats.
    $total = array_sum( array_column( $donations, 'amount' ) );
    $donor_ids = array_unique( array_column( $donations, 'donor_id' ) );

    // Get campaign goal from term meta.
    $goal = get_term_meta( $campaign_id, 'goal', true );
    $goal = $goal ? (float) $goal : null;

    return [
        'campaign'    => [
            'id'          => $campaign_id,
            'name'        => $term->name,
            'description' => $term->description,
            'goal'        => $goal,
            'goal_formatted' => $goal ? Helpers\format_currency( $goal ) : null,
        ],
        'donations'   => $donations,
        'stats'       => [
            'total'           => $total,
            'total_formatted' => Helpers\format_currency( $total ),
            'count'           => count( $donations ),
            'donor_count'     => count( $donor_ids ),
            'average'         => count( $donations ) > 0 
                ? round( $total / count( $donations ), 2 ) 
                : 0,
            'progress'        => $goal ? min( 100, round( ( $total / $goal ) * 100, 1 ) ) : null,
            'remaining'       => $goal ? max( 0, $goal - $total ) : null,
        ],
    ];
}
