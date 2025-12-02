<?php
if (!defined('ABSPATH')) exit;

// === Rate limiting helpers (transients) ========================
if (!function_exists('ssr_rate_key')) {
  function ssr_rate_key($tag, $suffix=''){
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = 'ssr_rl_' . $tag . '_' . md5($ip . '|' . $suffix);
    return $key;
  }
}
if (!function_exists('ssr_rate_get')) {
  function ssr_rate_get($key){ $v = get_transient($key); return is_numeric($v) ? (int)$v : 0; }
}
if (!function_exists('ssr_rate_inc')) {
  function ssr_rate_inc($key, $ttl_seconds){
    $n = ssr_rate_get($key) + 1;
    set_transient($key, $n, $ttl_seconds);
    return $n;
  }
}
if (!function_exists('ssr_rate_reset')) {
  function ssr_rate_reset($key){ delete_transient($key); }
}

remove_shortcode('ssr_login');

if (!function_exists('ssr_current_url')) {
  function ssr_current_url(){
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    return $scheme.$host.$uri;
  }
}


function ssr_shortcode_ssr_login_minimal($atts = [], $content = ''){
    // Logout
    if (isset($_GET['ssr_logout']) && function_exists('ssr_pin_revoke')) {
        ssr_pin_revoke();
        wp_safe_redirect(remove_query_arg(['ssr_logout','ssr_logged','ssr_err']));
        exit;
    }

    $session  = function_exists('ssr_current_verifier') ? ssr_current_verifier() : null;
    $is_ok    = !empty($session);

    // ✅ Récupère redirect_to (POST prioritaire, sinon GET)
    $redirect = '';
    if (isset($_POST['redirect_to'])) {
        $redirect = wp_unslash($_POST['redirect_to']);
    } elseif (isset($_GET['redirect_to'])) {
        $redirect = wp_unslash($_GET['redirect_to']);
    }

    $action   = esc_url(add_query_arg([]));

    $a = shortcode_atts([
        'title' => 'Connexion vérificateur',
        'action'=> $action,
    ], $atts, 'ssr_login');

	// POST
	$msg = '';
	if (
		$_SERVER['REQUEST_METHOD'] === 'POST'
		&& !empty($_POST['ssr_login_nonce'])
		&& wp_verify_nonce($_POST['ssr_login_nonce'], 'ssr_login_pin')
	) {
		// Anti bruteforce léger
		usleep(random_int(300000, 500000)); // 300–500 ms

		$vid = isset($_POST['verifier_id']) ? intval($_POST['verifier_id']) : 0;
		$pin = isset($_POST['ssr_pin']) ? preg_replace('/\D+/', '', $_POST['ssr_pin']) : '';

		// === Garde-fous de tentative ===
		$key_combo = ssr_rate_key('combo', 'vid:'.$vid); // IP + verifier
		$key_ip    = ssr_rate_key('ip');                 // IP global

		$attempts_combo = ssr_rate_get($key_combo);
		$attempts_ip    = ssr_rate_get($key_ip);

		$MAX_COMBO = 5;   // 5 tentatives
		$TTL_COMBO = 10 * MINUTE_IN_SECONDS; // fenêtre 10 min
		$MAX_IP    = 20;  // 20 tentatives sur 1 h (toutes cibles)
		$TTL_IP    = HOUR_IN_SECONDS;

		if ($attempts_combo >= $MAX_COMBO || $attempts_ip >= $MAX_IP) {
			if (function_exists('ssr_log')) ssr_log('Login BLOCKED (rate-limit): vid='.$vid, 'warning','auth');
			$msg = '<div class="ssr-msg-err">Trop de tentatives. Réessayez plus tard.</div>';
		}
		else if ($vid > 0 && $pin !== '' && function_exists('ssr_check_pin_for_verifier')) {
			$ok = ssr_check_pin_for_verifier($vid, $pin);
			if ($ok) {
				// ✅ Succès : reset des compteurs
				ssr_rate_reset($key_combo);
				ssr_rate_reset($key_ip);

				if (function_exists('ssr_pin_grant')) ssr_pin_grant($ok['id'], $ok['name']);
				if (function_exists('ssr_log')) ssr_log('Login OK: '.$ok['name'].' (#'.$ok['id'].')','info','auth');

				// URL de la page de login (sans paramètres)
				$login_url = remove_query_arg(['ssr_logout','ssr_err','ssr_logged'], ssr_current_url());

				// Referer (page d’où on vient)
				$referer = wp_get_referer();
				$safe_referer = '';
				if ($referer) {
					$same_host = (parse_url($referer, PHP_URL_HOST) === parse_url($login_url, PHP_URL_HOST));
					if ($same_host && strtok($referer,'?') !== strtok($login_url,'?')) {
						$safe_referer = $referer;
					}
				}
				wp_safe_redirect(home_url('/retards-verif'));
				exit;
			}
				else {
				// ❌ Échec : incrémente les compteurs
				$c1 = ssr_rate_inc($key_combo, $TTL_COMBO);
				$c2 = ssr_rate_inc($key_ip, $TTL_IP);
				if (function_exists('ssr_log')) ssr_log("Login FAIL ($c1/$MAX_COMBO combo, $c2/$MAX_IP ip): vid=".$vid,'warning','auth');
				$msg = '<div class="ssr-msg-err">Vérificateur / PIN invalide.</div>';
			}
		} else {
			// ❌ Champs manquants : incrémente IP global (optionnel)
			$c2 = ssr_rate_inc($key_ip, $TTL_IP);
			if (function_exists('ssr_log')) ssr_log('Login FAIL: champs manquants','warning','auth');
			$msg = '<div class="ssr-msg-err">Champs manquants.</div>';
		}
	}

    if (isset($_GET['ssr_logged'])) $msg = '<div class="ssr-msg-ok">Connexion réussie.</div>';
    if (isset($_GET['ssr_err']))    $msg = '<div class="ssr-msg-err">'.esc_html($_GET['ssr_err']).'</div>';

    // Vérificateurs
    global $wpdb;
    $rows = [];
    if (defined('SSR_T_VERIFIERS')) {
        $rows = (array) $wpdb->get_results(
            "SELECT id, display_name FROM ".SSR_T_VERIFIERS." WHERE is_active=1 ORDER BY display_name ASC",
            ARRAY_A
        );
    }

    ob_start(); ?>
    <style>
      :root { --ssr-orange:#ed8430; --ssr-border:#e6e6e6; --ssr-text:#111; --ssr-muted:#9aa0a6; }
      .ssr-auth-wrap{max-width:520px;margin:48px auto;padding:0 16px}
      .ssr-auth-card{background:#fff;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.06);padding:32px 28px}
      .ssr-auth-icon{display:flex;justify-content:center;margin:0 0 10px;color:var(--ssr-orange)}
      .ssr-auth-icon .ssr-icon{width:40px;height:40px;display:block}
      .ssr-auth-title{
        text-align:center;margin:0 0 12px;font-weight:800;color:var(--ssr-orange);
        font-size: clamp(28px, 4.2vw, 40px); line-height:1.05;
      }
      .ssr-msg-ok{background:#effaf1;color:#117a37;border-radius:12px;padding:12px;margin:0 0 14px;text-align:center}
      .ssr-msg-err{background:#fdeaea;color:#b00020;border-radius:12px;padding:12px;margin:0 0 14px;text-align:center}
      .ssr-form label{display:block;margin:14px 0 6px;font-weight:600;font-size:15px;color:var(--ssr-text)}
      .ssr-input, select.ssr-input{
        width:100%;padding:12px 14px;border:1px solid var(--ssr-border);border-radius:12px;font-size:16px;background:#fff;color:var(--ssr-text)
      }
      .ssr-input:focus{outline:none;border-color:#d0d0d0;box-shadow:none}
      .ssr-pin-grid{display:flex;justify-content:center;gap:14px;margin:20px 0 8px}
      .ssr-pin-cell{
        width:60px;height:60px;border-radius:14px;border:1px solid var(--ssr-border);
        background:#fff;display:flex;align-items:center;justify-content:center;
        font-size:28px;font-weight:800;color:var(--ssr-muted); text-align:center;
        transition:all .16s ease; outline:none; -moz-appearance:textfield; -webkit-text-security:disc; text-security:disc;
      }
      .ssr-pin-cell.filled{background:var(--ssr-orange);color:#fff;border-color:var(--ssr-orange);}
      .ssr-actions{margin-top:16px;display:flex;justify-content:center;gap:12px}
      .ssr-btn{appearance:none;border:0;border-radius:12px;padding:12px 20px;font-weight:800;cursor:pointer;font-size:16px}
      .ssr-btn-primary{background:var(--ssr-orange);color:#fff}
      .ssr-btn-secondary{background:#f5f5f5;color:#111}
      .ssr-btn[disabled]{opacity:.5;cursor:not-allowed}
    </style>

    <div class="ssr-auth-wrap">
      <div class="ssr-auth-card" role="form" aria-label="<?php echo esc_attr($a['title']); ?>">
        <!-- SVG lock -->
        <div class="ssr-auth-icon">
          <svg class="ssr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <rect x="3" y="11" width="18" height="10" rx="2"></rect>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
          </svg>
        </div>
        <h1 class="ssr-auth-title"><?php echo esc_html($a['title']); ?></h1>

        <?php echo $msg; ?>

		<?php if ($is_ok): ?>
		  <p style="text-align:center;margin:8px 0 0;color:#111">
			Connecté·e comme <strong><?php echo esc_html($session['name']); ?></strong>
		  </p>
		  <p style="text-align:center;margin-top:12px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
			<a class="ssr-btn ssr-btn-primary" href="<?php echo esc_url( home_url('/retards-verif/') ); ?>">Continuer</a>
			<a class="ssr-btn ssr-btn-secondary" href="<?php echo esc_url(add_query_arg('ssr_logout', 1)); ?>">Se déconnecter</a>
		  </p>
		<?php else: ?>

          <form method="post" action="<?php echo $a['action']; ?>" id="ssr-pin-form" class="ssr-form" autocomplete="one-time-code" novalidate>
            <?php wp_nonce_field('ssr_login_pin','ssr_login_nonce'); ?>

            <!-- ✅ Propage redirect_to si présent -->
            <?php if (!empty($redirect)): ?>
              <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect); ?>">
            <?php endif; ?>

            <label>Vérificateur
              <select name="verifier_id" class="ssr-input" required>
                <option value="">— Sélectionner —</option>
                <?php foreach ($rows as $r): ?>
                  <option value="<?php echo intval($r['id']); ?>">
                    <?php echo esc_html($r['display_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <input type="hidden" name="ssr_pin" id="ssr-pin-hidden" value="">

            <div class="ssr-pin-grid" id="ssr-pin-grid" aria-label="Code PIN à 4 chiffres">
              <input class="ssr-pin-cell" type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Chiffre 1">
              <input class="ssr-pin-cell" type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Chiffre 2">
              <input class="ssr-pin-cell" type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Chiffre 3">
              <input class="ssr-pin-cell" type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="1" aria-label="Chiffre 4">
            </div>

            <div class="ssr-actions">
              <button type="button" class="ssr-btn ssr-btn-secondary" id="ssr-pin-clear">Effacer</button>
              <button type="submit" class="ssr-btn ssr-btn-primary" id="ssr-submit" disabled>Se connecter</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <script>
      (function(){
        const form     = document.getElementById('ssr-pin-form');
        if (!form) return;

        const select   = form.querySelector('select[name="verifier_id"]');
        const submit   = document.getElementById('ssr-submit');
        const grid     = document.getElementById('ssr-pin-grid');
        const cells    = Array.from(grid.querySelectorAll('.ssr-pin-cell'));
        const hidden   = document.getElementById('ssr-pin-hidden');
        const btnClear = document.getElementById('ssr-pin-clear');
        const isDigit  = (k) => /^\d$/.test(k);
        const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);

        function value(){ return cells.map(c => c.value || '').join(''); }
        function ready(){ return (select && select.value) && value().length === 4; }
        function updateUI(){
          cells.forEach(c => c.classList.toggle('filled', !!c.value));
          if (submit) submit.disabled = !ready();
          hidden.value = value();
        }

        cells.forEach((cell, idx) => {
          cell.addEventListener('keydown', (e) => {
            const key = e.key;
            if (key === 'ArrowLeft' && idx > 0) { e.preventDefault(); cells[idx-1].focus(); return; }
            if (key === 'ArrowRight' && idx < 3) { e.preventDefault(); cells[idx+1].focus(); return; }
            if (key === 'Backspace') {
              if (cell.value) {
                cell.value = '';
                updateUI();
              } else if (idx > 0) {
                e.preventDefault();
                cells[idx-1].value = '';
                cells[idx-1].focus();
                updateUI();
              }
              return;
            }
            if (!isDigit(key) && key !== 'Tab') e.preventDefault();
          });

          cell.addEventListener('input', () => {
            cell.value = (cell.value || '').replace(/\D+/g, '').slice(-1);
            updateUI();
            if (cell.value && idx < 3) cells[idx+1].focus();
            if (ready()) setTimeout(() => (form.requestSubmit ? form.requestSubmit() : form.submit()), 60);
          });

          cell.addEventListener('paste', (e) => {
            e.preventDefault();
            const data = (e.clipboardData && e.clipboardData.getData('text')) || '';
            const digits = (data || '').replace(/\D+/g, '').slice(0, 4);
            if (!digits) return;
            for (let i = 0; i < 4; i++) cells[i].value = digits[i] || '';
            updateUI();
            if (ready()) setTimeout(() => (form.requestSubmit ? form.requestSubmit() : form.submit()), 60);
          });
        });

        if (select) {
          select.addEventListener('change', () => {
            updateUI();
            if (ready()) setTimeout(() => (form.requestSubmit ? form.requestSubmit() : form.submit()), 60);
          });
        }

        if (btnClear) {
          btnClear.addEventListener('click', (e) => {
            e.preventDefault();
            cells.forEach(c => c.value = '');
            updateUI();
            cells[0].focus();
          });
        }

        form.addEventListener('submit', (e) => {
          if (!ready()) {
            e.preventDefault();
            if (!select.value) select.focus(); else (cells.find(c => !c.value) || cells[3]).focus();
          }
        });

        updateUI();
        if (!isMobile) setTimeout(() => { try { cells[0].focus(); } catch(e){} }, 120);
      })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('ssr_login', 'ssr_shortcode_ssr_login_minimal');
