<?php
if (!defined('ABSPATH')) exit;

/**
 * includes/api.php — BASE SÛRE & ROBUSTE
 * - SOAP V3 : ssr_api() avec fallback sans WSDL
 * - Normalisation retards : ssr_normalize_retards_rows()
 * - Lecture retards par date : ssr_fetch_retards_by_date()
 *
 * Options attendues :
 *   - SSR_OPT_SOAP_URL        (ex: https://indl.smartschool.be)
 *   - SSR_OPT_SOAP_ACCESSCODE (code d’accès Webservices)
 */

/* Utilitaire date -> Y-m-d (tolérant) */
if (!function_exists('ssr_to_ymd')) {
function ssr_to_ymd($d){
    if (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $d;
    $ts = is_string($d) ? strtotime($d) : false;
    return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
}}

/* =========================
 * SOAP V3 — WRAPPER ROBUSTE
 * ========================= */
if (!function_exists('ssr_api')) {
function ssr_api(string $method, array $params = []) {
    // Réglages requis
    $accesscode = function_exists('ssr_get_option') ? ssr_get_option(SSR_OPT_SOAP_ACCESSCODE) : null;
    $baseUrl    = function_exists('ssr_get_option') ? rtrim(ssr_get_option(SSR_OPT_SOAP_URL), '/') : null;
    if (!$accesscode || !$baseUrl) return [];

    // Pas d’extension SOAP -> pas d’appel
    if (!class_exists('SoapClient')) return [];

    // Empêche PHP de bloquer trop longtemps si l’endpoint répond mal
    $connTimeout = 6;   // secondes
    $readTimeout = 10;  // secondes
    $old_default_socket_timeout = @ini_get('default_socket_timeout');
    @ini_set('default_socket_timeout', (string)$readTimeout);

    // Contexte SSL (on reste strict, mais neutre si defaults)
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $readTimeout,
        ],
        'ssl' => [
            // laisser les valeurs par défaut (verification true)
            // 'verify_peer' => true,
            // 'verify_peer_name' => true,
        ],
    ]);

    // Petit cache mémoire statique pour éviter de retenter le WSDL à chaque hit
    static $wsdl_down_until = 0;

    // Fabrique le SoapClient WSDL si possible
    $client = null;
    $wsdlUrl = $baseUrl . '/Webservices/V3?wsdl';

    try {
        if (time() > $wsdl_down_until) {
            $client = new SoapClient($wsdlUrl, [
                'trace'              => 0,
                'exceptions'         => true,
                'cache_wsdl'         => WSDL_CACHE_NONE,
                'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
                'connection_timeout' => $connTimeout,
                'stream_context'     => $ctx,
            ]);
        }
    } catch (\Throwable $e) {
        // Si le WSDL est KO, on évite d’insister pendant 5 minutes
        $wsdl_down_until = time() + 300;
        $client = null;
    }

    // Fallback sans WSDL (RPC/encoded) si besoin
    if (!$client) {
        try {
            $client = new SoapClient(null, [
                'location'           => $baseUrl . '/Webservices/V3',
                'uri'                => 'https://indl.smartschool.be/Webservices/V3',
                'style'              => SOAP_RPC,
                'use'                => SOAP_ENCODED,
                'trace'              => 0,
                'exceptions'         => true,
                'connection_timeout' => $connTimeout,
                'stream_context'     => $ctx,
                'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
            ]);
        } catch (\Throwable $e) {
            @ini_set('default_socket_timeout', (string)$old_default_socket_timeout);
            return [];
        }
    }

    // Appels avec ordre strict pour les méthodes qu’on utilise ici
    try {
        switch ($method) {
            case 'getAbsentsWithInternalNumberByDate': {
                $date = isset($params['date']) ? ssr_to_ymd($params['date']) : ssr_to_ymd(date('Y-m-d'));

                // Log pour debug
                if (function_exists('ssr_log')) {
                    ssr_log("SOAP call getAbsentsWithInternalNumberByDate with date: $date", 'info', 'api');
                }

                $res  = $client->__soapCall($method, [$accesscode, $date]);

                // Log de la réponse
                if (function_exists('ssr_log')) {
                    $count = is_array($res) ? count($res) : 0;
                    ssr_log("SOAP response: " . ($res === null ? 'null' : (is_array($res) ? "$count items" : gettype($res))), 'info', 'api');
                }

                break;
            }
            default: {
                // Générique : accesscode + params tels quels
                $res = $client->__soapCall($method, array_merge([$accesscode], $params));
            }
        }

        // Smartschool renvoie parfois une string JSON
        if (is_string($res)) {
            $json = json_decode($res, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                @ini_set('default_socket_timeout', (string)$old_default_socket_timeout);
                return is_array($json) ? $json : [];
            }
            @ini_set('default_socket_timeout', (string)$old_default_socket_timeout);
            return [];
        }

        // Normalisation objet -> array
        $arr = json_decode(json_encode($res), true);
        @ini_set('default_socket_timeout', (string)$old_default_socket_timeout);
        return is_array($arr) ? $arr : [];

    } catch (\Throwable $e) {
        @ini_set('default_socket_timeout', (string)$old_default_socket_timeout);
        return [];
    }
}}
/* ==================================================================
 * Normalise la réponse "absents par numéro interne" en liste filtrée R
 * ================================================================== */
