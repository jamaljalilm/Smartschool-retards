<?php
if (!defined('ABSPATH')) exit;

add_shortcode('ssr_nav', function($atts){
    $a = shortcode_atts([
        'login'       => '/connexion-verificateur',
        'calendrier'  => '/calendrier',
        'verif'       => '/retards-verif',
        'retenues'    => '/retenues',
        'recap'       => '/recap-retards',
        'suivi'       => '/suivi',
    ], $atts, 'ssr_nav');

    // URLs absolues
    $urls = [];
    foreach ($a as $k => $path) { $urls[$k] = esc_url( home_url( $path ) ); }

    // Page active (match exact sur le path)
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $isActive = function($path) use ($reqPath){
        $p = rtrim((string)$path,'/');
        $r = rtrim($reqPath,'/');
        return $p !== '' && $p === $r;
    };

    // Icônes SVG sobres (Feather/Lucide-like)
    $icon = function(string $name, string $label=''){
        $aria = $label !== '' ? 'aria-label="'.esc_attr($label).'" role="img"' : 'aria-hidden="true"';
        switch ($name) {
            case 'login': // lock
                return '<svg '.$aria.' class="ssr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="10" rx="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>';
            case 'calendrier': // calendar
                return '<svg '.$aria.' class="ssr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>';
            case 'verif': // clock
                return '<svg '.$aria.' class="ssr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M12 7v5l3 3"></path>
                </svg>';
            case 'retenues': // interdit
                return '<svg '.$aria.' class="ssr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>';
            case 'recap': // bar-chart
                return '<svg '.$aria.' class="ssr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="20" x2="18" y2="10"></line>
                    <line x1="12" y1="20" x2="12" y2="4"></line>
                    <line x1="6" y1="20" x2="6" y2="14"></line>
                </svg>';
            case 'suivi': // user
            default:
                return '<svg '.$aria.' class="ssr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21a8 8 0 0 0-16 0"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>';
        }
    };

    // Styles (labels visibles mais plus petits sur mobile)
    echo '<style>
    .ssr-bottom-nav {
        display:flex;justify-content:space-around;align-items:center;
        position:fixed;bottom:0;left:0;right:0;
        background:#fff;border-top:1px solid #e5e7eb;
        padding:8px 0;z-index:9999;
        font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Inter,Arial;
        -webkit-backdrop-filter:saturate(140%) blur(6px);
        backdrop-filter:saturate(140%) blur(6px);
    }
    .ssr-bottom-nav a {
        text-align:center;text-decoration:none;color:#6b7280;
        flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;
        font-size:12px;font-weight:600;transition:color .2s ease, transform .12s ease;
        padding:6px 0;
    }
    .ssr-bottom-nav a:hover { color:#374151; }
    .ssr-bottom-nav a:active { transform:translateY(1px); }
    .ssr-bottom-nav a.active { color:#f57c00; }
    .ssr-bottom-nav a.active .ssr-icon { stroke:#f57c00; }
    .ssr-icon { width:24px;height:24px;display:block; }
    .nav-label { display:inline; }

    /* Desktop ≥768px : labels 13px, icônes 20px */
    @media (min-width:768px){
        .ssr-icon{ width:20px;height:20px; }
        .ssr-bottom-nav a{ font-size:13px; }
    }

		/* Mobile <768px : forcer un label vraiment petit */
	@media (max-width: 767.98px){
	  /* Neutralise toute taille héritée sur le lien */
	  .ssr-bottom-nav a{
		font-size:0 !important;
		gap:0;
		padding:8px 0;
	  }
	  /* Remet une taille uniquement sur le label */
	  .ssr-bottom-nav a .nav-label{
		display:block;
		font-size:10px !important;  /* ajuste à 9px/8px si tu veux encore plus petit */
		line-height:1.05;
		margin-top:2px;
		white-space:nowrap;          /* évite le retour à la ligne */
		letter-spacing:.1px;         /* lisibilité */
	  }
	  /* Icônes inchangées */
	  .ssr-bottom-nav .ssr-icon{
		width:36px;height:36px;
	  }
}

    </style>';

    // Vérifier si l'utilisateur a accès au suivi
    $can_access_suivi = function_exists('ssr_can_access_suivi') && ssr_can_access_suivi();

    ob_start(); ?>
    <nav class="ssr-bottom-nav" aria-label="Navigation secondaire">
        <!-- Ordre: Connexion → Calendrier → Retards → Retenues → Récap → Suivi (si autorisé) -->
        <a href="<?php echo $urls['login']; ?>" class="<?php echo $isActive($a['login'])?'active':''; ?>">
            <?php echo $icon('login','Connexion'); ?><span class="nav-label">Connexion</span>
        </a>
        <a href="<?php echo $urls['calendrier']; ?>" class="<?php echo $isActive($a['calendrier'])?'active':''; ?>">
            <?php echo $icon('calendrier','Calendrier'); ?><span class="nav-label">Calendrier</span>
        </a>
        <a href="<?php echo $urls['verif']; ?>" class="<?php echo $isActive($a['verif'])?'active':''; ?>">
            <?php echo $icon('verif','Retards'); ?><span class="nav-label">Retards</span>
        </a>
        <a href="<?php echo $urls['retenues']; ?>" class="<?php echo $isActive($a['retenues'])?'active':''; ?>">
            <?php echo $icon('retenues','Retenues'); ?><span class="nav-label">Retenues</span>
        </a>
        <a href="<?php echo $urls['recap']; ?>" class="<?php echo $isActive($a['recap'])?'active':''; ?>">
            <?php echo $icon('recap','Récapitulatif'); ?><span class="nav-label">Récap</span>
        </a>
        <?php if ($can_access_suivi): ?>
        <a href="<?php echo $urls['suivi']; ?>" class="<?php echo $isActive($a['suivi'])?'active':''; ?>">
            <?php echo $icon('suivi','Suivi'); ?><span class="nav-label">Suivi</span>
        </a>
        <?php endif; ?>
    </nav>
    <?php
    return ob_get_clean();
});;
