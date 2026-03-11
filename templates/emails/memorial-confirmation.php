<?php
/**
 * Memorial donation confirmation email template.
 *
 * Override by copying to yourtheme/starter-shelter/emails/memorial-confirmation.php
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

$memorial = $data['memorial'] ?? [];
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
        /* translators: %s: honoree name */
        esc_html__( 'Thank you for your heartfelt memorial tribute in honor of %s. Your donation helps us continue our mission while honoring the memory of your loved one.', 'starter-shelter' ),
        '<strong>' . esc_html( $memorial['honoree_name'] ?? '' ) . '</strong>'
    );
    ?>
</p>

<h2><?php esc_html_e( 'Memorial Details', 'starter-shelter' ); ?></h2>

<table class="td" cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
    <tbody>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'In Memory Of', 'starter-shelter' ); ?></th>
            <td style="padding: 12px;"><strong><?php echo esc_html( $memorial['honoree_name'] ?? '' ); ?></strong></td>
        </tr>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Memorial Type', 'starter-shelter' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( Helpers\get_memorial_type_label( $memorial['memorial_type'] ?? '' ) ); ?></td>
        </tr>
        <?php if ( ! empty( $memorial['pet_species'] ) ) : ?>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Species', 'starter-shelter' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( Helpers\get_species_label( $memorial['pet_species'] ) ); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Donation Amount', 'starter-shelter' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( $memorial['amount_formatted'] ?? '' ); ?></td>
        </tr>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8;"><?php esc_html_e( 'Date', 'starter-shelter' ); ?></th>
            <td style="padding: 12px;"><?php echo esc_html( Helpers\format_date( $memorial['donation_date'] ?? '' ) ); ?></td>
        </tr>
    </tbody>
</table>

<?php if ( ! empty( $memorial['tribute_message'] ) ) : ?>
<h3><?php esc_html_e( 'Your Tribute Message', 'starter-shelter' ); ?></h3>

<blockquote style="border-left: 4px solid #6c757d; padding-left: 15px; margin: 20px 0; font-style: italic; color: #495057;">
    <?php echo esc_html( $memorial['tribute_message'] ); ?>
</blockquote>
<?php endif; ?>

<?php if ( ! empty( $memorial['id'] ) ) : ?>
<p style="background-color: #e7f3fe; padding: 15px; border-left: 4px solid #2196F3;">
    <?php esc_html_e( 'View and share your memorial page:', 'starter-shelter' ); ?><br>
    <a href="<?php echo esc_url( get_permalink( $memorial['id'] ) ); ?>"><?php echo esc_url( get_permalink( $memorial['id'] ) ); ?></a>
</p>
<?php endif; ?>

<?php
$notify_family = $memorial['notify_family'] ?? [];
if ( ! empty( $notify_family['enabled'] ) ) :
?>
<p style="background-color: #d4edda; padding: 15px; border-left: 4px solid #28a745;">
    <?php esc_html_e( 'We will notify the family of your thoughtful tribute as you requested.', 'starter-shelter' ); ?>
</p>
<?php endif; ?>

<p>
    <?php esc_html_e( 'This donation is tax-deductible. Please keep this email as your receipt for tax purposes.', 'starter-shelter' ); ?>
</p>

<p>
    <?php esc_html_e( 'With deepest gratitude,', 'starter-shelter' ); ?><br>
    <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
</p>

<?php
do_action( 'starter_shelter_memorial_confirmation_email_footer', $memorial, $donor, $email );
do_action( 'woocommerce_email_footer', $email );
