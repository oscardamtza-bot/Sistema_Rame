param(
    [switch] $Apply,
    [string] $WorkbookPath = (Join-Path (Split-Path $PSScriptRoot -Parent) 'acomodo.xlsx'),
    [string] $PhpPath = 'C:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

if (-not (Test-Path $WorkbookPath)) { throw 'No se encontró el libro de inventario.' }
if (-not (Test-Path $PhpPath)) { throw 'No se encontró PHP de XAMPP.' }

$archive = [System.IO.Compression.ZipFile]::OpenRead((Resolve-Path $WorkbookPath))
try {
    function Read-XmlEntry([string] $name) {
        $entry = $archive.GetEntry($name)
        if (-not $entry) { return $null }
        $reader = [System.IO.StreamReader]::new($entry.Open())
        try { [xml] $xml = $reader.ReadToEnd(); return $xml }
        finally { $reader.Dispose() }
    }

    $sharedStrings = [Collections.Generic.List[string]]::new()
    $sharedXml = Read-XmlEntry 'xl/sharedStrings.xml'
    if ($sharedXml) {
        foreach ($item in $sharedXml.SelectNodes("//*[local-name()='si']")) {
            $text = ''
            foreach ($part in $item.SelectNodes(".//*[local-name()='t']")) { $text += $part.InnerText }
            $sharedStrings.Add($text)
        }
    }

    function Read-SheetRows([string] $name) {
        $xml = Read-XmlEntry $name
        if (-not $xml) { throw 'No se pudo leer una hoja requerida del libro.' }
        $rows = [Collections.Generic.List[object]]::new()
        foreach ($row in $xml.SelectNodes("//*[local-name()='sheetData']/*[local-name()='row']")) {
            $values = @{}
            foreach ($cell in $row.SelectNodes("./*[local-name()='c']")) {
                $valueNode = $cell.SelectSingleNode("./*[local-name()='v']")
                if ($cell.t -eq 's' -and $valueNode) {
                    $value = $sharedStrings[[int] $valueNode.InnerText]
                } elseif ($cell.t -eq 'inlineStr') {
                    $value = ($cell.SelectNodes(".//*[local-name()='t']") | ForEach-Object { $_.InnerText }) -join ''
                } elseif ($valueNode) {
                    $value = $valueNode.InnerText
                } else {
                    $value = ''
                }
                $values[($cell.r -replace '\d', '')] = $value
            }
            $rows.Add($values)
        }
        return $rows.ToArray()
    }

    function Normalize-Text([string] $value) {
        $text = $value.Trim()
        if ($text -match '^(?i:SIN ASIGNAR|--|N/?A)$') { return $null }
        return $text
    }

    function Convert-ExcelDate([string] $value) {
        $number = 0.0
        if ([double]::TryParse($value, [Globalization.NumberStyles]::Float, [Globalization.CultureInfo]::InvariantCulture, [ref] $number)) {
            try { return [DateTime]::FromOADate($number).ToString('yyyy-MM-dd') }
            catch { return $null }
        }
        return $null
    }

    $inventorySourceRows = @(Read-SheetRows 'xl/worksheets/sheet1.xml' | Select-Object -Skip 1)
    $licenseRows = @(Read-SheetRows 'xl/worksheets/sheet2.xml' | Select-Object -Skip 1 | Where-Object {
        $_.ContainsKey('A') -and -not [string]::IsNullOrWhiteSpace($_['A'])
    })
    if (-not $inventorySourceRows.Count -or -not $licenseRows.Count) { throw 'No se encontraron registros en las hojas esperadas.' }

    $inventory = [Collections.Generic.List[object]]::new()
    $tagCounts = @{}
    $currentAsset = $null
    foreach ($row in $inventorySourceRows) {
        $tag = Normalize-Text ([string] $row['H'])
        if ($tag) {
            $tag = $tag.ToUpperInvariant()
            $windowsUsername = Normalize-Text $row['E']
            $currentAsset = [ordered]@{
                assetTag = $tag
                duplicateTag = $false
                brand = Normalize-Text $row['B']
                model = Normalize-Text $row['C']
                serialNumber = Normalize-Text $row['D']
                windowsUsername = $windowsUsername
                windowsPassword = ([string] $row['R']).Trim()
                assignedName = $null
                status = if ($row['G'].Trim().ToUpperInvariant() -eq 'BAJA') { 'retired' } elseif ($windowsUsername) { 'assigned' } else { 'available' }
                antivirusProduct = Normalize-Text $row['I']
                antivirusExpiresOn = Convert-ExcelDate $row['J']
                department = Normalize-Text $row['K']
                terminal = Normalize-Text $row['L']
                jobTitle = Normalize-Text $row['N']
                monitor = Normalize-Text $row['O']
                mouse = Normalize-Text $row['P']
                rdpAccess = Normalize-Text $row['Q']
                assignees = @()
            }
            $inventory.Add($currentAsset)
            $tagCounts[$tag] = 1 + [int] $tagCounts[$tag]
        }

        if (-not $currentAsset) { continue }
        $assigneeCell = Normalize-Text ([string] $row['F'])
        if (-not $assigneeCell) { continue }
        $personNames = @($assigneeCell -split '[\r\n]+' | ForEach-Object { Normalize-Text $_ } | Where-Object { $_ })
        foreach ($personName in $personNames) {
            $normalizedName = $personName.ToUpperInvariant()
            if (@($currentAsset['assignees'] | Where-Object { $_.name.ToUpperInvariant() -eq $normalizedName }).Count -gt 0) { continue }
            $personDepartment = Normalize-Text $row['K']
            if (-not $personDepartment) { $personDepartment = $currentAsset['department'] }
            $currentAsset['assignees'] += [ordered]@{ name = $personName; department = $personDepartment }
            if (-not $currentAsset['assignedName']) { $currentAsset['assignedName'] = $personName }
            if ($currentAsset['status'] -ne 'retired') { $currentAsset['status'] = 'assigned' }
        }
    }
    foreach ($item in $inventory) { $item['duplicateTag'] = $tagCounts[$item['assetTag']] -gt 1 }

    $licenseGroups = @{}
    foreach ($row in $licenseRows) {
        $key = $row['B'].Trim()
        if (-not $key) { continue }
        $expiresOn = Convert-ExcelDate $row['C']
        $groupKey = "$key|$expiresOn"
        if (-not $licenseGroups.ContainsKey($groupKey)) {
            $licenseGroups[$groupKey] = [ordered]@{ activationKey = $key; expiresOn = $expiresOn; assignments = @() }
        }
        $serial = ([string] $row['D']).Trim() -replace '^(?i:DELL ST:)\s*', ''
        $licenseGroups[$groupKey].assignments += [ordered]@{
            assetSerial = Normalize-Text $serial
            assignedName = Normalize-Text $row['E']
            department = Normalize-Text $row['F']
            installedAt = Convert-ExcelDate $row['H']
            sourceStatus = Normalize-Text $row['G']
        }
    }
} finally {
    $archive.Dispose()
}

$payload = [ordered]@{ apply = [bool] $Apply; inventory = $inventory; licenses = @($licenseGroups.Values) }
$json = ConvertTo-Json -InputObject $payload -Depth 8 -Compress
$phpCode = @'
require 'app/bootstrap.php';
$payload = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$connection = db();
$normalize = static fn (?string $value): string => function_exists('mb_strtoupper') ? mb_strtoupper(trim((string) $value), 'UTF-8') : strtoupper(trim((string) $value));
$existingRows = $connection->query('SELECT id, asset_tag, serial_number, (windows_password IS NOT NULL OR windows_password_ciphertext IS NOT NULL) AS has_windows_password FROM assets')->fetchAll();
$assetsBySerial = [];
$assetsByTag = [];
foreach ($existingRows as $asset) {
    if ($asset['serial_number']) $assetsBySerial[$normalize($asset['serial_number'])] = $asset;
    $assetsByTag[$normalize($asset['asset_tag'])] = $asset;
}
$usersByName = [];
foreach ($connection->query('SELECT id, name FROM users')->fetchAll() as $person) $usersByName[$normalize($person['name'])] = (int) $person['id'];
$counts = ['inventory_rows' => count($payload['inventory']), 'asset_inserted' => 0, 'asset_updated' => 0, 'duplicate_tag_held' => 0, 'tag_conflict_held' => 0, 'assignee_rows' => 0, 'matched_people' => 0, 'unmatched_people' => 0, 'password_rows' => 0, 'passwords_replacing_existing' => 0, 'license_groups' => 0, 'license_assignments' => 0, 'license_assets_matched' => 0, 'license_serials_unresolved' => 0, 'license_assignments_without_serial' => 0];
$knownSerials = [];
foreach ($payload['inventory'] as $item) if ($item['serialNumber']) $knownSerials[$normalize($item['serialNumber'])] = true;
if ($payload['apply']) $connection->beginTransaction();
try {
    foreach ($payload['inventory'] as $item) {
        $serialKey = $item['serialNumber'] ? $normalize($item['serialNumber']) : null;
        $existing = $serialKey ? ($assetsBySerial[$serialKey] ?? null) : null;
        if (!empty($item['duplicateTag']) && !$existing) { $counts['duplicate_tag_held']++; continue; }
        $tagOwner = $assetsByTag[$normalize($item['assetTag'])] ?? null;
        if ($tagOwner && !$existing && (!$serialKey || $normalize($tagOwner['serial_number']) !== $serialKey)) { $counts['tag_conflict_held']++; continue; }
        $sourceAssignees = $item['assignees'] ?? [];
        if (!$sourceAssignees && !empty($item['assignedName'])) {
            $sourceAssignees[] = ['name' => $item['assignedName'], 'department' => $item['department'] ?? null];
        }
        $resolvedAssignees = [];
        foreach ($sourceAssignees as $sourceAssignee) {
            $assigneeName = clean_text($sourceAssignee['name'] ?? '', 140);
            if ($assigneeName === '') continue;
            $personId = $usersByName[$normalize($assigneeName)] ?? null;
            $resolvedAssignees[] = ['name' => $assigneeName, 'department' => clean_text($sourceAssignee['department'] ?? '', 140), 'userId' => $personId];
            $counts['assignee_rows']++;
            if ($personId) $counts['matched_people']++; else $counts['unmatched_people']++;
        }
        $personId = $resolvedAssignees[0]['userId'] ?? null;
        $windowsPassword = (string) ($item['windowsPassword'] ?? '');
        if ($windowsPassword !== '') {
            $counts['password_rows']++;
            if ($existing && (int) $existing['has_windows_password'] === 1) $counts['passwords_replacing_existing']++;
        }
        if (!$payload['apply']) {
            if ($existing) $counts['asset_updated']++; else $counts['asset_inserted']++;
            continue;
        }
        $brand = clean_text($item['brand'] ?? '', 100);
        $model = clean_text($item['model'] ?? '', 120);
        $serial = clean_text($item['serialNumber'] ?? '', 150);
        $windowsUser = clean_string($item['windowsUsername'] ?? '', 190);
        $department = clean_text($item['department'] ?? '', 140);
        $terminal = clean_text($item['terminal'] ?? '', 190);
        $jobTitle = clean_text($item['jobTitle'] ?? '', 140);
        $monitor = clean_text($item['monitor'] ?? '', 160);
        $mouse = clean_text($item['mouse'] ?? '', 160);
        $rdp = clean_text($item['rdpAccess'] ?? '', 190);
        $antivirus = clean_text($item['antivirusProduct'] ?? '', 160);
        $sourceName = $resolvedAssignees[0]['name'] ?? clean_text($item['assignedName'] ?? '', 140);
        if ($existing) {
            $statement = $connection->prepare('UPDATE assets SET asset_tag=?, type="computer", brand=?, model=?, serial_number=?, windows_username=COALESCE(NULLIF(?, ""), windows_username), windows_password=COALESCE(NULLIF(?, ""), windows_password), windows_password_ciphertext=IF(?="", windows_password_ciphertext, NULL), status=?, department=COALESCE(NULLIF(?, ""), department), terminal=COALESCE(NULLIF(?, ""), terminal), job_title=COALESCE(NULLIF(?, ""), job_title), monitor=COALESCE(NULLIF(?, ""), monitor), mouse=COALESCE(NULLIF(?, ""), mouse), rdp_access=COALESCE(NULLIF(?, ""), rdp_access), antivirus_product=COALESCE(NULLIF(?, ""), antivirus_product), antivirus_expires_on=COALESCE(?, antivirus_expires_on), source_assignee_name=COALESCE(NULLIF(?, ""), source_assignee_name) WHERE id=?');
            $statement->execute([$item['assetTag'], $brand, $model, $serial, $windowsUser, $windowsPassword, $windowsPassword, $item['status'], $department, $terminal, $jobTitle, $monitor, $mouse, $rdp, $antivirus, $item['antivirusExpiresOn'], $sourceName, $existing['id']]);
            $assetId = (int) $existing['id'];
            $counts['asset_updated']++;
        } else {
            $statement = $connection->prepare('INSERT INTO assets (asset_tag, type, brand, model, serial_number, windows_username, windows_password, status, assigned_user_id, department, terminal, job_title, monitor, mouse, rdp_access, antivirus_product, antivirus_expires_on, source_assignee_name) VALUES (?, "computer", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$item['assetTag'], $brand, $model, $serial, $windowsUser, $windowsPassword !== '' ? $windowsPassword : null, $item['status'], $personId, $department, $terminal, $jobTitle, $monitor, $mouse, $rdp, $antivirus, $item['antivirusExpiresOn'], $sourceName]);
            $assetId = (int) $connection->lastInsertId();
            $counts['asset_inserted']++;
        }
        if ($serialKey) $assetsBySerial[$serialKey] = ['id' => $assetId, 'asset_tag' => $item['assetTag'], 'serial_number' => $serial];
        $assetsByTag[$normalize($item['assetTag'])] = ['id' => $assetId, 'asset_tag' => $item['assetTag'], 'serial_number' => $serial];
        foreach ($resolvedAssignees as $assignee) {
            $lookup = $connection->prepare('SELECT id, user_id FROM asset_assignees WHERE asset_id=? AND person_name=?');
            $lookup->execute([$assetId, $assignee['name']]);
            $existingAssignee = $lookup->fetch();
            if ($existingAssignee) {
                $assigneeId = (int) $existingAssignee['id'];
                $connection->prepare('UPDATE asset_assignees SET user_id=COALESCE(user_id, ?), department=COALESCE(NULLIF(?, ""), department) WHERE id=?')->execute([$assignee['userId'], $assignee['department'], $assigneeId]);
            } else {
                $connection->prepare('INSERT INTO asset_assignees (asset_id, user_id, person_name, department) VALUES (?, ?, ?, ?)')->execute([$assetId, $assignee['userId'], $assignee['name'], $assignee['department'] ?: null]);
                $assigneeId = (int) $connection->lastInsertId();
            }
            if ($assignee['userId']) {
                $check = $connection->prepare('SELECT 1 FROM asset_users WHERE asset_id=? AND user_id=?');
                $check->execute([$assetId, $assignee['userId']]);
                if (!$check->fetchColumn()) {
                    $count = $connection->prepare('SELECT COUNT(*) FROM asset_users WHERE asset_id=?');
                    $count->execute([$assetId]);
                    if ((int) $count->fetchColumn() < 5) {
                        $connection->prepare('INSERT INTO asset_users (asset_id, user_id, assigned_date) VALUES (?, ?, CURDATE())')->execute([$assetId, $assignee['userId']]);
                        $connection->prepare('UPDATE assets SET assigned_user_id=?, assigned_date=COALESCE(assigned_date, CURDATE()) WHERE id=?')->execute([$assignee['userId'], $assetId]);
                    }
                }
            }
        }
    }
    foreach ($payload['licenses'] as $license) {
        $licenseId = null;
        $find = $connection->prepare('SELECT id, license_key_ciphertext FROM licenses WHERE product="ESET" AND vendor="ESET" AND expires_on <=> ?');
        $find->execute([$license['expiresOn']]);
        foreach ($find->fetchAll() as $candidate) {
            if ($candidate['license_key_ciphertext'] && hash_equals(decrypt_secret($candidate['license_key_ciphertext']), $license['activationKey'])) { $licenseId = (int) $candidate['id']; break; }
        }
        $assignmentTotal = count($license['assignments']);
        if (!$payload['apply']) {
            $counts['license_groups']++;
            $counts['license_assignments'] += $assignmentTotal;
            foreach ($license['assignments'] as $assignment) {
                if (!$assignment['assetSerial']) $counts['license_assignments_without_serial']++;
                elseif (isset($knownSerials[$normalize($assignment['assetSerial'])])) $counts['license_assets_matched']++;
                else $counts['license_serials_unresolved']++;
            }
            continue;
        }
        if ($licenseId) {
            $connection->prepare('UPDATE licenses SET seats=?, seats_confirmed=0, activations=?, status="active" WHERE id=?')->execute([max(1, $assignmentTotal), $assignmentTotal, $licenseId]);
            $connection->prepare('DELETE FROM license_assignments WHERE license_id=?')->execute([$licenseId]);
        } else {
            $connection->prepare('INSERT INTO licenses (product, vendor, license_key_ciphertext, seats, seats_confirmed, activations, expires_on, status, notes) VALUES ("ESET", "ESET", ?, ?, 0, ?, ?, "active", "Importado desde Excel; capacidad contratada por confirmar.")')->execute([encrypt_secret($license['activationKey']), max(1, $assignmentTotal), $assignmentTotal, $license['expiresOn']]);
            $licenseId = (int) $connection->lastInsertId();
        }
        $counts['license_groups']++;
        $insertAssignment = $connection->prepare('INSERT INTO license_assignments (license_id, asset_id, asset_serial_snapshot, assigned_name, department, installed_at, source_status) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($license['assignments'] as $assignment) {
            $serialKey = $assignment['assetSerial'] ? $normalize($assignment['assetSerial']) : null;
            $asset = $serialKey ? ($assetsBySerial[$serialKey] ?? null) : null;
            if (!$serialKey) $counts['license_assignments_without_serial']++;
            elseif ($asset) $counts['license_assets_matched']++;
            else $counts['license_serials_unresolved']++;
            $insertAssignment->execute([$licenseId, $asset ? $asset['id'] : null, clean_string($assignment['assetSerial'] ?? '', 150) ?: null, clean_text($assignment['assignedName'] ?? '', 140) ?: null, clean_text($assignment['department'] ?? '', 140) ?: null, $assignment['installedAt'], clean_text($assignment['sourceStatus'] ?? '', 40) ?: null]);
            $counts['license_assignments']++;
        }
    }
    if ($payload['apply']) $connection->commit();
} catch (Throwable $error) {
    if ($payload['apply'] && $connection->inTransaction()) $connection->rollBack();
    throw $error;
}
echo json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
'@

$encodedCode = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($phpCode))
$process = [Diagnostics.Process]::new()
$process.StartInfo.FileName = $PhpPath
$process.StartInfo.WorkingDirectory = (Split-Path $PSScriptRoot -Parent)
$process.StartInfo.Arguments = "-r `"eval(base64_decode('$encodedCode'));`""
$process.StartInfo.UseShellExecute = $false
$process.StartInfo.RedirectStandardInput = $true
$process.StartInfo.RedirectStandardOutput = $true
$process.StartInfo.RedirectStandardError = $true
[void] $process.Start()
$inputBytes = [Text.Encoding]::UTF8.GetBytes($json)
$process.StandardInput.BaseStream.Write($inputBytes, 0, $inputBytes.Length)
$process.StandardInput.Close()
$output = $process.StandardOutput.ReadToEnd()
$processError = $process.StandardError.ReadToEnd()
$process.WaitForExit()
if ($process.ExitCode -ne 0) { throw 'No se pudo completar la importación; la transacción fue cancelada.' }
Write-Output $output