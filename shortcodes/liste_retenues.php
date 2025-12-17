<?php
if (!defined('ABSPATH')) exit;

add_shortcode('liste_retenues', function() {
	if (!ssr_is_logged_in_pin()) {
		if (!function_exists('ssr_is_editor_context') || !ssr_is_editor_context()) {
			$target = add_query_arg('redirect_to', rawurlencode($_SERVER['REQUEST_URI'] ?? ''), home_url('/connexion-verificateur/'));
			wp_safe_redirect($target);
			exit;
		}
		return '';
	}

	global $wpdb;
	$ver = $wpdb->prefix . 'smartschool_retards_verif';

	// Timezone
	$tz = wp_timezone();
	$today = (new DateTime('now', $tz))->format('Y-m-d');

	// Récupère le vérificateur actuel
	$verifier = function_exists('ssr_current_verifier') ? ssr_current_verifier() : null;
	$verifier_name = $verifier['name'] ?? '';

	// Compte les absences par élève jusqu'à aujourd'hui
	$query = $wpdb->prepare("
		SELECT
			user_identifier,
			MAX(firstname) as firstname,
			MAX(lastname) as lastname,
			MAX(class_code) as class_code,
			COUNT(*) as nb_absences
		FROM {$ver}
		WHERE status = 'absent'
		  AND date_retard <= %s
		GROUP BY user_identifier
		HAVING nb_absences >= 5
		ORDER BY nb_absences DESC, lastname ASC, firstname ASC
	", $today);

	$students = $wpdb->get_results($query, ARRAY_A);

	// 🔍 DEBUG - Vérifier tous les élèves (même ceux avec moins de 5 absences)
	$debug_query = $wpdb->prepare("
		SELECT
			user_identifier,
			MAX(firstname) as firstname,
			MAX(lastname) as lastname,
			MAX(class_code) as class_code,
			COUNT(*) as nb_absences,
			GROUP_CONCAT(CONCAT(date_retard, ':', status) ORDER BY date_retard SEPARATOR ' | ') as details
		FROM {$ver}
		WHERE date_retard <= %s
		GROUP BY user_identifier
		ORDER BY nb_absences DESC, lastname ASC, firstname ASC
	", $today);
	$all_students = $wpdb->get_results($debug_query, ARRAY_A);

	// Calcul des statistiques
	$total_students = count($students);
	$count_5 = 0;
	$count_10 = 0;
	$count_15 = 0;
	$count_20 = 0;

	foreach ($students as $s) {
		$nb = (int)$s['nb_absences'];
		if ($nb >= 20) $count_20++;
		elseif ($nb >= 15) $count_15++;
		elseif ($nb >= 10) $count_10++;
		elseif ($nb >= 5) $count_5++;
	}

	ob_start();
	?>

<style>
	/* Reprise du style de retards_verif.php */
	.ssr-retenues-title {
		text-align: center;
		font-size: 24px;
		font-weight: bold;
		color: #f57c00;
		margin: 15px 0;
	}

	/* Table principale */
	.ssr-retenues-table {
		width: 100%;
		max-width: 1200px;
		margin: 20px auto;
		border-collapse: collapse;
		background: #fff;
		border-radius: 8px;
		overflow: hidden;
		box-shadow: 0 2px 8px rgba(0,0,0,0.1);
	}

	.ssr-retenues-table th,
	.ssr-retenues-table td {
		text-align: center;
		vertical-align: middle;
		padding: 12px 8px;
		border: 1px solid #e6eaef;
		font-size: 15px;
	}

	.ssr-retenues-table th {
		background: #f5f5f7;
		font-weight: 700;
		color: #333;
	}

	/* Alternance zebra */
	.ssr-retenues-table tbody tr:nth-child(odd) {
		background-color: #ffffff;
	}

	.ssr-retenues-table tbody tr:nth-child(even) {
		background-color: #f7f7f9;
	}

	.ssr-retenues-table tbody tr:hover {
		background-color: #fff8e1 !important;
	}

	/* Badges de sanction */
	.ssr-badge-sanction {
		display: inline-block;
		padding: 6px 12px;
		border-radius: 999px;
		font-size: 13px;
		font-weight: 700;
		text-align: center;
	}

	.ssr-badge-retenue {
		background: #fff3cd;
		color: #856404;
		border: 1px solid #ffc107;
	}

	.ssr-badge-renvoi-demi {
		background: #ffe0b2;
		color: #e65100;
		border: 1px solid #ff9800;
	}

	.ssr-badge-renvoi {
		background: #fdeaea;
		color: #b00020;
		border: 1px solid #d32f2f;
	}

	/* Nombre d'absences en gras */
	.ssr-nb-absences {
		font-weight: bold;
		font-size: 16px;
		color: #d32f2f;
	}

	/* Tableau récap (infos utiles) */
	#ssr-retenues-info {
		width: 100%;
		max-width: 800px;
		margin: 10px auto;
		border-collapse: separate;
		border-spacing: 0 8px;
	}

	#ssr-retenues-info th,
	#ssr-retenues-info td {
		vertical-align: middle;
		padding: 10px 0;
		border: none;
		background: transparent;
	}

	#ssr-retenues-info th {
		width: 30%;
		text-align: left;
		font-weight: 700;
	}

	#ssr-retenues-info td {
		text-align: left;
	}

	#ssr-retenues-info input[type="date"] {
		background: #eef7ff;
		border: 1px solid #4090e0;
		border-radius: 6px;
		color: #222;
		padding: 8px 10px;
		transition: 0.2s;
	}

	#ssr-retenues-info input[type="date"]:focus {
		box-shadow: 0 0 0 2px rgba(64,144,224,0.3);
		outline: none;
	}

	#ssr-retenues-info .ssr-small-btn {
		background: #4090e0;
		color: #fff;
		border: none;
		border-radius: 6px;
		font-weight: 600;
		padding: 6px 14px;
		cursor: pointer;
		transition: background 0.2s ease;
	}

	#ssr-retenues-info .ssr-small-btn:hover {
		background: #2e77c8;
	}

	/* Pastilles */
	.ssr-badges {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
		align-items: center;
	}

	.ssr-pill {
		display: inline-flex;
		align-items: center;
		padding: 4px 8px;
		border-radius: 999px;
		font-size: 12px;
		font-weight: 700;
		line-height: 1.2;
		border: 1px solid transparent;
	}

	.ssr-pill--neutral {
		background: #f1f3f4;
		color: #3c4043;
		border-color: #e0e3e7;
	}

	/* Statistiques - Affichage 2-2 FORCÉ */
	.ssr-retenues-stats {
		display: grid;
		grid-template-columns: repeat(2, 1fr);
		gap: 10px;
		margin: 15px auto;
		max-width: 1200px;
	}

	.ssr-stat-card {
		padding: 12px;
		background: #f9fafb;
		border: 1px solid #e6eaef;
		border-radius: 8px;
		text-align: center;
	}

	.ssr-stat-number {
		font-size: 28px;
		font-weight: bold;
		color: #333;
	}

	.ssr-stat-label {
		font-size: 13px;
		color: #666;
		margin-top: 4px;
	}

	/* Mobile */
	@media (max-width: 640px) {
		.ssr-retenues-table th,
		.ssr-retenues-table td {
			font-size: 12px;
			padding: 8px 4px;
		}
	}
