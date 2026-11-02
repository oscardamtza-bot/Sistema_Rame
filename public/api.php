<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($action === 'state' && $method === 'GET') {
        $userCount = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        json_response([
            'setupRequired' => $userCount === 0,
            'user' => current_user(),
            'csrfToken' => csrf_token(),
        ]);
    }

    $data = request_data();
    if ($method !== 'GET') {
        verify_csrf();
    }

    if ($action === 'setup' && $method === 'POST') {
        if ((int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            json_response(['error' => 'La configuración inicial ya se completó.'], 409);
        }
        $name = clean_text($data['name'] ?? '', 140);
        $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $password = (string) ($data['password'] ?? '');
        if ($name === '' || !$email || strlen($password) < 8) {
            json_response(['error' => 'Ingresa nombre, correo válido y una contraseña de al menos 8 caracteres.'], 422);
        }
        $statement = db()->prepare('INSERT INTO users (name, email, role, password_hash) VALUES (?, ?, "admin", ?)');
        $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int) db()->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'admin'];
        json_response(['user' => current_user()]);
    }

    if ($action === 'login' && $method === 'POST') {
        $statement = db()->prepare('SELECT id, name, email, role, password_hash FROM users WHERE email = ? LIMIT 1');
        $statement->execute([filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '']);
        $user = $statement->fetch();
        if (!$user || !password_verify((string) ($data['password'] ?? ''), $user['password_hash'])) {
            json_response(['error' => 'Correo o contraseña incorrectos.'], 422);
        }
        session_regenerate_id(true);
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        json_response(['user' => $user]);
    }

    if ($action === 'logout' && $method === 'POST') {
        require_user();
        $_SESSION = [];
        session_destroy();
        json_response(['ok' => true]);
    }

    if ($action === 'invite' && $method === 'GET') {
        $token = clean_string($_GET['token'] ?? '', 120);
        $statement = db()->prepare('SELECT m.id, m.scheduled_at, m.proposed_at, m.status, m.notes, a.asset_tag, a.brand, a.model, u.name AS user_name FROM maintenance m JOIN assets a ON a.id = m.asset_id JOIN users u ON u.id = m.user_id WHERE m.invite_token_hash = ? LIMIT 1');
        $statement->execute([hash('sha256', $token)]);
        $invite = $statement->fetch();
        if (!$invite) {
            json_response(['error' => 'El enlace no existe o ya no es válido.'], 404);
        }
        json_response(['invite' => $invite]);
    }

    if ($action === 'invite-response' && $method === 'POST') {
        $token = clean_string($data['token'] ?? '', 120);
        $choice = $data['choice'] ?? '';
        $proposed = $data['proposedAt'] ?? null;
        $statement = db()->prepare('SELECT id, status FROM maintenance WHERE invite_token_hash = ? LIMIT 1');
        $statement->execute([hash('sha256', $token)]);
        $invite = $statement->fetch();
        if (!$invite || in_array($invite['status'], ['completed', 'cancelled'], true)) {
            json_response(['error' => 'Esta invitación ya no está disponible.'], 409);
        }
        if ($choice === 'confirm') {
            db()->prepare('UPDATE maintenance SET status = "confirmed", proposed_at = NULL WHERE id = ?')->execute([$invite['id']]);
        } elseif ($choice === 'reschedule' && is_string($proposed) && strtotime($proposed) > time()) {
            db()->prepare('UPDATE maintenance SET status = "reschedule_requested", proposed_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', strtotime($proposed)), $invite['id']]);
        } else {
            json_response(['error' => 'Selecciona una opción y una fecha futura válida.'], 422);
        }
        json_response(['ok' => true]);
    }

    $user = require_user();

    if ($action === 'dashboard' && $method === 'GET') {
        if ($user['role'] === 'user') {
            $countsStatement = db()->prepare('SELECT COUNT(*) AS assets, SUM(UPPER(asset_tag) LIKE "RAS-PC%") AS pcs, SUM(UPPER(asset_tag) LIKE "RAS-LT%") AS laptops FROM assets WHERE type="computer" AND id IN (SELECT asset_id FROM asset_users WHERE user_id=?) AND status<>"retired"');
            $countsStatement->execute([$user['id']]);
            $userCounts = $countsStatement->fetch();
            $maintenanceCount = db()->prepare('SELECT COUNT(*) FROM maintenance WHERE user_id=? AND status IN ("pending", "confirmed", "reschedule_requested")');
            $maintenanceCount->execute([$user['id']]);
            $upcomingStatement = db()->prepare('SELECT m.id, m.scheduled_at, m.status, a.asset_tag, a.brand, a.model, u.name AS user_name FROM maintenance m JOIN assets a ON a.id=m.asset_id JOIN users u ON u.id=m.user_id WHERE m.user_id=? AND m.status IN ("pending", "confirmed", "reschedule_requested") ORDER BY m.scheduled_at LIMIT 6');
            $upcomingStatement->execute([$user['id']]);
            json_response(['counts' => ['assets' => (int) $userCounts['assets'], 'pcs' => (int) $userCounts['pcs'], 'laptops' => (int) $userCounts['laptops'], 'maintenance' => (int) $maintenanceCount->fetchColumn()], 'upcoming' => $upcomingStatement->fetchAll(), 'charts' => dashboard_charts((int) $user['id'])]);
        }
        $counts = [
            'assets' => (int) db()->query('SELECT COUNT(*) FROM assets WHERE type="computer" AND status<>"retired"')->fetchColumn(),
            'pcs' => (int) db()->query('SELECT COUNT(*) FROM assets WHERE type="computer" AND UPPER(asset_tag) LIKE "RAS-PC%" AND status<>"retired"')->fetchColumn(),
            'laptops' => (int) db()->query('SELECT COUNT(*) FROM assets WHERE type="computer" AND UPPER(asset_tag) LIKE "RAS-LT%" AND status<>"retired"')->fetchColumn(),
            'maintenance' => (int) db()->query('SELECT COUNT(*) FROM maintenance WHERE status IN ("pending", "confirmed", "reschedule_requested")')->fetchColumn(),
        ];
        $next = db()->query('SELECT m.id, m.scheduled_at, m.status, a.asset_tag, a.brand, a.model, u.name AS user_name FROM maintenance m JOIN assets a ON a.id = m.asset_id JOIN users u ON u.id = m.user_id WHERE m.status IN ("pending", "confirmed", "reschedule_requested") ORDER BY m.scheduled_at LIMIT 6')->fetchAll();
        json_response(['counts' => $counts, 'upcoming' => $next, 'charts' => dashboard_charts()]);
    }

    if ($action === 'people' && $method === 'GET') {
        $statement = $user['role'] === 'user'
            ? db()->prepare('SELECT id, name, email, department FROM users WHERE id=? ORDER BY name')
            : db()->query('SELECT id, name, email, department FROM users ORDER BY name');
        if ($user['role'] === 'user') {
            $statement->execute([$user['id']]);
        }
        json_response(['people' => $statement->fetchAll()]);
    }

    if ($action === 'users' && $method === 'GET') {
        require_role(['admin']);
        $users = db()->query('SELECT id, name, email, role, department, created_at FROM users ORDER BY name')->fetchAll();
        json_response(['users' => $users]);
    }

    if ($action === 'responsibility-letters' && $method === 'GET') {
        $assetId = (int) ($_GET['assetId'] ?? 0);
        $assetStatement = db()->prepare('SELECT id FROM assets WHERE id=? AND type="computer"');
        $assetStatement->execute([$assetId]);
        if (!$assetStatement->fetch()) {
            json_response(['error' => 'Las cartas responsivas solo están disponibles para PC y laptops.'], 404);
        }
        if ($user['role'] === 'user') {
            $assignmentCheck = db()->prepare('SELECT 1 FROM asset_users WHERE asset_id=? AND user_id=?');
            $assignmentCheck->execute([$assetId, $user['id']]);
            if (!$assignmentCheck->fetchColumn()) {
                json_response(['error' => 'No tienes acceso a las cartas de este equipo.'], 404);
            }
        }
        $sql = 'SELECT l.id, l.user_id, l.assignee_id, l.person_name, l.original_name, l.mime_type, l.file_size, l.created_at, COALESCE(u.name, aa.person_name, l.person_name) AS user_name FROM responsibility_letters l LEFT JOIN users u ON u.id=l.user_id LEFT JOIN asset_assignees aa ON aa.id=l.assignee_id WHERE l.asset_id=?';
        $statement = in_array($user['role'], ['admin', 'technician'], true)
            ? db()->prepare($sql . ' ORDER BY user_name, l.created_at DESC, l.id DESC')
            : db()->prepare($sql . ' AND l.user_id=? ORDER BY l.created_at DESC, l.id DESC');
        $user['role'] === 'user'
            ? $statement->execute([$assetId, (int) $user['id']])
            : $statement->execute([$assetId]);
        json_response(['letters' => $statement->fetchAll()]);
    }

    if ($action === 'responsibility-letter-upload' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $assetId = (int) ($_POST['assetId'] ?? 0);
        $assigneeId = (int) ($_POST['assigneeId'] ?? 0);
        $assetCheck = db()->prepare('SELECT id FROM assets WHERE id=? AND type="computer"');
        $assetCheck->execute([$assetId]);
        if (!$assetCheck->fetchColumn()) {
            json_response(['error' => 'Solo puedes guardar cartas responsivas en PC y laptops.'], 422);
        }
        $assigneeCheck = db()->prepare('SELECT id, user_id, person_name FROM asset_assignees WHERE id=? AND asset_id=?');
        $assigneeCheck->execute([$assigneeId, $assetId]);
        $assignee = $assigneeCheck->fetch();
        if (!$assignee) {
            json_response(['error' => 'Selecciona a una persona asignada a este equipo.'], 422);
        }
        $userId = $assignee['user_id'] !== null ? (int) $assignee['user_id'] : null;
        $personName = clean_text($assignee['person_name'], 140);
        $upload = $_FILES['letter'] ?? null;
        if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['size'])) {
            json_response(['error' => 'Selecciona el archivo escaneado de la carta responsiva.'], 422);
        }
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $message = match ($upload['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite de carga del servidor.',
                UPLOAD_ERR_NO_FILE => 'Selecciona el archivo escaneado de la carta responsiva.',
                default => 'No se pudo recibir el archivo. Intenta cargarlo de nuevo.',
            };
            json_response(['error' => $message], $upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE ? 413 : 422);
        }
        $maxFileSize = 10 * 1024 * 1024;
        if (!is_uploaded_file($upload['tmp_name']) || (int) $upload['size'] < 1 || (int) $upload['size'] > $maxFileSize) {
            json_response(['error' => 'El archivo debe pesar como máximo 10 MB.'], 422);
        }
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($upload['tmp_name']);
        $allowedTypes = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (!is_string($mimeType) || !isset($allowedTypes[$mimeType])) {
            json_response(['error' => 'Solo se permiten archivos PDF, JPG o PNG.'], 422);
        }
        $originalName = str_replace('\\', '/', (string) ($upload['name'] ?? 'carta-responsiva'));
        $originalName = basename($originalName);
        $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName);
        if (!is_string($originalName) || trim($originalName) === '') {
            $originalName = 'carta-responsiva.' . $allowedTypes[$mimeType];
        }
        $filenameExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $extensionTypes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        if (($extensionTypes[$filenameExtension] ?? null) !== $mimeType) {
            json_response(['error' => 'La extensión del archivo no corresponde al formato escaneado.'], 422);
        }
        if ($mimeType === 'application/pdf') {
            $fileHandle = fopen($upload['tmp_name'], 'rb');
            $signature = $fileHandle ? fread($fileHandle, 5) : false;
            if ($fileHandle) {
                fclose($fileHandle);
            }
            if ($signature !== '%PDF-') {
                json_response(['error' => 'El archivo no parece ser un PDF válido.'], 422);
            }
        } else {
            $imageInfo = @getimagesize($upload['tmp_name']);
            if (!is_array($imageInfo) || ($imageInfo['mime'] ?? '') !== $mimeType) {
                json_response(['error' => 'La imagen escaneada no es válida.'], 422);
            }
        }
        $originalName = function_exists('mb_substr') ? mb_substr($originalName, 0, 255) : substr($originalName, 0, 255);
        $storedName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
        $uploadDirectory = dirname(__DIR__) . '/app/uploads/responsibility-letters';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0700, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('No fue posible preparar la carpeta privada para las cartas responsivas.');
        }
        $storedPath = $uploadDirectory . '/' . $storedName;
        if (!move_uploaded_file($upload['tmp_name'], $storedPath)) {
            throw new RuntimeException('No fue posible guardar el archivo de la carta responsiva.');
        }
        try {
            $statement = db()->prepare('INSERT INTO responsibility_letters (asset_id, user_id, assignee_id, person_name, original_name, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$assetId, $userId, $assigneeId, $personName, $originalName, $storedName, $mimeType, (int) $upload['size'], (int) $user['id']]);
        } catch (Throwable $error) {
            if (is_file($storedPath) && !unlink($storedPath)) {
                error_log('[Rame] No se pudo limpiar un archivo de carta responsiva tras fallar su registro.');
            }
            throw $error;
        }
        json_response(['ok' => true]);
    }

    if ($action === 'responsibility-letter-delete' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $letterId = (int) ($data['id'] ?? 0);
        $statement = db()->prepare('SELECT stored_name FROM responsibility_letters WHERE id=?');
        $statement->execute([$letterId]);
        $storedName = $statement->fetchColumn();
        if (!is_string($storedName)) {
            json_response(['error' => 'La carta responsiva no existe.'], 404);
        }
        db()->prepare('DELETE FROM responsibility_letters WHERE id=?')->execute([$letterId]);
        $filePath = dirname(__DIR__) . '/app/uploads/responsibility-letters/' . basename($storedName);
        if (is_file($filePath) && !unlink($filePath)) {
            error_log('[Rame] No se pudo eliminar el archivo de una carta responsiva borrada.');
        }
        json_response(['ok' => true]);
    }

    if ($action === 'responsibility-letter-download' && $method === 'GET') {
        $letterId = (int) ($_GET['id'] ?? 0);
        $statement = db()->prepare('SELECT l.id, COALESCE(l.user_id, aa.user_id) AS user_id, l.original_name, l.stored_name, l.mime_type, l.file_size FROM responsibility_letters l JOIN assets a ON a.id=l.asset_id LEFT JOIN asset_assignees aa ON aa.id=l.assignee_id WHERE l.id=? AND a.type="computer"');
        $statement->execute([$letterId]);
        $letter = $statement->fetch();
        if (!$letter || (!in_array($user['role'], ['admin', 'technician'], true) && (int) $letter['user_id'] !== (int) $user['id'])) {
            json_response(['error' => 'No se encontró la carta responsiva o no tienes permiso para consultarla.'], 404);
        }
        $filePath = dirname(__DIR__) . '/app/uploads/responsibility-letters/' . $letter['stored_name'];
        if (!is_file($filePath)) {
            json_response(['error' => 'El archivo de esta carta responsiva no está disponible en el servidor.'], 404);
        }
        $safeExtension = $letter['mime_type'] === 'application/pdf' ? 'pdf' : ($letter['mime_type'] === 'image/jpeg' ? 'jpg' : 'png');
        $encodedName = rawurlencode($letter['original_name']);
        $disposition = ($_GET['preview'] ?? '') === '1' ? 'inline' : 'attachment';
        header('Content-Type: ' . $letter['mime_type']);
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: ' . $disposition . '; filename="carta-responsiva.' . $safeExtension . '"; filename*=UTF-8\'\'' . $encodedName);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($filePath);
        exit;
    }

    if ($action === 'user-save' && $method === 'POST') {
        require_role(['admin']);
        $name = clean_text($data['name'] ?? '', 140);
        $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $role = in_array($data['role'] ?? '', ['admin', 'technician', 'user'], true) ? $data['role'] : 'user';
        $password = (string) ($data['password'] ?? '');
        if ($name === '' || !$email || strlen($password) < 8) {
            json_response(['error' => 'Ingresa nombre, correo válido y contraseña de al menos 8 caracteres.'], 422);
        }
        db()->prepare('INSERT INTO users (name, email, role, department, password_hash) VALUES (?, ?, ?, ?, ?)')->execute([$name, $email, $role, clean_text($data['department'] ?? '', 140), password_hash($password, PASSWORD_DEFAULT)]);
        json_response(['ok' => true]);
    }

    if ($action === 'assets' && $method === 'GET') {
        $passwordIndicator = in_array($user['role'], ['admin', 'technician'], true)
            ? '(a.windows_password IS NOT NULL OR a.windows_password_ciphertext IS NOT NULL)'
            : '0';
        $assetColumns = 'a.id, a.asset_tag, a.type, a.brand, a.model, a.serial_number, a.windows_username, a.status, a.phone_format_status, a.phone_category, a.assigned_user_id, a.assigned_date, a.department, a.terminal, a.job_title, a.monitor, a.mouse, a.rdp_access, a.purchase_date, a.warranty_until, a.antivirus_product, a.antivirus_expires_on, a.source_assignee_name, a.notes, a.created_at';
        $statement = $user['role'] === 'user'
            ? db()->prepare("SELECT {$assetColumns}, {$passwordIndicator} AS has_windows_password FROM assets a WHERE a.type='computer' AND EXISTS (SELECT 1 FROM asset_users au WHERE au.asset_id=a.id AND au.user_id=?) ORDER BY a.created_at DESC")
            : db()->query("SELECT {$assetColumns}, {$passwordIndicator} AS has_windows_password FROM assets a WHERE a.type='computer' ORDER BY a.created_at DESC");
        if ($user['role'] === 'user') {
            $statement->execute([$user['id']]);
        }
        $assets = $statement->fetchAll();
        if ($assets) {
            $assetIds = array_column($assets, 'id');
            $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
            $assignmentStatement = db()->prepare("SELECT au.asset_id, au.user_id, au.assigned_date, u.name FROM asset_users au JOIN users u ON u.id=au.user_id WHERE au.asset_id IN ({$placeholders}) ORDER BY u.name");
            $assignmentStatement->execute($assetIds);
            $assignmentsByAsset = [];
            foreach ($assignmentStatement->fetchAll() as $assignment) {
                $assignmentsByAsset[$assignment['asset_id']][] = $assignment;
            }
            $assigneeStatement = db()->prepare("SELECT aa.asset_id, aa.id AS assignee_id, aa.user_id, aa.person_name, aa.department, aa.assigned_date, u.name AS account_name FROM asset_assignees aa LEFT JOIN users u ON u.id=aa.user_id WHERE aa.asset_id IN ({$placeholders}) ORDER BY aa.person_name");
            $assigneeStatement->execute($assetIds);
            $assigneesByAsset = [];
            foreach ($assigneeStatement->fetchAll() as $assignee) {
                $assigneesByAsset[$assignee['asset_id']][] = $assignee;
            }
            foreach ($assets as &$asset) {
                $assignments = $assignmentsByAsset[$asset['id']] ?? [];
                $assignees = $assigneesByAsset[$asset['id']] ?? [];
                $asset['assigned_user_ids'] = array_map('intval', array_column($assignments, 'user_id'));
                $asset['assigned_users'] = array_column($assignments, 'name');
                $asset['assignees'] = array_map(static fn (array $assignee): array => [
                    'id' => (int) $assignee['assignee_id'],
                    'user_id' => $assignee['user_id'] !== null ? (int) $assignee['user_id'] : null,
                    'name' => $assignee['account_name'] ?: $assignee['person_name'],
                    'department' => $assignee['department'],
                    'assigned_date' => $assignee['assigned_date'],
                ], $assignees);
                $assigneeNames = array_column($asset['assignees'], 'name');
                $asset['assigned_name'] = $asset['assigned_users']
                    ? implode(', ', $asset['assigned_users'])
                    : ($assigneeNames ? implode(', ', $assigneeNames) : ($asset['source_assignee_name'] ?? ''));
                if ($assignments) {
                    $asset['assigned_user_id'] = (int) $assignments[0]['user_id'];
                    $asset['assigned_date'] = $assignments[0]['assigned_date'];
                }
            }
            unset($asset);
        }
        json_response(['assets' => $assets]);
    }

    if ($action === 'asset-save' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $id = (int) ($data['id'] ?? 0);
        $existingAsset = null;
        if ($id) {
            $assetExists = db()->prepare('SELECT id, type, phone_format_status, phone_category FROM assets WHERE id=?');
            $assetExists->execute([$id]);
            $existingAsset = $assetExists->fetch();
            if (!$existingAsset) {
                json_response(['error' => 'No se encontró el equipo que quieres actualizar.'], 404);
            }
        }
        $type = in_array($data['type'] ?? '', ['computer', 'phone', 'tablet', 'other'], true)
            ? $data['type']
            : ($existingAsset['type'] ?? 'computer');
        if ($type !== 'computer') {
            json_response(['error' => 'Este inventario solo administra PC y laptops.'], 422);
        }
        $phoneFormatStatus = in_array($data['phoneFormatStatus'] ?? '', ['active', 'formatted', 'to_format'], true)
            ? $data['phoneFormatStatus']
            : ($existingAsset['phone_format_status'] ?? null);
        $phoneCategory = in_array($data['phoneCategory'] ?? '', ['administrative', 'operational'], true)
            ? $data['phoneCategory']
            : ($existingAsset['phone_category'] ?? null);
        $assignedUserIds = is_array($data['assignedUserIds'] ?? null)
            ? array_values(array_unique(array_filter(array_map('intval', $data['assignedUserIds']), static fn (int $id): bool => $id > 0)))
            : (!empty($data['assignedUserId']) ? [(int) $data['assignedUserId']] : []);
        $manualPeopleProvided = array_key_exists('assignedPersonNames', $data);
        $manualPeople = [];
        foreach (is_array($data['assignedPersonNames'] ?? null) ? $data['assignedPersonNames'] : [] as $manualName) {
            $manualName = clean_text((string) $manualName, 140);
            if ($manualName !== '') {
                $manualPeople[mb_strtolower($manualName)] = $manualName;
            }
        }
        $manualPeople = array_values($manualPeople);
        if (count($assignedUserIds) + count($manualPeople) > 5) {
            json_response(['error' => 'Un equipo puede tener hasta cinco personas asignadas.'], 422);
        }
        if ($assignedUserIds) {
            $userPlaceholders = implode(',', array_fill(0, count($assignedUserIds), '?'));
            $usersStatement = db()->prepare("SELECT COUNT(*) FROM users WHERE id IN ({$userPlaceholders})");
            $usersStatement->execute($assignedUserIds);
            if ((int) $usersStatement->fetchColumn() !== count($assignedUserIds)) {
                json_response(['error' => 'Una o más personas seleccionadas no existen.'], 422);
            }
        }
        $assignedDate = null;
        if ($assignedUserIds || $manualPeople) {
            $assignedDate = trim((string) ($data['assignedDate'] ?? ''));
            if ($assignedDate !== '') {
                $parsedAssignedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $assignedDate);
                if (!$parsedAssignedDate || $parsedAssignedDate->format('Y-m-d') !== $assignedDate) {
                    json_response(['error' => 'Ingresa una fecha de asignación válida.'], 422);
                }
            } else {
                $assignedDate = null;
            }
        }
        if ($type === 'phone' && ($phoneFormatStatus === null || $phoneCategory === null)) {
            json_response(['error' => 'Selecciona el estado y la categoría del celular.'], 422);
        }
        $warrantyUntil = trim((string) ($data['warrantyUntil'] ?? ''));
        if ($warrantyUntil !== '') {
            $parsedWarrantyUntil = DateTimeImmutable::createFromFormat('!Y-m-d', $warrantyUntil);
            if (!$parsedWarrantyUntil || $parsedWarrantyUntil->format('Y-m-d') !== $warrantyUntil) {
                json_response(['error' => 'Ingresa una fecha de vencimiento de garantía válida.'], 422);
            }
        }
        $antivirusExpiresOn = trim((string) ($data['antivirusExpiresOn'] ?? ''));
        if ($antivirusExpiresOn !== '') {
            $parsedAntivirusExpiry = DateTimeImmutable::createFromFormat('!Y-m-d', $antivirusExpiresOn);
            if (!$parsedAntivirusExpiry || $parsedAntivirusExpiry->format('Y-m-d') !== $antivirusExpiresOn) {
                json_response(['error' => 'Ingresa una fecha de vencimiento de antivirus válida.'], 422);
            }
        }
        $windowsPassword = (string) ($data['windowsPassword'] ?? '');
        $clearWindowsPassword = !empty($data['clearWindowsPassword']);
        if (strlen($windowsPassword) > 4096) {
            json_response(['error' => 'La contraseña no puede superar 4096 caracteres.'], 422);
        }
        if ($clearWindowsPassword) {
            $windowsPasswordToSave = null;
        } elseif ($windowsPassword !== '') {
            $windowsPasswordToSave = $windowsPassword;
        } elseif ($id) {
            $existingPassword = db()->prepare('SELECT windows_password, windows_password_ciphertext FROM assets WHERE id=?');
            $existingPassword->execute([$id]);
            $existingPassword = $existingPassword->fetch();
            if (!$existingPassword) {
                json_response(['error' => 'No se encontró el equipo que quieres actualizar.'], 404);
            }
            $windowsPasswordToSave = $existingPassword['windows_password']
                ?? ($existingPassword['windows_password_ciphertext'] !== null
                    ? decrypt_secret($existingPassword['windows_password_ciphertext'])
                    : null);
        } else {
            $windowsPasswordToSave = null;
        }
        $fields = [
            clean_text($data['assetTag'] ?? '', 80),
            $type,
            clean_text($data['brand'] ?? '', 100),
            clean_text($data['model'] ?? '', 120),
            clean_text($data['serialNumber'] ?? '', 150),
            clean_text($data['windowsUsername'] ?? '', 190),
            in_array($data['status'] ?? '', ['assigned', 'available', 'maintenance', 'retired'], true) ? $data['status'] : 'available',
            $type === 'phone' ? $phoneFormatStatus : null,
            $type === 'phone' ? $phoneCategory : null,
            $assignedUserIds[0] ?? null,
            $assignedDate,
            clean_text($data['department'] ?? '', 140),
            clean_text($data['terminal'] ?? '', 190),
            clean_text($data['position'] ?? '', 140),
            clean_text($data['monitor'] ?? '', 160),
            clean_text($data['mouse'] ?? '', 160),
            clean_text($data['rdpAccess'] ?? '', 190),
            !empty($data['purchaseDate']) ? $data['purchaseDate'] : null,
            $warrantyUntil !== '' ? $warrantyUntil : null,
            clean_text($data['antivirusProduct'] ?? '', 160),
            $antivirusExpiresOn !== '' ? $antivirusExpiresOn : null,
            clean_text($data['sourceAssigneeName'] ?? '', 140),
            clean_text($data['notes'] ?? '', 2000),
        ];
        if ($fields[0] === '' || $fields[2] === '' || $fields[3] === '') {
            json_response(['error' => 'Etiqueta, marca y modelo son obligatorios.'], 422);
        }
        db()->beginTransaction();
        try {
            if ($id) {
                $statement = db()->prepare('UPDATE assets SET asset_tag=?, type=?, brand=?, model=?, serial_number=?, windows_username=?, status=?, phone_format_status=?, phone_category=?, assigned_user_id=?, assigned_date=?, department=?, terminal=?, job_title=?, monitor=?, mouse=?, rdp_access=?, purchase_date=?, warranty_until=?, antivirus_product=?, antivirus_expires_on=?, source_assignee_name=COALESCE(NULLIF(?, ""), source_assignee_name), notes=?, windows_password=?, windows_password_ciphertext=NULL WHERE id=?');
                $statement->execute([...$fields, $windowsPasswordToSave, $id]);
                $assetId = $id;
            } else {
                $statement = db()->prepare('INSERT INTO assets (asset_tag, type, brand, model, serial_number, windows_username, status, phone_format_status, phone_category, assigned_user_id, assigned_date, department, terminal, job_title, monitor, mouse, rdp_access, purchase_date, warranty_until, antivirus_product, antivirus_expires_on, source_assignee_name, notes, windows_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $statement->execute([...$fields, $windowsPasswordToSave]);
                $assetId = (int) db()->lastInsertId();
            }
            $previousDatesStatement = db()->prepare('SELECT user_id, assigned_date FROM asset_users WHERE asset_id=?');
            $previousDatesStatement->execute([$assetId]);
            $previousDates = array_column($previousDatesStatement->fetchAll(), 'assigned_date', 'user_id');
            db()->prepare('DELETE FROM asset_users WHERE asset_id=?')->execute([$assetId]);
            db()->prepare('DELETE FROM asset_assignees WHERE asset_id=? AND user_id IS NOT NULL')->execute([$assetId]);
            $assignmentInsert = db()->prepare('INSERT INTO asset_users (asset_id, user_id, assigned_date) VALUES (?, ?, ?)');
            $personStatement = db()->prepare('SELECT name, department FROM users WHERE id=?');
            $assigneeInsert = db()->prepare('INSERT INTO asset_assignees (asset_id, user_id, person_name, department, assigned_date) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), department=VALUES(department), assigned_date=VALUES(assigned_date)');
            foreach ($assignedUserIds as $assignedUserId) {
                $personAssignedDate = $assignedDate ?? ($previousDates[$assignedUserId] ?? date('Y-m-d'));
                $assignmentInsert->execute([$assetId, $assignedUserId, $personAssignedDate]);
                $personStatement->execute([$assignedUserId]);
                $person = $personStatement->fetch();
                if ($person) {
                    $assigneeInsert->execute([$assetId, $assignedUserId, $person['name'], $person['department'], $personAssignedDate]);
                }
            }
            if ($manualPeopleProvided) {
                db()->prepare('DELETE FROM asset_assignees WHERE asset_id=? AND user_id IS NULL')->execute([$assetId]);
                $manualInsert = db()->prepare('INSERT INTO asset_assignees (asset_id, user_id, person_name, department, assigned_date) VALUES (?, NULL, ?, ?, ?) ON DUPLICATE KEY UPDATE department=VALUES(department)');
                foreach ($manualPeople as $manualName) {
                    $manualInsert->execute([$assetId, $manualName, clean_text($data['department'] ?? '', 140) ?: null, $assignedDate ?: date('Y-m-d')]);
                }
            }
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $error;
        }
        json_response(['ok' => true]);
    }

    if ($action === 'asset-password' && $method === 'POST') {
        require_role(['admin']);
        $statement = db()->prepare('SELECT windows_password, windows_password_ciphertext FROM assets WHERE id=?');
        $statement->execute([(int) ($data['id'] ?? 0)]);
        $passwordRecord = $statement->fetch();
        if (!$passwordRecord) {
            json_response(['error' => 'Equipo no encontrado.'], 404);
        }
        $password = $passwordRecord['windows_password'];
        if ($password === null && $passwordRecord['windows_password_ciphertext'] !== null) {
            $password = decrypt_secret($passwordRecord['windows_password_ciphertext']);
            db()->prepare('UPDATE assets SET windows_password=?, windows_password_ciphertext=NULL WHERE id=?')->execute([$password, (int) $data['id']]);
        }
        if ($password === null) {
            json_response(['error' => 'No hay una contraseña guardada para este equipo.'], 404);
        }
        json_response(['password' => $password]);
    }

    if ($action === 'asset-history' && $method === 'GET') {
        $assetId = (int) ($_GET['assetId'] ?? 0);
        $assetQuery = $user['role'] === 'user'
            ? db()->prepare('SELECT id FROM assets WHERE id=? AND EXISTS (SELECT 1 FROM asset_users WHERE asset_id=assets.id AND user_id=?)')
            : db()->prepare('SELECT id FROM assets WHERE id=?');
        $assetQuery->execute($user['role'] === 'user' ? [$assetId, $user['id']] : [$assetId]);
        if (!$assetQuery->fetchColumn()) {
            json_response(['error' => 'No se encontró el equipo o no tienes acceso a su historial.'], 404);
        }
        $history = db()->prepare('SELECT h.id, h.event_type, h.component, h.details, h.occurred_at, u.name AS created_by_name FROM asset_history h LEFT JOIN users u ON u.id=h.created_by WHERE h.asset_id=? ORDER BY h.occurred_at DESC, h.id DESC');
        $history->execute([$assetId]);
        json_response(['history' => $history->fetchAll()]);
    }

    if ($action === 'asset-history-save' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $assetId = (int) ($data['assetId'] ?? 0);
        $eventType = in_array($data['eventType'] ?? '', ['repair', 'failure', 'inspection', 'other'], true) ? $data['eventType'] : 'other';
        $component = clean_text($data['component'] ?? '', 160);
        $details = clean_text($data['details'] ?? '', 4000);
        $occurredAt = strtotime((string) ($data['occurredAt'] ?? ''));
        $assetQuery = db()->prepare('SELECT id FROM assets WHERE id=?');
        $assetQuery->execute([$assetId]);
        if (!$assetQuery->fetchColumn() || $details === '' || !$occurredAt) {
            json_response(['error' => 'Selecciona un equipo y registra el detalle y la fecha del evento.'], 422);
        }
        db()->prepare('INSERT INTO asset_history (asset_id, event_type, component, details, occurred_at, created_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$assetId, $eventType, $component ?: null, $details, date('Y-m-d H:i:s', $occurredAt), $user['id']]);
        json_response(['ok' => true]);
    }

    if ($action === 'licenses' && $method === 'GET') {
        require_role(['admin', 'technician']);
        $licenses = db()->query('SELECT l.id, l.product, l.vendor, l.seats, l.seats_confirmed, l.activations, l.expires_on, CASE WHEN l.status="inactive" THEN "inactive" WHEN l.expires_on<CURDATE() THEN "expired" WHEN l.expires_on<=DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "expiring" ELSE "active" END AS status, l.notes, CASE WHEN l.license_key_ciphertext IS NULL THEN 0 ELSE 1 END AS has_key, (SELECT GROUP_CONCAT(DISTINCT COALESCE(a.asset_tag, la.asset_serial_snapshot) ORDER BY COALESCE(a.asset_tag, la.asset_serial_snapshot) SEPARATOR ", ") FROM license_assignments la LEFT JOIN assets a ON a.id=la.asset_id WHERE la.license_id=l.id) AS assigned_assets FROM licenses l ORDER BY l.expires_on IS NULL, l.expires_on')->fetchAll();
        json_response(['licenses' => $licenses]);
    }

    if ($action === 'license-save' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $id = (int) ($data['id'] ?? 0);
        $expiresOn = trim((string) ($data['expiresOn'] ?? ''));
        if ($expiresOn !== '') {
            $parsedExpiry = DateTimeImmutable::createFromFormat('!Y-m-d', $expiresOn);
            if (!$parsedExpiry || $parsedExpiry->format('Y-m-d') !== $expiresOn) {
                json_response(['error' => 'Ingresa una fecha de vencimiento válida.'], 422);
            }
        }
        $values = [clean_text($data['product'] ?? '', 160), clean_text($data['vendor'] ?? '', 140), max(1, (int) ($data['seats'] ?? 1)), !empty($data['seatsConfirmed']) ? 1 : 0, max(0, (int) ($data['activations'] ?? 0)), $expiresOn !== '' ? $expiresOn : null, ($data['status'] ?? '') === 'inactive' ? 'inactive' : 'active', clean_text($data['notes'] ?? '', 2000)];
        $activationKey = trim((string) ($data['activationKey'] ?? ''));
        $encryptedKey = $activationKey === '' ? null : encrypt_secret($activationKey);
        if ($values[0] === '') {
            json_response(['error' => 'El nombre del producto es obligatorio.'], 422);
        }
        if ($id) {
            if ($encryptedKey !== null) {
                db()->prepare('UPDATE licenses SET product=?, vendor=?, seats=?, seats_confirmed=?, activations=?, expires_on=?, status=?, notes=?, license_key_ciphertext=? WHERE id=?')->execute([...$values, $encryptedKey, $id]);
            } else {
                db()->prepare('UPDATE licenses SET product=?, vendor=?, seats=?, seats_confirmed=?, activations=?, expires_on=?, status=?, notes=? WHERE id=?')->execute([...$values, $id]);
            }
        } else {
            db()->prepare('INSERT INTO licenses (product, vendor, seats, seats_confirmed, activations, expires_on, status, notes, license_key_ciphertext) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$values, $encryptedKey]);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'license-key' && $method === 'POST') {
        require_role(['admin']);
        $statement = db()->prepare('SELECT license_key_ciphertext FROM licenses WHERE id=?');
        $statement->execute([(int) ($data['id'] ?? 0)]);
        $cipher = $statement->fetchColumn();
        if (!$cipher) {
            json_response(['error' => 'Clave de activación no encontrada.'], 404);
        }
        json_response(['activationKey' => decrypt_secret($cipher)]);
    }

    if ($action === 'credentials' && $method === 'GET') {
        require_role(['admin']);
        $credentials = db()->query('SELECT c.id, c.label, c.username, c.asset_id, c.updated_at, a.asset_tag FROM credentials c LEFT JOIN assets a ON a.id = c.asset_id ORDER BY c.label')->fetchAll();
        json_response(['credentials' => $credentials]);
    }

    if ($action === 'credential-save' && $method === 'POST') {
        require_role(['admin']);
        $label = clean_text($data['label'] ?? '', 140);
        $secret = (string) ($data['secret'] ?? '');
        if ($label === '' || $secret === '') {
            json_response(['error' => 'El nombre y la contraseña son obligatorios.'], 422);
        }
        $cipher = encrypt_secret($secret);
        $id = (int) ($data['id'] ?? 0);
        $assetId = !empty($data['assetId']) ? (int) $data['assetId'] : null;
        $username = clean_text($data['username'] ?? '', 190);
        if ($id) {
            db()->prepare('UPDATE credentials SET label=?, username=?, secret_ciphertext=?, asset_id=? WHERE id=?')->execute([$label, $username, $cipher, $assetId, $id]);
        } else {
            db()->prepare('INSERT INTO credentials (label, username, secret_ciphertext, asset_id) VALUES (?, ?, ?, ?)')->execute([$label, $username, $cipher, $assetId]);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'credential-reveal' && $method === 'POST') {
        require_role(['admin']);
        $statement = db()->prepare('SELECT secret_ciphertext FROM credentials WHERE id=?');
        $statement->execute([(int) ($data['id'] ?? 0)]);
        $cipher = $statement->fetchColumn();
        if (!$cipher) {
            json_response(['error' => 'Credencial no encontrada.'], 404);
        }
        json_response(['secret' => decrypt_secret($cipher)]);
    }

    if ($action === 'maintenances' && $method === 'GET') {
        $statement = $user['role'] === 'user'
            ? db()->prepare('SELECT m.id, m.asset_id, m.user_id, m.scheduled_at, m.proposed_at, m.status, m.notes, m.completed_at, a.asset_tag, a.brand, a.model, u.name AS user_name, u.email AS user_email FROM maintenance m JOIN assets a ON a.id=m.asset_id JOIN users u ON u.id=m.user_id WHERE m.user_id=? ORDER BY m.scheduled_at DESC')
            : db()->query('SELECT m.id, m.asset_id, m.user_id, m.scheduled_at, m.proposed_at, m.status, m.notes, m.completed_at, a.asset_tag, a.brand, a.model, u.name AS user_name, u.email AS user_email FROM maintenance m JOIN assets a ON a.id=m.asset_id JOIN users u ON u.id=m.user_id ORDER BY m.scheduled_at DESC');
        if ($user['role'] === 'user') {
            $statement->execute([$user['id']]);
        }
        $maintenances = $statement->fetchAll();
        $annualGroups = [];
        foreach ($maintenances as $index => $maintenance) {
            if ($maintenance['status'] === 'cancelled') {
                continue;
            }
            $year = (new DateTimeImmutable($maintenance['scheduled_at']))->format('Y');
            $annualGroups[$maintenance['asset_id'] . ':' . $year][] = $index;
        }
        foreach ($annualGroups as $indices) {
            usort($indices, static function (int $left, int $right) use ($maintenances): int {
                $dateComparison = strcmp($maintenances[$left]['scheduled_at'], $maintenances[$right]['scheduled_at']);
                return $dateComparison !== 0
                    ? $dateComparison
                    : ((int) $maintenances[$left]['id'] <=> (int) $maintenances[$right]['id']);
            });
            foreach ($indices as $position => $index) {
                $maintenances[$index]['annual_maintenance_number'] = $position + 1;
            }
        }
        json_response(['maintenances' => $maintenances]);
    }

    if ($action === 'maintenance-save' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $assetId = (int) ($data['assetId'] ?? 0);
        $userId = (int) ($data['userId'] ?? 0);
        $scheduledAt = (string) ($data['scheduledAt'] ?? '');
        if (!$assetId) {
            json_response(['error' => 'Selecciona un equipo para agendar el mantenimiento.'], 422);
        }
        if (!$userId) {
            json_response(['error' => 'Selecciona el usuario que recibirá el mantenimiento.'], 422);
        }
        $assignmentCheck = db()->prepare('SELECT 1 FROM asset_users WHERE asset_id=? AND user_id=?');
        $assignmentCheck->execute([$assetId, $userId]);
        if (!$assignmentCheck->fetchColumn()) {
            json_response(['error' => 'Selecciona una persona asignada al equipo para recibir el mantenimiento.'], 422);
        }
        $scheduledDate = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $scheduledAt);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$scheduledDate || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $scheduledDate->format('Y-m-d H:i') !== $scheduledAt) {
            json_response(['error' => 'Ingresa una fecha y hora válidas para el mantenimiento.'], 422);
        }
        $scheduled = $scheduledDate->getTimestamp();
        if ($scheduled <= time()) {
            json_response(['error' => 'El horario ya pasó. Selecciona una fecha y hora futuras.'], 422);
        }
        $token = bin2hex(random_bytes(32));
        $scheduledAt = date('Y-m-d H:i:s', $scheduled);
        $yearStart = date('Y-01-01 00:00:00', $scheduled);
        $yearEnd = date('Y-01-01 00:00:00', strtotime('+1 year', strtotime($yearStart)));
        db()->beginTransaction();
        try {
            $assetLock = db()->prepare('SELECT id FROM assets WHERE id=? FOR UPDATE');
            $assetLock->execute([$assetId]);
            if (!$assetLock->fetchColumn()) {
                db()->rollBack();
                json_response(['error' => 'No se encontró el equipo seleccionado.'], 404);
            }
            $yearCount = db()->prepare('SELECT COUNT(*) FROM maintenance WHERE asset_id=? AND scheduled_at>=? AND scheduled_at<? AND status<>"cancelled"');
            $yearCount->execute([$assetId, $yearStart, $yearEnd]);
            if ((int) $yearCount->fetchColumn() >= 2) {
                db()->rollBack();
                json_response(['error' => 'Este equipo ya tiene el máximo de dos mantenimientos agendados para ese año.'], 409);
            }
            $stmt = db()->prepare('INSERT INTO maintenance (asset_id, user_id, technician_id, scheduled_at, status, invite_token_hash, notes) VALUES (?, ?, ?, ?, "pending", ?, ?)');
            $stmt->execute([$assetId, $userId, $user['id'], $scheduledAt, hash('sha256', $token), clean_text($data['notes'] ?? '', 2000)]);
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $error;
        }
        $mailResult = send_maintenance_email($userId, $assetId, $scheduledAt, $token);
        json_response(['ok' => true, 'emailSent' => $mailResult['sent'], 'emailMessage' => $mailResult['message'], 'inviteUrl' => $mailResult['sent'] ? null : $config['app_url'] . '/respond.php?token=' . rawurlencode($token)]);
    }

    if ($action === 'maintenance-approve' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $id = (int) ($data['id'] ?? 0);
        $statement = db()->prepare('SELECT id, asset_id, user_id, proposed_at FROM maintenance WHERE id=? AND status="reschedule_requested"');
        $statement->execute([$id]);
        $maintenance = $statement->fetch();
        if (!$maintenance || !$maintenance['proposed_at'] || strtotime($maintenance['proposed_at']) <= time()) {
            json_response(['error' => 'La propuesta ya no está disponible o su horario ya pasó.'], 409);
        }
        $yearStart = date('Y-01-01 00:00:00', strtotime($maintenance['proposed_at']));
        $yearEnd = date('Y-01-01 00:00:00', strtotime('+1 year', strtotime($yearStart)));
        $token = bin2hex(random_bytes(32));
        db()->beginTransaction();
        try {
            $assetLock = db()->prepare('SELECT id FROM assets WHERE id=? FOR UPDATE');
            $assetLock->execute([(int) $maintenance['asset_id']]);
            if (!$assetLock->fetchColumn()) {
                db()->rollBack();
                json_response(['error' => 'No se encontró el equipo del mantenimiento.'], 404);
            }
            $maintenanceLock = db()->prepare('SELECT proposed_at FROM maintenance WHERE id=? AND status="reschedule_requested" FOR UPDATE');
            $maintenanceLock->execute([$id]);
            $currentProposal = $maintenanceLock->fetchColumn();
            if (!$currentProposal || strtotime($currentProposal) <= time()) {
                db()->rollBack();
                json_response(['error' => 'La propuesta ya no está disponible o su horario ya pasó.'], 409);
            }
            $yearCount = db()->prepare('SELECT COUNT(*) FROM maintenance WHERE asset_id=? AND scheduled_at>=? AND scheduled_at<? AND status<>"cancelled" AND id<>?');
            $yearCount->execute([(int) $maintenance['asset_id'], $yearStart, $yearEnd, $id]);
            if ((int) $yearCount->fetchColumn() >= 2) {
                db()->rollBack();
                json_response(['error' => 'Este equipo ya tiene el máximo de dos mantenimientos agendados para ese año; no se puede aprobar el cambio de fecha.'], 409);
            }
            db()->prepare('UPDATE maintenance SET scheduled_at=?, proposed_at=NULL, status="confirmed", invite_token_hash=?, invitation_sent_at=NULL WHERE id=? AND status="reschedule_requested"')
                ->execute([$currentProposal, hash('sha256', $token), $id]);
            db()->commit();
            $maintenance['proposed_at'] = $currentProposal;
        } catch (Throwable $error) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $error;
        }
        $mailResult = send_maintenance_email((int) $maintenance['user_id'], (int) $maintenance['asset_id'], $maintenance['proposed_at'], $token);
        json_response(['ok' => true, 'emailSent' => $mailResult['sent'], 'emailMessage' => $mailResult['message'], 'inviteUrl' => $mailResult['sent'] ? null : $config['app_url'] . '/respond.php?token=' . rawurlencode($token)]);
    }

    if ($action === 'maintenance-complete' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $id = (int) ($data['id'] ?? 0);
        $signature = (string) ($data['signature'] ?? '');
        $signedName = clean_text($data['signedName'] ?? '', 140);
        if (!$id || !str_starts_with($signature, 'data:image/png;base64,') || strlen($signature) > 800000 || $signedName === '') {
            json_response(['error' => 'Captura la firma del usuario y confirma su nombre.'], 422);
        }
        $statement = db()->prepare('UPDATE maintenance SET status="completed", completed_at=NOW(), signature_data=?, signed_name=?, signed_ip=? WHERE id=? AND status NOT IN ("completed", "cancelled")');
        $statement->execute([$signature, $signedName, $_SERVER['REMOTE_ADDR'] ?? null, $id]);
        if (!$statement->rowCount()) {
            json_response(['error' => 'No se encontró un mantenimiento pendiente para cerrar.'], 404);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'maintenance-receipt' && $method === 'POST') {
        $id = (int) ($data['id'] ?? 0);
        $statement = db()->prepare('SELECT m.user_id, d.title, d.form_type, d.form_data, d.signed_name, d.signed_at, d.signed_ip, d.signature_data, d.performed_by_name, d.performed_by_signed_at, d.performed_by_signed_ip, d.performed_by_signature_data, a.asset_tag, a.brand, a.model, u.name AS user_name FROM maintenance m JOIN assets a ON a.id=m.asset_id JOIN users u ON u.id=m.user_id JOIN responsibility_documents d ON d.maintenance_id=m.id AND d.form_type="service_order" WHERE m.id=? AND m.status="completed"');
        $statement->execute([$id]);
        $receipt = $statement->fetch();
        if (!$receipt || (!in_array($user['role'], ['admin', 'technician'], true) && (int) $receipt['user_id'] !== (int) $user['id'])) {
            json_response(['error' => 'No se encontró la Orden de Servicio firmada para este mantenimiento.'], 404);
        }
        json_response(['title' => $receipt['title'], 'formType' => $receipt['form_type'], 'formData' => json_decode((string) $receipt['form_data'], true) ?: [], 'asset' => $receipt['asset_tag'] . ' · ' . $receipt['brand'] . ' ' . $receipt['model'], 'user' => $receipt['user_name'], 'signedName' => $receipt['signed_name'], 'signedAt' => $receipt['signed_at'], 'signedIp' => $receipt['signed_ip'], 'signature' => $receipt['signature_data'], 'performedByName' => $receipt['performed_by_name'], 'performedBySignedAt' => $receipt['performed_by_signed_at'], 'performedBySignedIp' => $receipt['performed_by_signed_ip'], 'performedBySignature' => $receipt['performed_by_signature_data']]);
    }

    if ($action === 'documents' && $method === 'GET') {
        $statement = db()->prepare('SELECT d.id, d.title, d.body, d.form_type, d.signed_name, d.signed_at, d.performed_by_name, d.performed_by_signed_at, a.asset_tag, a.brand, a.model, u.name AS user_name FROM responsibility_documents d JOIN assets a ON a.id=d.asset_id JOIN users u ON u.id=d.user_id WHERE ? IN ("admin", "technician") OR d.user_id=? ORDER BY d.created_at DESC');
        $statement->execute([$user['role'], $user['id']]);
        json_response(['documents' => $statement->fetchAll()]);
    }

    if ($action === 'document-receipt' && $method === 'POST') {
        $id = (int) ($data['id'] ?? 0);
        $statement = db()->prepare('SELECT d.user_id, d.title, d.body, d.form_type, d.form_data, d.signature_data, d.signed_name, d.signed_at, d.signed_ip, d.performed_by_name, d.performed_by_signature_data, d.performed_by_signed_at, d.performed_by_signed_ip, a.asset_tag, a.brand, a.model, u.name AS user_name FROM responsibility_documents d JOIN assets a ON a.id=d.asset_id JOIN users u ON u.id=d.user_id WHERE d.id=? AND d.signed_at IS NOT NULL');
        $statement->execute([$id]);
        $receipt = $statement->fetch();
        if (!$receipt || ($receipt['form_type'] !== 'responsibility' && !$receipt['performed_by_signed_at']) || (!in_array($user['role'], ['admin', 'technician'], true) && (int) $receipt['user_id'] !== (int) $user['id'])) {
            json_response(['error' => 'No se encontró un documento firmado disponible.'], 404);
        }
        json_response(['title' => $receipt['title'], 'body' => $receipt['body'], 'formType' => $receipt['form_type'], 'formData' => json_decode((string) $receipt['form_data'], true) ?: [], 'asset' => $receipt['asset_tag'] . ' · ' . $receipt['brand'] . ' ' . $receipt['model'], 'user' => $receipt['user_name'], 'signedName' => $receipt['signed_name'], 'signedAt' => $receipt['signed_at'], 'signedIp' => $receipt['signed_ip'], 'signature' => $receipt['signature_data'], 'performedByName' => $receipt['performed_by_name'], 'performedBySignedAt' => $receipt['performed_by_signed_at'], 'performedBySignedIp' => $receipt['performed_by_signed_ip'], 'performedBySignature' => $receipt['performed_by_signature_data']]);
    }

    if ($action === 'document-save' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $assetId = (int) ($data['assetId'] ?? 0);
        $userId = (int) ($data['userId'] ?? 0);
        $maintenanceId = (int) ($data['maintenanceId'] ?? 0);
        $formType = in_array($data['formType'] ?? '', ['service_order', 'corrective_request'], true) ? $data['formType'] : '';
        $signedName = clean_text($data['signedName'] ?? '', 140);
        $signature = (string) ($data['signature'] ?? '');
        $performedByName = clean_text($data['performedByName'] ?? '', 140);
        $performedBySignature = (string) ($data['performedBySignature'] ?? '');
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $isSignature = static fn (string $value): bool => strlen($value) <= 800000 && preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/D', $value) === 1 && base64_decode(substr($value, 22), true) !== false;
        if (!$assetId || !$userId || !$formType || $signedName === '' || $performedByName === '' || !$isSignature($signature) || !$isSignature($performedBySignature)) {
            json_response(['error' => 'Completa el formato, el equipo, los nombres y las dos firmas.'], 422);
        }
        $assignmentCheck = db()->prepare('SELECT 1 FROM asset_users WHERE asset_id=? AND user_id=?');
        $assignmentCheck->execute([$assetId, $userId]);
        if (!$assignmentCheck->fetchColumn()) {
            json_response(['error' => 'La persona seleccionada debe estar asignada al equipo del formato.'], 422);
        }
        if ($maintenanceId) {
            $maintenanceCheck = db()->prepare('SELECT asset_id, user_id, status FROM maintenance WHERE id=?');
            $maintenanceCheck->execute([$maintenanceId]);
            $maintenanceRecord = $maintenanceCheck->fetch();
            if (!$maintenanceRecord
                || (int) $maintenanceRecord['asset_id'] !== $assetId
                || (int) $maintenanceRecord['user_id'] !== $userId
                || $formType !== 'service_order') {
                json_response(['error' => 'El mantenimiento no corresponde al equipo y usuario seleccionados.'], 409);
            }
            $existingOrder = db()->prepare('SELECT id FROM responsibility_documents WHERE maintenance_id=? LIMIT 1');
            $existingOrder->execute([$maintenanceId]);
            if ($existingOrder->fetchColumn()) {
                json_response(['error' => 'Este mantenimiento ya tiene una Orden de Servicio guardada. Abre la constancia para consultarla.'], 409);
            }
            if (!in_array($maintenanceRecord['status'], ['pending', 'confirmed', 'reschedule_requested'], true)) {
                json_response(['error' => 'El mantenimiento ya fue cerrado y no admite otra Orden de Servicio.'], 409);
            }
        }
        $allowedFields = $formType === 'service_order'
            ? ['date', 'department', 'cleaningExterior', 'cleaningInterior', 'systemCleanup', 'virusReview', 'computerPasswordStatus', 'satisfaction', 'comments']
            : ['date', 'failure', 'diagnosis', 'activities', 'closingDate', 'satisfaction', 'responseTime', 'solution', 'comments'];
        $fieldLimits = ['date' => 10, 'department' => 140, 'cleaningExterior' => 20, 'cleaningInterior' => 20, 'systemCleanup' => 20, 'virusReview' => 20, 'computerPasswordStatus' => 30, 'satisfaction' => 2, 'comments' => 2000, 'failure' => 2000, 'diagnosis' => 2000, 'activities' => 3000, 'closingDate' => 10, 'responseTime' => 140, 'solution' => 2000];
        $formFields = [];
        foreach ($allowedFields as $field) {
            $value = $fields[$field] ?? '';
            $formFields[$field] = in_array($field, ['department', 'comments', 'failure', 'diagnosis', 'activities', 'responseTime', 'solution'], true)
                ? clean_text(is_scalar($value) ? $value : '', $fieldLimits[$field])
                : clean_string(is_scalar($value) ? $value : '', $fieldLimits[$field]);
        }
        foreach (['date', 'closingDate'] as $field) {
            if (!array_key_exists($field, $formFields) || $formFields[$field] === '') {
                continue;
            }
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $formFields[$field]);
            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $formFields[$field]) {
                json_response(['error' => 'Ingresa una fecha válida para el formato.'], 422);
            }
        }
        if ($formFields['date'] === '') {
            json_response(['error' => 'Ingresa una fecha válida para el formato.'], 422);
        }
        if ($formType === 'service_order') {
            foreach (['cleaningExterior', 'cleaningInterior', 'systemCleanup', 'virusReview'] as $field) {
                if (!in_array($formFields[$field], ['done', 'not_done', 'not_applicable'], true)) {
                    json_response(['error' => 'Selecciona el estado de cada actividad realizada.'], 422);
                }
            }
            if (!in_array($formFields['computerPasswordStatus'], ['not_applicable', 'in_vault'], true)) {
                json_response(['error' => 'Indica si la contraseña quedó resguardada en la bóveda.'], 422);
            }
        } elseif ($formFields['failure'] === '' || $formFields['diagnosis'] === '') {
            json_response(['error' => 'Registra la falla y el diagnóstico de la solicitud.'], 422);
        }
        if (!in_array((int) $formFields['satisfaction'], range(6, 10), true)) {
            json_response(['error' => 'Selecciona una calificación de satisfacción entre 6 y 10.'], 422);
        }
        $title = $formType === 'service_order' ? 'Orden de Servicio' : 'Solicitud de Mantenimiento Correctivo';
        $body = $formFields['comments'] ?? '';
        $activityLabels = ['done' => 'Realizada', 'not_done' => 'No realizada', 'not_applicable' => 'No aplica'];
        if ($formType === 'service_order') {
            $historyDetails = implode("\n", [
                'Orden de Servicio F-SIS-01',
                'Fecha: ' . $formFields['date'],
                'Departamento: ' . ($formFields['department'] ?: 'No especificado'),
                'Limpieza exterior: ' . $activityLabels[$formFields['cleaningExterior']],
                'Limpieza interior: ' . $activityLabels[$formFields['cleaningInterior']],
                'Depuración de sistema: ' . $activityLabels[$formFields['systemCleanup']],
                'Revisión de virus: ' . $activityLabels[$formFields['virusReview']],
                'Contraseña de computadora: ' . ($formFields['computerPasswordStatus'] === 'in_vault' ? 'Resguardada en bóveda' : 'No aplica'),
                'Satisfacción: ' . $formFields['satisfaction'] . '/10',
                'Comentarios: ' . ($formFields['comments'] ?: 'Sin comentarios'),
                'Usuario: ' . $signedName,
                'Realizado por: ' . $performedByName,
            ]);
        } else {
            $historyDetails = implode("\n", [
                'Solicitud de Mantenimiento Correctivo F-SIS-02',
                'Fecha de solicitud: ' . $formFields['date'],
                'Falla: ' . $formFields['failure'],
                'Diagnóstico: ' . $formFields['diagnosis'],
                'Actividades realizadas: ' . ($formFields['activities'] ?: 'No especificadas'),
                'Fecha de cierre: ' . ($formFields['closingDate'] ?: 'Pendiente'),
                'Tiempo de respuesta: ' . ($formFields['responseTime'] ?: 'No especificado'),
                'Solución: ' . ($formFields['solution'] ?: 'No especificada'),
                'Satisfacción: ' . $formFields['satisfaction'] . '/10',
                'Comentarios: ' . ($formFields['comments'] ?: 'Sin comentarios'),
                'Usuario: ' . $signedName,
                'Realizado por: ' . $performedByName,
            ]);
        }
        $eventType = $formType === 'service_order' ? 'inspection' : 'repair';
        $occurredOn = $formType === 'corrective_request' && $formFields['closingDate'] !== '' ? $formFields['closingDate'] : $formFields['date'];
        db()->beginTransaction();
        try {
            db()->prepare('INSERT INTO responsibility_documents (asset_id, user_id, maintenance_id, title, body, form_type, form_data, signed_name, signature_data, signed_at, signed_ip, performed_by_name, performed_by_signature_data, performed_by_signed_at, performed_by_signed_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW(), ?)')->execute([$assetId, $userId, $maintenanceId ?: null, $title, $body, $formType, json_encode($formFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $signedName, $signature, $_SERVER['REMOTE_ADDR'] ?? null, $performedByName, $performedBySignature, $_SERVER['REMOTE_ADDR'] ?? null]);
            db()->prepare('INSERT INTO asset_history (asset_id, event_type, component, details, occurred_at, created_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$assetId, $eventType, $title, $historyDetails, $occurredOn . ' 00:00:00', $user['id']]);
            if ($maintenanceId) {
                $completeMaintenance = db()->prepare('UPDATE maintenance SET status="completed", completed_at=NOW(), signature_data=?, signed_name=?, signed_ip=? WHERE id=? AND status IN ("pending", "confirmed", "reschedule_requested")');
                $completeMaintenance->execute([$signature, $signedName, $_SERVER['REMOTE_ADDR'] ?? null, $maintenanceId]);
                if (!$completeMaintenance->rowCount()) {
                    throw new RuntimeException('El mantenimiento ya no está disponible para cerrar.');
                }
            }
            db()->commit();
        } catch (Throwable $error) {
            db()->rollBack();
            if ($maintenanceId && $error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                json_response(['error' => 'Este mantenimiento ya tiene una Orden de Servicio guardada. Abre la constancia para consultarla.'], 409);
            }
            throw $error;
        }
        json_response(['ok' => true]);
    }

    if ($action === 'document-sign' && $method === 'POST') {
        $id = (int) ($data['id'] ?? 0);
        $signature = (string) ($data['signature'] ?? '');
        $signedName = clean_text($data['signedName'] ?? '', 140);
        if (!$id || $signedName === '' || !str_starts_with($signature, 'data:image/png;base64,') || strlen($signature) > 800000) {
            json_response(['error' => 'Captura una firma válida e ingresa tu nombre.'], 422);
        }
        $statement = db()->prepare('UPDATE responsibility_documents SET signature_data=?, signed_name=?, signed_at=NOW() WHERE id=? AND user_id=? AND signed_at IS NULL');
        $statement->execute([$signature, $signedName, $id, $user['id']]);
        if (!$statement->rowCount()) {
            json_response(['error' => 'No fue posible registrar la firma. Verifica que el documento te corresponda y siga pendiente.'], 422);
        }
        json_response(['ok' => true]);
    }

    json_response(['error' => 'Acción no disponible.'], 404);
} catch (Throwable $error) {
    error_log('[Rame] ' . $error->getMessage());
    json_response(['error' => 'Ocurrió un error al procesar la solicitud. Revisa la configuración del servidor.'], 500);
}

function dashboard_charts(?int $userId = null): array
{
    $assetStatusSql = 'SELECT status AS label, COUNT(*) AS value FROM assets WHERE type="computer"';
    $assetTypeSql = 'SELECT CASE WHEN UPPER(asset_tag) LIKE "RAS-PC%" THEN "pc" WHEN UPPER(asset_tag) LIKE "RAS-LT%" THEN "laptop" ELSE "computer" END AS label, COUNT(*) AS value FROM assets WHERE type="computer" AND status<>"retired"';
    $maintenanceSql = 'SELECT DATE_FORMAT(scheduled_at, "%Y-%m") AS label, COUNT(*) AS value FROM maintenance WHERE scheduled_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)';
    if ($userId !== null) {
        $assetStatusSql .= ' AND id IN (SELECT asset_id FROM asset_users WHERE user_id=?)';
        $assetTypeSql .= ' AND id IN (SELECT asset_id FROM asset_users WHERE user_id=?)';
        $maintenanceSql .= ' AND user_id=?';
    }
    $assetStatusSql .= ' GROUP BY status ORDER BY status';
    $assetTypeSql .= ' GROUP BY 1 ORDER BY FIELD(label, "pc", "laptop", "phone", "computer", "tablet", "other")';
    $maintenanceSql .= ' GROUP BY DATE_FORMAT(scheduled_at, "%Y-%m") ORDER BY label';

    $assetStatus = db()->prepare($assetStatusSql);
    $assetType = db()->prepare($assetTypeSql);
    $maintenance = db()->prepare($maintenanceSql);
    foreach ([$assetStatus, $assetType, $maintenance] as $statement) {
        if ($userId !== null) {
            $statement->execute([$userId]);
        } else {
            $statement->execute();
        }
    }

    $assetStatusRows = $assetStatus->fetchAll();
    $assetTypeRows = $assetType->fetchAll();
    $monthlyCounts = array_column($maintenance->fetchAll(), 'value', 'label');
    $monthNames = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $antivirusRows = [];
    $antivirusStatusLabels = ['active' => 'Vigente', 'expiring' => 'Por vencer', 'expired' => 'Vencido', 'no_expiry' => 'Sin fecha'];
    $antivirusFilter = $userId === null ? '' : ' AND id IN (SELECT asset_id FROM asset_users WHERE user_id=?)';
    foreach ([['ESET', '%ESET%'], ['Windows Defender', '%WINDOWS DEFENDER%']] as [$product, $pattern]) {
        $antivirusStatement = db()->prepare('SELECT CASE WHEN antivirus_expires_on IS NULL THEN "no_expiry" WHEN antivirus_expires_on<CURDATE() THEN "expired" WHEN antivirus_expires_on<=DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "expiring" ELSE "active" END AS antivirus_status, COUNT(*) AS value FROM assets WHERE type="computer" AND antivirus_product LIKE ?' . $antivirusFilter . ' GROUP BY 1');
        $antivirusStatement->execute($userId === null ? [$pattern] : [$pattern, $userId]);
        foreach ($antivirusStatement->fetchAll() as $row) {
            $antivirusRows[] = ['label' => $product . ' · ' . ($antivirusStatusLabels[$row['antivirus_status']] ?? $row['antivirus_status']), 'value' => (int) $row['value']];
        }
    }
    $noAntivirusStatement = db()->prepare('SELECT COUNT(*) FROM assets WHERE type="computer" AND (antivirus_product IS NULL OR TRIM(antivirus_product)="") AND antivirus_expires_on IS NULL' . $antivirusFilter);
    $noAntivirusStatement->execute($userId === null ? [] : [$userId]);
    $noAntivirusCount = (int) $noAntivirusStatement->fetchColumn();
    if ($noAntivirusCount > 0) {
        $antivirusRows[] = ['label' => 'Sin antivirus', 'value' => $noAntivirusCount];
    }
    $licenseRows = $userId === null
    ? db()->query('SELECT CASE WHEN status="inactive" THEN "inactive" WHEN expires_on<CURDATE() THEN "expired" WHEN expires_on<=DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN "expiring" ELSE "active" END AS label, COUNT(*) AS value FROM licenses GROUP BY label ORDER BY label')->fetchAll()
        : [];
    if ($userId === null) {
        $maintenanceStatusRows = db()->query('SELECT status AS label, COUNT(*) AS value FROM maintenance GROUP BY status ORDER BY status')->fetchAll();
    } else {
        $maintenanceStatusStatement = db()->prepare('SELECT status AS label, COUNT(*) AS value FROM maintenance WHERE user_id=? GROUP BY status ORDER BY status');
        $maintenanceStatusStatement->execute([$userId]);
        $maintenanceStatusRows = $maintenanceStatusStatement->fetchAll();
    }
    $months = [];
    $monthValues = [];
    for ($offset = 5; $offset >= 0; $offset--) {
        $month = date('Y-m', strtotime("first day of -{$offset} months"));
        $monthTimestamp = strtotime($month . '-01');
        $months[] = $monthNames[(int) date('n', $monthTimestamp)] . ' ' . date('y', $monthTimestamp);
        $monthValues[] = (int) ($monthlyCounts[$month] ?? 0);
    }

    return [
        'assetStatus' => ['labels' => array_column($assetStatusRows, 'label'), 'values' => array_map('intval', array_column($assetStatusRows, 'value'))],
        'assetType' => ['labels' => array_column($assetTypeRows, 'label'), 'values' => array_map('intval', array_column($assetTypeRows, 'value'))],
        'antivirusStatus' => ['labels' => array_column($antivirusRows, 'label'), 'values' => array_map('intval', array_column($antivirusRows, 'value'))],
        'licenseStatus' => ['labels' => array_column($licenseRows, 'label'), 'values' => array_map('intval', array_column($licenseRows, 'value'))],
        'maintenanceStatus' => ['labels' => array_column($maintenanceStatusRows, 'label'), 'values' => array_map('intval', array_column($maintenanceStatusRows, 'value'))],
        'maintenanceMonthly' => ['labels' => $months, 'values' => $monthValues],
    ];
}

function send_maintenance_email(int $userId, int $assetId, string $scheduledAt, string $token): array
{
    global $config;
    $statement = db()->prepare('SELECT u.name, u.email, a.asset_tag, a.brand, a.model FROM users u JOIN assets a ON a.id=? WHERE u.id=?');
    $statement->execute([$assetId, $userId]);
    $details = $statement->fetch();
    if (!$details || !$config['smtp']['host'] || !$config['smtp']['from_email']) {
        return ['sent' => false, 'message' => 'Mantenimiento agendado. Falta configurar SMTP; el correo no se envió.'];
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        return ['sent' => false, 'message' => 'Mantenimiento agendado. Instala las dependencias de Composer para habilitar el correo.'];
    }
    require_once $autoload;
    $mailerClass = 'PHPMailer\\PHPMailer\\PHPMailer';
    if (!class_exists($mailerClass)) {
        return ['sent' => false, 'message' => 'Mantenimiento agendado. PHPMailer no está instalado; ejecuta composer install para habilitar el correo.'];
    }
    try {
        $mail = new $mailerClass(true);
        $mail->isSMTP();
        $mail->Host = $config['smtp']['host'];
        $mail->Port = $config['smtp']['port'];
        $mail->SMTPAuth = $config['smtp']['username'] !== '';
        $mail->Username = $config['smtp']['username'];
        $mail->Password = $config['smtp']['password'];
        if ($config['smtp']['encryption'] === 'tls') {
            $mail->SMTPSecure = constant($mailerClass . '::ENCRYPTION_STARTTLS');
        } elseif ($config['smtp']['encryption'] === 'ssl') {
            $mail->SMTPSecure = constant($mailerClass . '::ENCRYPTION_SMTPS');
        }
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($config['smtp']['from_email'], $config['smtp']['from_name']);
        $mail->addAddress($details['email'], $details['name']);
        $mail->isHTML(true);
        $mail->Subject = 'Agenda tu mantenimiento preventivo';
        $inviteUrl = $config['app_url'] . '/respond.php?token=' . rawurlencode($token);
        $date = date('d/m/Y H:i', strtotime($scheduledAt));
        $mail->Body = '<p>Hola ' . htmlspecialchars($details['name'], ENT_QUOTES, 'UTF-8') . ',</p><p>Se programó el mantenimiento de <strong>' . htmlspecialchars($details['asset_tag'] . ' · ' . $details['brand'] . ' ' . $details['model'], ENT_QUOTES, 'UTF-8') . '</strong> para el ' . $date . '.</p><p><a href="' . htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8') . '">Confirmar o proponer otro horario</a></p>';
        $mail->AltBody = "Se programó tu mantenimiento para el {$date}. Confirma o propone otro horario: {$inviteUrl}";
        $mail->send();
        db()->prepare('UPDATE maintenance SET invitation_sent_at=NOW() WHERE invite_token_hash=?')->execute([hash('sha256', $token)]);
        return ['sent' => true, 'message' => 'Invitación enviada por correo.'];
    } catch (Throwable $error) {
        error_log('[Rame mail] ' . $error->getMessage());
        return ['sent' => false, 'message' => 'Mantenimiento agendado, pero SMTP no pudo enviar el correo. Revisa la configuración.'];
    }
}