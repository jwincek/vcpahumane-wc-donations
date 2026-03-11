<?php
/**
 * Config loader with $ref resolution.
 *
 * @package Starter_Shelter
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Starter_Shelter\Core;

/**
 * Loads and caches configuration from JSON files with $ref resolution.
 *
 * @since 1.0.0
 */
class Config {

    /**
     * Cache of loaded configurations.
     *
     * @var array<string, array>
     */
    private static array $cache = [];

    /**
     * Path to config directory.
     *
     * @var string|null
     */
    private static ?string $config_path = null;

    /**
     * Initialize the config loader with path.
     *
     * @since 1.0.0
     *
     * @param string $path Path to config directory.
     */
    public static function init( string $path ): void {
        self::$config_path = trailingslashit( $path );
    }

    /**
     * Get a config file by name.
     *
     * @since 1.0.0
     *
     * @param string $name Config file name (without .json extension).
     * @return array The parsed config data.
     */
    public static function get( string $name ): array {
        if ( isset( self::$cache[ $name ] ) ) {
            return self::$cache[ $name ];
        }

        $file = self::$config_path . $name . '.json';
        if ( ! file_exists( $file ) ) {
            return [];
        }

        $contents = file_get_contents( $file );
        if ( false === $contents ) {
            return [];
        }

        $data = json_decode( $contents, true );
        if ( ! is_array( $data ) ) {
            return [];
        }

        // Resolve $ref references recursively.
        $data = self::resolve_refs( $data );

        self::$cache[ $name ] = $data;
        return $data;
    }

    /**
     * Get a specific item from a config file.
     *
     * @since 1.0.0
     *
     * @param string $name    Config file name.
     * @param string $key     Key to retrieve.
     * @param mixed  $default Default value if key not found.
     * @return mixed The config value or default.
     */
    public static function get_item( string $name, string $key, $default = null ) {
        $config = self::get( $name );
        return $config[ $key ] ?? $default;
    }

    /**
     * Get a nested item using dot notation.
     *
     * @since 1.0.0
     *
     * @param string $name    Config file name.
     * @param string $path    Dot-notation path (e.g., 'entities.sd_donation.fields').
     * @param mixed  $default Default value if path not found.
     * @return mixed The config value or default.
     */
    public static function get_path( string $name, string $path, $default = null ) {
        $config = self::get( $name );
        $keys = explode( '.', $path );

        foreach ( $keys as $key ) {
            if ( ! is_array( $config ) || ! isset( $config[ $key ] ) ) {
                return $default;
            }
            $config = $config[ $key ];
        }

        return $config;
    }

    /**
     * Resolve $ref references recursively.
     *
     * @since 1.0.0
     *
     * @param array $data Data to process.
     * @return array Data with $refs resolved.
     */
    private static function resolve_refs( array $data ): array {
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                // Check if this is a $ref reference.
                if ( isset( $value['$ref'] ) && 1 === count( $value ) ) {
                    $resolved = self::load_ref( $value['$ref'] );
                    if ( null !== $resolved ) {
                        $data[ $key ] = $resolved;
                    }
                } else {
                    // Recursively resolve nested refs.
                    $data[ $key ] = self::resolve_refs( $value );
                }
            }
        }

        return $data;
    }

    /**
     * Load a referenced schema file.
     *
     * @since 1.0.0
     *
     * @param string $ref Reference path (relative to config dir).
     * @return array|null The loaded schema or null on failure.
     */
    private static function load_ref( string $ref ): ?array {
        $file = self::$config_path . $ref;
        if ( ! file_exists( $file ) ) {
            return null;
        }

        $contents = file_get_contents( $file );
        if ( false === $contents ) {
            return null;
        }

        $data = json_decode( $contents, true );
        if ( ! is_array( $data ) ) {
            return null;
        }

        // Recursively resolve any nested refs in the loaded schema.
        return self::resolve_refs( $data );
    }

    /**
     * Clear the config cache.
     *
     * @since 1.0.0
     *
     * @param string|null $name Optional specific config to clear.
     */
    public static function clear_cache( ?string $name = null ): void {
        if ( null === $name ) {
            self::$cache = [];
        } else {
            unset( self::$cache[ $name ] );
        }
    }

    /**
     * Get the config path.
     *
     * @since 1.0.0
     *
     * @return string|null The config path.
     */
    public static function get_config_path(): ?string {
        return self::$config_path;
    }
}
