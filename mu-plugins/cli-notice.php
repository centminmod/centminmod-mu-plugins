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
        
        // MU-plugins don't fire deactivation hooks, so we use uninstall hook instead
        register_uninstall_hook( __FILE__, [ __CLASS__, 'cleanup_options' ] );
        
        // Add AJAX handler for notice dismissal
        add_action( 'wp_ajax_cli_notice_dismiss', [ __CLASS__, 'ajax_dismiss_notice' ] );
        
        // Enqueue admin script for dismissal functionality
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_scripts' ] );
    }
    
    /**
     * Force cleanup all notice options (for MU-plugin removal).
     */
    public function cleanup() {
        $permission_check = $this->check_permissions();
        if ( is_wp_error( $permission_check ) ) {
            WP_CLI::error( $permission_check->get_error_message() );
        }
        
        CLI_Dashboard_Notice::cleanup_options();
        WP_CLI::success( __( 'All CLI notice options have been removed from the database.', 'cli-dashboard-notice' ) );
    }
    
    /**
     * Enqueue admin scripts for notice dismissal
     */
    public static function enqueue_admin_scripts() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        // Only enqueue if we have an active notice
        if ( get_option( self::OPTION_PREFIX ) === false ) {
            return;
        }
        
        // Create inline script for notice dismissal
        $script = "
        jQuery(document).ready(function($) {
            $('.cli-dashboard-notice').on('click', '.notice-dismiss', function(e) {
                var nonce = $(this).closest('.cli-dashboard-notice').data('nonce');
                $.post(ajaxurl, {
                    action: 'cli_notice_dismiss',
                    nonce: nonce
                });
            });
        });";
        
        wp_add_inline_script( 'jquery', $script );
    }
    
    /**
     * AJAX handler for notice dismissal
     */
    public static function ajax_dismiss_notice() {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'cli_notice_dismiss' ) ) {
            wp_die( __( 'Security check failed.', 'cli-dashboard-notice' ) );
        }
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions.', 'cli-dashboard-notice' ) );
        }
        
        // Clean up notice
        self::cleanup_options();
        
        wp_send_json_success();
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
        
        // Output notice with proper escaping and nonce
        printf(
            '<div class="notice notice-%1$s is-dismissible cli-dashboard-notice" data-nonce="%3$s"><p>%2$s</p></div>',
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
        
        // Check if strtotime succeeded and reject any past dates
        if ( $timestamp === false || $timestamp <= time() ) {
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
                'rel'    => []  // Removed target to prevent phishing
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
            error_log( __( 'CLI Dashboard Notice: Options cleaned up', 'cli-dashboard-notice' ) );
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
            return new WP_Error( 'empty_message', __( 'Message cannot be empty.', 'cli-dashboard-notice' ) );
        }
        
        if ( strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
            return new WP_Error( 
                'message_too_long', 
                sprintf( __( 'Message exceeds maximum length of %d characters.', 'cli-dashboard-notice' ), self::MAX_MESSAGE_LENGTH )
            );
        }
        
        // Sanitize the message and ensure safe links
        $sanitized = wp_kses( trim( $message ), self::get_allowed_html() );
        
        // Post-process to add security attributes to links
        $sanitized = preg_replace_callback(
            '/<a\s+([^>]*?)href\s*=\s*["\']([^"\']*)["\']([^>]*?)>/i',
            function( $matches ) {
                $before = $matches[1];
                $href = $matches[2];
                $after = $matches[3];
                
                // Add noopener noreferrer for external links
                if ( strpos( $href, home_url() ) !== 0 && filter_var( $href, FILTER_VALIDATE_URL ) ) {
                    $rel_attr = 'rel="noopener noreferrer"';
                    if ( strpos( $after, 'rel=' ) === false && strpos( $before, 'rel=' ) === false ) {
                        $after = ' ' . $rel_attr . $after;
                    }
                }
                
                return '<a ' . $before . 'href="' . esc_url( $href ) . '"' . $after . '>';
            },
            $sanitized
        );
        
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
}

// Initialize the plugin
CLI_Dashboard_Notice::init();

/**
 * Manage temporary admin notices with enhanced security.
 *
 * ## EXAMPLES
 *
 *     # Basic usage (works with file system access)
 *     wp notice add "🔔 Hello world" --type=info --expires="2025-06-20 03:00:00"
 *     wp notice update "✅ Done"
 *     wp notice delete
 *     wp notice status
 *     wp notice cleanup
 *
 *     # With specific user context (recommended for shared servers)
 *     wp notice add "🔔 Hello world" --user=admin
 *
 * ## SECURITY
 *
 *     Commands work with file system access but are logged for security auditing.
 *     Use --user=<username> for explicit WordPress user context on shared servers.
 */
class CLI_Notice_Command {
    