if (!function_exists('ssr_normalize_retards_rows')) {
function ssr_normalize_retards_rows(array $resp, string $date): array {
    $out = [];
    foreach ($resp as $uid => $slots) {
        if (!is_array($slots)) continue;

        $am = isset($slots['am']) ? strtoupper(trim((string)$slots['am'])) : '';
        $pm = isset($slots['pm']) ? strtoupper(trim((string)$slots['pm'])) : '';

        $hasR_AM = ($am === 'R');
        $hasR_PM = ($pm === 'R');
        if (!$hasR_AM && !$hasR_PM) continue;

        $status_raw = $hasR_AM && $hasR_PM ? 'AM+PM' : ($hasR_AM ? 'AM' : 'PM');

        $out[] = [
            'userIdentifier' => (string)$uid,
            'class_code'     => '', // pas d’enrichissement ici (base sûre)
            'last_name'      => '',
            'first_name'     => '',
            'date_retard'    => $date,
            'status_raw'     => $status_raw,
        ];
    }
    usort($out, function($a,$b){
        $a_class = isset($a['class_code']) ? $a['class_code'] : '';
        $a_last = isset($a['last_name']) ? $a['last_name'] : '';
        $a_first = isset($a['first_name']) ? $a['first_name'] : '';
        $b_class = isset($b['class_code']) ? $b['class_code'] : '';
        $b_last = isset($b['last_name']) ? $b['last_name'] : '';
        $b_first = isset($b['first_name']) ? $b['first_name'] : '';
        return [$a_class, $a_last, $a_first] <=> [$b_class, $b_last, $b_first];
    });
    return $out;
}}



/* ============== OUTILS DATES ============== */
if (!function_exists('ssr_prev_days_for_check')) {
  function ssr_prev_days_for_check() {
    // "Aujourd'hui" en Europe/Brussels, minuit
    if (function_exists('wp_timezone')) {
      $tz = wp_timezone();
    } else {
      $tz = new DateTimeZone('Europe/Brussels');
    }
    $today = new DateTime('now', $tz);
    $today->setTime(0,0,0);

    $dow   = (int)$today->format('N'); // 1=lundi ... 7=dimanche
    $dates = [];

    switch ($dow) {
      case 1: // Lundi → vendredi précédent
        $d = clone $today; 
        $d->modify('-3 days'); // vendredi
        $dates[] = $d->format('Y-m-d');
        break;

      case 2: // Mardi → lundi
        $d = clone $today; 
        $d->modify('-1 day');
        $dates[] = $d->format('Y-m-d');
        break;

      case 3: // Mercredi → aucun élève à vérifier
      case 6: // Samedi → aucun élève à vérifier
      case 7: // Dimanche → aucun élève à vérifier
        return []; // rien à afficher

      case 4: // Jeudi → mardi + mercredi
        $d1 = clone $today; $d1->modify('-2 days'); // mardi
        $d2 = clone $today; $d2->modify('-1 day');  // mercredi
        $dates[] = $d1->format('Y-m-d');
        $dates[] = $d2->format('Y-m-d');
        break;

      case 5: // Vendredi → jeudi
        $d = clone $today; 
        $d->modify('-1 day');
        $dates[] = $d->format('Y-m-d');
        break;
    }

    // Pas de fallback pour mer/sam/dim (déjà retourné)
    return $dates;
  }
}



