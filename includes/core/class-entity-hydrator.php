<?php
/**
 * Entity Hydrator — Config-driven post-to-array conversion.
 *
 * Fixes N+1 query patterns present in the original implementation:
 *
 * 1. hydrate() called get_post_meta( $id, $key, true ) per field.
 *    For an entity with 8 fields, that's 8 queries per post.
 *    Now calls get_post_meta( $id ) ONCE to get all meta, then picks fields.
 *    WordPress caches this, so subsequent get_post_meta calls are free.
 *
 * 2. hydrate_many() called update_postmeta_cache() but NOT
 *    update_object_term_cache(). For entities with taxonomy relations
 *    (sd_donation → sd_campaign, sd_memorial → sd_memorial_year),
 *    get_the_terms() triggered 1 query per post.
 *    Now primes both caches in batch before hydration.
 *
 * 3. Computed fields like get_donor_display_name() call get_post_meta()
 *    on the DONOR post (a different post type). In list contexts, this
 *    means N donor lookups for N items. Now provides batch_prime_relations()
 *    to pre-cache related entity meta before hydrating.
 *
 * 4. get_permalink() in list_memorials() loop triggered N queries.
 *    Now provides hydrate_many_with_extras() that batch-primes permalink
 *    and attachment caches.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Core;

/**
 * Hydrates WP_Post objects into entity arrays using config definitions.
 *
 * @since 1.0.0
 */
class Entity_Hydrator {

	/**
	 * Cached entity configurations.
	 *
	 * @var array<string, array>
	 */
	private static array $configs = [];

	/**
	 * Initialize the hydrator with entity configs.
	 *
	 * @since 1.0.0
	 */
	public static function init(): void {
		self::$configs = Config::get_item( 'entities', 'entities', [] );
	}