    /**
     * Check user permissions before executing commands (practical security)
     *
     * @return bool|WP_Error True if authorized, WP_Error otherwise
     */
    private function check_permissions() {
        // Ensure WordPress functions are available
        if ( ! function_exists( 'wp_get_current_user' ) ) {
            return new WP_Error( 'no_user_context', __( 'No WordPress user context available.', 'cli-dashboard-notice' ) );
        }
        
        $current_user = wp_get_current_user();
        
        // If user context exists, check capabilities
        if ( $current_user->exists() ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return new WP_Error( 'insufficient_permissions', __( 'You do not have permission to manage notices.', 'cli-dashboard-notice' ) );
            }
        } else {
            // In CLI context without user, allow but log for security auditing
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( __( 'CLI Notice: Command executed via file system access (no WordPress user context)', 'cli-dashboard-notice' ) );
            }
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
                __( 'Invalid notice type. Must be one of: %s', 'cli-dashboard-notice' ), 
                implode( ', ', CLI_Dashboard_Notice::VALID_TYPES )
            ) );
        }
        
        $expires = $assoc['expires'] ?? null;
        if ( $expires ) {
            $expire_timestamp = CLI_Dashboard_Notice::validate_and_parse_date_public( $expires );
            if ( $expire_timestamp === false ) {
                WP_CLI::error( __( 'Invalid expiration date format. Use: YYYY-MM-DD HH:MM:SS', 'cli-dashboard-notice' ) );
            }
            
            // Check if expiration is in the past
            if ( $expire_timestamp <= time() ) {
                WP_CLI::error( __( 'Expiration date cannot be in the past.', 'cli-dashboard-notice' ) );
            }
        }
        
        // Check if notice already exists
        if ( get_option( CLI_Dashboard_Notice::OPTION_PREFIX ) !== false ) {
            WP_CLI::error( __( 'A notice already exists. Use "wp notice update" to modify it or "wp notice delete" to remove it first.', 'cli-dashboard-notice' ) );
        }
        
        // Store the notice using WordPress functions directly
        $success = update_option( CLI_Dashboard_Notice::OPTION_PREFIX, $validated_message );
        if ( ! $success ) {
            WP_CLI::error( __( 'Failed to save notice message.', 'cli-dashboard-notice' ) );
        }
        
        update_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_type', $type );
        
        if ( $expires ) {
            update_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_expires', $expires );
        }
        
        WP_CLI::success( sprintf( __( 'Notice added successfully. Type: %s', 'cli-dashboard-notice' ), $type ) );
        
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
            WP_CLI::error( __( 'No notice exists to update. Use "wp notice add" to create one.', 'cli-dashboard-notice' ) );
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
                WP_CLI::error( __( 'Invalid expiration date format. Use: YYYY-MM-DD HH:MM:SS', 'cli-dashboard-notice' ) );
            }
            
            if ( $expire_timestamp <= time() ) {
                WP_CLI::error( __( 'Expiration date cannot be in the past.', 'cli-dashboard-notice' ) );
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
        
        WP_CLI::success( sprintf( __( 'Notice updated successfully. Type: %s', 'cli-dashboard-notice' ), $type ) );
        
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
                    WP_CLI::success( sprintf( __( "Deleted '%s' option.", 'cli-dashboard-notice' ), $option ) );
                } else {
                    WP_CLI::warning( sprintf( __( "Failed to delete '%s' option.", 'cli-dashboard-notice' ), $option ) );
                }
            }
        }
        
        if ( $deleted_count === 0 ) {
            WP_CLI::warning( __( 'No notice options found to delete.', 'cli-dashboard-notice' ) );
        } else {
            WP_CLI::success( __( 'Notice removal complete.', 'cli-dashboard-notice' ) );
            
            // Log the action
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( sprintf( __( 'CLI Notice: Deleted notice - %d options removed', 'cli-dashboard-notice' ), $deleted_count ) );
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
            WP_CLI::success( __( 'No active notice.', 'cli-dashboard-notice' ) );
            return;
        }
        
        $type = get_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_type', 'warning' );
        $expires = get_option( CLI_Dashboard_Notice::OPTION_PREFIX . '_expires' );
        
        WP_CLI::log( __( 'Current Notice Status:', 'cli-dashboard-notice' ) );
        WP_CLI::log( sprintf( __( 'Message: %s', 'cli-dashboard-notice' ), $message ) );
        WP_CLI::log( sprintf( __( 'Type: %s', 'cli-dashboard-notice' ), $type ) );
        
        if ( $expires ) {
            $expire_timestamp = CLI_Dashboard_Notice::validate_and_parse_date_public( $expires );
            if ( $expire_timestamp !== false ) {
                $time_remaining = $expire_timestamp - time();
                if ( $time_remaining > 0 ) {
                    WP_CLI::log( sprintf( __( 'Expires: %1$s (%2$s remaining)', 'cli-dashboard-notice' ), $expires, human_time_diff( time(), $expire_timestamp ) ) );
                } else {
                    WP_CLI::warning( sprintf( __( 'Expired: %1$s (%2$s ago)', 'cli-dashboard-notice' ), $expires, human_time_diff( $expire_timestamp, time() ) ) );
                }
            } else {
                WP_CLI::warning( sprintf( __( 'Invalid expiration date: %s', 'cli-dashboard-notice' ), $expires ) );
            }
        } else {
            WP_CLI::log( __( 'Expires: Never', 'cli-dashboard-notice' ) );
        }
    }
}

// Register WP-CLI command after class definition
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'notice', 'CLI_Notice_Command' );
}