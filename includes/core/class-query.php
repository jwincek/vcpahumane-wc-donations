<?php
/**
 * Fluent Query Builder for entity queries.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Core;

/**
 * Fluent interface for building WP_Query with auto-hydration.
 *
 * @since 1.0.0
 */
class Query {

    /**
     * The post type to query.
     *
     * @var string
     */
    private string $post_type;

    /**
     * Meta query conditions.
     *
     * @var array
     */
    private array $meta_query = [];

    /**
     * Taxonomy query conditions.
     *
     * @var array
     */
    private array $tax_query = [];

    /**
     * Post status filter.
     *
     * @var string|array
     */
    private $post_status = 'publish';

    /**
     * Order by field.
     *
     * @var string
     */
    private string $orderby = 'date';

    /**
     * Order direction.
     *
     * @var string
     */
    private string $order = 'DESC';

    /**
     * Meta key for ordering by meta.
     *
     * @var string|null
     */
    private ?string $meta_key = null;

    /**
     * Meta type for ordering.
     *
     * @var string
     */
    private string $meta_type = 'CHAR';

    /**
     * Meta prefix for the entity.
     *
     * @var string
     */
    private string $meta_prefix = '_sd_';

	/**
	 * Whether to include permalinks in results.
	 *
	 * @since 2.0.0
	 * @var bool
	 */
	private bool $include_permalinks = false;

    /**
     * Additional query args.
     *
     * @var array
     */
    private array $additional_args = [];

    /**
     * Private constructor - use for() factory method.
     */
    private function __construct() {}

    /**
     * Start a new query for a post type.
     *
     * @since 1.0.0
     *
     * @param string $post_type The post type to query.
     * @return self
     */
    public static function for( string $post_type ): self {
        $query            = new self();
        $query->post_type = $post_type;

        $config              = Entity_Hydrator::get_config( $post_type );
        $query->meta_prefix  = $config['meta_prefix'] ?? '_sd_';

        return $query;
    }

	/**
	 * Include permalinks in hydrated results.
	 *
	 * Adds a 'permalink' key to each item. Use this instead of
	 * looping get_permalink() after paginate().
	 *
	 * @since 2.0.0
	 *
	 * @return self
	 */
	public function withPermalinks(): self {
		$this->include_permalinks = true;
		return $this;
	}

