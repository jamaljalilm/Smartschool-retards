<?php
if (!defined('ABSPATH')) exit;

/**
 * includes/cron.php
 * - Planification quotidienne via SSR_CRON_HOOK
 * - Replanification intelligente selon l'heure (WP timezone)
 * - Exécution avec respect du "test mode"
 * - Déclencheur manuel (WP-CLI & URL dev)
 */

/* ===================== Hook de tâche ===================== */
add_action(SSR_CRON_HOOK, 'ssr_cron_run_daily');

/* ===================== (Re)planification ===================== */
if (!function_exists('ssr_cron_maybe_reschedule_daily')) {
function ssr_cron_maybe_reschedule_daily(){
    $hook = SSR_CRON_HOOK;

    // Si déjà planifié, ne rien faire (tu peux forcer via ssr_cron_force_reschedule())
    if (wp_next_scheduled($hook)) {
        return;
    }

    $hhmm = ssr_get_option(SSR_OPT_DAILY_HHMM, '13:15');
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string)$hhmm)){
        $hhmm = '13:15';
    }

    $tz = wp_timezone(); // timezone WordPress
    $now = new DateTime('now', $tz);
    [$h,$m] = array_map('intval', explode(':',$hhmm));
    $run = (clone $now)->setTime($h, $m, 0);
    if ($run <= $now) { $run->modify('+1 day'); }

    // Timestamp UTC pour wp-cron
    $utc = new DateTimeZone('UTC');
    $runUtc = (clone $run)->setTimezone($utc);
    $ts = $runUtc->getTimestamp();

    wp_schedule_event($ts, 'daily', $hook);
    if (function_exists('ssr_log')) ssr_log('Cron scheduled @ '.$run->format('Y-m-d H:i:s T'), 'info', 'cron');
}}
/** Forcer une replanification (déschedule + schedule) */
if (!function_exists('ssr_cron_force_reschedule')) {
function ssr_cron_force_reschedule(){
    $hook = SSR_CRON_HOOK;
    $next = wp_next_scheduled($hook);
    if ($next) { wp_unschedule_event($next, $hook); }
    ssr_cron_maybe_reschedule_daily();
}}

/* ===================== Bootstrap: s’assure que c’est planifié ===================== */
add_action('plugins_loaded', function(){
    // Si rien n’est planifié (ex: après migration), planifie maintenant
    if (!wp_next_scheduled(SSR_CRON_HOOK)) {
        ssr_cron_maybe_reschedule_daily();
    }
}, 20);

/* ===================== Déclencheur manuel (dev) ===================== */
/* 1) via WP-CLI: wp cron event run ssr_send_daily_hook */
/* 2) via URL (admin connecté) : ?ssr_cron_run_now=1 */
add_action('admin_init', function(){
    if (!current_user_can('manage_options')) return;
    if (!empty($_GET['ssr_cron_run_now'])) {
        // Exécution inline (debug)
        ssr_cron_run_daily(true);
        wp_safe_redirect(remove_query_arg('ssr_cron_run_now'));
        exit;
    }
});

