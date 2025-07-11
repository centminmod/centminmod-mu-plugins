<?php
/**
 * Plugin Name: CLI Dashboard Notice
 * Plugin URI: https://github.com/centminmod/centminmod-mu-plugins
 * Description: Manage multiple dashboard notices via WP-CLI with enhanced security:
 *   - Support for up to 10 concurrent notices with auto-ID assignment
 *   - Individual expiration, types, and dismissal for each notice
 *   - Backward compatible with existing single-notice installations
 *   - Server key authentication and atomic cache operations
 * Version: 2.3.0
 * Author: CLI
 * Author URI: https://centminmod.com
 * License: GPLv2 or later
 * Text Domain: cli-dashboard-notice
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin class for CLI Dashboard Notice
 */
class CLI_Dashboard_Notice {
    
    const OPTION_PREFIX = 'temp_cli_dashboard_notice';
    const INDEX_OPTION = 'temp_cli_dashboard_notice_next_id';
    const MAX_MESSAGE_LENGTH = 1000;
    const VALID_TYPES = [ 'info', 'success', 'warning', 'error' ];
    const DATE_BUFFER_SECONDS = 60;
    const MIN_TIME_DISPLAY_THRESHOLD = 300;
    const MAX_NOTICES = 10;
    
    // Server key authentication constants
    const SERVER_KEY_OPTION = 'cli_notice_server_key_hash';
    const SERVER_KEY_LENGTH = 64;
    const SERVER_KEY_ENV_VAR = 'CLI_NOTICE_SERVER_KEY';
    
    /**
     * Cached options to prevent multiple database calls
     */
    private static $cached_options = null;
    
    /**
     * Initialize the plugin
     */
    public static function init() {
        add_action( 'admin_notices', [ __CLASS__, 'display_admin_notice' ] );
        
        // For MU-plugins, the uninstall hook is not guaranteed to fire.
        // It only runs if the plugin file is manually deleted from the filesystem
        // AND an administrator subsequently visits the main Plugins page in the admin area.
        // Therefore, this is a fallback mechanism. The primary method for removing
        // the notice options from the database is the `wp notice delete` command.
        register_uninstall_hook( __FILE__, [ __CLASS__, 'cleanup_options' ] );
        
        // Add AJAX handler for notice dismissal
        add_action( 'wp_ajax_cli_notice_dismiss', [ __CLASS__, 'ajax_dismiss_notice' ] );
        
        // Enqueue admin script for dismissal functionality
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_scripts' ] );
    }
    
    /**
     * Generate a new server key for CLI authentication
     *
     * @return string The generated server key (plain text)
     */
    public static function generate_server_key() {
        $server_key = wp_generate_password( self::SERVER_KEY_LENGTH, false );
        $key_hash = hash( 'sha256', $server_key );
        
        // Store the hash in WordPress options
        update_option( self::SERVER_KEY_OPTION, $key_hash );
        
        // Log the generation event
        self::cli_audit_log( 'Server key generated', [
            'key_hash_prefix' => substr( $key_hash, 0, 8 ) . '...',
            'generation_time' => current_time( 'mysql' ),
            'user_id' => get_current_user_id() ?: 'cli-no-user'
        ] );
        
        return $server_key;
    }
    
    /**
     * Validate server key from environment variable
     *
     * @return bool True if valid server key provided
     */
    public static function validate_server_key() {
        $provided_key = getenv( self::SERVER_KEY_ENV_VAR );
        if ( empty( $provided_key ) ) {
            return false;
        }
        
        $stored_hash = get_option( self::SERVER_KEY_OPTION );
        if ( empty( $stored_hash ) ) {
            return false;
        }
        
        $provided_hash = hash( 'sha256', $provided_key );
        $is_valid = hash_equals( $stored_hash, $provided_hash );
        
        if ( $is_valid ) {
            self::cli_audit_log( 'Server key authentication successful', [
                'key_hash_prefix' => substr( $stored_hash, 0, 8 ) . '...',
                'auth_time' => current_time( 'mysql' )
            ] );
        } else {
            self::cli_audit_log( 'Server key authentication failed', [
                'provided_hash_prefix' => substr( $provided_hash, 0, 8 ) . '...',
                'auth_time' => current_time( 'mysql' )
            ] );
        }
        
        return $is_valid;
    }
    
    /**
     * Check if server key is configured
     *
     * @return bool True if server key is set up
     */
    public static function has_server_key() {
        return ! empty( get_option( self::SERVER_KEY_OPTION ) );
    }
    
    /**
     * Force cleanup all notice options (for MU-plugin removal) - Private method for security
     */
    private function cleanup_internal() {
        $permission_check = $this->check_permissions();
        if ( is_wp_error( $permission_check ) ) {
            return $permission_check;
        }
        
        self::cleanup_options();
        return true;
    }
    
    /**
     * Public wrapper for cleanup with enhanced security
     */
    public function cleanup() {
        $result = $this->cleanup_internal();
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }
        
