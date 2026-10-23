<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173b6c">
    <title>Rame | Operación TI</title>
    <link rel="stylesheet" href="assets/app.css?v=assets-responsibility-3">
</head>
<body>
    <main id="app" class="app-shell" aria-live="polite">
        <div class="loading-screen"><span class="loader"></span><span>Conectando con Rame TI</span></div>
    </main>
    <div id="toast-region" class="toast-region" aria-live="polite"></div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
    <script src="assets/app.js?v=assets-responsibility-3" defer></script>
</body>
</html>