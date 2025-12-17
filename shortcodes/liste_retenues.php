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

	// Date de fin pour le comptage (par défaut aujourd'hui)
	$end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : $today;
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
		$end_date = $today;
	}

	// Récupère le vérificateur actuel
	$verifier = function_exists('ssr_current_verifier') ? ssr_current_verifier() : null;
	$verifier_name = $verifier['name'] ?? '';

	// Compte les absences par élève jusqu'à la date de fin
	$query = $wpdb->prepare("
		SELECT
			user_identifier,
			MAX(firstname) as firstname,
			MAX(lastname) as lastname,
			MAX(class_code) as class_code,
			COUNT(*) as nb_absences
		FROM {$ver}
		WHERE status = 'absent'
		  AND date_jour <= %s
		GROUP BY user_identifier
		HAVING nb_absences >= 5
		ORDER BY nb_absences DESC, lastname ASC, firstname ASC
	", $end_date);

	$students = $wpdb->get_results($query, ARRAY_A);

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

	/* Statistiques */
	.ssr-retenues-stats {
		display: flex;
		gap: 10px;
		flex-wrap: wrap;
		margin: 15px 0;
	}

	.ssr-stat-card {
		flex: 1 1 0;
		min-width: 150px;
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

		.ssr-stat-card {
			flex-basis: calc(50% - 5px);
		}
	}
</style>

	<h2 class="ssr-retenues-title">Liste des retenues et renvois</h2>

	<!-- Infos utiles -->
	<h3 style="text-align:left;margin-top:5px;margin-bottom:5px;font-weight:bold;">Informations utiles</h3>
	<table id="ssr-retenues-info">
		<tr>
			<th>Date de fin</th>
			<td>
				<form method="get" style="margin:0;display:flex;gap:6px;align-items:center;">
					<input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
					<button type="submit" class="ssr-small-btn">Changer</button>
				</form>
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
			<div class="ssr-stat-label">5-9 absences<br>(Retenue)</div>
		</div>
		<div class="ssr-stat-card">
			<div class="ssr-stat-number"><?php echo $count_10; ?></div>
			<div class="ssr-stat-label">10-14 absences<br>(Retenue)</div>
		</div>
		<div class="ssr-stat-card">
			<div class="ssr-stat-number"><?php echo $count_15; ?></div>
			<div class="ssr-stat-label">15-19 absences<br>(Demi-jour renvoi)</div>
		</div>
		<div class="ssr-stat-card">
			<div class="ssr-stat-number"><?php echo $count_20; ?></div>
			<div class="ssr-stat-label">20+ absences<br>(Jour de renvoi)</div>
		</div>
	</div>

	<?php if (empty($students)): ?>
		<div style="padding:20px;text-align:center;background:#f9fafb;border:1px solid #e6eaef;border-radius:8px;margin:20px auto;max-width:600px;">
			<p style="margin:0;color:#666;font-size:15px;">
				Aucun élève n'a atteint le seuil de 5 absences vérifiées jusqu'au <strong><?php echo esc_html(date_i18n('d/m/Y', strtotime($end_date))); ?></strong>.
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
					$sanction_label = 'Retenue (10+)';
					$sanction_class = 'ssr-badge-retenue';
				} else {
					$sanction_label = 'Retenue (5+)';
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
