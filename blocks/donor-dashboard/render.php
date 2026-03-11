<?php
/**
 * Donor Dashboard Block - Server-side render.
 *
 * @package Starter_Shelter
 * @subpackage Blocks
 * @since 2.0.0
 */

declare( strict_types = 1 );

use Starter_Shelter\Core\Entity_Hydrator;
use Starter_Shelter\Helpers;
use Starter_Shelter\Blocks;

// Block attributes.
$show_stats        = $attributes['showStats'] ?? true;
$show_recent_gifts = $attributes['showRecentGifts'] ?? true;
$show_membership   = $attributes['showMembership'] ?? true;
$show_donor_level  = $attributes['showDonorLevel'] ?? true;
$recent_count      = $attributes['recentGiftsCount'] ?? 5;
$layout            = $attributes['layout'] ?? 'cards';
$guest_message     = $attributes['guestMessage'] ?: __( 'Please log in to view your donor dashboard.', 'starter-shelter' );

// Check if user is logged in.
$is_logged_in = is_user_logged_in();
$donor_id = Blocks\get_current_user_donor_id();

// Get donor data if logged in.
$donor = null;
$stats = null;
$recent_gifts = [];
$membership = null;

if ( $donor_id ) {
    $donor = Entity_Hydrator::get( 'sd_donor', $donor_id );
    
    // Get stats.
    $stats = [
        'lifetime_giving' => (float) get_post_meta( $donor_id, '_sd_lifetime_giving', true ),
        'donation_count'  => (int) get_post_meta( $donor_id, '_sd_donation_count', true ),
        'first_gift_date' => get_post_meta( $donor_id, '_sd_first_gift_date', true ),
        'last_gift_date'  => get_post_meta( $donor_id, '_sd_last_gift_date', true ),
    ];
    
    // Get recent donations.
    $recent_query = new WP_Query( [
        'post_type'      => 'sd_donation',
        'posts_per_page' => $recent_count,
        'meta_query'     => [
            [
                'key'   => '_sd_donor_id',
                'value' => $donor_id,
            ],
        ],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );
    
    $recent_gifts = array_map( function( $post ) {
        return [
            'id'         => $post->ID,
            'amount'     => (float) get_post_meta( $post->ID, '_sd_amount', true ),
            'date'       => get_the_date( get_option( 'date_format' ), $post ),
            'allocation' => get_post_meta( $post->ID, '_sd_allocation', true ),
            'type'       => get_post_meta( $post->ID, '_sd_donation_type', true ) ?: 'general',
        ];
    }, $recent_query->posts );
    
    // Get membership status.
    if ( $show_membership ) {
        $membership_query = new WP_Query( [
            'post_type'      => 'sd_membership',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_sd_donor_id',
                    'value' => $donor_id,
                ],
                [
                    'key'     => '_sd_end_date',
                    'value'   => wp_date( 'Y-m-d' ),
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
            ],
        ] );
        
        if ( $membership_query->have_posts() ) {
            $mem_post = $membership_query->posts[0];
            $membership = [
                'id'        => $mem_post->ID,
                'tier'      => get_post_meta( $mem_post->ID, '_sd_tier', true ),
                'type'      => get_post_meta( $mem_post->ID, '_sd_membership_type', true ),
                'start'     => get_post_meta( $mem_post->ID, '_sd_start_date', true ),
                'end'       => get_post_meta( $mem_post->ID, '_sd_end_date', true ),
                'is_active' => true,
            ];
        }
    }
}

// Set state.
wp_interactivity_state( 'starter-shelter/donor', [
    'isLoading'   => false,
    'isLoggedIn'  => $is_logged_in,
    'donor'       => $donor,
    'stats'       => $stats,
    'recentGifts' => $recent_gifts,
    'membership'  => $membership,
] );

// Wrapper attributes.
$classes = [
    'sd-donor-dashboard',
    "sd-layout--{$layout}",
    ! $is_logged_in ? 'is-guest' : '',
];

$wrapper_attributes = get_block_wrapper_attributes( [
    'class'               => implode( ' ', array_filter( $classes ) ),
    'data-wp-interactive' => wp_json_encode( [ 'namespace' => 'starter-shelter/donor' ] ),
    'data-wp-init'        => 'callbacks.init',
] );
?>

