<?php
/**
 * Block Template Registration.
 *
 * Registers plugin-provided block templates for the Site Editor.
 *
 * Uses the filter-based approach (get_block_templates +
 * pre_get_block_file_template) as the primary method because
 * register_block_template() rejects template slugs containing
 * underscores (e.g. single-sd_memorial) due to a regex limitation
 * in WP_Block_Templates_Registry. See:
 * https://github.com/WordPress/gutenberg/issues/67066
 *
 * The filter-based pattern mirrors the proven Petstablished_Templates
 * implementation. When the regex is fixed in a future WordPress
 * release, this can be migrated to register_block_template().
 *
 * For classic themes: a single_template filter provides a PHP
 * fallback that renders the block markup through do_blocks().
 *
 * @package Starter_Shelter
 * @subpackage Templates
 * @since 2.2.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Templates;

/**
 * Plugin templates and their metadata.
 *
 * @return array<string, array{title: string, description: string, post_types: string[]}>
 */
function get_plugin_templates(): array {
    return [
        'single-sd_memorial' => [
            'title'       => __( 'Single Memorial', 'starter-shelter' ),
            'description' => __( 'Displays an individual memorial tribute with honoree name, message, and donor attribution.', 'starter-shelter' ),
            'post_types'  => [ 'sd_memorial' ],
        ],
    ];
}

/**
 * Get the path to the plugin's templates directory.
 *
 * @return string
 */
function get_templates_dir(): string {
    error_log(dirname( __DIR__ ) . '/templates/');
    return dirname( __DIR__, 1 ) . '/templates/';
}

// ─── Filter-based template registration ──────────────────────────────

/**
 * Inject plugin templates into the block template query results.
 *
 * Hooked to get_block_templates — fires when the Site Editor lists
 * templates or when WordPress resolves which template to render.
 *
 * @since 2.2.0
 *
 * @param \WP_Block_Template[] $templates     Existing templates.
 * @param array                $query         Query arguments.
 * @param string               $template_type 'wp_template' or 'wp_template_part'.
 * @return \WP_Block_Template[]
 */
function filter_add_templates( array $templates, array $query, string $template_type ): array {
    if ( 'wp_template' !== $template_type ) {
        return $templates;
    }

    foreach ( get_plugin_templates() as $slug => $data ) {
        // Skip if specific templates requested and this isn't one of them.
        if ( ! empty( $query['slug__in'] ) && ! in_array( $slug, $query['slug__in'], true ) ) {
            continue;
        }

        // Skip if already provided by the theme.
        $exists = false;
        foreach ( $templates as $template ) {
            if ( $template->slug === $slug ) {
                $exists = true;
                break;
            }
        }

        if ( ! $exists ) {
            $templates[] = build_template_object( $slug, $data );
        }
    }

    return $templates;
}
add_filter( 'get_block_templates', __NAMESPACE__ . '\\filter_add_templates', 10, 3 );

/**
 * Resolve a specific plugin template by ID.
 *
 * Hooked to pre_get_block_file_template — fires BEFORE WordPress
 * checks the theme directory, allowing plugin templates to be
 * found regardless of the active theme.
 *
 * @since 2.2.0
 *
 * @param \WP_Block_Template|null $template      Found template or null.
 * @param string                  $id            Template ID (theme//slug format).
 * @param string                  $template_type 'wp_template' or 'wp_template_part'.
 * @return \WP_Block_Template|null
 */
function filter_get_template( $template, string $id, string $template_type ) {
    if ( $template || 'wp_template' !== $template_type ) {
        return $template;
    }

    $parts = explode( '//', $id );
    $slug  = $parts[1] ?? $parts[0];

    $plugin_templates = get_plugin_templates();

    if ( ! isset( $plugin_templates[ $slug ] ) ) {
        return $template;
    }

    return build_template_object( $slug, $plugin_templates[ $slug ] );
}
add_filter( 'pre_get_block_file_template', __NAMESPACE__ . '\\filter_get_template', 10, 3 );

/**
 * Get the content of a template file.
 *
 * Uses output buffering with include rather than file_get_contents,
 * following the approach recommended in the WordPress Developer Blog.
 * This also enables PHP execution in templates (e.g. for i18n).
 *
 * @since 2.2.0
 *
 * @param string $template Template filename (relative to templates dir).
 * @return string The template content.
 */
function get_template_content( string $template ): string {
    $file = get_templates_dir() . $template;

    if ( ! file_exists( $file ) ) {
        return '';
    }

    ob_start();
    include $file;
    return ob_get_clean();
}

/**
 * Build a WP_Block_Template object from a plugin template file.
 *
 * @since 2.2.0
 *
 * @param string $slug Template slug.
 * @param array  $data Template metadata (title, description, post_types).
 * @return \WP_Block_Template
 */
function build_template_object( string $slug, array $data ): \WP_Block_Template {
    $content = get_template_content( $slug . '.html' );

    $template                 = new \WP_Block_Template();
    $template->id             = 'starter-shelter//' . $slug;
    $template->theme          = 'starter-shelter';
    $template->slug           = $slug;
    $template->source         = 'plugin';
    $template->type           = 'wp_template';
    $template->title          = $data['title'];
    $template->description    = $data['description'] ?? '';
    $template->status         = 'publish';
    $template->has_theme_file = true;
    $template->is_custom      = false;
    $template->content        = $content;
    $template->post_types     = $data['post_types'] ?? [];

    return $template;
}

// ─── Classic theme fallback ──────────────────────────────────────────

/**
 * For classic themes, render the block template via do_blocks().
 *
 * @since 2.2.0
 *
 * @param string $template The resolved template path.
 * @return string
 */
function classic_theme_fallback( string $template ): string {
    if ( wp_is_block_theme() ) {
        return $template;
    }

    if ( ! is_singular( 'sd_memorial' ) ) {
        return $template;
    }

    $fallback = get_templates_dir() . 'single-sd_memorial-classic.php';

    return file_exists( $fallback ) ? $fallback : $template;
}
add_filter( 'single_template', __NAMESPACE__ . '\\classic_theme_fallback', 20 );