</style>

	<h2 class="ssr-retenues-title">Liste des retenues et renvois</h2>

	<!-- Infos utiles -->
	<h3 style="text-align:left;margin-top:5px;margin-bottom:5px;font-weight:bold;">Informations utiles</h3>
	<table id="ssr-retenues-info">
		<tr>
			<th>Date du jour</th>
			<td>
				<div class="ssr-badges">
					<span class="ssr-pill ssr-pill--neutral">
						<?php echo esc_html(date_i18n('d/m/Y', strtotime($today))); ?>
					</span>
				</div>
			</td>
		</tr>
		<tr>
			<th>Vérificateur</th>
			<td>
				<div class="ssr-badges">
					<span class="ssr-pill ssr-pill--neutral">
						<?php echo esc_html($verifier_name); ?>
					</span>
				</div>
			</td>
		</tr>
		<tr>
			<th>Élèves concernés</th>
			<td>
				<div class="ssr-badges">
					<span class="ssr-pill ssr-pill--neutral"><?php echo (int)$total_students; ?></span>
				</div>
			</td>
		</tr>
	</table>

	<!-- Statistiques -->
	<h3 style="text-align:left;margin-top:15px;margin-bottom:10px;font-weight:bold;">Répartition par sanction</h3>
	<div class="ssr-retenues-stats">
		<div class="ssr-stat-card">
			<div class="ssr-stat-number"><?php echo $count_5; ?></div>
			<div class="ssr-stat-label">Retenue 1<br>(5-9 absences)</div>
		</div>
		<div class="ssr-stat-card">
			<div class="ssr-stat-number"><?php echo $count_10; ?></div>
			<div class="ssr-stat-label">Retenue 2<br>(10-14 absences)</div>
		</div>
		<div class="ssr-stat-card">
			<div class="ssr-stat-number"><?php echo $count_15; ?></div>
			<div class="ssr-stat-label">Demi-jour de renvoi<br>(15-19 absences)</div>
		</div>
		<div class="ssr-stat-card">
			<div class="ssr-stat-number"><?php echo $count_20; ?></div>
			<div class="ssr-stat-label">Jour de renvoi<br>(20+ absences)</div>
		</div>
	</div>

	<!-- 🔍 PANNEAU DE DEBUG (à supprimer après test) -->
	<details style="margin:20px 0;padding:15px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;">
		<summary style="cursor:pointer;font-weight:bold;color:#856404;">🔍 Debug : Voir tous les élèves (cliquer pour afficher)</summary>
		<div style="margin-top:15px;font-family:monospace;font-size:12px;max-height:400px;overflow-y:auto;">
			<p style="margin:0 0 10px 0;color:#856404;">
				<strong>Total d'élèves dans la base :</strong> <?php echo count($all_students); ?><br>
				<strong>Élèves affichés (≥5 absences) :</strong> <?php echo count($students); ?><br>
				<strong>Date de référence :</strong> <?php echo esc_html($today); ?>
			</p>
			<table style="width:100%;border-collapse:collapse;font-size:11px;">
				<thead>
					<tr style="background:#856404;color:#fff;">
						<th style="padding:5px;border:1px solid #ccc;">Nom</th>
						<th style="padding:5px;border:1px solid #ccc;">Prénom</th>
						<th style="padding:5px;border:1px solid #ccc;">Classe</th>
						<th style="padding:5px;border:1px solid #ccc;">Total</th>
						<th style="padding:5px;border:1px solid #ccc;">Absents</th>
						<th style="padding:5px;border:1px solid #ccc;">Présents</th>
						<th style="padding:5px;border:1px solid #ccc;">Détails</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($all_students as $s):
						$details_arr = explode(' | ', $s['details'] ?? '');
						$count_absent = 0;
						$count_present = 0;
						foreach ($details_arr as $d) {
							if (strpos($d, ':absent') !== false) $count_absent++;
							elseif (strpos($d, ':present') !== false) $count_present++;
						}
						$row_color = ($count_absent >= 5) ? '#e8f5e9' : '#fafafa';
					?>
					<tr style="background:<?php echo $row_color; ?>;">
						<td style="padding:5px;border:1px solid #ccc;"><?php echo esc_html($s['lastname'] ?? '—'); ?></td>
						<td style="padding:5px;border:1px solid #ccc;"><?php echo esc_html($s['firstname'] ?? '—'); ?></td>
						<td style="padding:5px;border:1px solid #ccc;"><?php echo esc_html($s['class_code'] ?? '—'); ?></td>
						<td style="padding:5px;border:1px solid #ccc;font-weight:bold;"><?php echo (int)$s['nb_absences']; ?></td>
						<td style="padding:5px;border:1px solid #ccc;color:#d32f2f;font-weight:bold;"><?php echo $count_absent; ?></td>
						<td style="padding:5px;border:1px solid #ccc;color:#2e7d32;"><?php echo $count_present; ?></td>
						<td style="padding:5px;border:1px solid #ccc;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr($s['details'] ?? ''); ?>">
							<?php echo esc_html($s['details'] ?? '—'); ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin:15px 0 0 0;color:#856404;font-size:11px;">
				<strong>Légende :</strong><br>
				• Fond vert clair = Élève avec ≥5 absences (devrait apparaître dans la liste)<br>
				• Fond gris = Élève avec &lt;5 absences (ne devrait pas apparaître)<br>
				• Colonne "Absents" = Nombre de lignes avec status='absent'<br>
				• Colonne "Présents" = Nombre de lignes avec status='present'
			</p>
		</div>
	</details>

	<?php if (empty($students)): ?>
		<div style="padding:20px;text-align:center;background:#f9fafb;border:1px solid #e6eaef;border-radius:8px;margin:20px auto;max-width:600px;">
			<p style="margin:0;color:#666;font-size:15px;">
				Aucun élève n'a atteint le seuil de 5 absences vérifiées jusqu'à aujourd'hui.
			</p>
		</div>
	<?php else: ?>

	<!-- Liste des élèves -->
	<h3 style="text-align:left;margin-top:15px;margin-bottom:10px;font-weight:bold;">Liste complète</h3>
	<table class="ssr-retenues-table">
		<thead>
			<tr>
				<th>Classe</th>
				<th>Nom</th>
				<th>Prénom</th>
				<th>Nb absences</th>
				<th>Sanction</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($students as $student):
				$nb = (int)$student['nb_absences'];

				// Déterminer la sanction
				if ($nb >= 20) {
					$sanction_label = 'Jour de renvoi';
					$sanction_class = 'ssr-badge-renvoi';
				} elseif ($nb >= 15) {
					$sanction_label = 'Demi-jour de renvoi';
					$sanction_class = 'ssr-badge-renvoi-demi';
				} elseif ($nb >= 10) {
					$sanction_label = 'Retenue 2';
					$sanction_class = 'ssr-badge-retenue';
				} else {
					$sanction_label = 'Retenue 1';
					$sanction_class = 'ssr-badge-retenue';
				}
			?>
			<tr>
				<td><?php echo esc_html($student['class_code'] ?? '—'); ?></td>
				<td><?php echo esc_html($student['lastname'] ?? '—'); ?></td>
				<td><?php echo esc_html($student['firstname'] ?? '—'); ?></td>
				<td><span class="ssr-nb-absences"><?php echo $nb; ?></span></td>
				<td>
					<span class="ssr-badge-sanction <?php echo $sanction_class; ?>">
						<?php echo esc_html($sanction_label); ?>
					</span>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php endif; ?>

	<?php
	return ob_get_clean();
});
