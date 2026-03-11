<?php
/**
 * Memorial confirmation email - plain text template.
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

use Starter_Shelter\Helpers;

$memorial = $data['memorial'] ?? [];
$donor = $data['donor'] ?? [];

echo "= " . esc_html( $heading ) . " =\n\n";

printf(
    esc_html__( 'Dear %s,', 'starter-shelter' ),
    esc_html( $donor['first_name'] ?? __( 'Friend', 'starter-shelter' ) )
);
echo "\n\n";

printf(
    esc_html__( 'Thank you for your heartfelt memorial tribute in honor of %s.', 'starter-shelter' ),
    esc_html( $memorial['honoree_name'] ?? '' )
);
echo "\n\n";

echo "= " . esc_html__( 'Memorial Details', 'starter-shelter' ) . " =\n\n";

echo esc_html__( 'In Memory Of:', 'starter-shelter' ) . ' ' . esc_html( $memorial['honoree_name'] ?? '' ) . "\n";
echo esc_html__( 'Memorial Type:', 'starter-shelter' ) . ' ' . esc_html( Helpers\get_memorial_type_label( $memorial['memorial_type'] ?? '' ) ) . "\n";

if ( ! empty( $memorial['pet_species'] ) ) {
    echo esc_html__( 'Species:', 'starter-shelter' ) . ' ' . esc_html( Helpers\get_species_label( $memorial['pet_species'] ) ) . "\n";
}

echo esc_html__( 'Donation Amount:', 'starter-shelter' ) . ' ' . esc_html( $memorial['amount_formatted'] ?? '' ) . "\n";
echo esc_html__( 'Date:', 'starter-shelter' ) . ' ' . esc_html( Helpers\format_date( $memorial['donation_date'] ?? '' ) ) . "\n\n";

if ( ! empty( $memorial['tribute_message'] ) ) {
    echo "= " . esc_html__( 'Your Tribute Message', 'starter-shelter' ) . " =\n\n";
    echo '"' . esc_html( $memorial['tribute_message'] ) . '"' . "\n\n";
}

if ( ! empty( $memorial['id'] ) ) {
    echo esc_html__( 'View your memorial page:', 'starter-shelter' ) . "\n";
    echo esc_url( get_permalink( $memorial['id'] ) ) . "\n\n";
}

$notify_family = $memorial['notify_family'] ?? [];
if ( ! empty( $notify_family['enabled'] ) ) {
    echo esc_html__( 'We will notify the family of your thoughtful tribute as you requested.', 'starter-shelter' ) . "\n\n";
}

echo esc_html__( 'This donation is tax-deductible. Please keep this email for your records.', 'starter-shelter' );
echo "\n\n";

echo esc_html__( 'With deepest gratitude,', 'starter-shelter' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
