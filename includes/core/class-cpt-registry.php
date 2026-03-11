<?php
/**
 * CPT Registry for auto-registering post types and taxonomies from config.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Core;

/**
 * Registers custom post types and taxonomies from configuration.
 *
 * @since 1.0.0
 */
class CPT_Registry {

    /**
     * Initialize the registry.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        add_action( 'init', [ self::class, 'register_post_types' ], 5 );
        add_action( 'init', [ self::class, 'register_taxonomies' ], 5 );
        add_action( 'init', [ self::class, 'register_post_meta' ], 11 );
    }

    /**
     * Register all post types from config.
     *
     * @since 1.0.0
     */
    public static function register_post_types(): void {
        $post_types = Config::get_item( 'post-types', 'post_types', [] );

        foreach ( $post_types as $slug => $config ) {
            self::register_post_type( $slug, $config );
        }
    }

    /**
     * Register a single post type.
     *
     * @since 1.0.0
     *
     * @param string $slug   The post type slug.
     * @param array  $config The post type configuration.
     */
    private static function register_post_type( string $slug, array $config ): void {
        $labels = self::build_labels( $config['labels'] ?? [] );

        $args = [
            'labels'              => $labels,
            'public'              => $config['public'] ?? false,
            'publicly_queryable'  => $config['publicly_queryable'] ?? ( $config['public'] ?? false ),
            'show_ui'             => $config['show_ui'] ?? true,
            'show_in_menu'        => $config['show_in_menu'] ?? true,
            'show_in_nav_menus'   => $config['show_in_nav_menus'] ?? false,
            'show_in_rest'        => $config['show_in_rest'] ?? true,
            'rest_base'           => $config['rest_base'] ?? $slug,
            'has_archive'         => $config['has_archive'] ?? false,
            'hierarchical'        => $config['hierarchical'] ?? false,
            'exclude_from_search' => $config['exclude_from_search'] ?? ! ( $config['public'] ?? false ),
            'capability_type'     => $config['capability_type'] ?? 'post',
            'map_meta_cap'        => $config['map_meta_cap'] ?? true,
            'supports'            => $config['supports'] ?? [ 'title' ],
            'menu_icon'           => $config['menu_icon'] ?? 'dashicons-admin-post',
            'menu_position'       => $config['menu_position'] ?? null,
        ];

        // Handle rewrite.
        if ( isset( $config['rewrite'] ) ) {
            if ( false === $config['rewrite'] ) {
                $args['rewrite'] = false;
            } elseif ( is_array( $config['rewrite'] ) ) {
                $args['rewrite'] = $config['rewrite'];
            } else {
                $args['rewrite'] = [ 'slug' => $config['rewrite'] ];
            }
        } else {
            $args['rewrite'] = false;
        }

        register_post_type( $slug, $args );
    }

    /**
     * Register all taxonomies from config.
     *
     * @since 1.0.0
     */
    public static function register_taxonomies(): void {
        $taxonomies = Config::get_item( 'taxonomies', 'taxonomies', [] );

        foreach ( $taxonomies as $slug => $config ) {
            self::register_taxonomy( $slug, $config );
        }
    }

    /**
     * Register a single taxonomy.
     *
     * @since 1.0.0
     *
     * @param string $slug   The taxonomy slug.
     * @param array  $config The taxonomy configuration.
     */
    private static function register_taxonomy( string $slug, array $config ): void {
        $labels     = self::build_taxonomy_labels( $config['labels'] ?? [] );
        $post_types = $config['post_types'] ?? [];

        $args = [
            'labels'             => $labels,
            'public'             => $config['public'] ?? true,
            'publicly_queryable' => $config['publicly_queryable'] ?? ( $config['public'] ?? true ),
            'show_ui'            => $config['show_ui'] ?? true,
            'show_in_menu'       => $config['show_in_menu'] ?? true,
            'show_in_nav_menus'  => $config['show_in_nav_menus'] ?? true,
            'show_in_rest'       => $config['show_in_rest'] ?? true,
            'rest_base'          => $config['rest_base'] ?? $slug,
            'hierarchical'       => $config['hierarchical'] ?? false,
            'show_admin_column'  => $config['show_admin_column'] ?? true,
            'query_var'          => $config['query_var'] ?? true,
        ];

        // Handle rewrite.
        if ( isset( $config['rewrite'] ) ) {
            if ( false === $config['rewrite'] ) {
                $args['rewrite'] = false;
            } elseif ( is_array( $config['rewrite'] ) ) {
                $args['rewrite'] = $config['rewrite'];
            } else {
                $args['rewrite'] = [ 'slug' => $config['rewrite'] ];
            }
        } else {
            $args['rewrite'] = [ 'slug' => $slug ];
        }

        register_taxonomy( $slug, $post_types, $args );

        // Create default terms if specified.
        if ( ! empty( $config['default_terms'] ) ) {
            self::create_default_terms( $slug, $config['default_terms'] );
        }
    }

