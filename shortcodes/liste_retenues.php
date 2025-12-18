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

	// Vérifier que la table existe (comme dans recap_retards.php ligne 102)
	$has_verif = $wpdb->get_var($wpdb->prepare(
		"SHOW TABLES LIKE %s",
		$wpdb->esc_like($ver)
	)) === $ver;

	if (!$has_verif) {
		return '<p style="color:red;">⚠️ La table ' . esc_html($ver) . ' n\'existe pas dans la base de données.</p>';
	}

	// Timezone
	$tz = wp_timezone();
	$today = (new DateTime('now', $tz))->format('Y-m-d');

	// Récupère le vérificateur actuel
	$verifier = function_exists('ssr_current_verifier') ? ssr_current_verifier() : null;
	$verifier_name = $verifier['name'] ?? '';

	// Compte les absences par élève jusqu'à aujourd'hui (EXACTEMENT comme recap_retards ligne 108)
	$query = $wpdb->prepare("
		SELECT
			user_identifier,
			MAX(first_name) as firstname,
			MAX(last_name) as lastname,
			MAX(class_code) as class_code,
			COUNT(*) as nb_absences
		FROM {$ver}
		WHERE status = 'absent'
		  AND date_retard <= %s
		GROUP BY user_identifier
		HAVING nb_absences >= 5
		ORDER BY MAX(class_code) ASC, MAX(last_name) ASC, MAX(first_name) ASC
	", $today);

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

	/* Retenue 1 (5-9) : Jaune */
	.ssr-badge-retenue-1 {
		background: #fff3cd;
		color: #856404;
		border: 1px solid #ffc107;
	}

	/* Retenue 2 (10-14) : Orange */
	.ssr-badge-retenue-2 {
		background: #fff7ed;
		color: #c2410c;
		border: 1px solid #fb923c;
	}

	/* Demi-jour renvoi (15-19) : Rouge clair */
	.ssr-badge-renvoi-demi {
		background: #fee2e2;
		color: #991b1b;
		border: 1px solid #f87171;
	}

	/* Jour de renvoi (20+) : Rouge foncé */
	.ssr-badge-renvoi {
		background: #fdeaea;
		color: #7f1d1d;
		border: 1px solid #dc2626;
	}

	/* Nombre d'absences en gras */
	.ssr-nb-absences {
		font-weight: bold;
		font-size: 16px;
		color: #d32f2f;
	}

	/* Statistiques - Bouton "Tous" seul + 2x2 pour les autres */
	.ssr-retenues-stats {
		display: grid;
		grid-template-columns: 1fr;
		gap: 10px;
		margin: 15px auto;
		max-width: 1200px;
	}

	.ssr-retenues-filters {
		display: grid;
		grid-template-columns: repeat(2, 1fr);
		gap: 10px;
	}

	.ssr-retenues-filters-date {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 10px;
	}

	.ssr-stat-card {
		padding: 12px;
		background: #f9fafb;
		border: 1px solid #e6eaef;
		border-radius: 8px;
		text-align: center;
		cursor: pointer;
		transition: all 0.2s ease;
		font-family: inherit;
		width: 100%;
	}

	.ssr-stat-card:hover {
		background: #e5e7eb;
		border-color: #d1d5db;
		transform: translateY(-2px);
		box-shadow: 0 4px 6px rgba(0,0,0,0.1);
	}

	.ssr-stat-card.active {
		background: #fff7ed;
		border: 2px solid #f57c00;
		box-shadow: 0 0 0 3px rgba(245,124,0,0.1);
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

	/* Wrapper date de sanction */
	.ssr-date-wrapper {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		justify-content: center;
	}

	/* Champ date de sanction */
	.ssr-date-sanction {
		padding: 6px 8px;
		border: 2px solid #d1d5db;
		border-radius: 6px;
		background: #f3f4f6;
		font-size: 13px;
		width: 120px;
		transition: all 0.2s;
		text-align: center;
		position: relative;
		color: #666;
	}

	/* Afficher "à fixer" quand le champ est vide */
	.ssr-date-sanction:not(.has-value)::before {
		content: 'à fixer';
		color: #999;
		pointer-events: none;
		font-size: 13px;
	}

	.ssr-date-sanction:not(.has-value)::-webkit-datetime-edit {
		opacity: 0;
	}

	.ssr-date-sanction.has-value::-webkit-datetime-edit {
		opacity: 1;
	}

	/* Quand une date est choisie, agrandir pour laisser place à la croix */
	.ssr-date-sanction.has-value {
		background: #e8f7ee;
		border-color: #2e7d32;
		color: #222;
		width: 105px;
		font-size: 12px;
	}

	.ssr-date-sanction:focus {
		border-color: #f57c00;
		outline: none;
		box-shadow: 0 0 0 2px rgba(245,124,0,0.1);
	}

	/* Bouton croix pour annuler la date */
	.ssr-clear-date {
		background: #ef4444;
		color: #fff;
		border: none;
		border-radius: 4px;
		width: 24px;
		height: 24px;
		cursor: pointer;
		font-size: 14px;
		line-height: 1;
		padding: 0;
		display: none;
		transition: background 0.2s;
		flex-shrink: 0;
	}

	.ssr-clear-date:hover {
		background: #dc2626;
	}

	.ssr-date-wrapper.has-date .ssr-clear-date {
		display: inline-flex;
		align-items: center;
		justify-content: center;
	}

	/* Centrer la date dans sa cellule */
	.ssr-retenues-table td:last-child {
		text-align: center;
	}

	/* Lignes cachées par le filtre */
	.ssr-student-row.hidden {
		display: none;
	}

	/* Mobile */
	@media (max-width: 640px) {
		.ssr-retenues-table th,
		.ssr-retenues-table td {
			font-size: 12px;
			padding: 8px 4px;
		}

		.ssr-date-sanction {
			width: 100px;
			font-size: 12px;
		}

		.ssr-date-sanction.has-value {
			width: 90px;
			font-size: 11px;
		}

		.ssr-clear-date {
			width: 20px;
			height: 20px;
			font-size: 12px;
		}

		.ssr-retenues-filters-date {
			grid-template-columns: 1fr;
		}
	}
</style>

	<h2 class="ssr-retenues-title">Liste des retenues et renvois</h2>

	<!-- Statistiques - BOUTONS FILTRES -->
	<h3 style="text-align:left;margin-top:15px;margin-bottom:10px;font-weight:bold;">Filtrer par sanction</h3>
	<div class="ssr-retenues-stats">
		<!-- Bouton "Tous les élèves" seul sur sa ligne -->
		<button type="button" class="ssr-stat-card ssr-filter-btn active" data-filter="all" data-filter-type="sanction">
			<div class="ssr-stat-number"><?php echo $total_students; ?></div>
			<div class="ssr-stat-label">Tous les élèves</div>
		</button>

		<!-- Grille 2x2 pour les autres boutons -->
		<div class="ssr-retenues-filters">
			<button type="button" class="ssr-stat-card ssr-filter-btn" data-filter="5-9" data-filter-type="sanction">
				<div class="ssr-stat-number"><?php echo $count_5; ?></div>
				<div class="ssr-stat-label">Retenue 1<br>(5-9 absences)</div>
			</button>
			<button type="button" class="ssr-stat-card ssr-filter-btn" data-filter="10-14" data-filter-type="sanction">
				<div class="ssr-stat-number"><?php echo $count_10; ?></div>
				<div class="ssr-stat-label">Retenue 2<br>(10-14 absences)</div>
			</button>
			<button type="button" class="ssr-stat-card ssr-filter-btn" data-filter="15-19" data-filter-type="sanction">
				<div class="ssr-stat-number"><?php echo $count_15; ?></div>
				<div class="ssr-stat-label">Demi-jour de renvoi<br>(15-19 absences)</div>
			</button>
			<button type="button" class="ssr-stat-card ssr-filter-btn" data-filter="20+" data-filter-type="sanction">
				<div class="ssr-stat-number"><?php echo $count_20; ?></div>
				<div class="ssr-stat-label">Jour de renvoi<br>(20+ absences)</div>
			</button>
		</div>
	</div>

	<!-- Filtres par date fixée ou non -->
	<h3 style="text-align:left;margin-top:20px;margin-bottom:10px;font-weight:bold;">Filtrer par statut de date</h3>
	<div class="ssr-retenues-stats">
		<div class="ssr-retenues-filters-date">
			<button type="button" class="ssr-stat-card ssr-filter-date active" data-filter="all-dates">
				<div class="ssr-stat-label">Tous</div>
			</button>
			<button type="button" class="ssr-stat-card ssr-filter-date" data-filter="with-date">
				<div class="ssr-stat-label">Avec date fixée</div>
			</button>
			<button type="button" class="ssr-stat-card ssr-filter-date" data-filter="without-date">
				<div class="ssr-stat-label">Sans date fixée</div>
			</button>
		</div>
	</div>

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
				<th>Date de sanction</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($students as $student):
				$nb = (int)$student['nb_absences'];

				// Déterminer la sanction et la catégorie pour le filtrage
				if ($nb >= 20) {
					$sanction_label = 'Jour de renvoi';
					$sanction_class = 'ssr-badge-renvoi';
					$filter_category = '20+';
				} elseif ($nb >= 15) {
					$sanction_label = 'Demi-jour de renvoi';
					$sanction_class = 'ssr-badge-renvoi-demi';
					$filter_category = '15-19';
				} elseif ($nb >= 10) {
					$sanction_label = 'Retenue 2';
					$sanction_class = 'ssr-badge-retenue-2';
					$filter_category = '10-14';
				} else {
					$sanction_label = 'Retenue 1';
					$sanction_class = 'ssr-badge-retenue-1';
					$filter_category = '5-9';
				}
			?>
			<tr class="ssr-student-row" data-category="<?php echo esc_attr($filter_category); ?>">
				<td><?php echo esc_html($student['class_code'] ?? '—'); ?></td>
				<td><?php echo esc_html($student['lastname'] ?? '—'); ?></td>
				<td><?php echo esc_html($student['firstname'] ?? '—'); ?></td>
				<td><span class="ssr-nb-absences"><?php echo $nb; ?></span></td>
				<td>
					<span class="ssr-badge-sanction <?php echo $sanction_class; ?>">
						<?php echo esc_html($sanction_label); ?>
					</span>
				</td>
				<td>
					<div class="ssr-date-wrapper">
						<input type="date" class="ssr-date-sanction" value="" />
						<button type="button" class="ssr-clear-date" title="Annuler la date">×</button>
					</div>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php endif; ?>

<script>
(function() {
	// Attendre que le DOM soit complètement chargé
	document.addEventListener('DOMContentLoaded', function() {
		const filterBtns = document.querySelectorAll('.ssr-filter-btn');
		const filterDateBtns = document.querySelectorAll('.ssr-filter-date');
		const rows = document.querySelectorAll('.ssr-student-row');

		// État des filtres
		let currentSanctionFilter = 'all';
		let currentDateFilter = 'all-dates';

		// Fonction pour appliquer les filtres combinés
		function applyFilters() {
			rows.forEach(row => {
				const category = row.getAttribute('data-category');
				const dateInput = row.querySelector('.ssr-date-sanction');
				const hasDate = dateInput && dateInput.value && dateInput.value.trim() !== '';

				// Vérifier le filtre par sanction
				const matchesSanction = currentSanctionFilter === 'all' || category === currentSanctionFilter;

				// Vérifier le filtre par date
				let matchesDate = true;
				if (currentDateFilter === 'with-date') {
					matchesDate = hasDate;
				} else if (currentDateFilter === 'without-date') {
					matchesDate = !hasDate;
				}

				// Afficher seulement si les deux filtres correspondent
				if (matchesSanction && matchesDate) {
					row.classList.remove('hidden');
				} else {
					row.classList.add('hidden');
				}
			});
		}

		// ===== Filtrage par catégorie de sanction =====
		filterBtns.forEach(btn => {
			btn.addEventListener('click', function(e) {
				e.preventDefault();
				currentSanctionFilter = this.getAttribute('data-filter');

				// Retire la classe active de tous les boutons de sanction
				filterBtns.forEach(b => b.classList.remove('active'));
				// Ajoute la classe active au bouton cliqué
				this.classList.add('active');

				// Applique les filtres
				applyFilters();
			});
		});

		// ===== Filtrage par date fixée/non fixée =====
		filterDateBtns.forEach(btn => {
			btn.addEventListener('click', function(e) {
				e.preventDefault();
				currentDateFilter = this.getAttribute('data-filter');

				// Retire la classe active de tous les boutons de date
				filterDateBtns.forEach(b => b.classList.remove('active'));
				// Ajoute la classe active au bouton cliqué
				this.classList.add('active');

				// Applique les filtres
				applyFilters();
			});
		});

		// ===== Gestion des champs de date =====
		document.querySelectorAll('.ssr-date-wrapper').forEach(wrapper => {
			const input = wrapper.querySelector('.ssr-date-sanction');
			const clearBtn = wrapper.querySelector('.ssr-clear-date');

			if (!input || !clearBtn) return;

			// Mettre à jour l'apparence selon la valeur
			function updateAppearance() {
				if (input.value && input.value.trim() !== '') {
					input.classList.add('has-value');
					wrapper.classList.add('has-date');
				} else {
					input.classList.remove('has-value');
					wrapper.classList.remove('has-date');
				}
				// Réappliquer les filtres quand une date change
				applyFilters();
			}

			// Au changement de date
			input.addEventListener('change', updateAppearance);
			input.addEventListener('input', updateAppearance);
			input.addEventListener('blur', updateAppearance);

			// Au clic sur la croix
			clearBtn.addEventListener('click', function(e) {
				e.preventDefault();
				input.value = '';
				updateAppearance();
			});

			// Initialisation
			updateAppearance();
		});
	});
})();
</script>

	<?php
	return ob_get_clean();
});