/* ===================== Tâche quotidienne ===================== */
if (!function_exists('ssr_cron_run_daily')) {
function ssr_cron_run_daily($manual=false){
    $test_mode = ssr_trueish(ssr_get_option(SSR_OPT_TESTMODE, '0'));
    $sender    = 'R001'; // Compte expéditeur Smartschool

    // Log de départ
    if (function_exists('ssr_log')) {
        ssr_log('Cron start (manual='.($manual?'yes':'no').', test_mode='.($test_mode?'1':'0').')', 'info', 'cron');
    }

    if ($test_mode){
        if (function_exists('ssr_log')) ssr_log('Cron skipped (test mode)', 'info', 'cron');
        return;
    }

    // ---- Exemple concret: envoyer un rappel aux élèves en retard aujourd'hui ----
    // Tu peux adapter la logique (filtrer AM/PM, limiter par classe, etc.)
    $date = (new DateTime('now', wp_timezone()))->format('Y-m-d');

    if (!function_exists('ssr_fetch_retards_by_date')) {
        if (function_exists('ssr_log')) ssr_log('ssr_fetch_retards_by_date manquante', 'error', 'cron');
        return;
    }

    // Récupérer les retards du jour (via HTTP puis fallback SOAP)
    $rows = ssr_fetch_retards_by_date($date);
    if (!is_array($rows) || empty($rows)) {
        if (function_exists('ssr_log')) ssr_log('Aucun retard à notifier pour '.$date, 'info', 'cron');
        return;
    }

    // Récupération du message personnalisé depuis les options
    $titleTpl = get_option('ssr_daily_message_title', 'Retard - Interdiction de sortir');
    $titleTpl = apply_filters('ssr_cron_message_title_tpl', $titleTpl);

    $bodyTpl = get_option('ssr_daily_message_body',
        "Bonjour,\n\ntu étais en retard aujourd'hui.\n\nMerci de venir te présenter demain pendant l'heure du midi au péron.\n\nMonsieur Khali"
    );
    $bodyTpl = apply_filters('ssr_cron_message_body_tpl', $bodyTpl);




    // Récupération des paramètres de destinataires
    $send_to_student = get_option('ssr_daily_send_to_student', '1');
    $send_to_parents = get_option('ssr_daily_send_to_parents', '1');

    // Envoi (sécurité : limiter à X envois / run pour éviter une rafale)
    $maxSends = 200; // ajuste si besoin
    $sent = 0;

    foreach ($rows as $r) {
        $uid = isset($r['userIdentifier']) ? $r['userIdentifier'] : '';
        if (!$uid) continue;

        // Récupération des informations de l'élève
        $prenom = isset($r['firstName']) ? $r['firstName'] : '';
        $nom = isset($r['lastName']) ? $r['lastName'] : '';
        $classe = isset($r['classCode']) ? $r['classCode'] : '';

        // Remplacement des variables dans le titre et le corps
        $title = $titleTpl;
        $title = str_replace('{prenom}', $prenom, $title);
        $title = str_replace('{nom}', $nom, $title);
        $title = str_replace('{classe}', $classe, $title);

        $body = $bodyTpl;
        $body = str_replace('{prenom}', $prenom, $body);
        $body = str_replace('{nom}', $nom, $body);
        $body = str_replace('{classe}', $classe, $body);

        if (function_exists('ssr_api_send_message')) {

            // 1) Élève : compte principal (coaccount = null)
            if ($send_to_student === '1') {
                $res = ssr_api_send_message($uid, $title, $body, $sender, null, null, true);
                if (is_wp_error($res)) {
                    if (function_exists('ssr_log')) ssr_log('Send FAIL (élève) uid='.$uid.' error='.$res->get_error_message(), 'error', 'cron');
                } else {
                    if (function_exists('ssr_log')) ssr_log('Send OK (élève) uid='.$uid, 'info', 'cron');
                    $sent++;
                }
            }

            // 2) Parents : coaccount 1 et 2
            if ($send_to_parents === '1') {
                for ($co = 1; $co <= 2; $co++) {
                    // On respecte aussi la limite max d'envois
                    if ($sent >= $maxSends) {
                        if (function_exists('ssr_log')) ssr_log('Stop: reached max sends ('.$maxSends.')', 'warning', 'cron');
                        break 2; // sort du foreach principal
                    }

                    $res_parent = ssr_api_send_message($uid, $title, $body, $sender, null, $co, true);
                    if (is_wp_error($res_parent)) {
                        if (function_exists('ssr_log')) ssr_log('Send FAIL (parent coaccount='.$co.') uid='.$uid.' error='.$res_parent->get_error_message(), 'error', 'cron');
                    } else {
                        if (function_exists('ssr_log')) ssr_log('Send OK (parent coaccount='.$co.') uid='.$uid, 'info', 'cron');
                        $sent++;
                    }
                }
            }
        }

        if ($sent >= $maxSends) {
            if (function_exists('ssr_log')) ssr_log('Stop: reached max sends ('.$maxSends.')', 'warning', 'cron');
            break;
        }
    }


    if (function_exists('ssr_log')) ssr_log('Cron end: sent='.$sent, 'info', 'cron');
}}