    /**
     * Create default taxonomy terms.
     *
     * @since 1.0.0
     *
     * @param string $taxonomy      The taxonomy name.
     * @param array  $default_terms Array of default terms.
     */
    private static function create_default_terms( string $taxonomy, array $default_terms ): void {
        foreach ( $default_terms as $term ) {
            $slug = $term['slug'] ?? sanitize_title( $term['name'] );

            if ( ! term_exists( $slug, $taxonomy ) ) {
                $args = [];
                if ( isset( $term['description'] ) ) {
                    $args['description'] = $term['description'];
                }
                if ( isset( $term['slug'] ) ) {
                    $args['slug'] = $term['slug'];
                }

                wp_insert_term( $term['name'], $taxonomy, $args );
            }
        }
    }

    /**
     * Register post meta for all entities.
     *
     * @since 1.0.0
     */
    public static function register_post_meta(): void {
        $entities = Config::get_item( 'entities', 'entities', [] );

        foreach ( $entities as $post_type => $config ) {
            $prefix = $config['meta_prefix'] ?? '_sd_';

            foreach ( $config['fields'] ?? [] as $field => $field_config ) {
                self::register_meta_field( $post_type, $prefix . $field, $field_config );
            }
        }
    }

    /**
     * Register a single meta field.
     *
     * @since 1.0.0
     *
     * @param string $post_type    The post type.
     * @param string $meta_key     The meta key.
     * @param array  $field_config The field configuration.
     */
    private static function register_meta_field( string $post_type, string $meta_key, array $field_config ): void {
        $type        = self::map_schema_type( $field_config['type'] ?? 'string' );
        $description = $field_config['description'] ?? '';

        $args = [
            'object_subtype'    => $post_type,
            'type'              => $type,
            'description'       => $description,
            'single'            => true,
            'show_in_rest'      => $field_config['show_in_rest'] ?? false,
            'sanitize_callback' => self::get_sanitize_callback( $field_config['type'] ?? 'string' ),
            'auth_callback'     => function() {
                return current_user_can( 'edit_posts' );
            },
        ];

        // Add default if specified.
        if ( isset( $field_config['default'] ) ) {
            $args['default'] = $field_config['default'];
        }

        register_meta( 'post', $meta_key, $args );
    }

    /**
     * Map JSON Schema type to WordPress meta type.
     *
     * @since 1.0.0
     *
     * @param string $schema_type The JSON Schema type.
     * @return string The WordPress meta type.
     */
    private static function map_schema_type( string $schema_type ): string {
        return match ( $schema_type ) {
            'integer' => 'integer',
            'number'  => 'number',
            'boolean' => 'boolean',
            'array'   => 'array',
            'object'  => 'object',
            default   => 'string',
        };
    }

    /**
     * Get the appropriate sanitization callback for a field type.
     *
     * @since 1.0.0
     *
     * @param string $type The field type.
     * @return callable The sanitization callback.
     */
    private static function get_sanitize_callback( string $type ): callable {
        return match ( $type ) {
            'integer' => 'absint',
            'number'  => fn( $value ) => (float) $value,
            'boolean' => 'rest_sanitize_boolean',
            'email'   => 'sanitize_email',
            'url'     => 'esc_url_raw',
            'array'   => fn( $value ) => is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : [],
            'object'  => fn( $value ) => is_array( $value ) ? $value : [],
            default   => 'sanitize_text_field',
        };
    }

