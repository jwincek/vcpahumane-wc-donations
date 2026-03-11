<?php
/**
 * Classic theme fallback for single memorial.
 *
 * Renders the block template markup through do_blocks() within
 * a standard WordPress page wrapper. Used only when the active
 * theme is not a block theme.
 *
 * @package Starter_Shelter
 * @since 2.2.0
 */

get_header();

$template_file = dirname( __FILE__ ) . '/single-sd_memorial.html';

if ( file_exists( $template_file ) ) {
    $content = file_get_contents( $template_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

    // Strip the header/footer template parts — classic themes provide their own.
    $content = preg_replace(
        '/<!-- wp:template-part \{[^}]*"area":"(?:header|footer)"[^}]*\} \/-->/',
        '',
        $content
    );

    // Render block markup.
    echo do_blocks( $content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — Block output is escaped by individual block render callbacks.
}

get_footer();
