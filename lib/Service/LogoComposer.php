<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

/**
 * Inserts the organisation logo (see LogoService::resolve()) into a template
 * at {{organisation.logo}} (or the earlier {{sag.logo}} / {{case.logo}}).
 *
 * Two kinds of placeholder are supported, in DOCX and ODT alike:
 *
 *   - Image placeholder: a picture whose alternative text, title or name is
 *     the placeholder. Its image is replaced by the logo, scaled to fit the
 *     picture's box with the logo's proportions. Position, anchoring and
 *     wrapping stay as designed.
 *   - Text placeholder: the placeholder typed as text. It is replaced by the
 *     logo as an inline image, 2 cm high (at most 6 cm wide).
 *
 * Without a logo, image placeholders are removed and text placeholders left
 * empty. SVG logos are embedded as SVG (DOCX: also marked with Word's SVG
 * extension), which Nextcloud Office renders natively.
 */
class LogoComposer {

    /** {{organisation.logo}}; {{sag.logo}} / {{case.logo}} are the earlier names, still accepted. */
    public const PLACEHOLDERS = ['organisation.logo', 'sag.logo', 'case.logo'];

    private const PATTERN = '/\{\{\s*(?:organisation|sag|case)\.logo\s*\}\}/i';

    /** Text placeholder size in cm. */
    private const TEXT_HEIGHT_CM = 2.0;
    private const TEXT_MAX_WIDTH_CM = 6.0;
    private const EMU_PER_CM = 360000;

    private const W   = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const WP  = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
    private const A   = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const PIC = 'http://schemas.openxmlformats.org/drawingml/2006/picture';
    private const ASVG = 'http://schemas.microsoft.com/office/drawing/2016/SVG/main';
    private const SVG_EXT_URI = '{96DAC541-7B7A-43D3-8B79-37D633B846F1}';
    private const PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const CT  = 'http://schemas.openxmlformats.org/package/2006/content-types';
    private const REL_IMAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

    private const TEXT     = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    private const DRAW     = 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0';
    private const SVGNS    = 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0';
    private const XLINK    = 'http://www.w3.org/1999/xlink';
    private const OFFICE   = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    private const MANIFEST = 'urn:oasis:names:tc:opendocument:xmlns:manifest:1.0';

    public function containsPlaceholder(string $xml): bool {
        return preg_match(self::PATTERN, $xml) === 1;
    }

    // ---------------------------------------------------------------
    // DOCX
    // ---------------------------------------------------------------

