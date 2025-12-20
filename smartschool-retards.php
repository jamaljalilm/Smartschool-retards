<?php
/*
Plugin Name: Smartschool – Retards 2
Description: Gestion des retards Smartschool (version modulée en fichiers). Sécurité renforcée (nonces, sanitization), cron quotidien, shortcodes.
Version: 1.0.0
Author: INDL
Text Domain: smartschool-retards
*/

if (!defined('ABSPATH')) exit;

// Define plugin path constants
define('SSR_PLUGIN_FILE', __FILE__);
define('SSR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SSR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SSR_INC_DIR', SSR_PLUGIN_DIR . 'includes/');
define('SSR_SC_DIR', SSR_PLUGIN_DIR . 'shortcodes/');

// Load core parts (order matters)
require_once SSR_INC_DIR . 'constants.php';
require_once SSR_INC_DIR . 'helpers.php';
require_once SSR_INC_DIR . 'security.php';
require_once SSR_INC_DIR . 'auth.php';
require_once SSR_INC_DIR . 'db.php';
require_once SSR_INC_DIR . 'api.php';
require_once SSR_INC_DIR . 'cron.php';
require_once SSR_INC_DIR . 'admin-message-test.php';
require_once SSR_INC_DIR . 'admin-daily-message-config.php';
require_once SSR_INC_DIR . 'admin-message-history.php';
require_once SSR_INC_DIR . 'admin-view-logs.php';
require_once SSR_INC_DIR . 'admin-migration.php';
require_once SSR_INC_DIR . 'admin.php';

// Load shortcodes (each file registers its own shortcode tag)
require_once SSR_SC_DIR . 'fiche_eleve.php';
require_once SSR_SC_DIR . 'recap_calendrier.php';
require_once SSR_SC_DIR . 'recap_retards.php';
require_once SSR_SC_DIR . 'ssr_login.php';
require_once SSR_SC_DIR . 'ssr_nav.php';
require_once SSR_SC_DIR . 'ssr_suivi.php';
require_once SSR_SC_DIR . 'retards_verif.php';
require_once SSR_SC_DIR . 'liste_retenues.php';

// Activation / deactivation hooks
register_activation_hook(__FILE__, 'ssr_activate_segmented');
register_deactivation_hook(__FILE__, 'ssr_deactivate_segmented');

function ssr_activate_segmented(){
    ssr_db_maybe_create_tables();
    ssr_db_migrate_column_names();
    ssr_db_add_status_raw_column();
    ssr_cron_maybe_reschedule_daily();
}

// Exécuter les migrations aussi lors du chargement de l'admin (au cas où)
add_action('admin_init', function() {
    ssr_db_migrate_column_names();
    ssr_db_add_status_raw_column();
});

function ssr_deactivate_segmented(){
    $hook = SSR_CRON_HOOK;
    $timestamp = wp_next_scheduled($hook);
    if($timestamp){
        wp_unschedule_event($timestamp, $hook);
    }
}

// Minimal front-end CSS if needed
add_action('wp_head', function(){
    echo '<style>.ssr-notice{padding:12px;border-radius:8px;margin:10px 0;background:#f5f5f7} .ssr-error{background:#fdecea;color:#b00020} .ssr-ok{background:#e8f5e9;color:#1b5e20}</style>';
});

// ===== Pages cibles : slugs + shortcodes du module "retards"
if (!function_exists('ssr_is_retards_page')) {
    function ssr_is_retards_page() {
        if (is_admin()) return false;

        // Slugs exacts de tes pages
        $slugs = [
            'retards-verif',
            'recap-retards',
            'retenues',
            'suivi',
            'calendrier',
            'connexion-verificateur',
        ];

        // Si on est sur l'une de ces pages
        if (is_page($slugs)) return true;

        // Détection par shortcodes (utile si tu utilises une seule page "fourre-tout")
        if (is_singular()) {
            $post = get_post();
            if ($post && !empty($post->post_content)) {
                $sc_list = [
                    'retards_verif',
                    'ssr_recap_retards',
                    'ssr_retenues',
                    'ssr_suivi',
                    'ssr_calendrier',
                    'ssr_login_verificateur',
                ];
                foreach ($sc_list as $sc) {
                    if (has_shortcode($post->post_content, $sc)) return true;
                }
            }
        }

        return false;
    }
}

register_deactivation_hook(__FILE__, function(){
    $hook = SSR_CRON_HOOK;
    $next = wp_next_scheduled($hook);
    if ($next) wp_unschedule_event($next, $hook);
});


// ===== Ajoute une classe <body> pour cibler facilement en CSS
add_filter('body_class', function ($classes) {
    if (ssr_is_retards_page()) {
        $classes[] = 'smartschool-retards';
    }
    return $classes;
});
// dans ton loader principal
add_filter('ssr_soap_date', function($d){
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt ? $dt->format('d/m/Y') : $d;
});
add_filter('ssr_soap_absents_method', fn() => 'getAbsentsWithInternalNumberByDate'); // par défaut
// ou, si la doc de ton tenant mentionne une autre méthode :
/* add_filter('ssr_soap_absents_method', fn() => 'getLatesWithInternalNumberByDate'); */

