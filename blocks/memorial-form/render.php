<?php
/**
 * Memorial Form Block - Dedicated tribute gift form.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

declare( strict_types = 1 );

$form_id = $attributes['formId'] ?: wp_unique_id( 'sd-memorial-' );

$preset_amounts        = $attributes['presetAmounts'] ?? [ 25, 50, 100, 250 ];
$default_amount        = $attributes['defaultAmount'] ?? 50;
$default_ded_type      = $attributes['defaultDedicationType'] ?? 'memory';
$default_honoree_type  = $attributes['defaultHonoreeType'] ?? 'person';
$show_anonymous        = $attributes['showAnonymous'] ?? true;
$show_family           = $attributes['showFamilyNotification'] ?? true;
$show_send_card        = $attributes['showSendCard'] ?? true;
$title                 = $attributes['title'] ?? __( 'Give in Memory or Honor', 'starter-shelter' );
$subtitle              = $attributes['subtitle'] ?? __( 'Honor a loved one with a meaningful tribute gift.', 'starter-shelter' );
$submit_text           = $attributes['submitButtonText'] ?? __( 'Add Memorial Gift to Cart', 'starter-shelter' );
$min_amount            = $attributes['minAmount'] ?? 10;
$max_amount            = $attributes['maxAmount'] ?? 100000;

$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '/checkout/';
$product_id = (int) get_option( 'sd_memorial_product_id', 0 );
$product_ok = $product_id && function_exists( 'wc_get_product' ) && wc_get_product( $product_id );

wp_interactivity_state( 'starter-shelter/memorial-form', [
    'forms' => [
        $form_id => [
            'amount' => $default_amount, 'customAmount' => '', 'isAnonymous' => false,
            'dedicationType' => $default_ded_type, 'honoreeType' => $default_honoree_type,
            'honoreeName' => '', 'tributeMessage' => '',
            'notifyFamily' => false, 'familyName' => '', 'familyEmail' => '', 'familyAddress' => '', 'sendCard' => false,
            'isProcessing' => false, 'error' => null, 'success' => null,
        ],
    ],
] );

$context = wp_json_encode( [
    'formId' => $form_id, 'presetAmounts' => $preset_amounts, 'defaultAmount' => $default_amount,
    'minAmount' => $min_amount, 'maxAmount' => $max_amount, 'checkoutUrl' => $checkout_url, 'productConfigured' => $product_ok,
] );

$wrapper = get_block_wrapper_attributes( [
    'class' => 'sd-memorial-form', 'id' => $form_id,
    'data-wp-interactive' => '{"namespace":"starter-shelter/memorial-form"}',
    'data-wp-context' => $context, 'data-wp-init' => 'actions.initForm',
] );
?>
<div <?php echo $wrapper; ?>>
    <div class="sd-form-header">
        <?php if ( $title ) : ?><h2 class="sd-form-title"><?php echo esc_html( $title ); ?></h2><?php endif; ?>
        <?php if ( $subtitle ) : ?><p class="sd-form-subtitle"><?php echo esc_html( $subtitle ); ?></p><?php endif; ?>
        <?php if ( ! $product_ok ) : ?>
            <div class="sd-config-warning" role="alert"><p><?php esc_html_e( 'Memorial form not configured.', 'starter-shelter' ); ?></p></div>
        <?php endif; ?>
    </div>

    <div class="sd-memorial-form-inner">
        <!-- Dedication Type -->
        <div class="sd-form-section sd-dedication-type-section">
            <span class="sd-section-label"><?php esc_html_e( 'This gift is:', 'starter-shelter' ); ?></span>
            <div class="sd-toggle-buttons">
                <label class="sd-toggle-button">
                    <input type="radio" name="<?php echo esc_attr( $form_id ); ?>_ded" value="memory" class="sd-radio"
                        data-wp-on--change="actions.setDedicationType" data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].dedicationType === 'memory'">
                    <span class="sd-toggle-content">
                        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" fill="currentColor"/></svg>
                        <?php esc_html_e( 'In Memory Of', 'starter-shelter' ); ?>
                    </span>
                </label>
                <label class="sd-toggle-button">
                    <input type="radio" name="<?php echo esc_attr( $form_id ); ?>_ded" value="honor" class="sd-radio"
                        data-wp-on--change="actions.setDedicationType" data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].dedicationType === 'honor'">
                    <span class="sd-toggle-content">
                        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" fill="currentColor"/></svg>
                        <?php esc_html_e( 'In Honor Of', 'starter-shelter' ); ?>
                    </span>
                </label>
            </div>
        </div>

        <!-- Honoree Type -->
        <div class="sd-form-section sd-honoree-type-section">
            <span class="sd-section-label"><?php esc_html_e( 'Honoring a:', 'starter-shelter' ); ?></span>
            <div class="sd-honoree-cards">
                <label class="sd-honoree-card">
                    <input type="radio" name="<?php echo esc_attr( $form_id ); ?>_hon" value="person" class="sd-radio"
                        data-wp-on--change="actions.setHonoreeType" data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].honoreeType === 'person'">
                    <span class="sd-card-inner">
                        <svg viewBox="0 0 24 24" width="32" height="32"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" fill="currentColor"/></svg>
                        <span><?php esc_html_e( 'Person', 'starter-shelter' ); ?></span>
                    </span>
                </label>
                <label class="sd-honoree-card">
                    <input type="radio" name="<?php echo esc_attr( $form_id ); ?>_hon" value="pet" class="sd-radio"
                        data-wp-on--change="actions.setHonoreeType" data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].honoreeType === 'pet'">
                    <span class="sd-card-inner">
                        <svg viewBox="0 0 24 24" width="32" height="32"><path d="M4.5 9.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5zm5-3a2.5 2.5 0 100-5 2.5 2.5 0 000 5zm5 0a2.5 2.5 0 100-5 2.5 2.5 0 000 5zm5 3a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM12 10c-2.5 0-4.5 2.24-4.5 5v5h9v-5c0-2.76-2-5-4.5-5z" fill="currentColor"/></svg>
                        <span><?php esc_html_e( 'Pet', 'starter-shelter' ); ?></span>
                    </span>
                </label>
            </div>
        </div>

        <!-- Honoree Name -->
        <div class="sd-form-section">
            <label for="<?php echo esc_attr( $form_id ); ?>-honoree" class="sd-section-label">
                <span data-wp-text="callbacks.getHonoreeLabel"><?php esc_html_e( "Person's Name", 'starter-shelter' ); ?></span>
                <span class="sd-required">*</span>
            </label>
            <input type="text" id="<?php echo esc_attr( $form_id ); ?>-honoree" class="sd-text-input sd-honoree-input" maxlength="100"
                placeholder="<?php esc_attr_e( 'Enter their name...', 'starter-shelter' ); ?>"
                data-wp-on--input="actions.setHonoreeName" data-wp-bind--value="state.forms['<?php echo esc_attr( $form_id ); ?>'].honoreeName">
        </div>

        <!-- Amount Selection -->
        <fieldset class="sd-form-section sd-amount-section">
            <legend class="sd-section-label"><?php esc_html_e( 'Gift Amount', 'starter-shelter' ); ?></legend>
            <div class="sd-preset-amounts">
                <?php foreach ( $preset_amounts as $amt ) : ?>
                    <button type="button" class="sd-amount-button"
                        data-wp-on--click="actions.selectAmount" data-wp-class--selected="callbacks.isAmountSelected"
                        data-wp-context='{"buttonAmount":<?php echo (int) $amt; ?>}'>$<?php echo number_format( $amt ); ?></button>
                <?php endforeach; ?>
            </div>
            <div class="sd-custom-amount">
                <label for="<?php echo esc_attr( $form_id ); ?>-custom" class="sd-custom-label"><?php esc_html_e( 'Or custom:', 'starter-shelter' ); ?></label>
                <div class="sd-input-wrapper">
                    <span class="sd-currency-symbol">$</span>
                    <input type="number" id="<?php echo esc_attr( $form_id ); ?>-custom" class="sd-custom-input"
                        min="<?php echo $min_amount; ?>" max="<?php echo $max_amount; ?>" step="1"
                        data-wp-on--input="actions.setCustomAmount" data-wp-on--focus="actions.clearPresetAmount"
                        data-wp-bind--value="state.forms['<?php echo esc_attr( $form_id ); ?>'].customAmount">
                </div>
            </div>
        </fieldset>

        <!-- Tribute Message -->
        <div class="sd-form-section">
            <label for="<?php echo esc_attr( $form_id ); ?>-tribute" class="sd-section-label"><?php esc_html_e( 'Tribute Message', 'starter-shelter' ); ?> <span class="sd-optional">(<?php esc_html_e( 'Optional', 'starter-shelter' ); ?>)</span></label>
            <textarea id="<?php echo esc_attr( $form_id ); ?>-tribute" class="sd-textarea" rows="4" maxlength="500"
                placeholder="<?php esc_attr_e( 'Share a memory or message about your loved one...', 'starter-shelter' ); ?>"
                data-wp-on--input="actions.setTributeMessage" data-wp-bind--value="state.forms['<?php echo esc_attr( $form_id ); ?>'].tributeMessage"></textarea>
            <p class="sd-char-count"><span data-wp-text="callbacks.getTributeCharCount">0</span>/500</p>
        </div>

        <?php if ( $show_family ) : ?>
        <!-- Family Notification -->
        <div class="sd-form-section sd-family-section">
            <label class="sd-checkbox-label sd-family-toggle">
                <input type="checkbox" class="sd-checkbox" data-wp-on--change="actions.toggleNotifyFamily"
                    data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].notifyFamily">
                <span class="sd-checkbox-custom"><svg class="sd-checkbox-icon" viewBox="0 0 20 20"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" fill="currentColor"/></svg></span>
                <span class="sd-checkbox-text"><?php esc_html_e( 'Notify the family of this tribute gift', 'starter-shelter' ); ?></span>
            </label>

            <div class="sd-family-fields" data-wp-bind--hidden="!state.forms['<?php echo esc_attr( $form_id ); ?>'].notifyFamily">
                <div class="sd-field-row">
                    <div class="sd-field-wrapper">
                        <label class="sd-field-label"><?php esc_html_e( 'Family Contact Name', 'starter-shelter' ); ?> <span class="sd-required">*</span></label>
                        <input type="text" class="sd-text-input" data-wp-on--input="actions.setFamilyName" data-wp-bind--value="state.forms['<?php echo esc_attr( $form_id ); ?>'].familyName">
                    </div>
                    <div class="sd-field-wrapper">
                        <label class="sd-field-label"><?php esc_html_e( 'Family Email', 'starter-shelter' ); ?></label>
                        <input type="email" class="sd-text-input" data-wp-on--input="actions.setFamilyEmail" data-wp-bind--value="state.forms['<?php echo esc_attr( $form_id ); ?>'].familyEmail">
                    </div>
                </div>
                <div class="sd-field-wrapper">
                    <label class="sd-field-label"><?php esc_html_e( 'Family Mailing Address', 'starter-shelter' ); ?></label>
                    <textarea class="sd-textarea" rows="2" data-wp-on--input="actions.setFamilyAddress" data-wp-bind--value="state.forms['<?php echo esc_attr( $form_id ); ?>'].familyAddress"></textarea>
                </div>
                <?php if ( $show_send_card ) : ?>
                <label class="sd-checkbox-label">
                    <input type="checkbox" class="sd-checkbox" data-wp-on--change="actions.toggleSendCard" data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].sendCard">
                    <span class="sd-checkbox-custom"><svg class="sd-checkbox-icon" viewBox="0 0 20 20"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" fill="currentColor"/></svg></span>
                    <span class="sd-checkbox-text"><?php esc_html_e( 'Send a physical sympathy/tribute card', 'starter-shelter' ); ?></span>
                </label>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( $show_anonymous ) : ?>
        <div class="sd-form-section sd-anonymous-section">
            <label class="sd-checkbox-label">
                <input type="checkbox" class="sd-checkbox" data-wp-on--change="actions.toggleAnonymous" data-wp-bind--checked="state.forms['<?php echo esc_attr( $form_id ); ?>'].isAnonymous">
                <span class="sd-checkbox-custom"><svg class="sd-checkbox-icon" viewBox="0 0 20 20"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" fill="currentColor"/></svg></span>
                <span class="sd-checkbox-text"><?php esc_html_e( 'Make my donation anonymous', 'starter-shelter' ); ?></span>
            </label>
        </div>
        <?php endif; ?>

        <!-- Summary & Submit -->
        <div class="sd-form-section sd-summary-section">
            <div class="sd-memorial-summary">
                <div class="sd-summary-dedication" data-wp-text="callbacks.getDedicationSummary"></div>
                <div class="sd-summary-amount">
                    <span class="sd-summary-label"><?php esc_html_e( 'Gift Amount:', 'starter-shelter' ); ?></span>
                    <span class="sd-summary-value" data-wp-text="callbacks.getDisplayAmount">$<?php echo number_format( $default_amount ); ?></span>
                </div>
            </div>

            <button type="button" class="sd-submit-button wp-element-button" data-wp-on--click="actions.submitToCart"
                data-wp-bind--disabled="!callbacks.canProceed" data-wp-class--is-processing="state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing">
                <span data-wp-bind--hidden="state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing"><?php echo esc_html( $submit_text ); ?></span>
                <span class="sd-button-loading" data-wp-bind--hidden="!state.forms['<?php echo esc_attr( $form_id ); ?>'].isProcessing"><span class="sd-spinner-small"></span> <?php esc_html_e( 'Adding...', 'starter-shelter' ); ?></span>
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
