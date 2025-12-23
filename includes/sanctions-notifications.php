<?php
if (!defined('ABSPATH')) exit;

/**
 * includes/sanctions-notifications.php
 * Gestion de l'envoi automatique des notifications de sanction
 */

if (!function_exists('ssr_send_sanction_notification')) {
    /**
     * Envoie une notification de sanction automatiquement
     *
     * @param array $sanction_data Données de la sanction
     * @return bool True si envoyé avec succès, false sinon
     */
    function ssr_send_sanction_notification($sanction_data) {
        // Vérifier si l'envoi automatique est activé
        $auto_send = get_option('ssr_sanction_auto_send', '1');
        if ($auto_send !== '1') {
            ssr_log('Envoi automatique désactivé pour les sanctions', 'info', 'sanctions');
            return false;
        }

        // Extraire les données
        $user_identifier = $sanction_data['user_identifier'] ?? '';
        $firstname = $sanction_data['firstname'] ?? '';
        $lastname = $sanction_data['lastname'] ?? '';
        $nb_absences = intval($sanction_data['nb_absences'] ?? 0);
        $sanction_type = $sanction_data['sanction_type'] ?? '';

        // Validation
        if (empty($user_identifier) || empty($sanction_type)) {
            ssr_log('Données de sanction incomplètes pour l\'envoi', 'warning', 'sanctions');
            return false;
        }

        // Déterminer le libellé de la sanction
        $sanction_labels = [
            'retenue_1' => 'Retenue 1',
            'retenue_2' => 'Retenue 2',
            'demi_jour' => 'Demi-jour de renvoi',
            'jour_renvoi' => 'Jour de renvoi',
        ];
        $sanction_label = $sanction_labels[$sanction_type] ?? $sanction_type;

        // Récupérer le template de message
        $title = get_option('ssr_sanction_message_title', 'Notification de sanction');
        $body = get_option('ssr_sanction_message_body', '');

        if (empty($body)) {
            ssr_log('Template de message de sanction vide', 'error', 'sanctions');
            return false;
        }

        // Remplacer les variables dans le titre et le corps
        $replacements = [
            '{prenom}' => $firstname,
            '{nom}' => $lastname,
            '{nb_absences}' => $nb_absences,
            '{sanction_type}' => $sanction_type,
            '{sanction_label}' => $sanction_label,
        ];

        $title_final = str_replace(array_keys($replacements), array_values($replacements), $title);
        $body_final = str_replace(array_keys($replacements), array_values($replacements), $body);

        // Récupérer les préférences d'envoi
        $send_to_student = get_option('ssr_sanction_send_to_student', '1');
        $send_to_parents = get_option('ssr_sanction_send_to_parents', '1');

        // Récupérer le sender identifier
        $sender = get_option(SSR_OPT_SENDER, 'Null');

        $success_count = 0;
        $error_count = 0;

        // Envoyer à l'élève
        if ($send_to_student === '1' && function_exists('ssr_api_send_message')) {
            // Préfixer avec INDL. si nécessaire (comme dans api.php)
            $student_identifier = $user_identifier;
            if (!preg_match('/^INDL\./i', $student_identifier)) {
                $student_identifier = 'INDL.' . $student_identifier;
            }

            $result = ssr_api_send_message(
                $student_identifier,
                $title_final,
                $body_final,
                $sender,
                null,  // attachments
                0,     // coaccount: compte principal
                false  // copyToLVS
            );

            if (is_wp_error($result)) {
                ssr_log(
                    "Erreur envoi sanction à {$firstname} {$lastname} ({$student_identifier}): " . $result->get_error_message(),
                    'error',
                    'sanctions'
                );
                $error_count++;
            } else {
                ssr_log(
                    "Message de sanction envoyé à {$firstname} {$lastname} ({$student_identifier}): {$sanction_label}",
                    'info',
                    'sanctions'
                );
                $success_count++;
            }
        }

        // Envoyer aux parents (co-comptes 1 et 2)
        if ($send_to_parents === '1' && function_exists('ssr_api_send_message')) {
            // Préfixer avec INDL. si nécessaire
            $student_identifier = $user_identifier;
            if (!preg_match('/^INDL\./i', $student_identifier)) {
                $student_identifier = 'INDL.' . $student_identifier;
            }

            // Parent 1
            $result_p1 = ssr_api_send_message(
                $student_identifier,
                $title_final,
                $body_final,
                $sender,
                null,  // attachments
                1,     // coaccount: parent 1
                false  // copyToLVS
            );

            if (is_wp_error($result_p1)) {
                ssr_log(
                    "Erreur envoi sanction parent 1 pour {$firstname} {$lastname}: " . $result_p1->get_error_message(),
                    'error',
                    'sanctions'
                );
                $error_count++;
            } else {
                ssr_log(
                    "Message de sanction envoyé au parent 1 de {$firstname} {$lastname}",
                    'info',
                    'sanctions'
                );
                $success_count++;
            }

            // Parent 2
            $result_p2 = ssr_api_send_message(
                $student_identifier,
                $title_final,
                $body_final,
                $sender,
                null,  // attachments
                2,     // coaccount: parent 2
                false  // copyToLVS
            );

            if (is_wp_error($result_p2)) {
                // Parent 2 peut ne pas exister, on log en warning seulement
                ssr_log(
                    "Info: parent 2 non disponible pour {$firstname} {$lastname}: " . $result_p2->get_error_message(),
                    'warning',
                    'sanctions'
                );
            } else {
                ssr_log(
                    "Message de sanction envoyé au parent 2 de {$firstname} {$lastname}",
                    'info',
                    'sanctions'
                );
                $success_count++;
            }
        }

        // Retourner true si au moins un envoi a réussi
        return $success_count > 0;
    }
}
