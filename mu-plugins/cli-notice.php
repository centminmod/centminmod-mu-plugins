<?php
/**
 * Plugin Name: CLI Dashboard Notice (Secure)
 * Plugin URI: https://github.com/centminmod/centminmod-mu-plugins
 * Description: Show a notice stored in WP-CLI options with enhanced security:
 *   - temp_cli_dashboard_notice
 *   - temp_cli_dashboard_notice_type
 *   - temp_cli_dashboard_notice_expires
 * Version: 2.0.0
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
    const MAX_MESSAGE_LENGTH = 1000;
    const VALID_TYPES = [ 'info', 'success', 'warning', 'error' ];
    
    /**
     * Initialize the plugin
     */
    public static function init() {
        add_action( 'admin_notices', [ __CLASS__, 'display_admin_notice' ] );
        
        // Register cleanup on plugin deactivation
        register_deactivation_hook( __FILE__, [ __CLASS__, 'cleanup_options' ] );
    }
    
    /**
     * Display admin notice with enhanced security
     */
    public static function display_admin_notice() {
        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        // Auto-expire logic with proper validation
        $expires = get_option( self::OPTION_PREFIX . '_expires' );
        if ( $expires ) {
            $expire_timestamp = self::validate_and_parse_date( $expires );
            if ( $expire_timestamp === false || time() > $expire_timestamp ) {
                self::cleanup_options();
                return;
            }
        }
        
        // Get and validate message
        $message = get_option( self::OPTION_PREFIX );
        if ( empty( $message ) ) {
            return;
        }
        
        // Get and validate notice type
        $type = get_option( self::OPTION_PREFIX . '_type', 'warning' );
        $type = self::validate_notice_type( $type );
        
        // Generate nonce for AJAX dismissal (future enhancement)
        $nonce = wp_create_nonce( 'cli_notice_dismiss' );
        
        // Output notice with proper escaping
        printf(
            '<div class="notice notice-%1$s is-dismissible" data-nonce="%3$s"><p>%2$s</p></div>',
            esc_attr( $type ),
            wp_kses( $message, self::get_allowed_html() ),
            esc_attr( $nonce )
        );
    }
    
    /**
     * Validate and parse expiration date
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
        
        // Check if strtotime succeeded and date is in reasonable range
        if ( $timestamp === false || $timestamp < time() - YEAR_IN_SECONDS ) {
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
     * Get allowed HTML tags for notices
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
                'target' => [],
                'rel'    => []
            ]
        ];
    }
    
    /**
     * Clean up all plugin options
     */
    public static function cleanup_options() {
        $options = [
            self::OPTION_PREFIX,
            self::OPTION_PREFIX . '_type',
            self::OPTION_PREFIX . '_expires',
        ];
        
        foreach ( $options as $option ) {
            delete_option( $option );
        }
        
        // Log cleanup for debugging
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'CLI Dashboard Notice: Options cleaned up' );
        }
    }
    
    /**
     * Validate message content
     *
     * @param string $message Message to validate
     * @return string|WP_Error Sanitized message or error
     */
    public static function validate_message( $message ) {
        if ( empty( $message ) ) {
            return new WP_Error( 'empty_message', 'Message cannot be empty.' );
        }
        
        if ( strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
            return new WP_Error( 
                'message_too_long', 
                sprintf( 'Message exceeds maximum length of %d characters.', self::MAX_MESSAGE_LENGTH )
            );
        }
        
        // Sanitize the message
        return wp_kses( trim( $message ), self::get_allowed_html() );
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
}

// Initialize the plugin
CLI_Dashboard_Notice::init();

/**
 * Manage temporary admin notices with enhanced security.
 *
 * ## EXAMPLES
 *
 *     wp notice add "🔔 Hello world" --type=info --expires="2025-06-20 03:00:00"
 *     wp notice update "✅ Done"
 *     wp notice delete
 *     wp notice status
 */
class CLI_Notice_Command {
    