// Retourne le code de la classe officielle (3-7) trouvé dans getUserDetails['groups'], sinon null
if (!function_exists('ssr_extract_official_class_from_user')) {
	function ssr_extract_official_class_from_user($user) {
		if (!isset($user['groups']) || !is_array($user['groups'])) {
			return null;
		}
		foreach ($user['groups'] as $g) {
			if (!empty($g['isOfficial']) && !empty($g['isKlas'])) {
				$code = isset($g['code']) ? $g['code'] : '';
				// Vérifie que le code commence par 3,4,5,6 ou 7
				if (preg_match('/^[3-7]/', $code)) {
					return $code;
				}
			}
		}
		return null;
	}
}

/* ======================================
 * Récupère les retards d’une date donnée
 * ====================================== */
if (!function_exists('ssr_fetch_retards_by_date')) {
	function ssr_fetch_retards_by_date($date) {
		$list = [];
		$abs = ssr_api("getAbsentsWithInternalNumberByDate", ["date" => $date]);

		if (!is_array($abs)) {
			if (function_exists('ssr_log')) ssr_log("fetch_retards($date): API returned non-array", 'warning', 'api');
			return $list;
		}

		$total_retards = 0;
		$skipped_no_user = 0;
		$skipped_year_1_2 = 0;
		$skipped_no_class = 0;

		foreach ($abs as $uid => $slots) {
			// Vérifie retard matin ou aprem
			$isRetard = (isset($slots['am']) && $slots['am'] === 'R')
					 || (isset($slots['pm']) && $slots['pm'] === 'R');

			if ($isRetard) {
				$total_retards++;

				// ⚡ Récup infos élève
				$user = ssr_api("getUserDetailsByNumber", ["internalNumber" => $uid]);
				if (!is_array($user)) {
					$skipped_no_user++;
					if (function_exists('ssr_log')) ssr_log("fetch_retards($date): UID $uid - getUserDetailsByNumber failed", 'warning', 'api');
					continue;
				}

				// Récupérer le vrai userIdentifier (accountCode au format INDL.XXXX)
				$userIdent = isset($user['userIdentifier']) ? $user['userIdentifier'] :
							(isset($user['accountCode']) ? $user['accountCode'] : $uid);

				$ln = isset($user['naam']) ? $user['naam'] : '';
				$fn = isset($user['voornaam']) ? $user['voornaam'] : '';

				// On cherche la vraie classe officielle
				$cls = null;
				if (!empty($user['groups']) && is_array($user['groups'])) {
					foreach ($user['groups'] as $g) {
						if (!empty($g['isKlas']) && !empty($g['isOfficial'])) {
							$code = isset($g['code']) ? $g['code'] : (isset($g['name']) ? $g['name'] : '');
							// ⚡ Filtrer : on ignore les classes qui commencent par 1 ou 2
							if (preg_match('/^[1-2]/', $code)) {
								$skipped_year_1_2++;
								if (function_exists('ssr_log')) ssr_log("fetch_retards($date): UID $uid ($fn $ln) - Skipped (year 1-2, class $code)", 'info', 'api');
								continue 2; // saute complètement cet élève
							}
							$cls = $code;
							break;
						}
					}
				}

				if (!$cls) {
					$skipped_no_class++;
					if (function_exists('ssr_log')) ssr_log("fetch_retards($date): UID $uid ($fn $ln) - Skipped (no official class)", 'warning', 'api');
					continue;
				}

				// Simplification du statut
				$statusText = [];
				if (!empty($slots['am']) && $slots['am'] === 'R') $statusText[] = "AM";
				if (!empty($slots['pm']) && $slots['pm'] === 'R') $statusText[] = "PM";
				$statusText = $statusText ? implode("+", $statusText) : "—";

				$list[] = [
					'userIdentifier' => $userIdent,
					'internalNumber' => $uid,
					'class_code'     => $cls,
					'last_name'      => $ln,
					'first_name'     => $fn,
					'date_retard'    => $date,
					'status_raw'     => $statusText
				];
			}
		}

		// Log de synthèse
		$returned = count($list);
		if (function_exists('ssr_log')) {
			ssr_log("fetch_retards($date): Total=$total_retards | Returned=$returned | Skipped: no_user=$skipped_no_user, year_1-2=$skipped_year_1_2, no_class=$skipped_no_class", 'info', 'api');
		}

		return $list;
	}
}
if (!function_exists('translate_status')) {
	function ssr_translate_status($code) {
		switch ($code) {
			case 'R': return 'Retard';
			case 'O': return 'Absent';
			case 'M': return 'Malade';
			case 'C': return 'Cours';
			case '|': return 'Présent';
			case '-': return 'Inconnu';
			case 'I': return 'Internat';
			default:  return $code;
		}
	}
}
/* ============================================
 * Envoi de message Smartschool via sendMsg
 * ============================================ */
