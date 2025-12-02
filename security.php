<?php
if (!defined('ABSPATH')) exit;

// Nonce unified helpers
function ssr_nonce_field($action, $name='_wpnonce'){
    wp_nonce_field($action, $name);
}
function ssr_verify_nonce_or_die($action, $name='_wpnonce'){
    if (!isset($_POST[$name]) || !wp_verify_nonce($_POST[$name], $action)){
        wp_die(__('Security check failed.', 'smartschool-retards'));
    }
}
