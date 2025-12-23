<?php
/**
 * Test direct pour vérifier si la fonction existe
 * Accédez à : /wp-content/plugins/Smartschool-retards/test-function.php
 */

require_once('../../../wp-load.php');

echo '<h1>Test de la fonction ssr_verification_date_for_retard</h1>';

// Vérifie si la fonction existe
if (function_exists('ssr_verification_date_for_retard')) {
    echo '<p style="color:green;"><strong>✅ La fonction existe !</strong></p>';

    // Test avec quelques dates
    $tests = [
        '2025-12-02' => 'Lundi → devrait donner Mardi 2025-12-03',
        '2025-12-17' => 'Mardi → devrait donner Jeudi 2025-12-19',
        '2025-12-18' => 'Mercredi → devrait donner Jeudi 2025-12-19',
        '2025-12-23' => 'Lundi → devrait donner Mardi 2025-12-24',
    ];

    echo '<h2>Tests de calcul :</h2>';
    echo '<table border="1" cellpadding="5">';
    echo '<tr><th>Date retard</th><th>Attendu</th><th>Résultat</th><th>OK?</th></tr>';

    foreach ($tests as $date => $desc) {
        $result = ssr_verification_date_for_retard($date);
        $expected = explode(' ', $desc)[4];
        $ok = ($result === $expected) ? '✅' : '❌';
        echo "<tr><td>$date</td><td>$desc</td><td><strong>$result</strong></td><td>$ok</td></tr>";
    }

    echo '</table>';

} else {
    echo '<p style="color:red;"><strong>❌ La fonction N\'EXISTE PAS !</strong></p>';
    echo '<p>Cela signifie que helpers.php n\'a pas été chargé correctement ou que le cache n\'a pas été vidé.</p>';
}

echo '<hr>';
echo '<p><a href="clear-cache.php">🔄 Vider le cache OPcache</a></p>';
echo '<p><a href="javascript:history.back()">← Retour</a></p>';
