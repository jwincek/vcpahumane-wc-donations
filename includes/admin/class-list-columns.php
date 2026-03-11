<?php
/**
 * Admin List Columns - Custom columns for CPT list tables.
 *
 * @package Starter_Shelter
 * @subpackage Admin
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin;

use Starter_Shelter\Core\{ Config, Entity_Hydrator };
use Starter_Shelter\Helpers;

/**
 * Adds custom columns to CPT admin list tables.
 *
 * @since 1.0.0
 */
class List_Columns {

    /**
     * Column configurations for each post type.
     *
     * @since 1.0.0
     * @var array
     */
    private static array $columns = [];

    /**
     * Initialize list columns.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        self::$columns = self::get_column_config();

        foreach ( self::$columns as $post_type => $config ) {
            // Register columns.
            add_filter( "manage_{$post_type}_posts_columns", [ self::class, 'register_columns' ] );
            
            // Render column content.
            add_action( "manage_{$post_type}_posts_custom_column", [ self::class, 'render_column' ], 10, 2 );
            
            // Make columns sortable.
            add_filter( "manage_edit-{$post_type}_sortable_columns", [ self::class, 'register_sortable' ] );
        }

        // Handle custom sorting.
        add_action( 'pre_get_posts', [ self::class, 'handle_sorting' ] );

        // Add row actions.
        add_filter( 'post_row_actions', [ self::class, 'add_row_actions' ], 10, 2 );

        // Enqueue admin styles.
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_styles' ] );
    }

    /**
     * Get column configuration for all post types.
     *
     * @since 1.0.0
     *
     * @return array Column configurations.
     */
    private static function get_column_config(): array {
        return [
            'sd_donation' => [
                'columns' => [
                    'cb'         => '<input type="checkbox" />',
                    'title'      => __( 'Donation', 'starter-shelter' ),
                    'amount'     => __( 'Amount', 'starter-shelter' ),
                    'donor'      => __( 'Donor', 'starter-shelter' ),
                    'allocation' => __( 'Allocation', 'starter-shelter' ),
                    'campaign'   => __( 'Campaign', 'starter-shelter' ),
                    'date'       => __( 'Date', 'starter-shelter' ),
                ],
                'sortable' => [ 'amount', 'date' ],
                'row_actions' => [ 'view_receipt', 'view_order' ],
            ],
            'sd_membership' => [
                'columns' => [
                    'cb'          => '<input type="checkbox" />',
                    'title'       => __( 'Membership', 'starter-shelter' ),
                    'donor'       => __( 'Member', 'starter-shelter' ),
                    'tier'        => __( 'Tier', 'starter-shelter' ),
                    'type'        => __( 'Type', 'starter-shelter' ),
                    'status'      => __( 'Status', 'starter-shelter' ),
                    'expiry'      => __( 'Expires', 'starter-shelter' ),
                    'logo_status' => __( 'Logo', 'starter-shelter' ),
                ],
                'sortable' => [ 'expiry', 'tier' ],
                'row_actions' => [ 'send_reminder', 'extend_membership', 'view_order' ],
            ],
            'sd_memorial' => [
                'columns' => [
                    'cb'             => '<input type="checkbox" />',
                    'title'          => __( 'Memorial', 'starter-shelter' ),
                    'honoree'        => __( 'Honoree', 'starter-shelter' ),
                    'type'           => __( 'Type', 'starter-shelter' ),
                    'donor'          => __( 'From', 'starter-shelter' ),
                    'family_notified'=> __( 'Family Notified', 'starter-shelter' ),
                    'date'           => __( 'Date', 'starter-shelter' ),
                ],
                'sortable' => [ 'date', 'type' ],
                'row_actions' => [ 'view_tribute', 'notify_family' ],
            ],
            'sd_donor' => [
                'columns' => [
                    'cb'              => '<input type="checkbox" />',
                    'title'           => __( 'Donor', 'starter-shelter' ),
                    'email'           => __( 'Email', 'starter-shelter' ),
                    'lifetime_giving' => __( 'Lifetime Giving', 'starter-shelter' ),
                    'donor_level'     => __( 'Level', 'starter-shelter' ),
                    'membership'      => __( 'Membership', 'starter-shelter' ),
                    'donation_count'  => __( 'Donations', 'starter-shelter' ),
                ],
                'sortable' => [ 'lifetime_giving', 'donation_count', 'donor_level' ],
                'row_actions' => [ 'view_dashboard', 'send_statement' ],
            ],
        ];
    }

