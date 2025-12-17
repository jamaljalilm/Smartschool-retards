<?php
if (!defined('ABSPATH')) exit;

/**
 * includes/admin-message-test.php
 * Outil de test pour envoyer des messages Smartschool manuellement
 *
 * Note: Le menu est enregistré dans admin.php
 */

/* ========================== PAGE TEST MESSAGES ========================== */
function ssr_admin_test_messages_render(){
    if (!current_user_can('manage_options')) {
        wp_die(__('Accès refusé.'));
    }

    $result_msg = '';
    $result_type = '';

    /* --------- POST HANDLER --------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ssr_test_send'])) {
        check_admin_referer('ssr_test_send_message', 'ssr_test_nonce');

        $user_id     = isset($_POST['user_identifier']) ? sanitize_text_field($_POST['user_identifier']) : '';
        $title       = isset($_POST['message_title']) ? sanitize_text_field($_POST['message_title']) : '';
        $body        = isset($_POST['message_body']) ? wp_kses_post($_POST['message_body']) : '';
        $sender      = isset($_POST['sender_identifier']) ? sanitize_text_field($_POST['sender_identifier']) : 'Null';
        $coaccount   = isset($_POST['coaccount']) ? intval($_POST['coaccount']) : null;
        $copy_to_lvs = !empty($_POST['copy_to_lvs']);

        // Validation
        if (empty($user_id)) {
            $result_msg = 'Erreur : l\'identifiant utilisateur est requis.';
            $result_type = 'error';
        } elseif (empty($title)) {
            $result_msg = 'Erreur : le titre est requis.';
            $result_type = 'error';
        } elseif (empty($body)) {
            $result_msg = 'Erreur : le corps du message est requis.';
            $result_type = 'error';
        } else {
            // Récupérer les détails de l'utilisateur pour remplacer les variables
            $user_details = null;
            $first_name = '';
            $last_name = '';
            $class_code = '';

            if (function_exists('ssr_api')) {
                // Essayer de récupérer les détails via l'API
                $user_details = ssr_api('getUserDetails', [$user_id]);

                if (is_array($user_details) && !empty($user_details)) {
                    $first_name = isset($user_details['voornaam']) ? $user_details['voornaam'] : '';
                    $last_name = isset($user_details['naam']) ? $user_details['naam'] : '';

                    // Récupérer la classe officielle
                    if (!empty($user_details['groups']) && is_array($user_details['groups'])) {
                        foreach ($user_details['groups'] as $g) {
                            if (!empty($g['isKlas']) && !empty($g['isOfficial'])) {
                                $class_code = isset($g['code']) ? $g['code'] : (isset($g['name']) ? $g['name'] : '');
                                break;
                            }
                        }
                    }
                }
            }

            // Remplacement des variables dans le titre et le corps
            $title_final = str_replace(
                ['{prenom}', '{nom}', '{classe}'],
                [$first_name, $last_name, $class_code],
                $title
            );

            $body_final = str_replace(
                ['{prenom}', '{nom}', '{classe}'],
                [$first_name, $last_name, $class_code],
                $body
            );

            // Envoi du message
            if (function_exists('ssr_api_send_message')) {
                $result = ssr_api_send_message(
                    $user_id,
                    $title_final,
                    $body_final,
                    $sender,
                    null,        // attachments
                    $coaccount,  // coaccount
                    $copy_to_lvs // copyToLVS
                );

                if (is_wp_error($result)) {
                    $result_msg = 'Erreur lors de l\'envoi : ' . esc_html($result->get_error_message());
                    $result_type = 'error';

                    // Vérifier et créer la table si nécessaire
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'smartschool_daily_messages';

                    // Créer la table si elle n'existe pas
                    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                        if (function_exists('ssr_db_maybe_create_tables')) {
                            ssr_db_maybe_create_tables();
                        }
                    }

                    // Enregistrer l'échec dans l'historique
                    $insert_result = $wpdb->insert(
                        $table_name,
                        [
                            'user_identifier' => $user_id,
                            'class_code' => $class_code,
                            'last_name' => $last_name,
                            'first_name' => $first_name,
                            'date_retard' => current_time('Y-m-d'),
                            'message_title' => $title_final,
                            'message_content' => $body_final,
                            'sent_to_student' => ($coaccount === null) ? 1 : 0,
                            'sent_to_parent1' => ($coaccount === 1) ? 1 : 0,
                            'sent_to_parent2' => ($coaccount === 2) ? 1 : 0,
                            'sent_at' => current_time('mysql'),
                            'status' => 'failed',
                            'error_message' => $result->get_error_message(),
                        ],
                        ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s']
                    );

                    // Debug: afficher si l'insertion a échoué
                    if ($insert_result === false) {
                        $result_msg .= '<br><small style="color: #d63638;">⚠️ Erreur lors de l\'enregistrement dans l\'historique: ' . esc_html($wpdb->last_error) . '</small>';
                    }
                } else {
                    $coaccount_label = ($coaccount === null) ? 'compte principal' : 'coaccount ' . $coaccount;
                    $result_msg = 'Message envoyé avec succès à l\'utilisateur ' . esc_html($user_id) . ' (' . $coaccount_label . ')';

                    // Afficher les variables remplacées si disponibles
                    if ($first_name || $last_name || $class_code) {
                        $result_msg .= '<br><small>Variables remplacées : ';
                        $vars_replaced = [];
                        if ($first_name) $vars_replaced[] = '{prenom} → ' . esc_html($first_name);
                        if ($last_name) $vars_replaced[] = '{nom} → ' . esc_html($last_name);
                        if ($class_code) $vars_replaced[] = '{classe} → ' . esc_html($class_code);
                        $result_msg .= implode(' | ', $vars_replaced);
                        $result_msg .= '</small>';
                    }

                    $result_type = 'success';

                    // Log
                    if (function_exists('ssr_log')) {
                        ssr_log('Test message sent to ' . $user_id . ' (' . $coaccount_label . ')', 'info', 'admin-test');
                    }

                    // Vérifier et créer la table si nécessaire
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'smartschool_daily_messages';

                    // Créer la table si elle n'existe pas
                    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                        if (function_exists('ssr_db_maybe_create_tables')) {
                            ssr_db_maybe_create_tables();
                        }
                    }

                    // Enregistrer dans l'historique
                    $insert_result = $wpdb->insert(
                        $table_name,
                        [
                            'user_identifier' => $user_id,
                            'class_code' => $class_code,
                            'last_name' => $last_name,
                            'first_name' => $first_name,
                            'date_retard' => current_time('Y-m-d'),
                            'message_title' => $title_final,
                            'message_content' => $body_final,
                            'sent_to_student' => ($coaccount === null) ? 1 : 0,
                            'sent_to_parent1' => ($coaccount === 1) ? 1 : 0,
                            'sent_to_parent2' => ($coaccount === 2) ? 1 : 0,
                            'sent_at' => current_time('mysql'),
                            'status' => 'success',
                            'error_message' => null,
                        ],
                        ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s']
                    );

                    // Debug: afficher si l'insertion a échoué
                    if ($insert_result === false) {
                        $result_msg .= '<br><small style="color: #d63638;">⚠️ Le message a été envoyé mais n\'a pas pu être enregistré dans l\'historique. Erreur DB: ' . esc_html($wpdb->last_error) . '</small>';
                    }
                }
            } else {
                $result_msg = 'Erreur : la fonction ssr_api_send_message n\'est pas disponible.';
                $result_type = 'error';
            }
        }
    }

    // Récupération du sender par défaut
    $default_sender = 'R001'; // Compte expéditeur Smartschool

    ?>
    <div class="wrap">
        <h1><?php _e('Test d\'envoi de messages Smartschool', 'smartschool-retards'); ?></h1>

        <?php if ($result_msg): ?>
            <div class="notice notice-<?php echo esc_attr($result_type); ?> is-dismissible">
                <p><?php echo $result_msg; ?></p>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Envoyer un message de test</h2>
            <p style="color: #666;">
                Utilisez cet outil pour tester l'envoi de messages via l'API Smartschool sendMsg.
                <br><strong>Attention :</strong> Les messages seront réellement envoyés !
            </p>
            <div style="background: #e7f3ff; border-left: 4px solid #0073aa; padding: 12px; margin: 15px 0;">
                <strong>💡 Variables disponibles :</strong>
                <ul style="margin: 8px 0 0 0; line-height: 1.6;">
                    <li><code>{prenom}</code> - Prénom de l'élève</li>
                    <li><code>{nom}</code> - Nom de famille de l'élève</li>
                    <li><code>{classe}</code> - Classe officielle de l'élève</li>
                </ul>
                <small style="color: #666;">Les variables seront automatiquement remplacées par les données réelles de l'élève.</small>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('ssr_test_send_message', 'ssr_test_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="user_identifier">Identifiant utilisateur *</label>
                        </th>
                        <td>
                            <input type="text"
                                   name="user_identifier"
                                   id="user_identifier"
                                   class="regular-text"
                                   required
                                   placeholder="Ex: INDL.9999"
                                   value="<?php echo isset($_POST['user_identifier']) ? esc_attr($_POST['user_identifier']) : 'INDL.9999'; ?>">
                            <p class="description">Numéro interne Smartschool ou identifiant unique de l'utilisateur</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="message_title">Titre du message *</label>
                        </th>
                        <td>
                            <input type="text"
                                   name="message_title"
                                   id="message_title"
                                   class="regular-text"
                                   required
                                   placeholder="Ex: Retard - Interdiction de sortir"
                                   value="<?php echo isset($_POST['message_title']) ? esc_attr($_POST['message_title']) : 'Retard - Interdiction de sortir'; ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="message_body">Corps du message *</label>
                        </th>
                        <td>
                            <?php
                            $default_message = <<<'HTML'
<div>
<p style="margin-bottom: 15px;">Bonjour {prenom},</p>
<p style="margin-bottom: 15px;">Tu as été en retard aujourd'hui. Tu seras donc <span style="color: #e03e2d;"><strong>privé de sortie</strong></span> la prochaine pause de midi. Merci de venir te présenter à la prochaine pause de midi à <strong>l'accueil à 13h05</strong>.</p>
<p style="margin-bottom: 15px;">⚠️ N'oublie pas de prévoir de quoi manger.</p>
<p style="margin-bottom: 15px;"><strong>Attention :</strong></p>

<ul style="margin-bottom: 5px;">
 	<li>Si tu ne te présentes pas <strong>5 fois</strong>, tu auras une <span style="color: #e03e2d;"><strong>retenue</strong></span>.</li>
 	<li>Si tu as été en retard <strong>2 fois le même jour</strong> (matin et après-midi) et que tu ne te présentes pas, cela comptera pour <strong>deux non-présentations</strong>.</li>
</ul>
<p style="margin-bottom: 15px;">Cordialement,</p>

</div>
<div style="font-family: Arial, sans-serif; font-size: 14px; color: #000;">
<table style="border-collapse: collapse;" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td style="padding-right: 15px; vertical-align: top;">
<p style="margin: 0px; font-weight: bold; font-size: 16px; text-align: right;">Robot IA - INDL Retards</p>
<p style="margin: 2px 0px; font-style: italic; color: #333333; text-align: right;">Rue Edmond Tollenaere, 32
1020 Bruxelles</p>
</td>
<td style="vertical-align: top;"><a href="https://indl.be/" target="_blank" rel="noopener"> <img style="float: left;" src="https://indl.be/wp-content/uploads/2023/04/185-low.gif" alt="Logo Institut Notre-Dame de Lourdes" width="180" height="50" border="0" /> </a></td>
</tr>
</tbody>
</table>
</div>
HTML;
                            $message_content = isset($_POST['message_body']) ? wp_kses_post($_POST['message_body']) : $default_message;

                            wp_editor(
                                $message_content,
                                'message_body',
                                array(
                                    'textarea_name' => 'message_body',
                                    'textarea_rows' => 10,
                                    'media_buttons' => false,
                                    'teeny' => false,
                                    'tinymce' => array(
                                        'toolbar1' => 'formatselect,bold,italic,underline,forecolor,backcolor,bullist,numlist,alignleft,aligncenter,alignright,link,unlink,undo,redo',
                                        'toolbar2' => '',
                                    ),
                                    'quicktags' => true,
                                )
                            );
                            ?>
                            <p class="description">
                                Utilisez l'éditeur pour formater votre message (gras, couleurs, listes, etc.)<br>
                                <strong>Variables disponibles :</strong> <code>{prenom}</code>, <code>{nom}</code>, <code>{classe}</code>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="sender_identifier">Expéditeur</label>
                        </th>
                        <td>
                            <input type="text"
                                   name="sender_identifier"
                                   id="sender_identifier"
                                   class="regular-text"
                                   placeholder="'Null' pour aucun expéditeur"
                                   value="<?php echo isset($_POST['sender_identifier']) ? esc_attr($_POST['sender_identifier']) : esc_attr($default_sender); ?>">
                            <p class="description">
                                Identifiant Smartschool de l'expéditeur. Utilisez 'Null' pour ne pas définir d'expéditeur.
                                <?php if ($default_sender): ?>
                                    <br><strong>Valeur par défaut :</strong> <?php echo esc_html($default_sender); ?>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="coaccount">Type de compte</label>
                        </th>
                        <td>
                            <select name="coaccount" id="coaccount">
                                <option value="">Compte principal (élève)</option>
                                <option value="1" <?php selected(isset($_POST['coaccount']) && $_POST['coaccount'] == '1'); ?>>Coaccount 1 (Parent 1)</option>
                                <option value="2" <?php selected(isset($_POST['coaccount']) && $_POST['coaccount'] == '2'); ?>>Coaccount 2 (Parent 2)</option>
                            </select>
                            <p class="description">Choisissez à quel compte envoyer le message</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="copy_to_lvs">Copier dans LVS</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="copy_to_lvs"
                                       id="copy_to_lvs"
                                       value="1"
                                       <?php checked(!empty($_POST['copy_to_lvs'])); ?>>
                                Ajouter ce message dans le Suivi des élèves (LVS)
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="ssr_test_send" class="button button-primary">
                        📤 Envoyer le message de test
                    </button>
                </p>
            </form>
        </div>

        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>📋 Exemples de tests rapides</h2>
            <p>Utilisez ces exemples pour tester différents scénarios :</p>
            <ul style="line-height: 1.8;">
                <li><strong>Message simple à un élève :</strong> Compte principal, sans expéditeur</li>
                <li><strong>Message aux parents :</strong> Sélectionner Coaccount 1 ou 2</li>
                <li><strong>Message avec suivi :</strong> Cocher "Copier dans LVS"</li>
            </ul>
        </div>

        <div class="card" style="max-width: 800px; margin-top: 20px; background: #fff3cd; border-left: 4px solid #ffc107;">
            <h2>⚠️ Important</h2>
            <ul style="line-height: 1.8;">
                <li>Les messages envoyés via cet outil sont <strong>réels</strong> et seront reçus par les utilisateurs.</li>
                <li>Vérifiez toujours l'identifiant utilisateur avant d'envoyer.</li>
                <li>Testez d'abord sur votre propre compte ou un compte de test.</li>
                <li>Les logs sont enregistrés dans la table <code><?php echo esc_html(SSR_T_LOG); ?></code></li>
            </ul>
        </div>
    </div>
    <?php
}
