<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('ssr_current_url')) {
  function ssr_current_url(){
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    return $scheme.$host.$uri;
  }
}

/** ===== Audit table ensure ===== */
if (!function_exists('ssr_audit_ensure_table')) {
  function ssr_audit_ensure_table(){
    global $wpdb;
    $audit = $wpdb->prefix . "smartschool_retards_verif_audit";
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS `$audit` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_identifier` VARCHAR(64) NOT NULL,
      `class_code` VARCHAR(64) DEFAULT NULL,
      `last_name` VARCHAR(190) DEFAULT NULL,
      `first_name` VARCHAR(190) DEFAULT NULL,
      `date_retard` DATE NOT NULL,
      `status_old` VARCHAR(10) DEFAULT NULL,
      `status_new` VARCHAR(10) DEFAULT NULL,
      `verified_at_old` DATETIME DEFAULT NULL,
      `verified_at_new` DATETIME DEFAULT NULL,
      `verified_by_code_new` VARCHAR(64) DEFAULT NULL,
      `verified_by_name_new` VARCHAR(190) DEFAULT NULL,
      `action` ENUM('save','reset') NOT NULL,
      `actor_code` VARCHAR(64) DEFAULT NULL,
      `actor_name` VARCHAR(190) DEFAULT NULL,
      `action_at` DATETIME NOT NULL,
      `meta` LONGTEXT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_date` (`date_retard`),
      KEY `idx_uid_date` (`user_identifier`,`date_retard`)
    ) $charset;";
    $wpdb->query($sql);
  }
}