        WP_CLI::success( __( 'All CLI notice options have been removed from the database.', 'cli-dashboard-notice' ) );
    }
    
    /**
     * Get all active notices
     *
     * @return array Array of notices with their IDs
     */
    public static function get_all_notices() {
        $notices = [];
        
        // Check for legacy single notice first with caching
        $legacy_notice = self::get_legacy_notice_cached();
        if ( $legacy_notice !== false ) {
            $notices[0] = $legacy_notice;
        }
        
        // Optimized batch loading of indexed notices
        $indexed_notices = self::get_indexed_notices_optimized();
        
        // Use + operator instead of array_merge to preserve keys
        $notices = $notices + $indexed_notices;
        
        return $notices;
    }
    
    /**
     * Get next available notice ID
     *
     * @return int Next available ID
     */
    public static function get_next_id() {
        // Check cache first for performance
        $cache_key = 'cli_dashboard_notices_next_id';
        $cached_next_id = wp_cache_get( $cache_key, 'cli_notices' );
        
        if ( false !== $cached_next_id ) {
            return $cached_next_id;
        }
        
        // Optimized gap detection using single database query
        global $wpdb;
        $option_prefix = self::OPTION_PREFIX;
        
        $used_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT CAST(SUBSTRING(option_name, %d) AS UNSIGNED) as notice_id 
                 FROM {$wpdb->options} 
                 WHERE option_name REGEXP %s 
                 ORDER BY notice_id",
                strlen( $option_prefix ) + 2,
                '^' . preg_quote( $option_prefix ) . '_[0-9]+$'
            )
        );
        
        // Find first gap or return next sequential ID
        for ( $i = 1; $i <= self::MAX_NOTICES; $i++ ) {
            if ( ! in_array( $i, $used_ids, true ) ) {
                // Cache the result for 1 minute
                wp_cache_set( $cache_key, $i, 'cli_notices', 60 );
                return $i;
            }
        }
        
        return false; // No available slots
    }
    
    /**
     * Optimized batch loading of indexed notices using single database query
     * Reduces 30 queries (3 per notice × 10 max notices) to 1 query
     *
     * @return array Associative array of notices indexed by ID
     */
    private static function get_indexed_notices_optimized() {
        // Check object cache first
        $cache_key = 'cli_dashboard_notices_indexed_v1';
        $cached_notices = wp_cache_get( $cache_key, 'cli_notices' );
        
        if ( false !== $cached_notices ) {
            return $cached_notices;
        }
        
        global $wpdb;
        $option_prefix = self::OPTION_PREFIX;
        
        // Single query to get all notice-related options
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value 
                 FROM {$wpdb->options} 
                 WHERE option_name LIKE %s 
                 AND option_name REGEXP %s
                 ORDER BY option_name",
                $option_prefix . '_%',
                '^' . preg_quote( $option_prefix ) . '_[0-9]+(_type|_expires)?$'
            ),
            ARRAY_A
        );
        
        // Parse results into structured notice array
        $notices = self::parse_indexed_notice_results( $results );
        
        // Cache for 5 minutes
        wp_cache_set( $cache_key, $notices, 'cli_notices', 300 );
        
        return $notices;
    }
    
    /**
     * Parse batch query results into notice structures
     *
     * @param array $results Database query results
     * @return array Parsed notices indexed by ID
     */
    private static function parse_indexed_notice_results( $results ) {
        $notices = [];
        $raw_data = [];
        
        // Group options by notice ID
        foreach ( $results as $row ) {
            $option_name = $row['option_name'];
            $option_value = $row['option_value'];
            
            // Extract notice ID and field type using regex
            if ( preg_match( '/^' . preg_quote( self::OPTION_PREFIX ) . '_(\d+)(?:_(.+))?$/', $option_name, $matches ) ) {
                $notice_id = (int) $matches[1];
                $field = isset( $matches[2] ) ? $matches[2] : 'message';
                
                $raw_data[$notice_id][$field] = $option_value;
            }
        }
        
        // Convert to standardized notice format
        foreach ( $raw_data as $id => $data ) {
            if ( ! empty( $data['message'] ) ) {
                $notices[$id] = [
                    'id' => $id,
                    'message' => $data['message'],
                    'type' => $data['type'] ?? 'warning',
                    'expires' => $data['expires'] ?? null
                ];
            }
        }
        
        return $notices;
    }
    
    /**
     * Get legacy single notice with caching
     *
     * @return array|false Legacy notice data or false if not found
     */
    private static function get_legacy_notice_cached() {
        $cache_key = 'cli_dashboard_notices_legacy';
        $cached_legacy = wp_cache_get( $cache_key, 'cli_notices' );
        
        // Check if we have a cached result (including negative results)
        if ( false !== $cached_legacy ) {
            // Return false if we cached that no notice exists
            if ( $cached_legacy === 'NO_NOTICE' ) {
                return false;
            }
            return $cached_legacy;
        }
        
        $legacy_message = get_option( self::OPTION_PREFIX );
        if ( $legacy_message === false ) {
            // Cache negative result with sentinel value to avoid repeated queries
            wp_cache_set( $cache_key, 'NO_NOTICE', 'cli_notices', 300 );
            return false;
        }
        
        $legacy_notice = [
            'id' => 0,
            'message' => $legacy_message,
            'type' => get_option( self::OPTION_PREFIX . '_type', 'warning' ),
            'expires' => get_option( self::OPTION_PREFIX . '_expires' )
        ];
        
        wp_cache_set( $cache_key, $legacy_notice, 'cli_notices', 300 );
        return $legacy_notice;
    }
    
    /**
     * Invalidate notice cache when notices are modified (atomic operation)
     */
    public static function invalidate_notice_cache() {
        return self::atomic_cache_operation( function() {
            wp_cache_delete( 'cli_dashboard_notices_indexed_v1', 'cli_notices' );
            wp_cache_delete( 'cli_dashboard_notices_legacy', 'cli_notices' );
            wp_cache_delete( 'cli_dashboard_notices_next_id', 'cli_notices' );
            
            // Clear any transients that might be caching notice data
            delete_transient( 'cli_notices_status' );
            
            // Clear object cache group if function exists (some cache systems support this)
            if ( function_exists( 'wp_cache_flush_group' ) ) {
                wp_cache_flush_group( 'cli_notices' );
            }
            
            // Clear internal static cache
            self::$cached_options = null;
            
            return true;
        } );
    }
    
    /**
     * Perform atomic cache operation with distributed locking
     *
     * @param callable $callback The operation to perform atomically
     * @param string $lock_id Optional lock identifier
     * @return mixed Result of callback or WP_Error on lock failure
     */
    public static function atomic_cache_operation( callable $callback, $lock_id = 'default' ) {
        $lock_key = "cli_notice_cache_lock_{$lock_id}";
        $lock_ttl = 5; // seconds
        $max_retries = 3;
        $retry_delay = 100000; // microseconds (0.1 seconds)
        
        for ( $retry = 0; $retry < $max_retries; $retry++ ) {
            // Try to acquire lock
            if ( wp_cache_add( $lock_key, time(), 'cli_notices_locks', $lock_ttl ) ) {
                try {
                    // Execute the callback within the lock
                    $result = call_user_func( $callback );
                    
                    // Log successful atomic operation
                    self::cli_audit_log( 'Atomic cache operation completed', [
                        'lock_id' => $lock_id,
                        'retry_attempt' => $retry + 1
                    ] );
                    
                    return $result;
                } catch ( Exception $e ) {
                    // Log error but still release lock
                    self::cli_audit_log( 'Atomic cache operation failed', [
                        'lock_id' => $lock_id,
                        'error' => $e->getMessage(),
                        'retry_attempt' => $retry + 1
                    ] );
                    throw $e;
                } finally {
                    // Always release the lock
                    wp_cache_delete( $lock_key, 'cli_notices_locks' );
                }
            }
            
            // Lock acquisition failed, wait before retry
            if ( $retry < $max_retries - 1 ) {
                usleep( $retry_delay * ( $retry + 1 ) ); // Exponential backoff
            }
        }
        
        // All retries failed
        self::cli_audit_log( 'Atomic cache operation lock acquisition failed', [
            'lock_id' => $lock_id,
            'max_retries' => $max_retries
        ] );
        
        return new WP_Error( 'cache_lock_failed', 
            sprintf( __( 'Cache operation in progress. Please retry in a few seconds.', 'cli-dashboard-notice' ) )
        );
    }
    
    /**
     * Update next ID counter
     *
     * @param int $used_id ID that was just used
     */
    public static function update_next_id( $used_id ) {
        $current_next = get_option( self::INDEX_OPTION, 1 );
        if ( $used_id >= $current_next ) {
            update_option( self::INDEX_OPTION, $used_id + 1 );
            // Invalidate next ID cache since available IDs have changed
            wp_cache_delete( 'cli_dashboard_notices_next_id', 'cli_notices' );
        }
    }
    
    /**
     * Get cached options to improve performance (legacy compatibility)
     */
    private static function get_cached_options() {
        if ( self::$cached_options === null ) {
            self::$cached_options = [
                'message' => get_option( self::OPTION_PREFIX ),
                'type' => get_option( self::OPTION_PREFIX . '_type', 'warning' ),
                'expires' => get_option( self::OPTION_PREFIX . '_expires' )
            ];
        }
        return self::$cached_options;
    }
    
    /**
     * Clear option cache
     */
    private static function clear_option_cache() {
        self::$cached_options = null;
    }
    
    /**
     * Enqueue admin scripts for notice dismissal
     */
    public static function enqueue_admin_scripts() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        $all_notices = self::get_all_notices();
        if ( empty( $all_notices ) ) {
            return;
        }
        
        // Create inline script for notice dismissal
        $script = "
        jQuery(document).ready(function($) {
            $('.cli-dashboard-notice').on('click', '.notice-dismiss', function(e) {
                var \$notice = $(this).closest('.cli-dashboard-notice');
                var nonce = \$notice.data('nonce');
                var noticeId = \$notice.data('notice-id');
                if (!nonce || noticeId === undefined) return;
                
                // Add loading state
                \$notice.css('opacity', '0.5');
                
                $.post(ajaxurl, {
                    action: 'cli_notice_dismiss',
                    nonce: nonce,
                    notice_id: noticeId
                })
                .done(function(response) {
                    if (response.success) {
                        // Hide notice immediately on success
                        \$notice.fadeOut(300, function() {
                            \$notice.remove();
                        });
                    } else {
                        // Restore opacity on error
                        \$notice.css('opacity', '1');
                        console.error('Notice dismissal failed:', response.data);
                    }
                })
                .fail(function() {
                    // Restore opacity on network error
                    \$notice.css('opacity', '1');
                    console.error('Notice dismissal failed: Network error');
                });
            });
        });";
        
        // 1. Register a new, unique script handle. We name it 'cli-notice-dismiss-js'.
        //    - The source URL is empty ('') because it's just a placeholder.
        //    - We explicitly declare its dependency on 'jquery' in the array.
        //    - The 'true' at the end tells WordPress to load it in the footer, which is a performance best practice.
        wp_register_script( 'cli-notice-dismiss-js', '', [ 'jquery' ], false, true );

        // 2. Tell WordPress to actually add our newly registered script to the page.
        wp_enqueue_script( 'cli-notice-dismiss-js' );

        // 3. Now, attach our inline JavaScript to our OWN handle, not the generic 'jquery' one.
        wp_add_inline_script( 'cli-notice-dismiss-js', $script );
    }
    
    /**
     * AJAX handler for notice dismissal with enhanced security
     */
    public static function ajax_dismiss_notice() {
        // Get notice ID from request
        $notice_id = intval( $_POST['notice_id'] ?? 0 );
        
        // Enhanced nonce verification with notice ID
        $nonce = sanitize_text_field( $_POST['nonce'] ?? '' );
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'cli_notice_dismiss_' . $notice_id ) ) {
            wp_send_json_error( __( 'Security check failed.', 'cli-dashboard-notice' ) );
            return;
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'cli-dashboard-notice' ) );
            return;
        }
        
        // Clean up specific notice
        self::cleanup_notice_by_id( $notice_id );
        
        wp_send_json_success();
    }
    
    /**
     * Display admin notices with enhanced security
     */
    public static function display_admin_notice() {
        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        $all_notices = self::get_all_notices();
        $expired_notices = [];
        
        foreach ( $all_notices as $notice_id => $notice ) {
            // Auto-expire logic with proper validation
            if ( $notice['expires'] ) {
                $expire_timestamp = self::validate_and_parse_date( $notice['expires'] );
                if ( $expire_timestamp === false || time() > $expire_timestamp ) {
                    $expired_notices[] = $notice_id;
                    continue;
                }
            }
            
            // Get and validate message
            $message = $notice['message'];
            if ( empty( $message ) ) {
                continue;
            }
            
            // Get and validate notice type
            $type = self::validate_notice_type( $notice['type'] );
            
            // Generate nonce for AJAX dismissal
            $nonce = wp_create_nonce( 'cli_notice_dismiss_' . $notice_id );
            
            // Output notice with proper escaping and nonce
            printf(
                '<div class="notice notice-%1$s is-dismissible cli-dashboard-notice" data-nonce="%4$s" data-notice-id="%5$s"><p>%2$s %3$s</p></div>',
                esc_attr( $type ),
                wp_kses( $message, self::get_allowed_html() ),
                $notice_id > 0 ? '<small>(ID: ' . esc_html( $notice_id ) . ')</small>' : '',
                esc_attr( $nonce ),
                esc_attr( $notice_id )
            );
        }
        
        // Clean up expired notices
        if ( ! empty( $expired_notices ) ) {
            foreach ( $expired_notices as $notice_id ) {
                self::cleanup_notice_by_id( $notice_id );
            }
        }
    }
    
    /**
     * Validate and parse expiration date with race condition protection
     *
     * @param string $date_string Date string to validate
     * @return int|false Timestamp or false on failure
     */
    private static function validate_and_parse_date( $date_string ) {
        if ( empty( $date_string ) ) {
            return false;
        }
        
        // Validate date format (YYYY-MM-DD HH:MM:SS)
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date_string ) ) {
            return false;
        }
        
        $timestamp = strtotime( $date_string );
        
        // Check if strtotime succeeded - add buffer to prevent race conditions
        if ( $timestamp === false || $timestamp <= ( time() + self::DATE_BUFFER_SECONDS ) ) {
            return false;
        }
        
        return $timestamp;
    }
    
    /**
     * Validate notice type
     *
     * @param string $type Notice type to validate
     * @return string Valid notice type
     */
    private static function validate_notice_type( $type ) {
        return in_array( $type, self::VALID_TYPES, true ) ? $type : 'warning';
    }
    
    /**
     * Get allowed HTML tags for notices with security hardening
     *
     * @return array Allowed HTML tags and attributes
     */
    private static function get_allowed_html() {
        return [
            'strong' => [],
            'em'     => [],
            'code'   => [],
            'br'     => [],
            'a'      => [
                'href'   => [],
                'title'  => [],
                'rel'    => []
            ]
        ];
    }
    
    /**
     * Clean up specific notice by ID (atomic operation)
     *
     * @param int $notice_id Notice ID to clean up (0 for legacy notice)
     */
    public static function cleanup_notice_by_id( $notice_id ) {
        // Perform deletion as atomic operation to prevent race conditions
        return self::atomic_cache_operation( function() use ( $notice_id ) {
            if ( $notice_id === 0 ) {
                // Legacy single notice
                $options = [
                    self::OPTION_PREFIX,
                    self::OPTION_PREFIX . '_type',
                    self::OPTION_PREFIX . '_expires',
                ];
            } else {
                // Indexed notice
                $options = [
                    self::OPTION_PREFIX . '_' . $notice_id,
                    self::OPTION_PREFIX . '_' . $notice_id . '_type',
                    self::OPTION_PREFIX . '_' . $notice_id . '_expires',
                ];
            }
            
            $deleted_count = 0;
            foreach ( $options as $option ) {
                if ( delete_option( $option ) ) {
                    $deleted_count++;
                }
            }
            
            // Optional logging - disabled by default, enable via filter
            if ( apply_filters( 'cli_dashboard_notice_enable_logging', false ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( sprintf( __( 'CLI Dashboard Notice: Notice ID %d cleaned up (deleted %d options)', 'cli-dashboard-notice' ), $notice_id, $deleted_count ) );
            }
            
            return $deleted_count;
        }, "cleanup_{$notice_id}" );
    }
    
    /**
     * Clean up all plugin options
     */
    public static function cleanup_options() {
        // Clean up legacy notice
        self::cleanup_notice_by_id( 0 );
        
        // Clean up all indexed notices
        for ( $i = 1; $i <= self::MAX_NOTICES; $i++ ) {
            if ( get_option( self::OPTION_PREFIX . '_' . $i ) !== false ) {
                self::cleanup_notice_by_id( $i );
            }
        }
        
        // Clean up index counter
        delete_option( self::INDEX_OPTION );
        
        // Optional logging - disabled by default, enable via filter
        if ( apply_filters( 'cli_dashboard_notice_enable_logging', false ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( __( 'CLI Dashboard Notice: All options cleaned up', 'cli-dashboard-notice' ) );
        }
    }
    
    /**
     * Validate message content with WordPress built-in functions
     *
     * @param string $message Message to validate
     * @return string|WP_Error Sanitized message or error
     */
    public static function validate_message( $message ) {
        if ( empty( $message ) ) {
            return new WP_Error( 'empty_message', __( 'Message cannot be empty.', 'cli-dashboard-notice' ) );
        }
        
        if ( strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
            return new WP_Error( 
                'message_too_long', 
                sprintf( __( 'Message exceeds maximum length of %d characters.', 'cli-dashboard-notice' ), self::MAX_MESSAGE_LENGTH )
            );
        }
        
        // Use WordPress built-in link processing instead of regex
        $sanitized = wp_kses( trim( $message ), self::get_allowed_html() );
        
        // Use WordPress built-in make_clickable for safe link processing
        $sanitized = make_clickable( $sanitized );
        
        // Add security attributes to external links using WordPress functions
        $sanitized = wp_rel_nofollow( $sanitized );
        
        return $sanitized;
    }
    
    /**
     * Validate and parse expiration date (public method for CLI)
     *
     * @param string $date_string Date string to validate
     * @return int|false Timestamp or false on failure
     */
    public static function validate_and_parse_date_public( $date_string ) {
        return self::validate_and_parse_date( $date_string );
    }
    
    /**
     * Get human time difference with minimum threshold
     *
     * @param int $from_timestamp From timestamp
     * @param int $to_timestamp To timestamp
     * @return string Human readable time difference
     */
    public static function get_human_time_diff( $from_timestamp, $to_timestamp ) {
        $diff = abs( $to_timestamp - $from_timestamp );
        
        // Don't show confusing short time periods
        if ( $diff < self::MIN_TIME_DISPLAY_THRESHOLD ) {
            return __( 'a few minutes', 'cli-dashboard-notice' );
        }
        
        return human_time_diff( $from_timestamp, $to_timestamp );
    }
    
    /**
     * CLI audit logging with enhanced context
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function cli_audit_log( $message, $context = [] ) {
        // CLI operations are always logged when in CLI context
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }
        
        // Prepare enhanced audit context
        $audit_data = [
            'timestamp' => current_time( 'mysql' ),
            'operation' => $message,
            'wp_user_id' => get_current_user_id() ?: 'cli-no-user',
            'system_user' => function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ? 
                ( posix_getpwuid( posix_geteuid() )['name'] ?? 'unknown' ) : 'unknown',
            'server_ip' => $_SERVER['SERVER_ADDR'] ?? 'unknown',
            'wp_cli_version' => defined( 'WP_CLI_VERSION' ) ? WP_CLI_VERSION : 'unknown',
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo( 'version' )
        ];
        
        // Add operation-specific context
        if ( ! empty( $context ) ) {
            $audit_data = array_merge( $audit_data, $context );
        }
        
        // Create log entry
        $log_entry = sprintf(
            'CLI Dashboard Notice: %s | Context: %s',
            $message,
            wp_json_encode( $audit_data )
        );
        
        // Log to multiple destinations
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( $log_entry );
        }
        
        // Log to custom file if specified
        $custom_log_file = defined( 'CLI_NOTICE_LOG_FILE' ) ? CLI_NOTICE_LOG_FILE : null;
        if ( $custom_log_file && is_writable( dirname( $custom_log_file ) ) ) {
            file_put_contents( $custom_log_file, date( 'Y-m-d H:i:s' ) . ' ' . $log_entry . PHP_EOL, FILE_APPEND | LOCK_EX );
        }
        
        // Allow custom logging via action hook
        do_action( 'cli_dashboard_notice_audit_log', $message, $audit_data );
    }
}

// Initialize the plugin
CLI_Dashboard_Notice::init();

/**
 * Manage multiple temporary admin notices with enhanced security and controlled CLI bypass.
 *
 * ## EXAMPLES
 *
 *     # Multiple notice management with auto-ID assignment
 *     wp notice add "🔔 Maintenance tonight" --type=warning --allow-cli    # Auto-assigns ID 1
 *     wp notice add "📊 New feature deployed" --type=success --allow-cli   # Auto-assigns ID 2
 *     wp notice add "⚠️ Security update" --id=5 --type=info --allow-cli    # Explicit ID
 *     
 *     # Update specific notices
 *     wp notice update "✅ Maintenance completed" --id=1 --type=success --allow-cli
 *     
 *     # Interactive delete (lists all notices)
 *     wp notice delete --allow-cli                    # Shows all notices with deletion instructions
 *     wp notice delete --id=1 --allow-cli            # Delete specific notice
 *     wp notice delete --all --allow-cli             # Delete all notices
 *     
 *     # Status and management
 *     wp notice status                                # Show all active notices (no flag needed)
 *     wp notice status --id=1                        # Show specific notice details
 *     wp notice security_status                      # Check security configuration
 *
 *     # Bulk operations with environment variable
 *     CLI_NOTICE_ENABLE=1 wp notice add "🔔 Bulk notice 1" --type=info
 *     CLI_NOTICE_ENABLE=1 wp notice add "🔔 Bulk notice 2" --type=warning
 *
 *     # Legacy single notice compatibility maintained
 *     # Existing single-notice behavior still works
 *
 * ## SECURITY
 *
 *     Web operations: STRICT - Always require manage_options capability
 *     CLI read operations: ALLOWED - Status checks work without flags  
 *     CLI write operations: CONTROLLED - Require --allow-cli flag or CLI_NOTICE_ENABLE=1
 *     All CLI operations: LOGGED - Comprehensive audit trail with notice IDs
 *     Multiple notices: Each notice has individual expiration and dismissal
 */
class CLI_Notice_Command {
    
    /**
     * Check if CLI bypass is enabled and log the attempt
     *
     * @param string $operation Operation being attempted
     * @param array $assoc_args WP-CLI associated arguments
     * @return bool|WP_Error True if bypass allowed, WP_Error otherwise
     */
    private function check_cli_bypass( $operation, $assoc_args = [] ) {
        // Always log CLI operation attempts
        CLI_Dashboard_Notice::cli_audit_log( "CLI operation attempted: {$operation}", [
            'bypass_method' => 'checking',
            'args' => array_keys( $assoc_args )
        ] );
        
        // Check if --allow-cli flag is present
        $allow_cli_flag = isset( $assoc_args['allow-cli'] );
        
        // Check if environment variable is set
        $env_enabled = getenv( 'CLI_NOTICE_ENABLE' ) === '1' || 
                      ( defined( 'CLI_NOTICE_ENABLE' ) && CLI_NOTICE_ENABLE );
        
        // Check if IP restrictions apply
        if ( $env_enabled || $allow_cli_flag ) {
            $ip_check = $this->check_ip_restrictions();
            if ( is_wp_error( $ip_check ) ) {
                CLI_Dashboard_Notice::cli_audit_log( "CLI operation blocked: IP restriction", [
                    'operation' => $operation,
                    'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ] );
                return $ip_check;
            }
        }
        
        // Check if time restrictions apply
        if ( $env_enabled || $allow_cli_flag ) {
            $time_check = $this->check_time_restrictions();
            if ( is_wp_error( $time_check ) ) {
                CLI_Dashboard_Notice::cli_audit_log( "CLI operation blocked: Time restriction", [
                    'operation' => $operation,
                    'current_time' => current_time( 'mysql' )
                ] );
                return $time_check;
            }
        }
        
        if ( $allow_cli_flag || $env_enabled ) {
            CLI_Dashboard_Notice::cli_audit_log( "CLI bypass granted for: {$operation}", [
                'bypass_method' => $allow_cli_flag ? 'flag' : 'environment',
                'flag_present' => $allow_cli_flag,
                'env_enabled' => $env_enabled,
                'security_implication' => 'Operation running without WordPress user permission check.',
            ] );
            return true;
        }
        
        return new WP_Error( 'cli_bypass_required', 
            sprintf( 
                __( 'CLI write operation "%s" requires --allow-cli flag or CLI_NOTICE_ENABLE=1 environment variable.', 'cli-dashboard-notice' ),
                $operation
            )
        );
    }
    
    /**
     * Check IP restrictions for CLI operations
     *
     * @return bool|WP_Error True if allowed, WP_Error if blocked
     */
    private function check_ip_restrictions() {
        // Skip IP check if no restrictions defined
        $allowed_ips_env = getenv( 'CLI_NOTICE_ALLOWED_IPS' );
        $allowed_ips_const = defined( 'CLI_NOTICE_ALLOWED_IPS' ) ? CLI_NOTICE_ALLOWED_IPS : null;
        
        if ( empty( $allowed_ips_env ) && empty( $allowed_ips_const ) ) {
            return true; // No restrictions
        }
        
        $allowed_ips = $allowed_ips_env ?: $allowed_ips_const;
        $allowed_ips_array = array_map( 'trim', explode( ',', $allowed_ips ) );
        
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // Allow localhost/CLI context
        $localhost_ips = [ '127.0.0.1', '::1', 'localhost', 'unknown' ];
        if ( in_array( $client_ip, $localhost_ips, true ) ) {
            return true;
        }
        
        if ( in_array( $client_ip, $allowed_ips_array, true ) ) {
            return true;
        }
        
        return new WP_Error( 'ip_blocked', 
            sprintf( __( 'CLI operations not allowed from IP: %s', 'cli-dashboard-notice' ), $client_ip )
        );
    }
    
    /**
     * Check time restrictions for CLI operations
     *
     * @return bool|WP_Error True if allowed, WP_Error if blocked
     */
    private function check_time_restrictions() {
        $business_hours_only = getenv( 'CLI_NOTICE_BUSINESS_HOURS_ONLY' ) === '1' || 
                              ( defined( 'CLI_NOTICE_BUSINESS_HOURS_ONLY' ) && CLI_NOTICE_BUSINESS_HOURS_ONLY );
        
        if ( ! $business_hours_only ) {
            return true; // No time restrictions
        }
        
        $current_hour = (int) current_time( 'H' );
        $start_hour = defined( 'CLI_NOTICE_BUSINESS_START' ) ? CLI_NOTICE_BUSINESS_START : 9;
        $end_hour = defined( 'CLI_NOTICE_BUSINESS_END' ) ? CLI_NOTICE_BUSINESS_END : 17;
        
        if ( $current_hour >= $start_hour && $current_hour <= $end_hour ) {
            return true;
        }
        
        return new WP_Error( 'outside_business_hours', 
            sprintf( 
                __( 'CLI operations only allowed during business hours (%d:00 - %d:00).', 'cli-dashboard-notice' ),
                $start_hour, $end_hour
            )
        );
    }
    
    /**
     * Check user permissions with tiered authentication system
     *
     * @param string $operation Operation being performed
     * @param array $assoc_args WP-CLI associated arguments
     * @return true|WP_Error True if authorized, WP_Error otherwise
     */
    private function check_permissions( $operation = '', $assoc_args = [] ) {
        // Ensure WordPress functions are available
        if ( ! function_exists( 'wp_get_current_user' ) ) {
            return new WP_Error( 'no_wp_functions', __( 'WordPress functions not available.', 'cli-dashboard-notice' ) );
        }
        
        // Handle CLI context with tiered authentication
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return $this->authenticate_cli_operation( $operation, $assoc_args );
        }
        
        // Web context - always strict
        $current_user = wp_get_current_user();
        if ( ! $current_user->exists() || ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'insufficient_permissions', 
                __( 'You do not have permission to manage notices. Ensure you are logged in as an administrator.', 'cli-dashboard-notice' ) 
            );
        }
        
        return true;
    }
    
    /**
     * Tiered authentication for CLI operations
     *
     * @param string $operation Operation being performed
     * @param array $assoc_args WP-CLI associated arguments
     * @return true|WP_Error True if authorized, WP_Error otherwise
     */
    private function authenticate_cli_operation( $operation, $assoc_args = [] ) {
        $current_user = wp_get_current_user();
        
        // Tier 1: WordPress user context (preferred method)
        if ( $current_user->exists() && current_user_can( 'manage_options' ) ) {
            CLI_Dashboard_Notice::cli_audit_log( "CLI operation with WordPress user context: {$operation}", [
                'auth_tier' => 'wordpress_user',
                'user_id' => $current_user->ID,
                'user_login' => $current_user->user_login
            ] );
            return true;
        }
        
        // Tier 2: Server key authentication (server admin use case)
        if ( CLI_Dashboard_Notice::validate_server_key() ) {
            // Check IP and time restrictions for server key auth
            $ip_check = $this->check_ip_restrictions();
            if ( is_wp_error( $ip_check ) ) {
                return $ip_check;
            }
            
            $time_check = $this->check_time_restrictions();
            if ( is_wp_error( $time_check ) ) {
                return $time_check;
            }
            
            CLI_Dashboard_Notice::cli_audit_log( "CLI operation with server key authentication: {$operation}", [
                'auth_tier' => 'server_key',
                'operation' => $operation
            ] );
            return true;
        }
        
        // Tier 3: Read operations with authentication bypass (limited scope)
        // Note: security_status removed from this list - now requires authentication
        if ( in_array( $operation, [ 'status', 'list' ], true ) ) {
            CLI_Dashboard_Notice::cli_audit_log( "CLI read operation allowed without authentication: {$operation}", [
                'auth_tier' => 'read_bypass',
                'operation' => $operation,
                'security_note' => 'Limited read-only operation in CLI context'
            ] );
            return true;
        }
        
        // Tier 4: Explicit bypass with enhanced audit (fallback compatibility)
        return $this->check_cli_bypass( $operation, $assoc_args );
    }
    
    /**
     * Handle WP_Error consistently across all methods
     *
     * @param WP_Error|mixed $result Result to check
     * @param string $success_message Optional success message
     */
    private function handle_result( $result, $success_message = '' ) {
        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
            return;
        }
        
        if ( ! empty( $success_message ) ) {
            WP_CLI::success( $success_message );
        }
    }
    
    
    /**
     * Add a new dashboard notice.
     *
     * @synopsis <message> [--type=<type>] [--expires=<expires>] [--id=<id>] [--allow-cli]
     */
    public function add( $args, $assoc ) {
        $permission_check = $this->check_permissions( 'add', $assoc );
        $this->handle_result( $permission_check );
        
        // Determine notice ID
        $notice_id = isset( $assoc['id'] ) ? intval( $assoc['id'] ) : null;
        
        if ( $notice_id === null ) {
            // Auto-assign next available ID
            $notice_id = CLI_Dashboard_Notice::get_next_id();
            if ( $notice_id === false ) {
                WP_CLI::error( sprintf( __( 'Maximum number of notices (%d) exceeded.', 'cli-dashboard-notice' ), CLI_Dashboard_Notice::MAX_NOTICES ) );
            }
        } else {
            // Validate explicit ID
            if ( $notice_id < 1 || $notice_id > CLI_Dashboard_Notice::MAX_NOTICES ) {
                WP_CLI::error( sprintf( __( 'Notice ID must be between 1 and %d.', 'cli-dashboard-notice' ), CLI_Dashboard_Notice::MAX_NOTICES ) );
            }
            
            // Check if ID already exists
            if ( get_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_' . $notice_id ) !== false ) {
                WP_CLI::error( sprintf( __( 'Notice with ID %d already exists. Use "update" to modify it or choose a different ID.', 'cli-dashboard-notice' ), $notice_id ) );
            }
        }
        
        // Validate and sanitize input
        $message = implode( ' ', $args );
        $validated_message = CLI_Dashboard_Notice::validate_message( $message );
        $this->handle_result( $validated_message );
        
        $type = $assoc['type'] ?? 'warning';
        if ( ! in_array( $type, CLI_Dashboard_Notice::VALID_TYPES, true ) ) {
            WP_CLI::error( sprintf( 
                __( 'Invalid notice type. Must be one of: %s', 'cli-dashboard-notice' ), 
                implode( ', ', CLI_Dashboard_Notice::VALID_TYPES )
            ) );
        }
        
        $expires = $assoc['expires'] ?? null;
        if ( $expires ) {
            $expire_timestamp = CLI_Dashboard_Notice::validate_and_parse_date_public( $expires );
            if ( $expire_timestamp === false ) {
                WP_CLI::error( __( 'Invalid expiration date format. Use: YYYY-MM-DD HH:MM:SS (must be in the future)', 'cli-dashboard-notice' ) );
            }
        }
        
        // Store the notice using indexed options
        $success = update_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_' . $notice_id, $validated_message );
        if ( ! $success ) {
            WP_CLI::error( __( 'Failed to save notice message.', 'cli-dashboard-notice' ) );
        }
        
        update_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_' . $notice_id . '_type', $type );
        
        if ( $expires ) {
            update_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_' . $notice_id . '_expires', $expires );
        }
        
        // Update next ID counter
        CLI_Dashboard_Notice::update_next_id( $notice_id );
        
        // Invalidate cache after successful database operations
        $cache_result = CLI_Dashboard_Notice::invalidate_notice_cache();
        if ( is_wp_error( $cache_result ) ) {
            WP_CLI::warning( sprintf( 
                __( 'Notice added but cache invalidation failed: %s', 'cli-dashboard-notice' ), 
                $cache_result->get_error_message() 
            ) );
        }
        
        WP_CLI::success( sprintf( __( 'Notice added successfully. ID: %d, Type: %s', 'cli-dashboard-notice' ), $notice_id, $type ) );
        
        // CLI audit logging
        CLI_Dashboard_Notice::cli_audit_log( 'Notice added', [
            'notice_id' => $notice_id,
            'type' => $type,
            'expires' => $expires ?: 'never',
            'message_length' => strlen( $message ),
            'user_id' => get_current_user_id()
        ] );
    }
    
    /**
     * Update an existing dashboard notice.
     *
     * @synopsis <message> [--type=<type>] [--expires=<expires>] [--clear-expiry] [--id=<id>] [--allow-cli]
     */
    public function update( $args, $assoc ) {
        $permission_check = $this->check_permissions( 'update', $assoc );
        $this->handle_result( $permission_check );
        
        // Determine notice ID to update
        $notice_id = isset( $assoc['id'] ) ? intval( $assoc['id'] ) : null;
        
        if ( $notice_id === null ) {
            // If no ID specified, try legacy single notice first, then show available notices
            if ( get_option( CLI_Dashboard_Notice::OPTION_PREFIX ) !== false ) {
                $notice_id = 0; // Legacy notice
            } else {
                // Show available notices
                $all_notices = CLI_Dashboard_Notice::get_all_notices();
                if ( empty( $all_notices ) ) {
                    WP_CLI::error( __( 'No notices found to update.', 'cli-dashboard-notice' ) );
                }
                
                WP_CLI::log( __( 'Multiple notices found. Specify which one to update:', 'cli-dashboard-notice' ) );
                foreach ( $all_notices as $id => $notice ) {
                    $preview = strlen( $notice['message'] ) > 50 ? substr( $notice['message'], 0, 50 ) . '...' : $notice['message'];
                    WP_CLI::log( sprintf( 'ID %d: [%s] %s', $id, $notice['type'], $preview ) );
                }
                WP_CLI::log( '' );
                WP_CLI::log( __( 'Usage: wp notice update "new message" --id=X --allow-cli', 'cli-dashboard-notice' ) );
                return;
            }
        }
        
        // Check if notice exists
        $option_key = $notice_id === 0 ? CLI_Dashboard_Notice::OPTION_PREFIX : CLI_Dashboard_Notice::OPTION_PREFIX . '_' . $notice_id;
        if ( get_option( $option_key ) === false ) {
            WP_CLI::error( sprintf( __( 'Notice with ID %d does not exist.', 'cli-dashboard-notice' ), $notice_id ) );
        }
        
        // Validate and sanitize input
        $message = implode( ' ', $args );
        $validated_message = CLI_Dashboard_Notice::validate_message( $message );
        $this->handle_result( $validated_message );
        
        $type_key = $notice_id === 0 ? CLI_Dashboard_Notice::OPTION_PREFIX . '_type' : CLI_Dashboard_Notice::OPTION_PREFIX . '_' . $notice_id . '_type';
        $type = $assoc['type'] ?? get_option( $type_key, 'warning' );
        if ( ! in_array( $type, CLI_Dashboard_Notice::VALID_TYPES, true ) ) {
            WP_CLI::error( sprintf( 
                __( 'Invalid notice type. Must be one of: %s', 'cli-dashboard-notice' ), 
                implode( ', ', CLI_Dashboard_Notice::VALID_TYPES )
            ) );
        }
        
        // Handle expiration
        $clear_expiry = isset( $assoc['clear-expiry'] );
        $expires = $assoc['expires'] ?? null;
        
        if ( $expires ) {
            $expire_timestamp = CLI_Dashboard_Notice::validate_and_parse_date_public( $expires );
            if ( $expire_timestamp === false ) {
                WP_CLI::error( __( 'Invalid expiration date format. Use: YYYY-MM-DD HH:MM:SS (must be in the future)', 'cli-dashboard-notice' ) );
            }
        }
        
        // Update the notice
        update_option( $option_key, $validated_message );
        update_option( $type_key, $type );
        
        $expires_key = $notice_id === 0 ? CLI_Dashboard_Notice::OPTION_PREFIX . '_expires' : CLI_Dashboard_Notice::OPTION_PREFIX . '_' . $notice_id . '_expires';
        if ( $expires ) {
            update_option( $expires_key, $expires );
        } elseif ( $clear_expiry ) {
            delete_option( $expires_key );
        }
        
        // Invalidate cache after successful database operations
        $cache_result = CLI_Dashboard_Notice::invalidate_notice_cache();
        if ( is_wp_error( $cache_result ) ) {
            WP_CLI::warning( sprintf( 
                __( 'Notice updated but cache invalidation failed: %s', 'cli-dashboard-notice' ), 
                $cache_result->get_error_message() 
            ) );
        }
        
        WP_CLI::success( sprintf( __( 'Notice updated successfully. ID: %s, Type: %s', 'cli-dashboard-notice' ), $notice_id === 0 ? 'legacy' : $notice_id, $type ) );
        
        // CLI audit logging
        CLI_Dashboard_Notice::cli_audit_log( 'Notice updated', [
            'notice_id' => $notice_id,
            'type' => $type,
            'expires' => $expires ?: ( $clear_expiry ? 'cleared' : 'unchanged' ),
            'message_length' => strlen( $message ),
            'user_id' => get_current_user_id()
        ] );
    }
    
    /**
     * Delete dashboard notice(s).
     *
     * @synopsis [--id=<id>] [--all] [--allow-cli]
     */
    public function delete( $args, $assoc ) {
        $permission_check = $this->check_permissions( 'delete', $assoc );
        $this->handle_result( $permission_check );
        
        $notice_id = isset( $assoc['id'] ) ? intval( $assoc['id'] ) : null;
        $delete_all = isset( $assoc['all'] );
        
        // If no flags provided, show interactive listing
        if ( $notice_id === null && ! $delete_all ) {
            $all_notices = CLI_Dashboard_Notice::get_all_notices();
            
            if ( empty( $all_notices ) ) {
                WP_CLI::success( __( 'No notices found to delete.', 'cli-dashboard-notice' ) );
                return;
            }
            
            WP_CLI::log( __( 'Available notices to delete:', 'cli-dashboard-notice' ) );
            WP_CLI::log( '' );
            
            foreach ( $all_notices as $id => $notice ) {
                $expires_info = '';
                if ( $notice['expires'] ) {
                    $expire_timestamp = CLI_Dashboard_Notice::validate_and_parse_date_public( $notice['expires'] );
                    if ( $expire_timestamp !== false ) {
                        $time_remaining = $expire_timestamp - time();
                        if ( $time_remaining > 0 ) {
                            $expires_info = sprintf( ' (expires: %s)', $notice['expires'] );
                        } else {
                            $expires_info = ' (EXPIRED)';
                        }
                    }
                } else {
                    $expires_info = ' (expires: never)';
                }
                
                $preview = strlen( $notice['message'] ) > 60 ? substr( $notice['message'], 0, 60 ) . '...' : $notice['message'];
                WP_CLI::log( sprintf( 'ID %s: [%s] %s%s', 
                    $id === 0 ? 'legacy' : $id, 
                    $notice['type'], 
                    $preview,
                    $expires_info
                ) );
            }
            
            WP_CLI::log( '' );
            WP_CLI::log( __( 'Usage:', 'cli-dashboard-notice' ) );
            WP_CLI::log( __( '  wp notice delete --id=X --allow-cli    # Delete specific notice', 'cli-dashboard-notice' ) );
            WP_CLI::log( __( '  wp notice delete --all --allow-cli     # Delete all notices', 'cli-dashboard-notice' ) );
            return;
        }
        
        $deleted_count = 0;
        
        if ( $delete_all ) {
            // Delete all notices
            $all_notices = CLI_Dashboard_Notice::get_all_notices();
            
            foreach ( $all_notices as $id => $notice ) {
                $cleanup_result = CLI_Dashboard_Notice::cleanup_notice_by_id( $id );
                if ( is_wp_error( $cleanup_result ) ) {
                    WP_CLI::error( sprintf( 
                        __( 'Failed to delete notice ID %s: %s', 'cli-dashboard-notice' ), 
                        $id === 0 ? 'legacy' : $id, 
                        $cleanup_result->get_error_message() 
                    ) );
                } else {
                    $deleted_count++;
                    WP_CLI::success( sprintf( __( 'Deleted notice ID %s.', 'cli-dashboard-notice' ), $id === 0 ? 'legacy' : $id ) );
                }
            }
            
            // Clean up index counter
            delete_option( CLI_Dashboard_Notice::INDEX_OPTION );
            
        } else {
            // Delete specific notice
            $option_key = $notice_id === 0 ? CLI_Dashboard_Notice::OPTION_PREFIX : CLI_Dashboard_Notice::OPTION_PREFIX . '_' . $notice_id;
            
            if ( get_option( $option_key ) === false ) {
                WP_CLI::error( sprintf( __( 'Notice with ID %d does not exist.', 'cli-dashboard-notice' ), $notice_id ) );
            }
            
            $cleanup_result = CLI_Dashboard_Notice::cleanup_notice_by_id( $notice_id );
            if ( is_wp_error( $cleanup_result ) ) {
                WP_CLI::error( sprintf( 
                    __( 'Failed to delete notice ID %s: %s', 'cli-dashboard-notice' ), 
                    $notice_id === 0 ? 'legacy' : $notice_id, 
                    $cleanup_result->get_error_message() 
                ) );
            } else {
                $deleted_count = 1;
                WP_CLI::success( sprintf( __( 'Deleted notice ID %s.', 'cli-dashboard-notice' ), $notice_id === 0 ? 'legacy' : $notice_id ) );
            }
        }
        
        if ( $deleted_count === 0 ) {
            WP_CLI::warning( __( 'No notice options found to delete.', 'cli-dashboard-notice' ) );
        } else {
            // Invalidate cache after successful deletion operations
            $cache_result = CLI_Dashboard_Notice::invalidate_notice_cache();
            if ( is_wp_error( $cache_result ) ) {
                WP_CLI::warning( sprintf( 
                    __( 'Notice deleted but cache invalidation failed: %s', 'cli-dashboard-notice' ), 
                    $cache_result->get_error_message() 
                ) );
            }
            
            WP_CLI::success( sprintf( __( 'Notice deletion complete. Removed %d notice(s).', 'cli-dashboard-notice' ), $deleted_count ) );
            
            // CLI audit logging
            CLI_Dashboard_Notice::cli_audit_log( 'Notice deleted', [
                'notice_id' => $delete_all ? 'all' : $notice_id,
                'deleted_count' => $deleted_count,
                'user_id' => get_current_user_id()
            ] );
        }
    }
    
    /**
     * Show current notice status and details.
     *
     * @synopsis [--id=<id>] [--allow-cli]
     */
    public function status( $args, $assoc ) {
        // Status is a read operation - always allowed in CLI
        $permission_check = $this->check_permissions( 'status', $assoc );
        $this->handle_result( $permission_check );
        
        $notice_id = isset( $assoc['id'] ) ? intval( $assoc['id'] ) : null;
        
        if ( $notice_id !== null ) {
            // Show specific notice
            $option_key = $notice_id === 0 ? CLI_Dashboard_Notice::OPTION_PREFIX : CLI_Dashboard_Notice::OPTION_PREFIX . '_' . $notice_id;
            $message = get_option( $option_key );
            
            if ( $message === false ) {
                WP_CLI::error( sprintf( __( 'Notice with ID %d not found.', 'cli-dashboard-notice' ), $notice_id ) );
            }
            
            $type_key = $notice_id === 0 ? CLI_Dashboard_Notice::OPTION_PREFIX . '_type' : CLI_Dashboard_Notice::OPTION_PREFIX . '_' . $notice_id . '_type';
            $expires_key = $notice_id === 0 ? CLI_Dashboard_Notice::OPTION_PREFIX . '_expires' : CLI_Dashboard_Notice::OPTION_PREFIX . '_' . $notice_id . '_expires';
            
            $type = get_option( $type_key, 'warning' );
            $expires = get_option( $expires_key );
            
            WP_CLI::log( sprintf( __( 'Notice ID %s Status:', 'cli-dashboard-notice' ), $notice_id === 0 ? 'legacy' : $notice_id ) );
            WP_CLI::log( sprintf( __( 'Message: %s', 'cli-dashboard-notice' ), $message ) );
            WP_CLI::log( sprintf( __( 'Type: %s', 'cli-dashboard-notice' ), $type ) );
            
            if ( $expires ) {
                $expire_timestamp = CLI_Dashboard_Notice::validate_and_parse_date_public( $expires );
                if ( $expire_timestamp !== false ) {
                    $time_remaining = $expire_timestamp - time();
                    if ( $time_remaining > 0 ) {
                        $human_time = CLI_Dashboard_Notice::get_human_time_diff( time(), $expire_timestamp );
                        WP_CLI::log( sprintf( __( 'Expires: %1$s (%2$s remaining)', 'cli-dashboard-notice' ), $expires, $human_time ) );
                    } else {
                        $human_time = CLI_Dashboard_Notice::get_human_time_diff( $expire_timestamp, time() );
                        WP_CLI::warning( sprintf( __( 'Expired: %1$s (%2$s ago)', 'cli-dashboard-notice' ), $expires, $human_time ) );
                    }
                } else {
                    WP_CLI::warning( sprintf( __( 'Invalid expiration date: %s', 'cli-dashboard-notice' ), $expires ) );
                }
            } else {
                WP_CLI::log( __( 'Expires: Never', 'cli-dashboard-notice' ) );
            }
            
        } else {
            // Show all notices
            $all_notices = CLI_Dashboard_Notice::get_all_notices();
            
            if ( empty( $all_notices ) ) {
                WP_CLI::success( __( 'No active notices.', 'cli-dashboard-notice' ) );
                return;
            }
            
            WP_CLI::log( sprintf( __( 'Active Notices (%d total):', 'cli-dashboard-notice' ), count( $all_notices ) ) );
            WP_CLI::log( '' );
            
            foreach ( $all_notices as $id => $notice ) {
                WP_CLI::log( sprintf( __( 'ID %s: [%s] %s', 'cli-dashboard-notice' ), 
                    $id === 0 ? 'legacy' : $id, 
                    $notice['type'], 
                    $notice['message'] 
                ) );
                
                if ( $notice['expires'] ) {
                    $expire_timestamp = CLI_Dashboard_Notice::validate_and_parse_date_public( $notice['expires'] );
                    if ( $expire_timestamp !== false ) {
                        $time_remaining = $expire_timestamp - time();
                        if ( $time_remaining > 0 ) {
                            $human_time = CLI_Dashboard_Notice::get_human_time_diff( time(), $expire_timestamp );
                            WP_CLI::log( sprintf( __( '  Expires: %1$s (%2$s remaining)', 'cli-dashboard-notice' ), $notice['expires'], $human_time ) );
                        } else {
                            $human_time = CLI_Dashboard_Notice::get_human_time_diff( $expire_timestamp, time() );
                            WP_CLI::warning( sprintf( __( '  Expired: %1$s (%2$s ago)', 'cli-dashboard-notice' ), $notice['expires'], $human_time ) );
                        }
                    } else {
                        WP_CLI::warning( sprintf( __( '  Invalid expiration date: %s', 'cli-dashboard-notice' ), $notice['expires'] ) );
                    }
                } else {
                    WP_CLI::log( __( '  Expires: Never', 'cli-dashboard-notice' ) );
                }
                
                WP_CLI::log( '' );
            }
        }
        
        // CLI audit logging for status checks
        CLI_Dashboard_Notice::cli_audit_log( 'Status checked', [
            'specific_id' => $notice_id,
            'total_notices' => $notice_id === null ? count( CLI_Dashboard_Notice::get_all_notices() ) : 1,
            'user_id' => get_current_user_id()
        ] );
    }
    
    /**
     * Show CLI bypass configuration and security status.
     */
    public function security_status() {
        // Require authentication for security status - no longer a bypass operation
        $permission_check = $this->check_permissions( 'security_status', [] );
        $this->handle_result( $permission_check );
        
        // Get sanitized security status
        $status = $this->get_sanitized_security_status();
        
        WP_CLI::log( __( 'CLI Dashboard Notice Security Configuration:', 'cli-dashboard-notice' ) );
        WP_CLI::log( '' );
        
        WP_CLI::log( sprintf( __( 'CLI Bypass Enabled: %s', 'cli-dashboard-notice' ), $status['cli_enabled'] ? 'Yes' : 'No' ) );
        WP_CLI::log( sprintf( __( 'Server Key Configured: %s', 'cli-dashboard-notice' ), $status['server_key_configured'] ? 'Yes' : 'No' ) );
        WP_CLI::log( sprintf( __( 'IP Restrictions Active: %s', 'cli-dashboard-notice' ), $status['ip_restrictions_active'] ? 'Yes' : 'No' ) );
        WP_CLI::log( sprintf( __( 'Business Hours Only: %s', 'cli-dashboard-notice' ), $status['business_hours_active'] ? 'Yes' : 'No' ) );
        WP_CLI::log( sprintf( __( 'Audit Logging Active: %s', 'cli-dashboard-notice' ), $status['audit_logging_active'] ? 'Yes' : 'No' ) );
        
        // Current authentication status
        WP_CLI::log( '' );
        WP_CLI::log( __( 'Current Authentication:', 'cli-dashboard-notice' ) );
        WP_CLI::log( sprintf( __( 'Authentication Tier: %s', 'cli-dashboard-notice' ), $status['current_auth_tier'] ) );
        
        if ( $status['has_wp_user'] ) {
            WP_CLI::log( sprintf( __( 'WordPress User: %s', 'cli-dashboard-notice' ), $status['wp_user_login'] ) );
            WP_CLI::log( sprintf( __( 'Can Manage Options: %s', 'cli-dashboard-notice' ), $status['can_manage_options'] ? 'Yes' : 'No' ) );
        } else {
            WP_CLI::log( __( 'WordPress User: No user context', 'cli-dashboard-notice' ) );
        }
        
        // Security recommendations
        WP_CLI::log( '' );
        WP_CLI::log( __( 'Security Recommendations:', 'cli-dashboard-notice' ) );
        
        if ( ! $status['cli_enabled'] ) {
            WP_CLI::log( __( '✅ CLI bypass disabled - Maximum security', 'cli-dashboard-notice' ) );
            WP_CLI::log( __( '   Use --allow-cli flag for write operations', 'cli-dashboard-notice' ) );
        } else {
            WP_CLI::warning( __( '⚠️ CLI bypass enabled - Review security settings', 'cli-dashboard-notice' ) );
            
            if ( ! $status['ip_restrictions_active'] ) {
                WP_CLI::warning( __( '⚠️ No IP restrictions - Consider adding IP whitelist', 'cli-dashboard-notice' ) );
            }
            
            if ( ! $status['business_hours_active'] ) {
                WP_CLI::log( __( '💡 Consider enabling business hours restrictions', 'cli-dashboard-notice' ) );
            }
        }
        
        if ( $status['server_key_configured'] ) {
            WP_CLI::log( __( '✅ Server key authentication available', 'cli-dashboard-notice' ) );
        } else {
            WP_CLI::log( __( '💡 Consider setting up server key authentication', 'cli-dashboard-notice' ) );
            WP_CLI::log( __( '   Run: wp notice generate-key --confirm', 'cli-dashboard-notice' ) );
        }
        
        CLI_Dashboard_Notice::cli_audit_log( 'Security status checked (authenticated)' );
    }
    
    /**
     * Get sanitized security status (no sensitive information)
     *
     * @return array Sanitized security configuration
     */
    private function get_sanitized_security_status() {
        // Check CLI bypass status
        $env_enabled = getenv( 'CLI_NOTICE_ENABLE' ) === '1';
        $const_enabled = defined( 'CLI_NOTICE_ENABLE' ) && CLI_NOTICE_ENABLE;
        $cli_enabled = $env_enabled || $const_enabled;
        
        // Check restrictions (but don't reveal actual values)
        $allowed_ips_env = getenv( 'CLI_NOTICE_ALLOWED_IPS' );
        $allowed_ips_const = defined( 'CLI_NOTICE_ALLOWED_IPS' ) ? CLI_NOTICE_ALLOWED_IPS : null;
        $ip_restrictions_active = ! empty( $allowed_ips_env ) || ! empty( $allowed_ips_const );
        
        $business_hours_env = getenv( 'CLI_NOTICE_BUSINESS_HOURS_ONLY' ) === '1';
        $business_hours_const = defined( 'CLI_NOTICE_BUSINESS_HOURS_ONLY' ) && CLI_NOTICE_BUSINESS_HOURS_ONLY;
        $business_hours_active = $business_hours_env || $business_hours_const;
        
        // Check logging (but don't reveal file paths)
        $debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
        $custom_log_enabled = defined( 'CLI_NOTICE_LOG_FILE' );
        $audit_logging_active = $debug_log_enabled || $custom_log_enabled;
        
        // Current user context
        $current_user = wp_get_current_user();
        $has_wp_user = $current_user->exists();
        $can_manage_options = $has_wp_user && current_user_can( 'manage_options' );
        
        // Determine current authentication tier
        $current_auth_tier = 'None';
        if ( $has_wp_user && $can_manage_options ) {
            $current_auth_tier = 'WordPress User';
        } elseif ( CLI_Dashboard_Notice::validate_server_key() ) {
            $current_auth_tier = 'Server Key';
        } elseif ( $cli_enabled ) {
            $current_auth_tier = 'CLI Bypass';
        }
        
        return [
            'cli_enabled' => $cli_enabled,
            'server_key_configured' => CLI_Dashboard_Notice::has_server_key(),
            'ip_restrictions_active' => $ip_restrictions_active,
            'business_hours_active' => $business_hours_active,
            'audit_logging_active' => $audit_logging_active,
            'has_wp_user' => $has_wp_user,
            'wp_user_login' => $has_wp_user ? $current_user->user_login : null,
            'can_manage_options' => $can_manage_options,
            'current_auth_tier' => $current_auth_tier
        ];
    }
    
    /**
     * Enable CLI bypass for current session (temporary).
     *
     * @synopsis [--ip-whitelist=<ips>] [--business-hours-only] [--confirm]
     */
    public function enable_cli( $args, $assoc ) {
        if ( ! isset( $assoc['confirm'] ) ) {
            WP_CLI::error( __( 'This command enables CLI bypass. Use --confirm flag to proceed.', 'cli-dashboard-notice' ) );
        }
        
        // Check current user has permission to modify security settings
        if ( ! current_user_can( 'manage_options' ) ) {
            WP_CLI::error( __( 'You must have manage_options capability to enable CLI bypass.', 'cli-dashboard-notice' ) );
        }
        
        $env_commands = [ 'export CLI_NOTICE_ENABLE=1' ];
        
        if ( isset( $assoc['ip-whitelist'] ) ) {
            $ips = sanitize_text_field( $assoc['ip-whitelist'] );
            $env_commands[] = sprintf( 'export CLI_NOTICE_ALLOWED_IPS="%s"', $ips );
        }
        
        if ( isset( $assoc['business-hours-only'] ) ) {
            $env_commands[] = 'export CLI_NOTICE_BUSINESS_HOURS_ONLY=1';
        }
        
        WP_CLI::log( __( 'To enable CLI bypass for this session, run:', 'cli-dashboard-notice' ) );
        WP_CLI::log( '' );
        foreach ( $env_commands as $cmd ) {
            WP_CLI::log( $cmd );
        }
        WP_CLI::log( '' );
        WP_CLI::log( __( 'Then use wp notice commands normally without --allow-cli flag.', 'cli-dashboard-notice' ) );
        
        WP_CLI::warning( __( 'Remember: This only affects the current shell session.', 'cli-dashboard-notice' ) );
        
        CLI_Dashboard_Notice::cli_audit_log( 'CLI bypass configuration displayed', [
            'ip_whitelist' => $assoc['ip-whitelist'] ?? 'none',
            'business_hours' => isset( $assoc['business-hours-only'] ),
            'user_id' => get_current_user_id()
        ] );
    }
    
    /**
     * Generate a new server key for CLI authentication.
     *
     * @synopsis [--confirm]
     */
    public function generate_key( $args, $assoc ) {
        if ( ! isset( $assoc['confirm'] ) ) {
            WP_CLI::error( __( 'This command generates a new server key. Use --confirm flag to proceed.', 'cli-dashboard-notice' ) );
        }
        
        // Check current user has permission to modify security settings
        if ( ! current_user_can( 'manage_options' ) ) {
            WP_CLI::error( __( 'You must have manage_options capability to generate server keys.', 'cli-dashboard-notice' ) );
        }
        
        // Check if server key already exists
        if ( CLI_Dashboard_Notice::has_server_key() ) {
            WP_CLI::warning( __( 'A server key already exists. This will replace the existing key.', 'cli-dashboard-notice' ) );
        }
        
        // Generate new server key
        $server_key = CLI_Dashboard_Notice::generate_server_key();
        
        WP_CLI::success( __( 'Server key generated successfully!', 'cli-dashboard-notice' ) );
        WP_CLI::log( '' );
        WP_CLI::log( __( 'To use the server key for CLI authentication, run:', 'cli-dashboard-notice' ) );
        WP_CLI::log( '' );
        WP_CLI::log( sprintf( 'export %s="%s"', CLI_Dashboard_Notice::SERVER_KEY_ENV_VAR, $server_key ) );
        WP_CLI::log( '' );
        WP_CLI::log( __( 'Then use wp notice commands normally without --allow-cli flag.', 'cli-dashboard-notice' ) );
        WP_CLI::log( '' );
        WP_CLI::warning( __( 'IMPORTANT: Save this key securely - it cannot be recovered!', 'cli-dashboard-notice' ) );
        
        // Write to temporary file for secure retrieval
        $temp_file = sys_get_temp_dir() . '/cli_notice_server_key_' . time() . '.txt';
        if ( file_put_contents( $temp_file, $server_key, LOCK_EX ) ) {
            chmod( $temp_file, 0600 );
            WP_CLI::log( sprintf( __( 'Key also saved to temporary file: %s', 'cli-dashboard-notice' ), $temp_file ) );
        }
    }
    
    /**
     * Check server key status and configuration.
     */
    public function key_status() {
        if ( CLI_Dashboard_Notice::has_server_key() ) {
            WP_CLI::success( __( 'Server key is configured.', 'cli-dashboard-notice' ) );
            
            // Test if current environment has valid key
            if ( CLI_Dashboard_Notice::validate_server_key() ) {
                WP_CLI::log( __( '✓ Current environment has valid server key', 'cli-dashboard-notice' ) );
            } else {
                WP_CLI::log( sprintf( 
                    __( '✗ No valid server key in environment variable %s', 'cli-dashboard-notice' ),
                    CLI_Dashboard_Notice::SERVER_KEY_ENV_VAR
                ) );
            }
        } else {
            WP_CLI::error( __( 'No server key is configured. Run "wp notice generate-key --confirm" to create one.', 'cli-dashboard-notice' ) );
        }
        
        CLI_Dashboard_Notice::cli_audit_log( 'Server key status checked' );
    }
    
    /**
     * Remove the server key (disables server key authentication).
     *
     * @synopsis [--confirm]
     */
    public function remove_key( $args, $assoc ) {
        if ( ! isset( $assoc['confirm'] ) ) {
            WP_CLI::error( __( 'This command removes the server key. Use --confirm flag to proceed.', 'cli-dashboard-notice' ) );
        }
        
        // Check current user has permission to modify security settings
        if ( ! current_user_can( 'manage_options' ) ) {
            WP_CLI::error( __( 'You must have manage_options capability to remove server keys.', 'cli-dashboard-notice' ) );
        }
        
        if ( ! CLI_Dashboard_Notice::has_server_key() ) {
            WP_CLI::error( __( 'No server key is currently configured.', 'cli-dashboard-notice' ) );
        }
        
        // Remove the server key
        delete_option( CLI_Dashboard_Notice::SERVER_KEY_OPTION );
        
        WP_CLI::success( __( 'Server key removed successfully.', 'cli-dashboard-notice' ) );
        WP_CLI::warning( __( 'CLI operations will now require --allow-cli flag or WordPress user context.', 'cli-dashboard-notice' ) );
        
        CLI_Dashboard_Notice::cli_audit_log( 'Server key removed', [
            'user_id' => get_current_user_id()
        ] );
    }
}

// Register WP-CLI command after class definition
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'notice', 'CLI_Notice_Command' );
}