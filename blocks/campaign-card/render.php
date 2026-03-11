<?php
/**
 * Campaign Card Block - Server-side render.
 *
 * Demonstrates using block bindings to populate a campaign card.
 * The block can receive a campaignId attribute or use the current
 * post context when used inside a Query Loop.
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

namespace Starter_Shelter\Blocks\CampaignCard;

use Starter_Shelter\Helpers;

// Get campaign ID from attributes or context.
$campaign_id = $attributes['campaignId'] ?? null;

// If no campaign ID, try to get from post context (for use in Query Loop).
if ( ! $campaign_id && ! empty( $block->context['postId'] ) ) {
    // Check if we're in a campaign context.
    $post_type = get_post_type( $block->context['postId'] );
    if ( 'sd_campaign' === $post_type ) {
        $campaign_id = $block->context['postId'];
    }
}

// If still no campaign, get active campaigns and use the first one.
if ( ! $campaign_id ) {
    $campaigns = get_terms( [
        'taxonomy'   => 'sd_campaign',
        'hide_empty' => false,
        'number'     => 1,
        'meta_query' => [
            [
                'key'     => '_sd_end_date',
                'value'   => wp_date( 'Y-m-d' ),
                'compare' => '>=',
                'type'    => 'DATE',
            ],
        ],
    ] );
    
    if ( ! empty( $campaigns ) && ! is_wp_error( $campaigns ) ) {
        $campaign_id = $campaigns[0]->term_id;
    }
}

// If no campaign found, show placeholder.
if ( ! $campaign_id ) {
    $wrapper_attributes = get_block_wrapper_attributes( [
        'class' => 'sd-campaign-card sd-campaign-card--empty',
    ] );
    
    echo '<div ' . $wrapper_attributes . '>';
    echo '<p>' . esc_html__( 'No active campaign found.', 'starter-shelter' ) . '</p>';
    echo '</div>';
    return;
}

// Get campaign data using the binding source.
$campaign_data = \Starter_Shelter\Blocks\get_campaign_value(
    [ 'id' => $campaign_id, 'field' => '' ],
    $block,
    ''
);

// If campaign data is an error or empty, extract manually.
if ( ! $campaign_data ) {
    $campaign = get_term( $campaign_id, 'sd_campaign' );
    
    if ( ! $campaign || is_wp_error( $campaign ) ) {
        return;
    }
    
    $goal   = (float) get_term_meta( $campaign_id, '_sd_goal', true );
    $end_date = get_term_meta( $campaign_id, '_sd_end_date', true );
    $raised = \Starter_Shelter\Blocks\calculate_campaign_raised( $campaign_id );
    $donors = \Starter_Shelter\Blocks\calculate_campaign_donors( $campaign_id );
    $progress = $goal > 0 ? min( 100, ( $raised / $goal ) * 100 ) : 0;
    
    $campaign_data = [
        'id'                  => $campaign_id,
        'name'                => $campaign->name,
        'description'         => $campaign->description,
        'goal'                => $goal,
        'goal_formatted'      => Helpers\format_currency( $goal ),
        'raised'              => $raised,
        'raised_formatted'    => Helpers\format_currency( $raised ),
        'progress'            => round( $progress, 1 ),
        'remaining'           => max( 0, $goal - $raised ),
        'remaining_formatted' => Helpers\format_currency( max( 0, $goal - $raised ) ),
        'end_date'            => $end_date,
        'end_date_formatted'  => $end_date ? Helpers\format_date( $end_date ) : '',
        'is_active'           => ! $end_date || strtotime( $end_date ) >= time(),
        'donor_count'         => $donors,
    ];
}

// Block settings.
$show_goal        = $attributes['showGoal'] ?? true;
$show_raised      = $attributes['showRaised'] ?? true;
$show_donors      = $attributes['showDonors'] ?? true;
$show_end_date    = $attributes['showEndDate'] ?? true;
$show_donate_btn  = $attributes['showDonateButton'] ?? true;
$progress_color   = $attributes['progressBarColor'] ?? '#059669';

// Generate unique ID for animations.
$block_id = 'campaign-card-' . wp_unique_id();

// Initialize interactivity state.
wp_interactivity_state(
    'starter-shelter/campaign-card',
    [
        'campaign' => $campaign_data,
        'isAnimated' => false,
    ]
);

// Context for this instance.
$context = [
    'campaignId' => $campaign_id,
];

// Build wrapper attributes.
$wrapper_classes = [
    'sd-campaign-card',
    $campaign_data['is_active'] ? 'is-active' : 'is-ended',
];

$wrapper_attributes = get_block_wrapper_attributes( [
    'class' => implode( ' ', $wrapper_classes ),
    'id'    => $block_id,
    'style' => '--sd-progress-color: ' . esc_attr( $progress_color ),
] );

// Interactivity attributes.
$interactive_attrs = sprintf(
    'data-wp-interactive="starter-shelter/campaign-card" %s data-wp-init="callbacks.init"',
    wp_interactivity_data_wp_context( $context )
);
?>

<div <?php echo $wrapper_attributes; ?> <?php echo $interactive_attrs; ?>>
    
    <div class="sd-campaign-header">
        <h3 class="sd-campaign-name"><?php echo esc_html( $campaign_data['name'] ); ?></h3>
        <?php if ( ! $campaign_data['is_active'] ) : ?>
        <span class="sd-campaign-badge sd-campaign-ended">
            <?php esc_html_e( 'Ended', 'starter-shelter' ); ?>
        </span>
        <?php endif; ?>
    </div>

    <?php if ( $campaign_data['description'] ) : ?>
    <p class="sd-campaign-description">
        <?php echo esc_html( wp_trim_words( $campaign_data['description'], 20 ) ); ?>
    </p>
    <?php endif; ?>

    <!-- Progress Bar -->
    <div class="sd-progress-wrapper">
        <div class="sd-progress-bar">
            <div 
                class="sd-progress-fill" 
                style="width: <?php echo esc_attr( $campaign_data['progress'] ); ?>%;"
                data-wp-style--width="state.progressWidth"
                role="progressbar"
                aria-valuenow="<?php echo esc_attr( $campaign_data['progress'] ); ?>"
                aria-valuemin="0"
                aria-valuemax="100"
            >
            </div>
        </div>
        <span class="sd-progress-text" data-wp-text="state.progressText">
            <?php echo esc_html( $campaign_data['progress'] . '%' ); ?>
        </span>
    </div>

    <!-- Stats Grid -->
    <div class="sd-campaign-stats">
        <?php if ( $show_raised ) : ?>
        <div class="sd-campaign-stat sd-stat-raised">
            <span class="sd-stat-value" data-wp-text="state.campaign.raised_formatted">
                <?php echo esc_html( $campaign_data['raised_formatted'] ); ?>
            </span>
            <span class="sd-stat-label"><?php esc_html_e( 'Raised', 'starter-shelter' ); ?></span>
        </div>
        <?php endif; ?>

        <?php if ( $show_goal ) : ?>
        <div class="sd-campaign-stat sd-stat-goal">
            <span class="sd-stat-value">
                <?php echo esc_html( $campaign_data['goal_formatted'] ); ?>
            </span>
            <span class="sd-stat-label"><?php esc_html_e( 'Goal', 'starter-shelter' ); ?></span>
        </div>
        <?php endif; ?>

        <?php if ( $show_donors ) : ?>
        <div class="sd-campaign-stat sd-stat-donors">
            <span class="sd-stat-value" data-wp-text="state.campaign.donor_count">
                <?php echo esc_html( $campaign_data['donor_count'] ); ?>
            </span>
            <span class="sd-stat-label"><?php esc_html_e( 'Donors', 'starter-shelter' ); ?></span>
        </div>
        <?php endif; ?>

        <?php if ( $show_end_date && $campaign_data['end_date'] ) : ?>
        <div class="sd-campaign-stat sd-stat-deadline">
            <span class="sd-stat-value">
                <?php echo esc_html( $campaign_data['end_date_formatted'] ); ?>
            </span>
            <span class="sd-stat-label"><?php esc_html_e( 'Deadline', 'starter-shelter' ); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <?php if ( $show_donate_btn && $campaign_data['is_active'] ) : ?>
    <div class="sd-campaign-action">
        <a 
            href="<?php echo esc_url( add_query_arg( 'campaign', $campaign_id, '/donate/' ) ); ?>"
            class="sd-donate-button wp-element-button"
            data-wp-on--click="actions.handleDonate"
        >
            <?php esc_html_e( 'Donate Now', 'starter-shelter' ); ?>
        </a>
    </div>
    <?php endif; ?>

</div>
