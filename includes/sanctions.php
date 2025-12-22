<?php
if (!defined('ABSPATH')) exit;

/**
 * includes/sanctions.php
 * - Gestion des messages automatiques pour les sanctions
 * - Templates personnalisables
 * - Envoi via API Smartschool
 */

/**
 * Envoie un message automatique à un élève qui atteint une sanction
 *
 * @param array $student_data Données de l'élève: [
 *   'user_identifier' => 'INDL.XXXX',
 *   'first_name' => 'Prénom',
 *   'last_name' => 'Nom',
 *   'nb_absences' => 10,
 *   'sanction_type' => 'retenue_2',
 *   'is_new_sanction' => true/false (true si première notification, false si mise à jour)
 * ]
 * @return bool|WP_Error True si succès, WP_Error si échec
 */
if (!function_exists('ssr_send_sanction_notification')) {
function ssr_send_sanction_notification($student_data) {
    // Vérifier que l'API est disponible
    if (!function_exists('ssr_api')) {
        ssr_log('Fonction ssr_api non disponible pour l\'envoi de notification de sanction', 'error', 'sanctions');
        return new WP_Error('api_unavailable', 'API function not available');
    }

    // Extraire les données
    $user_id = $student_data['user_identifier'] ?? '';
    $first_name = $student_data['first_name'] ?? '';
    $last_name = $student_data['last_name'] ?? '';
    $nb_absences = intval($student_data['nb_absences'] ?? 0);
    $sanction_type = $student_data['sanction_type'] ?? '';
    $is_new = $student_data['is_new_sanction'] ?? true;

    if (empty($user_id)) {
        ssr_log('Tentative d\'envoi de notification sans user_identifier', 'error', 'sanctions');
        return new WP_Error('missing_user_id', 'Missing user identifier');
    }

    // Récupérer le template de message depuis les options
    $default_title = 'Notification de sanction';
    $title_tpl = get_option('ssr_sanction_message_title', $default_title);
    $title_tpl = apply_filters('ssr_sanction_message_title_tpl', $title_tpl);

    $default_body = '<div style="font-family: Arial, sans-serif; color: #333;">
<p style="margin-bottom: 15px;">Bonjour {prenom},</p>
<p style="margin-bottom: 15px;">Suite à tes <strong style="color: #d32f2f;">{nb_absences} non-présentations</strong> après des retards, tu as atteint le seuil pour une <strong>{sanction_label}</strong>.</p>
<p style="margin-bottom: 15px;">📋 <strong>Détails de ta sanction :</strong></p>
<ul style="margin-bottom: 15px; padding-left: 20px;">
    <li><strong>Nombre de non-présentations :</strong> {nb_absences}</li>
    <li><strong>Type de sanction :</strong> {sanction_label}</li>
</ul>
<p style="margin-bottom: 15px;">⚠️ <strong>Important :</strong> Un message te sera envoyé prochainement avec la <strong>date exacte</strong> à laquelle tu devras te présenter pour effectuer ta sanction.</p>
<p style="margin-bottom: 15px;">Pour rappel, voici les seuils de sanction :</p>
<ul style="margin-bottom: 15px; padding-left: 20px;">
    <li>5-9 absences : Retenue 1</li>
    <li>10-14 absences : Retenue 2</li>
    <li>15-19 absences : Demi-jour de renvoi</li>
    <li>20+ absences : Jour de renvoi</li>
</ul>
<p style="margin-bottom: 15px;">N\'oublie pas de te présenter lors de la prochaine pause de midi à <strong>13h05 à l\'accueil</strong> pour éviter d\'accumuler davantage d\'absences.</p>
<p style="margin-bottom: 15px;">Cordialement,</p>
</div>
<div style="font-family: Arial, sans-serif; font-size: 14px; color: #000;">
<table style="border-collapse: collapse;" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td style="padding-right: 15px; vertical-align: top;">
<p style="margin: 0px; font-weight: bold; font-size: 16px; text-align: right;">Robot IA - INDL Retards</p>
<p style="margin: 2px 0px; font-style: italic; color: #333333; text-align: right;">Rue Edmond Tollenaere, 32<br/>1020 Bruxelles</p>
</td>
<td style="vertical-align: top;"><a href="https://indl.be/" target="_blank" rel="noopener"> <img style="float: left;" src="https://indl.be/wp-content/uploads/2023/04/185-low.gif" alt="Logo Institut Notre-Dame de Lourdes" width="180" height="50" border="0" /> </a></td>
</tr>
</tbody>
</table>
</div>';

    $body_tpl = get_option('ssr_sanction_message_body', $default_body);
    $body_tpl = apply_filters('ssr_sanction_message_body_tpl', $body_tpl);

    // Déterminer le label de la sanction
    $sanction_labels = [
        'retenue_1' => 'Retenue 1',
        'retenue_2' => 'Retenue 2',
        'demi_jour' => 'Demi-jour de renvoi',
        'jour_renvoi' => 'Jour de renvoi',
    ];
    $sanction_label = $sanction_labels[$sanction_type] ?? 'Sanction';

    // Remplacer les placeholders
    $replacements = [
        '{prenom}' => $first_name,
        '{nom}' => $last_name,
        '{nb_absences}' => $nb_absences,
        '{sanction_type}' => $sanction_type,
        '{sanction_label}' => $sanction_label,
    ];

    $title = str_replace(array_keys($replacements), array_values($replacements), $title_tpl);
    $body = str_replace(array_keys($replacements), array_values($replacements), $body_tpl);

    // Récupérer les paramètres d'envoi
    $send_to_student = get_option('ssr_sanction_send_to_student', '1');
    $send_to_parents = get_option('ssr_sanction_send_to_parents', '1');

    $sent_count = 0;
    $errors = [];

    // Envoi à l'élève
    if ($send_to_student === '1') {
        $result = ssr_api('sendMsg', [
            'msgType' => 'MSGTYPE_NOTE',
            'title' => $title,
            'body' => $body,
            'receivers' => [$user_id],
            'priority' => 1,
            'sender' => get_option(SSR_OPT_SENDER, 'R001'),
        ]);

        if (is_wp_error($result)) {
            $errors[] = 'Élève: ' . $result->get_error_message();
            ssr_log(
                "Échec envoi notification sanction à élève {$first_name} {$last_name} ({$user_id}): " . $result->get_error_message(),
                'error',
                'sanctions'
            );
        } else {
            $sent_count++;
            ssr_log(
                "Notification sanction envoyée à {$first_name} {$last_name} ({$user_id}): {$sanction_label} ({$nb_absences} absences)",
                'info',
                'sanctions'
            );
        }
    }

    // Envoi aux parents
    if ($send_to_parents === '1') {
        // Récupérer les informations de l'élève pour avoir les contacts des parents
        $user_details = ssr_api('getUserDetailsByNumber', ['internalNumber' => $user_id]);

        if (!is_wp_error($user_details) && !empty($user_details['coAccounts'])) {
            $parent_accounts = [];
            foreach ($user_details['coAccounts'] as $co_account) {
                if (!empty($co_account['accountId'])) {
                    $parent_accounts[] = $co_account['accountId'];
                }
            }

            if (!empty($parent_accounts)) {
                $result = ssr_api('sendMsg', [
                    'msgType' => 'MSGTYPE_NOTE',
                    'title' => $title,
                    'body' => $body,
                    'receivers' => $parent_accounts,
                    'priority' => 1,
                    'sender' => get_option(SSR_OPT_SENDER, 'R001'),
                ]);

                if (is_wp_error($result)) {
                    $errors[] = 'Parents: ' . $result->get_error_message();
                    ssr_log(
                        "Échec envoi notification sanction aux parents de {$first_name} {$last_name} ({$user_id}): " . $result->get_error_message(),
                        'error',
                        'sanctions'
                    );
                } else {
                    $sent_count++;
                    ssr_log(
                        "Notification sanction envoyée aux parents de {$first_name} {$last_name} ({$user_id}): {$sanction_label}",
                        'info',
                        'sanctions'
                    );
                }
            }
        }
    }

    // Retourner le résultat
    if ($sent_count > 0) {
        return true;
    } elseif (!empty($errors)) {
        return new WP_Error('send_failed', implode('; ', $errors));
    } else {
        return new WP_Error('no_recipients', 'No recipients configured');
    }
}}