    /**
     * Register columns for a post type.
     *
     * @since 1.0.0
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public static function register_columns( array $columns ): array {
        $screen = get_current_screen();
        if ( ! $screen || ! isset( self::$columns[ $screen->post_type ] ) ) {
            return $columns;
        }

        return self::$columns[ $screen->post_type ]['columns'];
    }

    /**
     * Render column content.
     *
     * @since 1.0.0
     *
     * @param string $column  Column name.
     * @param int    $post_id Post ID.
     */
    public static function render_column( string $column, int $post_id ): void {
        $post_type = get_post_type( $post_id );
        $entity = Entity_Hydrator::get( $post_type, $post_id );

        if ( ! $entity ) {
            echo '—';
            return;
        }
        
        // Ensure entity is an array (defensive check).
        if ( is_object( $entity ) ) {
            $entity = (array) $entity;
        }

        switch ( $post_type ) {
            case 'sd_donation':
                self::render_donation_column( $column, $entity, $post_id );
                break;
            case 'sd_membership':
                self::render_membership_column( $column, $entity, $post_id );
                break;
            case 'sd_memorial':
                self::render_memorial_column( $column, $entity, $post_id );
                break;
            case 'sd_donor':
                self::render_donor_column( $column, $entity, $post_id );
                break;
        }
    }

    /**
     * Render donation column.
     *
     * @since 1.0.0
     */
    private static function render_donation_column( string $column, array $donation, int $post_id ): void {
        switch ( $column ) {
            case 'amount':
                $amount = Helpers\format_currency( $donation['amount'] ?? 0 );
                echo '<strong class="sd-amount">' . esc_html( $amount ) . '</strong>';
                if ( ! empty( $donation['is_anonymous'] ) ) {
                    echo ' <span class="sd-badge sd-badge--muted">' . esc_html__( 'Anonymous', 'starter-shelter' ) . '</span>';
                }
                break;

            case 'donor':
                $donor_id = $donation['donor_id'] ?? 0;
                if ( $donor_id ) {
                    $donor = Entity_Hydrator::get( 'sd_donor', $donor_id );
                    if ( $donor ) {
                        printf(
                            '<a href="%s">%s</a><br><span class="sd-meta">%s</span>',
                            esc_url( get_edit_post_link( $donor_id ) ),
                            esc_html( $donor['display_name'] ?? $donor['first_name'] . ' ' . $donor['last_name'] ),
                            esc_html( $donor['email'] ?? '' )
                        );
                    }
                } else {
                    echo '—';
                }
                break;

            case 'allocation':
                $allocation = $donation['allocation'] ?? 'general-fund';
                $label = Helpers\get_allocation_label( $allocation );
                echo '<span class="sd-badge sd-badge--allocation">' . esc_html( $label ) . '</span>';
                break;

            case 'campaign':
                $terms = get_the_terms( $post_id, 'sd_campaign' );
                if ( $terms && ! is_wp_error( $terms ) ) {
                    $links = array_map( function( $term ) {
                        return sprintf(
                            '<a href="%s">%s</a>',
                            esc_url( admin_url( 'edit.php?post_type=sd_donation&sd_campaign=' . $term->slug ) ),
                            esc_html( $term->name )
                        );
                    }, $terms );
                    echo implode( ', ', $links );
                } else {
                    echo '—';
                }
                break;
        }
    }