    /**
     * Build post type labels from config.
     *
     * @since 1.0.0
     *
     * @param array $config Label configuration with singular and plural.
     * @return array Complete labels array.
     */
    private static function build_labels( array $config ): array {
        $singular = $config['singular'] ?? 'Item';
        $plural   = $config['plural'] ?? 'Items';

        return [
            'name'                     => $plural,
            'singular_name'            => $singular,
            'menu_name'                => $config['menu_name'] ?? $plural,
            'all_items'                => sprintf( 'All %s', $plural ),
            'add_new'                  => 'Add New',
            'add_new_item'             => sprintf( 'Add New %s', $singular ),
            'edit_item'                => sprintf( 'Edit %s', $singular ),
            'new_item'                 => sprintf( 'New %s', $singular ),
            'view_item'                => sprintf( 'View %s', $singular ),
            'view_items'               => sprintf( 'View %s', $plural ),
            'search_items'             => sprintf( 'Search %s', $plural ),
            'not_found'                => sprintf( 'No %s found', strtolower( $plural ) ),
            'not_found_in_trash'       => sprintf( 'No %s found in Trash', strtolower( $plural ) ),
            'parent_item_colon'        => sprintf( 'Parent %s:', $singular ),
            'archives'                 => sprintf( '%s Archives', $singular ),
            'attributes'               => sprintf( '%s Attributes', $singular ),
            'insert_into_item'         => sprintf( 'Insert into %s', strtolower( $singular ) ),
            'uploaded_to_this_item'    => sprintf( 'Uploaded to this %s', strtolower( $singular ) ),
            'filter_items_list'        => sprintf( 'Filter %s list', strtolower( $plural ) ),
            'items_list_navigation'    => sprintf( '%s list navigation', $plural ),
            'items_list'               => sprintf( '%s list', $plural ),
            'item_published'           => sprintf( '%s published.', $singular ),
            'item_published_privately' => sprintf( '%s published privately.', $singular ),
            'item_reverted_to_draft'   => sprintf( '%s reverted to draft.', $singular ),
            'item_scheduled'           => sprintf( '%s scheduled.', $singular ),
            'item_updated'             => sprintf( '%s updated.', $singular ),
        ];
    }

    /**
     * Build taxonomy labels from config.
     *
     * @since 1.0.0
     *
     * @param array $config Label configuration with singular and plural.
     * @return array Complete labels array.
     */
    private static function build_taxonomy_labels( array $config ): array {
        $singular = $config['singular'] ?? 'Category';
        $plural   = $config['plural'] ?? 'Categories';

        return [
            'name'                       => $plural,
            'singular_name'              => $singular,
            'menu_name'                  => $config['menu_name'] ?? $plural,
            'all_items'                  => sprintf( 'All %s', $plural ),
            'edit_item'                  => sprintf( 'Edit %s', $singular ),
            'view_item'                  => sprintf( 'View %s', $singular ),
            'update_item'                => sprintf( 'Update %s', $singular ),
            'add_new_item'               => sprintf( 'Add New %s', $singular ),
            'new_item_name'              => sprintf( 'New %s Name', $singular ),
            'parent_item'                => sprintf( 'Parent %s', $singular ),
            'parent_item_colon'          => sprintf( 'Parent %s:', $singular ),
            'search_items'               => sprintf( 'Search %s', $plural ),
            'popular_items'              => sprintf( 'Popular %s', $plural ),
            'separate_items_with_commas' => sprintf( 'Separate %s with commas', strtolower( $plural ) ),
            'add_or_remove_items'        => sprintf( 'Add or remove %s', strtolower( $plural ) ),
            'choose_from_most_used'      => sprintf( 'Choose from the most used %s', strtolower( $plural ) ),
            'not_found'                  => sprintf( 'No %s found', strtolower( $plural ) ),
            'no_terms'                   => sprintf( 'No %s', strtolower( $plural ) ),
            'items_list_navigation'      => sprintf( '%s list navigation', $plural ),
            'items_list'                 => sprintf( '%s list', $plural ),
            'back_to_items'              => sprintf( '&larr; Back to %s', $plural ),
        ];
    }

    /**
     * Get all registered post types from config.
     *
     * @since 1.0.0
     *
     * @return array Array of post type slugs.
     */
    public static function get_post_types(): array {
        return array_keys( Config::get_item( 'post-types', 'post_types', [] ) );
    }

    /**
     * Get all registered taxonomies from config.
     *
     * @since 1.0.0
     *
     * @return array Array of taxonomy slugs.
     */
    public static function get_taxonomies(): array {
        return array_keys( Config::get_item( 'taxonomies', 'taxonomies', [] ) );
    }
}
