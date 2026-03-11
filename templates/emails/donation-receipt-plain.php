<?php
/**
 * Donation receipt email - plain text template.
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

$donation = $data['donation'] ?? [];
$donor = $data['donor'] ?? [];

echo "= " . esc_html( $heading ) . " =\n\n";

printf(
    esc_html__( 'Dear %s,', 'starter-shelter' ),
    esc_html( $donor['first_name'] ?? __( 'Friend', 'starter-shelter' ) )
);
echo "\n\n";

echo esc_html__( 'Thank you for your generous donation to support our animal shelter. Your contribution makes a real difference in the lives of the animals in our care.', 'starter-shelter' );
echo "\n\n";

echo "= " . esc_html__( 'Donation Details', 'starter-shelter' ) . " =\n\n";

echo esc_html__( 'Amount:', 'starter-shelter' ) . ' ' . esc_html( $donation['amount_formatted'] ?? '' ) . "\n";
echo esc_html__( 'Date:', 'starter-shelter' ) . ' ' . esc_html( $donation['date_formatted'] ?? '' ) . "\n";
echo esc_html__( 'Allocation:', 'starter-shelter' ) . ' ' . esc_html( $donation['allocation_label'] ?? $donation['allocation'] ?? '' ) . "\n";

if ( ! empty( $donation['dedication'] ) ) {
    echo esc_html__( 'Dedication:', 'starter-shelter' ) . ' ' . esc_html( $donation['dedication'] ) . "\n";
}

echo esc_html__( 'Reference:', 'starter-shelter' ) . ' #' . esc_html( $donation['id'] ?? '' ) . "\n\n";

echo esc_html__( 'This donation is tax-deductible to the extent allowed by law. No goods or services were provided in exchange for this contribution.', 'starter-shelter' );
echo "\n\n";

echo esc_html__( 'Please keep this email as your receipt for tax purposes.', 'starter-shelter' );
echo "\n\n";

echo esc_html__( 'With gratitude,', 'starter-shelter' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
