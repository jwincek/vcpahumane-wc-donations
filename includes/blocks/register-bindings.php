<?php
/**
 * Block Bindings - Register custom binding sources for Shelter Donations.
 *
 * @package Starter_Shelter
 * @subpackage Blocks
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Blocks;

use Starter_Shelter\Core\{ Config, Entity_Hydrator, Query };
use Starter_Shelter\Helpers;

/**
 * Register all block binding sources.
 *
 * @since 1.0.0
 */
function register_binding_sources(): void {
    // Shelter entity data binding.
    register_block_bindings_source(
        'starter-shelter/entity',
        [
            'label'              => __( 'Shelter Entity Data', 'starter-shelter' ),
            'get_value_callback' => __NAMESPACE__ . '\\get_entity_value',
            'uses_context'       => [ 'postId', 'postType' ],
        ]
    );

    // Shelter donor data binding.
    register_block_bindings_source(
        'starter-shelter/donor',
        [
            'label'              => __( 'Shelter Donor Data', 'starter-shelter' ),
            'get_value_callback' => __NAMESPACE__ . '\\get_donor_value',
            'uses_context'       => [ 'postId', 'postType' ],
        ]
    );

    // Shelter stats binding.
    register_block_bindings_source(
        'starter-shelter/stats',
        [
            'label'              => __( 'Shelter Statistics', 'starter-shelter' ),
            'get_value_callback' => __NAMESPACE__ . '\\get_stats_value',
            'uses_context'       => [],
        ]
    );

    // Campaign progress binding.
    register_block_bindings_source(
        'starter-shelter/campaign',
        [
            'label'              => __( 'Campaign Data', 'starter-shelter' ),
            'get_value_callback' => __NAMESPACE__ . '\\get_campaign_value',
            'uses_context'       => [ 'postId', 'postType' ],
        ]
    );

    // Membership tier binding.
    register_block_bindings_source(
        'starter-shelter/tier',
        [
            'label'              => __( 'Membership Tier Data', 'starter-shelter' ),
            'get_value_callback' => __NAMESPACE__ . '\\get_tier_value',
            'uses_context'       => [],
        ]
    );
}

/**
 * Get entity field value for block binding.
 *
 * @since 1.0.0
 *
 * @param array    $source_args    Binding source arguments.
 * @param WP_Block $block_instance The block instance.
 * @param string   $attribute_name The attribute being bound.
 * @return mixed The bound value.
 */
function get_entity_value( array $source_args, $block_instance, string $attribute_name ) {
    $entity_type = $source_args['entity'] ?? null;
    $field = $source_args['field'] ?? null;
    $post_id = $source_args['id'] ?? $block_instance->context['postId'] ?? null;

    // Temporary debug — remove after confirming bindings work.
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( sprintf(
            '[SD Binding] entity=%s field=%s postId=%s context_keys=%s',
            $entity_type ?? 'null',
            $field ?? 'null',
            $post_id ?? 'null',
            implode( ',', array_keys( $block_instance->context ?? [] ) )
        ) );
    }

    if ( ! $entity_type || ! $field || ! $post_id ) {
        return null;
    }

    // Map friendly entity names to post types.
    $entity_map = [
        'donation'   => 'sd_donation',
        'membership' => 'sd_membership',
        'memorial'   => 'sd_memorial',
        'donor'      => 'sd_donor',
    ];

    $post_type = $entity_map[ $entity_type ] ?? $entity_type;
    $entity = Entity_Hydrator::get( $post_type, (int) $post_id );

    if ( ! $entity ) {
        return null;
    }

    // Support nested field access with dot notation.
    return get_nested_value( $entity, $field );
}

/**
 * Get donor field value for block binding.
 *
 * @since 1.0.0
 *
 * @param array    $source_args    Binding source arguments.
 * @param WP_Block $block_instance The block instance.
 * @param string   $attribute_name The attribute being bound.
 * @return mixed The bound value.
 */
function get_donor_value( array $source_args, $block_instance, string $attribute_name ) {
    $field = $source_args['field'] ?? null;
    $donor_id = $source_args['id'] ?? null;

    // If no donor ID, try to get from current user.
    if ( ! $donor_id ) {
        $donor_id = get_current_user_donor_id();
    }

    if ( ! $donor_id || ! $field ) {
        return null;
    }

    $donor = Entity_Hydrator::get( 'sd_donor', (int) $donor_id );

    if ( ! $donor ) {
        return null;
    }

    return get_nested_value( $donor, $field );
}

/**
 * Get shelter statistics for block binding.
 *
 * @since 1.0.0
 *
 * @param array    $source_args    Binding source arguments.
 * @param WP_Block $block_instance The block instance.
 * @param string   $attribute_name The attribute being bound.
 * @return mixed The bound value.
 */
