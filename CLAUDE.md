# CLAUDE.md - Smartschool Retards Plugin Guide

**Last Updated:** 2025-12-07
**Plugin Version:** 1.0.0
**Author:** INDL

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Codebase Structure](#codebase-structure)
3. [File Loading Order](#file-loading-order)
4. [Database Schema](#database-schema)
5. [Authentication System](#authentication-system)
6. [Security Patterns](#security-patterns)
7. [Key Conventions](#key-conventions)
8. [Development Workflows](#development-workflows)
9. [Common Tasks](#common-tasks)
10. [Important Notes for AI Assistants](#important-notes-for-ai-assistants)

---

## Project Overview

### What This Plugin Does

**Smartschool – Retards 2** is a WordPress plugin for managing student tardiness verification in French-speaking schools. It integrates with Smartschool (a school management platform) to track and verify late student arrivals with PIN-protected verifier access.

### Key Features

- **PIN-based authentication** (separate from WordPress users)
- **SOAP V3 API integration** with Smartschool webservices
- **Custom database tables** for logging and verification records
- **Shortcode-based UI** for embedding functionality into pages
- **Daily automated tasks** via WordPress cron
- **Mobile-responsive interface** with bottom navigation
- **Security features**: rate limiting, HMAC token signing, nonces
- **Audit trail** for all verification changes
- **Calendar filtering** (holidays, weekends, specific dates)

### Technology Stack

- **Language:** PHP 7.0+ (uses type hints, null coalescing operator)
- **Framework:** WordPress Plugin API
- **External API:** SOAP V3 (Smartschool webservices)
- **Database:** MySQL/MariaDB via wpdb
- **Session Management:** Cookie-based JWT tokens with HMAC signatures
- **Frontend:** Plain PHP templates with inline CSS/SVG icons
- **Timezone:** Europe/Brussels (configurable via WordPress settings)

---

## Codebase Structure

```
Smartschool-retards/
├── smartschool-retards.php       # Main plugin file (253 lines)
│                                  # - Defines constants
│                                  # - Loads all includes and shortcodes
│                                  # - Activation/deactivation hooks
│                                  # - CSS injection for UI customization
│                                  # - Logout button global display
│
├── includes/                      # Core plugin functionality
│   ├── constants.php             # Table names, option keys, hooks (38 lines)
│   ├── security.php              # Nonce helpers (368 bytes)
│   ├── helpers.php               # Utilities: dates, sanitization, calendar (~4.6KB)
│   ├── auth.php                  # PIN-based authentication/session (~4KB)
│   ├── db.php                    # Database table creation, logging (~1.7KB)
│   ├── api.php                   # SOAP V3 API wrapper for Smartschool (~13.8KB)
│   ├── cron.php                  # Daily scheduled tasks (~6.5KB)
│   ├── admin.php                 # Admin UI: settings, verifier management (~14KB)
│   └── daily-messages            # Message templates for cron (~17KB)
│
└── shortcodes/                   # Frontend shortcodes
    ├── ssr_login.php             # Login form [ssr_login] (~14KB)
    ├── ssr_nav.php               # Bottom navigation [ssr_nav] (~7KB)
    ├── fiche_eleve.php           # Student card [fiche_eleve] (~3.5KB)
    ├── retards_verif.php         # Main verification UI [retards_verif] (~27KB)
    ├── recap_retards.php         # Statistics summary [ssr_recap_retards] (~42KB)
    ├── recap_calendrier.php      # Calendar view [ssr_calendrier] (~25KB)
    └── ssr_suivi.php             # Audit trail [ssr_suivi] (~2.2KB)
```

### Key File Descriptions

| File | Purpose | Dependencies |
|------|---------|--------------|
| **smartschool-retards.php** | Main plugin loader, activation/deactivation hooks, CSS injection, logout button display | All includes and shortcodes |
| **includes/constants.php** | Centralizes table names, option keys, cron hook names | None |
| **includes/helpers.php** | Date/time utilities, sanitization, calendar generation | constants.php |
| **includes/security.php** | WordPress nonce wrappers | None |
| **includes/auth.php** | PIN authentication, token generation/verification, session management | constants.php, security.php |
| **includes/db.php** | Database table creation (dbDelta), logging function | constants.php |
| **includes/api.php** | SOAP V3 client for Smartschool API calls | constants.php, helpers.php |
| **includes/cron.php** | Daily notifications scheduler and executor | constants.php, helpers.php, api.php |
| **includes/admin.php** | Admin menu, settings page, verifier management UI | constants.php, security.php, auth.php, db.php |
| **shortcodes/*.php** | Each registers its own shortcode and renders frontend UI | All includes |

---

## File Loading Order

**CRITICAL:** The order of includes matters due to function dependencies!

```php
// From smartschool-retards.php (lines 19-27)
require_once SSR_INC_DIR . 'constants.php';    // 1. Define constants first
require_once SSR_INC_DIR . 'helpers.php';      // 2. Load utilities
require_once SSR_INC_DIR . 'security.php';     // 3. Load security helpers
require_once SSR_INC_DIR . 'auth.php';         // 4. Load auth (uses security)
require_once SSR_INC_DIR . 'db.php';           // 5. Load database functions
require_once SSR_INC_DIR . 'api.php';          // 6. Load API (uses helpers, constants)
require_once SSR_INC_DIR . 'cron.php';         // 7. Load cron (uses api, helpers)
require_once SSR_INC_DIR . 'admin.php';        // 8. Load admin (uses all above)

// Shortcodes can load in any order (lines 29-36)
require_once SSR_SC_DIR . 'fiche_eleve.php';
require_once SSR_SC_DIR . 'recap_calendrier.php';
require_once SSR_SC_DIR . 'recap_retards.php';
require_once SSR_SC_DIR . 'ssr_login.php';
require_once SSR_SC_DIR . 'ssr_nav.php';
require_once SSR_SC_DIR . 'ssr_suivi.php';
require_once SSR_SC_DIR . 'retards_verif.php';
```

**Why this matters:**
- `auth.php` depends on `constants.php` for `SSR_T_VERIFIERS`
- `api.php` depends on `helpers.php` for date formatting
- `admin.php` depends on `auth.php` for PIN verification
- Shortcodes depend on all includes for their functionality

**When adding new files:**
- Add constants to `includes/constants.php`
- Add helpers/utilities to `includes/helpers.php`
- Create new shortcodes in `shortcodes/` directory
- Update the loading order in `smartschool-retards.php` if dependencies exist

---

## Database Schema

### Tables Created

All tables use the `wp_` prefix (configurable via $wpdb->prefix).

#### 1. `wp_smartschool_retards_log`
**Purpose:** Activity and error logging

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key, auto-increment |
| `created_at` | DATETIME | Timestamp (default: CURRENT_TIMESTAMP) |
| `level` | VARCHAR(20) | Log level: 'info', 'warning', 'error' |
| `context` | VARCHAR(100) | Context category (e.g., 'auth', 'cron', 'api') |
| `message` | TEXT | Log message content |

**Indexes:** `level`, `context`
**Constant:** `SSR_T_LOG`

#### 2. `wp_smartschool_retards_verif`
**Purpose:** Attendance verification records

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key, auto-increment |
| `user_identifier` | VARCHAR(64) | Student ID from Smartschool |
| `class_code` | VARCHAR(64) | Class identifier |
| `date_jour` | DATE | Verification date (Y-m-d format) |
| `status` | ENUM | 'present', 'absent', 'late' |
| `lastname` | VARCHAR(191) | Student last name |
| `firstname` | VARCHAR(191) | Student first name |
| `verified_by_id` | VARCHAR(64) | Verifier ID who made the entry |
| `verified_by_name` | VARCHAR(191) | Verifier display name |
| `created_at` | DATETIME | Record creation timestamp |
| `updated_at` | DATETIME | Last modification timestamp |

**Unique Key:** `(user_identifier, date_jour)` - One record per student per day
**Index:** `(class_code, date_jour)`
**Constant:** `SSR_T_VERIF`

#### 3. `wp_smartschool_retards_verifiers`
**Purpose:** Verifier accounts (PIN management)

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key, auto-increment |
| `display_name` | VARCHAR(191) | Verifier's display name |
| `pin_hash` | VARCHAR(255) | Hashed PIN (password_hash or MD5 fallback) |
| `is_active` | TINYINT(1) | Active status (1=active, 0=inactive) |
| `created_at` | DATETIME | Account creation timestamp |

**Constant:** `SSR_T_VERIFIERS`

#### 4. `wp_smartschool_retards_verif_audit`
**Purpose:** Change audit trail for verification records

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key, auto-increment |
| `verif_id` | BIGINT UNSIGNED | FK to `wp_smartschool_retards_verif.id` |
| `action` | VARCHAR(20) | 'created', 'updated', 'deleted' |
| `old_status` | ENUM | Previous status value |
| `new_status` | ENUM | New status value |
| `changed_by_id` | VARCHAR(64) | Verifier ID who made the change |
| `changed_by_name` | VARCHAR(191) | Verifier display name |
| `changed_at` | DATETIME | Timestamp of change |

**Index:** `verif_id`, `changed_at`

### Database Operations

**Creation:** `ssr_db_maybe_create_tables()` in `includes/db.php:4`
**Logging:** `ssr_log($message, $level='info', $context=null)` in `includes/db.php:46`

```php
// Example usage
ssr_log('Student verification completed', 'info', 'verification');
ssr_log('SOAP API timeout', 'error', 'api');
```

---

## Authentication System

### How PIN Authentication Works

This plugin uses a **custom PIN-based authentication system** separate from WordPress users. It's designed for kiosk-style access where verifiers log in with a numeric ID and PIN.

### Authentication Flow

1. **User visits `/connexion-verificateur`** (contains `[ssr_login]` shortcode)
2. **Enters verifier ID + PIN** in the login form
3. **Rate limiting checked** (max 5 attempts per 10 minutes per user/IP)
4. **PIN verification** against `wp_smartschool_retards_verifiers` table
5. **Token generation** with HMAC-SHA256 signature
6. **Cookie storage** (HttpOnly, Secure if HTTPS, SameSite=Lax)
7. **Redirect** to original page or specified URL parameter

### Token Structure

Tokens are JWT-like structures with HMAC signatures:

```php
// Token payload (auth.php:28-37)
[
    'vid' => (int) $verifier_id,        // Verifier ID
    'vnm' => (string) $verifier_name,   // Verifier display name
    'exp' => (int) $expires_timestamp,  // Expiration time
    'sig' => (string) $hmac_signature   // HMAC-SHA256 signature
]

// Signature data
$data = get_site_url() . '|' . $vid . '|' . $vnm . '|' . $exp;
$sig  = hash_hmac('sha256', $data, wp_salt('auth'));
```

### Key Functions

| Function | Location | Purpose |
|----------|----------|---------|
| `ssr_is_logged_in_pin()` | auth.php:56 | Check if current user is authenticated |
| `ssr_current_verifier()` | auth.php:65 | Get current verifier's ID and name |
| `ssr_check_pin_for_verifier()` | auth.php:74 | Verify PIN for a verifier ID |
| `ssr_pin_grant()` | auth.php:98 | Create session cookie after successful login |
| `ssr_pin_revoke()` | auth.php:116 | Destroy session cookie (logout) |
| `ssr_pin_verify_token()` | auth.php:41 | Validate token signature and expiration |

### Session Duration

**Default:** 8 hours (`SSR_PIN_SESSION_HOURS` in auth.php:5)
**Cookie name:** `ssr_pin_session` (`SSR_PIN_COOKIE`)

### Security Features

- **HMAC-SHA256 signature** prevents token tampering
- **Site URL binding** prevents token reuse across domains
- **Expiration timestamp** enforced on every request
- **HttpOnly flag** prevents JavaScript access
- **Secure flag** enforced on HTTPS sites
- **SameSite=Lax** prevents CSRF attacks
- **Constant-time comparison** using `hash_equals()`

### Rate Limiting (Login)

- **Implementation:** In `shortcodes/ssr_login.php`
- **Limit:** 5 attempts per 10 minutes
- **Tracked by:** User ID + IP address
- **Storage:** WordPress transients
- **Key format:** `ssr_login_attempt_{verifier_id}_{ip_hash}`

---

## Security Patterns

### 1. Direct Access Prevention

Every PHP file starts with:
```php
if (!defined('ABSPATH')) exit;
```

**Purpose:** Prevents direct file execution outside WordPress context.

### 2. WordPress Nonces

**Usage:** All forms use nonces to prevent CSRF attacks.

```php
// In forms (security.php:3-8)
ssr_generate_nonce_field('my_action_name');

// In form handlers
if (!ssr_verify_nonce('my_action_name')) {
    wp_die('Security check failed');
}
```

**Wrapper functions:** `ssr_generate_nonce_field()`, `ssr_verify_nonce()`
**Location:** `includes/security.php`

### 3. Input Sanitization

**Always sanitize user input:**

```php
// Text fields
$name = sanitize_text_field($_POST['name']);

// Integers
$id = intval($_POST['id']);

// Dates (Y-m-d format)
$date = ssr_sanitize_date($_POST['date']);  // helpers.php

// HTML output
echo esc_html($user_input);
echo esc_attr($attribute_value);
echo esc_url($url);

// KSES filtering for allowed HTML
$allowed = ['strong' => [], 'em' => [], 'br' => []];
echo wp_kses($html_content, $allowed);
```

### 4. Database Queries

**Always use prepared statements:**

```php
global $wpdb;

// Good - prepared statement
$row = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM " . SSR_T_VERIFIERS . " WHERE id = %d",
    $verifier_id
));

// Bad - SQL injection risk
// $row = $wpdb->get_row("SELECT * FROM ... WHERE id = $id");
```

### 5. Rate Limiting Pattern

```php
// Example from ssr_login.php
$transient_key = 'ssr_login_attempt_' . $verifier_id . '_' . md5($ip);
$attempts = (int) get_transient($transient_key);

if ($attempts >= 5) {
    // Block login attempt
    return;
}

// Increment counter
set_transient($transient_key, $attempts + 1, 10 * MINUTE_IN_SECONDS);
```

### 6. Password Hashing

```php
// Storage (admin.php)
$pin_hash = function_exists('password_hash')
    ? password_hash($pin, PASSWORD_DEFAULT)
    : md5($pin);  // Fallback for old PHP

// Verification (auth.php:88-92)
if (function_exists('password_verify')) {
    $valid = password_verify($pin_plain, $hash);
} else {
    $valid = hash_equals($hash, md5($pin_plain));
}
```

**Note:** MD5 fallback is for compatibility but should be upgraded to bcrypt.

---

## Key Conventions

### Naming Conventions

| Type | Convention | Examples |
|------|------------|----------|
| **Functions** | `ssr_` prefix + snake_case | `ssr_is_logged_in_pin()`, `ssr_sanitize_date()` |
| **Constants** | `SSR_` prefix + SCREAMING_SNAKE_CASE | `SSR_T_LOG`, `SSR_PLUGIN_DIR`, `SSR_OPT_ENDPOINT` |
| **Table names** | `SSR_T_` prefix | `SSR_T_LOG`, `SSR_T_VERIF`, `SSR_T_VERIFIERS` |
| **Options** | `SSR_OPT_` prefix or legacy short names | `SSR_OPT_ENDPOINT`, `url`, `accesscode` |
| **Shortcodes** | `ssr_` prefix or descriptive names | `[ssr_login]`, `[retards_verif]`, `[ssr_nav]` |
| **CSS classes** | `ssr-` prefix + kebab-case | `.ssr-notice`, `.ssr-nav`, `.ssr-logout-floating` |

### Code Style

- **Indentation:** Tabs (not spaces)
- **Brace style:** K&R style (opening brace on same line)
- **String quotes:** Single quotes for strings, double for HTML attributes
- **Array syntax:** Short array syntax `[]` preferred over `array()`
- **Type hints:** Used where PHP version allows
- **Null coalescing:** `??` operator used throughout

### Function Definition Pattern

All functions use existence checks to prevent redefinition:

```php
if (!function_exists('ssr_my_function')) {
    function ssr_my_function() {
        // Implementation
    }
}
```

**Why:** Allows other plugins/themes to override functions if needed.

### Date/Time Handling

- **Internal storage:** `Y-m-d` format (2025-12-07)
- **Display format:** Configurable, default `d/m/Y` (07/12/2025)
- **Timezone:** Europe/Brussels by default, respects WordPress timezone setting
- **Functions:** `ssr_sanitize_date()`, `ssr_current_date_ymd()` in helpers.php

### WordPress Options

**Storage pattern:**

```php
// Save
update_option(SSR_OPT_ENDPOINT, $value);

// Retrieve
$value = get_option(SSR_OPT_ENDPOINT, $default);

// Delete
delete_option(SSR_OPT_ENDPOINT);
```

**Key options:**

| Option Key | Constant | Purpose |
|------------|----------|---------|
| `ssr_api_endpoint` | `SSR_OPT_ENDPOINT` | HTTP endpoint URL |
| `ssr_sender_identifier` | `SSR_OPT_SENDER` | Message sender ID |
| `ssr_daily_hhmm` | `SSR_OPT_DAILY_HHMM` | Cron time (HH:MM) |
| `ssr_test_mode` | `SSR_OPT_TESTMODE` | Debug flag (0/1) |
| `url` | `SSR_OPT_SOAP_URL` | Smartschool SOAP URL |
| `accesscode` | `SSR_OPT_SOAP_ACCESSCODE` | SOAP access code |
| `hours` | `SSR_OPT_SOAP_HOURS` | Time window setting |

---

## Development Workflows

### Adding a New Shortcode

1. **Create file** in `shortcodes/` directory (e.g., `my_shortcode.php`)
2. **Add ABSPATH check:**
   ```php
   <?php
   if (!defined('ABSPATH')) exit;
   ```
3. **Register shortcode:**
   ```php
   add_shortcode('my_shortcode', 'ssr_render_my_shortcode');
   ```
4. **Implement render function:**
   ```php
   function ssr_render_my_shortcode($atts) {
       // Auth check if needed
       if (!ssr_is_logged_in_pin()) {
           return '<p>Veuillez vous connecter.</p>';
       }

       // Your logic here
       ob_start();
       ?>
       <div class="ssr-my-shortcode">
           <!-- Your HTML -->
       </div>
       <?php
       return ob_get_clean();
   }
   ```
5. **Load in main plugin file** (smartschool-retards.php):
   ```php
   require_once SSR_SC_DIR . 'my_shortcode.php';
   ```
6. **Create WordPress page** with shortcode `[my_shortcode]`

### Adding a New Helper Function

1. **Open** `includes/helpers.php`
2. **Add function** with existence check:
   ```php
   if (!function_exists('ssr_my_helper')) {
       function ssr_my_helper($param) {
           // Implementation
           return $result;
       }
   }
   ```
3. **Document** with PHPDoc comments
4. **Use** anywhere in the plugin after helpers.php is loaded

### Adding a New Database Table

1. **Define constant** in `includes/constants.php`:
   ```php
   if (!defined('SSR_T_MYTABLE')) {
       define('SSR_T_MYTABLE', $wpdb->prefix . 'smartschool_retards_mytable');
   }
   ```
2. **Add creation SQL** in `includes/db.php` function `ssr_db_maybe_create_tables()`:
   ```php
   $mytable = SSR_T_MYTABLE;
   $sql[] = "CREATE TABLE IF NOT EXISTS $mytable (
       id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
       created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       -- your columns here
       PRIMARY KEY(id)
   ) $charset;";
   ```
3. **Run** on plugin activation (automatic via dbDelta)
4. **Deactivate and reactivate** plugin to create table

### Working with the SOAP API

**Main function:** `ssr_api($methode, $params)` in `includes/api.php`

**Example:**
```php
// Get absent students for a date
$date = '2025-12-07';
$result = ssr_api('getAbsentsWithInternalNumberByDate', ['date' => $date]);

if (is_wp_error($result)) {
    // Error handling
    ssr_log('SOAP API error: ' . $result->get_error_message(), 'error', 'api');
    return false;
}

// Success - $result is the SOAP response
foreach ($result as $student) {
    echo $student->lastName . ' ' . $student->firstName;
}
```

**Important filters:**
- `ssr_soap_date` - Transform date format before sending to API
- `ssr_soap_absents_method` - Change SOAP method name

### Cron Jobs

**Hook name:** `SSR_CRON_HOOK` ('ssr_daily_notifications_event')
**Function:** `ssr_cron_do_daily_work()` in `includes/cron.php`

**Schedule/reschedule:**
```php
// Done automatically on activation
ssr_cron_maybe_reschedule_daily();

// Manual reschedule (e.g., after settings change)
wp_clear_scheduled_hook(SSR_CRON_HOOK);
ssr_cron_maybe_reschedule_daily();
```

**Execution time:** Configured via `ssr_daily_hhmm` option (default: "13:15")

### Page Detection Logic

**Function:** `ssr_is_retards_page()` in smartschool-retards.php:61

**Detects pages by:**
1. Page slug match (`retards-verif`, `recap-retards`, etc.)
2. Shortcode presence in post content

**Used for:**
- Adding `smartschool-retards` body class
- Hiding theme headers/footers with CSS
- Displaying logout button

---

## Common Tasks

### 1. Add a New Verifier (Programmatically)

```php
global $wpdb;

$pin_hash = password_hash('1234', PASSWORD_DEFAULT);

$wpdb->insert(
    SSR_T_VERIFIERS,
    [
        'display_name' => 'John Doe',
        'pin_hash' => $pin_hash,
        'is_active' => 1,
    ],
    ['%s', '%s', '%d']
);

$verifier_id = $wpdb->insert_id;
```

### 2. Check if User is Logged In

```php
if (ssr_is_logged_in_pin()) {
    $verifier = ssr_current_verifier();
    echo 'Logged in as: ' . esc_html($verifier['name']);
} else {
    echo '<a href="/connexion-verificateur">Login</a>';
}
```

### 3. Log an Event

```php
ssr_log('Student marked as late', 'info', 'verification');
ssr_log('API timeout after 30s', 'warning', 'api');
ssr_log('Database insert failed', 'error', 'db');
```

### 4. Sanitize User Input

```php
// Text field
$name = sanitize_text_field($_POST['name']);

// Date (Y-m-d)
$date = ssr_sanitize_date($_POST['date']);

// Integer
$id = intval($_POST['id']);

// Status enum
$status = in_array($_POST['status'], ['present', 'absent', 'late'])
    ? $_POST['status']
    : 'present';
```

### 5. Query Verification Records

```php
global $wpdb;

// Get all verifications for a specific date
$date = '2025-12-07';
$results = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM " . SSR_T_VERIF . " WHERE date_jour = %s ORDER BY lastname, firstname",
    $date
));

foreach ($results as $row) {
    echo $row->firstname . ' ' . $row->lastname . ': ' . $row->status;
}
```

### 6. Add Custom Styling to Shortcode Pages

```php
// In your shortcode render function
ob_start();
?>
<style>
.ssr-my-custom-class {
    padding: 20px;
    background: #f5f5f7;
    border-radius: 8px;
}
</style>
<div class="ssr-my-custom-class">
    <!-- Your content -->
</div>
<?php
return ob_get_clean();
```

### 7. Override SOAP Method via Filter

```php
// In functions.php or custom plugin
add_filter('ssr_soap_absents_method', function() {
    return 'getLatesWithInternalNumberByDate';
});
```

### 8. Debug SOAP API Calls

Enable test mode:
```php
update_option(SSR_OPT_TESTMODE, '1');
```

Check logs:
```php
global $wpdb;
$logs = $wpdb->get_results(
    "SELECT * FROM " . SSR_T_LOG . " WHERE context = 'api' ORDER BY created_at DESC LIMIT 20"
);
```

---

## Important Notes for AI Assistants

### When Reading This Codebase

1. **File loading order matters** - Always check dependencies before suggesting file reorganization
2. **Not WordPress user-based** - This uses a custom PIN authentication system separate from WP users
3. **French language** - UI text, comments, and variable names are in French
4. **SOAP V3 API** - External dependency on Smartschool webservices (may require credentials to test)
5. **Database table names** - Always use constants (`SSR_T_LOG`) instead of hardcoded table names
6. **Timezone awareness** - Dates are stored in Y-m-d format but displayed according to timezone

### When Modifying Code

1. **Always preserve the ABSPATH check** at the top of files
2. **Use prepared statements** for all database queries
3. **Sanitize all user input** before processing
4. **Escape all output** using `esc_html()`, `esc_attr()`, `esc_url()`
5. **Generate nonces** for all forms and verify them in handlers
6. **Wrap functions** in `if (!function_exists())` checks
7. **Log important events** using `ssr_log()` for debugging
8. **Respect the naming conventions** (ssr_ prefix, snake_case)
9. **Add new constants** to `includes/constants.php`
10. **Update the loading order** in `smartschool-retards.php` if adding dependencies

### When Adding Features

1. **Check authentication** using `ssr_is_logged_in_pin()` in shortcodes
2. **Use WordPress transients** for temporary data (rate limiting, caching)
3. **Follow the shortcode pattern** - one file per shortcode in `shortcodes/`
4. **Add helpers** to `includes/helpers.php` if reusable across multiple files
5. **Use the logging system** instead of `error_log()` or `var_dump()`
6. **Test with test mode enabled** (`SSR_OPT_TESTMODE`) before production
7. **Consider mobile responsiveness** - existing UI uses bottom navigation for mobile

### When Debugging

1. **Check the log table** first: `SELECT * FROM wp_smartschool_retards_log ORDER BY created_at DESC`
2. **Enable test mode:** `update_option('ssr_test_mode', '1')`
3. **Check for JavaScript errors** in browser console (forms may fail silently)
4. **Verify nonces** are being generated and validated correctly
5. **Check cron execution:** `wp cron event list` (WP-CLI) or plugin like "WP Crontrol"
6. **Examine SOAP responses** in api.php by adding temporary logging
7. **Verify table existence:** `SHOW TABLES LIKE 'wp_smartschool_retards%'`

### Security Considerations

1. **Never store plain-text PINs** - always hash with `password_hash()`
2. **Never trust `$_GET`, `$_POST`, `$_COOKIE`** - always sanitize
3. **Never output user data without escaping** - XSS vulnerability
4. **Never build SQL queries with concatenation** - SQL injection risk
5. **Always verify nonces** before processing form submissions
6. **Use rate limiting** for login forms and sensitive operations
7. **Set HttpOnly and Secure flags** on authentication cookies
8. **Validate token signatures** using constant-time comparison (`hash_equals`)
9. **Implement CSRF protection** via nonces on all state-changing operations
10. **Log security events** (login attempts, failed auth, suspicious activity)

### Performance Considerations

1. **SOAP API calls are slow** - use caching where appropriate
2. **Database queries in loops** - use prepared statements and batch operations
3. **Cron jobs** - ensure they complete within PHP execution time limits
4. **Shortcode rendering** - use output buffering (`ob_start`/`ob_get_clean`)
5. **Transients** - set appropriate expiration times (don't pollute the database)

### Known Limitations

1. **MD5 fallback** for PIN hashing (legacy PHP compatibility) - should be bcrypt
2. **No multilingual support** - hardcoded French text
3. **No user roles/permissions** - all verifiers have same access level
4. **SOAP dependency** - requires external API availability
5. **Single-site only** - not tested for multisite WordPress installations
6. **No REST API** - all interactions via shortcodes/admin UI

### Integration Points

1. **WordPress hooks:**
   - `wp_head` - CSS injection, logout button
   - `wp_footer` - Logout button display
   - `body_class` - Add 'smartschool-retards' class
   - `init` - Logout handler
   - Custom cron: `ssr_daily_notifications_event`

2. **WordPress APIs used:**
   - $wpdb (database)
   - Options API
   - Transients API
   - Cron API
   - Shortcode API
   - Plugin API (hooks/filters)

3. **External dependencies:**
   - Smartschool SOAP V3 webservices
   - PHP SOAP extension

---

## Quick Reference

### File Locations

```
Main plugin: smartschool-retards.php
Constants: includes/constants.php
Auth: includes/auth.php
Database: includes/db.php
API: includes/api.php
Admin: includes/admin.php
Helpers: includes/helpers.php
Shortcodes: shortcodes/*.php
```

### Key Functions

```php
// Authentication
ssr_is_logged_in_pin()              // Check login status
ssr_current_verifier()              // Get current verifier info
ssr_pin_grant($id, $name)           // Create session
ssr_pin_revoke()                    // Destroy session

// Database
ssr_log($msg, $level, $context)     // Log event
ssr_db_maybe_create_tables()        // Create tables

// Helpers
ssr_sanitize_date($date)            // Sanitize date input
ssr_current_date_ymd()              // Get today's date (Y-m-d)
ssr_generate_nonce_field($action)   // Generate nonce field
ssr_verify_nonce($action)           // Verify nonce

// API
ssr_api($method, $params)           // Call SOAP method

// Detection
ssr_is_retards_page()               // Check if on plugin page
```

### Constants Reference

```php
// Paths
SSR_PLUGIN_FILE     // Main plugin file path
SSR_PLUGIN_DIR      // Plugin directory path
SSR_PLUGIN_URL      // Plugin URL
SSR_INC_DIR         // Includes directory
SSR_SC_DIR          // Shortcodes directory

// Tables
SSR_T_LOG           // wp_smartschool_retards_log
SSR_T_VERIF         // wp_smartschool_retards_verif
SSR_T_VERIFIERS     // wp_smartschool_retards_verifiers

// Options
SSR_OPT_ENDPOINT    // API endpoint URL
SSR_OPT_SENDER      // Sender identifier
SSR_OPT_DAILY_HHMM  // Cron time
SSR_OPT_TESTMODE    // Test mode flag
SSR_OPT_SOAP_URL    // SOAP URL
SSR_OPT_SOAP_ACCESSCODE // SOAP access code

// Auth
SSR_PIN_COOKIE           // Cookie name (ssr_pin_session)
SSR_PIN_SESSION_HOURS    // Session duration (8 hours)

// Cron
SSR_CRON_HOOK       // Cron hook name
```

---

## Git Development Workflow

**Current Branch:** `claude/claude-md-mivquasylby5rh13-01PYJNMSD9bZFFrbPB3PyqB3`

### Branch Naming Convention
All branches must start with `claude/` and end with the session ID to avoid 403 errors on push.

### Commit Guidelines
1. Write clear, descriptive commit messages
2. Use present tense ("Add feature" not "Added feature")
3. Reference issue numbers if applicable
4. Commit frequently with logical groupings

### Push Protocol
```bash
# Always use -u flag for first push
git push -u origin <branch-name>

# Retry on network errors (max 4 times with exponential backoff)
# 2s, 4s, 8s, 16s between retries
```

---

## Recent Changes

**Last refactoring (2 weeks ago):**
- Organized code into `/includes/` and `/shortcodes/` directories
- Separated each core functionality into its own file
- Made shortcodes self-registering
- Implemented audit trail for verification changes
- Added daily message templates

---

## Support & Documentation

**For questions about:**
- WordPress Plugin API: https://developer.wordpress.org/plugins/
- SOAP in PHP: https://www.php.net/manual/en/book.soap.php
- Smartschool API: Contact Smartschool support or check tenant documentation

**For plugin-specific issues:**
- Check logs: `wp_smartschool_retards_log` table
- Enable test mode: `update_option('ssr_test_mode', '1')`
- Review recent commits for changes

---

**End of CLAUDE.md**

*This document is maintained for AI assistant context. Update when making significant architectural changes.*
