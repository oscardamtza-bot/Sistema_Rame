<?php
declare(strict_types=1);

/** Genera el .xlsx del programa con el encabezado y formato del D-SIS-01. */
function xlsx_build_program(int $year, array $items, array $meta = []): string
{
    $S_TITLE = 1; $S_GRAY = 2; $S_BLUE = 3; $S_CELL = 4; $S_DONE = 5; $S_LABEL = 6; $S_BOX = 7; $S_BIG = 8;
    $cells = []; $merges = [];
    $merge = function (int $r1, int $c1, int $r2, int $c2, string $value, int $style) use (&$cells, &$merges): void {
        for ($r = $r1; $r <= $r2; $r++) {
            for ($c = $c1; $c <= $c2; $c++) $cells[$r][$c] = [($r === $r1 && $c === $c1) ? $value : '', $style];
        }
        if ($r1 !== $r2 || $c1 !== $c2) $merges[] = xlsx_col_name($c1) . $r1 . ':' . xlsx_col_name($c2) . $r2;
    };

    // Encabezado del documento (columnas B a T).
    $merge(2, 2, 9, 5, '', $S_BOX);
    $merge(2, 6, 5, 8, 'DOCUMENTOS', $S_BIG);
    $merge(6, 6, 9, 8, 'PROGRAMA DE MANTENIMIENTO DE HARDWARE Y SOFTWARE', $S_TITLE);
    $merge(2, 9, 3, 14, 'Codigo', $S_GRAY);
    $merge(2, 15, 3, 20, 'Fecha de Emision', $S_GRAY);
    $merge(4, 9, 5, 14, 'D-SIS-01', $S_TITLE);
    $merge(4, 15, 5, 20, (string) ($meta['issue_date'] ?? '25 de Abril del 2023'), $S_TITLE);
    $merge(6, 9, 7, 14, 'Revision', $S_GRAY);
    $merge(6, 15, 7, 20, 'Fecha de Revision', $S_GRAY);
    $merge(8, 9, 9, 14, (string) ($meta['revision'] ?? 'P'), $S_TITLE);
    $merge(8, 15, 9, 20, (string) ($meta['revision_date'] ?? ''), $S_TITLE);
    $merge(11, 2, 11, 5, 'Responsable de actualización:', $S_LABEL);
    $merge(11, 6, 11, 8, (string) ($meta['updated_by_name'] ?? ''), $S_TITLE);
    $merge(11, 9, 11, 14, 'Fecha de actualización:', $S_LABEL);
    $merge(11, 15, 11, 20, (string) ($meta['updated_on'] ?? ''), $S_TITLE);

    // Encabezado de la tabla.
    foreach ([2 => 'N°', 3 => 'MODELO DEL EQUIPO', 4 => 'NÚMERO DE SERIE (ST)', 5 => 'N° DE IDENTIFICACIÓN', 6 => 'DEPARTAMENTO', 7 => 'MONITOR', 8 => 'PROGRAMADO REALIZADO'] as $col => $label) {
        $merge(13, $col, 14, $col, $label, $S_BLUE);
    }
    $merge(13, 9, 13, 20, 'MES', $S_BLUE);
    foreach (['E', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'] as $i => $letter) $cells[14][9 + $i] = [$letter, $S_BLUE];

    $r = 15;
    foreach ($items as $item) {
        foreach ([2 => (string) $item['seq'], 3 => (string) $item['model'], 4 => (string) $item['serial'], 5 => (string) $item['tag'], 6 => (string) $item['department'], 7 => (string) ($item['monitor'] ?? '')] as $col => $value) {
            $merge($r, $col, $r + 1, $col, $value, $S_CELL);
        }
        $cells[$r][8] = ['P', $S_CELL];
        $cells[$r + 1][8] = ['R', $S_CELL];
        for ($m = 1; $m <= 12; $m++) {
            $cells[$r][8 + $m] = [in_array($m, $item['months'], true) ? 'X' : '', $S_CELL];
            $isDone = in_array($m, $item['done'] ?? [], true);
            $cells[$r + 1][8 + $m] = [$isDone ? '✓' : '', $isDone ? $S_DONE : $S_CELL];
        }
        $r += 2;
    }

    ksort($cells);
    $sheetData = '';
    foreach ($cells as $rowNumber => $row) {
        ksort($row);
        $height = $rowNumber >= 2 && $rowNumber <= 9 ? 20 : ($rowNumber === 13 ? 24 : 15);
        $sheetData .= '<row r="' . $rowNumber . '" ht="' . $height . '" customHeight="1">';
        foreach ($row as $col => [$value, $style]) {
            $ref = xlsx_col_name($col) . $rowNumber;
            $sheetData .= $value === ''
                ? '<c r="' . $ref . '" s="' . $style . '"/>'
                : '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
        }
        $sheetData .= '</row>';
    }
    $mergeXml = '<mergeCells count="' . count($merges) . '">' . implode('', array_map(static fn ($m) => '<mergeCell ref="' . $m . '"/>', $merges)) . '</mergeCells>';

    $logoPath = __DIR__ . '/../public/assets/fsis01-logo-original.png';
    $logo = is_file($logoPath) ? (string) file_get_contents($logoPath) : '';
    $drawingTag = $logo !== '' ? '<drawing r:id="rId1"/>' : '';
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols><col min="1" max="1" width="2" customWidth="1"/><col min="2" max="2" width="5" customWidth="1"/><col min="3" max="3" width="24" customWidth="1"/><col min="4" max="4" width="14" customWidth="1"/><col min="5" max="5" width="16" customWidth="1"/><col min="6" max="6" width="28" customWidth="1"/><col min="7" max="7" width="12" customWidth="1"/><col min="8" max="8" width="11" customWidth="1"/><col min="9" max="20" width="4.6" customWidth="1"/></cols><sheetData>'
        . $sheetData . '</sheetData>' . $mergeXml . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>' . $drawingTag . '</worksheet>';

    $font = static fn (string $extra, int $size = 10) => '<font>' . $extra . '<sz val="' . $size . '"/><name val="Arial"/></font>';
    $center = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
    $xf = static fn (int $font, int $fill, int $border, string $align) => '<xf numFmtId="0" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">' . $align . '</xf>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4">'
        . $font('') . $font('<b/>') . $font('<b/><color rgb="FFFFFFFF"/>') . $font('<b/>', 12)
        . '</fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF00E64D"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right><top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="9">'
        . $xf(0, 0, 0, '') . $xf(1, 0, 1, $center) . $xf(1, 2, 1, $center) . $xf(2, 3, 1, $center) . $xf(0, 0, 1, $center) . $xf(1, 4, 1, $center)
        . $xf(1, 0, 0, '<alignment horizontal="right" vertical="center"/>') . $xf(0, 0, 1, '') . $xf(3, 0, 1, $center)
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $ns = 'http://schemas.openxmlformats.org/';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $ns . 'officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="' . $ns . 'spreadsheetml/2006/main" xmlns:r="' . $ns . 'officeDocument/2006/relationships"><sheets><sheet name="D-SIS-01_" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $ns . 'officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="' . $ns . 'officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    if ($logo !== '') {
        [$w, $h] = getimagesizefromstring($logo) ?: [879, 361];
        $heightPx = 150; $widthPx = (int) round($heightPx * $w / $h);
        $emu = 9525;
        $files['xl/worksheets/_rels/sheet1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $ns . 'officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>';
        $files['xl/drawings/_rels/drawing1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $ns . 'officeDocument/2006/relationships/image" Target="../media/logo.png"/></Relationships>';
        $files['xl/drawings/drawing1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="' . $ns . 'drawingml/2006/spreadsheetDrawing" xmlns:a="' . $ns . 'drawingml/2006/main"><xdr:oneCellAnchor><xdr:from><xdr:col>1</xdr:col><xdr:colOff>' . (40 * $emu) . '</xdr:colOff><xdr:row>1</xdr:row><xdr:rowOff>' . (30 * $emu) . '</xdr:rowOff></xdr:from><xdr:ext cx="' . ($widthPx * $emu) . '" cy="' . ($heightPx * $emu) . '"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Logo"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip xmlns:r="' . $ns . 'officeDocument/2006/relationships" r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . ($widthPx * $emu) . '" cy="' . ($heightPx * $emu) . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';
        $files['xl/media/logo.png'] = $logo;
    }
    return xlsx_zip($files);
}
