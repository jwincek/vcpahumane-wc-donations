<?php
/**
 * CSV Validator - Config-driven row validation for imports.
 *
 * Reads required fields, validation types, and enum constraints from
 * import-export.json and entities.json rather than hardcoding them in
 * a switch statement per entity type.
 *
 * @package Starter_Shelter
 * @subpackage Admin\Import_Export
 * @since 2.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Admin\Import_Export;

use Starter_Shelter\Core\Config;

/**
 * Validates CSV import rows against config-defined rules.
 *
 * @since 2.0.0
 */
class CSV_Validator {

	/**
	 * Import config for this entity type.
	 *
	 * @var array
	 */
	private array $import_config;

	/**
	 * Entity type key (e.g. 'donations', 'memberships').
	 *
	 * @var string
	 */
	private string $entity_type;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param string $entity_type Key from import-export.json entity_types.
	 */
	public function __construct( string $entity_type ) {
		$this->entity_type   = $entity_type;
		$this->import_config = Config::get_path(
			'import-export',
			"entity_types.{$entity_type}.import",
			[]
		);
	}

	/**
	 * Check that a CSV file has the required column headers.
	 *
	 * @since 2.0.0
	 *
	 * @param array $headers Normalized (lowercase) CSV column headers.
	 * @return array List of missing required column names. Empty if all present.
	 */
	public function validate_headers( array $headers ): array {
		$required = $this->import_config['required'] ?? [];
		return array_values( array_diff( $required, $headers ) );
	}

	/**
	 * Validate a single import row.
	 *
	 * @since 2.0.0
	 *
	 * @param array $row Associative array of column_name => value.
	 * @return array List of error messages. Empty if valid.
	 */
	public function validate_row( array $row ): array {
		$errors = [];

		// Check required fields are present and non-empty.
		foreach ( ( $this->import_config['required'] ?? [] ) as $col ) {
			if ( ! isset( $row[ $col ] ) || '' === trim( (string) $row[ $col ] ) ) {
				$errors[] = sprintf(
					/* translators: %s: column name */
					__( 'Missing required field: %s', 'starter-shelter' ),
					$col
				);
			}
		}

		// Validate each field per its field_map rules.
		$field_map = $this->import_config['field_map'] ?? [];

		foreach ( $field_map as $csv_col => $mapping ) {
			$value = $row[ $csv_col ] ?? '';

			// Skip validation for empty optional fields.
			if ( '' === trim( (string) $value ) ) {
				// But check conditional required_when.
				if ( isset( $mapping['required_when'] ) ) {
					$condition   = $mapping['required_when'];
					$check_field = $condition['field'] ?? '';
					$check_value = $condition['equals'] ?? '';

					if ( ( $row[ $check_field ] ?? '' ) === $check_value ) {
						$errors[] = sprintf(
							/* translators: 1: column name, 2: condition field, 3: condition value */
							__( '%1$s is required when %2$s is "%3$s"', 'starter-shelter' ),
							$csv_col,
							$check_field,
							$check_value
						);
					}
				}
				continue;
			}

			$error = $this->validate_field( $csv_col, (string) $value, $mapping );
			if ( $error ) {
				$errors[] = $error;
			}
		}

		return $errors;
	}

	/**
	 * Validate a single field value against its configured rules.
	 *
	 * @since 2.0.0
	 *
	 * @param string $col     The CSV column name.
	 * @param string $value   The field value.
	 * @param array  $mapping The field_map entry from config.
	 * @return string|null Error message or null if valid.
	 */
	private function validate_field( string $col, string $value, array $mapping ): ?string {
		$type = $mapping['validate'] ?? 'text';

		return match ( $type ) {
			'email' => $this->validate_email( $col, $value ),
			'email_optional' => ( ! empty( $value ) ? $this->validate_email( $col, $value ) : null ),
			'positive_number' => $this->validate_positive_number( $col, $value ),
			'date' => $this->validate_date( $col, $value ),
			'enum' => $this->validate_enum( $col, $value, $mapping ),
			'boolean_string' => null, // Any value accepted, cast later.
			'url' => $this->validate_url( $col, $value ),
			'text' => null, // Text always passes.
			default => null,
		};
	}

