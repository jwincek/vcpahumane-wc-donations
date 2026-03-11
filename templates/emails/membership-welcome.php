<?php
/**
 * Membership welcome email template.
 *
 * Override by copying to yourtheme/starter-shelter/emails/membership-welcome.php
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 1.0.0
 *
 * @var Starter_Shelter\Emails\Config_Email $email      Email instance.
 * @var string                              $heading    Email heading.
 * @var array                               $data       Entity data.
 * @var array                               $args       Trigger arguments.
 * @var bool                                $plain_text Whether plain text.
 */

defined( 'ABSPATH' ) || exit;

use Starter_Shelter\Helpers;

$membership = $data['membership'] ?? [];
$donor = $data['donor'] ?? [];

do_action( 'woocommerce_email_header', $heading, $email );
?>

<p>
    <?php
    printf(
        /* translators: %s: donor name */
        esc_html__( 'Dear %s,', 'starter-shelter' ),
        esc_html( $donor['first_name'] ?? __( 'Friend', 'starter-shelter' ) )
    );
    ?>
</p>

<p>
    <?php
    printf(
        /* translators: 1: tier label, 2: site name */
        esc_html__( 'Welcome to the %1$s family! Thank you for becoming a %2$s member.', 'starter-shelter' ),
        esc_html( get_bloginfo( 'name' ) ),
        '<strong>' . esc_html( $membership['tier_label'] ?? $membership['tier'] ?? '' ) . '</strong>'
    );
    ?>
</p>

<h2><?php esc_html_e( 'Membership Details', 'starter-shelter' ); ?></h2>

<table class="td" cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
    <tbody>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Membership Level', 'starter-shelter' ); ?></th>
            <td style="padding: 12px;"><strong><?php echo esc_html( $membership['tier_label'] ?? $membership['tier'] ?? '' ); ?></strong></td>
        </tr>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Type', 'starter-shelter' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( ucfirst( $membership['membership_type'] ?? 'Individual' ) ); ?></td>
        </tr>
        <?php if ( 'business' === ( $membership['membership_type'] ?? '' ) && ! empty( $membership['business_name'] ) ) : ?>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Business Name', 'starter-shelter' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( $membership['business_name'] ); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Start Date', 'starter-shelter' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( Helpers\format_date( $membership['start_date'] ?? '' ) ); ?></td>
        </tr>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Expiration Date', 'starter-shelter' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( Helpers\format_date( $membership['end_date'] ?? '' ) ); ?></td>
        </tr>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Member ID', 'starter-shelter' ); ?></th>
            <td style="padding: 12px;">#<?php echo esc_html( $membership['id'] ?? '' ); ?></td>
        </tr>
    </tbody>
</table>

<?php if ( ! empty( $membership['benefits'] ) ) : ?>
<h2><?php esc_html_e( 'Your Member Benefits', 'starter-shelter' ); ?></h2>

<ul style="padding-left: 20px; margin-bottom: 20px;">
    <?php foreach ( $membership['benefits'] as $benefit ) : ?>
    <li style="margin-bottom: 8px;"><?php echo esc_html( $benefit ); ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<p style="background-color: #e7f3fe; padding: 15px; border-left: 4px solid #2196F3;">
    <?php
    printf(
        /* translators: %s: My Account URL */
        esc_html__( 'Visit your %s to view your membership status, giving history, and more.', 'starter-shelter' ),
        '<a href="' . esc_url( wc_get_account_endpoint_url( 'donor-dashboard' ) ) . '">' . esc_html__( 'Donor Dashboard', 'starter-shelter' ) . '</a>'
    );
    ?>
</p>

<p>
    <?php esc_html_e( 'Thank you for supporting our mission to help animals in need!', 'starter-shelter' ); ?>
</p>

<p>
    <?php esc_html_e( 'With gratitude,', 'starter-shelter' ); ?><br>
    <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
</p>

<?php
do_action( 'starter_shelter_membership_welcome_email_footer', $membership, $donor, $email );
do_action( 'woocommerce_email_footer', $email );