add_shortcode('retards_verif',function(){
	if (!ssr_is_logged_in_pin()) {
		if (!function_exists('ssr_is_editor_context') || !ssr_is_editor_context()) {
			$target = add_query_arg('redirect_to', rawurlencode(ssr_current_url()), home_url('/connexion-verificateur/'));
			wp_safe_redirect($target);
			exit;
		}
		return '';
	}
	// ⏱️ Date courante selon la timezone WP (Réglages > Général)
	$tz     = wp_timezone();
	$today  = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

	// 🔎 Date demandée via ?date=YYYY-MM-DD (optionnel)
	$req    = isset($_GET['date']) ? preg_replace('/[^0-9\-]/','', $_GET['date']) : '';
	$date   = ($req && strtotime($req)) ? (new DateTimeImmutable($req, $tz))->format('Y-m-d') : $today;

	// 🛡️ Borne la date dans la fenêtre autorisée, si tu utilises ces réglages
	$cfg = function_exists('ssr_cal_get_settings') ? ssr_cal_get_settings() : ['start_date'=>'','end_date'=>''];
	if (!empty($cfg['start_date']) && $date < $cfg['start_date']) $date = $cfg['start_date'];
	if (!empty($cfg['end_date'])   && $date > $cfg['end_date'])   $date = $cfg['end_date'];

	// 🚫 Empêche les caches de figer la date (au cas où un plugin de cache est actif)
	if (!headers_sent()) nocache_headers();

	// 👉 Utilise ensuite $date dans tes requêtes/affichage, par ex. boutons précedent/suivant :
	$prev = (new DateTimeImmutable($date, $tz))->modify('-1 day')->format('Y-m-d');
	$next = (new DateTimeImmutable($date, $tz))->modify('+1 day')->format('Y-m-d');

	// Exemples de liens
	$prev_url = esc_url( add_query_arg('date', $prev, get_permalink()) );
	$next_url = esc_url( add_query_arg('date', $next, get_permalink()) );
	$today_url = esc_url( add_query_arg('date', $today, get_permalink()) );

    global $wpdb;
    $ver       = $wpdb->prefix."smartschool_retards_verif";
    $ver_audit = $wpdb->prefix."smartschool_retards_verif_audit";
    ssr_audit_ensure_table();

	$verifier      = function_exists('ssr_current_verifier') ? ssr_current_verifier() : null;
	$verifier_id   = $verifier['id']   ?? 0;
	$verifier_name = $verifier['name'] ?? '';

    $message = "";

		// === Construction de $dates selon la règle métier
		$dow = (int)(new DateTimeImmutable($date, $tz))->format('N'); // 1=lundi ... 7=dimanche

		if (!empty($req)) {
		  // Sélection explicite via ?date=YYYY-MM-DD
		  if (in_array($dow, [3,6,7], true)) {
			// Mercredi / Samedi / Dimanche => aucun élève
			$dates = [];
		  } else {
			$dates = [ $date ];
		  }
		} else {
		  // Vue par défaut (pas de ?date=) => logique multi-jours
		  if (function_exists('ssr_prev_days_for_check')) {
			$dates = ssr_prev_days_for_check(); // doit déjà renvoyer [] pour mer/sam/dim et mar+mer le jeudi
		  } else {
			// Filet de sécurité si la fonction n'existe pas
			$dates = in_array($dow, [3,6,7], true) ? [] : [ $date ];
		  }
		}



    /* ===== RESET jour (avec archivage) ===== */
    if (isset($_POST['ssr_reset_nonce']) && wp_verify_nonce($_POST['ssr_reset_nonce'],'ssr_reset_day')) {
        $reset_date = sanitize_text_field($_POST['reset_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $reset_date)) {
            $actor_code = (string)$verifier_id; $actor_name = (string)$verifier_name; $now = current_time('mysql');

            // Archive tout
            $sql_insert_archive = $wpdb->prepare("
              INSERT INTO `$ver_audit`
              (user_identifier,class_code,last_name,first_name,date_retard,
               status_old,status_new,verified_at_old,verified_at_new,
               verified_by_code_new,verified_by_name_new,
               action,actor_code,actor_name,action_at,meta)
              SELECT v.user_identifier, v.class_code, v.last_name, v.first_name, v.date_retard,
                     v.status, NULL, v.verified_at, NULL,
                     NULL, NULL,
                     'reset', %s, %s, %s, NULL
              FROM `$ver` v
              WHERE v.date_retard = %s
            ", $actor_code, $actor_name, $now, $reset_date);
            $ok_archive = $wpdb->query($sql_insert_archive);

            // Supprime
            $deleted = $wpdb->delete($ver, ['date_retard' => $reset_date], ['%s']);

            if ($deleted === false) {
                $message = "<div style='padding:10px;margin:10px 0;background:#fdeaea;color:#b00020;border-radius:6px;'>Erreur SQL : "
                         . esc_html($wpdb->last_error) . "</div>";
            } else {
                $message = "<div style='padding:10px;margin:10px 0;background:#fff8e1;color:#8a6d3b;border-radius:6px;'>"
                         . "♻️ <strong>" . esc_html($reset_date) . "</strong> réinitialisée par "
                         . "<strong>" . esc_html($verifier_name ?: ('#'.$verifier_id)) . "</strong>. "
                         . "Archivage: " . intval($ok_archive) . " — Suppression: " . intval($deleted) . ".</div>";
                if ($reset_date !== $date) { $date = $reset_date; }
            }
        } else {
            $message = "<div style='padding:10px;margin:10px 0;background:#fdeaea;color:#b00020;border-radius:6px;'>Date invalide.</div>";
        }
    }

    /* ===== Enregistrement (avec audit) ===== */
    if (isset($_POST['ssr_verif_nonce']) && wp_verify_nonce($_POST['ssr_verif_nonce'],'ssr_verif')) {
        if (!empty($_POST['verif']) && is_array($_POST['verif'])) {
            foreach ($_POST['verif'] as $key => $val) {
                list($uid,$d) = explode('|', $key);
                $uid = sanitize_text_field($uid); $d = sanitize_text_field($d);
                $class = sanitize_text_field($_POST['class'][$key] ?? '');
                $ln    = sanitize_text_field($_POST['ln'][$key] ?? '');
                $fn    = sanitize_text_field($_POST['fn'][$key] ?? '');
                $new   = ($val==='present') ? 'present' : 'absent';

                $prev = $wpdb->get_row($wpdb->prepare("
                    SELECT status, verified_at FROM `$ver`
                    WHERE user_identifier=%s AND date_retard=%s
                    ORDER BY verified_at DESC LIMIT 1
                ", $uid, $d), ARRAY_A);
                $old_status = $prev['status'] ?? null; $old_at = $prev['verified_at'] ?? null;

                $now = current_time('mysql');
                $wpdb->replace($ver, [
                    'user_identifier' => $uid,
                    'class_code'      => $class,
                    'last_name'       => $ln,
                    'first_name'      => $fn,
                    'date_retard'     => $d,
                    'status'          => $new,
                    'verified_at'     => $now,
                    'verified_by_code'=> (string)$verifier_id,
                    'verified_by_name'=> (string)$verifier_name
                ]);

                $wpdb->insert($ver_audit, [
                    'user_identifier'       => $uid,
                    'class_code'            => $class,
                    'last_name'             => $ln,
                    'first_name'            => $fn,
                    'date_retard'           => $d,
                    'status_old'            => $old_status,
                    'status_new'            => $new,
                    'verified_at_old'       => $old_at,
                    'verified_at_new'       => $now,
                    'verified_by_code_new'  => (string)$verifier_id,
                    'verified_by_name_new'  => (string)$verifier_name,
                    'action'                => 'save',
                    'actor_code'            => (string)$verifier_id,
                    'actor_name'            => (string)$verifier_name,
                    'action_at'             => $now,
                    'meta'                  => null,
                ]);
            }
            $message = "<div style='padding:10px;margin:10px 0;background:#e8f7ee;color:#0a7a33;border-radius:6px;'>"
                     . "✅ Retards enregistrés par <strong>".esc_html($verifier_name)."</strong> le "
                     . esc_html(date_i18n('Y-m-d à H:i:s')) . "</div>";
        }
    }

    // Données du jour
    $toCheck = [];
    foreach ($dates as $d) { $toCheck = array_merge($toCheck, ssr_fetch_retards_by_date($d)); }

	// Pré-remplissage (derniers statuts)
	$prevMap = [];
	if (!empty($dates)) {
		$in = implode(',', array_fill(0, count($dates), '%s'));
		$sql = "
			SELECT t.user_identifier, t.date_retard, t.status, t.verified_by_name, t.verified_at
			FROM {$ver} t
			INNER JOIN (
				SELECT user_identifier, date_retard, MAX(verified_at) AS mv
				FROM {$ver}
				WHERE date_retard IN ($in)
				GROUP BY user_identifier, date_retard
			) x ON x.user_identifier = t.user_identifier
			   AND x.date_retard    = t.date_retard
			   AND x.mv             = t.verified_at
		";
		$rowsPrev = $wpdb->get_results($wpdb->prepare($sql, ...$dates), ARRAY_A);
		if ($rowsPrev) {
			foreach ($rowsPrev as $r) {
				$k = $r['user_identifier'].'|'.$r['date_retard'];
				$prevMap[$k] = [
					'status' => ($r['status'] === 'present') ? 'present' : 'absent',
					'who'    => $r['verified_by_name'] ?? '',
					'at'     => $r['verified_at'] ?? '',
				];
			}
		}
	}

    // Tri
    usort($toCheck,function($a,$b){
        return [$a['class_code'],$a['last_name'],$a['first_name']] <=> [$b['class_code'],$b['last_name'],$b['first_name']];
    });

    $count = count($toCheck);

    // Statut global & pastilles
    $lastVerif = $wpdb->get_row(
        $wpdb->prepare("SELECT verified_by_code, verified_by_name, verified_at FROM $ver WHERE date_retard=%s ORDER BY verified_at DESC LIMIT 1", $date),
        ARRAY_A
    );
    $countPresent = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ver WHERE date_retard=%s AND status='present'", $date));
    $countAbsent  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ver WHERE date_retard=%s AND status='absent'", $date));
	if (empty($dates)) {
		// Pas de jour à vérifier aujourd’hui
		$lastVerif = null;
		$countPresent = 0;
		$countAbsent  = 0;
	}

    if ($lastVerif) {
        $whoName = $lastVerif['verified_by_name'] ?: ( ($lastVerif['verified_by_code'] ?? '') ? '#'.$lastVerif['verified_by_code'] : '' );
        $dateStr = date_i18n('d/m/Y', strtotime($lastVerif['verified_at']));
        $timeStr = date_i18n('H:i', strtotime($lastVerif['verified_at'])); // ✅ format heure sans secondes
        $statusVerif = '<div class="ssr-badges">'
            . '<span class="ssr-pill ssr-pill--ok">Vérifié</span>'
            . '<span class="ssr-pill ssr-pill--neutral">'.esc_html($whoName).'</span>'
            . '<span class="ssr-pill ssr-pill--neutral">'.esc_html($dateStr).'</span>'
            . '<span class="ssr-pill ssr-pill--neutral">'.esc_html($timeStr).'</span>'
			. '<span class="ssr-pill ssr-pill--neutral">✅ ' .intval($countPresent).'  -  ❌ ' .intval($countAbsent).'</span>'
            . '</div>';
    } else {
        $statusVerif = '<div class="ssr-badges"><span class="ssr-pill ssr-pill--warn">À vérifier</span></div>';
    }

    $hasVerified = !empty($lastVerif);

    ob_start(); ?>
    <h2 class="ssr-title" style="text-align:center;font-size:24px;font-weight:bold;color:#f57c00;margin:15px 0;">
        Retards du jour
    </h2>

<style>
	/* ===================== TABLE PRINCIPALE (élèves) ===================== */
	.ssr-table th,
	.ssr-table td {
		text-align: center;
		vertical-align: middle;
		white-space: nowrap;
		max-width: 1px;
		font-size: min(16px, 1.2vw);
		overflow: hidden;
		text-overflow: ellipsis;
		border: 1px solid #e6eaef;
	}
	.ssr-table th { font-weight: 700 !important; }

	/* === Alternance ROBUSTE pour le tableau des élèves === */
	.ssr-table tbody tr:nth-child(odd)  { background-color: #ffffff !important; }
	.ssr-table tbody tr:nth-child(even) { background-color: #f7f7f9 !important; }

	/* Les cellules restent transparentes pour laisser voir la couleur de la ligne */
	.ssr-table tbody tr:nth-child(odd)  td,
	.ssr-table tbody tr:nth-child(odd)  th,
	.ssr-table tbody tr:nth-child(even) td,
	.ssr-table tbody tr:nth-child(even) th { background-color: transparent !important; }

	/* Survol : sur la ligne + cellules transparentes */
	.ssr-table tbody tr:hover { background-color: #fff8e1 !important; }
	.ssr-table tbody tr:hover td,
	.ssr-table tbody tr:hover th { background-color: transparent !important; }

	/* Boutons Présence */
	.toggle-btn {
		display: inline-flex;
		justify-content: center;
		align-items: center;
		width: 60px; height: 40px;
		font-size: 22px; font-weight: bold;
		border-radius: 8px;
		cursor: pointer; user-select: none;
		transition: all .2s;
	}
	.toggle-btn.red   { background: #fdeaea; color: #b00020; }
	.toggle-btn.green { background: #e8f7ee; color: #0a7a33; }
	.toggle-cell      { text-align: center; vertical-align: middle; }
	.status-AM, .status-PM { color:#000; font-weight: bold; }
	.status-AMP { color:#c0392b; font-weight: bold; }

	/* ===================== PASTILLES ===================== */
	.ssr-badges { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
	.ssr-pill {
		display: inline-flex; align-items: center;
		padding: 4px 8px; border-radius: 999px;
		font-size: 12px; font-weight: 700; line-height: 1.2;
		border: 1px solid transparent; pointer-events: none;
	}
	.ssr-pill--ok      { background: #2e7d32 !important; color: #fff !important; }
	.ssr-pill--warn    { background: #c62828 !important; color: #fff !important; }
	.ssr-pill--neutral { background: #f1f3f4 !important; color: #3c4043 !important; border-color: #e0e3e7 !important; }

	/* ===================== TABLEAU RÉCAP (#ssr-info) ===================== */
	#ssr-info {
		width: 100%;
		border-collapse: separate !important;
		border-spacing: 0 8px !important; /* aération verticale */
	}
	#ssr-info, #ssr-info * { border: none !important; box-shadow: none !important; }
	#ssr-info *:not(.ssr-pill) { background: transparent !important; }
	#ssr-info th { font-weight: 700 !important; }
	#ssr-info th,
	#ssr-info td {
		vertical-align: middle !important;
		padding: 10px 0 !important;
		white-space: normal !important;
		max-width: none !important;
	}
	#ssr-info tr { height: 46px; }

	/* Champ date — bleu pâle épuré */
	#ssr-info input[type="date"] {
		background: #eef7ff !important;
		border: 1px solid #4090e0 !important;
		border-radius: 6px !important;
		color: #222 !important;
		padding: 8px 10px !important;
		transition: 0.2s;
	}
	#ssr-info input[type="date"]:focus {
		box-shadow: 0 0 0 2px rgba(64,144,224,0.3);
		outline: none;
	}

	/* Bouton “Changer” — même bleu que le champ date */
	#ssr-info .ssr-small-btn {
		background: #4090e0 !important;
		color: #fff !important;
		border: none !important;
		border-radius: 6px !important;
		font-weight: 600 !important;
		padding: 6px 14px !important;
		cursor: pointer;
		transition: background 0.2s ease;
	}
	#ssr-info .ssr-small-btn:hover { background: #2e77c8 !important; }

	/* ===================== BOUTONS BAS (Enregistrer / Réinitialiser) ===================== */
	.ssr-actions { display: flex; gap: 10px; margin-top: 12px; }
	.ssr-actions .btn {
		flex: 1 1 0; padding: 14px;
		border: none; border-radius: 10px;
		font-size: 16px; font-weight: 700;
		cursor: pointer;
	}
	.ssr-actions.single .btn { flex-basis: 100%; }
	.ssr-save  { background: #f57c00; color: #fff; }
	.ssr-save:hover  { filter: brightness(0.95); }
	.ssr-reset { background: #455a64; color: #fff; }
	.ssr-reset:hover { filter: brightness(0.95); }

	/* ===================== MOBILE ===================== */
	@media (max-width: 640px) {
		.ssr-table th, .ssr-table td { font-size: 12px !important; white-space: nowrap; }
		#ssr-info { border-spacing: 0 12px !important; }
		#ssr-info th, #ssr-info td { padding: 10px 0 !important; }
		.toggle-btn { width: 45px !important; height: 30px !important; font-size: 16px !important; border-radius: 6px; }
		table.ssr-table th, table.ssr-table td { padding: 6px 8px !important; }
	}
/* === Carte “aucun élève” === */
.ssr-empty {
  max-width: 700px;
  margin: 16px auto;
  padding: 22px;
  border-radius: 12px;
  background: #f9fafb;
  border: 1px solid #e6eaef;
  text-align: center;
}
.ssr-empty-icon {
  display: flex;
  justify-content: center;
  margin-bottom: 8px;
}
/* === Icône calendrier (message vide) === */
.ssr-empty-icon .ssr-icon {
  stroke: #4090e0;          /* bleu harmonisé avec le champ date */
  width: 50px !important;   /* taille augmentée (40 → 64) */
  height: 50px !important;  /* hauteur correspondante */
  stroke-width: 1.5;        /* traits légèrement plus épais */
}

.ssr-empty-title {
  margin: 0 0 6px 0;
  font-size: 18px;
  font-weight: 700;
  color: #333;
}
.ssr-empty-text {
  margin: 0 0 14px 0;
  color: #555;
  font-size: 14px;
}
.ssr-empty-actions {
  display: inline-flex;
  gap: 8px;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
}
.ssr-empty-date {
  padding: 8px 10px !important;
  border: 1px solid #4090e0 !important;
  border-radius: 6px !important;
  background: #eef7ff !important;
  color: #222 !important;
}
.ssr-empty-btn {
  background: #4090e0 !important;
  color: #fff !important;
  border: none !important;
  border-radius: 6px !important;
  font-weight: 600 !important;
  padding: 8px 14px !important;
  cursor: pointer;
  transition: background .2s ease;
}
.ssr-empty-btn:hover { background: #2e77c8 !important; }

/* === Zebra ultra-robuste pour le tableau des élèves === */
table.ssr-table > tbody > tr:nth-of-type(odd)  { background-color: #f7f7f9 !important; }
table.ssr-table > tbody > tr:nth-of-type(even) { background-color: #ffffff !important; }

/* Les cellules héritent du fond de leur ligne (évite le tout-blanc) */
table.ssr-table > tbody > tr > td,
table.ssr-table > tbody > tr > th {
  background-color: inherit !important;
}

/* Survol harmonisé */
table.ssr-table > tbody > tr:hover { background-color: #fff8e1 !important; }
table.ssr-table > tbody > tr:hover > td,
table.ssr-table > tbody > tr:hover > th {
  background-color: transparent !important;
}

</style>


    <?php echo $message; ?>

    <!-- Tableau récap SANS bordures -->
	<h3 style="text-align:left;margin-top:5px;margin-bottom:5px;font-weight:bold;">Informations utiles</h3>
	<table id="ssr-info" style="max-width:800px;margin:10px auto;font-size:16px;">
		<tr>
			<th style="width:30%;text-align:left;">Date</th>
			<td style="text-align:left;">
				<form id="ssr-date-form" method="get" style="margin:0;display:flex;gap:6px;align-items:center;">
					<input id="ssr-date-input" type="date" name="date" value="<?php echo esc_attr($date); ?>" style="padding:5px;">
					<button id="ssr-date-submit" type="submit" class="button ssr-small-btn">Changer</button>
				</form>
			</td>
		</tr>
		<tr>
			<th style="text-align:left;">Vérificateur</th>
			<td style="text-align:left;">
				<div class="ssr-badges">
					<span class="ssr-pill ssr-pill--neutral">
						<?php echo esc_html($verifier_name ?: ''); ?>
					</span>
				</div>
			</td>
		</tr>
	  <tr>
		<th style="text-align:left;">Élèves</th>
		<td style="text-align:left;">
		  <div class="ssr-badges">
			<span class="ssr-pill ssr-pill--neutral"><?php echo (int)$count; ?></span>
		  </div>
		</td>
	  </tr>

	  <tr>
		<th style="text-align:left;">Statut</th>
		<td style="text-align:left;"><?php echo $statusVerif; ?></td>
	  </tr>
	</table>

		<?php if (!$count): ?>
		<div class="ssr-empty">
			<div class="ssr-empty-icon">
				<svg class="ssr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
					 stroke-linecap="round" stroke-linejoin="round" width="40" height="40">
					<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
					<line x1="16" y1="2" x2="16" y2="6"></line>
					<line x1="8" y1="2" x2="8" y2="6"></line>
					<line x1="3" y1="10" x2="21" y2="10"></line>
				</svg>
			</div>
			<h4 class="ssr-empty-title">Pas d’élèves à vérifier</h4>
			<p class="ssr-empty-text">
				Il n’y a aucun élève à contrôler pour cette date. Choisissez une autre date si besoin.
			</p>
			<form method="get" class="ssr-empty-actions">
				<input type="date" name="date" value="<?php echo esc_attr($date); ?>" class="ssr-empty-date">
				<button type="submit" class="ssr-empty-btn">Choisir une autre date</button>
			</form>
		</div>
		<?php else: ?>


        <h3 style="text-align:left;margin-top:15px;margin-bottom:10px;font-weight:bold;">Élèves devant se présenter</h3>

        <!-- Formulaire principal (enregistrer) -->
        <form method="post" id="ssr-form">
            <?php wp_nonce_field('ssr_verif','ssr_verif_nonce'); ?>
            <table class="ssr-table">
                <thead>
                    <tr>
                        <th>Classe</th>
                        <th>Nom</th>
                        <th>Prénom</th>
                        <th>Retard</th>
                        <th>Présence</th>
                    </tr>
                </thead>
                <tbody>
					<?php foreach ($toCheck as $row):
						$uid   = (string)($row['userIdentifier'] ?? '');
						$dret  = (string)($row['date_retard']    ?? $date);
						$key   = esc_attr($uid.'|'.$dret);
						$display = $row['status_raw'] ?? "—";
                        $classStatus = ($display==="AM+PM") ? "status-AMP" : (($display==="AM") ? "status-AM" : (($display==="PM") ? "status-PM" : ""));
						$initial = 'absent';
						if (isset($prevMap[$uid.'|'.$dret])) {
							$k = $uid.'|'.$dret; $initial = (($prevMap[$k]['status'] ?? 'absent') === 'present') ? 'present' : 'absent';
						}
						$btnClass = ($initial === 'present') ? 'green' : 'red';
						$btnText  = ($initial === 'present') ? '✅' : '❌';
					?>
						<tr>
							<td><?php echo esc_html($row['class_code'] ?? '❓'); ?></td>
							<td><?php echo esc_html($row['last_name'] ?? ''); ?></td>
							<td><?php echo esc_html($row['first_name'] ?? ''); ?></td>
							<td class="<?php echo esc_attr($classStatus); ?>"><?php echo esc_html($display); ?></td>
							<td class="toggle-cell">
								<span class="toggle-btn <?php echo esc_attr($btnClass); ?>" data-key="<?php echo $key; ?>"><?php echo $btnText; ?></span>
								<input type="hidden" name="verif[<?php echo $key; ?>]" value="<?php echo esc_attr($initial); ?>">
							</td>
						</tr>
						<input type="hidden" name="class[<?php echo $key; ?>]" value="<?php echo esc_attr($row['class_code'] ?? ''); ?>">
						<input type="hidden" name="ln[<?php echo $key; ?>]" value="<?php echo esc_attr($row['last_name'] ?? ''); ?>">
						<input type="hidden" name="fn[<?php echo $key; ?>]" value="<?php echo esc_attr($row['first_name'] ?? ''); ?>">
					<?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <?php if ($hasVerified): ?>
          <!-- Formulaire reset séparé uniquement si déjà vérifié -->
          <form method="post" id="ssr-reset-form" style="margin:0;">
              <?php wp_nonce_field('ssr_reset_day','ssr_reset_nonce'); ?>
              <input type="hidden" name="reset_date" value="<?php echo esc_attr($date); ?>">
          </form>
        <?php endif; ?>

        <!-- Zone actions : 50/50 si 2 boutons, 100% si 1 seul -->
        <div class="ssr-actions <?php echo $hasVerified ? '' : 'single'; ?>">
          <button type="submit" form="ssr-form" class="btn ssr-save">Enregistrer</button>
          <?php if ($hasVerified): ?>
            <button type="button" id="ssr-reset-trigger" class="btn ssr-reset">Réinitialiser</button>
          <?php endif; ?>
        </div>

        <script>
        // Toggle présence
        document.querySelectorAll(".toggle-btn").forEach(btn=>{
            btn.addEventListener("click",()=>{
                let input = btn.parentElement.querySelector("input[type=hidden]");
                if(btn.classList.contains("red")){
                    btn.classList.remove("red"); btn.classList.add("green");
                    btn.textContent = "✅"; input.value="present";
                } else {
                    btn.classList.remove("green"); btn.classList.add("red");
                    btn.textContent = "❌"; input.value="absent";
                }
            });
        });

        // Confirmation native (fenêtre de l’appareil) pour reset
        <?php if ($hasVerified): ?>
        (function(){
          const resetBtn  = document.getElementById('ssr-reset-trigger');
          const resetForm = document.getElementById('ssr-reset-form');
          if (resetBtn && resetForm){
            resetBtn.addEventListener('click', ()=>{
              const d = "<?php echo esc_js($date); ?>";
              const ok = window.confirm(
                "Souhaitez-vous réinitialiser la date " + d + " ?"
              );
              if (ok) resetForm.submit();
            });
          }
        })();
        <?php endif; ?>
// Auto-submit date reliably on mobile (iOS Safari, Android Chrome, etc.)
(function(){
  var form      = document.getElementById('ssr-date-form');
  var input     = document.getElementById('ssr-date-input');
  var submitBtn = document.getElementById('ssr-date-submit');
  if (!form || !input) return;

  var last = input.value;
  var pending = null;

  function reallySubmit(){
    // Prefer requestSubmit to simulate a real user submit (best for iOS)
    try {
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(submitBtn || null);
        return;
      }
    } catch(e){}
    // Fallbacks
    if (submitBtn && typeof submitBtn.click === 'function') {
      submitBtn.click();
    } else {
      form.submit();
    }
  }

  function scheduleSubmit(delay){
    if (pending) { clearTimeout(pending); pending = null; }
    pending = setTimeout(function(){
      if (input.value && input.value !== last) {
        last = input.value;
        reallySubmit();
      }
    }, delay);
  }

  // Fires when picker closes on iOS or when a date is confirmed
  input.addEventListener('change', function(){
    scheduleSubmit(0);
  });

  // Android/Chrome updates value during picking → debounce slightly
  input.addEventListener('input', function(){
    scheduleSubmit(250);
  });

  // As a final guard on some keyboards/pickers
  input.addEventListener('blur', function(){
    scheduleSubmit(0);
  });
})();
</script>
    <?php endif; ?>
    <?php
    return ob_get_clean();
});
