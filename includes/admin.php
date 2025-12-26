<?php
if (!defined('ABSPATH')) exit;

/**
 * includes/admin.php — UI uniquement (menu + page de réglages)
 * Dépend de:
 *  - constants.php (SSR_* constants)
 *  - helpers.php   (ssr_get_option, ssr_set_option, ssr_trueish, ssr_cal_get_settings)
 *  - db.php        (tables déjà créées via hook d’activation)
 *  - cron.php      (ssr_cron_maybe_reschedule_daily)
 *  - api.php       (SOAP/HTTP si tu les utilises ailleurs)
 */

/* ========================== MENU ADMIN ========================== */
add_action('admin_menu', function(){
    // Menu principal
    add_menu_page(
        __('Smartschool Retards','smartschool-retards'),
        __('Smartschool Retards','smartschool-retards'),
        'manage_options',
        'ssr-settings',
        'ssr_admin_page_render',
        'dashicons-clock',
        56
    );

    // Sous-menu : Test Messages
    add_submenu_page(
        'ssr-settings',
        __('Test Messages','smartschool-retards'),
        __('📤 Test Messages','smartschool-retards'),
        'manage_options',
        'ssr-test-messages',
        'ssr_admin_test_messages_render'
    );

    // Sous-menu : Configuration Message Quotidien
    add_submenu_page(
        'ssr-settings',
        __('Message Quotidien','smartschool-retards'),
        __('✉️ Message Quotidien','smartschool-retards'),
        'manage_options',
        'ssr-daily-message-config',
        'ssr_admin_daily_message_config_render'
    );

    // Sous-menu : Configuration Messages Sanctions
    add_submenu_page(
        'ssr-settings',
        __('Messages Sanctions','smartschool-retards'),
        __('⚖️ Messages Sanctions','smartschool-retards'),
        'manage_options',
        'ssr-sanction-message-config',
        'ssr_admin_sanction_message_config_render'
    );

    // Sous-menu : Historique Messages
    add_submenu_page(
        'ssr-settings',
        __('Historique Messages','smartschool-retards'),
        __('📬 Historique Messages','smartschool-retards'),
        'manage_options',
        'ssr-message-history',
        'ssr_admin_message_history_render'
    );

    // Sous-menu : Logs
    add_submenu_page(
        'ssr-settings',
        __('Logs','smartschool-retards'),
        __('📋 Logs','smartschool-retards'),
        'manage_options',
        'ssr-view-logs',
        'ssr_admin_view_logs_render'
    );

    // Sous-menu : Test Fonction
    add_submenu_page(
        'ssr-settings',
        __('Test Fonction','smartschool-retards'),
        __('🧪 Test Fonction','smartschool-retards'),
        'manage_options',
        'ssr-test-function',
        'ssr_admin_test_function_render'
    );
});

