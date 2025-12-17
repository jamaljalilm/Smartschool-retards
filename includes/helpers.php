<?php
if (!defined('ABSPATH')) exit;

/**
 * Helpers génériques (sans UI, sans shortcode)
 * Utilisés par admin, shortcodes et cron.
 */

/* ============ Options utils ============ */
if (!function_exists('ssr_get_option')) {
  function ssr_get_option($key, $default = '') { return get_option($key, $default); }
}
if (!function_exists('ssr_set_option')) {
  function ssr_set_option(array $pairs){ foreach($pairs as $k=>$v){ update_option($k, $v); } }
}
if (!function_exists('ssr_trueish')) {
  function ssr_trueish($v){ if (is_bool($v)) return $v; $v=strtolower(trim((string)$v)); return in_array($v,['1','true','yes','y','on'],true); }
}

/* ============ Sanitize helpers ============ */
if (!function_exists('ssr_sanitize_checkbox')) {
  function ssr_sanitize_checkbox($v){ return !empty($v) ? '1' : '0'; }
}
if (!function_exists('ssr_sanitize_time_hhmm')) {
  function ssr_sanitize_time_hhmm($v){
    $v = trim((string)$v);
    return preg_match('/^(2[0-3]|[01]\d):([0-5]\d)$/', $v) ? $v : '13:15';
  }
}

/* ============ KSES whitelist pour notices ============ */
if (!function_exists('ssr_kses_html_notice')) {
  function ssr_kses_html_notice($html){
    $allowed = [
      'p'=>['style'=>true],'br'=>[],'b'=>['style'=>true],'strong'=>['style'=>true],
      'i'=>['style'=>true],'em'=>['style'=>true],'u'=>['style'=>true],'span'=>['style'=>true],
      'div'=>['style'=>true],'ul'=>['style'=>true],'ol'=>['style'=>true],'li'=>['style'=>true],
      'a'=>['href'=>true,'target'=>true,'rel'=>true,'style'=>true],
    ];
    return wp_kses((string)$html, $allowed);
  }
}

/* ============ Dates utilitaires (Europe/Brussels) ============ */
if (!function_exists('ssr_today_be')) {
  function ssr_today_be(){
    $tz = new DateTimeZone('Europe/Brussels');
    $d  = new DateTime('now', $tz);
    $d->setTime(0,0,0);
    return $d;
  }
}


/* ============ Text utils ============ */
if (!function_exists('ssr_lines_to_set')) {
  // "YYYY-MM-DD" par ligne -> set associatif ['2025-10-10'=>1, ...]
  function ssr_lines_to_set($txt){
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$txt) as $line) {
      $d = trim($line);
      if ($d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $out[$d]=1;
    }
    return $out;
  }
}

/* ============ Calendrier (admin + shortcode) ============ */
if (!function_exists('ssr_cal_get_settings')) {
  function ssr_cal_get_settings(){
    $opt = get_option('ssr_cal_settings', []);
    $defaults = [
      'start_date'     => '',
      'end_date'       => '',
      'hide_weekends'  => '0',
      'hide_wed'       => '0',
      'holidays'       => '',
      'blanks'         => '',
    ];
    $opt = wp_parse_args(is_array($opt)? $opt : [], $defaults);
    $opt['start_date']    = preg_match('/^\d{4}-\d{2}-\d{2}$/',$opt['start_date']) ? $opt['start_date'] : '';
    $opt['end_date']      = preg_match('/^\d{4}-\d{2}-\d{2}$/',$opt['end_date'])   ? $opt['end_date']   : '';
    $opt['hide_weekends'] = $opt['hide_weekends'] ? '1' : '0';
    $opt['hide_wed']      = $opt['hide_wed']      ? '1' : '0';
    return $opt;
  }
}
if (!function_exists('ssr_cal_is_empty_day')) {
  // Masquer le jour dans le calendrier (jamais masquer "aujourd'hui")
  function ssr_cal_is_empty_day(string $dateStr, string $todayStr){
    $cfg = ssr_cal_get_settings();
    if ($dateStr === $todayStr) return false;

    // Hors plage
    if ($cfg['start_date'] && $dateStr < $cfg['start_date']) return true;
    if ($cfg['end_date']   && $dateStr > $cfg['end_date'])   return true;

    // Jours de semaine
    $N = (int)date('N', strtotime($dateStr)); // 1..7 (Lun..Dim)
    if ($cfg['hide_wed'] === '1' && $N === 3) return true;
    if ($cfg['hide_weekends'] === '1' && ($N === 6 || $N === 7)) return true;

    // Listes à la ligne -> sets
    $holidays = ssr_lines_to_set($cfg['holidays']);
    $blanks   = ssr_lines_to_set($cfg['blanks']);
    if (isset($holidays[$dateStr])) return true;
    if (isset($blanks[$dateStr]))   return true;

    return false;
  }
}

// Force une date au format Y-m-d depuis 'Y-m-d', 'd/m/Y' ou autre parsable
if (!function_exists('ssr_to_ymd')) {
function ssr_to_ymd($s){
    $s = trim((string)$s);
    if ($s === '') return date('Y-m-d');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;           // déjà OK
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $s)) {                  // dd/mm/YYYY
        [$d,$m,$y] = explode('/', $s);
        return sprintf('%04d-%02d-%02d', (int)$y, (int)$m, (int)$d);
    }
    $dt = DateTime::createFromFormat('!d/m/Y', $s) ?: DateTime::createFromFormat('!Y-m-d', $s) ?: new DateTime($s);
    return $dt ? $dt->format('Y-m-d') : date('Y-m-d');
}}

/**
 * Retourne les dates à vérifier pour la page "Vérifier retards"
 * Logique métier :
 * - Lundi : [vendredi dernier]
 * - Mardi : [lundi]
 * - Mercredi : [] (pas de retards le mercredi)
 * - Jeudi : [mardi, mercredi] (vérification des deux jours précédents)
 * - Vendredi : [jeudi]
 * - Samedi/Dimanche : [] (pas de retards le week-end)
 *
 * @return array Liste de dates au format Y-m-d
 */
if (!function_exists('ssr_prev_days_for_check')) {
function ssr_prev_days_for_check() {
    $tz = new DateTimeZone('Europe/Brussels');
    $today = new DateTime('now', $tz);
    $dow = (int)$today->format('N'); // 1=lundi, 2=mardi, ..., 7=dimanche

    $dates = [];

    switch ($dow) {
        case 1: // Lundi → Vendredi dernier
            $prev = clone $today;
            $prev->modify('-3 days');
            $dates[] = $prev->format('Y-m-d');
            break;

        case 2: // Mardi → Lundi
            $prev = clone $today;
            $prev->modify('-1 day');
            $dates[] = $prev->format('Y-m-d');
            break;

        case 3: // Mercredi → Aucun retard
            $dates = [];
            break;

        case 4: // Jeudi → Mardi ET Mercredi
            $mardi = clone $today;
            $mardi->modify('-2 days');
            $dates[] = $mardi->format('Y-m-d');

            $mercredi = clone $today;
            $mercredi->modify('-1 day');
            $dates[] = $mercredi->format('Y-m-d');
            break;

        case 5: // Vendredi → Jeudi
            $prev = clone $today;
            $prev->modify('-1 day');
            $dates[] = $prev->format('Y-m-d');
            break;

        case 6: // Samedi → Aucun retard
        case 7: // Dimanche → Aucun retard
            $dates = [];
            break;
    }

    return $dates;
}}
