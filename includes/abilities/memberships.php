<?php
/**
 * Membership ability callbacks.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Abilities\Memberships;

use Starter_Shelter\Core\{ Query, Entity_Hydrator };
use Starter_Shelter\Helpers;
use WP_Error;

/**
 * Create a new membership.
 *
 * @since 1.0.0
 *
 * @param array $input Membership input data.
 * @return array|WP_Error Created membership data or error.
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

    // Determine membership amount from tier if not provided.
    $tier   = $input['tier'] ?? 'friend';
    $type   = $input['membership_type'] ?? 'individual';
    $amount = $input['amount'] ?? Helpers\get_tier_amount( $tier, $type );

    // Calculate dates - use provided date or current time for start.
    $start_date = isset( $input['date'] ) 
        ? wp_date( 'Y-m-d', strtotime( $input['date'] ) )
        : wp_date( 'Y-m-d' );
    $end_date = wp_date( 'Y-m-d', strtotime( $start_date . ' +1 year' ) );

    // For display in title, use the year from start date.
    $start_year = wp_date( 'Y', strtotime( $start_date ) );

    // Create membership post.
    $membership_id = wp_insert_post( [
        'post_type'   => 'sd_membership',
        'post_title'  => sprintf(
            '%s - %s (%s)',
            $input['donor_name'] ?? $input['donor_email'],
            Helpers\get_tier_label( $tier ),
            $start_year
        ),
        'post_status' => 'publish',
        'post_date'   => $input['date'] ?? current_time( 'mysql' ),
        'meta_input'  => [
            '_sd_donor_id'        => $donor_id,
            '_sd_tier'            => $tier,
            '_sd_membership_type' => $type,
            '_sd_amount'          => (float) $amount,
            '_sd_start_date'      => $start_date,
            '_sd_end_date'        => $end_date,
            '_sd_wc_order_id'     => $input['order_id'] ?? 0,
            '_sd_auto_renew'      => $input['auto_renew'] ?? false,
        ],
    ], true );

    if ( is_wp_error( $membership_id ) ) {
        return $membership_id;
    }

    // Handle business-specific fields.
    if ( 'business' === $type ) {
        if ( ! empty( $input['business_name'] ) ) {
            update_post_meta( $membership_id, '_sd_business_name', $input['business_name'] );
        }
        if ( ! empty( $input['logo_attachment_id'] ) ) {
            update_post_meta( $membership_id, '_sd_logo_attachment_id', $input['logo_attachment_id'] );
        }
    }

    // Update donor lifetime giving.
    Helpers\update_donor_lifetime_giving( $donor_id, (float) $amount );

    do_action( 'starter_shelter_membership_created', $membership_id, $donor_id, $input );

    return [
        'membership_id'   => $membership_id,
        'donor_id'        => $donor_id,
        'tier'            => $tier,
        'tier_label'      => Helpers\get_tier_label( $tier ),
        'start_date'      => $start_date,
        'expiration_date' => $end_date,
        'benefits'        => Helpers\get_tier_benefits( $tier, $type ),
        'status'          => 'created',
    ];
}

/**
 * Renew an existing membership.
 *
 * @since 1.0.0
 *
 * @param array $input Renewal input data.
 * @return array|WP_Error Renewal result or error.
 */
function renew( array $input ): array|WP_Error {
    $membership_id = $input['membership_id'] ?? 0;
    $membership    = Entity_Hydrator::get( 'sd_membership', $membership_id );

    if ( ! $membership ) {
        return new WP_Error(
            'membership_not_found',
            __( 'Membership not found.', 'starter-shelter' ),
            [ 'status' => 404 ]
        );
    }

    // Calculate new end date.
    $current_end = $membership['end_date'] ?? wp_date( 'Y-m-d' );
    
    // If membership is still active, extend from current end date.
    // If expired, extend from today.
    if ( Helpers\is_membership_active( $current_end ) ) {
        $new_end = wp_date( 'Y-m-d', strtotime( "$current_end +1 year" ) );
    } else {
        $new_end = wp_date( 'Y-m-d', strtotime( '+1 year' ) );
    }

    // Get renewal tier (may be different from original).
    $tier = $input['tier'] ?? $membership['tier'];
    $type = $membership['membership_type'];

    // Determine amount.
    $amount = $input['amount'] ?? Helpers\get_tier_amount( $tier, $type );

    // Update membership.
    update_post_meta( $membership_id, '_sd_end_date', $new_end );
    update_post_meta( $membership_id, '_sd_tier', $tier );

    // Record the renewal order.
    if ( ! empty( $input['order_id'] ) ) {
        $renewals = get_post_meta( $membership_id, '_sd_renewal_orders', true ) ?: [];
        $renewals[] = [
            'order_id' => $input['order_id'],
            'date'     => wp_date( 'Y-m-d H:i:s' ),
            'amount'   => $amount,
        ];
        update_post_meta( $membership_id, '_sd_renewal_orders', $renewals );
    }

    // Update donor lifetime giving.
    $donor_id = $membership['donor_id'];
    Helpers\update_donor_lifetime_giving( $donor_id, (float) $amount );

    do_action( 'starter_shelter_membership_renewed', $membership_id, $donor_id, $input );

    return [
        'membership_id'       => $membership_id,
        'donor_id'            => $donor_id,
        'tier'                => $tier,
        'tier_label'          => Helpers\get_tier_label( $tier ),
        'new_expiration_date' => $new_end,
        'status'              => 'renewed',
    ];
}

