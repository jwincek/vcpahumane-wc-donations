<?php
/**
 * Memorial Archive Block — Deprecation Bridge
 *
 * Maps the old starter-shelter/memorial-archive block to the unified
 * starter-shelter/memorial-wall block with paginationStyle = 'paged'.
 *
 * When WordPress encounters a saved memorial-archive block, this render.php
 * translates its attributes and delegates to the memorial-wall render.
 *
 * Once all content has been migrated, this block directory can be removed.
 *
 * @package    Starter_Shelter
 * @subpackage Blocks
 * @since      2.1.0
 *
 * @var array    $attributes Block attributes from the old memorial-archive block.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

declare( strict_types = 1 );

// Map old memorial-archive attributes → memorial-wall attributes.
$attributes = [
    'archiveId'       => $attributes['archiveId'] ?? '',
    'perPage'         => $attributes['perPage'] ?? 12,
    'columns'         => $attributes['columns'] ?? 3,
    'showSearch'      => $attributes['showSearch'] ?? true,
    'showFilters'     => $attributes['showFilters'] ?? true,
    'showPagination'  => true,
    'showYearFilter'  => true,
    'showDonorName'   => true,
    'showDate'        => true,
    'showImage'       => true,
    'truncateTribute' => 100,
    'emptyMessage'    => '',
    'layout'          => 'grid',
    'cardStyle'       => 'elevated',
    'paginationStyle' => 'paged',

    // Fix the enum: old block used 'human', new block uses 'person'.
    'defaultType'     => ( 'human' === ( $attributes['defaultType'] ?? 'all' ) )
        ? 'person'
        : ( $attributes['defaultType'] ?? 'all' ),
];

// Delegate to the memorial-wall render.php.
include __DIR__ . '/../memorial-wall/render.php';
