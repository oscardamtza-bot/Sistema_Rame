<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

const TECHNICIAN_NAMES = ['DULCE MARIA MUÑIZ LOPEZ', 'OSCAR DANIEL MARTINEZ AGUIRRE', 'CESAR ELIUD BERNAL RIVERA'];

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
function department_assets(string $department): array
{
    $statement = db()->prepare('SELECT id, asset_tag, type, brand, model, status, assigned_user_id, source_assignee_name FROM assets WHERE department=? AND status<>"retired" ORDER BY asset_tag');
    $statement->execute([$department]);
    $assets = $statement->fetchAll();
    if (!$assets) return ['assets' => []];

    $assetIds = array_map('intval', array_column($assets, 'id'));
    $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
    $peopleByAsset = [];
    $addPerson = static function (int $assetId, string $name) use (&$peopleByAsset): void {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') return;
        $peopleByAsset[$assetId][normalize_person_key($name)] = $name;
    };

    $assignees = db()->prepare("SELECT asset_id, person_name FROM asset_assignees WHERE asset_id IN ({$placeholders})");
    $assignees->execute($assetIds);
    foreach ($assignees->fetchAll() as $row) $addPerson((int) $row['asset_id'], (string) $row['person_name']);

    $assignedUsers = db()->prepare("SELECT au.asset_id, u.name FROM asset_users au JOIN users u ON u.id=au.user_id WHERE au.asset_id IN ({$placeholders})");
    $assignedUsers->execute($assetIds);
    foreach ($assignedUsers->fetchAll() as $row) $addPerson((int) $row['asset_id'], (string) $row['name']);

    $assignedIds = array_values(array_unique(array_filter(array_map(static fn (array $asset): int => (int) ($asset['assigned_user_id'] ?? 0), $assets))));
    $assignedPeople = [];
    if ($assignedIds) {
        $userPlaceholders = implode(',', array_fill(0, count($assignedIds), '?'));
        $assignedQuery = db()->prepare("SELECT id,name FROM users WHERE id IN ({$userPlaceholders})");
        $assignedQuery->execute($assignedIds);
        foreach ($assignedQuery->fetchAll() as $person) $assignedPeople[(int) $person['id']] = $person;
    }

    foreach ($assets as &$asset) {
        $assetId = (int) $asset['id'];
        if (!empty($asset['assigned_user_id'])) {
            $person = $assignedPeople[(int) $asset['assigned_user_id']] ?? null;
            if ($person) $addPerson($assetId, (string) $person['name']);
        }
        foreach (preg_split('/[\r\n;,|]+/', (string) ($asset['source_assignee_name'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
            $addPerson($assetId, $name);
        }
        $asset['assigned_people'] = array_values($peopleByAsset[$assetId] ?? []);
        unset($asset['assigned_user_id'], $asset['source_assignee_name']);
    }
    unset($asset);

    return ['assets' => $assets];
}

function department_plan_period(int $year, int $month): array
{
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
    return [$start, $start->modify('first day of next month')];
}

function send_maintenance_invitation_email(string $recipientEmail, string $recipientName, array $asset, string $scheduledAt, string $inviteUrl): array
{
    global $config;
    $smtp = $config['smtp'] ?? [];
    $username = trim((string) ($smtp['username'] ?? ''));
    if (empty($smtp['host']) || empty($smtp['from_email']) || ($username !== '' && empty($smtp['password']))) {
        return ['sent' => false, 'message' => 'No se envió el correo: falta completar la configuración SMTP.'];
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        return ['sent' => false, 'message' => 'No se envió el correo: PHPMailer no está disponible.'];
    }
    require_once $autoload;
    $mailerClass = 'PHPMailer\\PHPMailer\\PHPMailer';
    if (!class_exists($mailerClass)) {
        return ['sent' => false, 'message' => 'No se envió el correo: PHPMailer no está disponible.'];
    }

    $date = (new DateTimeImmutable($scheduledAt))->format('d/m/Y H:i');
    $assetLabel = trim((string) $asset['asset_tag'] . ' · ' . (string) $asset['brand'] . ' ' . (string) $asset['model']);
    try {
        $mail = new $mailerClass(true);
        $mail->isSMTP();
        $mail->Host = (string) $smtp['host'];
        $mail->Port = (int) ($smtp['port'] ?? 587);
        $mail->SMTPAuth = $username !== '';
        $mail->Username = $username;
        $mail->Password = (string) ($smtp['password'] ?? '');
        if (($smtp['encryption'] ?? '') === 'tls') {
            $mail->SMTPSecure = constant($mailerClass . '::ENCRYPTION_STARTTLS');
        } elseif (($smtp['encryption'] ?? '') === 'ssl') {
            $mail->SMTPSecure = constant($mailerClass . '::ENCRYPTION_SMTPS');
        }
        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string) $smtp['from_email'], (string) ($smtp['from_name'] ?? 'Rame TI'));
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->isHTML(true);
        $mail->Subject = 'Agenda tu mantenimiento preventivo';
        $mail->Body = '<p>Hola ' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Se programó el mantenimiento de <strong>' . htmlspecialchars($assetLabel, ENT_QUOTES, 'UTF-8') . '</strong> para el ' . $date . '.</p>'
            . '<p><a href="' . htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8') . '">Confirma el horario o propone otro</a></p>';
        $mail->AltBody = "Hola {$recipientName}, se programó el mantenimiento de {$assetLabel} para el {$date}. Confirma el horario o propone otro: {$inviteUrl}";
        if (!$mail->send()) {
            return ['sent' => false, 'message' => 'PHPMailer no confirmó el envío. Comparte la invitación manualmente.'];
        }
        return ['sent' => true, 'message' => 'PHPMailer confirmó el envío del correo.'];
    } catch (Throwable $error) {
        error_log('[Rame mail] SMTP send failed.');
        return ['sent' => false, 'message' => 'PHPMailer no confirmó el envío. Comparte la invitación manualmente.'];
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
        $token = strtolower(trim((string) ($_GET['token'] ?? '')));
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            json_response(['error' => 'El enlace no existe o ya no es válido.'], 404);
        }
        $statement = db()->prepare('SELECT m.id, m.scheduled_at, m.proposed_at, m.status, m.notes, a.asset_tag, a.brand, a.model, u.name AS user_name FROM maintenance m JOIN assets a ON a.id=m.asset_id JOIN users u ON u.id=m.user_id WHERE m.invite_token_hash=? LIMIT 1');
        $statement->execute([hash('sha256', $token)]);
        $invite = $statement->fetch();
        if (!$invite) {
            json_response(['error' => 'El enlace no existe o ya no es válido.'], 404);
        }
        $saved = json_decode((string) ($invite['notes'] ?? ''), true);
        if (is_array($saved) && ($saved['_rame_service'] ?? false) === true) {
            $invite['user_name'] = (string) ($saved['person_name'] ?? $invite['user_name']);
            $invite['notes'] = (string) ($saved['service_notes'] ?? '');
        }
        json_response(['invite' => $invite]);
    }

    if ($action === 'invite-availability' && $method === 'GET') {
        $token = strtolower(trim((string) ($_GET['token'] ?? '')));
        $dateValue = trim((string) ($_GET['date'] ?? ''));
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            json_response(['error' => 'Esta invitación ya no está disponible.'], 404);
        }
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
        $token = strtolower(trim((string) ($data['token'] ?? '')));
        $choice = $data['choice'] ?? '';
        $proposedAt = $data['proposedAt'] ?? null;
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            json_response(['error' => 'Esta invitación ya no está disponible.'], 404);
        }
        $proposedDate = $choice === 'reschedule' && is_string($proposedAt) ? parse_maintenance_slot($proposedAt) : null;
        if ($choice !== 'confirm' && ($choice !== 'reschedule' || !$proposedDate)) {
            json_response(['error' => 'Selecciona una opción y una fecha futura válida.'], 422);
        }
        db()->beginTransaction();
        try {
            $inviteQuery = db()->prepare('SELECT id, status FROM maintenance WHERE invite_token_hash=? FOR UPDATE');
            $inviteQuery->execute([hash('sha256', $token)]);
            $invite = $inviteQuery->fetch();
            if (!$invite || in_array($invite['status'], ['completed', 'cancelled'], true)) {
                db()->rollBack();
                json_response(['error' => 'Esta invitación ya no está disponible.'], 409);
            }
            if ($choice === 'confirm') {
                db()->prepare('UPDATE maintenance SET status="confirmed", proposed_at=NULL WHERE id=?')->execute([$invite['id']]);
            } else {
                db()->prepare('UPDATE maintenance SET status="reschedule_requested", proposed_at=? WHERE id=?')
                    ->execute([$proposedDate->format('Y-m-d H:i:s'), $invite['id']]);
            }
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if (is_duplicate_slot_error($error)) {
                json_response(['error' => 'Ese horario acaba de ocuparse. Elige otro horario disponible.'], 409);
            }
            throw $error;
        }
        json_response(['ok' => true]);
    }

    $user = require_user();

    if (in_array($action, ['maintenance-program', 'maintenance-program-export'], true) && $method === 'GET') {
        require_role(['admin', 'technician']);
        $year = (int) ($_GET['year'] ?? date('Y'));
        if ($year < 2000 || $year > 2200) $year = (int) date('Y');
        $statement = db()->prepare('SELECT seq, model, serial_number, asset_tag, department, monitor_code, planned_months FROM maintenance_program_items WHERE plan_year=? ORDER BY seq, asset_tag');
        $statement->execute([$year]);
        // Un mes cuenta como realizado cuando existe una Orden de Servicio F-SIS-01 firmada o un mantenimiento completado en ese mes.
        $done = [];
        $docs = db()->prepare('SELECT a.asset_tag, MONTH(COALESCE(d.performed_by_signed_at, d.signed_at, d.created_at)) AS m FROM responsibility_documents d JOIN assets a ON a.id=d.asset_id WHERE d.form_type="service_order" AND d.performed_by_signed_at IS NOT NULL AND YEAR(COALESCE(d.performed_by_signed_at, d.signed_at, d.created_at))=?');
        $docs->execute([$year]);
        foreach ($docs->fetchAll() as $row) $done[strtoupper(trim((string) $row['asset_tag']))][(int) $row['m']] = true;
        $completed = db()->prepare('SELECT a.asset_tag, MONTH(m.completed_at) AS m FROM maintenance m JOIN assets a ON a.id=m.asset_id WHERE m.status="completed" AND m.completed_at IS NOT NULL AND YEAR(m.completed_at)=?');
        $completed->execute([$year]);
        foreach ($completed->fetchAll() as $row) $done[strtoupper(trim((string) $row['asset_tag']))][(int) $row['m']] = true;
        $items = [];
        $planned = 0; $realized = 0;
        foreach ($statement->fetchAll() as $row) {
            $months = array_values(array_filter(array_map('intval', explode(',', (string) $row['planned_months']))));
            $doneMonths = array_keys($done[strtoupper((string) $row['asset_tag'])] ?? []);
            sort($doneMonths);
            $planned += count($months);
            $realized += count(array_intersect($months, $doneMonths));
            $items[] = ['seq' => (int) $row['seq'], 'model' => $row['model'], 'serial' => $row['serial_number'], 'tag' => $row['asset_tag'], 'department' => $row['department'], 'monitor' => $row['monitor_code'], 'months' => $months, 'done' => $doneMonths];
        }
        // Si el año no tiene encabezado propio, hereda el del año anterior más reciente.
        $metaStatement = db()->prepare('SELECT revision, issue_date, revision_date, updated_by_name, updated_on FROM maintenance_program_meta WHERE plan_year<=? ORDER BY plan_year DESC LIMIT 1');
        $metaStatement->execute([$year]);
        $meta = $metaStatement->fetch() ?: ['revision' => 'P', 'issue_date' => '25 de Abril del 2023', 'revision_date' => '13 de Febrero de 2026', 'updated_by_name' => '', 'updated_on' => ''];
        if ($action === 'maintenance-program-export') {
            require_once __DIR__ . '/../app/xlsx_program.php';
            $binary = xlsx_build_program($year, $items, $meta);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="D-SIS-01_Programa_Mantenimiento_' . $year . '.xlsx"');
            header('Content-Length: ' . strlen($binary));
            echo $binary;
            exit;
        }
        json_response(['year' => $year, 'items' => $items, 'planned' => $planned, 'realized' => $realized, 'meta' => $meta]);
    }

    if ($action === 'maintenance-program-meta' && $method === 'POST') {
        require_role(['admin']);
        $year = (int) ($data['year'] ?? 0);
        if ($year < 2026 || $year > 2200) json_response(['error' => 'Año inválido.'], 422);
        $field = static fn (string $key, int $max): string => mb_substr(trim((string) ($data[$key] ?? '')), 0, $max);
        $revision = $field('revision', 20);
        if ($revision === '') json_response(['error' => 'La revisión es obligatoria.'], 422);
        db()->prepare('INSERT INTO maintenance_program_meta (plan_year, revision, issue_date, revision_date, updated_by_name, updated_on) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE revision=VALUES(revision), issue_date=VALUES(issue_date), revision_date=VALUES(revision_date), updated_by_name=VALUES(updated_by_name), updated_on=VALUES(updated_on)')
            ->execute([$year, $revision, $field('issue_date', 60), $field('revision_date', 60), $field('updated_by_name', 140), $field('updated_on', 60)]);
        json_response(['ok' => true, 'message' => 'Encabezado del programa ' . $year . ' actualizado.']);
    }

    if ($action === 'maintenance-program-generate' && $method === 'POST') {
        require_role(['admin']);
        $year = (int) ($data['year'] ?? 0);
        if ($year < 2000 || $year > 2200) json_response(['error' => 'Año inválido.'], 422);
        $assets = db()->query("SELECT asset_tag AS tag, TRIM(CONCAT(COALESCE(brand,''),' ',COALESCE(model,''))) AS model, COALESCE(serial_number,'') AS serial, UPPER(TRIM(COALESCE(department,''))) AS department, COALESCE(monitor,'') AS monitor FROM assets WHERE type='computer' AND status<>'retired' ORDER BY asset_tag")->fetchAll();
        if (!$assets) json_response(['error' => 'No hay equipos activos en el inventario.'], 422);
        require_once __DIR__ . '/../app/xlsx_program.php';
        $plan = plan_maintenance_program($assets);
        db()->beginTransaction();
        try {
            db()->prepare('DELETE FROM maintenance_program_items WHERE plan_year=?')->execute([$year]);
            $insert = db()->prepare('INSERT INTO maintenance_program_items (plan_year, seq, model, serial_number, asset_tag, department, monitor_code, planned_months, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($plan as $item) {
                $insert->execute([$year, $item['seq'], mb_substr($item['model'], 0, 140), mb_substr($item['serial'], 0, 120), mb_substr($item['tag'], 0, 60), mb_substr($item['department'], 0, 140), mb_substr($item['monitor'], 0, 60), implode(',', $item['months']), (int) $user['id']]);
            }
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            throw $error;
        }
        json_response(['ok' => true, 'count' => count($plan), 'message' => 'Programa ' . $year . ' generado con ' . count($plan) . ' equipos, agrupados por departamento.']);
    }

    if ($action === 'maintenance-program-import' && $method === 'POST') {
        require_role(['admin']);
        $year = (int) ($_POST['year'] ?? 0);
        if ($year < 2000 || $year > 2200) json_response(['error' => 'Año inválido.'], 422);
        $upload = $_FILES['program'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
            json_response(['error' => 'Selecciona el archivo Excel (.xlsx) del programa.'], 422);
        }
        if ((int) $upload['size'] > 10 * 1024 * 1024) json_response(['error' => 'El archivo supera 10 MB.'], 422);
        require_once __DIR__ . '/../app/xlsx_program.php';
        try {
            $items = xlsx_parse_maintenance_program((string) file_get_contents($upload['tmp_name']));
        } catch (Throwable $error) {
            json_response(['error' => $error instanceof RuntimeException ? $error->getMessage() : 'No se pudo leer el Excel. Verifica que sea .xlsx.'], 422);
        }
        db()->beginTransaction();
        try {
            db()->prepare('DELETE FROM maintenance_program_items WHERE plan_year=?')->execute([$year]);
            $insert = db()->prepare('INSERT INTO maintenance_program_items (plan_year, seq, model, serial_number, asset_tag, department, monitor_code, planned_months, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($items as $item) {
                $insert->execute([$year, $item['seq'], $item['model'], $item['serial'], $item['tag'], $item['department'], $item['monitor'], implode(',', $item['months']), (int) $user['id']]);
            }
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            throw $error;
        }
        json_response(['ok' => true, 'count' => count($items), 'message' => count($items) . ' equipos importados al programa ' . $year . '.']);
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
        $monthNow = (int) date('n');
        $yearNow = (int) date('Y');
        $monthTags = [];
        $programRows = db()->prepare('SELECT asset_tag, planned_months FROM maintenance_program_items WHERE plan_year=?');
        $programRows->execute([$yearNow]);
        foreach ($programRows->fetchAll() as $row) {
            if (in_array($monthNow, array_map('intval', explode(',', (string) $row['planned_months'])), true)) $monthTags[strtoupper(trim((string) $row['asset_tag']))] = false;
        }
        $doneRows = db()->prepare('SELECT a.asset_tag FROM responsibility_documents d JOIN assets a ON a.id=d.asset_id WHERE d.form_type="service_order" AND d.performed_by_signed_at IS NOT NULL AND YEAR(COALESCE(d.performed_by_signed_at, d.signed_at, d.created_at))=? AND MONTH(COALESCE(d.performed_by_signed_at, d.signed_at, d.created_at))=? UNION SELECT a.asset_tag FROM maintenance m JOIN assets a ON a.id=m.asset_id WHERE m.status="completed" AND YEAR(m.completed_at)=? AND MONTH(m.completed_at)=?');
        $doneRows->execute([$yearNow, $monthNow, $yearNow, $monthNow]);
        foreach ($doneRows->fetchAll(PDO::FETCH_COLUMN) as $tag) {
            $key = strtoupper(trim((string) $tag));
            if (array_key_exists($key, $monthTags)) $monthTags[$key] = true;
        }
        $counts['month_planned'] = count($monthTags);
        $counts['month_done'] = count(array_filter($monthTags));
        $counts['month_pending'] = $counts['month_planned'] - $counts['month_done'];
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
        $assigneeNames = [];
        foreach (is_array($data['assigneeNames'] ?? null) ? $data['assigneeNames'] : [] as $rawName) {
            $personName = clean_text($rawName, 140);
            if ($personName !== '' && !in_array($personName, $assigneeNames, true)) $assigneeNames[] = $personName;
        }
        if (count($assigneeNames) > 5) {
            json_response(['error' => 'Un equipo puede tener hasta cinco personas asignadas.'], 422);
        }
        $assignedUserIds = [];
        $assigneeUsers = [];
        $userLookup = db()->prepare('SELECT id, name, department FROM users WHERE UPPER(TRIM(name))=UPPER(?) LIMIT 1');
        foreach ($assigneeNames as $personName) {
            $userLookup->execute([$personName]);
            $matchedUser = $userLookup->fetch();
            $assigneeUsers[$personName] = $matchedUser ?: null;
            if ($matchedUser) $assignedUserIds[] = (int) $matchedUser['id'];
        }
        $assignedDate = null;
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
            'ARTEAGA',
            clean_text($data['position'] ?? '', 140),
            clean_text($data['monitor'] ?? '', 160),
            clean_text($data['mouse'] ?? '', 160),
            clean_text($data['rdpAccess'] ?? '', 190),
            !empty($data['purchaseDate']) ? $data['purchaseDate'] : null,
            $warrantyUntil !== '' ? $warrantyUntil : null,
            clean_text($data['antivirusProduct'] ?? '', 160),
            $antivirusExpiresOn !== '' ? $antivirusExpiresOn : null,
            implode('; ', $assigneeNames),
            clean_text($data['notes'] ?? '', 2000),
        ];
        if ($fields[0] === '' || $fields[2] === '' || $fields[3] === '') {
            json_response(['error' => 'Etiqueta, marca y modelo son obligatorios.'], 422);
        }
        db()->beginTransaction();
        try {
            if ($id) {
                $statement = db()->prepare('UPDATE assets SET asset_tag=?, type=?, brand=?, model=?, serial_number=?, windows_username=?, status=?, phone_format_status=?, phone_category=?, assigned_user_id=?, assigned_date=COALESCE(?, assigned_date), department=?, terminal=?, job_title=?, monitor=?, mouse=?, rdp_access=?, purchase_date=?, warranty_until=?, antivirus_product=?, antivirus_expires_on=?, source_assignee_name=?, notes=?, windows_password=?, windows_password_ciphertext=NULL WHERE id=?');
                $statement->execute([...$fields, $windowsPasswordToSave, $id]);
                $assetId = $id;
            } else {
                $statement = db()->prepare('INSERT INTO assets (asset_tag, type, brand, model, serial_number, windows_username, status, phone_format_status, phone_category, assigned_user_id, assigned_date, department, terminal, job_title, monitor, mouse, rdp_access, purchase_date, warranty_until, antivirus_product, antivirus_expires_on, source_assignee_name, notes, windows_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $statement->execute([...$fields, $windowsPasswordToSave]);
                $assetId = (int) db()->lastInsertId();
            }
            $existingStatement = db()->prepare('SELECT person_name, assigned_date FROM asset_assignees WHERE asset_id=?');
            $existingStatement->execute([$assetId]);
            $existingDates = array_column($existingStatement->fetchAll(), 'assigned_date', 'person_name');
            if ($assigneeNames) {
                $keepPlaceholders = implode(',', array_fill(0, count($assigneeNames), '?'));
                db()->prepare("DELETE FROM asset_assignees WHERE asset_id=? AND person_name NOT IN ({$keepPlaceholders})")->execute([$assetId, ...$assigneeNames]);
            } else {
                db()->prepare('DELETE FROM asset_assignees WHERE asset_id=?')->execute([$assetId]);
            }
            db()->prepare('DELETE FROM asset_users WHERE asset_id=?')->execute([$assetId]);
            $assignmentInsert = db()->prepare('INSERT INTO asset_users (asset_id, user_id, assigned_date) VALUES (?, ?, ?)');
            $assigneeInsert = db()->prepare('INSERT INTO asset_assignees (asset_id, user_id, person_name, department, assigned_date) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)');
            foreach ($assigneeNames as $personName) {
                $matchedUser = $assigneeUsers[$personName] ?? null;
                $personAssignedDate = $existingDates[$personName] ?? date('Y-m-d');
                if ($matchedUser) {
                    $assignmentInsert->execute([$assetId, (int) $matchedUser['id'], $personAssignedDate]);
                }
                $assigneeInsert->execute([$assetId, $matchedUser ? (int) $matchedUser['id'] : null, $personName, $matchedUser['department'] ?? (clean_text($data['department'] ?? '', 140) ?: null), $personAssignedDate]);
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
        $progressSql = 'SELECT a.id AS asset_id, a.asset_tag, a.brand, a.model, d.id AS doc_id, COALESCE(d.performed_by_signed_at, d.signed_at, d.created_at) AS done_at FROM responsibility_documents d JOIN assets a ON a.id=d.asset_id WHERE d.form_type="service_order" AND d.performed_by_signed_at IS NOT NULL AND YEAR(COALESCE(d.performed_by_signed_at, d.signed_at, d.created_at))=?';
        $progressSql .= $user['role'] === 'user' ? ' AND EXISTS (SELECT 1 FROM asset_users au WHERE au.asset_id=a.id AND au.user_id=?)' : '';
        $progressSql .= ' ORDER BY a.asset_tag, done_at, d.id';
        $progressQuery = db()->prepare($progressSql);
        $progressQuery->execute($user['role'] === 'user' ? [$year, $user['id']] : [$year]);
        $byAsset = [];
        foreach ($progressQuery->fetchAll() as $row) {
            $id = (int) $row['asset_id'];
            $byAsset[$id] ??= ['asset_id' => $id, 'asset_tag' => $row['asset_tag'], 'brand' => $row['brand'], 'model' => $row['model'], 'forms' => []];
            $byAsset[$id]['forms'][] = ['id' => (int) $row['doc_id'], 'done_at' => $row['done_at']];
        }
        $annualProgress = array_map(static function (array $asset): array {
            $asset['completed_count'] = min(2, count($asset['forms']));
            return $asset;
        }, array_values($byAsset));
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
        $assetQuery = db()->prepare('SELECT id, asset_tag, brand, model, source_assignee_name FROM assets WHERE id=? AND type="computer" AND status<>"retired"');
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
        $scheduledAt = $scheduledDate->format('Y-m-d H:i:s');
        $serviceNotes = clean_text($data['notes'] ?? '', 2000);
        $savedNotes = json_encode([
            '_rame_service' => true,
            'person_name' => $personName,
            'service_notes' => $serviceNotes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $token = bin2hex(random_bytes(32));
        db()->beginTransaction();
        try {
            $assetLock = db()->prepare('SELECT id FROM assets WHERE id=? FOR UPDATE');
            $assetLock->execute([$assetId]);
            if (!$assetLock->fetchColumn()) {
                db()->rollBack();
                json_response(['error' => 'No se encontró el equipo seleccionado.'], 404);
            }
            $stmt = db()->prepare('INSERT INTO maintenance (asset_id, user_id, technician_id, scheduled_at, status, invite_token_hash, notes) VALUES (?, ?, ?, ?, "pending", ?, ?)');
            $stmt->execute([$assetId, $user['id'], $user['id'], $scheduledAt, hash('sha256', $token), $savedNotes]);
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if (is_duplicate_slot_error($error)) json_response(['error' => 'Ese horario acaba de ocuparse. Actualiza la disponibilidad y elige otro.'], 409);
            throw $error;
        }

        $recipientQuery = db()->prepare('SELECT DISTINCT u.name, u.email FROM users u WHERE u.id IN (SELECT user_id FROM asset_users WHERE asset_id=? UNION SELECT assigned_user_id FROM assets WHERE id=? AND assigned_user_id IS NOT NULL)');
        $recipientQuery->execute([$assetId, $assetId]);
        $recipientEmails = [];
        foreach ($recipientQuery->fetchAll() as $candidate) {
            if (normalize_person_key((string) $candidate['name']) === normalize_person_key($personName)) {
                $email = filter_var($candidate['email'], FILTER_VALIDATE_EMAIL);
                if ($email) $recipientEmails[(string) $email] = ['email' => (string) $email, 'name' => (string) $candidate['name']];
            }
        }

        $inviteUrl = rtrim((string) $config['app_url'], '/') . '/respond.php?token=' . rawurlencode($token);
        $date = (new DateTimeImmutable($scheduledAt))->format('d/m/Y H:i');
        $assetLabel = trim((string) $asset['asset_tag'] . ' · ' . (string) $asset['brand'] . ' ' . (string) $asset['model']);
        $manualInvitation = "Hola {$personName}:\nSe programó el mantenimiento de {$assetLabel} para el {$date}.\nConfirma el horario o propone otro desde este enlace:\n{$inviteUrl}";
        if (count($recipientEmails) === 1) {
            $recipient = reset($recipientEmails);
            $emailResult = send_maintenance_invitation_email($recipient['email'], $recipient['name'], $asset, $scheduledAt, $inviteUrl);
        } else {
            $message = $recipientEmails
                ? 'No se envió el correo: hay más de una cuenta coincidente. Comparte la invitación manualmente.'
                : 'No se envió el correo: la persona no tiene un correo asociado en Activos. Comparte la invitación manualmente.';
            $emailResult = ['sent' => false, 'message' => $message];
        }
        if ($emailResult['sent']) {
            db()->prepare('UPDATE maintenance SET invitation_sent_at=NOW() WHERE invite_token_hash=?')->execute([hash('sha256', $token)]);
        }
        json_response([
            'ok' => true,
            'message' => $emailResult['sent'] ? 'Invitación enviada por correo.' : 'Servicio agendado; comparte la invitación manualmente.',
            'emailSent' => $emailResult['sent'],
            'emailMessage' => $emailResult['message'],
            'manualInvitation' => $manualInvitation,
        ]);
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
            db()->prepare('UPDATE maintenance SET scheduled_at=?, proposed_at=NULL, status="confirmed" WHERE id=? AND status="reschedule_requested"')
                ->execute([$currentProposal, $id]);
            db()->commit();
            $maintenance['proposed_at'] = $currentProposal;
        } catch (Throwable $error) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            if (is_duplicate_slot_error($error)) json_response(['error' => 'Ese horario acaba de ocuparse. No se pudo aprobar la reprogramación.'], 409);
            throw $error;
        }
        json_response(['ok' => true]);
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

    if ($action === 'technician-signatures' && $method === 'GET') {
        require_role(['admin', 'technician']);
        $stored = [];
        foreach (db()->query('SELECT name, signature_data FROM technician_signatures')->fetchAll() as $row) $stored[$row['name']] = $row['signature_data'];
        $list = [];
        foreach (TECHNICIAN_NAMES as $name) $list[] = ['name' => $name, 'signature' => $stored[$name] ?? null];
        json_response(['technicians' => $list]);
    }

    if ($action === 'technician-signature-save' && $method === 'POST') {
        require_role(['admin']);
        $name = clean_text($data['name'] ?? '', 140);
        if (!in_array($name, TECHNICIAN_NAMES, true)) json_response(['error' => 'Selecciona un técnico de la lista.'], 422);
        $signature = (string) ($data['signature'] ?? '');
        if ($signature === '') {
            db()->prepare('DELETE FROM technician_signatures WHERE name=?')->execute([$name]);
            json_response(['ok' => true, 'message' => 'Firma de ' . $name . ' eliminada.']);
        }
        if (strlen($signature) > 800000 || preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/D', $signature) !== 1 || base64_decode(substr($signature, 22), true) === false) {
            json_response(['error' => 'La firma no es válida. Dibújala de nuevo.'], 422);
        }
        db()->prepare('INSERT INTO technician_signatures (name, signature_data, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE signature_data=VALUES(signature_data), updated_by=VALUES(updated_by)')->execute([$name, $signature, (int) $user['id']]);
        json_response(['ok' => true, 'message' => 'Firma de ' . $name . ' guardada.']);
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
        if ($formType !== '' && $userId <= 0) {
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
        if ($performedBySignature === '' && in_array($performedByName, TECHNICIAN_NAMES, true)) {
            $storedSignature = db()->prepare('SELECT signature_data FROM technician_signatures WHERE name=? LIMIT 1');
            $storedSignature->execute([$performedByName]);
            $performedBySignature = (string) ($storedSignature->fetchColumn() ?: '');
        }
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
            json_response(['error' => 'Selecciona un equipo vigente del inventario de Activos.'], 422);
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
            : ['date', 'department', 'equipmentDetails', 'failure', 'diagnosis', 'activities', 'closingDate', 'satisfaction', 'responseTime', 'solution', 'comments'];
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
                'Departamento: ' . ($formFields['department'] ?: 'No especificado'),
                'Equipo: ' . ($formFields['equipmentDetails'] ?: 'No especificado'),
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