// Bouton "Déconnexion" — CSS global
add_action('wp_head', function () {
    if (is_admin()) return;
    ?>
    <style id="ssr-logout-floating-css">
      .ssr-logout-floating{
        position: fixed; top:12px; right:12px; z-index: 9999;
        background:#f57c00;            /* orange */
        color:#fff;                    /* texte blanc */
        padding:8px 12px;
        border-radius:10px; font-weight:800; text-decoration:none;
        box-shadow:0 4px 14px rgba(0,0,0,.12);
        border:1px solid #e78322;      /* bordure orange un peu plus foncée */
        transition: color .15s ease, box-shadow .15s ease;
      }
      .ssr-logout-floating:hover{
        background:#f57c00;            /* reste orange */
        color:#111;                    /* texte gris/noir */
        box-shadow:0 6px 18px rgba(0,0,0,.16);
      }
      @media (max-width:640px){
        .ssr-logout-floating{ padding:6px 10px; font-size:13px; }
      }
    </style>
    <?php
});


// Affiche le bouton sur toutes les pages "retards" SAUF la page login
add_action('wp_footer', function () {
    if (is_admin()) return;
    if (!function_exists('ssr_is_logged_in_pin') || !ssr_is_logged_in_pin()) return;
    if (!function_exists('ssr_is_retards_page') || !ssr_is_retards_page()) return;

    // Exclure explicitement la page "connexion-verificateur"
    if (is_page('connexion-verificateur')) return;

    global $post;
    if ($post instanceof WP_Post) {
        // Si la page contient le shortcode de login, on n’affiche pas
        if (has_shortcode($post->post_content ?? '', 'ssr_login')) return;
        // (ajoute d'autres shortcodes de login si besoin)
        // if (has_shortcode($post->post_content ?? '', 'ssr_login_verificateur')) return;
    }

    // URL de logout sur la page courante
    $scheme = is_ssl() ? 'https://' : 'http://';
    $here   = $scheme . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    $logout_url = add_query_arg('ssr_logout', 1, $here);

    echo '<a class="ssr-logout-floating" href="' . esc_url($logout_url) . '">Déconnexion</a>';
});

// Rendre ?ssr_logout=1 fonctionnel PARTOUT (pas seulement sur la page login)
add_action('init', function () {
    if (isset($_GET['ssr_logout']) && function_exists('ssr_pin_revoke')) {
        ssr_pin_revoke();
        // Retour sur la même page, sans les paramètres
        $scheme = is_ssl() ? 'https://' : 'http://';
        $here   = $scheme . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
        $back   = remove_query_arg(['ssr_logout','ssr_logged','ssr_err'], $here);
        wp_safe_redirect($back ?: home_url('/'));
        exit;
    }
});

// ===== Injecte le CSS tardivement pour dépasser le thème
add_action('wp_head', function () {
    if (!ssr_is_retards_page()) return;
    ?>
    <style id="ssr-retards-hide-chrome">
    /* Cache les en-têtes/menus du thème, mais pas ta nav interne */
    body.smartschool-retards .page-header,
    body.smartschool-retards .entry-header,
    body.smartschool-retards .site-header,
    body.smartschool-retards header.site-header,
    body.smartschool-retards .navbar,
    body.smartschool-retards .main-navigation,
    body.smartschool-retards header .menu,
    body.smartschool-retards .site-footer,
    body.smartschool-retards footer,
    body.smartschool-retards .footer,
    body.smartschool-retards #cookie-law-info-bar,
    body.smartschool-retards .cmplz-cookiebanner,
    /* Block themes (FSE) — uniquement dans le header */
    body.smartschool-retards .wp-site-blocks > header,
    body.smartschool-retards header .wp-block-navigation,
    body.smartschool-retards header.wp-block-template-part,
    body.smartschool-retards .wp-block-template-part.wp-block-template-part-header,
    /* Elementor header/footer */
    body.smartschool-retards .elementor-location-header,
    body.smartschool-retards .elementor-location-footer,
    /* Bandeaux de titre fréquents */
    body.smartschool-retards .hero,
    body.smartschool-retards .page-hero,
    body.smartschool-retards .page-title,
    body.smartschool-retards h1.entry-title {
        display: none !important;
        visibility: hidden !important;
        height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
    }

    /* Ne *pas* masquer les <nav> dans le contenu (où se trouve [ssr_nav]) */
    /* -> On a retiré le sélecteur global "nav" du masque ci-dessus */

    /* Ré-affiche explicitement la nav du shortcode si elle a la classe .ssr-nav */
    body.smartschool-retards .ssr-nav {
        display: block !important;
        visibility: visible !important;
        height: auto !important;
        margin: 0 0 16px 0 !important;
        padding: 0 !important;
        overflow: visible !important;
    }

    /* Optionnel : remonter le contenu principal si le thème réserve un offset */
    body.smartschool-retards .site-content,
    body.smartschool-retards .content-area,
    body.smartschool-retards main#primary,
    body.smartschool-retards main {
        margin-top: 0 !important;
        padding-top: 0 !important;
    }
    </style>
    <?php
}, 99);