    /**
     * Render membership column.
     *
     * @since 1.0.0
     */
    private static function render_membership_column( string $column, array $membership, int $post_id ): void {
        switch ( $column ) {
            case 'donor':
                $donor_id = $membership['donor_id'] ?? 0;
                if ( $donor_id ) {
                    $donor = Entity_Hydrator::get( 'sd_donor', $donor_id );
                    if ( $donor ) {
                        $name = $donor['display_name'] ?? trim( ( $donor['first_name'] ?? '' ) . ' ' . ( $donor['last_name'] ?? '' ) );
                        printf(
                            '<a href="%s"><strong>%s</strong></a>',
                            esc_url( get_edit_post_link( $donor_id ) ),
                            esc_html( $name )
                        );
                        
                        // Show business name if applicable.
                        if ( 'business' === ( $membership['membership_type'] ?? '' ) && ! empty( $membership['business_name'] ) ) {
                            echo '<br><span class="sd-meta">' . esc_html( $membership['business_name'] ) . '</span>';
                        }
                    }
                } else {
                    echo '—';
                }
                break;

            case 'tier':
                $tier = $membership['tier'] ?? '';
                $type = $membership['membership_type'] ?? 'individual';
                $tiers = Config::get_item( 'tiers', $type, [] );
                $tier_data = $tiers[ $tier ] ?? null;
                
                if ( $tier_data ) {
                    $price = Helpers\format_currency( $tier_data['price'] ?? 0 );
                    echo '<strong>' . esc_html( $tier_data['label'] ?? ucfirst( $tier ) ) . '</strong>';
                    echo '<br><span class="sd-meta">' . esc_html( $price ) . '/year</span>';
                } else {
                    echo esc_html( ucfirst( $tier ) );
                }
                break;

            case 'type':
                $type = $membership['membership_type'] ?? 'individual';
                $type_labels = [
                    'individual' => __( 'Individual', 'starter-shelter' ),
                    'family'     => __( 'Family', 'starter-shelter' ),
                    'business'   => __( 'Business', 'starter-shelter' ),
                ];
                $class = 'business' === $type ? 'sd-badge--business' : '';
                echo '<span class="sd-badge ' . esc_attr( $class ) . '">' . esc_html( $type_labels[ $type ] ?? $type ) . '</span>';
                break;

            case 'status':
                $end_date = $membership['end_date'] ?? '';
                $is_active = ! empty( $end_date ) && strtotime( $end_date ) >= time();
                
                if ( $is_active ) {
                    $days_left = ceil( ( strtotime( $end_date ) - time() ) / DAY_IN_SECONDS );
                    if ( $days_left <= 30 ) {
                        echo '<span class="sd-badge sd-badge--warning">' . esc_html__( 'Expiring Soon', 'starter-shelter' ) . '</span>';
                    } else {
                        echo '<span class="sd-badge sd-badge--success">' . esc_html__( 'Active', 'starter-shelter' ) . '</span>';
                    }
                } else {
                    echo '<span class="sd-badge sd-badge--error">' . esc_html__( 'Expired', 'starter-shelter' ) . '</span>';
                }
                break;

            case 'expiry':
                $end_date = $membership['end_date'] ?? '';
                if ( $end_date ) {
                    $formatted = Helpers\format_date( $end_date );
                    $is_past = strtotime( $end_date ) < time();
                    $class = $is_past ? 'sd-date--expired' : '';
                    echo '<span class="' . esc_attr( $class ) . '">' . esc_html( $formatted ) . '</span>';
                } else {
                    echo '—';
                }
                break;

            case 'logo_status':
                if ( 'business' !== ( $membership['membership_type'] ?? '' ) ) {
                    echo '<span class="sd-meta">N/A</span>';
                    break;
                }
                
                $logo_id = $membership['logo_attachment_id'] ?? 0;
                $logo_status = $membership['logo_status'] ?? 'none';
                
                if ( ! $logo_id ) {
                    echo '<span class="sd-badge sd-badge--muted">' . esc_html__( 'No Logo', 'starter-shelter' ) . '</span>';
                } else {
                    $statuses = [
                        'pending'  => [ 'label' => __( 'Pending Review', 'starter-shelter' ), 'class' => 'sd-badge--warning' ],
                        'approved' => [ 'label' => __( 'Approved', 'starter-shelter' ), 'class' => 'sd-badge--success' ],
                        'rejected' => [ 'label' => __( 'Rejected', 'starter-shelter' ), 'class' => 'sd-badge--error' ],
                    ];
                    $status_info = $statuses[ $logo_status ] ?? $statuses['pending'];
                    
                    // Show thumbnail on hover.
                    $thumb = wp_get_attachment_image_url( $logo_id, 'thumbnail' );
                    if ( $thumb ) {
                        echo '<span class="sd-logo-preview" data-thumb="' . esc_url( $thumb ) . '">';
                    }
                    echo '<span class="sd-badge ' . esc_attr( $status_info['class'] ) . '">' . esc_html( $status_info['label'] ) . '</span>';
                    if ( $thumb ) {
                        echo '</span>';
                    }
                    
                    // Quick action for pending.
                    if ( 'pending' === $logo_status ) {
                        echo '<br><a href="' . esc_url( admin_url( 'admin.php?page=starter-shelter-logos' ) ) . '" class="sd-link-small">';
                        echo esc_html__( 'Review', 'starter-shelter' ) . '</a>';
                    }
                }
                break;
        }
    }

