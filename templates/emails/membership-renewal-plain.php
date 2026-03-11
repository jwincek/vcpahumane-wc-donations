<?php
/**
 * Membership renewal reminder email - plain text template.
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

use Starter_Shelter\Helpers;

$membership = $data['membership'] ?? [];
$donor = $data['donor'] ?? [];

echo "= " . esc_html( $heading ) . " =\n\n";

printf(
    esc_html__( 'Dear %s,', 'starter-shelter' ),
    esc_html( $donor['first_name'] ?? __( 'Friend', 'starter-shelter' ) )
);
echo "\n\n";

printf(
    esc_html__( 'Your %1$s membership will expire on %2$s.', 'starter-shelter' ),
    esc_html( $membership['tier_label'] ?? $membership['tier'] ?? '' ),
    esc_html( Helpers\format_date( $membership['end_date'] ?? '' ) )
);
echo "\n\n";

echo esc_html__( 'Your membership has helped us:', 'starter-shelter' ) . "\n";
echo "* " . esc_html__( 'Provide food and shelter for animals in need', 'starter-shelter' ) . "\n";
echo "* " . esc_html__( 'Offer veterinary care and medical treatments', 'starter-shelter' ) . "\n";
echo "* " . esc_html__( 'Find forever homes for our furry friends', 'starter-shelter' ) . "\n";
echo "* " . esc_html__( 'Support community education and outreach programs', 'starter-shelter' ) . "\n\n";

echo "= " . esc_html__( 'Renew Your Membership', 'starter-shelter' ) . " =\n\n";

printf(
    esc_html__( 'Renew here: %s', 'starter-shelter' ),
    esc_url( home_url( '/membership/' ) )
);
echo "\n\n";

echo esc_html__( 'Current Membership:', 'starter-shelter' ) . ' ' . esc_html( $membership['tier_label'] ?? $membership['tier'] ?? '' ) . "\n";
echo esc_html__( 'Expiration Date:', 'starter-shelter' ) . ' ' . esc_html( Helpers\format_date( $membership['end_date'] ?? '' ) ) . "\n";

if ( ! empty( $membership['days_remaining'] ) ) {
    echo esc_html__( 'Days Remaining:', 'starter-shelter' ) . ' ' . esc_html( $membership['days_remaining'] ) . "\n";
}

echo "\n" . esc_html__( 'Thank you for your continued support!', 'starter-shelter' );
echo "\n\n";

echo esc_html__( 'Warm regards,', 'starter-shelter' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
