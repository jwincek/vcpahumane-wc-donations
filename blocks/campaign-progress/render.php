<?php
/**
 * Campaign Progress Block - Server-side render.
 *
 * @package Starter_Shelter
 * @subpackage Blocks
 * @since 2.0.0
 */

declare( strict_types = 1 );

use Starter_Shelter\Helpers;
use Starter_Shelter\Blocks;

// Get campaign ID.
$campaign_id = $attributes['campaignId'] ?? null;

if ( ! $campaign_id ) {
    return;
}

// Get campaign term.
$campaign = get_term( $campaign_id, 'sd_campaign' );

if ( ! $campaign || is_wp_error( $campaign ) ) {
    return;
}

// Block attributes.
$show_title       = $attributes['showTitle'] ?? true;
$show_description = $attributes['showDescription'] ?? false;
$show_donors      = $attributes['showDonorCount'] ?? true;
$show_end_date    = $attributes['showEndDate'] ?? true;
$show_percentage  = $attributes['showPercentage'] ?? true;
$bar_height       = $attributes['progressBarHeight'] ?? 24;
$bar_color        = $attributes['progressBarColor'] ?? '#059669';
$layout           = $attributes['layout'] ?? 'horizontal';
$refresh_interval = $attributes['refreshInterval'] ?? 0;

// Get campaign data.
$goal = (float) get_term_meta( $campaign->term_id, '_sd_goal', true );
$end_date = get_term_meta( $campaign->term_id, '_sd_end_date', true );

// Calculate raised amount.
$raised = Blocks\calculate_campaign_raised( $campaign->term_id );
$donor_count = Blocks\calculate_campaign_donors( $campaign->term_id );

// Calculate progress.
$progress = $goal > 0 ? min( 100, ( $raised / $goal ) * 100 ) : 0;
$remaining = max( 0, $goal - $raised );

// Is campaign active?
$is_active = ! $end_date || strtotime( $end_date ) >= time();

// Days remaining.
$days_remaining = null;
if ( $end_date && $is_active ) {
    $days_remaining = max( 0, ceil( ( strtotime( $end_date ) - time() ) / DAY_IN_SECONDS ) );
}

// Set state for this campaign.
wp_interactivity_state( 'starter-shelter/campaign', [
    'campaigns' => [
        $campaign_id => [
            'id'            => $campaign_id,
            'name'          => $campaign->name,
            'description'   => $campaign->description,
            'goal'          => $goal,
            'raised'        => $raised,
            'progress'      => round( $progress, 1 ),
            'remaining'     => $remaining,
            'donorCount'    => $donor_count,
            'endDate'       => $end_date,
            'isActive'      => $is_active,
            'daysRemaining' => $days_remaining,
        ],
    ],
] );

// Context.
$context = [
    'campaignId'      => $campaign_id,
    'refreshInterval' => $refresh_interval,
];

// Wrapper attributes.
$classes = [
    'sd-campaign-progress',
    "sd-layout--{$layout}",
    $is_active ? 'is-active' : 'is-ended',
    $progress >= 100 ? 'is-complete' : '',
];

$wrapper_attrs = [
    'class'               => implode( ' ', array_filter( $classes ) ),
    'data-wp-interactive' => wp_json_encode( [ 'namespace' => 'starter-shelter/campaign' ] ),
    'data-wp-context'     => wp_json_encode( $context ),
];

if ( $refresh_interval > 0 ) {
    $wrapper_attrs['data-wp-watch'] = 'callbacks.autoRefresh';
}

$wrapper_attributes = get_block_wrapper_attributes( $wrapper_attrs );
?>

<div <?php echo $wrapper_attributes; ?>>
    
    <?php if ( $show_title ) : ?>
    <h3 class="sd-campaign-title"><?php echo esc_html( $campaign->name ); ?></h3>
    <?php endif; ?>

    <?php if ( $show_description && $campaign->description ) : ?>
    <p class="sd-campaign-description"><?php echo esc_html( $campaign->description ); ?></p>
    <?php endif; ?>

    <!-- Progress Bar -->
    <div class="sd-progress-container">
        <div 
            class="sd-progress-bar"
            role="progressbar"
            aria-valuenow="<?php echo esc_attr( round( $progress ) ); ?>"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="<?php esc_attr_e( 'Campaign progress', 'starter-shelter' ); ?>"
            style="height: <?php echo (int) $bar_height; ?>px;"
            data-wp-bind--aria-valuenow="state.campaigns[context.campaignId].progress"
        >
            <div 
                class="sd-progress-fill"
                style="width: <?php echo esc_attr( $progress ); ?>%; background-color: <?php echo esc_attr( $bar_color ); ?>;"
                data-wp-style--width="callbacks.getProgressWidth"
            >
                <?php if ( $show_percentage ) : ?>
                <span 
                    class="sd-progress-label"
                    data-wp-text="callbacks.getProgressLabel"
                >
                    <?php echo esc_html( round( $progress ) . '%' ); ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="sd-campaign-stats">
        <div class="sd-stat sd-stat-raised">
            <span class="sd-stat-value" data-wp-text="callbacks.getRaisedFormatted">
                <?php echo esc_html( Helpers\format_currency( $raised ) ); ?>
            </span>
            <span class="sd-stat-label"><?php esc_html_e( 'raised', 'starter-shelter' ); ?></span>
        </div>

        <div class="sd-stat sd-stat-goal">
            <span class="sd-stat-label"><?php esc_html_e( 'of', 'starter-shelter' ); ?></span>
            <span class="sd-stat-value">
                <?php echo esc_html( Helpers\format_currency( $goal ) ); ?>
            </span>
            <span class="sd-stat-label"><?php esc_html_e( 'goal', 'starter-shelter' ); ?></span>
        </div>

        <?php if ( $show_donors ) : ?>
        <div class="sd-stat sd-stat-donors">
            <span class="sd-stat-value" data-wp-text="state.campaigns[context.campaignId].donorCount">
                <?php echo esc_html( number_format_i18n( $donor_count ) ); ?>
            </span>
            <span class="sd-stat-label"><?php esc_html_e( 'donors', 'starter-shelter' ); ?></span>
        </div>
        <?php endif; ?>

        <?php if ( $show_end_date && $days_remaining !== null ) : ?>
        <div class="sd-stat sd-stat-time">
            <span class="sd-stat-value">
                <?php echo esc_html( number_format_i18n( $days_remaining ) ); ?>
            </span>
            <span class="sd-stat-label"><?php esc_html_e( 'days left', 'starter-shelter' ); ?></span>
        </div>
        <?php elseif ( $show_end_date && ! $is_active ) : ?>
        <div class="sd-stat sd-stat-ended">
            <span class="sd-stat-label"><?php esc_html_e( 'Campaign ended', 'starter-shelter' ); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <?php if ( $progress >= 100 ) : ?>
    <div class="sd-campaign-complete" role="status">
        <svg class="sd-complete-icon" viewBox="0 0 20 20" width="20" height="20" aria-hidden="true">
            <path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" fill="currentColor"/>
        </svg>
        <?php esc_html_e( 'Goal reached! Thank you!', 'starter-shelter' ); ?>
    </div>
    <?php endif; ?>
</div>
