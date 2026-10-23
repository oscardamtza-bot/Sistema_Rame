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
        $name = clean_string($data['name'] ?? '', 140);
        $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $password = (string) ($data['password'] ?? '');
        if ($name === '' || !$email || strlen($password) < 12) {
            json_response(['error' => 'Ingresa nombre, correo válido y una contraseña de al menos 12 caracteres.'], 422);
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
            $countsStatement = db()->prepare('SELECT COUNT(*) AS assets, SUM(type="computer") AS computers, SUM(type="phone") AS phones FROM assets WHERE assigned_user_id=? AND status<>"retired"');
            $countsStatement->execute([$user['id']]);
            $userCounts = $countsStatement->fetch();
            $maintenanceCount = db()->prepare('SELECT COUNT(*) FROM maintenance WHERE user_id=? AND status IN ("pending", "confirmed", "reschedule_requested")');
            $maintenanceCount->execute([$user['id']]);
            $upcomingStatement = db()->prepare('SELECT m.id, m.scheduled_at, m.status, a.asset_tag, a.brand, a.model, u.name AS user_name FROM maintenance m JOIN assets a ON a.id=m.asset_id JOIN users u ON u.id=m.user_id WHERE m.user_id=? AND m.status IN ("pending", "confirmed", "reschedule_requested") ORDER BY m.scheduled_at LIMIT 6');
            $upcomingStatement->execute([$user['id']]);
            json_response(['counts' => ['assets' => (int) $userCounts['assets'], 'computers' => (int) $userCounts['computers'], 'phones' => (int) $userCounts['phones'], 'licenses' => 0, 'maintenance' => (int) $maintenanceCount->fetchColumn()], 'upcoming' => $upcomingStatement->fetchAll(), 'charts' => dashboard_charts((int) $user['id'])]);
        }
        $counts = [
            'assets' => (int) db()->query('SELECT COUNT(*) FROM assets WHERE status <> "retired"')->fetchColumn(),
            'computers' => (int) db()->query('SELECT COUNT(*) FROM assets WHERE type = "computer" AND status <> "retired"')->fetchColumn(),
            'phones' => (int) db()->query('SELECT COUNT(*) FROM assets WHERE type = "phone" AND status <> "retired"')->fetchColumn(),
            'licenses' => (int) db()->query('SELECT COUNT(*) FROM licenses WHERE status IN ("active", "expiring")')->fetchColumn(),
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

    if ($action === 'user-save' && $method === 'POST') {
        require_role(['admin']);
        $name = clean_string($data['name'] ?? '', 140);
        $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $role = in_array($data['role'] ?? '', ['admin', 'technician', 'user'], true) ? $data['role'] : 'user';
        $password = (string) ($data['password'] ?? '');
        if ($name === '' || !$email || strlen($password) < 12) {
            json_response(['error' => 'Ingresa nombre, correo válido y contraseña de al menos 12 caracteres.'], 422);
        }
        db()->prepare('INSERT INTO users (name, email, role, department, password_hash) VALUES (?, ?, ?, ?, ?)')->execute([$name, $email, $role, clean_string($data['department'] ?? '', 140), password_hash($password, PASSWORD_DEFAULT)]);
        json_response(['ok' => true]);
    }

    if ($action === 'assets' && $method === 'GET') {
        $statement = $user['role'] === 'user'
            ? db()->prepare('SELECT a.*, u.name AS assigned_name FROM assets a LEFT JOIN users u ON u.id=a.assigned_user_id WHERE a.assigned_user_id=? ORDER BY a.created_at DESC')
            : db()->query('SELECT a.*, u.name AS assigned_name FROM assets a LEFT JOIN users u ON u.id=a.assigned_user_id ORDER BY a.created_at DESC');
        if ($user['role'] === 'user') {
            $statement->execute([$user['id']]);
        }
        json_response(['assets' => $statement->fetchAll()]);
    }

    if ($action === 'asset-save' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $id = (int) ($data['id'] ?? 0);
        $type = in_array($data['type'] ?? '', ['computer', 'phone', 'tablet', 'other'], true) ? $data['type'] : 'other';
        $phoneFormatStatus = in_array($data['phoneFormatStatus'] ?? '', ['active', 'formatted', 'to_format'], true)
            ? $data['phoneFormatStatus']
            : null;
        $phoneCategory = in_array($data['phoneCategory'] ?? '', ['administrative', 'operational'], true)
            ? $data['phoneCategory']
            : null;
        if ($type === 'phone' && ($phoneFormatStatus === null || $phoneCategory === null)) {
            json_response(['error' => 'Selecciona el estado y la categoría del celular.'], 422);
        }
        $fields = [
            clean_string($data['assetTag'] ?? '', 80),
            $type,
            clean_string($data['brand'] ?? '', 100),
            clean_string($data['model'] ?? '', 120),
            clean_string($data['serialNumber'] ?? '', 150),
            in_array($data['status'] ?? '', ['assigned', 'available', 'maintenance', 'retired'], true) ? $data['status'] : 'available',
            $type === 'phone' ? $phoneFormatStatus : null,
            $type === 'phone' ? $phoneCategory : null,
            !empty($data['assignedUserId']) ? (int) $data['assignedUserId'] : null,
            !empty($data['purchaseDate']) ? $data['purchaseDate'] : null,
            !empty($data['warrantyUntil']) ? $data['warrantyUntil'] : null,
            clean_string($data['notes'] ?? '', 2000),
        ];
        if ($fields[0] === '' || $fields[2] === '' || $fields[3] === '') {
            json_response(['error' => 'Etiqueta, marca y modelo son obligatorios.'], 422);
        }
        if ($id) {
            $statement = db()->prepare('UPDATE assets SET asset_tag=?, type=?, brand=?, model=?, serial_number=?, status=?, phone_format_status=?, phone_category=?, assigned_user_id=?, purchase_date=?, warranty_until=?, notes=? WHERE id=?');
            $statement->execute([...$fields, $id]);
        } else {
            $statement = db()->prepare('INSERT INTO assets (asset_tag, type, brand, model, serial_number, status, phone_format_status, phone_category, assigned_user_id, purchase_date, warranty_until, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute($fields);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'asset-history' && $method === 'GET') {
        $assetId = (int) ($_GET['assetId'] ?? 0);
        $assetQuery = $user['role'] === 'user'
            ? db()->prepare('SELECT id FROM assets WHERE id=? AND assigned_user_id=?')
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
        $component = clean_string($data['component'] ?? '', 160);
        $details = clean_string($data['details'] ?? '', 4000);
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
        $licenses = db()->query('SELECT id, product, vendor, seats, activations, expires_on, status, notes, CASE WHEN license_key_ciphertext IS NULL THEN 0 ELSE 1 END AS has_key FROM licenses ORDER BY expires_on IS NULL, expires_on')->fetchAll();
        json_response(['licenses' => $licenses]);
    }

    if ($action === 'license-save' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $id = (int) ($data['id'] ?? 0);
        $values = [clean_string($data['product'] ?? '', 160), clean_string($data['vendor'] ?? '', 140), max(1, (int) ($data['seats'] ?? 1)), max(0, (int) ($data['activations'] ?? 0)), !empty($data['expiresOn']) ? $data['expiresOn'] : null, clean_string($data['status'] ?? 'active', 20), clean_string($data['notes'] ?? '', 2000)];
        $activationKey = trim((string) ($data['activationKey'] ?? ''));
        $encryptedKey = $activationKey === '' ? null : encrypt_secret($activationKey);
        if ($values[0] === '') {
            json_response(['error' => 'El nombre del producto es obligatorio.'], 422);
        }
        if ($id) {
            if ($encryptedKey !== null) {
                db()->prepare('UPDATE licenses SET product=?, vendor=?, seats=?, activations=?, expires_on=?, status=?, notes=?, license_key_ciphertext=? WHERE id=?')->execute([...$values, $encryptedKey, $id]);
            } else {
                db()->prepare('UPDATE licenses SET product=?, vendor=?, seats=?, activations=?, expires_on=?, status=?, notes=? WHERE id=?')->execute([...$values, $id]);
            }
        } else {
            db()->prepare('INSERT INTO licenses (product, vendor, seats, activations, expires_on, status, notes, license_key_ciphertext) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$values, $encryptedKey]);
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
        $label = clean_string($data['label'] ?? '', 140);
        $secret = (string) ($data['secret'] ?? '');
        if ($label === '' || $secret === '') {
            json_response(['error' => 'El nombre y la contraseña son obligatorios.'], 422);
        }
        $cipher = encrypt_secret($secret);
        $id = (int) ($data['id'] ?? 0);
        $assetId = !empty($data['assetId']) ? (int) $data['assetId'] : null;
        $username = clean_string($data['username'] ?? '', 190);
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
            ? db()->prepare('SELECT m.id, m.scheduled_at, m.proposed_at, m.status, m.notes, m.completed_at, a.asset_tag, a.brand, a.model, u.name AS user_name, u.email AS user_email FROM maintenance m JOIN assets a ON a.id=m.asset_id JOIN users u ON u.id=m.user_id WHERE m.user_id=? ORDER BY m.scheduled_at DESC')
            : db()->query('SELECT m.id, m.scheduled_at, m.proposed_at, m.status, m.notes, m.completed_at, a.asset_tag, a.brand, a.model, u.name AS user_name, u.email AS user_email FROM maintenance m JOIN assets a ON a.id=m.asset_id JOIN users u ON u.id=m.user_id ORDER BY m.scheduled_at DESC');
        if ($user['role'] === 'user') {
            $statement->execute([$user['id']]);
        }
        json_response(['maintenances' => $statement->fetchAll()]);
    }

    if ($action === 'maintenance-save' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $assetId = (int) ($data['assetId'] ?? 0);
        $userId = (int) ($data['userId'] ?? 0);
        $scheduled = strtotime((string) ($data['scheduledAt'] ?? ''));
        if (!$assetId || !$userId || !$scheduled || $scheduled <= time()) {
            json_response(['error' => 'Selecciona un equipo, usuario y horario futuro.'], 422);
        }
        $token = bin2hex(random_bytes(32));
        $stmt = db()->prepare('INSERT INTO maintenance (asset_id, user_id, technician_id, scheduled_at, status, invite_token_hash, notes) VALUES (?, ?, ?, ?, "pending", ?, ?)');
        $stmt->execute([$assetId, $userId, $user['id'], date('Y-m-d H:i:s', $scheduled), hash('sha256', $token), clean_string($data['notes'] ?? '', 2000)]);
        $mailResult = send_maintenance_email($userId, $assetId, date('Y-m-d H:i:s', $scheduled), $token);
        json_response(['ok' => true, 'emailSent' => $mailResult['sent'], 'emailMessage' => $mailResult['message'], 'inviteUrl' => $mailResult['sent'] ? null : $config['app_url'] . '/respond.php?token=' . rawurlencode($token)]);
    }

    if ($action === 'maintenance-approve' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $id = (int) ($data['id'] ?? 0);
        $statement = db()->prepare('SELECT asset_id, user_id, proposed_at FROM maintenance WHERE id=? AND status="reschedule_requested"');
        $statement->execute([$id]);
        $maintenance = $statement->fetch();
        if (!$maintenance || !$maintenance['proposed_at'] || strtotime($maintenance['proposed_at']) <= time()) {
            json_response(['error' => 'La propuesta ya no está disponible o su horario ya pasó.'], 409);
        }
        $token = bin2hex(random_bytes(32));
        db()->prepare('UPDATE maintenance SET scheduled_at=proposed_at, proposed_at=NULL, status="confirmed", invite_token_hash=?, invitation_sent_at=NULL WHERE id=?')->execute([hash('sha256', $token), $id]);
        $mailResult = send_maintenance_email((int) $maintenance['user_id'], (int) $maintenance['asset_id'], $maintenance['proposed_at'], $token);
        json_response(['ok' => true, 'emailSent' => $mailResult['sent'], 'emailMessage' => $mailResult['message'], 'inviteUrl' => $mailResult['sent'] ? null : $config['app_url'] . '/respond.php?token=' . rawurlencode($token)]);
    }

    if ($action === 'maintenance-complete' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $id = (int) ($data['id'] ?? 0);
        $signature = (string) ($data['signature'] ?? '');
        $signedName = clean_string($data['signedName'] ?? '', 140);
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
        $statement = db()->prepare('SELECT m.user_id, m.scheduled_at, m.completed_at, m.signature_data, m.signed_name, a.asset_tag, a.brand, a.model, u.name AS user_name FROM maintenance m JOIN assets a ON a.id=m.asset_id JOIN users u ON u.id=m.user_id WHERE m.id=? AND m.status="completed"');
        $statement->execute([$id]);
        $receipt = $statement->fetch();
        if (!$receipt || (!in_array($user['role'], ['admin', 'technician'], true) && (int) $receipt['user_id'] !== (int) $user['id'])) {
            json_response(['error' => 'No se encontró una constancia disponible.'], 404);
        }
        json_response(['asset' => $receipt['asset_tag'] . ' · ' . $receipt['brand'] . ' ' . $receipt['model'], 'user' => $receipt['user_name'], 'completedAt' => $receipt['completed_at'], 'signedName' => $receipt['signed_name'], 'signature' => $receipt['signature_data']]);
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
        $formType = in_array($data['formType'] ?? '', ['service_order', 'corrective_request'], true) ? $data['formType'] : '';
        $signedName = clean_string($data['signedName'] ?? '', 140);
        $signature = (string) ($data['signature'] ?? '');
        $performedByName = clean_string($data['performedByName'] ?? '', 140);
        $performedBySignature = (string) ($data['performedBySignature'] ?? '');
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $isSignature = static fn (string $value): bool => strlen($value) <= 800000 && preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/D', $value) === 1 && base64_decode(substr($value, 22), true) !== false;
        if (!$assetId || !$userId || !$formType || $signedName === '' || $performedByName === '' || !$isSignature($signature) || !$isSignature($performedBySignature)) {
            json_response(['error' => 'Completa el formato, el equipo, los nombres y las dos firmas.'], 422);
        }
        $allowedFields = $formType === 'service_order'
            ? ['date', 'department', 'cleaningExterior', 'cleaningInterior', 'systemCleanup', 'virusReview', 'computerPasswordStatus', 'satisfaction', 'comments']
            : ['date', 'failure', 'diagnosis', 'activities', 'closingDate', 'satisfaction', 'responseTime', 'solution', 'comments'];
        $fieldLimits = ['date' => 10, 'department' => 140, 'cleaningExterior' => 20, 'cleaningInterior' => 20, 'systemCleanup' => 20, 'virusReview' => 20, 'computerPasswordStatus' => 30, 'satisfaction' => 2, 'comments' => 2000, 'failure' => 2000, 'diagnosis' => 2000, 'activities' => 3000, 'closingDate' => 10, 'responseTime' => 140, 'solution' => 2000];
        $formFields = [];
        foreach ($allowedFields as $field) {
            $value = $fields[$field] ?? '';
            $formFields[$field] = clean_string(is_scalar($value) ? $value : '', $fieldLimits[$field]);
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
            db()->prepare('INSERT INTO responsibility_documents (asset_id, user_id, title, body, form_type, form_data, signed_name, signature_data, signed_at, signed_ip, performed_by_name, performed_by_signature_data, performed_by_signed_at, performed_by_signed_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW(), ?)')->execute([$assetId, $userId, $title, $body, $formType, json_encode($formFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $signedName, $signature, $_SERVER['REMOTE_ADDR'] ?? null, $performedByName, $performedBySignature, $_SERVER['REMOTE_ADDR'] ?? null]);
            db()->prepare('INSERT INTO asset_history (asset_id, event_type, component, details, occurred_at, created_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$assetId, $eventType, $title, $historyDetails, $occurredOn . ' 00:00:00', $user['id']]);
            db()->commit();
        } catch (Throwable $error) {
            db()->rollBack();
            throw $error;
        }
        json_response(['ok' => true]);
    }

    if ($action === 'document-sign' && $method === 'POST') {
        $id = (int) ($data['id'] ?? 0);
        $signature = (string) ($data['signature'] ?? '');
        $signedName = clean_string($data['signedName'] ?? '', 140);
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
    $assetStatusSql = 'SELECT status AS label, COUNT(*) AS value FROM assets';
    $assetTypeSql = 'SELECT type AS label, COUNT(*) AS value FROM assets';
    $maintenanceSql = 'SELECT DATE_FORMAT(scheduled_at, "%Y-%m") AS label, COUNT(*) AS value FROM maintenance WHERE scheduled_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)';
    if ($userId !== null) {
        $assetStatusSql .= ' WHERE assigned_user_id=?';
        $assetTypeSql .= ' WHERE assigned_user_id=?';
        $maintenanceSql .= ' AND user_id=?';
    }
    $assetStatusSql .= ' GROUP BY status ORDER BY status';
    $assetTypeSql .= ' GROUP BY type ORDER BY type';
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
    $licenseRows = $userId === null
        ? db()->query('SELECT status AS label, COUNT(*) AS value FROM licenses GROUP BY status ORDER BY status')->fetchAll()
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