    /**
     * Render memorial column.
     *
     * @since 1.0.0
     */
    private static function render_memorial_column( string $column, array $memorial, int $post_id ): void {
        switch ( $column ) {
            case 'honoree':
                $name = $memorial['honoree_name'] ?? '';
                echo '<strong>' . esc_html( $name ) . '</strong>';
                
                if ( ! empty( $memorial['pet_species'] ) ) {
                    $species = Helpers\get_species_label( $memorial['pet_species'] );
                    echo '<br><span class="sd-meta">' . esc_html( $species ) . '</span>';
                }
                break;

            case 'type':
                $type = $memorial['memorial_type'] ?? 'human';
                $types = [
                    'human' => [ 'label' => __( 'Person', 'starter-shelter' ), 'icon' => '❤️' ],
                    'pet'   => [ 'label' => __( 'Pet', 'starter-shelter' ), 'icon' => '🐾' ],
                    'honor' => [ 'label' => __( 'Honor', 'starter-shelter' ), 'icon' => '⭐' ],
                ];
                $type_info = $types[ $type ] ?? $types['human'];
                echo '<span class="sd-type-badge">' . esc_html( $type_info['icon'] . ' ' . $type_info['label'] ) . '</span>';
                break;

            case 'donor':
                $donor_id = $memorial['donor_id'] ?? 0;
                if ( $donor_id ) {
                    $donor = Entity_Hydrator::get( 'sd_donor', $donor_id );
                    if ( $donor ) {
                        // Try display_name, then full_name, then first+last name, then post_title.
                        $donor_post = get_post( $donor_id );
                        $display_name = $donor['display_name'] 
                            ?? $donor['full_name']
                            ?? ( ! empty( $donor['first_name'] ) || ! empty( $donor['last_name'] ) 
                                ? trim( ( $donor['first_name'] ?? '' ) . ' ' . ( $donor['last_name'] ?? '' ) )
                                : ( $donor_post ? $donor_post->post_title : __( 'Unknown', 'starter-shelter' ) ) 
                            );
                        
                        printf(
                            '<a href="%s">%s</a>',
                            esc_url( get_edit_post_link( $donor_id ) ),
                            esc_html( $display_name )
                        );
                    } else {
                        // Donor entity couldn't be hydrated, try direct post title.
                        $donor_post = get_post( $donor_id );
                        if ( $donor_post ) {
                            printf(
                                '<a href="%s">%s</a>',
                                esc_url( get_edit_post_link( $donor_id ) ),
                                esc_html( $donor_post->post_title )
                            );
                        } else {
                            echo '—';
                        }
                    }
                } else {
                    echo '—';
                }
                break;

            case 'family_notified':
                $notify_data = $memorial['notify_family'] ?? null;
                // Handle both object and array formats.
                if ( is_object( $notify_data ) ) {
                    $notify_enabled = $notify_data->enabled ?? false;
                } else {
                    $notify_enabled = $notify_data['enabled'] ?? false;
                }
                $notified_date = get_post_meta( $post_id, '_sd_family_notified_date', true );
                
                if ( ! $notify_enabled ) {
                    echo '<span class="sd-meta">' . esc_html__( 'Not Requested', 'starter-shelter' ) . '</span>';
                } elseif ( $notified_date ) {
                    echo '<span class="sd-badge sd-badge--success">' . esc_html__( 'Sent', 'starter-shelter' ) . '</span>';
                    echo '<br><span class="sd-meta">' . esc_html( Helpers\format_date( $notified_date ) ) . '</span>';
                } else {
                    echo '<span class="sd-badge sd-badge--warning">' . esc_html__( 'Pending', 'starter-shelter' ) . '</span>';
                }
                break;
        }
    }

