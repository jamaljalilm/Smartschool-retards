<?php
if (!defined('ABSPATH')) exit;

/** Fallbacks doux si tes helpers ne sont pas chargés **/
if (!function_exists('ssr_cal_get_settings')) {
    function ssr_cal_get_settings(){
        return [
            'start_date' => '',
            'end_date'   => '',
            'verif_url'  => '/retards-verif',
            // règle “jour vide” minimale : rien n’est vidé par défaut
        ];
    }
}
if (!function_exists('ssr_cal_is_empty_day')) {
    // Par défaut : ne vide pas les cases (sauf si tu ajoutes ta logique)
    function ssr_cal_is_empty_day($dateStr, $todayStr){ return false; }
}

add_shortcode('recap_calendrier', function($atts){
	// 🔐 Si pas connecté → redirige vers la page PIN avec redirect_to
	if (function_exists('ssr_is_logged_in_pin') && !ssr_is_logged_in_pin()) {
		$scheme   = is_ssl() ? 'https://' : 'http://';
		$current  = $scheme . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; // URL du calendrier
		$loginurl = add_query_arg('redirect_to', rawurlencode($current), home_url('/connexion-verificateur'));
		wp_safe_redirect($loginurl);
		exit;
	}

    global $wpdb;

    // Sécurise l’appel au settings (fallback si le helper n’existe pas)
    $cfg = function_exists('ssr_cal_get_settings') ? ssr_cal_get_settings() : [
        'start_date' => '',
        'end_date'   => '',
        'verif_url'  => '/retards-verif',
    ];

    $a = shortcode_atts([
        'table'        => $wpdb->prefix . 'smartschool_retards_verif',
        'date_col'     => '',               // ex: date_retard
        'month'        => '',               // YYYY-MM
        'start_monday' => '1',
        'verif_url'    => !empty($cfg['verif_url']) ? $cfg['verif_url'] : '/retards-verif',
    ], $atts, 'recap_calendrier');

    $now      = current_time('timestamp');
    $todayStr = date('Y-m-d', $now);

    // Plage de navigation (clamp)
    $navStart = !empty($cfg['start_date']) ? substr($cfg['start_date'],0,7) : date('Y-m', strtotime('-12 months', $now));
    $navEnd   = !empty($cfg['end_date'])   ? substr($cfg['end_date'],0,7)   : date('Y-m', strtotime('+12 months', $now));

    $ym_query = (isset($_GET['ym']) && preg_match('/^\d{4}-\d{2}$/', $_GET['ym'])) ? $_GET['ym'] : '';
    $monthStr = $ym_query ?: (preg_match('/^\d{4}-\d{2}$/', $a['month']) ? $a['month'] : date('Y-m', $now));
    if ($monthStr < $navStart) $monthStr = $navStart;
    if ($monthStr > $navEnd)   $monthStr = $navEnd;

    $curFirst = strtotime($monthStr . '-01 00:00:00');
    $firstDay = date('Y-m-01', $curFirst);
    $lastDay  = date('Y-m-t',  $curFirst);

    // Prev/Next
    $prevMonth = date('Y-m', strtotime('-1 month', $curFirst));
    $nextMonth = date('Y-m', strtotime('+1 month', $curFirst));
    if ($prevMonth < $navStart) $prevMonth = null;
    if ($nextMonth > $navEnd)   $nextMonth = null;

    // Libellés FR
    $mois_fr = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    $m_idx   = (int)date('n', $curFirst) - 1;
    $moisTxt = ucfirst($mois_fr[$m_idx]) . ' ' . date('Y', $curFirst);

    // Jours
    $mondayFirst = $a['start_monday'] !== '0';
    $joursHeader = $mondayFirst ? ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'] : ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];
    $joursShort  = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam']; // si besoin

    // Table & colonnes
    $table = preg_replace('/[^a-zA-Z0-9_]/','', $a['table']);
    // Astuce LIKE sécurisée
    $like  = $wpdb->esc_like($table);
    $exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $like) );
    if (!$exists) {
        return '<div style="padding:12px;border:1px solid #fca5a5;background:#fff1f2;border-radius:8px;color:#991b1b;">Table introuvable : <code>'.esc_html($table).'</code></div>';
    }

    $colsRes = $wpdb->get_results("SHOW COLUMNS FROM `$table`", ARRAY_A);
    $colsRes = is_array($colsRes) ? $colsRes : [];
    // Remplace l’arrow function pour compat PHP<7.4
    $cols = array_map(function($r){ return isset($r['Field']) ? $r['Field'] : ''; }, $colsRes);

    $pick = function(array $cands) use ($cols){
        foreach($cands as $c){
            if ($c && in_array($c, $cols, true)) return $c;
        }
        return '';
    };

    $date_col     = $pick([preg_replace('/[^a-zA-Z0-9_]/','', $a['date_col']), 'date_retard','date','jour','created_at','checked_at','updated_at','timestamp']);
    $verified_at  = $pick(['verified_at','date_verif','verification_date','verified_date']);
    $verifier_col = $pick(['verified_by_name','verified_by','checked_by','checked_user','user','username','author']);
    $code_col     = $pick(['verified_by_code','verifier_code','checked_by_code','user_code']);
    $status_col   = $pick(['status','state','present_absent']);

    if (!$date_col) {
        return '<div style="padding:12px;border:1px solid #fca5a5;background:#fff1f2;border-radius:8px;color:#991b1b;">
            Indique la colonne date : <code>[recap_calendrier date_col="date_retard"]</code>
        </div>';
    }

    // Map PIN → nom courant
    $pinsMap = [];
    $pinsOpt = get_option('smartschool_pins', []);
    if (is_array($pinsOpt)) {
        foreach ($pinsOpt as $p) {
            if (!empty($p['code']) && !empty($p['name'])) {
                $pinsMap[$p['code']] = $p['name'];
            }
        }
    }

    /* ===== Requêtes ===== */
	// Vérifiés + dernier verify (code + nom + horodatage)
	$verifiedByDay = [];
	if ($verified_at) {
		$nameSelect = $verifier_col
			? "SUBSTRING_INDEX(GROUP_CONCAT(`$verifier_col` ORDER BY `$verified_at` DESC SEPARATOR '||'),'||',1)"
			: "''";
		$codeSelect = $code_col
			? "SUBSTRING_INDEX(GROUP_CONCAT(`$code_col` ORDER BY `$verified_at` DESC SEPARATOR '||'),'||',1)"
			: "''";
		$timeSelect = "SUBSTRING_INDEX(GROUP_CONCAT(`$verified_at` ORDER BY `$verified_at` DESC SEPARATOR '||'),'||',1)";

		$sqlOk = "SELECT DATE(`$date_col`) AS d,
						 COUNT(*) AS cnt,
						 $nameSelect AS last_verifier,
						 $codeSelect AS last_code,
						 $timeSelect AS last_at
				  FROM `$table`
				  WHERE `$date_col` >= %s AND `$date_col` < %s
					AND `$verified_at` IS NOT NULL
				  GROUP BY DATE(`$date_col`)";
		$rowsOk = $wpdb->get_results(
			$wpdb->prepare($sqlOk, $firstDay.' 00:00:00', date('Y-m-d', strtotime($lastDay.' +1 day')).' 00:00:00'),
			ARRAY_A
		);
		$rowsOk = is_array($rowsOk) ? $rowsOk : [];
		foreach ($rowsOk as $r) {
			$verifiedByDay[$r['d']] = [
				'cnt'  => isset($r['cnt']) ? (int)$r['cnt'] : 0,
				'who'  => isset($r['last_verifier']) ? $r['last_verifier'] : '',
				'code' => isset($r['last_code']) ? $r['last_code'] : '',
				'at'   => isset($r['last_at']) ? $r['last_at'] : null, // << ajoute l’horodatage brut
			];
		}
	}


    // Compteurs présent/absent par jour
    $countsByDay = [];
    if ($status_col) {
        $sqlCnt = "SELECT DATE(`$date_col`) AS d,
                          SUM(CASE WHEN LOWER(`$status_col`)='present' THEN 1 ELSE 0 END) AS present_cnt,
                          SUM(CASE WHEN LOWER(`$status_col`)='absent'  THEN 1 ELSE 0 END) AS absent_cnt
                   FROM `$table`
                   WHERE `$date_col` >= %s AND `$date_col` < %s
                   GROUP BY DATE(`$date_col`)";
        $rowsCnt = $wpdb->get_results(
            $wpdb->prepare($sqlCnt, $firstDay.' 00:00:00', date('Y-m-d', strtotime($lastDay.' +1 day')).' 00:00:00'),
            ARRAY_A
        );
        $rowsCnt = is_array($rowsCnt) ? $rowsCnt : [];
        foreach ($rowsCnt as $r) {
            $countsByDay[$r['d']] = [
                'present'=> isset($r['present_cnt']) ? (int)$r['present_cnt'] : 0,
                'absent' => isset($r['absent_cnt'])  ? (int)$r['absent_cnt']  : 0,
            ];
        }
    }

	/* ===== Grille ===== */
	$firstWeekdayN = (int)date('N', strtotime($firstDay)); // 1..7
	$offset = $mondayFirst ? ($firstWeekdayN - 1) : (int)date('w', strtotime($firstDay)); // 0..6
	if ($offset < 0) $offset = 0;
	$nbDays = (int)date('t', $curFirst);

	// dates mois courant
	$cYear  = (int)date('Y', $curFirst);
	$cMonth = (int)date('m', $curFirst);

	// mois précédent & suivant
	$prevTs   = strtotime('-1 month', $curFirst);
	$nextTs   = strtotime('+1 month', $curFirst);
	$prevDays = (int)date('t', $prevTs);

	// === construit le tableau des cellules
	$cells = [];

	// 1) Jours "adjacents" du mois précédent (pour compléter la 1re ligne)
	for ($i = $offset; $i > 0; $i--) {
		$dnum = $prevDays - $i + 1;
		$dstr = date('Y-m', $prevTs) . '-' . sprintf('%02d', $dnum);
		$cells[] = ['date'=>$dstr, 'adj'=>true];
	}

	// 2) Jours du mois courant
	for ($d=1; $d <= $nbDays; $d++) {
		$dstr = date('Y-m', $curFirst) . '-' . sprintf('%02d', $d);
		$cells[] = ['date'=>$dstr, 'adj'=>false];
	}

	// 3) Jours "adjacents" du mois suivant (pour compléter la dernière ligne)
	$rem = (7 - (count($cells) % 7)) % 7;
	for ($i=1; $i <= $rem; $i++) {
		$dstr = date('Y-m', $nextTs) . '-' . sprintf('%02d', $i);
		$cells[] = ['date'=>$dstr, 'adj'=>true];
	}


    // URLs nav
    $base_url   = get_permalink();
    if (!$base_url) $base_url = home_url(add_query_arg(null, null)); // fallback
    $base_clean = remove_query_arg('ym', $base_url);
    $todayMonth = date('Y-m', $now);
    if ($todayMonth < $navStart) $todayMonth = $navStart;
    if ($todayMonth > $navEnd)   $todayMonth = $navEnd;
    $url_today  = add_query_arg('ym', $todayMonth, $base_clean);
    $verif_base = esc_url( home_url( !empty($a['verif_url']) ? $a['verif_url'] : '/retards-verif' ) );

    ob_start(); ?>
    <div class="ssr-cal-wrap" style="max-width:980px;margin:0 auto;font-family:system-ui, sans-serif;">
		<style>
		  /* ===== En-tête ===== */
			.ssr-cal-head{display:flex;flex-direction:column;align-items:center;gap:6px;margin:10px 0 12px;}
			.ssr-cal-title{font-weight:800;color:#f57c00;font-size:clamp(22px,2.2vw,28px);margin:0;text-align:center;}
			.ssr-cal-sub{font-size:clamp(14px,1.4vw,16px);color:#111;font-weight:700;margin:0;text-align:center;}
			.ssr-cal-nav{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:center;margin-top:8px;}
			.ssr-cal-btn{padding:8px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;text-decoration:none;color:#111;font-weight:600;}
			.ssr-cal-btn:hover{background:#f3f4f6;}
			.ssr-cal-btn.is-disabled{opacity:.5;pointer-events:none;}
			.ssr-cal-select{padding:8px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;color:#111;font-weight:600;}
			.ssr-cal-subselect{margin-top:6px}
			.ssr-cal-subselect select{
			  padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;
			  background:#fff;color:#111;font-weight:700;font-size:15px;
			}
			@media (max-width:600px){
			  .ssr-cal-subselect select{font-size:14px;padding:6px 10px}
			}

		  /* En-tête des jours (toujours visible) */
		  .ssr-cal-daynames{display:grid;grid-template-columns:repeat(7,1fr);gap:clamp(4px,0.6vw,10px);margin:10px 0 6px;}
		  .ssr-cal-dayname{text-transform:lowercase;color:#fe8a2b;font-weight:700;font-size:clamp(12px,1.2vw,14px);text-align:center;}

		  /* ===== Grille minimaliste (desktop & mobile) ===== */
		  .ssr-cal-grid{
			display:grid;
			grid-template-columns:repeat(7,1fr);
			gap:clamp(4px,0.6vw,10px);
		  }

		  /* Carte épurée : uniquement le chiffre + un dot d’état en-dessous */
		  .ssr-cal-cell{
			display:flex;flex-direction:column;
			align-items:center;justify-content:center;
			min-height:clamp(56px,8.5vw,120px);
			border:1.5px solid #e5e7eb;border-radius:12px;background:#fff;
			padding:clamp(6px,0.8vw,10px);text-decoration:none;position:relative;overflow:hidden;
			transition:transform .12s ease, box-shadow .12s ease, filter .12s ease;
		  }
		  .ssr-cal-cell:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(0,0,0,.06);filter:brightness(0.995);}

		  /* On masque le bloc descriptif (on reste épuré) */
		  .ssr-cal-center{display:none}

		  .ssr-cal-date{
			display:flex;flex-direction:column;align-items:center;gap:6px;margin:0;
		  }
		  .ssr-num{
			display:inline-block;
			width:clamp(34px,4.6vw,44px); height:clamp(34px,4.6vw,44px);
			line-height:clamp(34px,4.6vw,44px);
			text-align:center;border-radius:50%;font-weight:800;
			font-size:clamp(15px,1.8vw,18px); color:#111;
		  }
		  .ssr-dot{width:clamp(6px,0.9vw,8px);height:clamp(6px,0.9vw,8px);border-radius:9999px;display:block}

		  /* États via le DOT (et la pastille du jour) */
		  .state-ok .ssr-dot{background:#10b981}
		  .state-ko .ssr-dot{background:#ef4444}
		  .state-future .ssr-dot{background:#9ca3af}

		  /* Jours adjacents (mois précédent/suivant) : grisés et non cliquables */
		  .is-adj{pointer-events:none;opacity:.45;background:#fff;border-style:dashed}
		  .is-adj .ssr-num{color:#9aa0a6}
		  .is-adj .ssr-dot{background:#9aa0a6}
		  /* Aujourd’hui : pastille orange pleine (comme ta capture) */

		  .is-today .ssr-num{background:#f57c00;color:#fff}

		  /* Accessibilité focus clavier */
		  .ssr-cal-cell:focus{outline:3px solid rgba(37,99,235,.25);outline-offset:2px}

		  /* Ajustements très petits écrans */
		  @media (max-width: 600px){
			.ssr-cal-btn,.ssr-cal-select{padding:6px 10px;font-size:14px}
		  }
		/* ===== Panneau détails + animation ===== */
		.ssr-cal-detail{max-width:980px;margin:12px auto 0;padding:0 4px;transition:all .3s ease;}
		.ssr-cal-detail[hidden]{display:none !important;}
		.ssr-cal-detail-card {
		  border: 1.5px solid #e6eaef;      /* même ton clair */
		  border-radius: 12px;
		  background: #f9fafb;              /* même fond que la carte “aucun élève” */
		  padding: 22px 20px 24px;          /* un peu plus d’espace pour respirer */
		  box-shadow: 0 6px 14px rgba(0, 0, 0, .04);
		  position: relative;
		  opacity: 0;
		  transform: translateY(10px);
		  transition: opacity .25s ease, transform .25s ease;
		}
		.ssr-cal-detail.show .ssr-cal-detail-card{
		  opacity:1;
		  transform:translateY(0);
		}

		/* bouton fermer (croix grise -> rouge uniquement sur le texte au hover) */
		.ssr-cal-close{
		  position:absolute;
		  top:8px;
		  right:10px;
		  background:transparent;
		  border:none;
		  font-size:20px;
		  line-height:1;
		  color:#9ca3af; /* gris par défaut */
		  cursor:pointer;
		  font-weight:700;
		  transition:color .2s ease; /* uniquement la couleur du texte */
		}
		.ssr-cal-close:hover{
		  color:#ef4444; /* rouge vif au survol */
		  background: transparent !important;
		  border: none !important;
		  border-bottom-color: transparent !important;
		  box-shadow: none !important;
		}

		.ssr-cal-detail-head{display:flex;align-items:center;gap:10px;margin-bottom:6px}
		.ssr-cal-detail-dot{width:8px;height:8px;border-radius:9999px;background:#10b981;display:inline-block}
		.ssr-cal-detail-date{font-weight:800;color:#111}
		.ssr-cal-detail-row{margin:6px 0;color:#111}
		.ssr-cal-detail-actions{margin-top:10px}
		.ssr-cal-detail-link{display:inline-block;padding:8px 12px;border-radius:8px;background:#f57c00;color:#fff;text-decoration:none;font-weight:700}
		.ssr-cal-detail-link:hover{filter:brightness(.97)}

			/* Jours "masqués" : semi-transparents comme les jours adjacents, non cliquables */
		.is-muted{pointer-events: none!important; opacity: .45!important; background: #fff!important; border-style: dashed!important; /* comme les adjacents si tu veux */}
		.is-muted .ssr-num{ color:#9ca3af !important; }
		.is-muted .ssr-dot{ background:#d1d5db !important; }

			
		</style>

        <!-- Titre + sous-titre + nav -->
        <div class="ssr-cal-head">
            <h3 class="ssr-cal-title">Calendrier des vérifications</h3>
            <div class="ssr-cal-subselect">
			  <select id="ssr-cal-month" aria-label="Choisir un mois">
				<?php
				  $iter = strtotime($navStart.'-01 00:00:00');
				  $endIter = strtotime($navEnd.'-01 00:00:00');
				  while ($iter <= $endIter) {
					  $val = date('Y-m', $iter);
					  $lbl = ucfirst($mois_fr[(int)date('n',$iter)-1]) . ' ' . date('Y',$iter);
					  $sel = selected($val, $monthStr, false);
					  echo '<option value="'.esc_attr($val).'" '.$sel.'>'.esc_html($lbl).'</option>';
					  $iter = strtotime('+1 month', $iter);
				  }
				?>
			  </select>
			</div>


            <div class="ssr-cal-nav">
                <?php if($prevMonth): ?>
                    <a class="ssr-cal-btn" href="<?php echo esc_url(add_query_arg('ym',$prevMonth,$base_clean)); ?>">Mois précédent</a>
                <?php else: ?>
                    <span class="ssr-cal-btn is-disabled">Mois précédent</span>
                <?php endif; ?>

                <a class="ssr-cal-btn" href="<?php echo esc_url($url_today); ?>">Aujourd’hui</a>

                <?php if($nextMonth): ?>
                    <a class="ssr-cal-btn" href="<?php echo esc_url(add_query_arg('ym',$nextMonth,$base_clean)); ?>">Mois suivant</a>
                <?php else: ?>
                    <span class="ssr-cal-btn is-disabled">Mois suivant</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- En-tête jours (desktop) -->
        <div class="ssr-cal-daynames" aria-hidden="true">
            <?php foreach($joursHeader as $jn): ?>
                <div class="ssr-cal-dayname"><?php echo esc_html($jn); ?></div>
            <?php endforeach; ?>
        </div>

		<!-- Grille -->
		<div class="ssr-cal-grid">
		<?php foreach ($cells as $cell):
			$dstr  = is_array($cell) ? $cell['date'] : $cell; // compat si tu n'as pas encore A+B
			$isAdj = is_array($cell) ? !empty($cell['adj']) : false;
			$wasEmpty = (!$isAdj && ssr_cal_is_empty_day($dstr, $todayStr));

			$ts       = strtotime($dstr.' 00:00:00');
			$isToday  = ($dstr === $todayStr);
			$isFuture = ($ts > $now);

			$infoOk   = $verifiedByDay[$dstr] ?? null;
			$isOk     = !$isFuture && $infoOk && ($infoOk['cnt'] > 0);
			$counts   = $countsByDay[$dstr] ?? ['present'=>0, 'absent'=>0];

			// Nom “à jour” via code PIN
			$whoCode     = $infoOk['code'] ?? '';
			$whoNameOld  = $infoOk['who']  ?? '';
			$displayWho  = ($whoCode && isset($pinsMap[$whoCode])) ? $pinsMap[$whoCode] : $whoNameOld;

			$stateCls = $isFuture ? 'state-future' : ($isOk ? 'state-ok' : 'state-ko');
			$dateNum  = (int)date('j', $ts);
			$href     = add_query_arg('date', $dstr, $verif_base);

			// data-* pour détails quand c'est vert
			$lastAt = $infoOk['at'] ?? null;
			$dataAttr = $isOk
				? ' data-date="'.esc_attr($dstr).'"'
				  .' data-who="'.esc_attr($displayWho).'"'
				  .' data-present="'.intval($counts['present']).'"'
				  .' data-absent="'.intval($counts['absent']).'"'
				  .' data-at="'.esc_attr($lastAt ?: '') .'"'
				: '';

			ob_start(); ?>
				<div class="ssr-cal-center"><!-- contenu masqué en style épuré --></div>
				<div class="ssr-cal-date" title="<?php echo esc_attr($dstr); ?>">
					<span class="ssr-num"><?php echo esc_html($dateNum); ?></span>
					<span class="ssr-dot" aria-hidden="true"></span>
				</div>
			<?php
			$inner = ob_get_clean();

			$cls = trim($stateCls . ($isToday ? ' is-today' : '') . ($isAdj ? ' is-adj' : ''). ($wasEmpty ? ' is-muted' : ''));

			// Les jours adjacents, futurs OU "muted" ne sont pas cliquables
			if ($isAdj || $isFuture || $wasEmpty){
				echo '<div class="ssr-cal-cell '.esc_attr($cls).'" role="group" aria-label="'.esc_attr($dstr).'">'.$inner.'</div>';
			} else {
				// comportement normal (vert = panneau détail, rouge = lien verif) — si tu as mon code précédent
			if ($isOk) {
				echo '<a href="#" class="ssr-cal-cell '.esc_attr($cls).' js-ssr-ok" role="button" aria-label="'.esc_attr($dstr).'"'.$dataAttr.'">'.$inner.'</a>';
			} else {
					echo '<a class="ssr-cal-cell '.esc_attr($cls).'" href="'.esc_url($href).'" role="group" aria-label="'.esc_attr($dstr).'">'.$inner.'</a>';
				}
			}

		endforeach; ?>
		</div>

		<!-- Panneau de détails -->
		<div id="ssr-cal-detail" class="ssr-cal-detail" hidden>
		  <div class="ssr-cal-detail-card">
			<button type="button" class="ssr-cal-close" aria-label="Fermer le panneau">✕</button>
			<div class="ssr-cal-detail-head">
			  <span class="ssr-cal-detail-dot"></span>
			  <span class="ssr-cal-detail-date"></span>
			</div>
			<div class="ssr-cal-detail-body">
			  <div class="ssr-cal-detail-row"><strong>Vérifié par :</strong> <span class="ssr-cal-detail-who">—</span></div>
			  <div class="ssr-cal-detail-row"><strong>Vérifié le :</strong> <span class="ssr-cal-detail-when">—</span></div>
			  <div class="ssr-cal-detail-row"><strong>Présences :</strong> ✅ <span class="ssr-cal-detail-present">0</span> • ❌ <span class="ssr-cal-detail-absent">0</span></div>
			</div>
			<div class="ssr-cal-detail-actions">
			  <a class="ssr-cal-detail-link" href="#" target="_self" rel="nofollow">Ouvrir la page de vérification</a>
			</div>
		  </div>
		</div>


    </div>

	<script>
	/* === Sélecteur de mois === */
	(function(){
	  var sel = document.getElementById('ssr-cal-month');
	  if (!sel) return;
	  sel.addEventListener('change', function(){
		var ym = this.value;
		var url = new URL(window.location.href);
		url.searchParams.set('ym', ym);
		window.location.href = url.toString();
	  });
	})();

	/* === Panneau de détails (toggle + animation + croix) === */
	(function(){
	  const wrap   = document.querySelector('.ssr-cal-wrap');
	  const detail = document.getElementById('ssr-cal-detail');
	  if (!wrap || !detail) return;

	  const dCard = detail.querySelector('.ssr-cal-detail-card');
	  const dDate = detail.querySelector('.ssr-cal-detail-date');
	  const dWho  = detail.querySelector('.ssr-cal-detail-who');
	  const dWhen = detail.querySelector('.ssr-cal-detail-when');
	  const dPr   = detail.querySelector('.ssr-cal-detail-present');
	  const dAb   = detail.querySelector('.ssr-cal-detail-absent');
	  const dDot  = detail.querySelector('.ssr-cal-detail-dot');
	  const dLink = detail.querySelector('.ssr-cal-detail-link');
	  const closeBtn = detail.querySelector('.ssr-cal-close');

	  // base pour /retards-verif
	  const red = wrap.querySelector('.ssr-cal-cell.state-ko[href]');
	  const verifBase = red ? red.href.split('?')[0] : '';

	  let openedDate = null;

		function formatDateTimeFR(iso){
		  if (!iso) return '—';
		  try{
			// compat iOS : remplacer '-' par '/'
			const d = new Date(iso.replace(/-/g,'/'));
			// Ex: 14/10/2025 à 13:45
			const date = d.toLocaleDateString('fr-BE', { day:'2-digit', month:'2-digit', year:'numeric' });
			const time = d.toLocaleTimeString('fr-BE', { hour:'2-digit', minute:'2-digit' });
			return `${date} à ${time}`;
		  }catch(e){ return iso; }
		}

		function formatDateFR(iso){
			try{
				const d = new Date(iso.replace(/-/g,'/')); // compat iOS
				return d.toLocaleDateString('fr-BE', {
					weekday:'long', day:'numeric', month:'long', year:'numeric'
				});
			}catch(e){ return iso; }
		}
		function formatDateTimeFR(iso){
			if (!iso) return '—';
			try{
				const d = new Date(iso.replace(/-/g,'/'));
				const date = d.toLocaleDateString('fr-BE', { day:'2-digit', month:'2-digit', year:'numeric' });
				const time = d.toLocaleTimeString('fr-BE', { hour:'2-digit', minute:'2-digit' });
				return `${date} à ${time}`;
			}catch(e){ return iso; }
		}

	  // clic sur une case verte
	  wrap.addEventListener('click', function(e){
		const a = e.target.closest('.js-ssr-ok');
		if (!a) return;
		e.preventDefault();

		const date = a.getAttribute('data-date') || '';

		// toggle : referme si on reclique le même jour
		if (!detail.hidden && openedDate === date) {
		  detail.classList.remove('show');
		  setTimeout(()=> detail.hidden = true, 200);
		  openedDate = null;
		  return;
		}

		// sinon on (ré)ouvre
		const who = a.getAttribute('data-who') || '—';
		const pr  = a.getAttribute('data-present') || '0';
		const ab  = a.getAttribute('data-absent')  || '0';
		const at = a.getAttribute('data-at') || '';
		  
		dDate.textContent = formatDateFR(date);
		dWho.textContent  = who;
		dPr.textContent   = pr;
		dAb.textContent   = ab;
		dWhen.textContent = formatDateTimeFR(at);
		dDot.style.background = '#10b981';

		const base = verifBase || (window.location.origin + '<?php echo esc_js(parse_url($verif_base, PHP_URL_PATH)); ?>');
		dLink.href = base + '?date=' + encodeURIComponent(date);

		detail.hidden = false;
		openedDate = date;
		// Animation: ajoute la classe sur le conteneur (match le CSS .ssr-cal-detail.show .ssr-cal-detail-card)
		requestAnimationFrame(()=> detail.classList.add('show'));

		if (window.matchMedia('(max-width: 600px)').matches){
		  detail.scrollIntoView({behavior:'smooth', block:'start'});
		}
	  });

	  // bouton ✕ Fermer
	  if (closeBtn){
		closeBtn.addEventListener('click', function(){
		  detail.classList.remove('show');
		  setTimeout(()=> detail.hidden = true, 200);
		  openedDate = null;
		});
	  }
	})();
		// Auto-submit du changement de date
		(function(){
			const form  = document.getElementById('ssr-date-form');
			const input = document.getElementById('ssr-date-input');
			if (!form || !input) return;
			input.addEventListener('change', () => form.submit());
		})();

	</script>


    <?php
    return ob_get_clean();
});
