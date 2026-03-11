<?php
/**
 * Memorial family notification email - plain text template.
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

use Starter_Shelter\Helpers;

$memorial = $data['memorial'] ?? [];
$donor = $data['donor'] ?? [];
$notify_family = $memorial['notify_family'] ?? [];

$family_name = $notify_family['name'] ?? __( 'Dear Friend', 'starter-shelter' );
$is_anonymous = $memorial['is_anonymous'] ?? false;

echo "= " . esc_html( $heading ) . " =\n\n";

printf(
    esc_html__( 'Dear %s,', 'starter-shelter' ),
    esc_html( $family_name )
);
echo "\n\n";

if ( $is_anonymous ) {
    printf(
        esc_html__( 'A generous donor has made a memorial donation to %2$s in loving memory of %1$s.', 'starter-shelter' ),
        esc_html( $memorial['honoree_name'] ?? '' ),
        esc_html( get_bloginfo( 'name' ) )
    );
} else {
    printf(
        esc_html__( '%1$s has made a memorial donation to %2$s in loving memory of %3$s.', 'starter-shelter' ),
        esc_html( Helpers\get_donor_display_name( $donor['first_name'] ?? '', $donor['last_name'] ?? '' ) ),
        esc_html( get_bloginfo( 'name' ) ),
        esc_html( $memorial['honoree_name'] ?? '' )
    );
}
echo "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html__( 'In Loving Memory Of', 'starter-shelter' ) . "\n";
echo esc_html( $memorial['honoree_name'] ?? '' ) . "\n";
if ( ! empty( $memorial['memorial_type'] ) ) {
    echo esc_html( Helpers\get_memorial_type_label( $memorial['memorial_type'] ) ) . "\n";
}
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( ! empty( $memorial['tribute_message'] ) ) {
    echo "= " . esc_html__( 'A Message from the Donor', 'starter-shelter' ) . " =\n\n";
    echo '"' . esc_html( $memorial['tribute_message'] ) . '"' . "\n\n";
}

echo esc_html__( 'This thoughtful gift will help us provide care and comfort to animals in need.', 'starter-shelter' );
echo "\n\n";

if ( ! empty( $memorial['id'] ) ) {
    echo esc_html__( 'View the memorial tribute page:', 'starter-shelter' ) . "\n";
    echo esc_url( get_permalink( $memorial['id'] ) ) . "\n\n";
}

echo esc_html__( 'We send our sincere condolences to you and your family.', 'starter-shelter' );
echo "\n\n";

echo esc_html__( 'With heartfelt sympathy,', 'starter-shelter' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