    /**
     * Render donor column.
     *
     * @since 1.0.0
     */
    private static function render_donor_column( string $column, array $donor, int $post_id ): void {
        switch ( $column ) {
            case 'email':
                $email = $donor['email'] ?? '';
                if ( $email ) {
                    echo '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
                } else {
                    echo '—';
                }
                break;

            case 'lifetime_giving':
                $total = $donor['lifetime_giving'] ?? 0;
                $formatted = Helpers\format_currency( $total );
                echo '<strong class="sd-amount">' . esc_html( $formatted ) . '</strong>';
                break;

            case 'donor_level':
                $level = $donor['donor_level'] ?? 'new';
                $levels = [
                    'new'      => [ 'label' => __( 'New', 'starter-shelter' ), 'class' => '' ],
                    'bronze'   => [ 'label' => __( 'Bronze', 'starter-shelter' ), 'class' => 'sd-level--bronze' ],
                    'silver'   => [ 'label' => __( 'Silver', 'starter-shelter' ), 'class' => 'sd-level--silver' ],
                    'gold'     => [ 'label' => __( 'Gold', 'starter-shelter' ), 'class' => 'sd-level--gold' ],
                    'platinum' => [ 'label' => __( 'Platinum', 'starter-shelter' ), 'class' => 'sd-level--platinum' ],
                ];
                $level_info = $levels[ $level ] ?? $levels['new'];
                echo '<span class="sd-level-badge ' . esc_attr( $level_info['class'] ) . '">';
                echo esc_html( $level_info['label'] ) . '</span>';
                break;

            case 'membership':
                // Check for active membership.
                global $wpdb;
                $membership = $wpdb->get_row( $wpdb->prepare( "
                    SELECT p.ID, pm_tier.meta_value as tier, pm_type.meta_value as type, pm_end.meta_value as end_date
                    FROM {$wpdb->posts} p
                    JOIN {$wpdb->postmeta} pm_donor ON p.ID = pm_donor.post_id AND pm_donor.meta_key = '_sd_donor_id'
                    LEFT JOIN {$wpdb->postmeta} pm_tier ON p.ID = pm_tier.post_id AND pm_tier.meta_key = '_sd_tier'
                    LEFT JOIN {$wpdb->postmeta} pm_type ON p.ID = pm_type.post_id AND pm_type.meta_key = '_sd_membership_type'
                    LEFT JOIN {$wpdb->postmeta} pm_end ON p.ID = pm_end.post_id AND pm_end.meta_key = '_sd_end_date'
                    WHERE p.post_type = 'sd_membership'
                    AND p.post_status = 'publish'
                    AND pm_donor.meta_value = %d
                    AND pm_end.meta_value >= %s
                    ORDER BY pm_end.meta_value DESC
                    LIMIT 1
                ", $post_id, wp_date( 'Y-m-d' ) ) );

                if ( $membership ) {
                    $tier_label = ucfirst( $membership->tier ?? '' );
                    printf(
                        '<a href="%s" class="sd-badge sd-badge--success">%s</a>',
                        esc_url( get_edit_post_link( $membership->ID ) ),
                        esc_html( $tier_label )
                    );
                } else {
                    echo '<span class="sd-badge sd-badge--muted">' . esc_html__( 'None', 'starter-shelter' ) . '</span>';
                }
                break;

            case 'donation_count':
                global $wpdb;
                $count = (int) $wpdb->get_var( $wpdb->prepare( "
                    SELECT COUNT(*)
                    FROM {$wpdb->posts} p
                    JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sd_donor_id'
                    WHERE p.post_type = 'sd_donation'
                    AND p.post_status = 'publish'
                    AND pm.meta_value = %d
                ", $post_id ) );

                if ( $count > 0 ) {
                    printf(
                        '<a href="%s">%d</a>',
                        esc_url( admin_url( 'edit.php?post_type=sd_donation&donor_id=' . $post_id ) ),
                        $count
                    );
                } else {
                    echo '0';
                }
                break;
        }
    }

    /**
     * Register sortable columns.
     *
     * @since 1.0.0
     *
     * @param array $columns Sortable columns.
     * @return array Modified sortable columns.
     */
    public static function register_sortable( array $columns ): array {
        $screen = get_current_screen();
        if ( ! $screen || ! isset( self::$columns[ $screen->post_type ] ) ) {
            return $columns;
        }

        $config = self::$columns[ $screen->post_type ];
        foreach ( $config['sortable'] ?? [] as $col ) {
            $columns[ $col ] = $col;
        }

        return $columns;
    }

    /**
     * Handle custom column sorting.
     *
     * @since 1.0.0
     *
     * @param \WP_Query $query The query object.
     */
    public static function handle_sorting( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $post_type = $query->get( 'post_type' );
        if ( ! isset( self::$columns[ $post_type ] ) ) {
            return;
        }

        $orderby = $query->get( 'orderby' );

        // Map column names to meta keys.
        $meta_key_map = [
            'amount'          => '_sd_amount',
            'expiry'          => '_sd_end_date',
            'tier'            => '_sd_tier',
            'lifetime_giving' => '_sd_lifetime_giving',
            'donation_count'  => '_sd_donation_count', // Would need to be stored/computed.
            'donor_level'     => '_sd_donor_level',
            'type'            => '_sd_memorial_type',
        ];

        if ( isset( $meta_key_map[ $orderby ] ) ) {
            $query->set( 'meta_key', $meta_key_map[ $orderby ] );
            
            // Determine meta type for proper sorting.
            $numeric_keys = [ '_sd_amount', '_sd_lifetime_giving', '_sd_donation_count' ];
            if ( in_array( $meta_key_map[ $orderby ], $numeric_keys, true ) ) {
                $query->set( 'orderby', 'meta_value_num' );
            } else {
                $query->set( 'orderby', 'meta_value' );
            }
        }
    }

    /**
     * Add custom row actions.
     *
     * @since 1.0.0
     *
     * @param array    $actions Existing actions.
     * @param \WP_Post $post    The post object.
     * @return array Modified actions.
     */
    public static function add_row_actions( array $actions, \WP_Post $post ): array {
        if ( ! isset( self::$columns[ $post->post_type ] ) ) {
            return $actions;
        }

        $entity = Entity_Hydrator::get( $post->post_type, $post->ID );
        
        // Ensure entity is an array.
        if ( ! is_array( $entity ) ) {
            if ( is_object( $entity ) ) {
                $entity = (array) $entity;
            } else {
                return $actions;
            }
        }
        
        $config = self::$columns[ $post->post_type ];
        $new_actions = [];

        foreach ( $config['row_actions'] ?? [] as $action ) {
            switch ( $action ) {
                case 'view_receipt':
                    $new_actions['view_receipt'] = sprintf(
                        '<a href="%s">%s</a>',
                        esc_url( add_query_arg( [
                            'action' => 'sd_view_receipt',
                            'id'     => $post->ID,
                            '_wpnonce' => wp_create_nonce( 'sd_view_receipt_' . $post->ID ),
                        ], admin_url( 'admin-ajax.php' ) ) ),
                        esc_html__( 'View Receipt', 'starter-shelter' )
                    );
                    break;

                case 'view_order':
                    $order_id = $entity['wc_order_id'] ?? 0;
                    if ( $order_id ) {
                        $new_actions['view_order'] = sprintf(
                            '<a href="%s">%s</a>',
                            esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ),
                            esc_html__( 'View Order', 'starter-shelter' )
                        );
                    }
                    break;

                case 'send_reminder':
                    $new_actions['send_reminder'] = sprintf(
                        '<a href="%s" class="sd-action-link">%s</a>',
                        esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sd_send_reminder&id=' . $post->ID ), 'sd_send_reminder_' . $post->ID ) ),
                        esc_html__( 'Send Reminder', 'starter-shelter' )
                    );
                    break;

                case 'extend_membership':
                    $new_actions['extend'] = sprintf(
                        '<a href="%s" class="sd-action-link">%s</a>',
                        esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sd_extend_membership&id=' . $post->ID ), 'sd_extend_' . $post->ID ) ),
                        esc_html__( 'Extend 30 Days', 'starter-shelter' )
                    );
                    break;

                case 'view_tribute':
                    $new_actions['view_tribute'] = sprintf(
                        '<a href="%s" target="_blank">%s</a>',
                        esc_url( get_permalink( $post->ID ) ),
                        esc_html__( 'View Tribute', 'starter-shelter' )
                    );
                    break;

                case 'notify_family':
                    $notify_data = $entity['notify_family'] ?? null;
                    // Handle both object and array formats.
                    if ( is_object( $notify_data ) ) {
                        $notify_enabled = $notify_data->enabled ?? false;
                    } else {
                        $notify_enabled = $notify_data['enabled'] ?? false;
                    }
                    $notified = get_post_meta( $post->ID, '_sd_family_notified_date', true );
                    
                    if ( $notify_enabled && ! $notified ) {
                        $new_actions['notify_family'] = sprintf(
                            '<a href="%s" class="sd-action-link">%s</a>',
                            esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sd_notify_family&id=' . $post->ID ), 'sd_notify_family_' . $post->ID ) ),
                            esc_html__( 'Notify Family', 'starter-shelter' )
                        );
                    }
                    break;

                case 'view_dashboard':
                    // Link to frontend donor dashboard if exists.
                    $dashboard_page = Settings::get( 'donor_dashboard_page', 0 );
                    if ( $dashboard_page ) {
                        $new_actions['view_dashboard'] = sprintf(
                            '<a href="%s" target="_blank">%s</a>',
                            esc_url( add_query_arg( 'donor_id', $post->ID, get_permalink( $dashboard_page ) ) ),
                            esc_html__( 'View Dashboard', 'starter-shelter' )
                        );
                    }
                    break;

                case 'send_statement':
                    $new_actions['send_statement'] = sprintf(
                        '<a href="%s" class="sd-action-link">%s</a>',
                        esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sd_send_statement&id=' . $post->ID ), 'sd_send_statement_' . $post->ID ) ),
                        esc_html__( 'Send Statement', 'starter-shelter' )
                    );
                    break;
            }
        }

        // Insert our actions after 'edit'.
        $position = array_search( 'edit', array_keys( $actions ), true );
        if ( false !== $position ) {
            $actions = array_slice( $actions, 0, $position + 1, true ) 
                     + $new_actions 
                     + array_slice( $actions, $position + 1, null, true );
        } else {
            $actions = array_merge( $new_actions, $actions );
        }

        return $actions;
    }

    /**
     * Enqueue admin styles for list columns.
     *
     * @since 1.0.0
     *
     * @param string $hook The current admin page hook.
     */
    public static function enqueue_styles( string $hook ): void {
        if ( 'edit.php' !== $hook ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || ! isset( self::$columns[ $screen->post_type ] ) ) {
            return;
        }

        wp_add_inline_style( 'wp-admin', self::get_inline_styles() );
    }

    /**
     * Get inline styles for list columns.
     *
     * @since 1.0.0
     *
     * @return string CSS styles.
     */
    private static function get_inline_styles(): string {
        return '
            .sd-amount { color: #059669; font-weight: 600; }
            .sd-meta { color: #6b7280; font-size: 12px; }
            .sd-link-small { font-size: 11px; }
            
            .sd-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 500;
                text-transform: uppercase;
                letter-spacing: 0.025em;
            }
            .sd-badge--success { background: #d1fae5; color: #065f46; }
            .sd-badge--warning { background: #fef3c7; color: #92400e; }
            .sd-badge--error { background: #fee2e2; color: #991b1b; }
            .sd-badge--muted { background: #f3f4f6; color: #6b7280; }
            .sd-badge--business { background: #dbeafe; color: #1e40af; }
            .sd-badge--allocation { background: #ede9fe; color: #5b21b6; }
            
            .sd-level-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                background: #f3f4f6;
            }
            .sd-level--bronze { background: #fef3c7; color: #92400e; }
            .sd-level--silver { background: #e5e7eb; color: #374151; }
            .sd-level--gold { background: #fef08a; color: #854d0e; }
            .sd-level--platinum { background: #e0e7ff; color: #3730a3; }
            
            .sd-type-badge { font-size: 13px; }
            
            .sd-date--expired { color: #dc2626; }
            
            .sd-logo-preview { position: relative; cursor: help; }
            .sd-logo-preview:hover::after {
                content: "";
                position: absolute;
                bottom: 100%;
                left: 0;
                width: 60px;
                height: 60px;
                background-image: var(--thumb-url);
                background-size: cover;
                border: 2px solid #fff;
                box-shadow: 0 2px 8px rgba(0,0,0,0.15);
                border-radius: 4px;
            }
            
            .column-amount { width: 120px; }
            .column-allocation { width: 130px; }
            .column-tier { width: 120px; }
            .column-type { width: 100px; }
            .column-status { width: 110px; }
            .column-expiry { width: 100px; }
            .column-logo_status { width: 120px; }
            .column-lifetime_giving { width: 130px; }
            .column-donor_level { width: 100px; }
            .column-membership { width: 100px; }
            .column-donation_count { width: 90px; }
            .column-family_notified { width: 130px; }
        ';
    }
}
