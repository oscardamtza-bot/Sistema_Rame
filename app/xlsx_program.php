<?php
declare(strict_types=1);

// Lector mínimo de .xlsx con zlib (no requiere la extensión zip).
function xlsx_read_entries(string $binary): array
{
    $end = strrpos($binary, "PK\x05\x06");
    if ($end === false) throw new RuntimeException('El archivo no es un Excel (.xlsx) válido.');
    $eocd = unpack('vdisk/vcdDisk/ventriesDisk/ventries/Vsize/Voffset', substr($binary, $end + 4, 16));
    $pos = $eocd['offset'];
    $entries = [];
    for ($i = 0; $i < $eocd['entries']; $i++) {
        if (substr($binary, $pos, 4) !== "PK\x01\x02") break;
        $h = unpack('vmadeBy/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/VlocalOffset', substr($binary, $pos + 4, 42));
        $name = substr($binary, $pos + 46, $h['nameLen']);
        $entries[$name] = $h;
        $pos += 46 + $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
    }
    return $entries;
}

function xlsx_entry(string $binary, array $entries, string $name): ?string
{
    if (!isset($entries[$name])) return null;
    $e = $entries[$name];
    $local = unpack('vnameLen/vextraLen', substr($binary, $e['localOffset'] + 26, 4));
    $start = $e['localOffset'] + 30 + $local['nameLen'] + $local['extraLen'];
    $raw = substr($binary, $start, $e['csize']);
    if ($e['method'] === 0) return $raw;
    $out = @gzinflate($raw);
    return $out === false ? null : $out;
}

function xlsx_col_index(string $ref): int
{
    preg_match('/^([A-Z]+)/', $ref, $m);
    $n = 0;
    foreach (str_split($m[1] ?? 'A') as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n;
}

function xlsx_text(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

/** Devuelve [fila => [columna => texto]] de la hoja que contiene "MODELO DEL EQUIPO". */
function xlsx_find_program_sheet(string $binary): array
{
    $entries = xlsx_read_entries($binary);
    $shared = [];
    $sharedXml = xlsx_entry($binary, $entries, 'xl/sharedStrings.xml');
    if ($sharedXml !== null) {
        $xml = simplexml_load_string($sharedXml);
        foreach ($xml->si as $si) {
            $text = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $t) $text .= (string) $t;
            $shared[] = $text;
        }
    }
    foreach ($entries as $name => $_) {
        if (!preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) continue;
        $sheetXml = xlsx_entry($binary, $entries, $name);
        if ($sheetXml === null || stripos($sheetXml, '<sheetData') === false) continue;
        $xml = simplexml_load_string($sheetXml);
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $r = (int) $row['r'];
            foreach ($row->c as $c) {
                $type = (string) $c['t'];
                $v = isset($c->v) ? (string) $c->v : '';
                if ($type === 's') $v = $shared[(int) $v] ?? '';
                elseif ($type === 'inlineStr') $v = (string) $c->is->t;
                $v = xlsx_text($v);
                if ($v !== '') $rows[$r][xlsx_col_index((string) $c['r'])] = $v;
            }
        }
        foreach ($rows as $cells) {
            foreach ($cells as $v) {
                if (mb_strtoupper($v, 'UTF-8') === 'MODELO DEL EQUIPO') return $rows;
            }
        }
    }
    throw new RuntimeException('No se encontró la hoja del programa (columna "MODELO DEL EQUIPO").');
}

