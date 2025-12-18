<?php
if (!defined('ABSPATH')) exit;

/**
 * constants.php
 * - Centralise les noms de tables, clés d'options et hooks
 * - Protège contre les redéfinitions (if !defined)
 */

global $wpdb;

/* ===================== Tables ===================== */
if (!defined('SSR_T_LOG'))       define('SSR_T_LOG',       $wpdb->prefix . 'smartschool_retards_log');
if (!defined('SSR_T_VERIF'))     define('SSR_T_VERIF',     $wpdb->prefix . 'smartschool_retards_verif');
if (!defined('SSR_T_VERIFIERS')) define('SSR_T_VERIFIERS', $wpdb->prefix . 'smartschool_retards_verifiers');
if (!defined('SSR_T_SANCTIONS')) define('SSR_T_SANCTIONS', $wpdb->prefix . 'smartschool_retenues_sanctions');

/* ===================== Options (nouvelle UI) ===================== */
if (!defined('SSR_OPT_ENDPOINT'))   define('SSR_OPT_ENDPOINT',   'ssr_api_endpoint');      // endpoint HTTP (nouvelle API)
if (!defined('SSR_OPT_SENDER'))     define('SSR_OPT_SENDER',     'ssr_sender_identifier'); // identifiant expéditeur
if (!defined('SSR_OPT_DAILY_HHMM')) define('SSR_OPT_DAILY_HHMM', 'ssr_daily_hhmm');        // "13:15"
if (!defined('SSR_OPT_TESTMODE'))   define('SSR_OPT_TESTMODE',   'ssr_test_mode');         // "0"/"1"

/* ===================== Options (legacy SOAP V3) =====================
 * Compatibilité avec ta fonction ssr_api() historique qui lisait:
 *  - url
 *  - accesscode
 *  - hours
 */
if (!defined('SSR_OPT_SOAP_URL'))        define('SSR_OPT_SOAP_URL',        'url');
if (!defined('SSR_OPT_SOAP_ACCESSCODE')) define('SSR_OPT_SOAP_ACCESSCODE', 'accesscode');
if (!defined('SSR_OPT_SOAP_HOURS'))      define('SSR_OPT_SOAP_HOURS',      'hours');

/* ===================== Divers ===================== */
if (!defined('SSR_OPT_PIN_HASH')) define('SSR_OPT_PIN_HASH', 'ssr_verif_pin_hash'); // si encore utilisé quelque part

/* ===================== Cron ===================== */
if (!defined('SSR_CRON_HOOK')) define('SSR_CRON_HOOK', 'ssr_daily_notifications_event');
