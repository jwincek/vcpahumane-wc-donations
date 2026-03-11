<?php
/**
 * Membership welcome email - plain text template.
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
    esc_html__( 'Welcome to the %1$s family! Thank you for becoming a %2$s member.', 'starter-shelter' ),
    esc_html( get_bloginfo( 'name' ) ),
    esc_html( $membership['tier_label'] ?? $membership['tier'] ?? '' )
);
echo "\n\n";

echo "= " . esc_html__( 'Membership Details', 'starter-shelter' ) . " =\n\n";

echo esc_html__( 'Membership Level:', 'starter-shelter' ) . ' ' . esc_html( $membership['tier_label'] ?? $membership['tier'] ?? '' ) . "\n";
echo esc_html__( 'Type:', 'starter-shelter' ) . ' ' . esc_html( ucfirst( $membership['membership_type'] ?? 'Individual' ) ) . "\n";

if ( 'business' === ( $membership['membership_type'] ?? '' ) && ! empty( $membership['business_name'] ) ) {
    echo esc_html__( 'Business Name:', 'starter-shelter' ) . ' ' . esc_html( $membership['business_name'] ) . "\n";
}

echo esc_html__( 'Start Date:', 'starter-shelter' ) . ' ' . esc_html( Helpers\format_date( $membership['start_date'] ?? '' ) ) . "\n";
echo esc_html__( 'Expiration Date:', 'starter-shelter' ) . ' ' . esc_html( Helpers\format_date( $membership['end_date'] ?? '' ) ) . "\n";
echo esc_html__( 'Member ID:', 'starter-shelter' ) . ' #' . esc_html( $membership['id'] ?? '' ) . "\n\n";

if ( ! empty( $membership['benefits'] ) ) {
    echo "= " . esc_html__( 'Your Member Benefits', 'starter-shelter' ) . " =\n\n";
    foreach ( $membership['benefits'] as $benefit ) {
        echo "* " . esc_html( $benefit ) . "\n";
    }
    echo "\n";
}

printf(
    esc_html__( 'Visit your Donor Dashboard to view your membership status: %s', 'starter-shelter' ),
    esc_url( wc_get_account_endpoint_url( 'donor-dashboard' ) )
);
echo "\n\n";

echo esc_html__( 'Thank you for supporting our mission!', 'starter-shelter' );
echo "\n\n";

echo esc_html__( 'With gratitude,', 'starter-shelter' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
