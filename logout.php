<?php
session_start();
require_once __DIR__ . '/includes/auditoria.php';
orionRegistrarAtividade();
orionRegistrarSaida();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout - Orion Inventory</title>
    <link rel="stylesheet" href="assets/css/global/import.css">
    <link rel="stylesheet" href="assets/css/pages/logout.css">
    <link rel="icon" type="image/x-icon" href="img/favicon/favicon.ico">
</head>
<body>
    <main class="logout-card">
        <img src="img/global/logos/orion" alt="Orion Inventory" class="logout-logo">
        <div class="logout-icon"><i class="bi bi-check2-circle"></i></div>
        <h1>Até logo!</h1>
        <p>Sua sessão foi encerrada com segurança.</p>
        <a href="index.php" class="logout-login"><i class="bi bi-box-arrow-in-right"></i> Voltar ao login</a>
    </main>
</body>
</html>