function get_stats_value( array $source_args, $block_instance, string $attribute_name ) {
    $stat = $source_args['stat'] ?? null;
    $period = $source_args['period'] ?? 'all_time';

    if ( ! $stat ) {
        return null;
    }

    // Get stats via ability if available.
    $ability = wp_get_ability( 'shelter-donations/get-stats' );

    if ( $ability ) {
        $stats = $ability->execute( [ 'period' => $period ] );

        if ( ! is_wp_error( $stats ) && isset( $stats[ $stat ] ) ) {
            return $stats[ $stat ];
        }
    }

    // Fallback to direct calculation for common stats.
    return calculate_stat( $stat, $period );
}

/**
 * Get campaign data for block binding.
 *
 * @since 1.0.0
 *
 * @param array    $source_args    Binding source arguments.
 * @param WP_Block $block_instance The block instance.
 * @param string   $attribute_name The attribute being bound.
 * @return mixed The bound value.
 */
function get_campaign_value( array $source_args, $block_instance, string $attribute_name ) {
    $field = $source_args['field'] ?? null;
    $campaign_id = $source_args['id'] ?? null;

    if ( ! $field ) {
        return null;
    }

    // Get campaign term.
    $campaign = $campaign_id ? get_term( (int) $campaign_id, 'sd_campaign' ) : null;

    if ( ! $campaign || is_wp_error( $campaign ) ) {
        return null;
    }

    // Get campaign meta.
    $goal = (float) get_term_meta( $campaign->term_id, '_sd_goal', true );
    $end_date = get_term_meta( $campaign->term_id, '_sd_end_date', true );

    // Calculate raised amount.
    $raised = calculate_campaign_raised( $campaign->term_id );

    // Calculate progress.
    $progress = $goal > 0 ? min( 100, ( $raised / $goal ) * 100 ) : 0;

    $campaign_data = [
        'id'                 => $campaign->term_id,
        'name'               => $campaign->name,
        'description'        => $campaign->description,
        'slug'               => $campaign->slug,
        'goal'               => $goal,
        'goal_formatted'     => Helpers\format_currency( $goal ),
        'raised'             => $raised,
        'raised_formatted'   => Helpers\format_currency( $raised ),
        'progress'           => round( $progress, 1 ),
        'progress_formatted' => round( $progress, 1 ) . '%',
        'remaining'          => max( 0, $goal - $raised ),
        'remaining_formatted' => Helpers\format_currency( max( 0, $goal - $raised ) ),
        'end_date'           => $end_date,
        'end_date_formatted' => $end_date ? Helpers\format_date( $end_date ) : '',
        'is_active'          => ! $end_date || strtotime( $end_date ) >= time(),
        'donor_count'        => calculate_campaign_donors( $campaign->term_id ),
    ];

    return get_nested_value( $campaign_data, $field );
}

/**
 * Get membership tier data for block binding.
 *
 * @since 1.0.0
 *
 * @param array    $source_args    Binding source arguments.
 * @param WP_Block $block_instance The block instance.
 * @param string   $attribute_name The attribute being bound.
 * @return mixed The bound value.
 */
function get_tier_value( array $source_args, $block_instance, string $attribute_name ) {
    $tier_slug = $source_args['tier'] ?? null;
    $field = $source_args['field'] ?? null;
    $type = $source_args['type'] ?? 'individual';

    if ( ! $tier_slug || ! $field ) {
        return null;
    }

    $tiers = Config::get_item( 'tiers', $type, [] );
    $tier = $tiers[ $tier_slug ] ?? null;

    if ( ! $tier ) {
        return null;
    }

    // Add computed fields.
    $tier['slug'] = $tier_slug;
    $tier['price_formatted'] = Helpers\format_currency( $tier['price'] ?? 0 );

    return get_nested_value( $tier, $field );
}

/**
 * Get nested value from array using dot notation.
 *
 * @since 1.0.0
 *
 * @param array  $data The data array.
 * @param string $path The dot-notation path.
 * @return mixed The value or null.
 */
function get_nested_value( array $data, string $path ) {
    $keys = explode( '.', $path );
    $value = $data;

    foreach ( $keys as $key ) {
        if ( is_array( $value ) && isset( $value[ $key ] ) ) {
            $value = $value[ $key ];
        } else {
            return null;
        }
    }

    // Format arrays as comma-separated string for block content.
    if ( is_array( $value ) ) {
        return implode( ', ', array_filter( $value, 'is_string' ) );
    }

    return $value;
}

/**
 * Get current user's donor ID.
 *
 * @since 1.0.0
 *
 * @return int|null Donor ID or null.
 */
