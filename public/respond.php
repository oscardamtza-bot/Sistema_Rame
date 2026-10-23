<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['token'] ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173b6c">
    <title>Mantenimiento | Rame TI</title>
    <link rel="stylesheet" href="assets/app.css?v=blue-brand-2">
</head>
<body class="invite-page">
    <main id="invite-app" class="invite-shell" data-token="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <div class="loading-screen"><span class="loader"></span><span>Cargando invitación</span></div>
    </main>
    <div id="toast-region" class="toast-region" aria-live="polite"></div>
    <script src="assets/respond.js?v=blue-brand-2" defer></script>
</body>
</html>