/* ========================== PAGE ADMIN (réglages) ========================== */
function ssr_admin_page_render(){
    if (!current_user_can('manage_options')) return;
    global $wpdb;

    $saved = false; 
    $verif_msg = '';

    /* --------- POST HANDLERS --------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Réglages calendrier
        if (isset($_POST['ssr_cal_settings_save'])) {
            check_admin_referer('ssr_cal_settings_save', 'ssr_cal_nonce');

            $out = [];
            $out['start_date']    = (!empty($_POST['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['start_date'])) ? $_POST['start_date'] : '';
            $out['end_date']      = (!empty($_POST['end_date'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['end_date']))   ? $_POST['end_date']   : '';
            $out['hide_wed']      = !empty($_POST['hide_wed']) ? '1' : '0';
            $out['hide_weekends'] = !empty($_POST['hide_weekends']) ? '1' : '0';
            $out['holidays']      = isset($_POST['holidays']) ? wp_kses_post($_POST['holidays']) : '';
            $out['blanks']        = isset($_POST['blanks'])   ? wp_kses_post($_POST['blanks'])   : '';

            update_option('ssr_cal_settings', $out);
            $saved = true;
        }

        // Réglages legacy SOAP (compat)
        if (isset($_POST['smartschool_save'])) {
            check_admin_referer('smartschool_save', 'smartschool_nonce');
            ssr_set_option([
                SSR_OPT_SOAP_ACCESSCODE => isset($_POST['accesscode']) ? sanitize_text_field($_POST['accesscode']) : '',
                SSR_OPT_SOAP_URL        => isset($_POST['url']) ? esc_url_raw(trim($_POST['url'])) : '',
                SSR_OPT_SOAP_HOURS      => isset($_POST['hours']) ? sanitize_text_field($_POST['hours']) : '',
            ]);
            echo '<div class="updated"><p>Options Smartschool (SOAP) sauvegardées ✅</p></div>';
            $saved = true;
        }

        // Ajouter / Mettre à jour un vérificateur
        if (isset($_POST['ssr_verifier_save'])) {
            check_admin_referer('ssr_verifier_save', 'ssr_verifier_nonce');

            $vid             = isset($_POST['verifier_id']) ? intval($_POST['verifier_id']) : 0;
            $name            = isset($_POST['display_name']) ? sanitize_text_field($_POST['display_name']) : '';
            $is_active       = !empty($_POST['is_active']) ? 1 : 0;
            $can_access_suivi = !empty($_POST['can_access_suivi']) ? 1 : 0;
            $pin1            = isset($_POST['pin1']) ? sanitize_text_field($_POST['pin1']) : '';
            $pin2            = isset($_POST['pin2']) ? sanitize_text_field($_POST['pin2']) : '';

            if (!$name) {
                $verif_msg = '<div class="notice notice-error"><p>Le nom est obligatoire.</p></div>';
            } else {
                $data = [
                    'display_name' => $name,
                    'is_active' => $is_active,
                    'can_access_suivi' => $can_access_suivi,
                    'updated_at' => current_time('mysql')
                ];
                $fmt  = ['%s','%d','%d','%s'];

                if ($pin1 || $pin2) {
                    if ($pin1 !== $pin2) {
                        $verif_msg = '<div class="notice notice-error"><p>Les deux PIN ne correspondent pas.</p></div>';
                    } else {
                        $hash = function_exists('password_hash') ? password_hash($pin1, PASSWORD_DEFAULT) : md5($pin1);
                        $data['pin_hash'] = $hash;
                        $fmt[] = '%s';
                    }
                }

                if (!$verif_msg) {
                    if ($vid > 0) {
                        $ok = $wpdb->update(SSR_T_VERIFIERS, $data, ['id'=>$vid], $fmt, ['%d']);
                        if ($ok === false) {
                            $verif_msg = '<div class="notice notice-error"><p>Erreur SQL: ' . esc_html($wpdb->last_error) . '</p></div>';
                        } else {
                            $verif_msg = '<div class="updated"><p>Vérificateur mis à jour.</p></div>';
                        }
                    } else {
                        $data['created_at'] = current_time('mysql');
                        $ok = $wpdb->insert(SSR_T_VERIFIERS, $data, array_merge($fmt, ['%s']));
                        if ($ok === false) {
                            $verif_msg = '<div class="notice notice-error"><p>Erreur SQL: ' . esc_html($wpdb->last_error) . '</p></div>';
                        } else {
                            $verif_msg = '<div class="updated"><p>Vérificateur ajouté.</p></div>';
                        }
                    }
                }
            }
        }

        // Supprimer un vérificateur
        if (isset($_POST['ssr_verifier_delete'])) {
            check_admin_referer('ssr_verifier_delete', 'ssr_verifier_del_nonce');
            $vid = isset($_POST['verifier_id']) ? intval($_POST['verifier_id']) : 0;
            if ($vid > 0) {
                $wpdb->delete(SSR_T_VERIFIERS, ['id'=>$vid], ['%d']);
                $verif_msg = '<div class="updated"><p>Vérificateur supprimé.</p></div>';
            }
        }
    }

    /* --------- CHARGER RÉGLAGES --------- */
    $cal               = ssr_cal_get_settings();
    $legacy_url        = ssr_get_option(SSR_OPT_SOAP_URL, '');
    $legacy_accesscode = ssr_get_option(SSR_OPT_SOAP_ACCESSCODE, '');
    $legacy_hours      = ssr_get_option(SSR_OPT_SOAP_HOURS, '13:15');

    // Liste des vérificateurs
    $rows = $wpdb->get_results("SELECT * FROM " . SSR_T_VERIFIERS . " ORDER BY display_name ASC", ARRAY_A);
    $edit_row = null;
    if (isset($_GET['edit']) && intval($_GET['edit'])>0) {
        $id = intval($_GET['edit']);
        foreach($rows as $r){ if (intval($r['id']) === $id) { $edit_row = $r; break; } }
    }

    /* --------- RENDU --------- */ ?>
    <div class="wrap">
      <h1><?php echo esc_html__('Smartschool – Retards (réglages)', 'smartschool-retards'); ?></h1>

      <style>
        .ssr-box{
          border:1px solid #d0d7de;
          border-left:4px solid #2271b1; /* bleu WP */
          background:#f6f7f7;
          border-radius:6px;
          padding:16px 18px;
          margin:24px 0;
        }
        .ssr-box h2{
          margin:0 0 12px;
          font-size:18px;
          line-height:1.4;
          color:#1d2327;
        }
        .ssr-box .description{ margin-top:6px; color:#5c6873; }
        .ssr-row{ display:grid; gap:16px; grid-template-columns: 1fr; }
        @media(min-width: 1024px){
          .ssr-row{ grid-template-columns: 1fr 1fr; }
        }
        .ssr-spacer{ height:8px; }
      </style>

      <?php if ($saved) echo '<div class="updated"><p>Réglages enregistrés.</p></div>'; ?>

      <!-- Encadré: Legacy SOAP V3 (compat) -->
      <div class="ssr-box">
        <h2>Smartschool SOAP (V3) — Options historiques</h2>
        <form method="post">
          <?php wp_nonce_field('smartschool_save','smartschool_nonce'); ?>
          <input type="hidden" name="smartschool_save" value="1"/>
          <table class="form-table">
            <tr>
              <th><label for="url">URL Smartschool (base)</label></th>
              <td><input type="url" id="url" name="url" class="regular-text" value="<?php echo esc_attr($legacy_url); ?>" placeholder="https://votre-ecole.smartschool.be"/></td>
            </tr>
            <tr>
              <th><label for="accesscode">Accesscode</label></th>
              <td><input type="text" id="accesscode" name="accesscode" class="regular-text" value="<?php echo esc_attr($legacy_accesscode); ?>"/></td>
            </tr>
            <tr>
              <th><label for="hours">Heure(s) (libre)</label></th>
              <td><input type="text" id="hours" name="hours" class="regular-text" value="<?php echo esc_attr($legacy_hours); ?>" placeholder="13:15"/></td>
            </tr>
          </table>
          <?php submit_button('Enregistrer'); ?>
        </form>
      </div>

      <!-- Encadré: Tableau des vérificateurs -->
	  <!-- Encadré: Formulaire ajout / édition vérificateur -->
      <div class="ssr-box">
        <h2>Vérificateurs — Liste</h2>
        <?php echo $verif_msg; ?>
        <table class="widefat striped" style="margin-bottom:8px;">
          <thead>
            <tr><th>ID</th><th>Nom</th><th>Actif</th><th>Accès Suivi</th><th>Créé</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if ($rows): foreach($rows as $r): ?>
            <tr>
              <td><?php echo intval($r['id']); ?></td>
              <td><?php echo esc_html($r['display_name']); ?></td>
              <td><?php echo intval($r['is_active']) ? 'Oui' : '<span style="color:#b00020">Non</span>'; ?></td>
              <td><?php
                $has_access = isset($r['can_access_suivi']) ? intval($r['can_access_suivi']) : 0;
                echo $has_access ? '<span style="color:#10b981;font-weight:600;">✓ Oui</span>' : '<span style="color:#9ca3af;">— Non</span>';
              ?></td>
              <td><?php echo esc_html($r['created_at']); ?></td>
              <td>
                <a class="button" href="<?php echo esc_url(add_query_arg(['page'=>'ssr-settings','edit'=>intval($r['id'])], admin_url('admin.php'))); ?>">Modifier</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Supprimer ce vérificateur ?');">
                  <?php wp_nonce_field('ssr_verifier_delete','ssr_verifier_del_nonce'); ?>
                  <input type="hidden" name="verifier_id" value="<?php echo intval($r['id']); ?>"/>
                  <button class="button button-link-delete" name="ssr_verifier_delete" value="1">Supprimer</button>
                </form>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6">Aucun vérificateur pour l'instant.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
        <h2><?php echo $edit_row ? 'Modifier le vérificateur' : 'Ajouter un vérificateur'; ?></h2>
        <form method="post">
          <?php wp_nonce_field('ssr_verifier_save','ssr_verifier_nonce'); ?>
          <input type="hidden" name="ssr_verifier_save" value="1"/>
          <input type="hidden" name="verifier_id" value="<?php echo $edit_row ? intval($edit_row['id']) : 0; ?>"/>

          <table class="form-table">
            <tr>
              <th><label for="display_name">Nom</label></th>
              <td><input type="text" id="display_name" name="display_name" class="regular-text" value="<?php echo $edit_row ? esc_attr($edit_row['display_name']) : ''; ?>" required/></td>
            </tr>
            <tr>
              <th><label for="is_active">Actif</label></th>
              <td><label><input type="checkbox" id="is_active" name="is_active" <?php checked($edit_row ? intval($edit_row['is_active']) : 1); ?>/> Activer ce vérificateur</label></td>
            </tr>
            <tr>
              <th><label for="can_access_suivi">Accès page Suivi</label></th>
              <td>
                <label><input type="checkbox" id="can_access_suivi" name="can_access_suivi" <?php checked($edit_row ? intval($edit_row['can_access_suivi'] ?? 0) : 0); ?>/> Autoriser l'accès à la page Suivi</label>
                <p class="description">Si coché, ce vérificateur pourra voir le menu "Suivi" et accéder à l'historique des vérifications.</p>
              </td>
            </tr>
            <tr>
              <th><label for="pin1">Nouveau PIN</label></th>
              <td><input type="password" id="pin1" name="pin1" class="regular-text" <?php echo $edit_row ? '' : 'required'; ?>/></td>
            </tr>
            <tr>
              <th><label for="pin2">Confirmer PIN</label></th>
              <td><input type="password" id="pin2" name="pin2" class="regular-text" <?php echo $edit_row ? '' : 'required'; ?>/></td>
            </tr>
          </table>
          <?php submit_button($edit_row ? 'Mettre à jour' : 'Ajouter'); ?>
        </form>
      </div>

      <!-- Encadré: Calendrier -->
      <div class="ssr-box">
        <h2>Calendrier — Période & jours à masquer</h2>
        <form method="post">
          <?php wp_nonce_field('ssr_cal_settings_save','ssr_cal_nonce'); ?>
          <input type="hidden" name="ssr_cal_settings_save" value="1"/>

          <table class="form-table">
            <tr>
              <th><label for="start_date">Début de période</label></th>
              <td><input type="date" id="start_date" name="start_date" value="<?php echo esc_attr($cal['start_date']); ?>"/></td>
            </tr>
            <tr>
              <th><label for="end_date">Fin de période</label></th>
              <td><input type="date" id="end_date" name="end_date" value="<?php echo esc_attr($cal['end_date']); ?>"/></td>
            </tr>
            <tr>
              <th>Jours à masquer</th>
              <td>
                <label style="margin-right:16px;">
                  <input type="checkbox" name="hide_wed" value="1" <?php checked('1', $cal['hide_wed']); ?>/> Mercredi
                </label>
                <label>
                  <input type="checkbox" name="hide_weekends" value="1" <?php checked('1', $cal['hide_weekends']); ?>/> Week-ends (samedi & dimanche)
                </label>
              </td>
            </tr>
            <tr>
              <th><label for="holidays">Jours fériés</label></th>
              <td>
                <textarea id="holidays" name="holidays" rows="6" cols="50" class="large-text code" placeholder="YYYY-MM-DD&#10"><?php echo esc_textarea($cal['holidays']); ?></textarea>
                <p class="description">Une date par ligne, format <code>YYYY-MM-DD</code>.</p>
              </td>
            </tr>
            <tr>
              <th><label for="blanks">Jours blancs</label></th>
              <td>
                <textarea id="blanks" name="blanks" rows="6" cols="50" class="large-text code" placeholder="YYYY-MM-DD&#10"><?php echo esc_textarea($cal['blanks']); ?></textarea>
                <p class="description">Journées sans contrôle (masquées). Une date par ligne, format <code>YYYY-MM-DD</code>.</p>
              </td>
            </tr>
          </table>

          <?php submit_button('Enregistrer'); ?>
        </form>
      </div>

    </div>
    <?php
}

/**
 * Page de test de la fonction ssr_verification_date_for_retard
 */
function ssr_admin_test_function_render() {
	if (!current_user_can('manage_options')) return;

	// Gestion du reset OPcache
	if (isset($_POST['ssr_reset_opcache']) && check_admin_referer('ssr_reset_opcache_action', 'ssr_reset_opcache_nonce')) {
		$success_count = 0;
		$failed_files = [];

		// Liste des fichiers à invalider
		$files_to_invalidate = [
			SSR_INC_DIR . 'helpers.php',
			SSR_SC_DIR . 'recap_calendrier.php',
		];

		// Tente d'abord opcache_reset (tout vider)
		if (function_exists('opcache_reset')) {
			if (opcache_reset()) {
				echo '<div class="notice notice-success is-dismissible"><p><strong>✅ OPcache vidé avec succès (opcache_reset) !</strong></p></div>';
			} else {
				// Si opcache_reset échoue, essaye opcache_invalidate sur chaque fichier
				if (function_exists('opcache_invalidate')) {
					foreach ($files_to_invalidate as $file) {
						if (file_exists($file)) {
							if (opcache_invalidate($file, true)) {
								$success_count++;
							} else {
								$failed_files[] = basename($file);
							}
						}
					}

					if ($success_count > 0) {
						echo '<div class="notice notice-success is-dismissible"><p><strong>✅ Cache invalidé pour ' . $success_count . ' fichier(s) !</strong></p></div>';
					}
					if (count($failed_files) > 0) {
						echo '<div class="notice notice-warning is-dismissible"><p><strong>⚠️ Échec pour : ' . implode(', ', $failed_files) . '</strong></p></div>';
					}
					if ($success_count === 0 && count($failed_files) === 0) {
						echo '<div class="notice notice-error is-dismissible"><p><strong>❌ Aucun fichier n\'a pu être invalidé</strong></p></div>';
					}
				} else {
					echo '<div class="notice notice-error is-dismissible"><p><strong>❌ opcache_reset bloqué et opcache_invalidate non disponible</strong></p></div>';
					echo '<div class="notice notice-info"><p><strong>Solution :</strong> Redémarrez PHP-FPM en ligne de commande :<br><code>sudo systemctl restart php8.1-fpm</code></p></div>';
				}
			}
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p><strong>⚠️ OPcache n\'est pas disponible sur ce serveur</strong></p></div>';
		}
	}

	echo '<div class="wrap">';
	echo '<h1>🧪 Test de la fonction ssr_verification_date_for_retard</h1>';

	// Section OPcache
	echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:15px;margin:20px 0;">';
	echo '<h2 style="margin-top:0;">🔄 Cache PHP (OPcache)</h2>';

	if (function_exists('opcache_get_status')) {
		$status = opcache_get_status(false);
		$config = opcache_get_configuration();

		if ($status && $status['opcache_enabled']) {
			echo '<p><strong>Statut :</strong> <span style="color:green;">✅ Activé</span></p>';
			echo '<p><strong>Mémoire utilisée :</strong> ' . round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) . ' MB</p>';
			echo '<p><strong>Fichiers en cache :</strong> ' . $status['opcache_statistics']['num_cached_scripts'] . '</p>';

			// Configuration critique pour savoir quand le cache se rafraîchit
			echo '<hr style="margin:15px 0;border:none;border-top:1px solid #ddd;">';
			echo '<h3 style="margin-top:10px;">⏱️ Paramètres de rafraîchissement :</h3>';

			$revalidate_freq = isset($config['directives']['opcache.revalidate_freq']) ? $config['directives']['opcache.revalidate_freq'] : 'N/A';
			$validate_timestamps = isset($config['directives']['opcache.validate_timestamps']) ? $config['directives']['opcache.validate_timestamps'] : 'N/A';
			$restrict_api = isset($config['directives']['opcache.restrict_api']) ? $config['directives']['opcache.restrict_api'] : '';

			echo '<table class="widefat" style="margin-top:10px;">';
			echo '<tr><td style="width:50%;"><strong>opcache.validate_timestamps</strong><br><small>Vérifie si les fichiers ont changé ?</small></td><td>';
			if ($validate_timestamps === true || $validate_timestamps === 1) {
				echo '<span style="color:green;font-weight:bold;">✅ OUI</span>';
			} else {
				echo '<span style="color:red;font-weight:bold;">❌ NON (cache permanent jusqu\'au redémarrage)</span>';
			}
			echo '</td></tr>';

			echo '<tr><td><strong>opcache.revalidate_freq</strong><br><small>Fréquence de vérification (secondes)</small></td><td>';
			echo '<strong style="font-size:18px;color:#0073aa;">' . $revalidate_freq . ' secondes</strong>';
			if ($revalidate_freq == 0) {
				echo '<br><small style="color:green;">→ Vérifie à chaque requête !</small>';
			} elseif ($revalidate_freq <= 60) {
				echo '<br><small style="color:green;">→ Cache rafraîchi toutes les ' . $revalidate_freq . ' secondes</small>';
			} else {
				echo '<br><small style="color:orange;">→ Cache rafraîchi toutes les ' . round($revalidate_freq / 60, 1) . ' minutes</small>';
			}
			echo '</td></tr>';

			echo '<tr><td><strong>opcache.restrict_api</strong><br><small>Chemin autorisé pour contrôler le cache</small></td><td>';
			if (empty($restrict_api)) {
				echo '<span style="color:green;">✅ Aucune restriction</span>';
			} else {
				echo '<span style="color:orange;">⚠️ Restreint à : <code>' . esc_html($restrict_api) . '</code></span>';
			}
			echo '</td></tr>';
			echo '</table>';

			// Estimation du prochain rafraîchissement
			echo '<div style="background:#e7f3ff;border-left:4px solid #0073aa;padding:12px;margin-top:15px;">';
			echo '<strong>📅 Estimation du prochain rafraîchissement :</strong><br>';
			if ($validate_timestamps === false || $validate_timestamps === 0) {
				echo '<span style="color:red;">Le cache ne se rafraîchit JAMAIS automatiquement. Redémarrage PHP-FPM requis.</span>';
			} elseif ($revalidate_freq == 0) {
				echo '<span style="color:green;">Le cache se rafraîchit à CHAQUE requête. Rechargez la page maintenant !</span>';
			} else {
				echo 'Maximum <strong>' . $revalidate_freq . ' secondes</strong> après la modification du fichier.<br>';
				echo '<small>Fichiers modifiés : 2025-12-23 23:59 → Attendez ' . $revalidate_freq . ' secondes puis rafraîchissez le calendrier.</small>';
			}
			echo '</div>';

		} else {
			echo '<p><strong>Statut :</strong> <span style="color:orange;">⚠️ Désactivé</span></p>';
		}
	} else {
		echo '<p><strong>Statut :</strong> <span style="color:gray;">❌ Non disponible</span></p>';
	}

	// Formulaire de reset
	echo '<form method="post" style="margin-top:15px;">';
	wp_nonce_field('ssr_reset_opcache_action', 'ssr_reset_opcache_nonce');
	echo '<input type="hidden" name="ssr_reset_opcache" value="1">';
	submit_button('🔄 Vider OPcache maintenant', 'secondary', 'submit', false);
	echo '</form>';
	echo '</div>';

	// Section Diagnostic Cron / Messages
	echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:15px;margin:20px 0;">';
	echo '<h2 style="margin-top:0;">📤 Diagnostic des Messages Quotidiens</h2>';

	// 1. Mode test
	$test_mode = get_option(SSR_OPT_TESTMODE, '0');
	$is_test = ($test_mode === '1' || $test_mode === 1 || $test_mode === true);

	echo '<table class="widefat" style="margin-top:10px;">';
	echo '<tr><td style="width:50%;"><strong>Mode test</strong><br><small>Si activé, AUCUN message n\'est envoyé</small></td><td>';
	if ($is_test) {
		echo '<span style="color:red;font-weight:bold;">❌ ACTIVÉ (messages BLOQUÉS)</span>';
		echo '<br><small>→ Allez dans <a href="' . admin_url('admin.php?page=ssr-settings') . '">Réglages</a> pour désactiver</small>';
	} else {
		echo '<span style="color:green;font-weight:bold;">✅ DÉSACTIVÉ (envoi autorisé)</span>';
	}
	echo '</td></tr>';

	// 2. Cron planifié
	$next_cron = wp_next_scheduled(SSR_CRON_HOOK);
	echo '<tr><td><strong>Prochaine exécution</strong><br><small>Quand le cron va s\'exécuter</small></td><td>';
	if ($next_cron) {
		$tz = wp_timezone();
		$dt = new DateTime('@' . $next_cron);
		$dt->setTimezone($tz);
		echo '<strong style="color:#0073aa;">' . $dt->format('d/m/Y à H:i:s') . '</strong>';
		$diff = $next_cron - time();
		if ($diff > 0) {
			echo '<br><small>→ Dans ' . human_time_diff($next_cron) . '</small>';
		} else {
			echo '<br><small style="color:orange;">→ En retard de ' . human_time_diff($next_cron) . '</small>';
		}
	} else {
		echo '<span style="color:red;font-weight:bold;">❌ PAS PLANIFIÉ !</span>';
		echo '<br><small>→ Le cron n\'est pas actif. Décochez/recochez "Mode test" pour le réactiver.</small>';
	}
	echo '</td></tr>';

	// 3. Heure configurée
	$cron_time = get_option(SSR_OPT_DAILY_HHMM, '13:15');
	echo '<tr><td><strong>Heure configurée</strong><br><small>Heure d\'envoi quotidien</small></td><td>';
	echo '<strong style="font-size:18px;color:#0073aa;">' . esc_html($cron_time) . '</strong>';
	echo '</td></tr>';

	// 4. Destinataires
	$send_student = get_option('ssr_daily_send_to_student', '1');
	$send_parents = get_option('ssr_daily_send_to_parents', '1');
	echo '<tr><td><strong>Destinataires</strong><br><small>À qui les messages sont envoyés</small></td><td>';
	$recipients = [];
	if ($send_student === '1') $recipients[] = '✅ Élèves';
	else $recipients[] = '❌ Élèves';
	if ($send_parents === '1') $recipients[] = '✅ Parents';
	else $recipients[] = '❌ Parents';
	echo implode(' • ', $recipients);
	echo '</td></tr>';

	echo '</table>';

	// Bouton test manuel
	echo '<div style="background:#e7f3ff;border-left:4px solid #0073aa;padding:12px;margin-top:15px;">';
	echo '<strong>🧪 Test manuel</strong><br>';
	echo '<p>Cliquez sur ce bouton pour exécuter le cron MAINTENANT et voir les logs en temps réel :</p>';
	echo '<a href="' . admin_url('admin.php?page=ssr-test-fonction&ssr_cron_run_now=1') . '" class="button button-secondary">▶️ Exécuter le cron maintenant</a>';
	echo '<p style="margin-top:10px;"><small>⚠️ Attention : Si le mode test est désactivé, cela enverra de VRAIS messages !</small></p>';
	echo '</div>';

	// Logs récents
	global $wpdb;
	$log_table = SSR_T_LOG;
	$recent_logs = $wpdb->get_results($wpdb->prepare(
		"SELECT created_at, level, context, message
		 FROM $log_table
		 WHERE context = 'cron'
		 ORDER BY created_at DESC
		 LIMIT 10"
	), ARRAY_A);

	if (!empty($recent_logs)) {
		echo '<h3 style="margin-top:20px;">📋 Logs récents (cron) :</h3>';
		echo '<table class="widefat striped" style="margin-top:10px;">';
		echo '<thead><tr><th>Date</th><th>Niveau</th><th>Message</th></tr></thead>';
		echo '<tbody>';
		foreach ($recent_logs as $log) {
			$level_color = $log['level'] === 'error' ? 'red' : ($log['level'] === 'warning' ? 'orange' : 'green');
			echo '<tr>';
			echo '<td style="white-space:nowrap;">' . esc_html($log['created_at']) . '</td>';
			echo '<td style="color:' . $level_color . ';font-weight:bold;">' . esc_html($log['level']) . '</td>';
			echo '<td>' . esc_html($log['message']) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p style="margin-top:15px;"><em>Aucun log de cron trouvé.</em></p>';
	}

	echo '</div>';

	// Section Diagnostic Messages Sanctions
	echo '<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:15px;margin:20px 0;">';
	echo '<h2 style="margin-top:0;">⚖️ Diagnostic des Messages Sanctions</h2>';

	echo '<p><strong>ℹ️ Note :</strong> Les messages sanctions sont envoyés automatiquement quand vous <strong>créez une sanction</strong> dans la page <a href="' . admin_url('admin.php?page=ssr-liste-retenues') . '">Liste des retenues</a>.</p>';

	echo '<table class="widefat" style="margin-top:10px;">';

	// 1. Envoi automatique
	$sanction_auto = get_option('ssr_sanction_auto_send', '1');
	echo '<tr><td style="width:50%;"><strong>Envoi automatique</strong><br><small>Si activé, envoie automatiquement lors de la création d\'une sanction</small></td><td>';
	if ($sanction_auto === '1') {
		echo '<span style="color:green;font-weight:bold;">✅ ACTIVÉ</span>';
	} else {
		echo '<span style="color:red;font-weight:bold;">❌ DÉSACTIVÉ (messages bloqués)</span>';
		echo '<br><small>→ Allez dans <a href="' . admin_url('admin.php?page=ssr-sanction-message-config') . '">Messages Sanctions</a> pour activer</small>';
	}
	echo '</td></tr>';

	// 2. Destinataires
	$sanction_student = get_option('ssr_sanction_send_to_student', '1');
	$sanction_parents = get_option('ssr_sanction_send_to_parents', '1');
	echo '<tr><td><strong>Destinataires</strong><br><small>À qui les messages de sanction sont envoyés</small></td><td>';
	$sanction_recipients = [];
	if ($sanction_student === '1') $sanction_recipients[] = '✅ Élèves';
	else $sanction_recipients[] = '❌ Élèves';
	if ($sanction_parents === '1') $sanction_recipients[] = '✅ Parents';
	else $sanction_recipients[] = '❌ Parents';
	echo implode(' • ', $sanction_recipients);
	echo '</td></tr>';

	// 3. Template configuré
	$sanction_body = get_option('ssr_sanction_message_body', '');
	echo '<tr><td><strong>Template de message</strong><br><small>Message configuré pour les sanctions</small></td><td>';
	if (!empty($sanction_body)) {
		echo '<span style="color:green;font-weight:bold;">✅ Configuré</span>';
		echo '<br><small>Longueur : ' . strlen($sanction_body) . ' caractères</small>';
	} else {
		echo '<span style="color:red;font-weight:bold;">❌ VIDE (messages ne peuvent pas être envoyés)</span>';
		echo '<br><small>→ Allez dans <a href="' . admin_url('admin.php?page=ssr-sanction-message-config') . '">Messages Sanctions</a> pour configurer</small>';
	}
	echo '</td></tr>';

	echo '</table>';

	// Logs récents sanctions
	$sanction_logs = $wpdb->get_results($wpdb->prepare(
		"SELECT created_at, level, context, message
		 FROM $log_table
		 WHERE context = 'sanctions'
		 ORDER BY created_at DESC
		 LIMIT 10"
	), ARRAY_A);

	if (!empty($sanction_logs)) {
		echo '<h3 style="margin-top:20px;">📋 Logs récents (sanctions) :</h3>';
		echo '<table class="widefat striped" style="margin-top:10px;">';
		echo '<thead><tr><th>Date</th><th>Niveau</th><th>Message</th></tr></thead>';
		echo '<tbody>';
		foreach ($sanction_logs as $log) {
			$level_color = $log['level'] === 'error' ? 'red' : ($log['level'] === 'warning' ? 'orange' : 'green');
			echo '<tr>';
			echo '<td style="white-space:nowrap;">' . esc_html($log['created_at']) . '</td>';
			echo '<td style="color:' . $level_color . ';font-weight:bold;">' . esc_html($log['level']) . '</td>';
			echo '<td>' . esc_html($log['message']) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p style="margin-top:15px;"><em>Aucun log de sanction trouvé.</em></p>';
		echo '<p><small>💡 <strong>Conseil :</strong> Essayez de créer une sanction dans <a href="' . admin_url('admin.php?page=ssr-liste-retenues') . '">Liste des retenues</a> pour tester l\'envoi.</small></p>';
	}

	echo '</div>';

	// Vérifie si la fonction existe
	if (function_exists('ssr_verification_date_for_retard')) {
		echo '<div class="notice notice-success"><p style="font-size:16px;"><strong>✅ La fonction existe !</strong></p></div>';

		// Test avec quelques dates (avec les VRAIS jours de la semaine)
		$tests = [
			'2025-12-01' => ['expected' => '2025-12-02', 'desc' => 'Lundi retard → Mardi vérif'],
			'2025-12-02' => ['expected' => '2025-12-04', 'desc' => 'Mardi retard → Jeudi vérif'],
			'2025-12-03' => ['expected' => '2025-12-04', 'desc' => 'Mercredi retard → Jeudi vérif'],
			'2025-12-04' => ['expected' => '2025-12-05', 'desc' => 'Jeudi retard → Vendredi vérif'],
			'2025-12-05' => ['expected' => '2025-12-08', 'desc' => 'Vendredi retard → Lundi vérif'],
		];

		echo '<h2>Tests de calcul :</h2>';
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>Date retard</th><th>Description</th><th>Attendu</th><th>Résultat</th><th>Status</th></tr></thead>';
		echo '<tbody>';

		foreach ($tests as $date => $info) {
			$result = ssr_verification_date_for_retard($date);
			$expected = $info['expected'];
			$ok = ($result === $expected);
			$status = $ok ? '<span style="color:green;font-weight:bold;">✅ OK</span>' : '<span style="color:red;font-weight:bold;">❌ ERREUR</span>';
			echo "<tr>";
			echo "<td><code>$date</code></td>";
			echo "<td>{$info['desc']}</td>";
			echo "<td><code>$expected</code></td>";
			echo "<td><strong><code>$result</code></strong></td>";
			echo "<td>$status</td>";
			echo "</tr>";
		}

		echo '</tbody></table>';

	} else {
		echo '<div class="notice notice-error"><p style="font-size:16px;"><strong>❌ La fonction N\'EXISTE PAS !</strong></p></div>';
		echo '<p>Cela signifie que le fichier <code>helpers.php</code> n\'a pas été chargé correctement ou que le cache PHP n\'a pas été vidé.</p>';
		echo '<p><strong>Solution :</strong> Videz le cache OPcache avec le bouton ci-dessus ou redémarrez PHP-FPM/Apache.</p>';
	}

	echo '</div>';
}
