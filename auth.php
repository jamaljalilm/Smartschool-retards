<?php
if (!defined('ABSPATH')) exit;

if (!defined('SSR_PIN_COOKIE')) define('SSR_PIN_COOKIE', 'ssr_pin_session');
if (!defined('SSR_PIN_SESSION_HOURS')) define('SSR_PIN_SESSION_HOURS', 8);

// ---------- Internes ----------
if (!function_exists('ssr_pin_cookie_secret')){
	function ssr_pin_cookie_secret(){
		if (function_exists('wp_salt')) return wp_salt('auth');
		return (defined('AUTH_SALT') && AUTH_SALT) ? AUTH_SALT : get_site_url();
	}
}

if (!function_exists('ssr_pin_encode_token')){
	function ssr_pin_encode_token($arr){
		return base64_encode(json_encode($arr));
	}
}

if (!function_exists('ssr_pin_decode_token')){
	function ssr_pin_decode_token($token){
		$arr = json_decode(base64_decode((string)$token), true);
		return is_array($arr) ? $arr : null;
	}
}

if (!function_exists('ssr_pin_make_token')){
	function ssr_pin_make_token($verifier_id, $verifier_name, $expires_ts){
		$payload = [
			'vid' => (int)$verifier_id,
			'vnm' => (string)$verifier_name,
			'exp' => (int)$expires_ts,
		];
		$data = get_site_url() . '|' . $payload['vid'] . '|' . $payload['vnm'] . '|' . $payload['exp'];
		$payload['sig'] = hash_hmac('sha256', $data, ssr_pin_cookie_secret());
		return ssr_pin_encode_token($payload);
	}
}

if (!function_exists('ssr_pin_verify_token')){
	function ssr_pin_verify_token($token){
		$arr = ssr_pin_decode_token($token);
		if (!$arr || empty($arr['vid']) || empty($arr['vnm']) || empty($arr['exp']) || empty($arr['sig'])) return false;
		if (time() > intval($arr['exp'])) return false;

		$data = get_site_url() . '|' . intval($arr['vid']) . '|' . (string)$arr['vnm'] . '|' . intval($arr['exp']);
		$expected = hash_hmac('sha256', $data, ssr_pin_cookie_secret());
		if (!hash_equals($expected, (string)$arr['sig'])) return false;

		return $arr; // retourne le payload si OK
	}
}

// ---------- API publique ----------
if (!function_exists('ssr_is_logged_in_pin')){
	function ssr_is_logged_in_pin(){
		if (!empty($_COOKIE[SSR_PIN_COOKIE])) {
			return (bool) ssr_pin_verify_token($_COOKIE[SSR_PIN_COOKIE]);
		}
		return false;
	}
	}

	if (!function_exists('ssr_current_verifier')){
	function ssr_current_verifier(){
		if (empty($_COOKIE[SSR_PIN_COOKIE])) return null;
		$arr = ssr_pin_verify_token($_COOKIE[SSR_PIN_COOKIE]);
		if (!$arr) return null;
		return ['id' => (int)$arr['vid'], 'name' => (string)$arr['vnm']];
	}
}

if (!function_exists('ssr_check_pin_for_verifier')){
	function ssr_check_pin_for_verifier($verifier_id, $pin_plain){
		global $wpdb;
		$id = intval($verifier_id);
		if ($id <= 0 || !is_string($pin_plain) || $pin_plain==='') return false;

		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT id, display_name, pin_hash, is_active FROM " . SSR_T_VERIFIERS . " WHERE id=%d LIMIT 1",
			$id
		), ARRAY_A);

		if (!$row || intval($row['is_active']) !== 1) return false;

		$hash = (string)$row['pin_hash'];
		if (function_exists('password_verify')) {
			if (!password_verify($pin_plain, $hash)) return false;
		} else {
			if (!hash_equals($hash, md5($pin_plain))) return false;
		}

		return ['id' => (int)$row['id'], 'name' => (string)$row['display_name']];
	}
}

if (!function_exists('ssr_pin_grant')){
	function ssr_pin_grant($verifier_id, $verifier_name){
		$exp = time() + (SSR_PIN_SESSION_HOURS * 3600);
		$token = ssr_pin_make_token($verifier_id, $verifier_name, $exp);
		if (function_exists('ssr_log')) {
		ssr_log('Login PIN: ' . $verifier_name . ' (#' . $verifier_id . ')', 'info', 'auth');
		}
		setcookie(SSR_PIN_COOKIE, $token, [
			'expires'  => $exp,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		]);
	}
}

if (!function_exists('ssr_pin_revoke')){
	function ssr_pin_revoke(){
    	setcookie(SSR_PIN_COOKIE, '', time()-3600, COOKIEPATH ? COOKIEPATH : '/');
		if (function_exists('ssr_current_verifier')) {
    		$v = ssr_current_verifier();
    		if ($v && function_exists('ssr_log')) {
        		ssr_log('Logout PIN: ' . $v['name'] . ' (#' . $v['id'] . ')', 'info', 'auth');
    		}
		}

	}
}