    /**
     * Insert the logo into one DOCX part (document, header or footer).
     * Changed package parts are returned in $parts (name => XML, also read
     * from there); the image itself is added to $zip.
     *
     * @param array{path: string, mime_type: string, width: int, height: int, org_name: string}|null $logo
     * @param array<string, string> $parts
     */
    public function composeDocx(\ZipArchive $zip, string $partName, string $xml, ?array $logo, array &$parts): string {
        if (!$this->containsPlaceholder($xml)) {
            return $xml;
        }
        $doc = $this->loadXml($xml);
        $relId = $logo !== null ? $this->docxImageRelationship($zip, $partName, $logo, $parts) : null;
        $ext = $logo !== null ? $this->extensionOf($logo) : '';

        // Image placeholders
        foreach (iterator_to_array($doc->getElementsByTagNameNS(self::WP, 'docPr'), false) as $docPr) {
            if (!$this->matches($docPr->getAttribute('descr'))
                && !$this->matches($docPr->getAttribute('title'))
                && !$this->matches($docPr->getAttribute('name'))) {
                continue;
            }
            $drawing = $this->closestAncestor($docPr, self::W, 'drawing');
            $container = $docPr->parentNode;
            if ($relId === null) {
                $drawing?->parentNode?->removeChild($drawing);
                continue;
            }
            foreach ($container->getElementsByTagNameNS(self::A, 'blip') as $blip) {
                $this->setDocxBlip($blip, $relId, $ext);
            }
            $extent = $this->firstChildNS($container, self::WP, 'extent');
            if ($extent !== null) {
                [$cx, $cy] = array_map('intval', array_map('round', $this->fit(
                    $logo['width'], $logo['height'], (float)$extent->getAttribute('cx'), (float)$extent->getAttribute('cy'),
                )));
                $extent->setAttribute('cx', (string)$cx);
                $extent->setAttribute('cy', (string)$cy);
                foreach ($container->getElementsByTagNameNS(self::A, 'xfrm') as $xfrm) {
                    $aExt = $this->firstChildNS($xfrm, self::A, 'ext');
                    $aExt?->setAttribute('cx', (string)$cx);
                    $aExt?->setAttribute('cy', (string)$cy);
                }
            }
            $docPr->setAttribute('descr', $logo['org_name']);
            $docPr->removeAttribute('title');
            if ($this->matches($docPr->getAttribute('name'))) {
                $docPr->setAttribute('name', 'Logo');
            }
        }

        // Text placeholders
        $drawingId = 900000;
        foreach (iterator_to_array($doc->getElementsByTagNameNS(self::W, 't'), false) as $t) {
            $pieces = preg_split(self::PATTERN, $t->textContent);
            if (count($pieces) < 2) {
                continue;
            }
            $run = $t->parentNode;
            foreach ($pieces as $i => $text) {
                if ($i > 0 && $relId !== null) {
                    $run->insertBefore($this->docxInlineDrawing($doc, $relId, $logo, ++$drawingId), $t);
                }
                if ($text !== '') {
                    $newT = $doc->createElementNS(self::W, 'w:t');
                    $newT->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                    $newT->appendChild($doc->createTextNode($text));
                    $run->insertBefore($newT, $t);
                }
            }
            $run->removeChild($t);
        }

        return $doc->saveXML();
    }

    /**
     * Add the logo image to the package (once) and a relationship to it from
     * $partName (once). Returns the relationship id.
     */
    private function docxImageRelationship(\ZipArchive $zip, string $partName, array $logo, array &$parts): ?string {
        $ext = $this->extensionOf($logo);
        $mediaName = 'word/media/oclogo.' . $ext;
        if (!isset($parts[$mediaName])) {
            $content = file_get_contents($logo['path']);
            if ($content === false) {
                return null;
            }
            $zip->addFromString($mediaName, $content);
            // Marker only: binary parts are written to the ZIP directly.
            $parts[$mediaName] = null;
            $this->ensureDocxDefaultContentType($zip, $ext, $logo['mime_type'], $parts);
        }

        $relsName = dirname($partName) . '/_rels/' . basename($partName) . '.rels';
        $rels = $this->loadPart($zip, $relsName, $parts);
        if ($rels === null) {
            $rels = new \DOMDocument('1.0', 'UTF-8');
            $rels->xmlStandalone = true;
            $rels->appendChild($rels->createElementNS(self::PKG_REL, 'Relationships'));
        }
        $target = 'media/oclogo.' . $ext;
        $ids = [];
        foreach ($rels->getElementsByTagNameNS(self::PKG_REL, 'Relationship') as $rel) {
            if ($rel->getAttribute('Target') === $target && $rel->getAttribute('Type') === self::REL_IMAGE) {
                return $rel->getAttribute('Id');
            }
            $ids[$rel->getAttribute('Id')] = true;
        }
        $id = 'rIdOcLogo';
        for ($n = 2; isset($ids[$id]); $n++) {
            $id = 'rIdOcLogo' . $n;
        }
        $rel = $rels->createElementNS(self::PKG_REL, 'Relationship');
        $rel->setAttribute('Id', $id);
        $rel->setAttribute('Type', self::REL_IMAGE);
        $rel->setAttribute('Target', $target);
        $rels->documentElement->appendChild($rel);
        $parts[$relsName] = $rels->saveXML();
        return $id;
    }