/**
 * Détecte si une sanction vient d'être atteinte (nouveau seuil)
 * Compare l'ancien nb_absences avec le nouveau pour détecter un franchissement de seuil
 *
 * @param int $old_nb_absences Ancien nombre d'absences
 * @param int $new_nb_absences Nouveau nombre d'absences
 * @return bool True si un nouveau seuil est franchi
 */
if (!function_exists('ssr_is_new_sanction_threshold')) {
function ssr_is_new_sanction_threshold($old_nb_absences, $new_nb_absences) {
    // Seuils de sanctions : 5, 10, 15, 20
    $thresholds = [5, 10, 15, 20];

    foreach ($thresholds as $threshold) {
        // Si l'ancien était sous le seuil et le nouveau est au-dessus ou égal
        if ($old_nb_absences < $threshold && $new_nb_absences >= $threshold) {
            return true;
        }
    }

    return false;
}}

/**
 * Détermine le type de sanction en fonction du nombre d'absences
 *
 * @param int $nb_absences Nombre d'absences
 * @return string Type de sanction (retenue_1, retenue_2, demi_jour, jour_renvoi)
 */
if (!function_exists('ssr_get_sanction_type')) {
function ssr_get_sanction_type($nb_absences) {
    if ($nb_absences >= 20) {
        return 'jour_renvoi';
    } elseif ($nb_absences >= 15) {
        return 'demi_jour';
    } elseif ($nb_absences >= 10) {
        return 'retenue_2';
    } else {
        return 'retenue_1';
    }
}}
