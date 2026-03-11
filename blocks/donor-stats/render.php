<?php
/**
 * Donor Stats Block - Server-side render.
 *
 * Demonstrates the "wrapper block with bound core blocks" pattern.
 * This block renders core blocks (paragraphs, headings) that use
 * block bindings to pull in shelter statistics.
 *
 * @package Starter_Shelter
 * @subpackage Blocks
 * @since 1.0.0
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Blocks\DonorStats;

use Starter_Shelter\Helpers;

// Block settings.
$period           = $attributes['period'] ?? 'all_time';
$show_total       = $attributes['showTotal'] ?? true;
$show_count       = $attributes['showCount'] ?? true;
$show_donors      = $attributes['showDonors'] ?? true;
$show_members     = $attributes['showMembers'] ?? false;
$animate_numbers  = $attributes['animateNumbers'] ?? true;
$refresh_interval = $attributes['refreshInterval'] ?? 0;

// Get statistics using the binding source.
$stats = \Starter_Shelter\Blocks\calculate_stat( 'total_donations', $period );
$count = \Starter_Shelter\Blocks\calculate_stat( 'donation_count', $period );
$donors = \Starter_Shelter\Blocks\calculate_stat( 'donor_count', $period );
$members = \Starter_Shelter\Blocks\calculate_stat( 'active_members', $period );

// Format values.
$total_formatted = Helpers\format_currency( $stats );

// Generate unique ID.
$block_id = 'donor-stats-' . wp_unique_id();

// Initialize interactivity state if animation is enabled.
if ( $animate_numbers ) {
    wp_interactivity_state(
        'starter-shelter/donor-stats',
        [
            'stats' => [
                'total'   => $stats,
                'count'   => $count,
                'donors'  => $donors,
                'members' => $members,
            ],
            'formatted' => [
                'total'   => $total_formatted,
                'count'   => number_format( $count ),
                'donors'  => number_format( $donors ),
                'members' => number_format( $members ),
            ],
            'animated' => [
                'total'   => 0,
                'count'   => 0,
                'donors'  => 0,
                'members' => 0,
            ],
            'isAnimated' => false,
        ]
    );
}

// Context for this block instance.
$context = [
    'period'          => $period,
    'refreshInterval' => $refresh_interval,
    'animateNumbers'  => $animate_numbers,
];

// Build wrapper attributes.
$wrapper_attributes = get_block_wrapper_attributes( [
    'class' => 'sd-donor-stats',
    'id'    => $block_id,
] );

// Interactivity attributes.
$interactive_attrs = '';
if ( $animate_numbers ) {
    $interactive_attrs = sprintf(
        'data-wp-interactive="starter-shelter/donor-stats" %s data-wp-init="callbacks.init" data-wp-watch="callbacks.watchViewport"',
        wp_interactivity_data_wp_context( $context )
    );
}
?>

<div <?php echo $wrapper_attributes; ?> <?php echo $interactive_attrs; ?>>
    
    <?php if ( $show_total ) : ?>
    <div class="sd-stat-card sd-stat-total">
        <div class="sd-stat-icon">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h1.96c.1 1.05.82 1.87 2.65 1.87 1.96 0 2.4-.98 2.4-1.59 0-.83-.44-1.61-2.67-2.14-2.48-.6-4.18-1.62-4.18-3.67 0-1.72 1.39-2.84 3.11-3.21V4h2.67v1.95c1.86.45 2.79 1.86 2.85 3.39H14.3c-.05-1.11-.64-1.87-2.22-1.87-1.5 0-2.4.68-2.4 1.64 0 .84.65 1.39 2.67 1.91s4.18 1.39 4.18 3.91c-.01 1.83-1.38 2.83-3.12 3.16z"/>
            </svg>
        </div>
        <div class="sd-stat-content">
            <span 
                class="sd-stat-value" 
                <?php if ( $animate_numbers ) : ?>
                data-wp-text="state.formatted.total"
                data-wp-class--is-animating="state.isAnimating"
                <?php endif; ?>
            >
                <?php echo esc_html( $total_formatted ); ?>
            </span>
            <span class="sd-stat-label"><?php esc_html_e( 'Total Donations', 'starter-shelter' ); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( $show_count ) : ?>
    <div class="sd-stat-card sd-stat-count">
        <div class="sd-stat-icon">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M19.5 3.5L18 2l-1.5 1.5L15 2l-1.5 1.5L12 2l-1.5 1.5L9 2 7.5 3.5 6 2v14H3v3c0 1.66 1.34 3 3 3h12c1.66 0 3-1.34 3-3V2l-1.5 1.5zM19 19c0 .55-.45 1-1 1s-1-.45-1-1v-3H8V5h11v14z"/>
            </svg>
        </div>
        <div class="sd-stat-content">
            <span 
                class="sd-stat-value"
                <?php if ( $animate_numbers ) : ?>
                data-wp-text="state.formatted.count"
                <?php endif; ?>
            >
                <?php echo esc_html( number_format( $count ) ); ?>
            </span>
            <span class="sd-stat-label"><?php esc_html_e( 'Donations Made', 'starter-shelter' ); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( $show_donors ) : ?>
    <div class="sd-stat-card sd-stat-donors">
        <div class="sd-stat-icon">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
            </svg>
        </div>
        <div class="sd-stat-content">
            <span 
                class="sd-stat-value"
                <?php if ( $animate_numbers ) : ?>
                data-wp-text="state.formatted.donors"
                <?php endif; ?>
            >
                <?php echo esc_html( number_format( $donors ) ); ?>
            </span>
            <span class="sd-stat-label"><?php esc_html_e( 'Generous Donors', 'starter-shelter' ); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( $show_members ) : ?>
    <div class="sd-stat-card sd-stat-members">
        <div class="sd-stat-icon">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M20 6h-2.18c.11-.31.18-.65.18-1a2.996 2.996 0 0 0-5.5-1.65l-.5.67-.5-.68C10.96 2.54 10.05 2 9 2 7.34 2 6 3.34 6 5c0 .35.07.69.18 1H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-5-2c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zM9 4c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm11 15H4v-2h16v2zm0-5H4V8h5.08L7 10.83 8.62 12 11 8.76l1-1.36 1 1.36L15.38 12 17 10.83 14.92 8H20v6z"/>
            </svg>
        </div>
        <div class="sd-stat-content">
            <span 
                class="sd-stat-value"
                <?php if ( $animate_numbers ) : ?>
                data-wp-text="state.formatted.members"
                <?php endif; ?>
            >
                <?php echo esc_html( number_format( $members ) ); ?>
            </span>
            <span class="sd-stat-label"><?php esc_html_e( 'Active Members', 'starter-shelter' ); ?></span>
        </div>
    </div>
    <?php endif; ?>

</div>