    private function ensureDocxDefaultContentType(\ZipArchive $zip, string $ext, string $mimeType, array &$parts): void {
        $types = $this->loadPart($zip, '[Content_Types].xml', $parts);
        if ($types === null) {
            return;
        }
        foreach ($types->getElementsByTagNameNS(self::CT, 'Default') as $d) {
            if (strtolower($d->getAttribute('Extension')) === $ext) {
                return;
            }
        }
        $default = $types->createElementNS(self::CT, 'Default');
        $default->setAttribute('Extension', $ext);
        $default->setAttribute('ContentType', $mimeType);
        $types->documentElement->insertBefore($default, $types->documentElement->firstChild);
        $parts['[Content_Types].xml'] = $types->saveXML();
    }

    /**
     * Point a picture at the logo. For SVG, Word's SVG extension is added so
     * Word shows the vector image too.
     */
    private function setDocxBlip(\DOMElement $blip, string $relId, string $ext): void {
        $blip->setAttributeNS(self::R, 'r:embed', $relId);
        $blip->removeAttributeNS(self::R, 'link');
        foreach (iterator_to_array($blip->childNodes) as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'extLst') {
                $blip->removeChild($child);
            }
        }
        if ($ext === 'svg') {
            $doc = $blip->ownerDocument;
            $extLst = $doc->createElementNS(self::A, 'a:extLst');
            $aExt = $doc->createElementNS(self::A, 'a:ext');
            $aExt->setAttribute('uri', self::SVG_EXT_URI);
            $svgBlip = $doc->createElementNS(self::ASVG, 'asvg:svgBlip');
            $svgBlip->setAttributeNS(self::R, 'r:embed', $relId);
            $aExt->appendChild($svgBlip);
            $extLst->appendChild($aExt);
            $blip->appendChild($extLst);
        }
    }

    private function docxInlineDrawing(\DOMDocument $doc, string $relId, array $logo, int $id): \DOMElement {
        [$wCm, $hCm] = $this->textSizeCm($logo);
        $cx = (int)round($wCm * self::EMU_PER_CM);
        $cy = (int)round($hCm * self::EMU_PER_CM);
        $name = htmlspecialchars($logo['org_name'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $file = 'oclogo.' . $this->extensionOf($logo);

        $xml = '<w:drawing xmlns:w="' . self::W . '" xmlns:wp="' . self::WP . '" xmlns:a="' . self::A
            . '" xmlns:pic="' . self::PIC . '" xmlns:r="' . self::R . '">'
            . '<wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
            . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
            . '<wp:docPr id="' . $id . '" name="Logo" descr="' . $name . '"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="' . $file . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $relId . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            . '</a:graphicData></a:graphic></wp:inline></w:drawing>';

        $fragment = $this->loadXml($xml);
        $drawing = $doc->importNode($fragment->documentElement, true);
        if ($this->extensionOf($logo) === 'svg') {
            $this->setDocxBlip($drawing->getElementsByTagNameNS(self::A, 'blip')->item(0), $relId, 'svg');
        }
        return $drawing;
    }

    // ---------------------------------------------------------------
    // ODT
    // ---------------------------------------------------------------

    /**
     * Insert the logo into content.xml or styles.xml of an ODT template.
     * See composeDocx() for $parts.
     *
     * @param array{path: string, mime_type: string, width: int, height: int, org_name: string}|null $logo
     * @param array<string, string> $parts
     */
    public function composeOdt(\ZipArchive $zip, string $xml, ?array $logo, array &$parts): string {
        if (!$this->containsPlaceholder($xml)) {
            return $xml;
        }
        $doc = $this->loadXml($xml);
        $href = $logo !== null ? $this->odtPicture($zip, $logo, $parts) : null;

        // Image placeholders: frames whose name, title or description is the placeholder.
        foreach (iterator_to_array($doc->getElementsByTagNameNS(self::DRAW, 'frame'), false) as $frame) {
            $title = $this->firstChildNS($frame, self::SVGNS, 'title');
            $desc = $this->firstChildNS($frame, self::SVGNS, 'desc');
            if (!$this->matches($frame->getAttributeNS(self::DRAW, 'name'))
                && !$this->matches($title?->textContent ?? '') && !$this->matches($desc?->textContent ?? '')) {
                continue;
            }
            if ($href === null) {
                $frame->parentNode->removeChild($frame);
                continue;
            }
            foreach ($frame->getElementsByTagNameNS(self::DRAW, 'image') as $image) {
                $image->setAttributeNS(self::XLINK, 'xlink:href', $href);
                foreach (iterator_to_array($image->childNodes) as $child) {
                    if ($child instanceof \DOMElement && $child->localName === 'binary-data') {
                        $image->removeChild($child);
                    }
                }
            }
            $boxW = $this->lengthToCm($frame->getAttributeNS(self::SVGNS, 'width'));
            $boxH = $this->lengthToCm($frame->getAttributeNS(self::SVGNS, 'height'));
            if ($boxW > 0 && $boxH > 0) {
                [$w, $h] = $this->fit($logo['width'], $logo['height'], $boxW, $boxH);
                $frame->setAttributeNS(self::SVGNS, 'svg:width', $this->cm($w));
                $frame->setAttributeNS(self::SVGNS, 'svg:height', $this->cm($h));
            }
            if ($this->matches($frame->getAttributeNS(self::DRAW, 'name'))) {
                $frame->setAttributeNS(self::DRAW, 'draw:name', 'Logo');
            }
            foreach ([$title, $desc] as $el) {
                if ($el !== null) {
                    $el->textContent = $logo['org_name'];
                }
            }
        }

        // Text placeholders
        $xp = new \DOMXPath($doc);
        foreach (iterator_to_array($xp->query('//text()'), false) as $node) {
            $pieces = preg_split(self::PATTERN, $node->data);
            if (count($pieces) < 2 || !($node->parentNode instanceof \DOMElement) || $node->parentNode->namespaceURI !== self::TEXT) {
                continue;
            }
            $parent = $node->parentNode;
            foreach ($pieces as $i => $text) {
                if ($i > 0 && $href !== null) {
                    $parent->insertBefore($this->odtInlineFrame($doc, $href, $logo), $node);
                }
                if ($text !== '') {
                    $parent->insertBefore($doc->createTextNode($text), $node);
                }
            }
            $parent->removeChild($node);
        }

        return $doc->saveXML();
    }

    /**
     * Add the logo to Pictures/ and the manifest (once); returns its path.
     */
    private function odtPicture(\ZipArchive $zip, array $logo, array &$parts): ?string {
        $path = 'Pictures/oclogo.' . $this->extensionOf($logo);
        if (array_key_exists($path, $parts)) {
            return $path;
        }
        $content = file_get_contents($logo['path']);
        if ($content === false) {
            return null;
        }
        $zip->addFromString($path, $content);
        $parts[$path] = null;

        $manifest = $this->loadPart($zip, 'META-INF/manifest.xml', $parts);
        if ($manifest !== null) {
            $entry = $manifest->createElementNS(self::MANIFEST, 'manifest:file-entry');
            $entry->setAttributeNS(self::MANIFEST, 'manifest:full-path', $path);
            $entry->setAttributeNS(self::MANIFEST, 'manifest:media-type', $logo['mime_type']);
            $manifest->documentElement->appendChild($entry);
            $parts['META-INF/manifest.xml'] = $manifest->saveXML();
        }
        return $path;
    }

    private function odtInlineFrame(\DOMDocument $doc, string $href, array $logo): \DOMElement {
        [$w, $h] = $this->textSizeCm($logo);
        $frame = $doc->createElementNS(self::DRAW, 'draw:frame');
        $frame->setAttributeNS(self::DRAW, 'draw:name', 'Logo');
        $frame->setAttributeNS(self::TEXT, 'text:anchor-type', 'as-char');
        $frame->setAttributeNS(self::SVGNS, 'svg:width', $this->cm($w));
        $frame->setAttributeNS(self::SVGNS, 'svg:height', $this->cm($h));
        $frame->setAttributeNS(self::DRAW, 'draw:z-index', '0');
        $image = $doc->createElementNS(self::DRAW, 'draw:image');
        $image->setAttributeNS(self::XLINK, 'xlink:href', $href);
        $image->setAttributeNS(self::XLINK, 'xlink:type', 'simple');
        $image->setAttributeNS(self::XLINK, 'xlink:show', 'embed');
        $image->setAttributeNS(self::XLINK, 'xlink:actuate', 'onLoad');
        $frame->appendChild($image);
        $title = $doc->createElementNS(self::SVGNS, 'svg:title');
        $title->textContent = $logo['org_name'];
        $frame->appendChild($title);
        return $frame;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function matches(string $text): bool {
        return preg_match(self::PATTERN, $text) === 1;
    }

    /**
     * Scale (w, h) to fit inside the box (boxW, boxH), keeping proportions.
     *
     * @return array{0: float, 1: float}
     */
    private function fit(int $w, int $h, float $boxW, float $boxH): array {
        $scale = min($boxW / $w, $boxH / $h);
        return [$w * $scale, $h * $scale];
    }

    /**
     * Size of a text-placeholder logo in cm.
     *
     * @return array{0: float, 1: float}
     */
    private function textSizeCm(array $logo): array {
        $h = self::TEXT_HEIGHT_CM;
        $w = $h * $logo['width'] / $logo['height'];
        if ($w > self::TEXT_MAX_WIDTH_CM) {
            $h *= self::TEXT_MAX_WIDTH_CM / $w;
            $w = self::TEXT_MAX_WIDTH_CM;
        }
        return [$w, $h];
    }

    private function lengthToCm(string $length): float {
        if (!preg_match('/^\s*([\d.]+)\s*(cm|mm|in|pt|pc|px)?\s*$/i', $length, $m)) {
            return 0.0;
        }
        $factor = match (strtolower($m[2] ?? 'cm')) {
            'mm' => 0.1,
            'in' => 2.54,
            'pt' => 2.54 / 72,
            'pc' => 2.54 / 6,
            'px' => 2.54 / 96,
            default => 1.0,
        };
        return (float)$m[1] * $factor;
    }

    private function cm(float $value): string {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') . 'cm';
    }

    private function extensionOf(array $logo): string {
        return strtolower(pathinfo($logo['path'], PATHINFO_EXTENSION));
    }

    private function loadXml(string $xml): \DOMDocument {
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            throw new \RuntimeException('Invalid XML in document');
        }
        return $doc;
    }

    /**
     * @param array<string, string|null> $parts updated parts that take precedence over the ZIP
     */
    private function loadPart(\ZipArchive $zip, string $name, array $parts): ?\DOMDocument {
        $xml = $parts[$name] ?? $zip->getFromName($name);
        if ($xml === false || $xml === null) {
            return null;
        }
        try {
            return $this->loadXml($xml);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function firstChildNS(\DOMElement $parent, string $ns, string $localName): ?\DOMElement {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === $ns && $child->localName === $localName) {
                return $child;
            }
        }
        return null;
    }

    private function closestAncestor(\DOMNode $node, string $ns, string $localName): ?\DOMElement {
        for ($n = $node->parentNode; $n !== null; $n = $n->parentNode) {
            if ($n instanceof \DOMElement && $n->namespaceURI === $ns && $n->localName === $localName) {
                return $n;
            }
        }
        return null;
    }
}
