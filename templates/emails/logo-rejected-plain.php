<?php
/**
 * Business Logo Rejected Email Template (Plain Text)
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

$membership       = $data['membership'] ?? [];
$donor            = $data['donor'] ?? [];
$business_name    = $membership['business_name'] ?? __( 'Your business', 'starter-shelter' );
$rejection_reason = $args['reason'] ?? __( 'The logo did not meet our display requirements.', 'starter-shelter' );

echo "= " . $heading . " =\n\n";

printf(
    esc_html__( 'Dear %s,', 'starter-shelter' ),
    esc_html( $donor['first_name'] ?? $donor['full_name'] ?? __( 'Valued Member', 'starter-shelter' ) )
);
echo "\n\n";

printf(
    esc_html__( 'Thank you for submitting your logo for %s. Unfortunately, we were unable to approve the logo in its current form.', 'starter-shelter' ),
    esc_html( $business_name )
);
echo "\n\n";

echo esc_html__( 'REASON:', 'starter-shelter' ) . "\n";
echo wp_strip_all_tags( $rejection_reason ) . "\n\n";

echo esc_html__( 'To update your logo, please ensure it meets the following requirements:', 'starter-shelter' ) . "\n";
echo "- " . esc_html__( 'High resolution (minimum 300x300 pixels)', 'starter-shelter' ) . "\n";
echo "- " . esc_html__( 'PNG or JPG format with transparent or white background preferred', 'starter-shelter' ) . "\n";
echo "- " . esc_html__( 'Clear, legible design without offensive content', 'starter-shelter' ) . "\n";
echo "- " . esc_html__( 'You must have rights to use the logo', 'starter-shelter' ) . "\n\n";

echo esc_html__( 'You can upload a new logo through your My Account page, or reply to this email with an updated version attached.', 'starter-shelter' ) . "\n\n";

echo esc_html__( 'If you have any questions or need assistance, please don\'t hesitate to contact us.', 'starter-shelter' ) . "\n\n";

echo esc_html__( 'Best regards,', 'starter-shelter' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