/** @return list<array{seq:int,model:string,serial:string,tag:string,department:string,monitor:string,months:list<int>}> */
function xlsx_parse_maintenance_program(string $binary): array
{
    $rows = xlsx_find_program_sheet($binary);
    $headerRow = null;
    $cols = [];
    foreach ($rows as $r => $cells) {
        foreach ($cells as $c => $v) {
            $u = mb_strtoupper($v, 'UTF-8');
            if ($u === 'MODELO DEL EQUIPO') { $headerRow = $r; $cols['model'] = $c; }
            if ($headerRow === $r) {
                if (str_contains($u, 'SERIE')) $cols['serial'] = $c;
                if (str_contains($u, 'IDENTIFICACI')) $cols['tag'] = $c;
                if ($u === 'DEPARTAMENTO') $cols['department'] = $c;
                if ($u === 'MONITOR') $cols['monitor'] = $c;
            }
        }
        if ($headerRow !== null) break;
    }
    foreach (['model', 'serial', 'tag', 'department'] as $key) {
        if (!isset($cols[$key])) throw new RuntimeException('Faltan encabezados del programa (serie, identificación o departamento).');
    }
    $seqCol = max(1, $cols['model'] - 1);
    $monitorCol = $cols['monitor'] ?? null;

    // La fila de meses contiene E F M A M J J A S O N D en 12 columnas consecutivas.
    $letters = ['E', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];
    $monthStart = null; $monthRow = null;
    foreach ($rows as $r => $cells) {
        if ($r < $headerRow) continue;
        foreach (array_keys($cells) as $start) {
            $ok = true;
            foreach ($letters as $i => $letter) {
                if (mb_strtoupper($cells[$start + $i] ?? '', 'UTF-8') !== $letter) { $ok = false; break; }
            }
            if ($ok) { $monthStart = $start; $monthRow = $r; break 2; }
        }
        if ($r > $headerRow + 6) break;
    }
    if ($monthStart === null) throw new RuntimeException('No se encontró la fila de meses (E F M A M J J A S O N D).');

    $items = [];
    foreach ($rows as $r => $cells) {
        if ($r <= $monthRow) continue;
        $tag = strtoupper(str_replace(' ', '', $cells[$cols['tag']] ?? ''));
        if ($tag === '' || !preg_match('/^[A-Z0-9][A-Z0-9\-_.\/]{2,}$/', $tag)) continue;
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            if (mb_strtoupper($cells[$monthStart + $i] ?? '', 'UTF-8') === 'X') $months[] = $i + 1;
        }
        if (isset($items[$tag])) {
            $items[$tag]['months'] = array_values(array_unique(array_merge($items[$tag]['months'], $months)));
            sort($items[$tag]['months']);
            continue;
        }
        $items[$tag] = [
            'seq' => (int) ($cells[$seqCol] ?? 0),
            'model' => mb_substr($cells[$cols['model']] ?? '', 0, 140),
            'serial' => mb_substr($cells[$cols['serial']] ?? '', 0, 120),
            'tag' => mb_substr($tag, 0, 60),
            'department' => mb_substr(mb_strtoupper($cells[$cols['department']] ?? '', 'UTF-8'), 0, 140),
            'monitor' => $monitorCol ? mb_substr($cells[$monitorCol] ?? '', 0, 60) : '',
            'months' => $months,
        ];
    }
    if (!$items) throw new RuntimeException('El Excel no contiene equipos con número de identificación.');
    return array_values($items);
}

/**
 * Reparte equipos en 6 pares de meses (m y m+6) agrupados por departamento y balanceando la carga.
 * @param list<array{tag:string,model:string,serial:string,department:string,monitor:string}> $assets
 * @return list<array{seq:int,model:string,serial:string,tag:string,department:string,monitor:string,months:list<int>}>
 */
function plan_maintenance_program(array $assets): array
{
    $byDepartment = [];
    foreach ($assets as $asset) {
        $byDepartment[$asset['department'] !== '' ? $asset['department'] : 'SIN DEPARTAMENTO'][] = $asset;
    }
    $total = count($assets);
    $chunkSize = max(1, (int) ceil($total / 6));
    $chunks = [];
    foreach ($byDepartment as $department => $list) {
        usort($list, static fn ($a, $b) => strnatcasecmp($a['tag'], $b['tag']));
        // Un departamento muy grande se divide para no saturar un solo mes.
        $parts = count($list) > $chunkSize * 1.5 ? array_chunk($list, $chunkSize) : [$list];
        foreach ($parts as $part) $chunks[] = $part;
    }
    usort($chunks, static fn ($a, $b) => count($b) <=> count($a));
    $load = array_fill(1, 6, 0);
    $plan = [];
    foreach ($chunks as $chunk) {
        asort($load);
        $month = (int) array_key_first($load);
        $load[$month] += count($chunk);
        foreach ($chunk as $asset) $plan[] = $asset + ['months' => [$month, $month + 6]];
    }
    usort($plan, static function ($a, $b) {
        return [$a['months'][0], $a['department']] <=> [$b['months'][0], $b['department']] ?: strnatcasecmp($a['tag'], $b['tag']);
    });
    foreach ($plan as $i => &$item) $item['seq'] = $i + 1;
    unset($item);
    return $plan;
}

function xlsx_zip(array $files): string
{
    $out = ''; $central = ''; $count = 0;
    foreach ($files as $name => $data) {
        $crc = crc32($data); $len = strlen($data); $nameLen = strlen($name);
        $offset = strlen($out);
        $out .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, 0, 0, 22561, $crc, $len, $len, $nameLen, 0) . $name . $data;
        $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 0, 0, 22561, $crc, $len, $len, $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
        $count++;
    }
    return $out . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), strlen($out), 0);
}

function xlsx_col_name(int $index): string
{
    $name = '';
    while ($index > 0) { $name = chr(65 + (($index - 1) % 26)) . $name; $index = intdiv($index - 1, 26); }
    return $name;
}

require_once __DIR__ . '/xlsx_build.php';
