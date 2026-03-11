<?php
/**
 * Memorial ability callbacks.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Abilities\Memorials;

use Starter_Shelter\Core\{ Query, Entity_Hydrator };
use Starter_Shelter\Helpers;
use WP_Error;

/**
 * Create a new memorial tribute.
 *
 * @since 1.0.0
 *
 * @param array $input Memorial input data.
 * @return array|WP_Error Created memorial data or error.
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
    $memorial_date = $input['date'] ?? wp_date( 'Y-m-d H:i:s' );

    // Get donor display name for denormalized search.
    $donor_display_name = '';
    if ( ! ( $input['is_anonymous'] ?? false ) ) {
        $donor_display_name = get_post_meta( $donor_id, '_sd_display_name', true )
            ?: get_the_title( $donor_id );
    }

    // Create memorial post.
    $memorial_id = wp_insert_post( [
        'post_type'   => 'sd_memorial',
        'post_title'  => $input['honoree_name'],
        'post_status' => 'publish',
        'post_date'   => $memorial_date,
        'meta_input'  => [
            '_sd_donor_id'           => $donor_id,
            '_sd_donor_display_name' => $donor_display_name,
            '_sd_honoree_name'       => $input['honoree_name'],
            '_sd_memorial_type'      => $input['memorial_type'] ?? 'memory',
            '_sd_pet_species'        => $input['pet_species'] ?? '',
            '_sd_tribute_message'    => $input['tribute_message'] ?? '',
            '_sd_amount'             => (float) ( $input['amount'] ?? 0 ),
            '_sd_wc_order_id'        => $input['order_id'] ?? 0,
            '_sd_is_anonymous'       => $input['is_anonymous'] ?? false,
            '_sd_donation_date'      => $memorial_date,
            '_sd_date'               => $memorial_date,
            '_sd_notify_family'      => $input['notify_family'] ?? [],
        ],
    ], true );

    if ( is_wp_error( $memorial_id ) ) {
        return $memorial_id;
    }

    // Handle photo attachment.
    if ( ! empty( $input['photo_id'] ) ) {
        update_post_meta( $memorial_id, '_sd_photo_id', (int) $input['photo_id'] );
    }

    // Assign to memorial year taxonomy based on the memorial date.
    $year = wp_date( 'Y', strtotime( $memorial_date ) );
    wp_set_object_terms( $memorial_id, [ $year ], 'sd_memorial_year' );

    // Update donor lifetime giving.
    if ( ! empty( $input['amount'] ) ) {
        Helpers\update_donor_lifetime_giving( $donor_id, (float) $input['amount'] );
    }

    // Determine if family notification should be sent.
    $family_notified = false;
    $notify_family   = $input['notify_family'] ?? [];
    
    if ( ! empty( $notify_family['enabled'] ) && ! empty( $notify_family['email'] ) ) {
        do_action( 'starter_shelter_memorial_created', $memorial_id, $donor_id, $input );
        $family_notified = true;
    } else {
        // Fire general hook without family notification.
        do_action( 'starter_shelter_memorial_created', $memorial_id, $donor_id, $input );
    }

    return [
        'memorial_id'     => $memorial_id,
        'donor_id'        => $donor_id,
        'honoree_name'    => $input['honoree_name'],
        'permalink'       => get_permalink( $memorial_id ),
        'family_notified' => $family_notified,
        'status'          => 'created',
    ];
}

/**
 * List memorials with filtering.
 *
 * @since 1.0.0
 *
 * @param array $input Filter and pagination options.
 * @return array Paginated memorial list.
 */
function list_memorials( array $input = [] ): array {
    $query = Query::for( 'sd_memorial' )
        ->withPermalinks()                    // ← one-line addition
        ->where( 'donor_id', $input['donor_id'] ?? null )
        ->searchMultiple( [ 'honoree_name', 'donor_display_name' ], $input['search'] ?? null )
        ->orderBy( 'donation_date', 'DESC' );

    $type = $input['type'] ?? null;
    if ( $type && 'all' !== $type ) {
        $query->where( 'memorial_type', $type );
    }

    if ( ! empty( $input['year'] ) ) {
        $query->whereInTaxonomy( 'sd_memorial_year', (string) $input['year'], 'slug' );
    }

    return $query->paginate(                  // ← direct return, no loop
        max( 1, (int) ( $input['page'] ?? 1 ) ),
        max( 1, (int) ( $input['per_page'] ?? 12 ) )
    );
}

/**
 * Get a memorial by ID.
 *
 * @since 1.0.0
 *
 * @param array $input Input with memorial_id.
 * @return array|WP_Error Memorial data or error.
 */
function get( array $input ): array|WP_Error {
    $result = Entity_Hydrator::get( 'sd_memorial', $input['memorial_id'] );

    if ( ! $result ) {
        return new WP_Error(
            'memorial_not_found',
            __( 'Memorial not found.', 'starter-shelter' ),
            [ 'status' => 404 ]
        );
    }

    // Add permalink.
    $result['permalink'] = get_permalink( $input['memorial_id'] );

    return $result;
}
