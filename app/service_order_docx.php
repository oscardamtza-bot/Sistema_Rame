<?php
declare(strict_types=1);

/** Genera el F-SIS-01 Rev. 04 usando la plantilla Word institucional original. */
function rame_service_order_docx(array $receipt): string
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('La extensión PHP zip (ZipArchive) está deshabilitada. Activa extension=zip en php.ini y reinicia Apache.');
    }
    if (!class_exists(DOMDocument::class)) {
        throw new RuntimeException('La extensión PHP dom está deshabilitada. Activa extension=dom en php.ini y reinicia Apache.');
    }

    $template = dirname(__DIR__) . '/F-SIS-01 Rev. 04 Orden de Servicio.docx';
    if (!is_file($template)) {
        $template = __DIR__ . '/templates/F-SIS-01 Rev. 04 Orden de Servicio.docx';
    }
    if (!is_file($template)) throw new RuntimeException('No se encontró la plantilla institucional F-SIS-01.');
    $tmp = tempnam(sys_get_temp_dir(), 'rame_fsis01_');
    if ($tmp === false || !copy($template, $tmp)) throw new RuntimeException('No fue posible preparar el documento F-SIS-01.');

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); throw new RuntimeException('No fue posible abrir la plantilla DOCX.'); }
    try {
        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) throw new RuntimeException('La plantilla DOCX no contiene document.xml.');
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        if (!$dom->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('No se pudo leer la estructura de la plantilla F-SIS-01.');
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rows = $xp->query('//w:body/w:tbl[1]/w:tr');
        $getCell = static function (int $rowIndex, int $cellIndex) use ($rows, $xp): ?DOMElement {
            $row = $rows->item($rowIndex);
            if (!$row) return null;
            $cell = $xp->query('./w:tc', $row)->item($cellIndex);
            return $cell instanceof DOMElement ? $cell : null;
        };
        $getRowIndex = static function (string $label, int $startAt = 0) use ($rows): ?int {
            for ($index = $startAt; $index < $rows->length; $index++) {
                $row = $rows->item($index);
                if ($row && stripos((string) $row->textContent, $label) !== false) return $index;
            }
            return null;
        };
        $getCellIndex = static function (int $rowIndex, string $label) use ($rows, $xp): ?int {
            $row = $rows->item($rowIndex);
            if (!$row) return null;
            $cells = $xp->query('./w:tc', $row);
            for ($index = 0; $index < $cells->length; $index++) {
                $cell = $cells->item($index);
                if ($cell && stripos((string) $cell->textContent, $label) !== false) return $index;
            }
            return null;
        };
        foreach ([
            'FECHA:', 'DEPARTAMENTO:', 'DATOS DEL EQUIPO:',
            'LIMPIEZA EXTERIOR', 'LIMPIEZA INTERIOR', 'DEPURACIÓN DE SISTEMA', 'REVISIÓN DE VIRUS',
            'CONTRASEÑA DE COMPUTADORA', 'SATISFACCIÓN DEL MANTENIMIENTO REALIZADO',
            'COMENTARIOS O SUGERENCIAS', 'NOMBRE DEL USUARIO:', 'REALIZADO POR:',
        ] as $requiredLabel) {
            if ($getRowIndex($requiredLabel) === null) {
                throw new RuntimeException('La plantilla F-SIS-01 no contiene el campo requerido: ' . $requiredLabel);
            }
        }
        $setCell = static function (?DOMElement $cell, string $value) use ($dom, $xp): void {
            if (!$cell) return;
            $p = $xp->query('./w:p', $cell)->item(0);
            if (!$p) {
                $p = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:p');
                $cell->appendChild($p);
            }
            $pPr = $xp->query('./w:pPr', $p)->item(0);
            $runPr = $xp->query('./w:r/w:rPr', $p)->item(0);
            foreach (iterator_to_array($p->childNodes) as $child) if ($child !== $pPr) $p->removeChild($child);
            $value = trim($value);
            if ($value === '') return;
            $parts = preg_split('/\R/u', $value) ?: [$value];
            foreach ($parts as $i => $part) {
                $run = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
                if ($runPr) $run->appendChild($runPr->cloneNode(true));
                if ($i > 0) $run->appendChild($dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:br'));
                $t = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
                $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $t->appendChild($dom->createTextNode($part));
                $run->appendChild($t);
                $p->appendChild($run);
            }
        };
        $v = is_array($receipt['formData'] ?? null) ? $receipt['formData'] : [];
        $str = static fn(string $key): string => trim((string)($v[$key] ?? ''));

        // Ubicar los valores junto a sus etiquetas en la plantilla institucional activa.
        $headerRow = $getRowIndex('FECHA:');
        if ($headerRow !== null) {
            $dateLabelCell = $getCellIndex($headerRow, 'FECHA:');
            $departmentLabelCell = $getCellIndex($headerRow, 'DEPARTAMENTO:');
            if ($dateLabelCell !== null) $setCell($getCell($headerRow, $dateLabelCell + 1), $str('date'));
            if ($departmentLabelCell !== null) $setCell($getCell($headerRow, $departmentLabelCell + 1), $str('department'));
        }

        // Bloque de datos: refleja las selecciones de la vista previa y la captura manual.
        $equipmentLabelRow = $getRowIndex('DATOS DEL EQUIPO:');
        if ($equipmentLabelRow !== null) {
            $setCell($getCell($equipmentLabelRow + 1, 0), $str('equipmentDetails'));
        }

        // Actividades: texto Sí, No o N/A en la columna elegida por el usuario.
        foreach ([
            'cleaningExterior' => 'LIMPIEZA EXTERIOR',
            'cleaningInterior' => 'LIMPIEZA INTERIOR',
            'systemCleanup' => 'DEPURACIÓN DE SISTEMA',
            'virusReview' => 'REVISIÓN DE VIRUS',
        ] as $field => $activityLabel) {
            $choice = (string)($v[$field] ?? '');
            $column = $choice === 'done' ? 1 : ($choice === 'not_done' ? 2 : ($choice === 'not_applicable' ? 3 : 0));
            $rowIndex = $getRowIndex($activityLabel);
            if ($column > 0 && $rowIndex !== null) $setCell($getCell($rowIndex, $column), $choice === 'done' ? 'Sí' : ($choice === 'not_done' ? 'No' : 'N/A'));
        }

        // La contraseña se imprime en el espacio previsto por el formato institucional.
        $passwordRow = $getRowIndex('CONTRASEÑA DE COMPUTADORA');
        $passwordLabelCell = $passwordRow !== null ? $getCellIndex($passwordRow, 'CONTRASEÑA DE COMPUTADORA') : null;
        if ($passwordRow !== null && $passwordLabelCell !== null) $setCell($getCell($passwordRow, $passwordLabelCell + 1), $str('computerPassword'));
        $score = $str('satisfaction');
        $satisfactionRow = $getRowIndex('SATISFACCIÓN DEL MANTENIMIENTO REALIZADO');
        if ($satisfactionRow !== null) $setCell($getCell($satisfactionRow, 1), $score);
        $commentsRow = $getRowIndex('COMENTARIOS O SUGERENCIAS');
        if ($commentsRow !== null) $setCell($getCell($commentsRow + 1, 0), $str('comments'));
        $userNameRow = $getRowIndex('NOMBRE DEL USUARIO:');
        $technicianNameRow = $getRowIndex('REALIZADO POR:');
        if ($userNameRow !== null) {
            $userNameCell = $getCellIndex($userNameRow, 'NOMBRE DEL USUARIO:');
            if ($userNameCell !== null) $setCell($getCell($userNameRow, $userNameCell + 1), (string)($receipt['signedName'] ?? ''));
        }
        if ($technicianNameRow !== null) {
            $technicianNameCell = $getCellIndex($technicianNameRow, 'REALIZADO POR:');
            if ($technicianNameCell !== null) $setCell($getCell($technicianNameRow, $technicianNameCell + 1), (string)($receipt['performedByName'] ?? ''));
        }

        // Insertar las dos firmas manuscritas en las celdas FIRMA del formato original.
        $relationships = $zip->getFromName('word/_rels/document.xml.rels');
        if ($relationships === false) throw new RuntimeException('La plantilla DOCX no contiene relaciones de documento.');
        $relsDom = new DOMDocument('1.0', 'UTF-8');
        $relsDom->preserveWhiteSpace = true;
        if (!$relsDom->loadXML($relationships, LIBXML_NONET)) throw new RuntimeException('No se pudieron leer las relaciones de imágenes de Word.');
        $relsXp = new DOMXPath($relsDom);
        $relsXp->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $usedIds = [];
        foreach ($relsXp->query('/*/*') as $rel) $usedIds[] = (string)$rel->getAttribute('Id');
        $nextRel = 1;
        $addSignature = static function (?DOMElement $cell, string $dataUri, string $label) use ($zip, $dom, $relsDom, $relsXp, &$usedIds, &$nextRel): void {
            if (!$cell || trim($dataUri) === '') return;
            if (!preg_match('~^data:image/(png|jpeg);base64,([A-Za-z0-9+/=\r\n]+)$~', trim($dataUri), $m)) return;
            $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : 'png';
            $binary = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
            if ($binary === false || strlen($binary) < 40 || strlen($binary) > 2_000_000) return;
            $isPng = str_starts_with($binary, "\x89PNG\r\n\x1a\n");
            $isJpeg = str_starts_with($binary, "\xFF\xD8\xFF");
            if (($ext === 'png' && !$isPng) || ($ext === 'jpg' && !$isJpeg)) return;

            $imageName = 'rame_signature_' . $label . '.' . $ext;
            $zip->addFromString('word/media/' . $imageName, $binary);
            do { $relId = 'rIdRame' . $nextRel++; } while (in_array($relId, $usedIds, true));
            $usedIds[] = $relId;
            $rel = $relsDom->createElementNS('http://schemas.openxmlformats.org/package/2006/relationships', 'Relationship');
            $rel->setAttribute('Id', $relId);
            $rel->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image');
            $rel->setAttribute('Target', 'media/' . $imageName);
            $relsDom->documentElement->appendChild($rel);

            $p = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:p');
            $pPr = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:pPr');
            $jc = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:jc');
            $jc->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:val', 'center');
            $pPr->appendChild($jc); $p->appendChild($pPr);
            $run = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
            $drawing = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:drawing');
            $inline = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'wp:inline');
            foreach (['distT','distB','distL','distR'] as $attr) $inline->setAttribute($attr, '0');
            $extent = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'wp:extent');
            $extent->setAttribute('cx', '1500000'); $extent->setAttribute('cy', '480000'); $inline->appendChild($extent);
            $effect = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'wp:effectExtent');
            foreach (['l','t','r','b'] as $a) $effect->setAttribute($a, '0'); $inline->appendChild($effect);
            $docPr = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'wp:docPr');
            $docPr->setAttribute('id', (string)(100 + $nextRel)); $docPr->setAttribute('name', 'Firma ' . $label); $inline->appendChild($docPr);
            $frame = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'wp:cNvGraphicFramePr');
            $locks = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'a:graphicFrameLocks');
            $locks->setAttribute('noChangeAspect', '1'); $frame->appendChild($locks); $inline->appendChild($frame);
            $graphic = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'a:graphic');
            $graphicData = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'a:graphicData');
            $graphicData->setAttribute('uri', 'http://schemas.openxmlformats.org/drawingml/2006/picture');
            $pic = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/picture', 'pic:pic');
            $nv = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/picture', 'pic:nvPicPr');
            $cNvPr = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/picture', 'pic:cNvPr');
            $cNvPr->setAttribute('id', '0'); $cNvPr->setAttribute('name', $imageName); $nv->appendChild($cNvPr);
            $nv->appendChild($dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/picture', 'pic:cNvPicPr')); $pic->appendChild($nv);
            $blipFill = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/picture', 'pic:blipFill');
            $blip = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'a:blip');
            $blip->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:embed', $relId); $blipFill->appendChild($blip);
            $stretch = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'a:stretch');
            $stretch->appendChild($dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'a:fillRect')); $blipFill->appendChild($stretch); $pic->appendChild($blipFill);
            $spPr = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/picture', 'pic:spPr');
            $xfrm = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'a:xfrm');
            $off = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'a:off'); $off->setAttribute('x','0'); $off->setAttribute('y','0'); $xfrm->appendChild($off);
            $extNode = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'a:ext'); $extNode->setAttribute('cx','1500000'); $extNode->setAttribute('cy','480000'); $xfrm->appendChild($extNode); $spPr->appendChild($xfrm);
            $prst = $dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'a:prstGeom'); $prst->setAttribute('prst','rect'); $prst->appendChild($dom->createElementNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'a:avLst')); $spPr->appendChild($prst); $pic->appendChild($spPr);
            $graphicData->appendChild($pic); $graphic->appendChild($graphicData); $inline->appendChild($graphic); $drawing->appendChild($inline); $run->appendChild($drawing); $p->appendChild($run);
            // La celda ya contiene la palabra FIRMA en el renglón inferior; se inserta imagen en el primer párrafo vacío.
            $inserted = false;
            foreach ($cell->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'p') {
                    $text = trim((string)$child->textContent);
                    if ($text === '') { $cell->replaceChild($p, $child); $inserted = true; break; }
                }
            }
            if (!$inserted) {
                $tcPr = $cell->firstChild;
                if ($tcPr instanceof DOMElement && $tcPr->localName === 'tcPr' && $tcPr->nextSibling) {
                    $cell->insertBefore($p, $tcPr->nextSibling);
                } else {
                    $cell->appendChild($p);
                }
            }
        };
        $userSignatureRow = $userNameRow !== null ? $getRowIndex('FIRMA', $userNameRow + 1) : null;
        $technicianSignatureRow = $technicianNameRow !== null ? $getRowIndex('FIRMA', $technicianNameRow + 1) : null;
        if ($userSignatureRow === null || $technicianSignatureRow === null) {
            throw new RuntimeException('La plantilla F-SIS-01 no contiene ambos espacios de firma.');
        }
        $userSignatureCell = $userSignatureRow !== null ? $getCellIndex($userSignatureRow, 'FIRMA') : null;
        $technicianSignatureCell = $technicianSignatureRow !== null ? $getCellIndex($technicianSignatureRow, 'FIRMA') : null;
        $addSignature($userSignatureRow !== null && $userSignatureCell !== null ? $getCell($userSignatureRow, $userSignatureCell) : null, (string)($receipt['signature'] ?? ''), 'usuario');
        $addSignature($technicianSignatureRow !== null && $technicianSignatureCell !== null ? $getCell($technicianSignatureRow, $technicianSignatureCell) : null, (string)($receipt['performedBySignature'] ?? ''), 'tecnico');

        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->addFromString('word/_rels/document.xml.rels', $relsDom->saveXML());
        $contentTypes = $zip->getFromName('[Content_Types].xml');
        if ($contentTypes !== false) {
            $ctDom = new DOMDocument('1.0', 'UTF-8'); $ctDom->preserveWhiteSpace = true;
            if ($ctDom->loadXML($contentTypes, LIBXML_NONET)) {
                $ctXp = new DOMXPath($ctDom); $ctXp->registerNamespace('ct','http://schemas.openxmlformats.org/package/2006/content-types');
                $hasPng = $ctXp->query('/ct:Types/ct:Default[@Extension="png"]')->length > 0;
                $hasJpg = $ctXp->query('/ct:Types/ct:Default[@Extension="jpg"]')->length > 0;
                if (!$hasPng) { $n=$ctDom->createElementNS('http://schemas.openxmlformats.org/package/2006/content-types','Default'); $n->setAttribute('Extension','png'); $n->setAttribute('ContentType','image/png'); $ctDom->documentElement->appendChild($n); }
                if (!$hasJpg) { $n=$ctDom->createElementNS('http://schemas.openxmlformats.org/package/2006/content-types','Default'); $n->setAttribute('Extension','jpg'); $n->setAttribute('ContentType','image/jpeg'); $ctDom->documentElement->appendChild($n); }
                $zip->addFromString('[Content_Types].xml', $ctDom->saveXML());
            }
        }
    } catch (Throwable $e) {
        $zip->close(); @unlink($tmp); throw $e;
    }
    $zip->close();
    return $tmp;
}
