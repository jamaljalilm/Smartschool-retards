<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('ssr_get_all_students')) {
  /**
   * Fallback multi-stratégies pour récupérer les élèves
   * Retourne des entrées: user_identifier, class_code, last_name, first_name
   */
  function ssr_get_all_students(){
    ssr_log('DEBUG ssr_get_all_students: Début de la fonction', 'info', 'recap');

    // 0) Hook/Provider externe (si toi/ton plugin en expose un)
    if (function_exists('ssr_students_provider')) {
      $out = ssr_students_provider();
      if (is_array($out) && !empty($out)) {
        ssr_log('DEBUG ssr_get_all_students: Retour via provider externe (' . count($out) . ' élèves)', 'info', 'recap');
        return $out;
      }
    }

    global $wpdb;
    $out = [];

    // 1) Table "comptes" si présente (adapte le nom si tu en as une autre)
    $t_accounts = $wpdb->prefix . 'smartschool_accounts';
    $has_accounts = $wpdb->get_var($wpdb->prepare(
      "SHOW TABLES LIKE %s", $wpdb->esc_like($t_accounts)
    )) === $t_accounts;

    ssr_log('DEBUG ssr_get_all_students: Table accounts existe? ' . ($has_accounts ? 'OUI' : 'NON'), 'info', 'recap');

    if ($has_accounts) {
      $rows = $wpdb->get_results("
        SELECT
          CAST(user_identifier AS CHAR)   AS user_identifier,
          CAST(class_code AS CHAR)        AS class_code,
          CAST(last_name AS CHAR)         AS last_name,
          CAST(first_name AS CHAR)        AS first_name
        FROM {$t_accounts}
        WHERE (user_identifier IS NOT NULL AND user_identifier <> '')
      ", ARRAY_A);
      ssr_log('DEBUG ssr_get_all_students: Query accounts retourné ' . ($rows ? count($rows) : 0) . ' lignes', 'info', 'recap');
      if ($rows) return $rows;
    }

    // 2) Fallback via table des vérifs → DISTINCT (couvre au moins ceux connus par les retards)
    $t_verif = $wpdb->prefix . 'smartschool_retards_verif';
    $has_verif = $wpdb->get_var($wpdb->prepare(
      "SHOW TABLES LIKE %s", $wpdb->esc_like($t_verif)
    )) === $t_verif;

    ssr_log('DEBUG ssr_get_all_students: Table verif existe? ' . ($has_verif ? 'OUI' : 'NON'), 'info', 'recap');

    if ($has_verif) {
      $rows = $wpdb->get_results("
        SELECT DISTINCT
          CAST(user_identifier AS CHAR)   AS user_identifier,
          CAST(class_code AS CHAR)        AS class_code,
          CAST(last_name AS CHAR)         AS last_name,
          CAST(first_name AS CHAR)        AS first_name
        FROM {$t_verif}
        WHERE (user_identifier IS NOT NULL AND user_identifier <> '')
      ", ARRAY_A);
      ssr_log('DEBUG ssr_get_all_students: Query verif retourné ' . ($rows ? count($rows) : 0) . ' lignes', 'info', 'recap');
      if ($rows) return $rows;
    }

    // 3) Dernier filet: vide (mais évite le fatal)
    ssr_log('DEBUG ssr_get_all_students: Aucune source de données trouvée, retour tableau vide', 'warning', 'recap');
    return [];
  }
}

// ==== AJAX: fiche élève (JSON) ====
add_action('wp_ajax_ssr_fetch_fiche_eleve', 'ssr_fetch_fiche_eleve_cb');
add_action('wp_ajax_nopriv_ssr_fetch_fiche_eleve', 'ssr_fetch_fiche_eleve_cb');

function ssr_fetch_fiche_eleve_cb(){
    // Auth custom si dispo
    if (function_exists('ssr_is_logged_in_pin') && !ssr_is_logged_in_pin()){
        wp_send_json_error(array('message' => 'unauthorized'), 403);
    }

    // Nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ssr_fiche')) {
        wp_send_json_error(array('message'=>'bad nonce'), 400);
    }

    // ID élève
    $uid = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
    if (!$uid) {
        wp_send_json_error(array('message'=>'missing id'), 400);
    }

    // -------- Infos utilisateur (robuste) --------
    $user = array();
    if (function_exists('ssr_api')) {
        $user = ssr_api("getUserDetailsByNumber", array("internalNumber" => $uid));
        if (!is_array($user)) $user = array();
    }
    $last_name  = isset($user['naam']) ? $user['naam'] : (isset($user['last_name']) ? $user['last_name'] : '');
    $first_name = isset($user['voornaam']) ? $user['voornaam'] : (isset($user['first_name']) ? $user['first_name'] : '');
    $name = trim($last_name . ' ' . $first_name);

    $class = '';
    if (function_exists('ssr_extract_official_class_from_user')) {
        $class = ssr_extract_official_class_from_user($user);
    }
    if (!$class && isset($user['class'])) $class = $user['class'];

 // -------- Données vérif depuis la DB --------
global $wpdb;
$ver = $wpdb->prefix . "smartschool_retards_verif";
$has_verif = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($ver))) === $ver;

