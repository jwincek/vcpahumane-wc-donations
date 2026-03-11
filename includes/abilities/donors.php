<?php
/**
 * Donor ability callbacks.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Abilities\Donors;

use Starter_Shelter\Core\{ Query, Entity_Hydrator };
use Starter_Shelter\Helpers;
use WP_Error;

/**
 * Get donor profile.
 *
 * @since 1.0.0
 *
 * @param array $input Input with donor_id.
 * @return array|WP_Error Donor profile or error.
 */
function get_profile( array $input ): array|WP_Error {
    $donor_id = $input['donor_id'] ?? 0;

    // Allow lookup by user_id.
    if ( empty( $donor_id ) && ! empty( $input['user_id'] ) ) {
        $donors = get_posts( [
            'post_type'      => 'sd_donor',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_sd_user_id',
                    'value' => $input['user_id'],
                ],
            ],
            'fields'         => 'ids',
        ] );

        if ( ! empty( $donors ) ) {
            $donor_id = $donors[0];
        }
    }

    // Allow lookup by email.
    if ( empty( $donor_id ) && ! empty( $input['email'] ) ) {
        $donors = get_posts( [
            'post_type'      => 'sd_donor',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_sd_email',
                    'value' => sanitize_email( $input['email'] ),
                ],
            ],
            'fields'         => 'ids',
        ] );

        if ( ! empty( $donors ) ) {
            $donor_id = $donors[0];
        }
    }

    if ( ! $donor_id ) {
        return new WP_Error(
            'donor_not_found',
            __( 'Donor not found.', 'starter-shelter' ),
            [ 'status' => 404 ]
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

    // Add donor level label.
    $donor['donor_level_label'] = Helpers\get_donor_level_label( $donor['donor_level'] ?? 'new' );

    return $donor;
}

/**
 * Update donor address.
 *
 * @since 1.0.0
 *
 * @param array $input Input with donor_id and address.
 * @return array|WP_Error Updated donor data or error.
 */
function update_address( array $input ): array|WP_Error {
    $donor_id = $input['donor_id'] ?? 0;

    if ( ! $donor_id ) {
        return new WP_Error(
            'invalid_donor_id',
            __( 'Valid donor ID is required.', 'starter-shelter' ),
            [ 'status' => 400 ]
        );
    }

    $donor = get_post( $donor_id );
    if ( ! $donor || 'sd_donor' !== $donor->post_type ) {
        return new WP_Error(
            'donor_not_found',
            __( 'Donor not found.', 'starter-shelter' ),
            [ 'status' => 404 ]
        );
    }

    // Normalize address keys.
    $address = $input['address'] ?? [];
    $normalized_address = [
        'line_1'      => $address['line_1'] ?? $address['address_1'] ?? '',
        'line_2'      => $address['line_2'] ?? $address['address_2'] ?? '',
        'city'        => $address['city'] ?? '',
        'state'       => $address['state'] ?? '',
        'postal_code' => $address['postal_code'] ?? $address['postcode'] ?? '',
        'country'     => $address['country'] ?? 'US',
    ];

    // Filter out empty values.
    $normalized_address = array_filter( $normalized_address, fn( $v ) => '' !== $v );

    update_post_meta( $donor_id, '_sd_address', $normalized_address );

    // Track the source of the update.
    $source = $input['source'] ?? 'unknown';
    update_post_meta( $donor_id, '_sd_address_last_updated', wp_date( 'Y-m-d H:i:s' ) );
    update_post_meta( $donor_id, '_sd_address_update_source', $source );

    do_action( 'starter_shelter_donor_address_updated', $donor_id, $normalized_address, $source );

    return [
        'donor_id'          => $donor_id,
        'address'           => $normalized_address,
        'formatted_address' => Helpers\format_address( $normalized_address ),
        'status'            => 'updated',
    ];
}

/**
 * Get donor giving history.
 *
 * @since 1.0.0
 *
 * @param array $input Input with donor_id and optional filters.
 * @return array|WP_Error Donor history or error.
 */
function get_history( array $input ): array|WP_Error {
    $donor_id = $input['donor_id'] ?? 0;

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

    // Get donations.
    $donations = Query::for( 'sd_donation' )
        ->where( 'donor_id', $donor_id )
        ->whereDateBetween(
            'donation_date',
            $input['date_from'] ?? null,
            $input['date_to'] ?? null
        )
        ->orderBy( 'donation_date', 'DESC' )
        ->get( $input['limit'] ?? 100 );

    // Get memberships.
    $memberships = Query::for( 'sd_membership' )
        ->where( 'donor_id', $donor_id )
        ->orderBy( 'start_date', 'DESC' )
        ->get( 10 );

    // Get memorials.
    $memorials = Query::for( 'sd_memorial' )
        ->where( 'donor_id', $donor_id )
        ->orderBy( 'donation_date', 'DESC' )
        ->get( $input['limit'] ?? 50 );

    // Calculate stats.
    $total_donations = array_sum( array_column( $donations, 'amount' ) );
    $total_memorials = array_sum( array_column( $memorials, 'amount' ) );
    $total_memberships = array_sum( array_column( $memberships, 'amount' ) );

    return [
        'donor'       => $donor,
        'donations'   => $donations,
        'memberships' => $memberships,
        'memorials'   => $memorials,
        'stats'       => [
            'total_donations'       => $total_donations,
            'total_memorials'       => $total_memorials,
            'total_memberships'     => $total_memberships,
            'total_giving'          => $total_donations + $total_memorials + $total_memberships,
            'donation_count'        => count( $donations ),
            'memorial_count'        => count( $memorials ),
            'membership_count'      => count( $memberships ),
            'first_donation_date'   => ! empty( $donations ) ? end( $donations )['donation_date'] : null,
            'last_donation_date'    => ! empty( $donations ) ? $donations[0]['donation_date'] ?? null : null,
        ],
    ];
}

/**
 * Update donor profile.
 *
 * @since 1.0.0
 *
 * @param array $input Input with donor_id and fields to update.
 * @return array|WP_Error Updated donor data or error.
 */
function update_profile( array $input ): array|WP_Error {
    $donor_id = $input['donor_id'] ?? 0;

    if ( ! $donor_id ) {
        return new WP_Error(
            'invalid_donor_id',
            __( 'Valid donor ID is required.', 'starter-shelter' ),
            [ 'status' => 400 ]
        );
    }

    $donor = get_post( $donor_id );
    if ( ! $donor || 'sd_donor' !== $donor->post_type ) {
        return new WP_Error(
            'donor_not_found',
            __( 'Donor not found.', 'starter-shelter' ),
            [ 'status' => 404 ]
        );
    }

    // Update allowed fields.
    $updatable_fields = [
        'first_name',
        'last_name',
        'phone',
        'communication_preferences',
    ];

    foreach ( $updatable_fields as $field ) {
        if ( isset( $input[ $field ] ) ) {
            update_post_meta( $donor_id, '_sd_' . $field, $input[ $field ] );
        }
    }

    // Update post title if name changed.
    if ( isset( $input['first_name'] ) || isset( $input['last_name'] ) ) {
        $first = $input['first_name'] ?? get_post_meta( $donor_id, '_sd_first_name', true );
        $last  = $input['last_name'] ?? get_post_meta( $donor_id, '_sd_last_name', true );
        $name  = trim( "$first $last" );

        if ( $name ) {
            wp_update_post( [
                'ID'         => $donor_id,
                'post_title' => $name,
            ] );
        }
    }

    do_action( 'starter_shelter_donor_profile_updated', $donor_id, $input );

    return Entity_Hydrator::get( 'sd_donor', $donor_id );
}