    /**
     * Add a where clause for exact match.
     *
     * @since 1.0.0
     *
     * @param string $field The field name (without prefix).
     * @param mixed  $value The value to match.
     * @return self
     */
    public function where( string $field, $value ): self {
        if ( null === $value || '' === $value ) {
            return $this;
        }

        $this->meta_query[] = [
            'key'   => $this->meta_prefix . $field,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Add a where clause with comparison operator.
     *
     * @since 1.0.0
     *
     * @param string $field   The field name.
     * @param mixed  $value   The value to compare.
     * @param string $compare The comparison operator.
     * @param string $type    Optional meta type.
     * @return self
     */
    public function whereCompare( string $field, $value, string $compare, string $type = 'CHAR' ): self {
        if ( null === $value ) {
            return $this;
        }

        $this->meta_query[] = [
            'key'     => $this->meta_prefix . $field,
            'value'   => $value,
            'compare' => $compare,
            'type'    => $type,
        ];

        return $this;
    }

    /**
     * Add a date range filter.
     *
     * @since 1.0.0
     *
     * @param string      $field The date field name.
     * @param string|null $from  Start date (Y-m-d).
     * @param string|null $to    End date (Y-m-d).
     * @return self
     */
    public function whereDateBetween( string $field, ?string $from, ?string $to ): self {
        if ( ! $from && ! $to ) {
            return $this;
        }

        $key = $this->meta_prefix . $field;

        if ( $from && $to ) {
            $this->meta_query[] = [
                'key'     => $key,
                'value'   => [ $from, $to ],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ];
        } elseif ( $from ) {
            $this->meta_query[] = [
                'key'     => $key,
                'value'   => $from,
                'compare' => '>=',
                'type'    => 'DATE',
            ];
        } else {
            $this->meta_query[] = [
                'key'     => $key,
                'value'   => $to,
                'compare' => '<=',
                'type'    => 'DATE',
            ];
        }

        return $this;
    }

    /**
     * Filter by year.
     *
     * @since 1.0.0
     *
     * @param string   $field The date field name.
     * @param int|null $year  The year to filter by.
     * @return self
     */
    public function whereYear( string $field, ?int $year ): self {
        if ( ! $year ) {
            return $this;
        }
        return $this->whereDateBetween( $field, "$year-01-01", "$year-12-31" );
    }

    /**
     * Filter by taxonomy terms.
     *
     * @since 1.0.0
     *
     * @param string    $taxonomy The taxonomy name.
     * @param int|int[] $terms    Term ID(s) to filter by.
     * @param string    $field    The term field to use.
     * @return self
     */
    public function whereInTaxonomy( string $taxonomy, $terms, string $field = 'term_id' ): self {
        if ( ! $terms ) {
            return $this;
        }

        $this->tax_query[] = [
            'taxonomy' => $taxonomy,
            'field'    => $field,
            'terms'    => (array) $terms,
        ];

        return $this;
    }

    /**
     * Add a LIKE search on a field.
     *
     * @since 1.0.0
     *
     * @param string      $field The field name.
     * @param string|null $term  The search term.
     * @return self
     */
    public function search( string $field, ?string $term ): self {
        if ( ! $term ) {
            return $this;
        }

        $this->meta_query[] = [
            'key'     => $this->meta_prefix . $field,
            'value'   => $term,
            'compare' => 'LIKE',
        ];

        return $this;
    }

    /**
     * Add a LIKE search across multiple fields (OR).
     *
     * @since 1.0.0
     *
     * @param array       $fields Array of field names.
     * @param string|null $term   The search term.
     * @return self
     */
    public function searchMultiple( array $fields, ?string $term ): self {
        if ( ! $term || empty( $fields ) ) {
            return $this;
        }

        $or_clauses = [ 'relation' => 'OR' ];

        foreach ( $fields as $field ) {
            $or_clauses[] = [
                'key'     => $this->meta_prefix . $field,
                'value'   => $term,
                'compare' => 'LIKE',
            ];
        }

        $this->meta_query[] = $or_clauses;

        return $this;
    }

    /**
     * Add an IN clause.
     *
     * @since 1.0.0
     *
     * @param string $field  The field name.
     * @param array  $values Array of values.
     * @return self
     */
    public function whereIn( string $field, array $values ): self {
        if ( empty( $values ) ) {
            return $this;
        }

        $this->meta_query[] = [
            'key'     => $this->meta_prefix . $field,
            'value'   => $values,
            'compare' => 'IN',
        ];

        return $this;
    }

    /**
     * Add a NOT IN clause.
     *
     * @since 1.0.0
     *
     * @param string $field  The field name.
     * @param array  $values Array of values to exclude.
     * @return self
     */
    public function whereNotIn( string $field, array $values ): self {
        if ( empty( $values ) ) {
            return $this;
        }

        $this->meta_query[] = [
            'key'     => $this->meta_prefix . $field,
            'value'   => $values,
            'compare' => 'NOT IN',
        ];

        return $this;
    }

    /**
     * Filter for non-null/non-empty values.
     *
     * @since 1.0.0
     *
     * @param string $field The field name.
     * @return self
     */
    public function whereExists( string $field ): self {
        $this->meta_query[] = [
            'key'     => $this->meta_prefix . $field,
            'compare' => 'EXISTS',
        ];

        $this->meta_query[] = [
            'key'     => $this->meta_prefix . $field,
            'value'   => '',
            'compare' => '!=',
        ];

        return $this;
    }

    /**
     * Set the post status filter.
     *
     * @since 1.0.0
     *
     * @param string|array $status Post status(es).
     * @return self
     */
    public function status( $status ): self {
        $this->post_status = $status;
        return $this;
    }

    /**
     * Set the order by field and direction.
     *
     * @since 1.0.0
     *
     * @param string $field     The field to order by.
     * @param string $direction Order direction.
     * @param string $type      Meta type for meta ordering.
     * @return self
     */
    public function orderBy( string $field, string $direction = 'DESC', string $type = 'CHAR' ): self {
        $this->order = strtoupper( $direction );

        $standard = [ 'date', 'title', 'ID', 'modified', 'rand', 'menu_order', 'author', 'name' ];

        if ( in_array( $field, $standard, true ) ) {
            $this->orderby  = $field;
            $this->meta_key = null;
        } else {
            $this->orderby   = 'meta_value';
            $this->meta_key  = $this->meta_prefix . $field;
            $this->meta_type = $type;

            if ( in_array( $type, [ 'NUMERIC', 'DECIMAL' ], true ) ) {
                $this->orderby = 'meta_value_num';
            }
        }

        return $this;
    }

    /**
     * Add additional WP_Query args.
     *
     * @since 1.0.0
     *
     * @param array $args Additional args to merge.
     * @return self
     */
    public function withArgs( array $args ): self {
        $this->additional_args = array_merge( $this->additional_args, $args );
        return $this;
    }

    /**
     * Execute the query with pagination.
     *
     * @since 1.0.0
     *
     * @param int $page     Current page number.
     * @param int $per_page Items per page.
     * @return array{items: array, total: int, total_pages: int, page: int, per_page: int}
     */
    public function paginate( int $page = 1, int $per_page = 10 ): array {
        $args     = $this->build_args( $page, $per_page );
        $wp_query = new \WP_Query( $args );

		$items = $this->include_permalinks
			? Entity_Hydrator::hydrate_many_with_permalinks( $wp_query->posts, $this->post_type )
			: Entity_Hydrator::hydrate_many( $wp_query->posts, $this->post_type );

        return [
            'items'       => $items,
            'total'       => $wp_query->found_posts,
            'total_pages' => $wp_query->max_num_pages,
            'page'        => $page,
            'per_page'    => $per_page,
        ];
    }

    /**
     * Get all results without pagination.
     *
     * @since 1.0.0
     *
     * @param int $limit Maximum number of results (-1 for all).
     * @return array Array of hydrated entities.
     */
    public function get( int $limit = -1 ): array {
        $args             = $this->build_args( 1, $limit );
        $args['nopaging'] = true;
        unset( $args['paged'] );

        $wp_query = new \WP_Query( $args );

        return $this->include_permalinks
			? Entity_Hydrator::hydrate_many_with_permalinks( $wp_query->posts, $this->post_type )
			: Entity_Hydrator::hydrate_many( $wp_query->posts, $this->post_type );
    }

    /**
     * Get the first result only.
     *
     * @since 1.0.0
     *
     * @return array|null The first entity or null.
     */
    public function first(): ?array {
        $results = $this->paginate( 1, 1 );
        return $results['items'][0] ?? null;
    }

    /**
     * Get count only (optimized).
     *
     * @since 1.0.0
     *
     * @return int The count.
     */
    public function count(): int {
        $args           = $this->build_args( 1, 1 );
        $args['fields'] = 'ids';

        return ( new \WP_Query( $args ) )->found_posts;
    }

    /**
     * Check if any results exist.
     *
     * @since 1.0.0
     *
     * @return bool Whether any results exist.
     */
    public function exists(): bool {
        return $this->count() > 0;
    }

    /**
     * Get post IDs only.
     *
     * @since 1.0.0
     *
     * @param int $limit Maximum number of IDs.
     * @return int[] Array of post IDs.
     */
    public function pluckIds( int $limit = -1 ): array {
        $args             = $this->build_args( 1, $limit );
        $args['fields']   = 'ids';
        $args['nopaging'] = true;
        unset( $args['paged'] );

        return ( new \WP_Query( $args ) )->posts;
    }

    /**
     * Calculate sum of a numeric field.
     *
     * @since 1.0.0
     *
     * @param string $field The field to sum.
     * @return float The sum.
     */
    public function sum( string $field ): float {
        global $wpdb;

        $meta_key = $this->meta_prefix . $field;
        $args     = $this->build_args( 1, -1 );

        $where_clauses = [ "p.post_type = %s", "p.post_status = %s" ];
        $where_values  = [ $this->post_type, is_array( $this->post_status ) ? $this->post_status[0] : $this->post_status ];

        $meta_joins  = [];
        $meta_wheres = [];
        $i           = 0;

        foreach ( $this->meta_query as $mq ) {
            if ( isset( $mq['relation'] ) ) {
				continue; // Skip OR groups in sum (simplified).
			}
            $alias           = "pm$i";
            $meta_joins[]    = "INNER JOIN {$wpdb->postmeta} $alias ON p.ID = $alias.post_id";
            $meta_wheres[]   = $wpdb->prepare( "$alias.meta_key = %s", $mq['key'] );

            if ( isset( $mq['value'] ) && isset( $mq['compare'] ) ) {
                $compare = $mq['compare'];
                $value   = $mq['value'];

                if ( 'BETWEEN' === $compare && is_array( $value ) ) {
                    $meta_wheres[] = $wpdb->prepare( "$alias.meta_value BETWEEN %s AND %s", $value[0], $value[1] );
                } elseif ( 'LIKE' === $compare ) {
                    $meta_wheres[] = $wpdb->prepare( "$alias.meta_value LIKE %s", '%' . $wpdb->esc_like( $value ) . '%' );
                } elseif ( 'IN' === $compare && is_array( $value ) ) {
                    $placeholders  = implode( ',', array_fill( 0, count( $value ), '%s' ) );
                    $meta_wheres[] = $wpdb->prepare( "$alias.meta_value IN ($placeholders)", ...$value );
                } else {
                    $meta_wheres[] = $wpdb->prepare( "$alias.meta_value = %s", $value );
                }
            }

            $i++;
        }

        $joins_sql = implode( ' ', $meta_joins );
        $joins_sql .= " INNER JOIN {$wpdb->postmeta} pm_sum ON p.ID = pm_sum.post_id AND pm_sum.meta_key = %s";
        $where_values[] = $meta_key;

        $where_sql = implode( ' AND ', array_merge( $where_clauses, $meta_wheres ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT COALESCE(SUM(CAST(pm_sum.meta_value AS DECIMAL(15,2))), 0) 
             FROM {$wpdb->posts} p 
             $joins_sql 
             WHERE $where_sql",
            ...$where_values
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (float) $wpdb->get_var( $sql );
    }

    /**
     * Build WP_Query arguments.
     *
     * @since 1.0.0
     *
     * @param int $page     Page number.
     * @param int $per_page Items per page.
     * @return array WP_Query args.
     */
    private function build_args( int $page, int $per_page ): array {
        $args = [
            'post_type'      => $this->post_type,
            'post_status'    => $this->post_status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'order'          => $this->order,
            'orderby'        => $this->orderby,
        ];

        if ( ! empty( $this->meta_query ) ) {
            $args['meta_query'] = $this->meta_query;
        }

        if ( ! empty( $this->tax_query ) ) {
            $args['tax_query'] = $this->tax_query;
        }

        if ( $this->meta_key ) {
            $args['meta_key']  = $this->meta_key;
            $args['meta_type'] = $this->meta_type;
        }

        if ( ! empty( $this->additional_args ) ) {
            $args = array_merge( $args, $this->additional_args );
        }

        return $args;
    }
}