	/**
	 * Validate email field.
	 *
	 * @param string $col   Column name.
	 * @param string $value The value.
	 * @return string|null Error or null.
	 */
	private function validate_email( string $col, string $value ): ?string {
		if ( ! is_email( $value ) ) {
			return sprintf(
				/* translators: %s: column name */
				__( 'Invalid email in %s', 'starter-shelter' ),
				$col
			);
		}
		return null;
	}

	/**
	 * Validate positive number field.
	 *
	 * @param string $col   Column name.
	 * @param string $value The value.
	 * @return string|null Error or null.
	 */
	private function validate_positive_number( string $col, string $value ): ?string {
		if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
			return sprintf(
				/* translators: %s: column name */
				__( 'Invalid amount in %s (must be a positive number)', 'starter-shelter' ),
				$col
			);
		}
		return null;
	}

	/**
	 * Validate date field.
	 *
	 * @param string $col   Column name.
	 * @param string $value The value.
	 * @return string|null Error or null.
	 */
	private function validate_date( string $col, string $value ): ?string {
		if ( ! strtotime( $value ) ) {
			return sprintf(
				/* translators: %s: column name */
				__( 'Invalid date in %s', 'starter-shelter' ),
				$col
			);
		}
		return null;
	}

	/**
	 * Validate URL field.
	 *
	 * @param string $col   Column name.
	 * @param string $value The value.
	 * @return string|null Error or null.
	 */
	private function validate_url( string $col, string $value ): ?string {
		if ( ! empty( $value ) && ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return sprintf(
				/* translators: %s: column name */
				__( 'Invalid URL in %s', 'starter-shelter' ),
				$col
			);
		}
		return null;
	}

	/**
	 * Validate against allowed enum values.
	 *
	 * Enum values can come from two sources:
	 * 1. enum_ref → resolves to entities.json (e.g. "sd_donation.allocation")
	 * 2. enum_values → inline array in import-export.json
	 *
	 * @since 2.0.0
	 *
	 * @param string $col     Column name.
	 * @param string $value   The value.
	 * @param array  $mapping The field_map entry.
	 * @return string|null Error or null.
	 */
	private function validate_enum( string $col, string $value, array $mapping ): ?string {
		$allowed = [];

		// Source 1: Reference to entities.json.
		if ( ! empty( $mapping['enum_ref'] ) ) {
			$ref_parts = explode( '.', $mapping['enum_ref'], 2 );
			if ( count( $ref_parts ) === 2 ) {
				$entity_key = $ref_parts[0];
				$field_name = $ref_parts[1];
				$allowed    = Config::get_path(
					'entities',
					"entities.{$entity_key}.fields.{$field_name}.enum",
					[]
				);
			}
		}

		// Source 2: Inline values in the import config.
		if ( empty( $allowed ) && ! empty( $mapping['enum_values'] ) ) {
			$allowed = $mapping['enum_values'];
		}

		// If no enum defined, skip validation.
		if ( empty( $allowed ) ) {
			return null;
		}

		if ( ! in_array( $value, $allowed, true ) ) {
			return sprintf(
				/* translators: 1: column name, 2: the invalid value, 3: comma-separated allowed values */
				__( 'Invalid %1$s: "%2$s" (allowed: %3$s)', 'starter-shelter' ),
				$col,
				$value,
				implode( ', ', $allowed )
			);
		}

		return null;
	}

	/**
	 * Get the import config for this entity type.
	 *
	 * Useful for consumers that need to read config details
	 * (e.g. AJAX handler building template downloads).
	 *
	 * @since 2.0.0
	 *
	 * @return array The import config.
	 */
	public function get_config(): array {
		return $this->import_config;
	}
}
