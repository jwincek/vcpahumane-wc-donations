<?php
/**
 * Membership Form Block - Tier selection and signup.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

declare( strict_types = 1 );

use Starter_Shelter\Core\Config;

$form_id          = $attributes['formId'] ?: wp_unique_id( 'sd-membership-' );
$membership_type  = $attributes['membershipType'] ?? 'individual';
$show_type_toggle = $attributes['showTypeToggle'] ?? false;
$default_tier     = $attributes['defaultTier'] ?? '';
$layout           = $attributes['layout'] ?? 'cards';
$columns          = $attributes['columns'] ?? 3;
$show_benefits    = $attributes['showBenefits'] ?? true;
$show_anonymous   = $attributes['showAnonymous'] ?? false;
$title            = $attributes['title'] ?? __( 'Become a Member', 'starter-shelter' );
$subtitle         = $attributes['subtitle'] ?? __( 'Join our community of animal advocates.', 'starter-shelter' );
$submit_text      = $attributes['submitButtonText'] ?? __( 'Join Now', 'starter-shelter' );

// Get tiers from config.
$individual_tiers = Config::get_item( 'tiers', 'individual', [] );
$business_tiers   = Config::get_item( 'tiers', 'business', [] );

$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '/checkout/';

// Check product configuration.
$individual_product_id = (int) get_option( 'sd_membership_product_id', 0 );
$business_product_id   = (int) get_option( 'sd_business_membership_product_id', 0 );
$product_ok = ( $membership_type === 'business' ) 
    ? ( $business_product_id && function_exists( 'wc_get_product' ) && wc_get_product( $business_product_id ) )
    : ( $individual_product_id && function_exists( 'wc_get_product' ) && wc_get_product( $individual_product_id ) );

wp_interactivity_state( 'starter-shelter/membership-form', [
    'forms' => [
        $form_id => [
            'membershipType' => $membership_type,
            'selectedTier'   => $default_tier,
            'isAnonymous'    => false,
            'businessName'   => '',
            'isProcessing'   => false,
            'error'          => null,
            'success'        => null,
        ],
    ],
] );

$context = wp_json_encode( [
    'formId'            => $form_id,
    'membershipType'    => $membership_type,
    'defaultTier'       => $default_tier,
    'checkoutUrl'       => $checkout_url,
    'productConfigured' => $product_ok,
    'tiers'             => [
        'individual' => $individual_tiers,
        'business'   => $business_tiers,
    ],
] );

$wrapper = get_block_wrapper_attributes( [
    'class' => 'sd-membership-form sd-layout-' . esc_attr( $layout ),
    'id'    => $form_id,
    'data-wp-interactive' => '{"namespace":"starter-shelter/membership-form"}',
    'data-wp-context'     => $context,
    'data-wp-init'        => 'actions.initForm',
] );

// Get the tiers for current type.
$current_tiers = ( $membership_type === 'business' ) ? $business_tiers : $individual_tiers;
?>
<div <?php echo $wrapper; ?>>
    <div class="sd-form-header">
        <?php if ( $title ) : ?><h2 class="sd-form-title"><?php echo esc_html( $title ); ?></h2><?php endif; ?>
        <?php if ( $subtitle ) : ?><p class="sd-form-subtitle"><?php echo esc_html( $subtitle ); ?></p><?php endif; ?>
        <?php if ( ! $product_ok ) : ?>
            <div class="sd-config-warning" role="alert"><p><?php esc_html_e( 'Membership form not configured.', 'starter-shelter' ); ?></p></div>
        <?php endif; ?>
    </div>

    <div class="sd-membership-form-inner">
        <?php if ( $show_type_toggle && ! empty( $individual_tiers ) && ! empty( $business_tiers ) ) : ?>
        <!-- Type Toggle -->
        <div class="sd-form-section sd-type-toggle-section">
            <div class="sd-type-toggle">
                <label class="sd-type-option">
                    <input type="radio" name="<?php echo esc_attr( $form_id ); ?>_type" value="individual"
                        data-wp-on--change="actions.setMembershipType"
                        data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].membershipType === 'individual'">
                    <span class="sd-type-label"><?php esc_html_e( 'Individual', 'starter-shelter' ); ?></span>
                </label>
                <label class="sd-type-option">
                    <input type="radio" name="<?php echo esc_attr( $form_id ); ?>_type" value="business"
                        data-wp-on--change="actions.setMembershipType"
                        data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].membershipType === 'business'">
                    <span class="sd-type-label"><?php esc_html_e( 'Business', 'starter-shelter' ); ?></span>
                </label>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tier Selection -->
        <div class="sd-form-section sd-tiers-section">
            <span class="sd-section-label"><?php esc_html_e( 'Select Your Level', 'starter-shelter' ); ?></span>
            
            <div class="sd-tiers-grid sd-columns-<?php echo esc_attr( $columns ); ?>">
                <?php foreach ( $current_tiers as $slug => $tier ) : 
                    $price = $tier['price'] ?? $tier['amount'] ?? 0;
                    $name = $tier['name'] ?? $tier['label'] ?? ucfirst( $slug );
                    $benefits = $tier['benefits'] ?? [];
                    $featured = $tier['featured'] ?? false;
                ?>
                    <div class="sd-tier-card <?php echo $featured ? 'sd-tier-featured' : ''; ?>"
                         data-wp-class--selected="callbacks.isTierSelected"
                         data-wp-context='{"tierSlug":"<?php echo esc_attr( $slug ); ?>"}'>
                        <?php if ( $featured ) : ?>
                            <div class="sd-tier-badge"><?php esc_html_e( 'Most Popular', 'starter-shelter' ); ?></div>
                        <?php endif; ?>
                        
                        <button type="button" class="sd-tier-select-btn" data-wp-on--click="actions.selectTier">
                            <div class="sd-tier-header">
                                <h3 class="sd-tier-name"><?php echo esc_html( $name ); ?></h3>
                                <div class="sd-tier-price">
                                    <span class="sd-price-amount">$<?php echo esc_html( number_format( $price ) ); ?></span>
                                    <span class="sd-price-period">/<?php esc_html_e( 'year', 'starter-shelter' ); ?></span>
                                </div>
                            </div>
                            
                            <?php if ( $show_benefits && ! empty( $benefits ) ) : ?>
                            <ul class="sd-tier-benefits">
                                <?php foreach ( $benefits as $benefit ) : ?>
                                    <li>
                                        <svg viewBox="0 0 20 20" width="16" height="16"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" fill="currentColor"/></svg>
                                        <?php echo esc_html( $benefit ); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Business Name (for business memberships) -->
        <div class="sd-form-section sd-business-section" data-wp-bind--hidden="!callbacks.isBusinessMembership">
            <label for="<?php echo esc_attr( $form_id ); ?>-business" class="sd-section-label">
                <?php esc_html_e( 'Business Name', 'starter-shelter' ); ?> <span class="sd-required">*</span>
            </label>
            <input type="text" id="<?php echo esc_attr( $form_id ); ?>-business" class="sd-text-input" maxlength="200"
                placeholder="<?php esc_attr_e( 'Enter your business name...', 'starter-shelter' ); ?>"
                data-wp-on--input="actions.setBusinessName"
                data-wp-bind--value="state.forms['<?php echo esc_attr( $form_id ); ?>'].businessName">
        </div>

        <?php if ( $show_anonymous ) : ?>
        <div class="sd-form-section sd-anonymous-section">
            <label class="sd-checkbox-label">
                <input type="checkbox" class="sd-checkbox" data-wp-on--change="actions.toggleAnonymous"
                    data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].isAnonymous">
                <span class="sd-checkbox-custom"><svg class="sd-checkbox-icon" viewBox="0 0 20 20"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" fill="currentColor"/></svg></span>
                <span class="sd-checkbox-text"><?php esc_html_e( 'Keep my membership anonymous', 'starter-shelter' ); ?></span>
            </label>
        </div>
        <?php endif; ?>

        <!-- Summary & Submit -->
        <div class="sd-form-section sd-summary-section">
            <div class="sd-membership-summary" data-wp-bind--hidden="!state.forms['<?php echo esc_attr( $form_id ); ?>'].selectedTier">
                <span class="sd-summary-label"><?php esc_html_e( 'Your Membership:', 'starter-shelter' ); ?></span>
                <span class="sd-summary-value" data-wp-text="callbacks.getDisplayPrice">$0</span>
                <span class="sd-summary-period">/<?php esc_html_e( 'year', 'starter-shelter' ); ?></span>
            </div>

            <button type="button" class="sd-submit-button wp-element-button" data-wp-on--click="actions.submitToCart"
                data-wp-bind--disabled="!callbacks.canProceed"
                data-wp-class--is-processing="state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing">
                <span data-wp-bind--hidden="state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing"><?php echo esc_html( $submit_text ); ?></span>
                <span class="sd-button-loading" data-wp-bind--hidden="!state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing">
                    <span class="sd-spinner-small"></span> <?php esc_html_e( 'Processing...', 'starter-shelter' ); ?>
                </span>
            </button>

            <div class="sd-form-success" data-wp-bind--hidden="!state.forms['<?php echo esc_attr( $form_id ); ?>'].success">
                <p data-wp-text="state.forms['<?php echo esc_attr( $form_id ); ?>'].success"></p>
                <a href="<?php echo esc_url( $checkout_url ); ?>" class="sd-checkout-link wp-element-button"><?php esc_html_e( 'Proceed to Checkout', 'starter-shelter' ); ?></a>
            </div>
            <div class="sd-form-error" role="alert" data-wp-bind--hidden="!state.forms['<?php echo esc_attr( $form_id ); ?>'].error">
                <p data-wp-text="state.forms['<?php echo esc_attr( $form_id ); ?>'].error"></p>
            </div>
        </div>
    </div>
</div>
