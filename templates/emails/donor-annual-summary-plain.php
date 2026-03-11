<?php
/**
 * Annual giving summary email - plain text template.
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

use Starter_Shelter\Helpers;

$donor = $data['donor'] ?? [];
$summary = $args['summary'] ?? [];
$year = $args['year'] ?? date( 'Y' );

echo "= " . esc_html( $heading ) . " =\n\n";

printf(
    esc_html__( 'Dear %s,', 'starter-shelter' ),
    esc_html( $donor['first_name'] ?? __( 'Friend', 'starter-shelter' ) )
);
echo "\n\n";

printf(
    esc_html__( 'Thank you for your generous support of %2$s throughout %1$d. Below is a summary of your charitable contributions for your tax records.', 'starter-shelter' ),
    (int) $year,
    esc_html( get_bloginfo( 'name' ) )
);
echo "\n\n";

echo "════════════════════════════════════════\n";
printf( esc_html__( '%d ANNUAL GIVING SUMMARY', 'starter-shelter' ), (int) $year );
echo "\n════════════════════════════════════════\n\n";

echo esc_html__( 'Donations:', 'starter-shelter' ) . ' ' . esc_html( $summary['donations']['formatted'] ?? '$0.00' );
echo ' (' . esc_html( $summary['donations']['count'] ?? 0 ) . ' ' . esc_html__( 'gifts', 'starter-shelter' ) . ")\n";

echo esc_html__( 'Memorial Tributes:', 'starter-shelter' ) . ' ' . esc_html( $summary['memorials']['formatted'] ?? '$0.00' );
echo ' (' . esc_html( $summary['memorials']['count'] ?? 0 ) . ' ' . esc_html__( 'tributes', 'starter-shelter' ) . ")\n";

echo esc_html__( 'Memberships:', 'starter-shelter' ) . ' ' . esc_html( $summary['memberships']['formatted'] ?? '$0.00' ) . "\n\n";

echo "----------------------------------------\n";
echo esc_html__( 'TOTAL TAX-DEDUCTIBLE AMOUNT:', 'starter-shelter' ) . ' ' . esc_html( $summary['grand_formatted'] ?? '$0.00' ) . "\n";
echo "----------------------------------------\n\n";

if ( ! empty( $summary['donations']['by_allocation'] ) ) {
    echo "= " . esc_html__( 'Donations by Purpose', 'starter-shelter' ) . " =\n\n";
    foreach ( $summary['donations']['by_allocation'] as $allocation => $amount ) {
        echo esc_html( Helpers\get_allocation_label( $allocation ) ) . ': ' . esc_html( Helpers\format_currency( $amount ) ) . "\n";
    }
    echo "\n";
}

echo esc_html__( 'TAX INFORMATION:', 'starter-shelter' ) . "\n";
echo esc_html__( 'No goods or services were provided in exchange for these contributions. Your donations are tax-deductible to the extent allowed by law.', 'starter-shelter' ) . "\n";
printf(
    esc_html__( 'Our Tax ID (EIN): %s', 'starter-shelter' ),
    esc_html( get_option( 'starter_shelter_ein', '[EIN Number]' ) )
);
echo "\n\n";

printf(
    esc_html__( 'View your complete giving history: %s', 'starter-shelter' ),
    esc_url( wc_get_account_endpoint_url( 'annual-statement' ) )
);
echo "\n\n";

echo esc_html__( 'Thank you for your continued support!', 'starter-shelter' );
echo "\n\n";

echo esc_html__( 'With sincere gratitude,', 'starter-shelter' ) . "\n";
echo esc_html( get_bloginfo( 'name' ) ) . "\n\n";

printf(
    esc_html__( 'This statement was generated on %s.', 'starter-shelter' ),
    esc_html( wp_date( get_option( 'date_format' ) ) )
);
echo "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