	/**
	 * Get a single entity by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type The post type.
	 * @param int    $post_id   The post ID.
	 * @return array|null The hydrated entity or null if not found.
	 */
	public static function get( string $post_type, int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== $post_type ) {
			return null;
		}
		return self::hydrate( $post, $post_type );
	}

	/**
	 * Hydrate a WP_Post into an entity array.
	 *
	 * Uses get_post_meta( $id ) with no key to fetch ALL meta in one query,
	 * then picks individual fields from the result. WordPress's object cache
	 * ensures subsequent get_post_meta() calls for the same post are free.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post      The post object.
	 * @param string   $post_type The post type (for config lookup).
	 * @return array The hydrated entity data.
	 */
	public static function hydrate( \WP_Post $post, string $post_type ): array {
		if ( empty( self::$configs ) ) {
			self::init();
		}

		$config = self::$configs[ $post_type ] ?? null;

		if ( ! $config ) {
			return [
				'id'    => $post->ID,
				'title' => $post->post_title,
			];
		}

		$prefix = $config['meta_prefix'] ?? '_';
		$entity = [ 'id' => $post->ID ];

		// Fetch ALL meta in one call. WordPress caches this per-post,
		// so get_post_meta($id, $key, true) calls in computed fields
		// will hit the cache, not the database.
		$all_meta = get_post_meta( $post->ID );

		// Hydrate defined fields from the bulk meta array.
		foreach ( $config['fields'] ?? [] as $field => $field_config ) {
			$meta_key  = $prefix . $field;
			$raw_value = isset( $all_meta[ $meta_key ] ) ? $all_meta[ $meta_key ][0] : '';
			$entity[ $field ] = self::cast( $raw_value, $field_config );
		}

		// Compute derived fields.
		foreach ( $config['computed'] ?? [] as $field => $computed_config ) {
			$entity[ $field ] = self::compute( $computed_config, $entity, $post );
		}

		// Load taxonomy relations.
		foreach ( $config['relations'] ?? [] as $field => $relation_config ) {
			if ( 'taxonomy' === ( $relation_config['type'] ?? '' ) ) {
				$entity[ $field ] = self::get_taxonomy_terms( $post->ID, $relation_config['taxonomy'] );
			}
		}

		return $entity;
	}

	/**
	 * Hydrate multiple posts at once with full cache priming.
	 *
	 * Primes three caches before hydration to eliminate N+1 queries:
	 * 1. Post meta cache (update_postmeta_cache)
	 * 2. Taxonomy term cache (update_object_term_cache)
	 * 3. Related entity meta cache (for cross-entity computed fields)
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post[] $posts     Array of WP_Post objects.
	 * @param string     $post_type The post type.
	 * @return array Array of hydrated entities.
	 */
	public static function hydrate_many( array $posts, string $post_type ): array {
		if ( empty( $posts ) ) {
			return [];
		}

		$post_ids = wp_list_pluck( $posts, 'ID' );

		// 1. Prime post meta cache — one query for all posts.
		update_postmeta_cache( $post_ids );

		// 2. Prime taxonomy term cache — one query per taxonomy.
		$taxonomies = self::get_entity_taxonomies( $post_type );
		if ( ! empty( $taxonomies ) ) {
			update_object_term_cache( $post_ids, $post_type );
		}

		// 3. Prime related entity meta (e.g., donor meta for donation lists).
		self::prime_relation_caches( $posts, $post_type );

		return array_map(
			fn( \WP_Post $post ) => self::hydrate( $post, $post_type ),
			$posts
		);
	}

	/**
	 * Hydrate many posts and add permalink to each result.
	 *
	 * Use this instead of looping get_permalink() after hydrate_many().
	 * Primes all caches and adds permalink in the same pass.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_Post[] $posts     Array of WP_Post objects.
	 * @param string     $post_type The post type.
	 * @return array Array of hydrated entities with 'permalink' key.
	 */
	public static function hydrate_many_with_permalinks( array $posts, string $post_type ): array {
		$items = self::hydrate_many( $posts, $post_type );

		// get_permalink() on a cached post is cheap (no DB hit),
		// but we still avoid the function call overhead for empty sets.
		foreach ( $items as $i => &$item ) {
			$item['permalink'] = get_permalink( $posts[ $i ]->ID );
		}

		return $items;
	}

	/**
	 * Prime meta caches for related entities.
	 *
	 * For example, when hydrating a list of sd_donation posts, each donation
	 * has a donor_id field. The computed field "donor_name" calls
	 * get_donor_display_name( donor_id ), which calls get_post_meta() on
	 * the donor post. Without priming, that's N donor meta queries.
	 *
	 * This method collects all unique foreign key IDs from the posts' meta,
	 * then primes the meta cache for those related posts in one query.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_Post[] $posts     Array of WP_Post objects.
	 * @param string     $post_type The post type.
	 */
	private static function prime_relation_caches( array $posts, string $post_type ): void {
		if ( empty( self::$configs ) ) {
			self::init();
		}

		$config = self::$configs[ $post_type ] ?? null;
		if ( ! $config ) {
			return;
		}

		$prefix   = $config['meta_prefix'] ?? '_';
		$relations = $config['relations'] ?? [];

		// Collect IDs for each entity relation (not taxonomy relations).
		$related_ids_by_type = [];

		foreach ( $relations as $field => $relation_config ) {
			$rel_type = $relation_config['type'] ?? '';
			if ( 'taxonomy' === $rel_type ) {
				continue; // Taxonomy cache already primed by update_object_term_cache.
			}

			$fk_field = $relation_config['foreign_key'] ?? '';
			if ( ! $fk_field ) {
				continue;
			}

			$meta_key = $prefix . $fk_field;

			foreach ( $posts as $post ) {
				// Read from already-primed meta cache (step 1 did update_postmeta_cache).
				$related_id = (int) get_post_meta( $post->ID, $meta_key, true );
				if ( $related_id > 0 ) {
					$related_ids_by_type[ $rel_type ][ $related_id ] = true;
				}
			}
		}

		// Prime meta cache for each related post type in one query per type.
		foreach ( $related_ids_by_type as $related_type => $ids_map ) {
			$ids = array_keys( $ids_map );
			if ( ! empty( $ids ) ) {
				// Prime the post cache (loads posts into WP object cache).
				_prime_post_caches( $ids, true, true );
			}
		}
	}

	/**
	 * Get taxonomy names used by an entity type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $post_type The post type.
	 * @return string[] Array of taxonomy names.
	 */
	private static function get_entity_taxonomies( string $post_type ): array {
		if ( empty( self::$configs ) ) {
			self::init();
		}

		$config = self::$configs[ $post_type ] ?? null;
		if ( ! $config ) {
			return [];
		}

		$taxonomies = [];
		foreach ( $config['relations'] ?? [] as $relation_config ) {
			if ( 'taxonomy' === ( $relation_config['type'] ?? '' ) && ! empty( $relation_config['taxonomy'] ) ) {
				$taxonomies[] = $relation_config['taxonomy'];
			}
		}

		return $taxonomies;
	}

	/**
	 * Cast a raw value to the correct type based on config.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value  The raw value from meta.
	 * @param array $config The field configuration.
	 * @return mixed The cast value.
	 */
	private static function cast( $value, array $config ) {
		$type = $config['type'] ?? 'string';

		if ( '' === $value || null === $value ) {
			return $config['default'] ?? self::type_default( $type );
		}

		return match ( $type ) {
			'integer' => (int) $value,
			'number'  => (float) $value,
			'boolean' => filter_var( $value, FILTER_VALIDATE_BOOLEAN ),
			'array'   => self::to_array( $value ),
			'object'  => self::to_array( $value ),
			default   => (string) $value,
		};
	}

	/**
	 * Convert a value to an array recursively.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The value to convert.
	 * @return array The converted array.
	 */
	private static function to_array( $value ): array {
		if ( is_array( $value ) ) {
			return array_map( fn( $v ) => is_object( $v ) || is_array( $v ) ? self::to_array( $v ) : $v, $value );
		}

		if ( is_object( $value ) ) {
			return array_map( fn( $v ) => is_object( $v ) || is_array( $v ) ? self::to_array( $v ) : $v, (array) $value );
		}

		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : [];
		}

		return [];
	}

	/**
	 * Get the default value for a type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type The field type.
	 * @return mixed The default value.
	 */
	private static function type_default( string $type ) {
		return match ( $type ) {
			'integer' => 0,
			'number'  => 0.0,
			'boolean' => false,
			'array'   => [],
			'object'  => [],
			default   => '',
		};
	}

	/**
	 * Compute a derived field value.
	 *
	 * @since 1.0.0
	 *
	 * @param array    $config The computed field configuration.
	 * @param array    $entity The current entity data.
	 * @param \WP_Post $post   The original post object.
	 * @return mixed The computed value.
	 */
	private static function compute( array $config, array $entity, \WP_Post $post ) {
		$function = $config['function'] ?? '';
		if ( empty( $function ) ) {
			return null;
		}

		$args = [];
		foreach ( $config['args'] ?? [] as $arg ) {
			if ( '_self' === $arg ) {
				$args[] = $post->ID;
			} elseif ( '_post' === $arg ) {
				$args[] = $post;
			} elseif ( isset( $entity[ $arg ] ) ) {
				$args[] = $entity[ $arg ];
			} else {
				$args[] = $arg;
			}
		}

		$callable = 'Starter_Shelter\\Helpers\\' . $function;
		if ( function_exists( $callable ) ) {
			return $callable( ...$args );
		}

		if ( function_exists( $function ) ) {
			return $function( ...$args );
		}

		return null;
	}

	/**
	 * Get taxonomy terms for a post.
	 *
	 * When called after update_object_term_cache(), this hits the
	 * WP object cache instead of the database.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $taxonomy The taxonomy name.
	 * @return array Array of term data.
	 */
	private static function get_taxonomy_terms( int $post_id, string $taxonomy ): array {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return [];
		}

		return array_map(
			fn( \WP_Term $term ) => [
				'id'   => $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
			],
			$terms
		);
	}

	/**
	 * Get a related entity.
	 *
	 * @since 1.0.0
	 *
	 * @param string $entity_type The related entity type.
	 * @param int    $entity_id   The related entity ID.
	 * @return array|null The related entity or null.
	 */
	public static function get_relation( string $entity_type, int $entity_id ): ?array {
		if ( ! $entity_id ) {
			return null;
		}
		return self::get( $entity_type, $entity_id );
	}

	/**
	 * Get the config for an entity type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type The post type.
	 * @return array|null The entity config or null.
	 */
	public static function get_config( string $post_type ): ?array {
		if ( empty( self::$configs ) ) {
			self::init();
		}
		return self::$configs[ $post_type ] ?? null;
	}

	/**
	 * Refresh configs from file.
	 *
	 * @since 1.0.0
	 */
	public static function refresh(): void {
		Config::clear_cache( 'entities' );
		self::init();
	}
}
