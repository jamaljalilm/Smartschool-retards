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
                SSR_OPT_SOAP_ACCESSCODE => sanitize_text_field($_POST['accesscode'] ?? ''),
                SSR_OPT_SOAP_URL        => esc_url_raw(trim($_POST['url'] ?? '')),
                SSR_OPT_SOAP_HOURS      => sanitize_text_field($_POST['hours'] ?? ''),
            ]);
            echo '<div class="updated"><p>Options Smartschool (SOAP) sauvegardées ✅</p></div>';
            $saved = true;
        }

        // Ajouter / Mettre à jour un vérificateur
        if (isset($_POST['ssr_verifier_save'])) {
            check_admin_referer('ssr_verifier_save', 'ssr_verifier_nonce');

            $vid       = isset($_POST['verifier_id']) ? intval($_POST['verifier_id']) : 0;
            $name      = isset($_POST['display_name']) ? sanitize_text_field($_POST['display_name']) : '';
            $is_active = !empty($_POST['is_active']) ? 1 : 0;
            $pin1      = isset($_POST['pin1']) ? sanitize_text_field($_POST['pin1']) : '';
            $pin2      = isset($_POST['pin2']) ? sanitize_text_field($_POST['pin2']) : '';

            if (!$name) {
                $verif_msg = '<div class="notice notice-error"><p>Le nom est obligatoire.</p></div>';
            } else {
                $data = ['display_name'=>$name, 'is_active'=>$is_active, 'updated_at'=>current_time('mysql')];
                $fmt  = ['%s','%d','%s'];

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
            <tr><th>ID</th><th>Nom</th><th>Actif</th><th>Créé</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if ($rows): foreach($rows as $r): ?>
            <tr>
              <td><?php echo intval($r['id']); ?></td>
              <td><?php echo esc_html($r['display_name']); ?></td>
              <td><?php echo intval($r['is_active']) ? 'Oui' : '<span style="color:#b00020">Non</span>'; ?></td>
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
            <tr><td colspan="5">Aucun vérificateur pour l’instant.</td></tr>
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
