<?php
/**
 * Business Logo Approved Email Template (Plain Text)
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

$membership   = $data['membership'] ?? [];
$donor        = $data['donor'] ?? [];
$business_name = $membership['business_name'] ?? __( 'Your business', 'starter-shelter' );

echo "= " . $heading . " =\n\n";

printf(
    esc_html__( 'Dear %s,', 'starter-shelter' ),
    esc_html( $donor['first_name'] ?? $donor['full_name'] ?? __( 'Valued Member', 'starter-shelter' ) )
);
echo "\n\n";

printf(
    esc_html__( 'Great news! The logo for %s has been approved and is now visible on our website.', 'starter-shelter' ),
    esc_html( $business_name )
);
echo "\n\n";

echo esc_html__( 'Your business logo will appear on:', 'starter-shelter' ) . "\n";
echo "- " . esc_html__( 'Our Business Sponsors page', 'starter-shelter' ) . "\n";
echo "- " . esc_html__( 'The Donor Wall (if applicable to your membership tier)', 'starter-shelter' ) . "\n";
echo "- " . esc_html__( 'Our annual report and promotional materials', 'starter-shelter' ) . "\n\n";

echo esc_html__( 'Thank you for your generous support of our shelter and the animals in our care. Your business partnership makes a real difference!', 'starter-shelter' ) . "\n\n";

echo esc_html__( 'If you have any questions about your business membership benefits, please don\'t hesitate to contact us.', 'starter-shelter' ) . "\n\n";

echo esc_html__( 'With gratitude,', 'starter-shelter' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