function get_current_user_donor_id(): ?int {
    $user_id = get_current_user_id();

    if ( ! $user_id ) {
        return null;
    }

    // Check transient cache first.
    $cache_key = "sd_donor_id_{$user_id}";
    $donor_id = get_transient( $cache_key );

    if ( false !== $donor_id ) {
        return $donor_id ?: null;
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
        'no_found_rows'  => true,
    ] );

    $donor_id = $donors[0] ?? 0;

    // Cache for 1 hour.
    set_transient( $cache_key, $donor_id, HOUR_IN_SECONDS );

    return $donor_id ?: null;
}

/**
 * Invalidate donor ID cache when donor is updated.
 *
 * @since 2.0.0
 *
 * @param int $post_id Post ID.
 */
function invalidate_donor_cache( int $post_id ): void {
    if ( 'sd_donor' !== get_post_type( $post_id ) ) {
        return;
    }

    $user_id = get_post_meta( $post_id, '_sd_user_id', true );
    if ( $user_id ) {
        delete_transient( "sd_donor_id_{$user_id}" );
    }
}
add_action( 'save_post_sd_donor', __NAMESPACE__ . '\\invalidate_donor_cache' );

/**
 * Calculate a shelter statistic.
 *
 * @since 1.0.0
 *
 * @param string $stat   The stat to calculate.
 * @param string $period The time period.
 * @return mixed The stat value.
 */
function calculate_stat( string $stat, string $period ) {
    global $wpdb;

    $date_clause = '';
    if ( 'this_year' === $period ) {
        $date_clause = $wpdb->prepare(
            " AND pm_date.meta_value >= %s",
            wp_date( 'Y-01-01' )
        );
    } elseif ( 'this_month' === $period ) {
        $date_clause = $wpdb->prepare(
            " AND pm_date.meta_value >= %s",
            wp_date( 'Y-m-01' )
        );
    }

    switch ( $stat ) {
        case 'total_donations':
        case 'total_donations_formatted':
            $total = (float) $wpdb->get_var( "
                SELECT COALESCE(SUM(pm.meta_value), 0)
                FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_amount'
                JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_sd_donation_date'
                WHERE p.post_type = 'sd_donation'
                AND p.post_status = 'publish'
                {$date_clause}
            " );
            return 'total_donations_formatted' === $stat ? Helpers\format_currency( $total ) : $total;

        case 'donation_count':
            return (int) $wpdb->get_var( "
                SELECT COUNT(*)
                FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_sd_donation_date'
                WHERE p.post_type = 'sd_donation'
                AND p.post_status = 'publish'
                {$date_clause}
            " );

        case 'donor_count':
            return (int) $wpdb->get_var( "
                SELECT COUNT(DISTINCT pm.meta_value)
                FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_donor_id'
                JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_sd_donation_date'
                WHERE p.post_type = 'sd_donation'
                AND p.post_status = 'publish'
                {$date_clause}
            " );

        case 'active_members':
            $today = wp_date( 'Y-m-d' );
            return (int) $wpdb->get_var( $wpdb->prepare( "
                SELECT COUNT(*)
                FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_end_date'
                WHERE p.post_type = 'sd_membership'
                AND p.post_status = 'publish'
                AND pm.meta_value >= %s
            ", $today ) );

        case 'memorial_count':
            return (int) $wpdb->get_var( "
                SELECT COUNT(*)
                FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_sd_donation_date'
                WHERE p.post_type = 'sd_memorial'
                AND p.post_status = 'publish'
                {$date_clause}
            " );

        default:
            return null;
    }
}

/**
 * Calculate total raised for a campaign.
 *
 * @since 1.0.0
 *
 * @param int $campaign_id The campaign term ID.
 * @return float Total raised.
 */
function calculate_campaign_raised( int $campaign_id ): float {
    global $wpdb;

    return (float) $wpdb->get_var( $wpdb->prepare( "
        SELECT COALESCE(SUM(pm.meta_value), 0)
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_amount'
        JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        WHERE p.post_type = 'sd_donation'
        AND p.post_status = 'publish'
        AND tr.term_taxonomy_id = %d
    ", $campaign_id ) );
}

/**
 * Calculate donor count for a campaign.
 *
 * @since 1.0.0
 *
 * @param int $campaign_id The campaign term ID.
 * @return int Donor count.
 */
function calculate_campaign_donors( int $campaign_id ): int {
    global $wpdb;

    return (int) $wpdb->get_var( $wpdb->prepare( "
        SELECT COUNT(DISTINCT pm.meta_value)
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_donor_id'
        JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        WHERE p.post_type = 'sd_donation'
        AND p.post_status = 'publish'
        AND tr.term_taxonomy_id = %d
    ", $campaign_id ) );
}

// Register binding sources immediately.
// This file is require_once'd from init priority 20, so hooking
// another add_action at the same priority would miss the boat.
register_binding_sources();
