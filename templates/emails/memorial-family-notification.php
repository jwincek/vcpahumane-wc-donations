<?php
/**
 * Memorial family notification email template.
 *
 * Sent to family members when someone creates a memorial in honor of their loved one.
 * Override by copying to yourtheme/starter-shelter/emails/memorial-family-notification.php
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
$notify_family = $memorial['notify_family'] ?? [];

$family_name = $notify_family['name'] ?? __( 'Dear Friend', 'starter-shelter' );
$is_anonymous = $memorial['is_anonymous'] ?? false;

do_action( 'woocommerce_email_header', $heading, $email );
?>

<p>
    <?php
    printf(
        /* translators: %s: family member name */
        esc_html__( 'Dear %s,', 'starter-shelter' ),
        esc_html( $family_name )
    );
    ?>
</p>

<p>
    <?php
    if ( $is_anonymous ) {
        printf(
            /* translators: 1: honoree name, 2: site name */
            esc_html__( 'We wanted to let you know that a generous donor has made a memorial donation to %2$s in loving memory of %1$s.', 'starter-shelter' ),
            '<strong>' . esc_html( $memorial['honoree_name'] ?? '' ) . '</strong>',
            esc_html( get_bloginfo( 'name' ) )
        );
    } else {
        printf(
            /* translators: 1: donor name, 2: site name, 3: honoree name */
            esc_html__( '%1$s has made a memorial donation to %2$s in loving memory of %3$s.', 'starter-shelter' ),
            '<strong>' . esc_html( Helpers\get_donor_display_name( $donor['first_name'] ?? '', $donor['last_name'] ?? '' ) ) . '</strong>',
            esc_html( get_bloginfo( 'name' ) ),
            '<strong>' . esc_html( $memorial['honoree_name'] ?? '' ) . '</strong>'
        );
    }
    ?>
</p>

<div style="background-color: #f8f9fa; padding: 25px; margin: 25px 0; border-radius: 8px; text-align: center;">
    <p style="font-size: 1.2em; margin-bottom: 10px;">
        <?php esc_html_e( 'In Loving Memory Of', 'starter-shelter' ); ?>
    </p>
    <h2 style="font-size: 1.8em; margin: 10px 0; color: #333;">
        <?php echo esc_html( $memorial['honoree_name'] ?? '' ); ?>
    </h2>
    <?php if ( ! empty( $memorial['memorial_type'] ) ) : ?>
    <p style="color: #666; font-style: italic;">
        <?php echo esc_html( Helpers\get_memorial_type_label( $memorial['memorial_type'] ) ); ?>
    </p>
    <?php endif; ?>
</div>

<?php if ( ! empty( $memorial['tribute_message'] ) ) : ?>
<h3><?php esc_html_e( 'A Message from the Donor', 'starter-shelter' ); ?></h3>

<blockquote style="border-left: 4px solid #6c757d; padding-left: 20px; margin: 20px 0; font-style: italic; color: #495057; font-size: 1.1em; line-height: 1.6;">
    "<?php echo esc_html( $memorial['tribute_message'] ); ?>"
</blockquote>
<?php endif; ?>

<p>
    <?php esc_html_e( 'This thoughtful gift will help us provide care and comfort to animals in need, creating a meaningful tribute to your loved one\'s memory.', 'starter-shelter' ); ?>
</p>

<?php if ( ! empty( $memorial['id'] ) ) : ?>
<p style="background-color: #e7f3fe; padding: 15px; border-left: 4px solid #2196F3;">
    <?php esc_html_e( 'You can view the memorial tribute page here:', 'starter-shelter' ); ?><br>
    <a href="<?php echo esc_url( get_permalink( $memorial['id'] ) ); ?>"><?php echo esc_url( get_permalink( $memorial['id'] ) ); ?></a>
</p>
<?php endif; ?>

<p>
    <?php esc_html_e( 'We are deeply honored to be part of this tribute and send our sincere condolences to you and your family.', 'starter-shelter' ); ?>
</p>

<p>
    <?php esc_html_e( 'With heartfelt sympathy,', 'starter-shelter' ); ?><br>
    <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
</p>

<?php
do_action( 'starter_shelter_memorial_family_notification_email_footer', $memorial, $donor, $email );
do_action( 'woocommerce_email_footer', $email );