<div <?php echo $wrapper_attributes; ?>>

    <?php if ( ! $is_logged_in ) : ?>
    <!-- Guest State -->
    <div class="sd-dashboard-guest">
        <svg class="sd-guest-icon" viewBox="0 0 24 24" width="48" height="48" aria-hidden="true">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z" fill="currentColor"/>
        </svg>
        <p class="sd-guest-message"><?php echo esc_html( $guest_message ); ?></p>
        <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="sd-login-button wp-element-button">
            <?php esc_html_e( 'Log In', 'starter-shelter' ); ?>
        </a>
    </div>

    <?php else : ?>
    <!-- Loading State -->
    <div class="sd-dashboard-loading" data-wp-bind--hidden="!state.isLoading">
        <div class="sd-spinner"></div>
        <p><?php esc_html_e( 'Loading your dashboard...', 'starter-shelter' ); ?></p>
    </div>

    <!-- Dashboard Content -->
    <div class="sd-dashboard-content" data-wp-bind--hidden="state.isLoading">
        
        <!-- Header -->
        <header class="sd-dashboard-header">
            <div class="sd-donor-greeting">
                <h2>
                    <?php
                    printf(
                        /* translators: %s: donor name */
                        esc_html__( 'Welcome back, %s!', 'starter-shelter' ),
                        '<span data-wp-text="state.donor.display_name">' . esc_html( $donor['display_name'] ?? __( 'Friend', 'starter-shelter' ) ) . '</span>'
                    );
                    ?>
                </h2>
                
                <?php if ( $show_donor_level && isset( $donor['donor_level'] ) ) : ?>
                <span class="sd-donor-level" data-wp-text="callbacks.donorLevelLabel">
                    <?php 
                    $levels = [
                        'new' => __( 'New Donor', 'starter-shelter' ),
                        'bronze' => __( 'Bronze', 'starter-shelter' ),
                        'silver' => __( 'Silver', 'starter-shelter' ),
                        'gold' => __( 'Gold', 'starter-shelter' ),
                        'platinum' => __( 'Platinum', 'starter-shelter' ),
                    ];
                    echo esc_html( $levels[ $donor['donor_level'] ] ?? __( 'Donor', 'starter-shelter' ) );
                    ?>
                </span>
                <?php endif; ?>
            </div>
        </header>

        <?php if ( $show_stats && $stats ) : ?>
        <!-- Stats Grid -->
        <section class="sd-dashboard-stats">
            <h3 class="sd-section-title"><?php esc_html_e( 'Your Impact', 'starter-shelter' ); ?></h3>
            
            <div class="sd-stats-grid">
                <div class="sd-stat-card">
                    <span class="sd-stat-value" data-wp-text="callbacks.lifetimeGivingFormatted">
                        <?php echo esc_html( Helpers\format_currency( $stats['lifetime_giving'] ) ); ?>
                    </span>
                    <span class="sd-stat-label"><?php esc_html_e( 'Lifetime Giving', 'starter-shelter' ); ?></span>
                </div>
                
                <div class="sd-stat-card">
                    <span class="sd-stat-value" data-wp-text="state.stats.donation_count">
                        <?php echo esc_html( number_format_i18n( $stats['donation_count'] ) ); ?>
                    </span>
                    <span class="sd-stat-label"><?php esc_html_e( 'Total Gifts', 'starter-shelter' ); ?></span>
                </div>
                
                <?php if ( $stats['first_gift_date'] ) : ?>
                <div class="sd-stat-card">
                    <span class="sd-stat-value">
                        <?php echo esc_html( wp_date( 'M Y', strtotime( $stats['first_gift_date'] ) ) ); ?>
                    </span>
                    <span class="sd-stat-label"><?php esc_html_e( 'Donor Since', 'starter-shelter' ); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ( $show_membership ) : ?>
        <!-- Membership Status -->
        <section class="sd-dashboard-membership">
            <h3 class="sd-section-title"><?php esc_html_e( 'Membership', 'starter-shelter' ); ?></h3>
            
            <?php if ( $membership ) : ?>
            <div class="sd-membership-card is-active">
                <div class="sd-membership-badge">
                    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
                        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" fill="currentColor"/>
                    </svg>
                    <span data-wp-text="state.membership.tier">
                        <?php echo esc_html( ucfirst( $membership['tier'] ?? '' ) ); ?>
                    </span>
                </div>
                <div class="sd-membership-details">
                    <p class="sd-membership-status"><?php esc_html_e( 'Active Member', 'starter-shelter' ); ?></p>
                    <p class="sd-membership-expires">
                        <?php
                        printf(
                            /* translators: %s: expiration date */
                            esc_html__( 'Renews: %s', 'starter-shelter' ),
                            esc_html( wp_date( get_option( 'date_format' ), strtotime( $membership['end'] ) ) )
                        );
                        ?>
                    </p>
                </div>
            </div>
            <?php else : ?>
            <div class="sd-membership-card is-inactive">
                <p><?php esc_html_e( 'You are not currently a member.', 'starter-shelter' ); ?></p>
                <a href="<?php echo esc_url( home_url( '/membership/' ) ); ?>" class="sd-join-button wp-element-button">
                    <?php esc_html_e( 'Become a Member', 'starter-shelter' ); ?>
                </a>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ( $show_recent_gifts && ! empty( $recent_gifts ) ) : ?>
        <!-- Recent Gifts -->
        <section class="sd-dashboard-gifts">
            <h3 class="sd-section-title"><?php esc_html_e( 'Recent Gifts', 'starter-shelter' ); ?></h3>
            
            <ul class="sd-gifts-list">
                <?php foreach ( $recent_gifts as $gift ) : ?>
                <li class="sd-gift-item">
                    <span class="sd-gift-amount"><?php echo esc_html( Helpers\format_currency( $gift['amount'] ) ); ?></span>
                    <span class="sd-gift-type"><?php echo esc_html( ucfirst( $gift['type'] ) ); ?></span>
                    <span class="sd-gift-date"><?php echo esc_html( $gift['date'] ); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            
            <a href="<?php echo esc_url( wc_get_account_endpoint_url( 'donations' ) ); ?>" class="sd-view-all-link">
                <?php esc_html_e( 'View All Gifts →', 'starter-shelter' ); ?>
            </a>
        </section>
        <?php endif; ?>

        <!-- Quick Actions -->
        <section class="sd-dashboard-actions">
            <a href="<?php echo esc_url( home_url( '/donate/' ) ); ?>" class="sd-action-button wp-element-button">
                <?php esc_html_e( 'Make a Gift', 'starter-shelter' ); ?>
            </a>
            <a href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-account' ) ); ?>" class="sd-action-button sd-action-secondary">
                <?php esc_html_e( 'Update Profile', 'starter-shelter' ); ?>
            </a>
        </section>
    </div>
    <?php endif; ?>
</div>
