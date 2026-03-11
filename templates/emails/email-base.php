<?php
/**
 * Base email template for Shelter Donations.
 *
 * This template provides common structure for all shelter donation emails.
 * Override by copying to yourtheme/starter-shelter/emails/email-base.php
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 1.0.0
 *
 * @var Starter_Shelter\Emails\Config_Email $email   Email instance.
 * @var string                              $heading Email heading.
 * @var array                               $data    Entity data.
 * @var array                               $args    Trigger arguments.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hook: starter_shelter_email_header
 */
do_action( 'woocommerce_email_header', $heading, $email );
?>

<div class="sd-email-content">
    <?php
    /**
     * Hook: starter_shelter_email_content
     *
     * Allows insertion of content at the start of the email body.
     *
     * @param array                               $data  Entity data.
     * @param Starter_Shelter\Emails\Config_Email $email Email instance.
     */
    do_action( 'starter_shelter_email_content', $data, $email );
    ?>
</div>

<?php
/**
 * Hook: woocommerce_email_footer
 */
do_action( 'woocommerce_email_footer', $email );