/**
 * Get membership status.
 *
 * @since 1.0.0
 *
 * @param array $input Input with membership_id or donor_id.
 * @return array|WP_Error Membership status or error.
 */
function get_status( array $input ): array|WP_Error {
    // Get by membership ID.
    if ( ! empty( $input['membership_id'] ) ) {
        $membership = Entity_Hydrator::get( 'sd_membership', $input['membership_id'] );

        if ( ! $membership ) {
            return new WP_Error(
                'membership_not_found',
                __( 'Membership not found.', 'starter-shelter' ),
                [ 'status' => 404 ]
            );
        }

        return $membership;
    }

    // Get by donor ID (most recent active membership).
    if ( ! empty( $input['donor_id'] ) ) {
        $membership = Query::for( 'sd_membership' )
            ->where( 'donor_id', $input['donor_id'] )
            ->orderBy( 'end_date', 'DESC' )
            ->first();

        if ( ! $membership ) {
            return [
                'has_membership' => false,
                'status'         => 'none',
                'donor_id'       => $input['donor_id'],
            ];
        }

        return array_merge( $membership, [
            'has_membership' => true,
            'status'         => $membership['is_active'] ? 'active' : 'expired',
        ] );
    }

    return new WP_Error(
        'invalid_input',
        __( 'Either membership_id or donor_id is required.', 'starter-shelter' ),
        [ 'status' => 400 ]
    );
}

/**
 * List memberships with filtering.
 *
 * @since 1.0.0
 *
 * @param array $input Filter and pagination options.
 * @return array Paginated membership list.
 */
function list_memberships( array $input = [] ): array {
    $query = Query::for( 'sd_membership' )
        ->where( 'donor_id', $input['donor_id'] ?? null )
        ->where( 'tier', $input['tier'] ?? null )
        ->where( 'membership_type', $input['membership_type'] ?? null )
        ->orderBy( 'end_date', 'DESC' );

    // Filter by status (active/expired/expiring).
    $status = $input['status'] ?? null;
    if ( 'active' === $status ) {
        $query->whereCompare( 'end_date', wp_date( 'Y-m-d' ), '>=', 'DATE' );
    } elseif ( 'expired' === $status ) {
        $query->whereCompare( 'end_date', wp_date( 'Y-m-d' ), '<', 'DATE' );
    } elseif ( 'expiring' === $status ) {
        $today     = wp_date( 'Y-m-d' );
        $in30days  = wp_date( 'Y-m-d', strtotime( '+30 days' ) );
        $query->whereDateBetween( 'end_date', $today, $in30days );
    }

    return $query->paginate( $input['page'] ?? 1, $input['per_page'] ?? 10 );
}

/**
 * Cancel an active membership.
 *
 * @since 1.0.0
 *
 * @param array $input {
 *     Cancel membership input.
 *
 *     @type int    $membership_id Required. The membership post ID.
 *     @type string $reason        Optional. Cancellation reason.
 * }
 * @return array|WP_Error Cancellation result or error.
 */
function cancel( array $input ): array|WP_Error {
    $membership_id = $input['membership_id'] ?? 0;

    if ( ! $membership_id ) {
        return new WP_Error(
            'missing_membership_id',
            __( 'Membership ID is required.', 'starter-shelter' )
        );
    }

    // Verify the membership exists.
    $membership = Entity_Hydrator::get( 'sd_membership', $membership_id );
    if ( ! $membership ) {
        return new WP_Error(
            'membership_not_found',
            __( 'Membership not found.', 'starter-shelter' )
        );
    }

    // Check if already cancelled or expired.
    $current_status = $membership['status'] ?? '';
    if ( 'cancelled' === $current_status ) {
        return new WP_Error(
            'already_cancelled',
            __( 'This membership is already cancelled.', 'starter-shelter' )
        );
    }

    $cancelled_at = wp_date( 'Y-m-d H:i:s' );

    // Update the membership status.
    update_post_meta( $membership_id, '_sd_status', 'cancelled' );
    update_post_meta( $membership_id, '_sd_cancelled_at', $cancelled_at );

    // Store cancellation reason if provided.
    if ( ! empty( $input['reason'] ) ) {
        update_post_meta( $membership_id, '_sd_cancellation_reason', sanitize_textarea_field( $input['reason'] ) );
    }

    // Clear the end date to stop renewal reminders.
    update_post_meta( $membership_id, '_sd_end_date', '' );

    // Update donor's active membership status if this was their current membership.
    $donor_id = $membership['donor_id'] ?? 0;
    if ( $donor_id ) {
        $current_membership_id = (int) get_post_meta( $donor_id, '_sd_current_membership_id', true );
        if ( $current_membership_id === $membership_id ) {
            delete_post_meta( $donor_id, '_sd_current_membership_id' );
            update_post_meta( $donor_id, '_sd_is_member', false );
        }
    }

    /**
     * Fires after a membership is cancelled.
     *
     * @since 1.0.0
     *
     * @param int    $membership_id The cancelled membership ID.
     * @param array  $membership    The membership data before cancellation.
     * @param string $reason        The cancellation reason.
     */
    do_action( 'starter_shelter_membership_cancelled', $membership_id, $membership, $input['reason'] ?? '' );

    return [
        'membership_id' => $membership_id,
        'status'        => 'cancelled',
        'cancelled_at'  => $cancelled_at,
    ];
}