$rows = array();
if ($has_verif) {
    $rs = $wpdb->get_results(
        $wpdb->prepare("
            SELECT date_retard, status, verified_at, verified_by_name
            FROM {$ver}
            WHERE user_identifier = %s
            ORDER BY date_retard DESC, verified_at DESC
        ", $uid),
        ARRAY_A
    );
    // déduplication par date_retard (garde la plus récente)
    $byDate = array();
    foreach ((array)$rs as $r) {
        $d = isset($r['date_retard']) ? $r['date_retard'] : '';
        if ($d && !isset($byDate[$d])) {
            $byDate[$d] = $r;
        }
    }
    $rows = array_values($byDate);
}

// -------- Construction sortie JSON --------
$rows_out = array();

foreach ($rows as $r) {
    $date_retard = isset($r['date_retard']) ? $r['date_retard'] : '';
    $verified_at = isset($r['verified_at']) ? $r['verified_at'] : '';

    // Normaliser la date du retard au format Y-m-d
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$date_retard)) {
        $date_retard = substr($date_retard, 0, 10);
    } else {
        $ts = strtotime($date_retard);
        if ($ts) $date_retard = date('Y-m-d', $ts);
    }

    $rows_out[] = array(
        'date'   => $date_retard,                                   // Date du retard
        'status' => isset($r['status']) ? $r['status'] : '',        // Présent/absent
        'by'     => !empty($r['verified_by_name']) ? $r['verified_by_name'] : '', // Vérifié par
        'at'     => !empty($verified_at) ? $verified_at : '',       // Vérifié le (timestamp complet)
    );
}

