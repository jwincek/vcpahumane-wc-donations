<?php
/**
 * Donation Form Block - Server-side render.
 *
 * Simple donation form with amount selection and allocation.
 * For tribute/memorial gifts, use the Memorial Form block instead.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

declare( strict_types = 1 );

use Starter_Shelter\Core\Config;

$form_id = $attributes['formId'] ?: wp_unique_id( 'sd-donation-' );

// Attributes with defaults.
$preset_amounts   = $attributes['presetAmounts'] ?? [ 25, 50, 100, 250, 500 ];
$default_amount   = $attributes['defaultAmount'] ?? 50;
$show_allocation  = $attributes['showAllocation'] ?? true;
$show_anonymous   = $attributes['showAnonymous'] ?? true;
$campaign_id      = $attributes['campaignId'] ?? null;
$title            = $attributes['title'] ?? __( 'Make a Donation', 'starter-shelter' );
$subtitle         = $attributes['subtitle'] ?? __( 'Your gift helps animals in need.', 'starter-shelter' );
$submit_text      = $attributes['submitButtonText'] ?? __( 'Add to Cart', 'starter-shelter' );
$show_secure      = $attributes['showSecureBadge'] ?? true;
$min_amount       = $attributes['minAmount'] ?? 1;
$max_amount       = $attributes['maxAmount'] ?? 100000;

// Get allocations.
$allocations = [];
if ( class_exists( '\Starter_Shelter\Core\Config' ) ) {
    $allocations = Config::get_item( 'settings', 'allocations', [] );
}
if ( empty( $allocations ) ) {
    $allocations = [
        'general-fund' => __( 'General Fund', 'starter-shelter' ),
        'medical-care' => __( 'Medical Care', 'starter-shelter' ),
    ];
}

$campaign = $campaign_id ? get_term( $campaign_id, 'sd_campaign' ) : null;
$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '/checkout/';
$product_id = (int) get_option( 'sd_donation_product_id', 0 );
$product_ok = $product_id && function_exists( 'wc_get_product' ) && wc_get_product( $product_id );

// Initialize state.
wp_interactivity_state( 'starter-shelter/donation-form', [
    'forms' => [
        $form_id => [
            'amount'       => $default_amount,
            'customAmount' => '',
            'allocation'   => array_key_first( $allocations ) ?: 'general-fund',
            'isAnonymous'  => false,
            'dedication'   => '',
            'campaignId'   => $campaign_id,
            'isProcessing' => false,
            'error'        => null,
            'success'      => null,
        ],
    ],
] );

$context = wp_json_encode( [
    'formId'            => $form_id,
    'presetAmounts'     => $preset_amounts,
    'defaultAmount'     => $default_amount,
    'campaignId'        => $campaign_id,
    'minAmount'         => $min_amount,
    'maxAmount'         => $max_amount,
    'productType'       => 'donation',
    'checkoutUrl'       => $checkout_url,
    'productConfigured' => $product_ok,
] );

$wrapper = get_block_wrapper_attributes( [
    'class'               => 'sd-donation-form',
    'id'                  => $form_id,
    'data-wp-interactive' => '{"namespace":"starter-shelter/donation-form"}',
    'data-wp-context'     => $context,
    'data-wp-init'        => 'actions.initForm',
] );
?>
<div <?php echo $wrapper; ?>>
    <div class="sd-form-header">
        <?php if ( $title ) : ?><h2 class="sd-form-title"><?php echo esc_html( $title ); ?></h2><?php endif; ?>
        <?php if ( $subtitle ) : ?><p class="sd-form-subtitle"><?php echo esc_html( $subtitle ); ?></p><?php endif; ?>
        <?php if ( $campaign && ! is_wp_error( $campaign ) ) : ?>
            <div class="sd-campaign-badge"><?php printf( esc_html__( 'Supporting: %s', 'starter-shelter' ), '<strong>' . esc_html( $campaign->name ) . '</strong>' ); ?></div>
        <?php endif; ?>
        <?php if ( ! $product_ok ) : ?>
            <div class="sd-config-warning" role="alert"><p><?php esc_html_e( 'Donation form not configured.', 'starter-shelter' ); ?></p></div>
        <?php endif; ?>
    </div>

    <div class="sd-donation-form-inner">
        <!-- Amount Selection -->
        <fieldset class="sd-form-section sd-amount-section">
            <legend class="sd-section-label"><?php esc_html_e( 'Select Amount', 'starter-shelter' ); ?></legend>
            <div class="sd-preset-amounts" role="radiogroup">
                <?php foreach ( $preset_amounts as $amt ) : ?>
                    <button type="button" role="radio" class="sd-amount-button"
                        data-wp-on--click="actions.selectAmount" 
                        data-wp-class--selected="callbacks.isAmountSelected"
                        data-wp-context='{"buttonAmount":<?php echo (int) $amt; ?>}'>
                        $<?php echo number_format( $amt ); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="sd-custom-amount">
                <label for="<?php echo esc_attr( $form_id ); ?>-custom" class="sd-custom-label">
                    <?php esc_html_e( 'Or enter custom amount:', 'starter-shelter' ); ?>
                </label>
                <div class="sd-input-wrapper">
                    <span class="sd-currency-symbol">$</span>
                    <input type="number" 
                        id="<?php echo esc_attr( $form_id ); ?>-custom" 
                        class="sd-custom-input"
                        min="<?php echo esc_attr( $min_amount ); ?>" 
                        max="<?php echo esc_attr( $max_amount ); ?>" 
                        step="1" 
                        placeholder="0"
                        data-wp-on--input="actions.setCustomAmount" 
                        data-wp-on--focus="actions.clearPresetAmount"
                        data-wp-bind--value="state.forms['<?php echo esc_attr( $form_id ); ?>'].customAmount">
                </div>
            </div>
        </fieldset>

        <?php if ( $show_allocation ) : ?>
        <div class="sd-form-section sd-allocation-section">
            <label class="sd-section-label" for="<?php echo esc_attr( $form_id ); ?>-alloc">
                <?php esc_html_e( 'Direct Your Gift', 'starter-shelter' ); ?>
            </label>
            <div class="sd-select-wrapper">
                <select id="<?php echo esc_attr( $form_id ); ?>-alloc" class="sd-select" data-wp-on--change="actions.setAllocation">
                    <?php foreach ( $allocations as $k => $v ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( $show_anonymous ) : ?>
        <div class="sd-form-section sd-anonymous-section">
            <label class="sd-checkbox-label">
                <input type="checkbox" class="sd-checkbox" 
                    data-wp-on--change="actions.toggleAnonymous" 
                    data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].isAnonymous">
                <span class="sd-checkbox-custom">
                    <svg class="sd-checkbox-icon" viewBox="0 0 20 20">
                        <path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" fill="currentColor"/>
                    </svg>
                </span>
                <span class="sd-checkbox-text"><?php esc_html_e( 'Make my donation anonymous', 'starter-shelter' ); ?></span>
            </label>
        </div>
        <?php endif; ?>

        <!-- Summary & Submit -->
        <div class="sd-form-section sd-summary-section">
            <div class="sd-donation-summary">
                <span class="sd-summary-label"><?php esc_html_e( 'Your Gift:', 'starter-shelter' ); ?></span>
                <span class="sd-summary-amount" data-wp-text="callbacks.getDisplayAmount">
                    $<?php echo number_format( $default_amount ); ?>
                </span>
            </div>

            <button type="button" class="sd-submit-button wp-element-button" 
                data-wp-on--click="actions.submitToCart"
                data-wp-bind--disabled="!callbacks.canProceed" 
                data-wp-class--is-processing="state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing">
                <span data-wp-bind--hidden="state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing">
                    <?php echo esc_html( $submit_text ); ?>
                </span>
                <span class="sd-button-loading" data-wp-bind--hidden="!state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing">
                    <span class="sd-spinner-small"></span> <?php esc_html_e( 'Adding...', 'starter-shelter' ); ?>
                </span>
            </button>

            <div class="sd-form-success" role="status" data-wp-bind--hidden="!state.forms['<?php echo esc_attr( $form_id ); ?>'].success">
                <p data-wp-text="state.forms['<?php echo esc_attr( $form_id ); ?>'].success"></p>
                <a href="<?php echo esc_url( $checkout_url ); ?>" class="sd-checkout-link wp-element-button">
                    <?php esc_html_e( 'Proceed to Checkout', 'starter-shelter' ); ?>
                </a>
            </div>
            <div class="sd-form-error" role="alert" data-wp-bind--hidden="!state.forms['<?php echo esc_attr( $form_id ); ?>'].error">
                <p data-wp-text="state.forms['<?php echo esc_attr( $form_id ); ?>'].error"></p>
            </div>
        </div>
    </div>

    <?php if ( $show_secure ) : ?>
    <div class="sd-form-footer">
        <p class="sd-secure-notice">
            <svg viewBox="0 0 24 24" width="16" height="16">
                <path d="M12 1C8.676 1 6 3.676 6 7v2H4v14h16V9h-2V7c0-3.324-2.676-6-6-6zm0 2c2.276 0 4 1.724 4 4v2H8V7c0-2.276 1.724-4 4-4z" fill="currentColor"/>
            </svg>
            <?php esc_html_e( 'Secure donation powered by WooCommerce', 'starter-shelter' ); ?>
        </p>
    </div>
    <?php endif; ?>
</div>
