<?php
/**
* Plugin Name: CLI Dashboard Notice
* Plugin URI: https://github.com/centminmod/centminmod-mu-plugins
* Description: Show a notice stored in WP-CLI options:
*   - temp_cli_dashboard_notice
*   - temp_cli_dashboard_notice_type
*   - temp_cli_dashboard_notice_expires
* Version: 1.2.0
* Author: CLI
* Author URI: https://centminmod.com
* License: GPLv2 or later
* Text Domain: cli-dashboard-notice
*/

add_action( 'admin_notices', function() {
   if ( ! current_user_can( 'manage_options' ) ) {
       return;
   }

   // Auto-expire logic
   $expires = get_option( 'temp_cli_dashboard_notice_expires' );
   if ( $expires && time() > strtotime( $expires ) ) {
       delete_option( 'temp_cli_dashboard_notice' );
       delete_option( 'temp_cli_dashboard_notice_type' );
       delete_option( 'temp_cli_dashboard_notice_expires' );
       return;
   }

   $msg  = get_option( 'temp_cli_dashboard_notice' );
   if ( ! $msg ) {
       return;
   }
   $type = get_option( 'temp_cli_dashboard_notice_type', 'warning' );
   if ( ! in_array( $type, [ 'info', 'success', 'warning', 'error' ], true ) ) {
       $type = 'warning';
   }

   printf(
       '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
       esc_attr( $type ),
       wp_kses_post( $msg )
   );
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
   /**
    * Manage temporary admin notices.
    *
    * ## EXAMPLES
    *
    *     wp notice add "🔔 Hello world" --type=info --expires="2025-06-20 03:00:00"
    *     wp notice update "✅ Done"
    *     wp notice delete
    */
   class CLI_Notice_Command {
       /**
        * Add a new dashboard notice.
        *
        * @synopsis <message> [--type=<type>] [--expires=<expires>]
        */
       public function add( $args, $assoc ) {
           $msg     = implode( ' ', $args );
           $type    = $assoc['type']    ?? 'warning';
           $expires = $assoc['expires'] ?? null;

           \WP_CLI::runcommand(
               'option add temp_cli_dashboard_notice ' . escapeshellarg( $msg ),
               [ 'exit_error' => false ]
           );
           \WP_CLI::runcommand(
               'option update temp_cli_dashboard_notice_type ' . escapeshellarg( $type ),
               [ 'exit_error' => false ]
           );
           if ( $expires ) {
               \WP_CLI::runcommand(
                   'option update temp_cli_dashboard_notice_expires ' . escapeshellarg( $expires ),
                   [ 'exit_error' => false ]
               );
           }
           \WP_CLI::success( "Notice added." );
       }

       /**
        * Update an existing dashboard notice.
        *
        * @synopsis <message> [--type=<type>] [--expires=<expires>]
        */
       public function update( $args, $assoc ) {
           $msg     = implode( ' ', $args );
           $type    = $assoc['type']    ?? 'warning';
           $expires = $assoc['expires'] ?? null;

           \WP_CLI::runcommand(
               'option update temp_cli_dashboard_notice ' . escapeshellarg( $msg ),
               [ 'exit_error' => false ]
           );
           \WP_CLI::runcommand(
               'option update temp_cli_dashboard_notice_type ' . escapeshellarg( $type ),
               [ 'exit_error' => false ]
           );
           if ( $expires ) {
               \WP_CLI::runcommand(
                   'option update temp_cli_dashboard_notice_expires ' . escapeshellarg( $expires ),
                   [ 'exit_error' => false ]
               );
           } else {
               // Clear expiration if not specified
               \WP_CLI::runcommand(
                   'option delete temp_cli_dashboard_notice_expires',
                   [ 'exit_error' => false ]
               );
           }
           \WP_CLI::success( "Notice updated." );
       }

       /**
        * Delete the dashboard notice.
        */
       public function delete() {
           $options = [
               'temp_cli_dashboard_notice',
               'temp_cli_dashboard_notice_type',
               'temp_cli_dashboard_notice_expires',
           ];

           foreach ( $options as $opt ) {
               if ( get_option( $opt ) !== false ) {
                   \WP_CLI::runcommand(
                       "option delete $opt",
                       [ 'exit_error' => false ]
                   );
                   \WP_CLI::success( "Deleted '{$opt}' option." );
               }
           }

           \WP_CLI::success( "Notice removal complete." );
       }
   }

   WP_CLI::add_command( 'notice', 'CLI_Notice_Command' );
}