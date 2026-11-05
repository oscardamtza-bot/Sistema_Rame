<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function protect_document_form_data(array $formData, array $user): array
{
    if (!in_array($user['role'] ?? '', ['admin', 'technician'], true)
        && array_key_exists('computerPassword', $formData)) {
        $formData['computerPassword'] = 'CONFIDENCIAL';
    }

    return $formData;
}

function parse_maintenance_slot(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d H:i') !== $value) {
        return null;
    }
    $minutes = ((int) $date->format('H')) * 60 + (int) $date->format('i');
    if ($minutes < 510 || $minutes >= 1020 || $minutes % 30 !== 0 || $date->getTimestamp() <= time()) {
        return null;
    }
    return $date;
}

function is_duplicate_slot_error(Throwable $error): bool
{
    return $error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062;
}

function normalize_person_key(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    if (function_exists('iconv')) {
        $asciiName = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($asciiName !== false) $name = $asciiName;
    }
    return mb_strtolower($name, 'UTF-8');
}

/** Read current equipment and assignment data from Activos. */
function department_assets(string $department, bool $includeEmailSuggestions = false): array
{
    $statement = db()->prepare('SELECT id, asset_tag, type, brand, model, status, assigned_user_id, source_assignee_name FROM assets WHERE department=? AND status<>"retired" ORDER BY asset_tag');
    $statement->execute([$department]);
    $assets = $statement->fetchAll();
    if (!$assets) return ['assets' => [], 'emailSuggestions' => []];

    $assetIds = array_map('intval', array_column($assets, 'id'));
    $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
    $peopleByAsset = [];
    $emails = [];
    $addPerson = static function (int $assetId, string $name, ?string $email = null) use (&$peopleByAsset, &$emails, $includeEmailSuggestions): void {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') return;
        $key = normalize_person_key($name);
        $peopleByAsset[$assetId][$key] = $name;
        if ($includeEmailSuggestions && $email && filter_var($email, FILTER_VALIDATE_EMAIL)) $emails[strtolower($email)] = $email;
    };

    $assignees = db()->prepare("SELECT aa.asset_id, aa.person_name, COALESCE(u.email, matched.email) AS email FROM asset_assignees aa LEFT JOIN users u ON u.id=aa.user_id LEFT JOIN users matched ON aa.user_id IS NULL AND LOWER(TRIM(matched.name))=LOWER(TRIM(aa.person_name)) WHERE aa.asset_id IN ({$placeholders})");
    $assignees->execute($assetIds);
    foreach ($assignees->fetchAll() as $row) $addPerson((int) $row['asset_id'], (string) $row['person_name'], $row['email'] ?: null);

    $assignedUsers = db()->prepare("SELECT au.asset_id, u.name, u.email FROM asset_users au JOIN users u ON u.id=au.user_id WHERE au.asset_id IN ({$placeholders})");
    $assignedUsers->execute($assetIds);
    foreach ($assignedUsers->fetchAll() as $row) $addPerson((int) $row['asset_id'], (string) $row['name'], $row['email'] ?: null);

    $assignedIds = array_values(array_unique(array_filter(array_map(static fn (array $asset): int => (int) ($asset['assigned_user_id'] ?? 0), $assets))));
    $assignedPeople = [];
    if ($assignedIds) {
        $userPlaceholders = implode(',', array_fill(0, count($assignedIds), '?'));
        $assignedQuery = db()->prepare("SELECT id,name,email FROM users WHERE id IN ({$userPlaceholders})");
        $assignedQuery->execute($assignedIds);
        foreach ($assignedQuery->fetchAll() as $person) $assignedPeople[(int) $person['id']] = $person;
    }

    foreach ($assets as &$asset) {
        $assetId = (int) $asset['id'];
        if (!empty($asset['assigned_user_id'])) {
            $person = $assignedPeople[(int) $asset['assigned_user_id']] ?? null;
            if ($person) $addPerson($assetId, (string) $person['name'], $person['email'] ?: null);
        }
        foreach (preg_split('/[\r\n;,|]+/', (string) ($asset['source_assignee_name'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
            $addPerson($assetId, $name);
        }
        $asset['assigned_people'] = array_values($peopleByAsset[$assetId] ?? []);
        unset($asset['assigned_user_id'], $asset['source_assignee_name']);
    }
    unset($asset);

    return ['assets' => $assets, 'emailSuggestions' => array_values($emails)];
}

function append_department_request_event(int $requestId, string $eventType, string $details, ?int $actorId = null): void
{
    db()->prepare('INSERT INTO maintenance_department_request_history (request_id,event_type,details,actor_id) VALUES (?,?,?,?)')
        ->execute([$requestId, $eventType, $details !== '' ? $details : null, $actorId]);
}

function append_linked_maintenance_event(int $maintenanceId, string $eventType, string $details, ?int $actorId = null): void
{
    $query = db()->prepare('SELECT request_id FROM maintenance_department_request_services WHERE maintenance_id=? LIMIT 1');
    $query->execute([$maintenanceId]);
    $requestId = (int) $query->fetchColumn();
    if ($requestId) append_department_request_event($requestId, $eventType, $details, $actorId);
}

function department_plan_period(int $year, int $month): array
{
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
    return [$start, $start->modify('first day of next month')];
}

function send_department_maintenance_email(array $plan, array $assets, string $recipient, string $inviteUrl): array
{
    global $config;
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (empty($config['smtp']['host']) || empty($config['smtp']['username']) || empty($config['smtp']['password']) || empty($config['smtp']['from_email']) || !is_file($autoload)) {
        return ['sent' => false, 'message' => 'No se envió el correo. Falta la configuración Gmail SMTP o PHPMailer.'];
    }
    require_once $autoload;
    $mailerClass = 'PHPMailer\\PHPMailer\\PHPMailer';
    if (!class_exists($mailerClass)) return ['sent' => false, 'message' => 'No se envió el correo. PHPMailer no está disponible.'];
    $monthName = (new DateTimeImmutable(sprintf('%04d-%02d-01', (int) $plan['plan_year'], (int) $plan['plan_month'])))->format('F');
    $rows = [];
    foreach ($assets as $asset) {
        $tag = htmlspecialchars((string) $asset['asset_tag'], ENT_QUOTES, 'UTF-8');
        $details = htmlspecialchars(trim(($asset['brand'] ?? '') . ' ' . ($asset['model'] ?? '')), ENT_QUOTES, 'UTF-8');
        $people = htmlspecialchars(implode(', ', $asset['assigned_people'] ?? []), ENT_QUOTES, 'UTF-8');
        $rows[] = '<tr><td style="padding:8px;border:1px solid #d8e3f0"><strong>' . $tag . '</strong></td><td style="padding:8px;border:1px solid #d8e3f0">' . $details . '</td><td style="padding:8px;border:1px solid #d8e3f0">' . ($people !== '' ? $people : 'Sin persona asignada en Activos') . '</td></tr>';
    }
    try {
        $mail = new $mailerClass(true);
        $mail->isSMTP();
        $mail->Host = $config['smtp']['host'];
        $mail->Port = $config['smtp']['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp']['username'];
        $mail->Password = $config['smtp']['password'];
        if ($config['smtp']['encryption'] === 'tls') $mail->SMTPSecure = constant($mailerClass . '::ENCRYPTION_STARTTLS');
        elseif ($config['smtp']['encryption'] === 'ssl') $mail->SMTPSecure = constant($mailerClass . '::ENCRYPTION_SMTPS');
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($config['smtp']['from_email'], $config['smtp']['from_name']);
        $mail->addAddress($recipient);
        $mail->isHTML(true);
        $mail->Subject = 'Confirmación de mantenimiento preventivo · ' . $plan['department'];
        $safeDepartment = htmlspecialchars((string) $plan['department'], ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8');
        $mail->Body = '<p>El área de TI solicita coordinar el mantenimiento preventivo del departamento <strong>' . $safeDepartment . '</strong> para ' . $monthName . ' de ' . (int) $plan['plan_year'] . '.</p><p>Equipos incluidos: <strong>' . count($assets) . '</strong></p><table style="border-collapse:collapse;width:100%"><thead><tr><th style="padding:8px;border:1px solid #d8e3f0">Inventario</th><th style="padding:8px;border:1px solid #d8e3f0">Equipo</th><th style="padding:8px;border:1px solid #d8e3f0">Persona(s) asignada(s)</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table><p>Abra el enlace para revisar la solicitud y elegir fecha y hora de inicio. El sistema asignará un espacio de 30 minutos por equipo desde el horario elegido, omitirá los espacios ocupados y continuará en días posteriores del mismo mes si hace falta. La atención es de 8:30 a. m. a 5:00 p. m.</p><p><a href="' . $safeUrl . '">Revisar equipos y confirmar horario</a></p>';
        $mail->AltBody = 'Mantenimiento preventivo de ' . $plan['department'] . ' en ' . $monthName . ' de ' . $plan['plan_year'] . '. Equipos: ' . count($assets) . '. Revise y confirme el horario: ' . $inviteUrl;
        $mail->send();
        return ['sent' => true, 'message' => 'Gmail SMTP aceptó el correo. La entrega final depende del servicio del destinatario.'];
    } catch (Throwable $error) {
        error_log('[Rame department mail] SMTP send failed.');
        return ['sent' => false, 'message' => 'Gmail SMTP no confirmó el envío. Revisa la configuración y vuelve a intentar.'];
    }
}

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

    if ($action === 'invite-availability' && $method === 'GET') {
        $token = clean_string($_GET['token'] ?? '', 120);
        $dateValue = clean_string($_GET['date'] ?? '', 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $dateValue) {
            json_response(['error' => 'Selecciona una fecha válida.'], 422);
        }
        $inviteQuery = db()->prepare('SELECT id, status FROM maintenance WHERE invite_token_hash=? LIMIT 1');
        $inviteQuery->execute([hash('sha256', $token)]);
        $invite = $inviteQuery->fetch();
        if (!$invite || in_array($invite['status'], ['completed', 'cancelled'], true)) {
            json_response(['error' => 'Esta invitación ya no está disponible.'], 404);
        }
        $busyQuery = db()->prepare('SELECT DATE_FORMAT(reserved_slot, "%H:%i") FROM maintenance WHERE reserved_slot>=? AND reserved_slot<? AND id<>?');
        $busyQuery->execute([$date->format('Y-m-d 00:00:00'), $date->modify('+1 day')->format('Y-m-d 00:00:00'), $invite['id']]);
        $busy = array_fill_keys(array_column($busyQuery->fetchAll(PDO::FETCH_NUM), 0), true);
        $slots = [];
        for ($minute = 510; $minute < 1020; $minute += 30) {
            $time = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
            $slotDate = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $dateValue . ' ' . $time);
            $slots[] = ['time' => $time, 'available' => !isset($busy[$time]) && $slotDate && $slotDate->getTimestamp() > time()];
        }
        json_response(['date' => $dateValue, 'slots' => $slots]);
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
        } elseif ($choice === 'reschedule' && is_string($proposed) && ($proposedDate = parse_maintenance_slot($proposed))) {
            try {
                db()->prepare('UPDATE maintenance SET status = "reschedule_requested", proposed_at = ? WHERE id = ?')->execute([$proposedDate->format('Y-m-d H:i:s'), $invite['id']]);
            } catch (Throwable $error) {
                if (is_duplicate_slot_error($error)) json_response(['error' => 'Ese horario acaba de ocuparse. Elige otro horario disponible.'], 409);
                throw $error;
            }
        } else {
            json_response(['error' => 'Selecciona una opción y una fecha futura válida.'], 422);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'department-invite' && $method === 'GET') {
        $token = clean_string($_GET['token'] ?? '', 120);
        $statement = db()->prepare('SELECT t.id AS token_id, t.status AS token_status, t.expires_at, r.id AS request_id, r.status AS request_status, r.scheduled_start, r.confirmed_at, p.plan_year, p.plan_month, p.department FROM maintenance_department_request_tokens t JOIN maintenance_department_requests r ON r.id=t.request_id JOIN maintenance_department_plans p ON p.id=r.plan_id WHERE t.token_hash=? AND t.expires_at>NOW() AND t.status IN ("sent","confirmed") LIMIT 1');
        $statement->execute([hash('sha256', $token)]);
        $invite = $statement->fetch();
        if (!$invite) json_response(['error' => 'El enlace no existe, venció o ya no está disponible.'], 404);
        $itemsQuery = db()->prepare('SELECT COALESCE(a.asset_tag,ra.asset_tag_snapshot) AS asset_tag, a.type, a.brand, a.model, ra.assigned_people_snapshot FROM maintenance_department_request_assets ra LEFT JOIN assets a ON a.id=ra.asset_id WHERE ra.token_id=? ORDER BY ra.asset_tag_snapshot');
        $itemsQuery->execute([(int) $invite['token_id']]);
        $items = $itemsQuery->fetchAll();
        json_response(['invite' => [
            'department' => $invite['department'], 'year' => (int) $invite['plan_year'], 'month' => (int) $invite['plan_month'],
            'status' => $invite['request_status'], 'tokenStatus' => $invite['token_status'], 'scheduledStart' => $invite['scheduled_start'],
            'confirmedAt' => $invite['confirmed_at'], 'itemCount' => count($items), 'items' => $items,
        ]]);
    }

    if ($action === 'department-availability' && $method === 'GET') {
        $token = clean_string($_GET['token'] ?? '', 120);
        $dateValue = clean_string($_GET['date'] ?? '', 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $dateValue) {
            json_response(['error' => 'Selecciona una fecha válida.'], 422);
        }
        $inviteQuery = db()->prepare('SELECT t.id AS token_id, t.status, r.id AS request_id, r.status AS request_status, p.plan_year, p.plan_month FROM maintenance_department_request_tokens t JOIN maintenance_department_requests r ON r.id=t.request_id JOIN maintenance_department_plans p ON p.id=r.plan_id WHERE t.token_hash=? AND t.expires_at>NOW() LIMIT 1');
        $inviteQuery->execute([hash('sha256', $token)]);
        $invite = $inviteQuery->fetch();
        if (!$invite || $invite['status'] !== 'sent' || $invite['request_status'] !== 'email_sent') {
            json_response(['error' => 'Esta solicitud ya no acepta cambios de horario.'], 409);
        }
        if ((int) $date->format('Y') !== (int) $invite['plan_year'] || (int) $date->format('n') !== (int) $invite['plan_month'] || $date->getTimestamp() <= time()) {
            json_response(['error' => 'Elige una fecha futura dentro del periodo de mantenimiento indicado.'], 422);
        }
        $itemCountQuery = db()->prepare('SELECT COUNT(*) FROM maintenance_department_request_assets WHERE token_id=? AND asset_id IS NOT NULL');
        $itemCountQuery->execute([(int) $invite['token_id']]);
        $itemCount = (int) $itemCountQuery->fetchColumn();
        if ($itemCount < 1) json_response(['error' => 'La solicitud no tiene equipos disponibles para programar.'], 409);

        [$periodStart, $periodEnd] = department_plan_period((int) $invite['plan_year'], (int) $invite['plan_month']);
        $busyQuery = db()->prepare('SELECT DATE_FORMAT(reserved_slot,"%Y-%m-%d %H:%i") FROM maintenance WHERE reserved_slot>=? AND reserved_slot<?');
        $busyQuery->execute([$periodStart->format('Y-m-d H:i:s'), $periodEnd->format('Y-m-d H:i:s')]);
        $busy = array_fill_keys($busyQuery->fetchAll(PDO::FETCH_COLUMN), true);
        $now = new DateTimeImmutable('now');
        $slots = [];
            for ($minute = 510; $minute < 1020; $minute += 30) {
            $time = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
            $candidate = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $dateValue . ' ' . $time);
            $available = $candidate && $candidate > $now && !isset($busy[$candidate->format('Y-m-d H:i')]);
            $freeCount = 0;
            if ($available) {
                for ($day = $date; $day < $periodEnd && $freeCount < $itemCount; $day = $day->modify('+1 day')) {
                    $firstMinute = $day->format('Y-m-d') === $dateValue ? $minute : 510;
                    for ($nextMinute = $firstMinute; $nextMinute < 1020 && $freeCount < $itemCount; $nextMinute += 30) {
                        $nextSlot = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $day->format('Y-m-d') . ' ' . sprintf('%02d:%02d', intdiv($nextMinute, 60), $nextMinute % 60));
                        if ($nextSlot > $now && !isset($busy[$nextSlot->format('Y-m-d H:i')])) $freeCount++;
                    }
                }
                $available = $freeCount >= $itemCount;
            }
            $slots[] = ['time' => $time, 'available' => $available];
        }
        json_response(['date' => $dateValue, 'requiredEquipment' => $itemCount, 'slots' => $slots]);
    }

    if ($action === 'department-confirm' && $method === 'POST') {
        $token = clean_string($data['token'] ?? '', 120);
        $scheduledAt = parse_maintenance_slot((string) ($data['scheduledAt'] ?? ''));
        if (!$scheduledAt) json_response(['error' => 'Elige un horario futuro disponible dentro de la atención.'], 422);
        db()->beginTransaction();
        try {
            $tokenQuery = db()->prepare('SELECT t.id AS token_id, t.status AS token_status, t.expires_at, r.id AS request_id, r.status AS request_status, r.created_by, p.id AS plan_id, p.plan_year, p.plan_month, p.department FROM maintenance_department_request_tokens t JOIN maintenance_department_requests r ON r.id=t.request_id JOIN maintenance_department_plans p ON p.id=r.plan_id WHERE t.token_hash=? FOR UPDATE');
            $tokenQuery->execute([hash('sha256', $token)]);
            $invite = $tokenQuery->fetch();
            if (!$invite || $invite['token_status'] !== 'sent' || $invite['request_status'] !== 'email_sent' || strtotime((string) $invite['expires_at']) <= time()) {
                db()->rollBack();
                json_response(['error' => 'La solicitud venció, ya fue confirmada o dejó de estar disponible.'], 409);
            }
            if ((int) $scheduledAt->format('Y') !== (int) $invite['plan_year'] || (int) $scheduledAt->format('n') !== (int) $invite['plan_month']) {
                db()->rollBack();
                json_response(['error' => 'El horario debe estar dentro del periodo de mantenimiento de la solicitud.'], 422);
            }
            $requestAssetsQuery = db()->prepare('SELECT ra.asset_id, ra.asset_tag_snapshot, ra.assigned_people_snapshot, a.department, a.status FROM maintenance_department_request_assets ra LEFT JOIN assets a ON a.id=ra.asset_id WHERE ra.token_id=? ORDER BY ra.asset_tag_snapshot FOR UPDATE');
            $requestAssetsQuery->execute([(int) $invite['token_id']]);
            $requestAssets = $requestAssetsQuery->fetchAll();
            if (!$requestAssets) {
                db()->rollBack();
                json_response(['error' => 'La solicitud no incluye equipos vigentes. Contacta al área de TI.'], 409);
            }
            foreach ($requestAssets as $item) {
                if (!$item['asset_id'] || $item['status'] === 'retired' || trim((string) $item['department']) !== trim((string) $invite['department'])) {
                    db()->rollBack();
                    json_response(['error' => 'Cambió el inventario incluido en la solicitud. Contacta al área de TI para actualizarla.'], 409);
                }
            }
            [$periodStart, $periodEnd] = department_plan_period((int) $invite['plan_year'], (int) $invite['plan_month']);
            $monthBusyQuery = db()->prepare('SELECT DATE_FORMAT(reserved_slot,"%Y-%m-%d %H:%i") FROM maintenance WHERE reserved_slot>=? AND reserved_slot<?');
            $monthBusyQuery->execute([$periodStart->format('Y-m-d H:i:s'), $periodEnd->format('Y-m-d H:i:s')]);
            $busy = array_fill_keys($monthBusyQuery->fetchAll(PDO::FETCH_COLUMN), true);
            $candidateKey = $scheduledAt->format('Y-m-d H:i');
            if ($scheduledAt < $periodStart || $scheduledAt >= $periodEnd || isset($busy[$candidateKey])) {
                db()->rollBack();
                json_response(['error' => 'Ese horario acaba de ocuparse o no corresponde al periodo solicitado. Actualiza la disponibilidad.'], 409);
            }
            $slots = [];
            for ($day = new DateTimeImmutable($scheduledAt->format('Y-m-d')); $day < $periodEnd && count($slots) < count($requestAssets); $day = $day->modify('+1 day')) {
                $firstMinute = $day->format('Y-m-d') === $scheduledAt->format('Y-m-d') ? ((int) $scheduledAt->format('H') * 60 + (int) $scheduledAt->format('i')) : 510;
                for ($minute = $firstMinute; $minute < 1020 && count($slots) < count($requestAssets); $minute += 30) {
                    $slot = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $day->format('Y-m-d') . ' ' . sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60));
                    $key = $slot->format('Y-m-d H:i');
                    if ($slot > new DateTimeImmutable('now') && !isset($busy[$key])) {
                        $slots[] = $slot;
                        $busy[$key] = true;
                    }
                }
            }
            if (count($slots) !== count($requestAssets)) {
                db()->rollBack();
                json_response(['error' => 'Ya no hay suficientes horarios consecutivos disponibles durante este periodo. Actualiza disponibilidad e intenta otra hora.'], 409);
            }
            $insertMaintenance = db()->prepare('INSERT INTO maintenance (asset_id,user_id,technician_id,scheduled_at,status,invite_token_hash,notes) VALUES (?,?,?, ?,"confirmed",?,?)');
            $insertService = db()->prepare('INSERT INTO maintenance_department_request_services (request_id,asset_id,maintenance_id) VALUES (?,?,?)');
            foreach ($requestAssets as $index => $item) {
                $serviceNotes = json_encode([
                    '_rame_service' => true,
                    'person_name' => (string) ($item['assigned_people_snapshot'] ?: 'Sin persona asignada en Activos'),
                    'service_notes' => 'Mantenimiento preventivo del departamento ' . $invite['department'] . ' (' . (int) $invite['plan_month'] . '/' . (int) $invite['plan_year'] . ').',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $insertMaintenance->execute([(int) $item['asset_id'], (int) $invite['created_by'], (int) $invite['created_by'], $slots[$index]->format('Y-m-d H:i:s'), hash('sha256', bin2hex(random_bytes(32))), $serviceNotes]);
                $insertService->execute([(int) $invite['request_id'], (int) $item['asset_id'], (int) db()->lastInsertId()]);
            }
            db()->prepare('UPDATE maintenance_department_requests SET status="confirmed", scheduled_start=?, confirmed_at=NOW() WHERE id=? AND status="email_sent"')
                ->execute([$scheduledAt->format('Y-m-d H:i:s'), (int) $invite['request_id']]);
            db()->prepare('UPDATE maintenance_department_request_tokens SET status="confirmed", confirmed_at=NOW() WHERE id=? AND status="sent"')
                ->execute([(int) $invite['token_id']]);
            db()->prepare('UPDATE maintenance_department_request_tokens SET status="revoked" WHERE request_id=? AND id<>? AND status IN ("sent","sending")')
                ->execute([(int) $invite['request_id'], (int) $invite['token_id']]);
            append_department_request_event((int) $invite['request_id'], 'confirmed', count($requestAssets) . ' equipos programados desde ' . $scheduledAt->format('Y-m-d H:i') . '.', (int) $invite['created_by']);
            db()->commit();
            json_response(['ok' => true, 'scheduledCount' => count($requestAssets), 'scheduledStart' => $scheduledAt->format('Y-m-d H:i:s')]);
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            if (is_duplicate_slot_error($error)) json_response(['error' => 'Uno de los horarios acaba de ocuparse. No se registró ningún equipo; consulta de nuevo la disponibilidad.'], 409);
            throw $error;
        }
    }

    $user = require_user();

    if ($action === 'maintenance-department-plan' && $method === 'GET') {
        require_role(['admin', 'technician']);
        $year = (int) ($_GET['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2200) $year = (int) date('Y');
        $departmentRows = db()->query("SELECT COALESCE(NULLIF(TRIM(department),''),'') AS department, COUNT(*) AS total_assets, SUM(status<>'retired') AS active_assets, SUM(status='retired') AS retired_assets FROM assets GROUP BY COALESCE(NULLIF(TRIM(department),''),'') ORDER BY department")->fetchAll();
        $departments = [];
        foreach ($departmentRows as $row) {
            $department = trim((string) $row['department']);
            if ($department === '') continue;
            $details = department_assets($department, true);
            $departments[] = [
                'name' => $department, 'totalAssets' => (int) $row['total_assets'], 'activeAssets' => count($details['assets']),
                'retiredAssets' => (int) $row['retired_assets'], 'eligible' => count($details['assets']) > 0,
                'assets' => $details['assets'], 'emailSuggestions' => $details['emailSuggestions'],
                'unclassified' => in_array(mb_strtoupper($department, 'UTF-8'), ['SIN ASIGNAR', 'SIN DEPARTAMENTO'], true),
            ];
        }
        $unclassifiedQuery = db()->query("SELECT id, asset_tag, type, brand, model, department, status FROM assets WHERE NULLIF(TRIM(department),'') IS NULL OR UPPER(TRIM(department)) IN ('SIN ASIGNAR','SIN DEPARTAMENTO') ORDER BY status, asset_tag");
        $unclassifiedAssets = $unclassifiedQuery->fetchAll();
        $planQuery = db()->prepare('SELECT p.id, p.plan_month, p.department, p.recipient_email, p.is_active, r.id AS request_id, CASE WHEN r.status="email_sent" AND (SELECT MAX(t0.expires_at) FROM maintenance_department_request_tokens t0 WHERE t0.request_id=r.id)<=NOW() THEN "expired" ELSE r.status END AS request_status, r.recipient_email AS request_email, r.scheduled_start, r.confirmed_at, (SELECT COUNT(*) FROM maintenance_department_request_assets ra WHERE ra.token_id=(SELECT MAX(t.id) FROM maintenance_department_request_tokens t WHERE t.request_id=r.id)) AS included_assets, (SELECT COUNT(*) FROM maintenance_department_request_services s JOIN maintenance m ON m.id=s.maintenance_id WHERE s.request_id=r.id AND m.status="completed") AS completed_assets, (SELECT COUNT(*) FROM maintenance_department_request_services s JOIN maintenance m ON m.id=s.maintenance_id WHERE s.request_id=r.id AND m.status IN ("pending","confirmed","reschedule_requested")) AS pending_assets, (SELECT COUNT(*) FROM maintenance_department_request_services s JOIN maintenance m ON m.id=s.maintenance_id WHERE s.request_id=r.id AND m.status="cancelled") AS cancelled_assets, (SELECT COUNT(*) FROM maintenance_department_request_services s JOIN maintenance m ON m.id=s.maintenance_id WHERE s.request_id=r.id AND m.status="reschedule_requested") AS reschedule_requests, (SELECT COUNT(*) FROM maintenance_department_request_history h WHERE h.request_id=r.id AND h.event_type IN ("maintenance_rescheduled","reschedule_requested")) AS reschedule_events FROM maintenance_department_plans p LEFT JOIN maintenance_department_requests r ON r.plan_id=p.id WHERE p.plan_year=? AND p.is_active=1 ORDER BY p.plan_month,p.department');
        $planQuery->execute([$year]);
        $plans = $planQuery->fetchAll();
        foreach ($plans as &$plan) {
            $departmentData = null;
            foreach ($departments as $department) if ($department['name'] === $plan['department']) { $departmentData = $department; break; }
            $activeCount = $departmentData['activeAssets'] ?? 0;
            $plan['included_assets'] = (int) $plan['included_assets'];
            $plan['completed_assets'] = (int) $plan['completed_assets'];
            $plan['pending_assets'] = max(0, $activeCount - (int) $plan['completed_assets']);
            $plan['cancelled_assets'] = (int) $plan['cancelled_assets'];
            $plan['current_assets'] = (int) $activeCount;
            $plan['progress'] = $activeCount > 0 ? (int) floor(min(1, $plan['completed_assets'] / $activeCount) * 100) : 0;
            $plan['reschedule_events'] = (int) $plan['reschedule_events'];
        }
        unset($plan);
        $historyQuery = db()->prepare('SELECT id, plan_year, plan_month, department, action, previous_email, recipient_email, changed_at FROM maintenance_department_plan_history WHERE plan_year=? ORDER BY changed_at DESC,id DESC');
        $historyQuery->execute([$year]);
        $years = array_map('intval', db()->query('SELECT DISTINCT plan_year FROM maintenance_department_plans ORDER BY plan_year DESC')->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array($year, $years, true)) array_unshift($years, $year);
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[] = ['month' => $month, 'departments' => array_values(array_filter($plans, static fn (array $plan): bool => (int) $plan['plan_month'] === $month))];
        }
        $emailReady = !empty($config['smtp']['host']) && !empty($config['smtp']['username']) && !empty($config['smtp']['password']) && !empty($config['smtp']['from_email']) && is_file(__DIR__ . '/../vendor/autoload.php');
        json_response(['year' => $year, 'years' => $years, 'departments' => $departments, 'unclassifiedAssets' => $unclassifiedAssets, 'plans' => $plans, 'months' => $months, 'history' => $historyQuery->fetchAll(), 'emailReady' => $emailReady]);
    }

    if ($action === 'maintenance-department-preview' && $method === 'GET') {
        require_role(['admin']);
        $planId = (int) ($_GET['id'] ?? 0);
        $planQuery = db()->prepare('SELECT p.id,p.plan_year,p.plan_month,p.department,p.recipient_email,r.id AS request_id,r.status AS request_status,r.recipient_email AS request_email,r.scheduled_start,r.confirmed_at,(SELECT t.status FROM maintenance_department_request_tokens t WHERE t.request_id=r.id ORDER BY t.id DESC LIMIT 1) AS latest_token_status,(SELECT t.sent_at FROM maintenance_department_request_tokens t WHERE t.request_id=r.id ORDER BY t.id DESC LIMIT 1) AS latest_sent_at FROM maintenance_department_plans p LEFT JOIN maintenance_department_requests r ON r.plan_id=p.id WHERE p.id=? AND p.is_active=1');
        $planQuery->execute([$planId]);
        $plan = $planQuery->fetch();
        if (!$plan) json_response(['error' => 'El departamento ya no está incluido en el programa.'], 404);
        $details = department_assets((string) $plan['department'], true);
        $mailReady = !empty($config['smtp']['host']) && !empty($config['smtp']['username']) && !empty($config['smtp']['password']) && !empty($config['smtp']['from_email']) && is_file(__DIR__ . '/../vendor/autoload.php');
        json_response(['plan' => $plan, 'assets' => $details['assets'], 'emailSuggestions' => $details['emailSuggestions'], 'emailReady' => $mailReady]);
    }

    if ($action === 'maintenance-department-plan-save' && $method === 'POST') {
        require_role(['admin']);
        $year = (int) ($data['year'] ?? 0);
        $month = (int) ($data['month'] ?? 0);
        $departments = $data['departments'] ?? [];
        if ($year < 2000 || $year > 2200 || $month < 1 || $month > 12 || !is_array($departments) || count($departments) > 50) {
            json_response(['error' => 'Indica un año, mes y departamentos válidos.'], 422);
        }
        $departments = array_values(array_unique(array_filter(array_map(static fn ($value): string => clean_text($value, 140), $departments))));
        db()->beginTransaction();
        try {
            $currentQuery = db()->prepare('SELECT id,department,recipient_email,is_active FROM maintenance_department_plans WHERE plan_year=? AND plan_month=? FOR UPDATE');
            $currentQuery->execute([$year, $month]);
            $currentRows = $currentQuery->fetchAll();
            $currentByDepartment = [];
            foreach ($currentRows as $row) $currentByDepartment[(string) $row['department']] = $row;

            $existingDepartments = array_keys($currentByDepartment);
            $eligibleQuery = db()->query("SELECT DISTINCT TRIM(department) AS department FROM assets WHERE status<>'retired' AND NULLIF(TRIM(department),'') IS NOT NULL AND UPPER(TRIM(department)) NOT IN ('SIN ASIGNAR','SIN DEPARTAMENTO')");
            $eligible = [];
            foreach (array_merge($eligibleQuery->fetchAll(PDO::FETCH_COLUMN), $existingDepartments) as $availableDepartment) {
                $eligible[normalize_person_key((string) $availableDepartment)] = (string) $availableDepartment;
            }
            foreach ($departments as &$department) {
                $key = normalize_person_key($department);
                if (!isset($eligible[$key])) {
                    db()->rollBack();
                    json_response(['error' => 'Solo se pueden programar departamentos registrados en Activos con equipos vigentes. No se aceptan nombres inventados.'], 422);
                }
                $department = $eligible[$key];
            }
            unset($department);
            $departments = array_values(array_unique($departments));

            $wanted = array_fill_keys($departments, true);
            foreach ($currentRows as $row) {
                $department = (string) $row['department'];
                if ((int) $row['is_active'] && !isset($wanted[$department])) {
                    $requestQuery = db()->prepare('SELECT id,status FROM maintenance_department_requests WHERE plan_id=? FOR UPDATE');
                    $requestQuery->execute([(int) $row['id']]);
                    $request = $requestQuery->fetch();
                    if ($request && $request['status'] === 'confirmed') {
                        $activeServices = db()->prepare('SELECT COUNT(*) FROM maintenance_department_request_services s JOIN maintenance m ON m.id=s.maintenance_id WHERE s.request_id=? AND m.status IN ("pending","confirmed","reschedule_requested")');
                        $activeServices->execute([(int) $request['id']]);
                        if ((int) $activeServices->fetchColumn() > 0) {
                            db()->rollBack();
                            json_response(['error' => 'Cancela o termina primero los servicios confirmados de ' . $department . ' antes de retirarlo del mes.'], 409);
                        }
                    }
                    db()->prepare('UPDATE maintenance_department_plans SET is_active=0,updated_by=? WHERE id=?')->execute([(int) $user['id'], (int) $row['id']]);
                    db()->prepare('INSERT INTO maintenance_department_plan_history (plan_id,plan_year,plan_month,department,action,previous_email,recipient_email,changed_by) VALUES (?,?,?,?,"removed",?,?,?)')
                        ->execute([(int) $row['id'], $year, $month, $department, $row['recipient_email'], $row['recipient_email'], (int) $user['id']]);
                    if ($request && $request['status'] === 'email_sent') {
                        db()->prepare('UPDATE maintenance_department_requests SET status="cancelled" WHERE id=?')->execute([(int) $request['id']]);
                        db()->prepare('UPDATE maintenance_department_request_tokens SET status="revoked" WHERE request_id=? AND status IN ("sent","sending")')->execute([(int) $request['id']]);
                        append_department_request_event((int) $request['id'], 'plan_removed', 'Se retiró el departamento del mes antes de confirmar la solicitud.', (int) $user['id']);
                    }
                }
            }

            foreach ($departments as $department) {
                $existing = $currentByDepartment[$department] ?? null;
                if ($existing) {
                    if (!(int) $existing['is_active']) {
                        db()->prepare('UPDATE maintenance_department_plans SET is_active=1,updated_by=? WHERE id=?')->execute([(int) $user['id'], (int) $existing['id']]);
                        db()->prepare('INSERT INTO maintenance_department_plan_history (plan_id,plan_year,plan_month,department,action,previous_email,recipient_email,changed_by) VALUES (?,?,?,?,"added",?,?,?)')
                            ->execute([(int) $existing['id'], $year, $month, $department, $existing['recipient_email'], $existing['recipient_email'], (int) $user['id']]);
                        $request = db()->prepare('SELECT id,status FROM maintenance_department_requests WHERE plan_id=?');
                        $request->execute([(int) $existing['id']]);
                        $requestRow = $request->fetch();
                        if ($requestRow && $requestRow['status'] === 'cancelled') db()->prepare('UPDATE maintenance_department_requests SET status="notification_pending" WHERE id=?')->execute([(int) $requestRow['id']]);
                    }
                    continue;
                }
                db()->prepare('INSERT INTO maintenance_department_plans (plan_year,plan_month,department,created_by,updated_by) VALUES (?,?,?,?,?)')
                    ->execute([$year, $month, $department, (int) $user['id'], (int) $user['id']]);
                $planId = (int) db()->lastInsertId();
                db()->prepare('INSERT INTO maintenance_department_plan_history (plan_id,plan_year,plan_month,department,action,changed_by) VALUES (?,?,?,? ,"added",?)')
                    ->execute([$planId, $year, $month, $department, (int) $user['id']]);
            }
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            throw $error;
        }
        json_response(['ok' => true]);
    }

    if ($action === 'maintenance-department-notify' && $method === 'POST') {
        require_role(['admin']);
        $planId = (int) ($data['planId'] ?? 0);
        $recipient = trim((string) ($data['recipientEmail'] ?? ''));
        $explicitResend = !empty($data['resend']);
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || mb_strlen($recipient) > 190) {
            json_response(['error' => 'Ingresa y revisa el correo real del destinatario antes de enviar.'], 422);
        }
        if (empty($config['smtp']['host']) || empty($config['smtp']['username']) || empty($config['smtp']['password']) || empty($config['smtp']['from_email']) || !is_file(__DIR__ . '/../vendor/autoload.php')) {
            json_response(['error' => 'No se envió el correo: configura Gmail SMTP y PHPMailer en el entorno local.'], 503);
        }
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $mailAssets = [];
        $requestId = 0;
        $tokenId = 0;
        $plan = null;
        db()->beginTransaction();
        try {
            $planQuery = db()->prepare('SELECT id,plan_year,plan_month,department,recipient_email FROM maintenance_department_plans WHERE id=? AND is_active=1 FOR UPDATE');
            $planQuery->execute([$planId]);
            $plan = $planQuery->fetch();
            if (!$plan) {
                db()->rollBack();
                json_response(['error' => 'El departamento ya no está incluido en el programa.'], 404);
            }
            $details = department_assets((string) $plan['department']);
            if (!$details['assets']) {
                db()->rollBack();
                json_response(['error' => 'El departamento ya no tiene equipos vigentes en Activos. Actualiza la planificación.'], 409);
            }
            $requestQuery = db()->prepare('SELECT id,status FROM maintenance_department_requests WHERE plan_id=? FOR UPDATE');
            $requestQuery->execute([$planId]);
            $request = $requestQuery->fetch();
            if ($request && $request['status'] === 'confirmed') {
                db()->rollBack();
                json_response(['error' => 'Esta solicitud ya fue confirmada y programó los equipos.'], 409);
            }
            if ($request) {
                $latestQuery = db()->prepare('SELECT id,status,created_at FROM maintenance_department_request_tokens WHERE request_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
                $latestQuery->execute([(int) $request['id']]);
                $latest = $latestQuery->fetch();
                if ($latest && $latest['status'] === 'sending' && strtotime((string) $latest['created_at']) > time() - 600) {
                    db()->rollBack();
                    json_response(['error' => 'Ya hay un envío en curso para este departamento. Espera su resultado antes de reintentar.'], 409);
                }
                if ($request['status'] === 'email_sent' && !$explicitResend) {
                    db()->rollBack();
                    json_response(['error' => 'La invitación ya se envió. Usa Reenviar para revocar el enlace anterior y emitir uno nuevo.'], 409);
                }
                $requestId = (int) $request['id'];
                db()->prepare('UPDATE maintenance_department_request_tokens SET status="failed",failure_message="El envío anterior no finalizó" WHERE request_id=? AND status="sending" AND created_at<=DATE_SUB(NOW(),INTERVAL 10 MINUTE)')->execute([$requestId]);
                db()->prepare('UPDATE maintenance_department_request_tokens SET status="revoked" WHERE request_id=? AND status="sent"')->execute([$requestId]);
                db()->prepare('UPDATE maintenance_department_requests SET status="notification_pending",recipient_email=? WHERE id=?')->execute([$recipient, $requestId]);
            } else {
                db()->prepare('INSERT INTO maintenance_department_requests (plan_id,status,recipient_email,created_by) VALUES (?,"notification_pending",?,?)')
                    ->execute([$planId, $recipient, (int) $user['id']]);
                $requestId = (int) db()->lastInsertId();
            }
            if (($plan['recipient_email'] ?? '') !== $recipient) {
                db()->prepare('UPDATE maintenance_department_plans SET recipient_email=?,updated_by=? WHERE id=?')->execute([$recipient, (int) $user['id'], $planId]);
                db()->prepare('INSERT INTO maintenance_department_plan_history (plan_id,plan_year,plan_month,department,action,previous_email,recipient_email,changed_by) VALUES (?,?,?, ?,"recipient_updated",?,?,?)')
                    ->execute([$planId, (int) $plan['plan_year'], (int) $plan['plan_month'], $plan['department'], $plan['recipient_email'], $recipient, (int) $user['id']]);
            }
            db()->prepare('INSERT INTO maintenance_department_request_tokens (request_id,token_hash,recipient_email,expires_at,status,created_by) VALUES (?,?,?,?,"sending",?)')
                ->execute([$requestId, $tokenHash, $recipient, (new DateTimeImmutable('+14 days'))->format('Y-m-d H:i:s'), (int) $user['id']]);
            $tokenId = (int) db()->lastInsertId();
            $insertItem = db()->prepare('INSERT INTO maintenance_department_request_assets (request_id,token_id,asset_id,asset_tag_snapshot,assigned_people_snapshot) VALUES (?,?,?,?,?)');
            foreach ($details['assets'] as $asset) {
                $assignedNames = $asset['assigned_people'] ?: ['Sin persona asignada en Activos'];
                $insertItem->execute([$requestId, $tokenId, (int) $asset['id'], $asset['asset_tag'], implode('; ', $assignedNames)]);
                $mailAssets[] = $asset + ['assigned_people' => $assignedNames];
            }
            append_department_request_event($requestId, $explicitResend ? 'notification_resent' : 'notification_prepared', count($mailAssets) . ' equipos; destinatario revisado: ' . $recipient . '.', (int) $user['id']);
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            throw $error;
        }

        $inviteUrl = rtrim((string) $config['app_url'], '/') . '/respond.php?token=' . rawurlencode($rawToken);
        $mailResult = send_department_maintenance_email($plan, $mailAssets, $recipient, $inviteUrl);
        if ($mailResult['sent']) {
            db()->prepare('UPDATE maintenance_department_request_tokens SET status="sent",sent_at=NOW() WHERE id=? AND status="sending"')->execute([$tokenId]);
            db()->prepare('UPDATE maintenance_department_requests SET status="email_sent",recipient_email=? WHERE id=? AND status="notification_pending"')->execute([$recipient, $requestId]);
            append_department_request_event($requestId, 'email_accepted', 'Gmail SMTP aceptó el mensaje para ' . $recipient . '.', (int) $user['id']);
            json_response(['ok' => true, 'emailSent' => true, 'message' => 'Gmail SMTP aceptó el correo. La entrega final depende del servicio de correo del destinatario.']);
        }
        db()->prepare('UPDATE maintenance_department_request_tokens SET status="failed",failure_message=? WHERE id=? AND status="sending"')->execute([$mailResult['message'], $tokenId]);
        db()->prepare('UPDATE maintenance_department_requests SET status="notification_pending" WHERE id=?')->execute([$requestId]);
        append_department_request_event($requestId, 'email_failed', 'No se confirmó el envío por SMTP.', (int) $user['id']);
        json_response(['ok' => true, 'emailSent' => false, 'message' => $mailResult['message']]);
    }

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
        $replacedLetters = [];
        try {
            db()->beginTransaction();
            if ($userId !== null) {
                $existingLetters = db()->prepare('SELECT id, stored_name FROM responsibility_letters WHERE asset_id=? AND (assignee_id=? OR (assignee_id IS NULL AND user_id=?)) FOR UPDATE');
                $existingLetters->execute([$assetId, $assigneeId, $userId]);
            } else {
                $existingLetters = db()->prepare('SELECT id, stored_name FROM responsibility_letters WHERE asset_id=? AND (assignee_id=? OR (assignee_id IS NULL AND user_id IS NULL AND person_name=?)) FOR UPDATE');
                $existingLetters->execute([$assetId, $assigneeId, $personName]);
            }
            $replacedLetters = $existingLetters->fetchAll();
            $statement = db()->prepare('INSERT INTO responsibility_letters (asset_id, user_id, assignee_id, person_name, original_name, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$assetId, $userId, $assigneeId, $personName, $originalName, $storedName, $mimeType, (int) $upload['size'], (int) $user['id']]);
            foreach ($replacedLetters as $oldLetter) {
                db()->prepare('DELETE FROM responsibility_letters WHERE id=?')->execute([(int) $oldLetter['id']]);
            }
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if (is_file($storedPath) && !unlink($storedPath)) {
                error_log('[Rame] No se pudo limpiar un archivo de carta responsiva tras fallar su registro.');
            }
            throw $error;
        }
        $cleanupWarning = false;
        foreach ($replacedLetters as $oldLetter) {
            $oldStoredName = (string) $oldLetter['stored_name'];
            if (!preg_match('/\A[a-f0-9]{32}\.(?:pdf|jpg|png)\z/D', $oldStoredName)) {
                error_log('[Rame] Se encontró un nombre inválido al limpiar una carta responsiva reemplazada.');
                $cleanupWarning = true;
                continue;
            }
            $oldFilePath = $uploadDirectory . '/' . $oldStoredName;
            if (is_file($oldFilePath) && !unlink($oldFilePath)) {
                error_log('[Rame] No fue posible limpiar una carta responsiva reemplazada.');
                $cleanupWarning = true;
            }
        }
        json_response(['ok' => true, 'replaced' => count($replacedLetters), 'cleanupWarning' => $cleanupWarning]);
    }

    if ($action === 'responsibility-letter-delete' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $letterId = (int) ($data['id'] ?? 0);
        if (!$letterId) {
            json_response(['error' => 'Selecciona una carta responsiva válida para eliminar.'], 422);
        }
        $statement = db()->prepare('SELECT l.id, l.stored_name FROM responsibility_letters l JOIN assets a ON a.id=l.asset_id WHERE l.id=? AND a.type="computer"');
        $statement->execute([$letterId]);
        $letter = $statement->fetch();
        if (!$letter) {
            json_response(['error' => 'No se encontró la carta responsiva que quieres eliminar.'], 404);
        }
        db()->beginTransaction();
        try {
            db()->prepare('DELETE FROM responsibility_letters WHERE id=?')->execute([$letterId]);
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $error;
        }
        $storedName = (string) $letter['stored_name'];
        if (!preg_match('/\A[a-f0-9]{32}\.(?:pdf|jpg|png)\z/D', $storedName)) {
            error_log('[Rame] Se encontró un nombre inválido al eliminar una carta responsiva.');
            json_response(['error' => 'Se quitó la carta del inventario, pero no se pudo validar el archivo almacenado. Contacta a administración.'], 500);
        }
        $filePath = dirname(__DIR__) . '/app/uploads/responsibility-letters/' . $storedName;
        if (is_file($filePath) && !unlink($filePath)) {
            error_log('[Rame] No fue posible eliminar el archivo de una carta responsiva.');
            json_response(['error' => 'Se quitó la carta del inventario, pero no se pudo eliminar el archivo del servidor. Contacta a administración.'], 500);
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
            // Consolidate assignments from the normalized table and legacy inventory fields.
            // Older imports stored the employee name in assets.source_assignee_name or
            // asset_assignees.person_name without linking it to users.id.
            $legacyAssignmentStatement = db()->prepare(
                "SELECT a.id AS asset_id, u.id AS user_id, u.name, a.assigned_date
                 FROM assets a JOIN users u
                   ON (a.assigned_user_id = u.id OR LOWER(TRIM(a.source_assignee_name)) = LOWER(TRIM(u.name)))
                 WHERE a.id IN ({$placeholders})
                 UNION
                 SELECT aa.asset_id, u.id AS user_id, u.name, aa.assigned_date
                 FROM asset_assignees aa JOIN users u
                   ON (aa.user_id = u.id OR (aa.user_id IS NULL AND LOWER(TRIM(aa.person_name)) = LOWER(TRIM(u.name))))
                 WHERE aa.asset_id IN ({$placeholders})"
            );
            $legacyAssignmentStatement->execute(array_merge($assetIds, $assetIds));
            foreach ($legacyAssignmentStatement->fetchAll() as $legacyAssignment) {
                $assignmentsByAsset[$legacyAssignment['asset_id']][] = $legacyAssignment;
            }
            $letterStatement = db()->prepare("SELECT asset_id, assignee_id, user_id FROM responsibility_letters WHERE asset_id IN ({$placeholders})");
            $letterStatement->execute($assetIds);
            $lettersByAsset = [];
            foreach ($letterStatement->fetchAll() as $letter) {
                $lettersByAsset[$letter['asset_id']][] = $letter;
            }
            foreach ($assets as &$asset) {
                $assignments = $assignmentsByAsset[$asset['id']] ?? [];
                $assignees = $assigneesByAsset[$asset['id']] ?? [];
                $asset['assigned_user_ids'] = array_values(array_unique(array_map('intval', array_column($assignments, 'user_id'))));
                $asset['assignees'] = array_map(static fn (array $assignee): array => [
                    'id' => (int) $assignee['assignee_id'],
                    'user_id' => $assignee['user_id'] !== null ? (int) $assignee['user_id'] : null,
                    // person_name es el nombre capturado en el inventario; account_name
                    // se conserva aparte para no perderlo si también contiene un nombre.
                    'name' => $assignee['person_name'] ?: $assignee['account_name'],
                    'account_name' => $assignee['account_name'],
                    'department' => $assignee['department'],
                    'assigned_date' => $assignee['assigned_date'],
                ], $assignees);
                // El inventario puede tener personas repartidas entre asset_users,
                // asset_assignees y el campo importado source_assignee_name.
                // Se combinan todas las fuentes para no ocultar nombres completos.
                $rawNames = array_merge(
                    array_column($assignments, 'name'),
                    array_column($asset['assignees'], 'name'),
                    array_column($asset['assignees'], 'account_name'),
                    preg_split('/[\r\n;|]+/', (string)($asset['source_assignee_name'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []
                );
                $allNames = [];
                $seenNames = [];
                foreach ($rawNames as $rawName) {
                    $name = trim((string)$rawName);
                    if ($name === '') continue;
                    $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
                    $key = preg_replace('/\s+/u', ' ', trim($key));
                    if (function_exists('iconv')) {
                        $asciiKey = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
                        if ($asciiKey !== false) $key = strtolower($asciiKey);
                    }
                    if (isset($seenNames[$key])) continue;
                    $seenNames[$key] = true;
                    $allNames[] = $name;
                }
                $asset['assigned_users'] = $allNames;
                $asset['assigned_name'] = implode("\n", $allNames);
                $peopleToCheck = $assignees ?: array_map(
                    static fn (array $assignment): array => ['assignee_id' => null, 'user_id' => $assignment['user_id']],
                    $assignments
                );
                $asset['has_all_responsibility_letters'] = (bool) $peopleToCheck;
                foreach ($peopleToCheck as $person) {
                    $hasLetter = false;
                    foreach ($lettersByAsset[$asset['id']] ?? [] as $letter) {
                        $matchesAssignee = $person['assignee_id'] !== null
                            && $letter['assignee_id'] !== null
                            && (int) $person['assignee_id'] === (int) $letter['assignee_id'];
                        $matchesUser = $person['user_id'] !== null
                            && $letter['user_id'] !== null
                            && (int) $person['user_id'] === (int) $letter['user_id'];
                        if ($matchesAssignee || $matchesUser) {
                            $hasLetter = true;
                            break;
                        }
                    }
                    if (!$hasLetter) {
                        $asset['has_all_responsibility_letters'] = false;
                        break;
                    }
                }
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
        if (count($assignedUserIds) > 5) {
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
        if ($assignedUserIds) {
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

    if ($action === 'maintenance-availability' && $method === 'GET') {
        require_role(['admin', 'technician']);
        $dateValue = clean_string($_GET['date'] ?? '', 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $dateValue) {
            json_response(['error' => 'Selecciona una fecha válida.'], 422);
        }
        $excludeId = max(0, (int) ($_GET['excludeId'] ?? 0));
        $busyQuery = db()->prepare('SELECT DATE_FORMAT(reserved_slot, "%H:%i") FROM maintenance WHERE reserved_slot>=? AND reserved_slot<? AND id<>?');
        $busyQuery->execute([$date->format('Y-m-d 00:00:00'), $date->modify('+1 day')->format('Y-m-d 00:00:00'), $excludeId]);
        $busy = array_fill_keys(array_column($busyQuery->fetchAll(PDO::FETCH_NUM), 0), true);
        $slots = [];
        for ($minute = 510; $minute < 1020; $minute += 30) {
            $time = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
            $slotDate = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $dateValue . ' ' . $time);
            $available = !isset($busy[$time]) && $slotDate && $slotDate->getTimestamp() > time();
            $slots[] = ['time' => $time, 'available' => $available];
        }
        json_response(['date' => $dateValue, 'slots' => $slots]);
    }

    if ($action === 'maintenances' && $method === 'GET') {
        $statement = $user['role'] === 'user'
            ? db()->prepare('SELECT m.id, m.asset_id, m.user_id, m.scheduled_at, m.proposed_at, m.status, m.notes, m.completed_at, a.asset_tag, a.brand, a.model, u.name AS user_name, u.email AS user_email FROM maintenance m JOIN assets a ON a.id=m.asset_id JOIN users u ON u.id=m.user_id WHERE m.user_id=? ORDER BY m.scheduled_at DESC')
            : db()->query('SELECT m.id, m.asset_id, m.user_id, m.scheduled_at, m.proposed_at, m.status, m.notes, m.completed_at, a.asset_tag, a.brand, a.model, u.name AS user_name, u.email AS user_email FROM maintenance m JOIN assets a ON a.id=m.asset_id JOIN users u ON u.id=m.user_id ORDER BY m.scheduled_at DESC');
        if ($user['role'] === 'user') {
            $statement->execute([$user['id']]);
        }
        $maintenances = $statement->fetchAll();
        foreach ($maintenances as &$maintenance) {
            $saved = json_decode((string) ($maintenance['notes'] ?? ''), true);
            if (is_array($saved) && ($saved['_rame_service'] ?? false) === true) {
                $maintenance['user_name'] = (string) ($saved['person_name'] ?? $maintenance['user_name']);
                $maintenance['user_email'] = '';
                $maintenance['service_notes'] = (string) ($saved['service_notes'] ?? '');
            } else {
                $maintenance['service_notes'] = (string) ($maintenance['notes'] ?? '');
            }
        }
        unset($maintenance);
        foreach ($maintenances as &$maintenance) {
            $maintenance['calendar_at'] = $maintenance['status'] === 'reschedule_requested' && $maintenance['proposed_at']
                ? $maintenance['proposed_at']
                : $maintenance['scheduled_at'];
        }
        unset($maintenance);
        $year = (int) ($_GET['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2200) $year = (int) date('Y');
        $progressSql = 'SELECT a.id AS asset_id, a.asset_tag, a.brand, a.model, COUNT(m.id) AS completed_count FROM assets a LEFT JOIN maintenance m ON m.asset_id=a.id AND m.status="completed" AND YEAR(COALESCE(m.completed_at,m.scheduled_at))=? WHERE a.type="computer" AND a.status<>"retired"';
        $progressSql .= $user['role'] === 'user' ? ' AND EXISTS (SELECT 1 FROM asset_users au WHERE au.asset_id=a.id AND au.user_id=?)' : '';
        $progressSql .= ' GROUP BY a.id, a.asset_tag, a.brand, a.model ORDER BY a.asset_tag';
        $progressQuery = db()->prepare($progressSql);
        $progressQuery->execute($user['role'] === 'user' ? [$year, $user['id']] : [$year]);
        $annualProgress = array_map(static function (array $asset): array {
            $asset['completed_count'] = min(2, (int) $asset['completed_count']);
            return $asset;
        }, $progressQuery->fetchAll());
        json_response(['maintenances' => $maintenances, 'annualProgress' => $annualProgress, 'year' => $year]);
    }

    if ($action === 'maintenance-save' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $assetId = (int) ($data['assetId'] ?? 0);
        $personName = clean_text($data['personName'] ?? '', 140);
        $scheduledAt = (string) ($data['scheduledAt'] ?? '');
        if (!$assetId) {
            json_response(['error' => 'Selecciona un equipo para agendar el mantenimiento.'], 422);
        }
        if ($personName === '') {
            json_response(['error' => 'Selecciona una persona asignada al equipo.'], 422);
        }
        $assetQuery = db()->prepare('SELECT id, source_assignee_name FROM assets WHERE id=? AND type="computer" AND status<>"retired"');
        $assetQuery->execute([$assetId]);
        $asset = $assetQuery->fetch();
        if (!$asset) {
            json_response(['error' => 'No se encontró el equipo seleccionado.'], 404);
        }
        $assigneeQuery = db()->query('SELECT person_name FROM asset_assignees UNION SELECT u.name AS person_name FROM asset_users au JOIN users u ON u.id=au.user_id UNION SELECT source_assignee_name AS person_name FROM assets WHERE source_assignee_name IS NOT NULL');
        $assignedNames = [];
        foreach ($assigneeQuery->fetchAll(PDO::FETCH_COLUMN) as $storedName) {
            $assignedNames = array_merge($assignedNames, preg_split('/[\r\n;,|]+/', (string) $storedName, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }
        $normalizeName = static function (string $name): string {
            $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
            if (function_exists('iconv')) {
                $asciiName = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
                if ($asciiName !== false) $name = $asciiName;
            }
            return strtolower($name);
        };
        $hasAssignment = false;
        foreach ($assignedNames as $assignedName) {
            if ($normalizeName((string) $assignedName) === $normalizeName($personName)) {
                $hasAssignment = true;
                break;
            }
        }
        if (!$hasAssignment) {
            json_response(['error' => 'Selecciona una persona registrada en las asignaciones de Activos.'], 422);
        }
        $scheduledDate = parse_maintenance_slot($scheduledAt);
        if (!$scheduledDate) {
            json_response(['error' => 'Elige un horario futuro disponible entre 8:30 a. m. y 5:00 p. m., en intervalos de 30 minutos.'], 422);
        }
        $token = bin2hex(random_bytes(32));
        $scheduledAt = $scheduledDate->format('Y-m-d H:i:s');
        $serviceNotes = clean_text($data['notes'] ?? '', 2000);
        $savedNotes = json_encode([
            '_rame_service' => true,
            'person_name' => $personName,
            'service_notes' => $serviceNotes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        db()->beginTransaction();
        try {
            $assetLock = db()->prepare('SELECT id FROM assets WHERE id=? FOR UPDATE');
            $assetLock->execute([$assetId]);
            if (!$assetLock->fetchColumn()) {
                db()->rollBack();
                json_response(['error' => 'No se encontró el equipo seleccionado.'], 404);
            }
            $stmt = db()->prepare('INSERT INTO maintenance (asset_id, user_id, technician_id, scheduled_at, status, invite_token_hash, notes) VALUES (?, ?, ?, ?, "confirmed", ?, ?)');
            $stmt->execute([$assetId, $user['id'], $user['id'], $scheduledAt, hash('sha256', $token), $savedNotes]);
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if (is_duplicate_slot_error($error)) json_response(['error' => 'Ese horario acaba de ocuparse. Actualiza la disponibilidad y elige otro.'], 409);
            throw $error;
        }
        json_response(['ok' => true, 'message' => 'Servicio guardado en la agenda.']);
    }

    if ($action === 'maintenance-reschedule' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $id = (int) ($data['id'] ?? 0);
        $newDate = parse_maintenance_slot((string) ($data['scheduledAt'] ?? ''));
        if (!$id || !$newDate) {
            json_response(['error' => 'Elige una fecha futura disponible dentro del horario de atención.'], 422);
        }
        db()->beginTransaction();
        try {
            $recordQuery = db()->prepare('SELECT id, asset_id, scheduled_at, status FROM maintenance WHERE id=? FOR UPDATE');
            $recordQuery->execute([$id]);
            $record = $recordQuery->fetch();
            if (!$record || !in_array($record['status'], ['pending', 'confirmed', 'reschedule_requested'], true)) {
                db()->rollBack();
                json_response(['error' => 'Solo se pueden reprogramar servicios pendientes o confirmados.'], 409);
            }
            $oldDate = (string) $record['scheduled_at'];
            $newDateString = $newDate->format('Y-m-d H:i:s');
            db()->prepare('UPDATE maintenance SET scheduled_at=?, proposed_at=NULL, status=CASE WHEN status="reschedule_requested" THEN "confirmed" ELSE status END WHERE id=?')
                ->execute([$newDateString, $id]);
            append_linked_maintenance_event($id, 'maintenance_rescheduled', 'Servicio reprogramado de ' . $oldDate . ' a ' . $newDateString . '.', (int) $user['id']);
            db()->prepare('INSERT INTO asset_history (asset_id, event_type, component, details, occurred_at, created_by) VALUES (?, "other", "Reprogramación de mantenimiento", ?, ?, ?)')
                ->execute([(int) $record['asset_id'], 'Servicio #' . $id . ' reprogramado de ' . $oldDate . ' a ' . $newDateString . '.', $newDate->format('Y-m-d 00:00:00'), $user['id']]);
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            if (is_duplicate_slot_error($error)) json_response(['error' => 'Ese horario acaba de ocuparse. Actualiza la disponibilidad y elige otro.'], 409);
            throw $error;
        }
        json_response(['ok' => true]);
    }

    if ($action === 'maintenance-cancel' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $id = (int) ($data['id'] ?? 0);
        db()->beginTransaction();
        try {
            $recordQuery = db()->prepare('SELECT id, asset_id, scheduled_at, proposed_at, status FROM maintenance WHERE id=? FOR UPDATE');
            $recordQuery->execute([$id]);
            $record = $recordQuery->fetch();
            if (!$record || in_array($record['status'], ['completed', 'cancelled'], true)) {
                db()->rollBack();
                json_response(['error' => 'No se encontró un servicio activo para cancelar.'], 409);
            }
            db()->prepare('UPDATE maintenance SET status="cancelled", proposed_at=NULL WHERE id=?')->execute([$id]);
            append_linked_maintenance_event($id, 'maintenance_cancelled', 'Servicio cancelado; el horario quedó disponible.', (int) $user['id']);
            db()->prepare('INSERT INTO asset_history (asset_id, event_type, component, details, occurred_at, created_by) VALUES (?, "other", "Cancelación de mantenimiento", ?, NOW(), ?)')
                ->execute([(int) $record['asset_id'], 'Se canceló el servicio #' . $id . ' programado para ' . (string) ($record['proposed_at'] ?: $record['scheduled_at']) . '.', $user['id']]);
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            throw $error;
        }
        json_response(['ok' => true]);
    }

    if ($action === 'maintenance-approve' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $id = (int) ($data['id'] ?? 0);
        $statement = db()->prepare('SELECT id, asset_id, user_id, proposed_at FROM maintenance WHERE id=? AND status="reschedule_requested"');
        $statement->execute([$id]);
        $maintenance = $statement->fetch();
        if (!$maintenance || !$maintenance['proposed_at'] || !parse_maintenance_slot(date('Y-m-d H:i', strtotime($maintenance['proposed_at'])))) {
            json_response(['error' => 'La propuesta ya no está disponible o su horario ya pasó.'], 409);
        }
        $token = bin2hex(random_bytes(32));
        db()->beginTransaction();
        try {
            $assetLock = db()->prepare('SELECT id FROM assets WHERE id=? FOR UPDATE');
            $assetLock->execute([(int) $maintenance['asset_id']]);
            if (!$assetLock->fetchColumn()) {
                db()->rollBack();
                json_response(['error' => 'No se encontró el equipo del mantenimiento.'], 404);
            }
            $maintenanceLock = db()->prepare('SELECT scheduled_at, proposed_at FROM maintenance WHERE id=? AND status="reschedule_requested" FOR UPDATE');
            $maintenanceLock->execute([$id]);
            $lockedMaintenance = $maintenanceLock->fetch();
            $currentProposal = $lockedMaintenance['proposed_at'] ?? null;
            if (!$currentProposal || !parse_maintenance_slot(date('Y-m-d H:i', strtotime($currentProposal)))) {
                db()->rollBack();
                json_response(['error' => 'La propuesta ya no está disponible o su horario ya pasó.'], 409);
            }
            db()->prepare('INSERT INTO asset_history (asset_id, event_type, component, details, occurred_at, created_by) VALUES (?, "other", "Reprogramacion de mantenimiento", ?, NOW(), ?)')
                ->execute([(int) $maintenance['asset_id'], 'Servicio #' . $id . ' reprogramado de ' . (string) $lockedMaintenance['scheduled_at'] . ' a ' . (string) $currentProposal . '.', $user['id']]);
            db()->prepare('UPDATE maintenance SET scheduled_at=?, proposed_at=NULL, status="confirmed", invite_token_hash=?, invitation_sent_at=NULL WHERE id=? AND status="reschedule_requested"')
                ->execute([$currentProposal, hash('sha256', $token), $id]);
            append_linked_maintenance_event($id, 'maintenance_rescheduled', 'Propuesta aceptada para ' . (string) $currentProposal . '.', (int) $user['id']);
            db()->commit();
            $maintenance['proposed_at'] = $currentProposal;
        } catch (Throwable $error) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if (is_duplicate_slot_error($error)) json_response(['error' => 'Ese horario acaba de ocuparse. No se pudo aprobar la reprogramación.'], 409);
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
        append_linked_maintenance_event($id, 'maintenance_completed', 'El mantenimiento quedó completado y firmado.', (int) $user['id']);
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
        json_response(['title' => $receipt['title'], 'formType' => $receipt['form_type'], 'formData' => protect_document_form_data(json_decode((string) $receipt['form_data'], true) ?: [], $user), 'asset' => $receipt['asset_tag'] . ' · ' . $receipt['brand'] . ' ' . $receipt['model'], 'user' => $receipt['user_name'], 'signedName' => $receipt['signed_name'], 'signedAt' => $receipt['signed_at'], 'signedIp' => $receipt['signed_ip'], 'signature' => $receipt['signature_data'], 'performedByName' => $receipt['performed_by_name'], 'performedBySignedAt' => $receipt['performed_by_signed_at'], 'performedBySignedIp' => $receipt['performed_by_signed_ip'], 'performedBySignature' => $receipt['performed_by_signature_data']]);
    }

    if ($action === 'documents' && $method === 'GET') {
        $statement = db()->prepare('SELECT d.id, d.title, d.body, d.form_type, d.signed_name, d.signed_at, d.performed_by_name, d.performed_by_signed_at, a.asset_tag, a.brand, a.model, COALESCE(NULLIF(d.signed_name, \'\'), u.name) AS user_name FROM responsibility_documents d JOIN assets a ON a.id=d.asset_id JOIN users u ON u.id=d.user_id WHERE ? IN ("admin", "technician") OR d.user_id=? ORDER BY d.created_at DESC');
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
        json_response(['title' => $receipt['title'], 'body' => $receipt['body'], 'formType' => $receipt['form_type'], 'formData' => protect_document_form_data(json_decode((string) $receipt['form_data'], true) ?: [], $user), 'asset' => $receipt['asset_tag'] . ' · ' . $receipt['brand'] . ' ' . $receipt['model'], 'user' => $receipt['user_name'], 'signedName' => $receipt['signed_name'], 'signedAt' => $receipt['signed_at'], 'signedIp' => $receipt['signed_ip'], 'signature' => $receipt['signature_data'], 'performedByName' => $receipt['performed_by_name'], 'performedBySignedAt' => $receipt['performed_by_signed_at'], 'performedBySignedIp' => $receipt['performed_by_signed_ip'], 'performedBySignature' => $receipt['performed_by_signature_data']]);
    }

    if ($action === 'document-export' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $id = (int) ($data['id'] ?? 0);
        $statement = db()->prepare('SELECT d.user_id, d.title, d.form_type, d.form_data, d.signed_name, d.signed_at, d.signature_data, d.performed_by_name, d.performed_by_signed_at, d.performed_by_signature_data, a.asset_tag, a.brand, a.model, u.name AS user_name FROM responsibility_documents d JOIN assets a ON a.id=d.asset_id JOIN users u ON u.id=d.user_id WHERE d.id=? AND d.signed_at IS NOT NULL');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!$row || $row['form_type'] !== 'service_order' || !$row['performed_by_signed_at']) {
            json_response(['error' => 'No se encontró una Orden de Servicio F-SIS-01 firmada disponible.'], 404);
        }
        $formDataForExport = json_decode((string)$row['form_data'], true) ?: [];
        require_once __DIR__ . '/../app/service_order_docx.php';
        $file = rame_service_order_docx([
            'formData' => json_decode((string)$row['form_data'], true) ?: [],
            'asset' => trim($row['asset_tag'] . ' · ' . $row['brand'] . ' ' . $row['model']),
            'brand' => $row['brand'],
            'model' => $row['model'],
            'department' => (string)($formDataForExport['department'] ?? ''),
            'user' => $row['user_name'],
            'signedName' => $row['signed_name'],
            'signature' => $row['signature_data'],
            'performedByName' => $row['performed_by_name'],
            'performedBySignature' => $row['performed_by_signature_data'],
        ]);
        $downloadName = 'F-SIS-01_Orden_de_Servicio_' . $id . '.docx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        @unlink($file);
        exit;
    }

    if ($action === 'document-save' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $assetId = (int) ($data['assetId'] ?? 0);
        $userId = (int) ($data['userId'] ?? 0);
        $maintenanceId = (int) ($data['maintenanceId'] ?? 0);
        $formType = in_array($data['formType'] ?? '', ['service_order', 'corrective_request'], true) ? $data['formType'] : '';
        // En F-SIS-01 la persona atendida proviene del nombre asignado en ACTIVOS,
        // aunque no exista una cuenta en users. user_id se conserva como propietario
        // interno/auditoría; el nombre real queda en signed_name.
        if ($formType === 'service_order' && $userId <= 0) {
            if ($maintenanceId > 0) {
                $maintenanceOwner = db()->prepare('SELECT user_id FROM maintenance WHERE id=? LIMIT 1');
                $maintenanceOwner->execute([$maintenanceId]);
                $userId = (int) ($maintenanceOwner->fetchColumn() ?: 0);
            }
            if ($userId <= 0) {
                $userId = (int) $user['id'];
            }
        }
        $signedName = clean_text($data['signedName'] ?? '', 140);
        $signature = (string) ($data['signature'] ?? '');
        $performedByName = clean_text($data['performedByName'] ?? '', 140);
        $performedBySignature = (string) ($data['performedBySignature'] ?? '');
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $isSignature = static fn (string $value): bool => strlen($value) <= 800000 && preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/D', $value) === 1 && base64_decode(substr($value, 22), true) !== false;
        if (!$assetId || !$userId || !$formType || $signedName === '' || $performedByName === '' || !$isSignature($signature) || !$isSignature($performedBySignature)) {
            json_response(['error' => 'Completa el formato, el equipo, los nombres y las dos firmas.'], 422);
        }
        // En una Orden de Servicio la persona atendida y el activo intervenido
        // se seleccionan de manera independiente desde el inventario de Activos.
        // No se exige que exista una relación asset_users entre ambos registros.
        $personCheck = db()->prepare('SELECT id FROM users WHERE id=? LIMIT 1');
        $personCheck->execute([$userId]);
        $assetCheck = db()->prepare('SELECT id FROM assets WHERE id=? AND type="computer" AND status<>"retired" LIMIT 1');
        $assetCheck->execute([$assetId]);
        if (!$personCheck->fetchColumn() || !$assetCheck->fetchColumn()) {
            json_response(['error' => 'Selecciona una persona y un equipo vigentes del inventario de Activos.'], 422);
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
            ? ['date', 'department', 'equipmentDetails', 'cleaningExterior', 'cleaningInterior', 'systemCleanup', 'virusReview', 'computerPassword', 'satisfaction', 'comments']
            : ['date', 'failure', 'diagnosis', 'activities', 'closingDate', 'satisfaction', 'responseTime', 'solution', 'comments'];
        $fieldLimits = ['date' => 10, 'department' => 140, 'equipmentDetails' => 1200, 'cleaningExterior' => 20, 'cleaningInterior' => 20, 'systemCleanup' => 20, 'virusReview' => 20, 'computerPassword' => 120, 'satisfaction' => 2, 'comments' => 2000, 'failure' => 2000, 'diagnosis' => 2000, 'activities' => 3000, 'closingDate' => 10, 'responseTime' => 140, 'solution' => 2000];
        $formFields = [];
        foreach ($allowedFields as $field) {
            $value = $fields[$field] ?? '';
            $formFields[$field] = in_array($field, ['department', 'equipmentDetails', 'comments', 'failure', 'diagnosis', 'activities', 'responseTime', 'solution'], true)
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
            if (trim($formFields['equipmentDetails']) === '') {
                json_response(['error' => 'Completa los datos del equipo en el espacio correspondiente del F-SIS-01.'], 422);
            }
            if (trim($formFields['computerPassword']) === '') {
                json_response(['error' => 'La contraseña de computadora es obligatoria.'], 422);
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
                'Datos del equipo: ' . $formFields['equipmentDetails'],
                'Limpieza exterior: ' . $activityLabels[$formFields['cleaningExterior']],
                'Limpieza interior: ' . $activityLabels[$formFields['cleaningInterior']],
                'Depuración de sistema: ' . $activityLabels[$formFields['systemCleanup']],
                'Revisión de virus: ' . $activityLabels[$formFields['virusReview']],
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
                append_linked_maintenance_event($maintenanceId, 'maintenance_completed', 'Mantenimiento completado mediante Orden de Servicio F-SIS-01.', (int) $user['id']);
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

    if ($action === 'document-delete' && $method === 'POST') {
        require_role(['admin', 'technician']);
        $id = (int) ($data['id'] ?? 0);
        $password = (string) ($data['password'] ?? '');
        if (!$id || $password === '') {
            json_response(['error' => 'Ingresa tu contraseña para confirmar la eliminación.'], 422);
        }
        $accountQuery = db()->prepare('SELECT password_hash FROM users WHERE id=? LIMIT 1');
        $accountQuery->execute([$user['id']]);
        $passwordHash = $accountQuery->fetchColumn();
        if (!$passwordHash || !password_verify($password, (string) $passwordHash)) {
            json_response(['error' => 'La contraseña no es correcta.'], 403);
        }
        $documentQuery = db()->prepare('SELECT id, asset_id, maintenance_id, title FROM responsibility_documents WHERE id=? LIMIT 1');
        $documentQuery->execute([$id]);
        $document = $documentQuery->fetch();
        if (!$document) {
            json_response(['error' => 'No se encontró el documento.'], 404);
        }
        db()->beginTransaction();
        try {
            if ($document['maintenance_id']) {
                db()->prepare('UPDATE maintenance SET status="confirmed", completed_at=NULL, signature_data=NULL, signed_name=NULL, signed_ip=NULL WHERE id=? AND status="completed"')->execute([$document['maintenance_id']]);
            }
            db()->prepare('DELETE FROM responsibility_documents WHERE id=?')->execute([$id]);
            db()->prepare('INSERT INTO asset_history (asset_id, event_type, component, details, occurred_at, created_by) VALUES (?, "other", ?, ?, NOW(), ?)')->execute([
                $document['asset_id'],
                'Documento eliminado',
                'Se eliminó el documento #' . $id . ' (' . clean_text($document['title'], 160) . ') tras revalidar la contraseña de la cuenta.',
                $user['id'],
            ]);
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
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
