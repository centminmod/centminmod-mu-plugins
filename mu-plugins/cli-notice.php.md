# CLI Dashboard Notice README (v2.0.0)

This document provides instructions for installing, configuring, and using the **CLI Dashboard Notice** Must-Use plugin for WordPress. This tool enables you to add, update, and delete temporary admin dashboard notices entirely via WP‑CLI commands, with enhanced security and validation.

![screenshot](screenshots/wp-plugin-cli-notice.png)

---

## Table of Contents

* [Prerequisites](#prerequisites)
* [Installation](#installation)
* [Security Features](#security-features)
* [Usage](#usage)
  * [`wp notice add`](#wp-notice-add)
  * [`wp notice update`](#wp-notice-update)
  * [`wp notice delete`](#wp-notice-delete)
  * [`wp notice status`](#wp-notice-status)
* [Examples](#examples)
* [Security Considerations](#security-considerations)
* [Troubleshooting](#troubleshooting)
* [Advanced Features](#advanced-features)
* [Migration from v1.x](#migration-from-v1x)

---

## Prerequisites

* A WordPress installation running **6.0** or later.
* WP‑CLI installed and available in your server's `$PATH`.
* File system access to your WordPress root (SSH or similar).
* PHP 7.4 or later (recommended: PHP 8.0+).
* User with `manage_options` capability for WordPress admin context.
* (Optional) SELinux on AlmaLinux: ability to run `restorecon`.

---

## Installation

1. **SSH into your server** and navigate to the WordPress root directory:

   ```bash
   cd /home/nginx/domains/yourdomain.com/public   # adjust to your WordPress root
   ```

2. **Create the `mu-plugins` directory** (if it doesn't exist):

   ```bash
   mkdir -p wp-content/mu-plugins
   ```

3. **Install the MU-plugin** by creating `cli-notice.php`:

```bash
wget -O wp-content/mu-plugins/cli-notice.php https://github.com/centminmod/centminmod-mu-plugins/raw/refs/heads/master/mu-plugins/cli-notice.php
chown nginx:nginx wp-content/mu-plugins/cli-notice.php
chmod 644 wp-content/mu-plugins/cli-notice.php
```

4. **(SELinux only)** If you're on AlmaLinux with SELinux enforcing, run:

   ```bash
   restorecon -Rv wp-content/mu-plugins
   ```

That's it! The plugin is active automatically and ready for secure use.

---

## Security Features

Version 2.0.0 includes comprehensive security enhancements:

* **Permission Validation**: All WP-CLI commands verify user capabilities
* **Input Sanitization**: Messages are validated and sanitized with length limits (1000 chars max)
* **XSS Prevention**: Strict HTML filtering with allowed tags only
* **Command Injection Protection**: Direct WordPress API usage instead of shell commands
* **Date Validation**: Proper expiration date format checking and timezone handling
* **Error Handling**: Comprehensive validation with informative error messages
* **Audit Logging**: All operations logged when `WP_DEBUG_LOG` is enabled
* **Automatic Cleanup**: Options cleaned up on plugin deactivation

---

## Usage

Once installed, you can manage dashboard notices using the enhanced `wp notice` commands with built-in security validation.

### `wp notice add`

**Syntax:**

```bash
wp notice add "Your message here" [--type=<type>] [--expires=<YYYY-MM-DD HH:MM:SS>]
```

**Parameters:**
* `message` (required): Notice text (max 1000 characters)
* `--type` (optional): Notice style - `info`, `success`, `warning`, or `error` (default: `warning`)
* `--expires` (optional): Auto-expiry timestamp in format `YYYY-MM-DD HH:MM:SS`

**Examples:**

```bash
# Basic warning notice
wp notice add "🔔 System maintenance scheduled"

# Success notice with expiration
wp notice add "🎉 Deployment complete" --type=success --expires="2025-06-17 09:00:00"

# Error notice that never expires
wp notice add "❌ Critical: Database backup failed" --type=error
```

**Sample Output:**
```bash
wp notice add "🔔 Hello world" --type=info --expires="2025-06-20 03:00:00"
Success: Notice added successfully. Type: info
```

**Security Features:**
* Validates message length and content
* Prevents duplicate notices (must delete or update existing)
* Validates expiration date format and ensures future dates only
* Sanitizes HTML content with allowed tags only

### `wp notice update`

**Syntax:**

```bash
wp notice update "Updated message" [--type=<type>] [--expires=<YYYY-MM-DD HH:MM:SS>] [--clear-expiry]
```

**Parameters:**
* `message` (required): New notice text
* `--type` (optional): Change notice type
* `--expires` (optional): Set new expiration date
* `--clear-expiry` (optional): Remove expiration (notice will persist until manually deleted)

**Examples:**

```bash
# Update message only
wp notice update "🔄 Maintenance in progress"

# Change type and clear expiration
wp notice update "✅ Maintenance completed" --type=success --clear-expiry

# Update with new expiration
wp notice update "⚠️ Service degraded" --type=warning --expires="2025-06-18 12:00:00"
```

**Sample Output:**
```bash
wp notice update "Updated message text" --clear-expiry
Success: Notice updated successfully. Type: warning
```

**Security Features:**
* Requires existing notice to update
* Validates all input parameters
* Preserves existing settings when not specified
* Prevents setting expiration dates in the past

### `wp notice delete`

**Syntax:**

```bash
wp notice delete
```

**Examples:**

```bash
# Remove current notice
wp notice delete
```

**Sample Output:**
```bash
wp notice delete
Success: Deleted 'temp_cli_dashboard_notice' option.
Success: Deleted 'temp_cli_dashboard_notice_type' option.
Success: Deleted 'temp_cli_dashboard_notice_expires' option.
Success: Notice removal complete.
```

**Security Features:**
* Safely removes all related options
* Provides feedback on what was actually deleted
* Graceful handling of missing options

### `wp notice status`

**Syntax:**

```bash
wp notice status
```

**Examples:**

```bash
# Check current notice details
wp notice status
```

**Sample Output:**
```bash
wp notice status
Current Notice Status:
Message: 🎉 Deployment successful
Type: success
Expires: 2025-06-17 15:30:00 (2 hours remaining)
```

**Features:**
* Shows complete notice information
* Calculates and displays time remaining for expiring notices
* Warns about expired or invalid expiration dates
* Indicates when no notice is active

---

## Examples

### Basic Operations

```bash
# Add a simple maintenance notice
wp notice add "🔧 Scheduled maintenance tonight at 2 AM EST" --type=warning

# Check what's currently active
wp notice status

# Update for immediate maintenance
wp notice update "🚨 Emergency maintenance in progress" --type=error

# Clear when done
wp notice delete
```

### Deployment Workflow

```bash
# Start deployment notice
wp notice add "🚀 Deployment starting..." --type=info --expires="2025-06-17 14:00:00"

# Update during deployment
wp notice update "⏳ Deployment in progress - database migration" --type=warning

# Success notification
wp notice update "✅ Deployment completed successfully" --type=success --expires="2025-06-17 15:00:00"

# Auto-expires in 1 hour, or manually remove
wp notice delete
```

### Emergency Notifications

```bash
# Critical system alert (no expiry)
wp notice add "🚨 CRITICAL: Payment system down - investigating" --type=error

# Update with progress
wp notice update "🔧 Payment system restored - monitoring for stability" --type=warning --expires="2025-06-17 18:00:00"

# Final confirmation
wp notice update "✅ All systems operational" --type=success --expires="2025-06-17 17:00:00"
```

### Scheduled Maintenance

```bash
# Advance notice (expires right before maintenance)
wp notice add "📅 Scheduled maintenance: Tomorrow 2-4 AM EST" --type=info --expires="2025-06-18 02:00:00"

# During maintenance window
wp notice add "🔧 Maintenance in progress - expect brief interruptions" --type=warning --expires="2025-06-18 04:00:00"

# Maintenance complete
wp notice add "✅ Maintenance completed - all systems normal" --type=success --expires="2025-06-18 06:00:00"
```

---

## Security Considerations

### Permission Requirements

* WP-CLI commands require WordPress context with `manage_options` capability
* File system access needed for plugin installation
* Commands are logged when `WP_DEBUG_LOG` is enabled

### Input Validation

* Messages limited to 1000 characters
* HTML content filtered to safe tags only: `<strong>`, `<em>`, `<code>`, `<br>`, `<a>`
* Expiration dates must be in future and valid format
* Notice types restricted to predefined values

### Allowed HTML Tags

The plugin allows these HTML tags in messages for basic formatting:

```html
<strong>Bold text</strong>
<em>Italic text</em>
<code>Code snippets</code>
<br> <!-- Line breaks -->
<a href="https://example.com" title="Link title">Link text</a>
```

### Best Practices

1. **Use appropriate notice types**:
   - `info`: General information, announcements
   - `success`: Completed actions, confirmations
   - `warning`: Important notices, upcoming events
   - `error`: Critical issues, failures

2. **Set reasonable expiration times**:
   - Short-term: 1-4 hours for immediate issues
   - Medium-term: 1-2 days for scheduled events
   - Long-term: 1 week maximum for general announcements

3. **Keep messages concise and actionable**
4. **Use emojis sparingly for visual impact**
5. **Always clean up notices when issues are resolved**

---

## Troubleshooting

### Common Issues

* **"Insufficient permissions" error:**
  * Ensure your WordPress user has `manage_options` capability
  * Verify WP-CLI is running in correct WordPress context
  * Check file permissions on WordPress directory

* **"Invalid expiration date format" error:**
  * Use exact format: `YYYY-MM-DD HH:MM:SS`
  * Example: `2025-06-17 14:30:00`
  * Ensure date is in the future

* **"Message too long" error:**
  * Keep messages under 1000 characters
  * Use concise, actionable language
  * Move detailed information to external documentation

* **Notice not appearing in dashboard:**
  * Verify you have `manage_options` capability
  * Check if notice has expired: `wp notice status`
  * Ensure message exists: `wp option get temp_cli_dashboard_notice`

* **HTML not rendering correctly:**
  * Use only allowed HTML tags
  * Check HTML syntax and proper tag closure
  * Test with simple text first, then add formatting

### Debug Information

Enable WordPress debug logging to see detailed operation logs:

```php
// In wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Check logs at: `wp-content/debug.log`

### SELinux Issues (AlmaLinux/RHEL)

If notices don't appear on SELinux systems:

```bash
# Check SELinux context
ls -Z wp-content/mu-plugins/

# Restore proper context
restorecon -Rv wp-content/mu-plugins/

# Check for SELinux denials
grep "cli-notice" /var/log/audit/audit.log
```

---

## Advanced Features

### Integration with CI/CD Pipelines

```bash
#!/bin/bash
# deployment-notice.sh

# Start deployment
wp notice add "🚀 Deploying version $VERSION" --type=info --expires="$(date -d '+2 hours' '+%Y-%m-%d %H:%M:%S')"

# Your deployment commands here
./deploy.sh

# Update based on result
if [ $? -eq 0 ]; then
    wp notice update "✅ Version $VERSION deployed successfully" --type=success --expires="$(date -d '+1 hour' '+%Y-%m-%d %H:%M:%S')"
else
    wp notice update "❌ Deployment failed - rolling back" --type=error
fi
```

### Automated Maintenance Windows

```bash
#!/bin/bash
# maintenance-window.sh

START_TIME="2025-06-18 02:00:00"
END_TIME="2025-06-18 04:00:00"

# Schedule advance notice
wp notice add "📅 Maintenance scheduled: $(date -d "$START_TIME" '+%b %d at %I:%M %p')" --type=info --expires="$START_TIME"

# During maintenance (run from cron at start time)
wp notice add "🔧 Maintenance in progress" --type=warning --expires="$END_TIME"

# Completion notice (run from cron at end time)
wp notice add "✅ Maintenance completed" --type=success --expires="$(date -d "$END_TIME + 2 hours" '+%Y-%m-%d %H:%M:%S')"
```

### WordPress Hooks Integration

```php
// In your theme's functions.php or custom plugin
add_action( 'wp_ajax_custom_deploy_start', function() {
    if ( current_user_can( 'manage_options' ) ) {
        WP_CLI::runcommand( 'notice add "🚀 Custom deployment initiated" --type=info' );
    }
});
```

### Status Monitoring

```bash
#!/bin/bash
# check-notice-status.sh

STATUS=$(wp notice status 2>/dev/null)

if echo "$STATUS" | grep -q "No active notice"; then
    echo "No notices active"
    exit 0
elif echo "$STATUS" | grep -q "Expired:"; then
    echo "Notice has expired - cleaning up"
    wp notice delete
else
    echo "Active notice found:"
    echo "$STATUS"
fi
```

---

## Migration from v1.x

If upgrading from version 1.x, no data migration is needed. However, be aware of these changes:

### Breaking Changes

1. **Command validation**: v2.0 has stricter input validation
2. **Permission requirements**: All commands now check capabilities
3. **Error handling**: More informative error messages, some scripts may need updating

### Recommended Updates

1. **Update automation scripts** to handle new error messages
2. **Add error checking** to existing scripts using the plugin
3. **Review message content** to ensure compliance with 1000-character limit
4. **Test expiration date formats** with new validation

### Compatibility

* All existing notices will continue to work
* WP-CLI command syntax remains the same
* Option names unchanged for backward compatibility

---

## API Reference

### WordPress Options Used

* `temp_cli_dashboard_notice`: Main notice message content
* `temp_cli_dashboard_notice_type`: Notice type (info, success, warning, error)  
* `temp_cli_dashboard_notice_expires`: Expiration timestamp (YYYY-MM-DD HH:MM:SS format)

### WordPress Hooks

* `admin_notices`: Displays the notice in admin dashboard
* `register_deactivation_hook`: Cleans up options when plugin deactivated

### Constants

* `CLI_Dashboard_Notice::MAX_MESSAGE_LENGTH`: 1000 characters
* `CLI_Dashboard_Notice::VALID_TYPES`: ['info', 'success', 'warning', 'error']
* `CLI_Dashboard_Notice::OPTION_PREFIX`: 'temp_cli_dashboard_notice'

---

## Support and Contributing

For issues, feature requests, or contributions:

* **GitHub Repository**: https://github.com/centminmod/centminmod-mu-plugins
* **Documentation**: This README file
* **Security Issues**: Please report privately to the maintainers

### Version History

* **v2.0.0**: Complete security rewrite with enhanced validation
* **v1.2.0**: Basic functionality with expiration support
* **v1.1.0**: Added notice types
* **v1.0.0**: Initial release

---

## License

This plugin is licensed under the GPLv2 or later license. You are free to use, modify, and distribute it according to the terms of the license.