if (!function_exists('ssr_api_send_message')) {
    /**
     * Enveloppe la méthode SOAP sendMsg de Smartschool.
     *
     * Signature sendMsg Smartschool :
     *  sendMsg(accesscode, userIdentifier, title, body, senderIdentifier, attachments, coaccount, copyToLVS)
     *
     * @param string $userIdentifier Identifiant unique du destinataire
     * @param string $title Titre du message
     * @param string $body Corps du message
     * @param string $senderIdentifier Identifiant de l'expéditeur ('Null' = pas d'expéditeur)
     * @param mixed|null $attachments Pièces jointes en base64 (optionnel)
     * @param int|null $coaccount Type de compte (0=principal, 1=parent1, 2=parent2)
     * @param bool $copyToLVS Copier dans le Suivi des élèves (optionnel)
     * @return mixed|WP_Error Résultat de l'API ou WP_Error en cas d'erreur
     */
    function ssr_api_send_message(
        $userIdentifier,
        $title,
        $body,
        $senderIdentifier = 'Null',
        $attachments = null,
        $coaccount = null,
        $copyToLVS = false
    ) {
        // Sécurisation basique
        $userIdentifier   = trim((string)$userIdentifier);
        $title            = trim((string)$title);
        $body             = (string)$body;
        $senderIdentifier = trim((string)$senderIdentifier);

        if ($userIdentifier === '') {
            return new WP_Error('ssr_msg_no_recipient', 'Aucun destinataire fourni.');
        }
        if ($title === '') {
            return new WP_Error('ssr_msg_no_title', 'Le titre du message est vide.');
        }
        if ($body === '') {
            return new WP_Error('ssr_msg_no_body', 'Le corps du message est vide.');
        }

        // Si pas d’expéditeur, on suit la doc Smartschool : 'Null'
        if ($senderIdentifier === '') {
            $senderIdentifier = 'Null';
        }

        // Construction des paramètres dans l'ordre EXACT de sendMsg
        $params = array(
            $userIdentifier,
            $title,
            $body,
            $senderIdentifier,
        );

        // 5ème paramètre : attachments (ou null si on veut utiliser coaccount / copyToLVS)
        $hasAttachments = ($attachments !== null);

        if ($hasAttachments) {
            $params[] = $attachments;
        } elseif ($coaccount !== null || $copyToLVS) {
            // On veut aller jusqu'à coaccount/copyToLVS → on met un placeholder null
            $params[] = null;
        }

        // 6ème paramètre : coaccount (0,1,2,…) si fourni
        if ($coaccount !== null || $copyToLVS) {
            $params[] = ($coaccount === null) ? 0 : (int)$coaccount;
        }

        // 7ème paramètre : copyToLVS (bool)
        if ($coaccount !== null || $copyToLVS) {
            $params[] = (bool)$copyToLVS;
        }

        // Appel SOAP via le wrapper générique
        $res = ssr_api('sendMsg', $params);

        if (!is_array($res) && !is_string($res) && empty($res)) {
            if (function_exists('ssr_log')) {
                ssr_log('sendMsg: réponse vide ou invalide pour '.$userIdentifier, 'error', 'api');
            }
            return new WP_Error(
                'ssr_msg_empty_response',
                'La réponse de Smartschool (sendMsg) est vide ou invalide.'
            );
        }

        if (function_exists('ssr_log')) {
            $coInfo = ($coaccount === null) ? 'main' : 'coaccount='.$coaccount;
            ssr_log('sendMsg OK userIdentifier='.$userIdentifier.' ('.$coInfo.') title="'.$title.'"', 'info', 'api');
        }

        return $res;
    }
}