// -------- Réponse --------
wp_send_json_success(array(
    'id'    => $uid,
    'name'  => $name ? $name : ('Élève ' . $uid),
    'class' => $class ? $class : '—',
    'rows'  => $rows_out,
));

}
add_shortcode('recap_retards', function($atts){
	if (!ssr_is_logged_in_pin()) {
		if (!function_exists('ssr_is_editor_context') || !ssr_is_editor_context()) {
			$target = add_query_arg('redirect_to', rawurlencode(ssr_current_url()), home_url('/connexion-verificateur/'));
			wp_safe_redirect($target);
			exit;
		}
		return '';
	}
    $a = shortcode_atts([
        'fiche' => '/fiche-eleve',
        'placeholder' => 'Rechercher un élève (nom, prénom, classe)…',
        'default_open' => '0',
    ], $atts, 'recap_retards');

    $fiche_url = esc_url(home_url($a['fiche']));
    $students = ssr_get_all_students();

    // Debug logging
    ssr_log('DEBUG recap_retards: Nombre d\'élèves récupérés = ' . count($students), 'info', 'recap');
    if (empty($students)) {
        ssr_log('DEBUG recap_retards: AUCUN élève trouvé!', 'warning', 'recap');
    }

    // Regrouper par classe
    $byClass = [];
    foreach ($students as $s) {
        $cls = $s['class_code'] ?: '—';
        $byClass[$cls][] = $s;
    }
    ksort($byClass, SORT_NATURAL | SORT_FLAG_CASE);

    $open_all = !empty($a['default_open']) && $a['default_open'] !== '0';
$ajax_url = admin_url('admin-ajax.php');
$nonce    = wp_create_nonce('ssr_fiche');
// choisis la source qui te convient :
$convoc   = isset($a['convocation']) ? (string)$a['convocation'] : '13:15';
// ou si tu n’as pas d’attribut : $convoc = '13:15';



    ob_start(); ?>

<!-- ===== Modal fiche élève ===== -->
<div id="ssr-fiche-overlay" class="ssr-fiche-overlay" aria-hidden="true">
  <div class="ssr-fiche-backdrop" data-close="1"></div>
  <div class="ssr-fiche-dialog" role="dialog" aria-modal="true" aria-labelledby="ssr-fiche-title">
    <button class="ssr-fiche-close" type="button" aria-label="Fermer" data-close="1">×</button>
    <div class="ssr-fiche-body">
      <div class="ssr-fiche-header">
        <h3 id="ssr-fiche-title" class="ssr-fiche-name">Chargement…</h3>
        <div class="ssr-fiche-meta">Classe <span class="v-class">—</span> • ID <span class="v-id">—</span></div>
      </div>
      <div class="ssr-fiche-table-wrap">
        <table class="ssr-table">
			<thead>
				<tr>
					<th>Date du retard</th>
					<th>Date vérif</th>
					<th>Présent ?</th>
					<th>Vérifié par</th>
					<th>Vérifié le</th>
				</tr>
			</thead>
          <tbody class="ssr-fiche-rows">
            <tr><td colspan="5" style="text-align:center;color:#666;">Chargement…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>


    <div class="ssr-eleves-wrap" style="max-width:980px;margin:0 auto;font-family:system-ui, sans-serif;">

        <style>
            :root{
                --ssr-sticky-top:0px;      /* admin bar offset */
                --ssr-sticky-height:130px; /* hauteur du bloc sticky dynamique */
            }

            /* ===== Sticky unifié (titre + recherche + boutons + sommaire) ===== */
            .ssr-sticky{
                position: sticky;
                top: 0;
				background: #fff;
                z-index: 999;
                border-bottom:1px solid #eee;
                padding:0px 0 8px;
            }
            /* Fallback fixed si sticky cassé */
            .ssr-sticky.is-fixed{
                position: fixed;
                top: var(--ssr-sticky-top, 0);
                left: 50%;
				background: #fff;
                transform: translateX(-50%);
                width: min(980px, calc(100% - 32px));
                box-shadow: 0 2px 6px rgba(0,0,0,.06);
            }
            #ssr-sticky-spacer{ height:0; }

            /* Visually hidden (ARIA live region) */
            .sr-only{
                position:absolute !important;
                width:1px;height:1px;margin:-1px;padding:0;border:0;
                clip:rect(0 0 0 0);clip-path:inset(50%);overflow:hidden;
                white-space:nowrap;
            }

            /* Titre */
            .ssr-title{ text-align:center;font-size:26px;font-weight:700;color:#f57c00;margin:8px 0 10px; }

            /* Toolbar */
            .ssr-toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin:0 0 10px;}
            .ssr-input-wrap{position:relative;flex:1 1 360px;min-width:260px;}
            .ssr-input{width:100%;padding:12px 14px;border:1.5px solid #d1d5db;border-radius:8px;font-size:16px;background:#f9fafb;}
            .ssr-actions{display:flex;gap:8px;}
            @media (max-width: 640px){
                .ssr-toolbar{align-items:stretch;}
                .ssr-actions{width:100%;justify-content:center;}
            }

            /* Boutons (couleur unifiée + état actif orange pâle) */
            .ssr-btn{padding:10px 16px;border:1px solid transparent;border-radius:6px;background:#4b5563;color:#fff;font-weight:600;cursor:pointer;transition:all .2s;}
            .ssr-btn:hover{filter:brightness(1.05);}
            .ssr-btn[aria-pressed="true"]{
                background:#fff7ed;
                color:#7c2d12;
                border-color:#f57c00;
                box-shadow:inset 0 0 0 2px rgba(245,124,0,.15);
            }

            /* Sommaire (dans le sticky) */
            .ssr-summary{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin:6px 0 2px;padding-bottom:4px;}
            .ssr-chip{padding:6px 10px;border:1px solid #ddd;border-radius:6px;background:#f3f4f6;color:#333;text-decoration:none;font-size:14px;transition:all .2s;}
            .ssr-chip:hover{background:#e5e7eb;}
            .ssr-chip.is-open{border-color:#f57c00;background:#fff7ed;color:#7c2d12;}
            .ssr-chip[role="button"]{outline:none;}
            .ssr-chip:focus{box-shadow:0 0 0 3px rgba(245,124,0,.25);}

            /* Groupes + entêtes (compense la hauteur sticky quand on scrolle vers eux) */
            .ssr-class-group{
                margin:10px 0;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#fff;
                scroll-margin-top: calc(var(--ssr-sticky-top, 0px) + var(--ssr-sticky-height, 130px) + 8px);
            }
            .ssr-class-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;padding:14px 16px;font-size:18px;font-weight:600;color:#111;background:#f3f4f6;border:0;cursor:pointer;transition:background .2s;}
            .ssr-class-toggle:hover{background:#e5e7eb;}

            /* Accordéon animé (slide) */
            .ssr-class-panel{
                overflow:hidden;
                max-height:0;
                transition:max-height .25s ease;
                padding:0 16px; /* padding latéral conservé */
                background:#fff;
            }
            .ssr-class-panel.open{
                /* max-height contrôlée en JS (scrollHeight) */
                padding-bottom:12px;
                padding-top:12px;
            }

            /* Cartes élève */
            .ssr-grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px;}
            .ssr-card{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #eee;border-radius:8px;text-decoration:none;color:#222;background:#fafafa;transition:background .2s;}
            .ssr-card:hover{background:#f3f4f6;}

            /* Auto-complétion */
            .ssr-suggest{
                position:absolute;left:0;right:0;top:calc(100% + 6px);
                border:1px solid #e5e7eb;border-radius:8px;background:#fff;
                box-shadow:0 8px 20px rgba(0,0,0,.08);
                max-height:300px;overflow:auto;z-index:1000;
            }
            .ssr-s-item{padding:10px 12px;display:flex;justify-content:space-between;gap:8px;cursor:pointer;}
            .ssr-s-item:hover, .ssr-s-item.active{background:#f3f4f6;}
            .ssr-s-name{font-weight:600;color:#111;}
            .ssr-s-meta{color:#666;font-size:12px;}
            .ssr-s-empty{padding:10px 12px;color:#888;}
            .ssr-hl{color:#f57c00;font-weight:700;} /* highlight orange */
			
/* ===== Modal fiche ===== */
/* l'overlay ne doit pas capter les clics quand il est fermé */
.ssr-fiche-overlay{ position:fixed; inset:0; display:none; z-index:9999; pointer-events:none; }
.ssr-fiche-overlay.open{ display:block; pointer-events:auto; }
.ssr-fiche-backdrop{position:absolute;inset:0;background:rgba(17,24,39,.35);backdrop-filter:blur(4px);}
.ssr-fiche-dialog{
  position:relative;margin:0 auto;top:50%;transform:translateY(-50%);
  background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.2);
  width:min(720px, 92vw); max-height:90vh; overflow:auto; padding:16px 16px 12px;
}
@media (max-width:640px){ .ssr-fiche-dialog{ width:90vw; max-height:90vh; } }
.ssr-fiche-close{
  position:absolute;right:10px;top:8px;border:0;background:#f3f4f6;border-radius:8px;cursor:pointer;
  font-size:22px;line-height:1;padding:4px 10px;color:#333;
}
.ssr-fiche-close:hover{background:#e5e7eb}
.ssr-fiche-header{margin-bottom:10px}
.ssr-fiche-name{margin:0 28px 2px 0;font-size:22px;font-weight:800}
.ssr-fiche-meta{color:#666}
.ssr-fiche-table-wrap{overflow:auto;max-height:calc(90vh - 140px)}


/* Désactiver le blur sur smartphone */
@media (max-width: 640px){
  .ssr-fiche-backdrop{ backdrop-filter:none !important; }
}

/* Taille un peu plus petite dans la carte */
.ssr-fiche-dialog{ font-size:14px; }
.ssr-fiche-name{ font-size:20px; }
.ssr-fiche-meta{ font-size:13px; }

/* Tableau compact, centré + zébré */
.ssr-table{ width:100%; border-collapse:collapse; }
.ssr-table th, .ssr-table td{
  padding:8px 10px;
  text-align:center;        /* horizontal */
  vertical-align:middle;    /* vertical */
}
.ssr-table thead th{ font-weight:700; } /* titres en gras */
.ssr-table tbody tr:nth-child(odd){ background:#fafafa; } /* zébrage */

        </style>

        <!-- ===== Sticky unifié ===== -->
        <div class="ssr-sticky" id="ssr-sticky">
            <h2 class="ssr-title">Récapitulatif des élèves par classe</h2>

            <div class="ssr-toolbar">
                <div class="ssr-input-wrap">
                    <input id="ssr-search" type="search"
                        placeholder="<?php echo esc_attr($a['placeholder']); ?>"
                        class="ssr-input"
                        aria-label="Recherche d'élève"
                        autocomplete="off"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-owns="ssr-suggest"
                        aria-haspopup="listbox"
                        aria-activedescendant="">
                    <div id="ssr-suggest" class="ssr-suggest" style="display:none;" role="listbox"></div>
                    <div id="ssr-sr-status" class="sr-only" aria-live="polite" aria-atomic="true"></div>
                </div>

                <div class="ssr-actions">
                    <button id="ssr-expand-all" type="button"
                            class="ssr-btn"
                            aria-pressed="<?php echo $open_all ? 'true':'false'; ?>">
                        ➕ Déplier tout
                    </button>
                    <button id="ssr-collapse-all" type="button"
                            class="ssr-btn"
                            aria-pressed="<?php echo $open_all ? 'false':'true'; ?>">
                        ➖ Replier tout
                    </button>
                </div>
            </div>

            <!-- Sommaire dans le sticky -->
            <div id="ssr-summary" class="ssr-summary" role="group" aria-label="Classes">
                <?php foreach ($byClass as $cls => $_): ?>
                    <a href="#ssr-group-<?php echo md5($cls); ?>"
                       class="ssr-chip"
                       data-target="ssr-group-<?php echo md5($cls); ?>"
                       role="button"
                       tabindex="0"
                       aria-pressed="false">
                        <?php echo esc_html($cls); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Spacer pour mode fixed -->
        <div id="ssr-sticky-spacer"></div>

        <!-- Liste des classes -->
        <div id="ssr-list">
            <?php if (!$byClass): ?>
                <p style="text-align:center;color:#666;">Aucun élève à afficher.</p>
            <?php else: ?>
                <?php foreach ($byClass as $cls => $arr):
                    $group_id = 'ssr-group-' . md5($cls);
                    $is_open = $open_all ? 'true' : 'false';
                ?>
                    <div class="ssr-class-group" id="<?php echo esc_attr($group_id); ?>" data-class="<?php echo esc_attr($cls); ?>">

                        <!-- En-tête -->
                        <button class="ssr-class-toggle"
                                type="button"
                                aria-expanded="<?php echo esc_attr($is_open); ?>"
                                aria-controls="<?php echo esc_attr($group_id); ?>-panel">
                            <span><?php echo esc_html($cls); ?></span>
                            <span class="ssr-icon" style="font-size:22px;line-height:1;color:#555;">
                                <?php echo $open_all ? '−' : '+'; ?>
                            </span>
                        </button>

                        <!-- Panel élèves -->
                        <div id="<?php echo esc_attr($group_id); ?>-panel"
                             class="ssr-class-panel<?php echo $open_all ? ' open' : ''; ?>"
                             style="<?php if($open_all) echo 'max-height:9999px;'; ?>">
							<ul class="ssr-grid">
							<?php foreach ($arr as $s):
								$name = trim(($s['last_name'] ?? '') . ' ' . ($s['first_name'] ?? ''));
								$uid  = (string)($s['user_identifier'] ?? '');
							?>
							<li class="ssr-eleve-item"
								data-name="<?php echo esc_attr(mb_strtolower($name)); ?>"
								data-class="<?php echo esc_attr(mb_strtolower($cls)); ?>">

							  <a href="#"
								 class="ssr-card js-fiche"
								 data-id="<?php echo esc_attr($uid); ?>"
								 data-name="<?php echo esc_attr($name ?: $uid); ?>"
								 data-class="<?php echo esc_attr($cls); ?>">

								<span style="font-size:20px;">👤</span>
								<span style="display:flex;flex-direction:column;">
								  <strong style="line-height:1.1;"><?php echo esc_html($name ?: $uid); ?></strong>
								  <small class="ssr-meta" style="color:#666;"><?php echo esc_html($cls); ?> • ID <?php echo esc_html($uid); ?></small>
								</span>
							  </a>
							</li>
							<?php endforeach; ?>

                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    (function(){
        /* ====== Utils ====== */
        const adminBar = document.getElementById('wpadminbar');
        const $ = (sel, ctx=document) => ctx.querySelector(sel);
        const $$ = (sel, ctx=document) => Array.from(ctx.querySelectorAll(sel));
        const raf = (cb)=> window.requestAnimationFrame ? requestAnimationFrame(cb) : setTimeout(cb, 16);
const SSR_AJAX  = <?php echo json_encode($ajax_url); ?>;
const SSR_NONCE = <?php echo json_encode($nonce); ?>;
const SSR_CONVO = <?php echo json_encode($convoc); ?>; // ex. "13:15"

window.addEventListener('error', e => console.error('JS error:', e.message));

        // Debounce helper (perf)
        function debounce(fn, delay=150){
            let t; return (...args)=>{ clearTimeout(t); t = setTimeout(()=>fn(...args), delay); };
        }

        // LocalStorage key (unique par page)
        const LS_KEY = 'ssr_recaps_open_classes:' + (location.pathname || 'root');

        /* ====== Sticky unifié (fallback fixed) ====== */
        const sticky = document.getElementById('ssr-sticky');
        const stickySpacer = document.getElementById('ssr-sticky-spacer');
        const sentinel = document.createElement('div');
        sticky.parentNode.insertBefore(sentinel, sticky);

        function setStickyTop(){
            const top = adminBar ? adminBar.offsetHeight : 0;
            document.documentElement.style.setProperty('--ssr-sticky-top', top + 'px');
        }
        function setStickyHeight(){
            const h = sticky ? sticky.offsetHeight : 0;
            document.documentElement.style.setProperty('--ssr-sticky-height', h + 'px');
        }
        setStickyTop(); setStickyHeight();
        window.addEventListener('resize', ()=>{ setStickyTop(); setStickyHeight(); });

        // Observer pour fallback fixed
        const ioSticky = new IntersectionObserver((entries)=>{
            entries.forEach(entry=>{
                if(entry.isIntersecting){
                    sticky.classList.remove('is-fixed');
                    stickySpacer.style.height = '0px';
                }else{
                    sticky.classList.add('is-fixed');
                    stickySpacer.style.height = sticky.offsetHeight + 'px';
                }
            });
            setStickyHeight();
        }, {threshold: 0});
        ioSticky.observe(sentinel);

        /* ====== Accordéon animé + persistance ====== */
        const groups = $$('.ssr-class-group');
        const expandAllBtn = $('#ssr-expand-all');
        const collapseAllBtn = $('#ssr-collapse-all');
        const chips = $$('#ssr-summary .ssr-chip');
        const chipById = new Map(chips.map(ch => [ch.dataset.target, ch]));
        const panels = new Map(groups.map(g => [g.id, $('#'+g.id+'-panel')]));

        function isOpen(groupEl){
            return groupEl?.querySelector('.ssr-class-toggle')?.getAttribute('aria-expanded') === 'true';
        }

        // Animation slide open/close
        function slideOpen(panel){
            if (!panel) return;
            panel.classList.add('open');
            panel.style.display = ''; // au cas où
            const target = panel.scrollHeight;
            panel.style.maxHeight = '0px';
            raf(()=>{ panel.style.maxHeight = target + 'px'; });
        }
        function slideClose(panel){
            if (!panel) return;
            const current = panel.scrollHeight;
            panel.style.maxHeight = current + 'px';
            raf(()=>{ panel.style.maxHeight = '0px'; });
            panel.addEventListener('transitionend', function te(e){
                if (e.propertyName === 'max-height'){
                    panel.classList.remove('open');
                    panel.style.display = 'none';
                    panel.removeEventListener('transitionend', te);
                }
            });
        }
        function setOpen(groupEl, open, {persist=true}={}){
            const panel = panels.get(groupEl.id) || groupEl.querySelector('.ssr-class-panel');
            const toggle = groupEl.querySelector('.ssr-class-toggle');
            const icon   = groupEl.querySelector('.ssr-icon');
            if (!panel || !toggle) return;

            // Eviter double état
            const already = isOpen(groupEl);
            if (open === already) return;

            // ARIA + icône
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (icon) icon.textContent = open ? '−' : '+';

            // Animation
            if (open){
                panel.style.display = '';
                slideOpen(panel);
            }else{
                slideClose(panel);
            }

            // Persistance
            if (persist){
                const saved = new Set(JSON.parse(localStorage.getItem(LS_KEY) || '[]'));
                if (open) saved.add(groupEl.id); else saved.delete(groupEl.id);
                localStorage.setItem(LS_KEY, JSON.stringify(Array.from(saved)));
            }
        }

        function expandAll(){
            groups.forEach(g => {
                const panel = panels.get(g.id);
                const toggle = g.querySelector('.ssr-class-toggle');
                if (toggle.getAttribute('aria-expanded') !== 'true'){
                    toggle.setAttribute('aria-expanded','true');
                    g.querySelector('.ssr-icon').textContent = '−';
                    panel.style.display = '';
                    slideOpen(panel);
                }
            });
            // Sauvegarde
            localStorage.setItem(LS_KEY, JSON.stringify(groups.map(g=>g.id)));
        }

        function collapseAll(){
            groups.forEach(g => {
                const panel = panels.get(g.id);
                const toggle = g.querySelector('.ssr-class-toggle');
                if (toggle.getAttribute('aria-expanded') !== 'false'){
                    toggle.setAttribute('aria-expanded','false');
                    g.querySelector('.ssr-icon').textContent = '+';
                    slideClose(panel);
                }
            });
            localStorage.setItem(LS_KEY, JSON.stringify([]));
        }

        function refreshStates(){
            // Sommaire = état ouvert
            groups.forEach(g => {
                const chip = chipById.get(g.id);
                if (!chip) return;
                const opened = isOpen(g);
                chip.classList.toggle('is-open', opened);
                chip.setAttribute('aria-pressed', opened ? 'true' : 'false');
            });
            // Boutons globaux
            const allOpen = groups.length && groups.every(g => isOpen(g));
            const allClosed = groups.length && groups.every(g => !isOpen(g));
            expandAllBtn.setAttribute('aria-pressed', allOpen ? 'true' : 'false');
            collapseAllBtn.setAttribute('aria-pressed', allClosed ? 'true' : 'false');
            if(!allOpen && !allClosed){
                expandAllBtn.setAttribute('aria-pressed','false');
                collapseAllBtn.setAttribute('aria-pressed','false');
            }
        }

        // Appliquer la persistance au chargement
        (function restoreOpen(){
            const saved = new Set(JSON.parse(localStorage.getItem(LS_KEY) || '[]'));
            if (saved.size){
                groups.forEach(g=>{
                    const wantsOpen = saved.has(g.id);
                    const panel = panels.get(g.id);
                    const toggle = g.querySelector('.ssr-class-toggle');
                    toggle.setAttribute('aria-expanded', wantsOpen ? 'true':'false');
                    g.querySelector('.ssr-icon').textContent = wantsOpen ? '−' : '+';
                    if (wantsOpen){
                        panel.classList.add('open');
                        panel.style.display = '';
                        panel.style.maxHeight = panel.scrollHeight + 'px';
                    }else{
                        panel.classList.remove('open');
                        panel.style.display = 'none';
                        panel.style.maxHeight = '0px';
                    }
                });
            }else{
                // Utiliser default_open si pas de persistance
                groups.forEach(g=>{
                    const panel = panels.get(g.id);
                    const toggle = g.querySelector('.ssr-class-toggle');
                    const openDefault = <?php echo $open_all ? 'true' : 'false'; ?>;
                    toggle.setAttribute('aria-expanded', openDefault ? 'true' : 'false');
                    g.querySelector('.ssr-icon').textContent = openDefault ? '−' : '+';
                    if (openDefault){
                        panel.classList.add('open');
                        panel.style.display = '';
                        panel.style.maxHeight = panel.scrollHeight + 'px';
                    }else{
                        panel.classList.remove('open');
                        panel.style.display = 'none';
                        panel.style.maxHeight = '0px';
                    }
                });
            }
            refreshStates();
        })();

        // Actions globales
        expandAllBtn.addEventListener('click', ()=>{ expandAll(); refreshStates(); });
        collapseAllBtn.addEventListener('click', ()=>{ collapseAll(); refreshStates(); });

        // Toggle individuel
document.addEventListener('click', (e)=>{
  const btn = e.target.closest('.ssr-class-toggle');
  if (!btn) return;
  // ne pas agir si un overlay est ouvert et capte le clic
  const overlay = document.getElementById('ssr-fiche-overlay');
  if (overlay && overlay.classList.contains('open')) return;

  const group = btn.closest('.ssr-class-group');
  setOpen(group, !isOpen(group));
  refreshStates();
});

        /* ====== Sommaire : clic + clavier (← / →) ====== */
        function focusChipAt(index){
            if (!chips.length) return;
            const i = (index + chips.length) % chips.length;
            chips[i].focus();
        }
        chips.forEach((link, idx) => {
			// Clic : toggle (ouvre si fermé, referme si ouvert)
			link.addEventListener('click', function(e){
			  e.preventDefault();
			  const targetId = this.dataset.target;
			  const target   = document.getElementById(targetId);
			  if (!target) return;

			  const header = target.querySelector('.ssr-class-toggle');
			  const isOpened = header?.getAttribute('aria-expanded') === 'true';

			  // toggle
			  setOpen(target, !isOpened);
			  refreshStates();

			  // si on vient d'ouvrir, on scroll + on surligne un instant
			  if (!isOpened){
				const topOffset = (adminBar ? adminBar.offsetHeight : 0) + (sticky ? sticky.offsetHeight : 0) + 8;
				const y = header.getBoundingClientRect().top + window.pageYOffset - topOffset;
				window.scrollTo({ top: y, behavior: 'smooth' });

				const originalBg = header.style.backgroundColor || '#f3f4f6';
				header.style.backgroundColor = '#ffe8cc';
				setTimeout(()=>{ header.style.backgroundColor = originalBg; }, 600);
			  }
			});

            // Clavier
            link.addEventListener('keydown', (e)=>{
                if (e.key === 'ArrowRight'){ e.preventDefault(); focusChipAt(idx+1); }
                if (e.key === 'ArrowLeft'){ e.preventDefault(); focusChipAt(idx-1); }
                if (e.key === 'Enter' || e.key === ' '){ e.preventDefault(); link.click(); }
            });
        });

        /* ====== Recherche + Auto-complétion (ARIA) ====== */
        const input = document.getElementById('ssr-search');
        const suggest = document.getElementById('ssr-suggest');
        const live = document.getElementById('ssr-sr-status');

        // Index depuis le DOM (optimisé : valeurs déjà en minuscules)
        const index = $$('.ssr-eleve-item').map(item=>{
            const a = item.querySelector('a');
            const meta = item.querySelector('.ssr-meta')?.textContent || '';
            return {
                key:  (item.dataset.name || '') + ' ' + (item.dataset.class || ''),
                name: (item.dataset.name || ''),     // minuscule
                cls:  (item.dataset.class || ''),    // minuscule
                href: a ? a.getAttribute('href') : '#',
                label: (a ? a.querySelector('strong')?.textContent : '') || '',
                idText: meta
            };
        });

        let sIdx = -1; // option active
        function escapeHtml(t){ return (t||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
        function escapeRegExp(t){ return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
        // Surlignage multi-mots
        function highlightHtml(text, query){
            const raw = text || '';
            const q = (query||'').trim();
            if (!q) return escapeHtml(raw);
            const tokens = q.split(/\s+/).filter(Boolean).map(escapeRegExp);
            if (!tokens.length) return escapeHtml(raw);
            const re = new RegExp('(' + tokens.join('|') + ')', 'ig');
            const parts = raw.split(re);
            return parts.map((p,i)=> i%2 ? '<span class="ssr-hl">'+escapeHtml(p)+'</span>' : escapeHtml(p)).join('');
        }

        function renderSuggest(list, q){
            if (!list.length){
                suggest.innerHTML = '<div class="ssr-s-empty" role="option" aria-disabled="true">Aucun résultat…</div>';
                suggest.style.display = 'block';
                input.setAttribute('aria-expanded','true');
                live.textContent = 'Aucun résultat';
                sIdx = -1;
                input.setAttribute('aria-activedescendant','');
                return;
            }
            suggest.innerHTML = list.map((r,i)=>`
                <div class="ssr-s-item" role="option" id="ssr-opt-${i}" data-href="${r.href}" data-i="${i}">
                    <span class="ssr-s-name">${highlightHtml(r.label, q)}</span>
                    <span class="ssr-s-meta">${highlightHtml(r.idText, q)}</span>
                </div>
            `).join('');
            suggest.style.display = 'block';
            input.setAttribute('aria-expanded','true');
            live.textContent = list.length + ' résultat' + (list.length>1?'s':'') + ' disponibles';
            sIdx = -1;
            input.setAttribute('aria-activedescendant','');
        }

        function hideSuggest(){ suggest.style.display = 'none'; input.setAttribute('aria-expanded','false'); sIdx = -1; input.setAttribute('aria-activedescendant',''); }

        function filterList(q){
            const ql = (q||'').trim().toLowerCase();
            // Filtrage + ouverture des classes avec résultats
            groups.forEach(g => {
                let visible = 0;
                const li = g.querySelectorAll('.ssr-eleve-item');
                li.forEach(item=>{
                    const hay = item.dataset.name + ' ' + item.dataset.class;
                    const show = !ql || hay.includes(ql);
                    item.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                // Affichage du groupe
                g.style.display = visible ? '' : 'none';
                // Ouvrir si filtré et contient des résultats
                const shouldOpen = ql ? (visible > 0) : (<?php echo $open_all ? 'true' : 'false'; ?>);
                setOpen(g, shouldOpen, {persist:false});
            });
            refreshStates();
        }

        function filterAndSuggest(q){
            const ql = (q||'').trim().toLowerCase();
            if (!ql){ hideSuggest(); return; }
            // Recherche rapide
            const res = index.filter(r=> r.key.includes(ql) || r.label.toLowerCase().includes(ql) || r.idText.toLowerCase().includes(ql)).slice(0, 12);
            renderSuggest(res, q);
        }

        const onSearchInput = debounce((e)=>{
            const q = e.target.value || '';
            filterList(q);
            filterAndSuggest(q);
        }, 150);

        input.addEventListener('input', onSearchInput);

        // Ouverture suggestions avec flèche bas
        input.addEventListener('keydown', (e)=>{
            const visible = suggest.style.display !== 'none';
            if (e.key === 'ArrowDown'){
                if (!visible){
                    const q = input.value || '';
                    filterAndSuggest(q);
                }else{
                    const items = $$('.ssr-s-item', suggest);
                    if (!items.length) return;
                    sIdx = (sIdx + 1) % items.length;
                    items.forEach(el => el.classList.remove('active'));
                    items[sIdx].classList.add('active');
                    input.setAttribute('aria-activedescendant', items[sIdx].id);
                    items[sIdx].scrollIntoView({block:'nearest'});
                }
                e.preventDefault();
            }else if (e.key === 'ArrowUp' && visible){
                const items = $$('.ssr-s-item', suggest);
                if (!items.length) return;
                sIdx = (sIdx - 1 + items.length) % items.length;
                items.forEach(el => el.classList.remove('active'));
                items[sIdx].classList.add('active');
                input.setAttribute('aria-activedescendant', items[sIdx].id);
                items[sIdx].scrollIntoView({block:'nearest'});
                e.preventDefault();
            }else if (e.key === 'Enter' && visible){
                const items = $$('.ssr-s-item', suggest);
                if (sIdx >= 0 && items[sIdx]){
                    e.preventDefault();
                    const href = items[sIdx].getAttribute('data-href');
                    if (href) window.location.href = href;
                }
            }else if (e.key === 'Escape'){
                hideSuggest();
            }
        });

        // Clic suggestion
        suggest.addEventListener('click', (e)=>{
            const item = e.target.closest('.ssr-s-item');
            if (!item) return;
            const href = item.getAttribute('data-href');
            if (href) window.location.href = href;
        });
        // Fermer suggestions si clic hors du champ/liste
        document.addEventListener('click', (e)=>{
            if (!suggest.contains(e.target) && e.target !== input){
                hideSuggest();
            }
        });

        /* ===== Fiche élève (modal) ===== */
        const overlay = document.getElementById('ssr-fiche-overlay');
        const rowsEl  = overlay ? overlay.querySelector('.ssr-fiche-rows') : null;
        const nameEl  = overlay ? overlay.querySelector('.ssr-fiche-name') : null;
        const classEl = overlay ? overlay.querySelector('.v-class') : null;
        const idEl    = overlay ? overlay.querySelector('.v-id') : null;

        function openOverlay(){ if(!overlay) return; overlay.classList.add('open'); document.body.style.overflow='hidden'; }
        function closeOverlay(){ if(!overlay) return; overlay.classList.remove('open'); document.body.style.overflow=''; }

        // Fermer (backdrop, croix, Échap)
        document.addEventListener('click', (e)=>{ if (e.target && e.target.dataset && e.target.dataset.close === '1') closeOverlay(); });
        document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && overlay && overlay.classList.contains('open')) closeOverlay(); });

        // Délégation: capter tous les clics sur .js-fiche (même si la liste évolue)
        document.addEventListener('click', (ev)=>{
          const a = ev.target.closest('.js-fiche');
          if (!a) return;
          ev.preventDefault();

          if (!overlay || !rowsEl || !nameEl || !classEl || !idEl){
            console.warn('Modal HTML manquant (#ssr-fiche-overlay)'); return;
          }

          const id  = a.dataset.id;
          const nm  = a.dataset.name || 'Élève';
          const cls = a.dataset.class || '—';

          // Pré-chargement visuel
          nameEl.textContent  = nm;
          classEl.textContent = cls;
          idEl.textContent    = id || '—';
          rowsEl.innerHTML    = '<tr><td colspan="5" style="text-align:center;color:#666;">Chargement…</td></tr>';
          openOverlay();

          if (!id){
            rowsEl.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#c00;">ID manquant.</td></tr>';
            return;
          }

          const body = new URLSearchParams();
          body.set('action','ssr_fetch_fiche_eleve');
          body.set('nonce', SSR_NONCE || '');
          body.set('id', id);

          fetch(SSR_AJAX, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
            .then(r=>r.json())
            .then(data=>{
              if (!data || !data.success) throw new Error('AJAX error');
              const d = data.data || {};
              nameEl.textContent  = d.name  || nm;
              classEl.textContent = d.class || cls;
              idEl.textContent    = d.id    || id;

              const list = Array.isArray(d.rows) ? d.rows : [];
              if (!list.length){
                rowsEl.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#666;">Aucun retard vérifié pour cet élève.</td></tr>';
                return;
              }

			  rowsEl.innerHTML = list.map(r=>{
				  // Date du retard : priorité à r.date, sinon date extraite de r.at
				  const dateRet = r.date
				  ? new Date(r.date.replace(/-/g,'/'))
				  : (r.at ? new Date(r.at.replace(/-/g,'/')) : null);

				  const dateRetFr   = dateRet ? dateRet.toLocaleDateString('fr-BE') : '—';

				  // Date vérif = date seule (jour/mois/année)
				  const atDt        = r.at ? new Date(r.at.replace(/-/g,'/')) : null;
				  const dateVerifFr = atDt ? atDt.toLocaleDateString('fr-BE') : '—';

				  // Vérifié le = date + heure
				  const verifieLeFr = atDt ? atDt.toLocaleString('fr-BE',{
					  day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'
				  }) : '—';

				  const present = r.status === 'present' ? '✅' : '❌';
				  const by      = r.by ? (typeof escapeHtml === 'function' ? escapeHtml(r.by) : r.by) : '—';

				  return `<tr>
<td>${dateRetFr}</td>
<td>${dateVerifFr}</td>
<td>${present}</td>
<td>${by}</td>
<td>${verifieLeFr}</td>
		</tr>`;
			  }).join('');
            })
            .catch(err=>{
              console.error(err);
              rowsEl.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#c00;">Erreur lors du chargement.</td></tr>';
            });
        });

    })(); // ← garde bien cette fermeture APRÈS le bloc modal

    </script>
    <?php
    return ob_get_clean();
});;