    /**
     * Check user permissions before executing commands
     *
     * @return bool|WP_Error True if authorized, WP_Error otherwise
     */
    private function check_permissions() {
        // For WP-CLI, we need to ensure WordPress user context
        if ( ! function_exists( 'wp_get_current_user' ) ) {
            return new WP_Error( 'no_user_context', 'No WordPress user context available.' );
        }
        
        // If no user is set, try to get user ID 1 (usually admin)
        $current_user = wp_get_current_user();
        if ( ! $current_user->exists() ) {
            // In CLI context, we'll allow if user has file system access
            // but log this for security auditing
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( 'CLI Notice: Command executed without WordPress user context' );
            }
            return true;
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'insufficient_permissions', 'You do not have permission to manage notices.' );
        }
        
        return true;
    }
    
    /**
     * Add a new dashboard notice.
     *
     * @synopsis <message> [--type=<type>] [--expires=<expires>]
     */
    public function add( $args, $assoc ) {
        $permission_check = $this->check_permissions();
        if ( is_wp_error( $permission_check ) ) {
            WP_CLI::error( $permission_check->get_error_message() );
        }
        
        // Validate and sanitize input
        $message = implode( ' ', $args );
        $validated_message = CLI_Dashboard_Notice::validate_message( $message );
        
        if ( is_wp_error( $validated_message ) ) {
            WP_CLI::error( $validated_message->get_error_message() );
        }
        
        $type = $assoc['type'] ?? 'warning';
        if ( ! in_array( $type, CLI_Dashboard_Notice::VALID_TYPES, true ) ) {
            WP_CLI::error( sprintf( 
                'Invalid notice type. Must be one of: %s', 
                implode( ', ', CLI_Dashboard_Notice::VALID_TYPES )
            ) );
        }
        
        $expires = $assoc['expires'] ?? null;
        if ( $expires ) {
            $expire_timestamp = CLI_Dashboard_Notice::validate_and_parse_date_public( $expires );
            if ( $expire_timestamp === false ) {
                WP_CLI::error( 'Invalid expiration date format. Use: YYYY-MM-DD HH:MM:SS' );
            }
            
            // Check if expiration is in the past
            if ( $expire_timestamp <= time() ) {
                WP_CLI::error( 'Expiration date cannot be in the past.' );
            }
        }
        
        // Check if notice already exists
        if ( get_option( CLI_Dashboard_Notice::OPTION_PREFIX ) !== false ) {
            WP_CLI::error( 'A notice already exists. Use "wp notice update" to modify it or "wp notice delete" to remove it first.' );
        }
        
        // Store the notice using WordPress functions directly
        $success = update_option( CLI_Dashboard_Notice::OPTION_PREFIX, $validated_message );
        if ( ! $success ) {
            WP_CLI::error( 'Failed to save notice message.' );
        }
        
        update_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_type', $type );
        
        if ( $expires ) {
            update_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_expires', $expires );
        }
        
        WP_CLI::success( sprintf( 'Notice added successfully. Type: %s', $type ) );
        
        // Log the action for security auditing
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( sprintf( 
                'CLI Notice: Added notice - Type: %s, Expires: %s', 
                $type, 
                $expires ?: 'never' 
            ) );
        }
    }
    
    /**
     * Update an existing dashboard notice.
     *
     * @synopsis <message> [--type=<type>] [--expires=<expires>] [--clear-expiry]
     */
    public function update( $args, $assoc ) {
        $permission_check = $this->check_permissions();
        if ( is_wp_error( $permission_check ) ) {
            WP_CLI::error( $permission_check->get_error_message() );
        }
        
        // Check if notice exists
        if ( get_option( CLI_Dashboard_Notice::OPTION_PREFIX ) === false ) {
            WP_CLI::error( 'No notice exists to update. Use "wp notice add" to create one.' );
        }
        
        // Validate and sanitize input
        $message = implode( ' ', $args );
        $validated_message = CLI_Dashboard_Notice::validate_message( $message );
        
        if ( is_wp_error( $validated_message ) ) {
            WP_CLI::error( $validated_message->get_error_message() );
        }
        
        $type = $assoc['type'] ?? get_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_type', 'warning' );
        if ( ! in_array( $type, CLI_Dashboard_Notice::VALID_TYPES, true ) ) {
            WP_CLI::error( sprintf( 
                'Invalid notice type. Must be one of: %s', 
                implode( ', ', CLI_Dashboard_Notice::VALID_TYPES )
            ) );
        }
        
        // Handle expiration
        $clear_expiry = isset( $assoc['clear-expiry'] );
        $expires = $assoc['expires'] ?? null;
        
        if ( $expires ) {
            $expire_timestamp = CLI_Dashboard_Notice::validate_and_parse_date_public( $expires );
            if ( $expire_timestamp === false ) {
                WP_CLI::error( 'Invalid expiration date format. Use: YYYY-MM-DD HH:MM:SS' );
            }
            
            if ( $expire_timestamp <= time() ) {
                WP_CLI::error( 'Expiration date cannot be in the past.' );
            }
        }
        
        // Update the notice
        update_option( CLI_Dashboard_Notice::OPTION_PREFIX, $validated_message );
        update_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_type', $type );
        
        if ( $expires ) {
            update_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_expires', $expires );
        } elseif ( $clear_expiry ) {
            delete_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_expires' );
        }
        
        WP_CLI::success( sprintf( 'Notice updated successfully. Type: %s', $type ) );
        
        // Log the action
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( sprintf( 
                'CLI Notice: Updated notice - Type: %s, Expires: %s', 
                $type, 
                $expires ?: ( $clear_expiry ? 'cleared' : 'unchanged' )
            ) );
        }
    }
    
    /**
     * Delete the dashboard notice.
     */
    public function delete() {
        $permission_check = $this->check_permissions();
        if ( is_wp_error( $permission_check ) ) {
            WP_CLI::error( $permission_check->get_error_message() );
        }
        
        $options = [
            CLI_Dashboard_Notice::OPTION_PREFIX,
            CLI_Dashboard_Notice::OPTION_PREFIX . '_type',
            CLI_Dashboard_Notice::OPTION_PREFIX . '_expires',
        ];
        
        $deleted_count = 0;
        foreach ( $options as $option ) {
            if ( get_option( $option ) !== false ) {
                if ( delete_option( $option ) ) {
                    $deleted_count++;
                    WP_CLI::success( sprintf( "Deleted '%s' option.", $option ) );
                } else {
                    WP_CLI::warning( sprintf( "Failed to delete '%s' option.", $option ) );
                }
            }
        }
        
        if ( $deleted_count === 0 ) {
            WP_CLI::warning( 'No notice options found to delete.' );
        } else {
            WP_CLI::success( 'Notice removal complete.' );
            
            // Log the action
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( sprintf( 'CLI Notice: Deleted notice - %d options removed', $deleted_count ) );
            }
        }
    }
    
    /**
     * Show current notice status and details.
     */
    public function status() {
        $permission_check = $this->check_permissions();
        if ( is_wp_error( $permission_check ) ) {
            WP_CLI::error( $permission_check->get_error_message() );
        }
        
        $message = get_option( CLI_Dashboard_Notice::OPTION_PREFIX );
        
        if ( $message === false ) {
            WP_CLI::success( 'No active notice.' );
            return;
        }
        
        $type = get_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_type', 'warning' );
        $expires = get_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_expires' );
        
        WP_CLI::log( 'Current Notice Status:' );
        WP_CLI::log( sprintf( 'Message: %s', $message ) );
        WP_CLI::log( sprintf( 'Type: %s', $type ) );
        
        if ( $expires ) {
            $expire_timestamp = CLI_Dashboard_Notice::validate_and_parse_date_public( $expires );
            if ( $expire_timestamp !== false ) {
                $time_remaining = $expire_timestamp - time();
                if ( $time_remaining > 0 ) {
                    WP_CLI::log( sprintf( 'Expires: %s (%s remaining)', $expires, human_time_diff( time(), $expire_timestamp ) ) );
                } else {
                    WP_CLI::warning( sprintf( 'Expired: %s (%s ago)', $expires, human_time_diff( $expire_timestamp, time() ) ) );
                }
            } else {
                WP_CLI::warning( sprintf( 'Invalid expiration date: %s', $expires ) );
            }
        } else {
            WP_CLI::log( 'Expires: Never' );
        }
    }
}

// Register WP-CLI command after class definition
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'notice', 'CLI_Notice_Command' );
}