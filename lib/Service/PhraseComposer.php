<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

/**
 * Inserts the content of phrase documents into a template at the
 * {{fraser.indhold}} / {{phrases.content}} placeholder.
 *
 * A paragraph containing nothing but the placeholder is replaced by the
 * phrases' own paragraphs, tables and lists with their formatting:
 *
 *   - DOCX phrase into a DOCX template: body content is copied together with
 *     the styles it uses (when the template lacks them), its list numbering,
 *     images and hyperlinks.
 *   - ODT phrase into an ODT template: body content is copied together with
 *     its automatic styles (renamed per phrase), missing common styles and
 *     images.
 *   - Rich text (text/html — a phrase without a file, edited in OpenCase;
 *     see PhraseHtml) becomes formatted paragraphs: bold/italic/underline/
 *     strike-through, headings, real bullet and numbered lists, quotes.
 *   - A phrase in the other format, or plain text (text/plain), is inserted
 *     as plain paragraphs.
 *
 * A placeholder that shares its paragraph with other text, or sits in a
 * spreadsheet, is filled with the phrases' plain text instead (see
 * plainText() and TemplateMergeService).
 *
 * The template's styles always win: a style that exists in both documents
 * keeps the template's definition, so phrases follow the letterhead.
 */
class PhraseComposer {

    public const PLACEHOLDERS = ['fraser.indhold', 'phrases.content'];

    private const W   = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const W14 = 'http://schemas.microsoft.com/office/word/2010/wordml';
    private const WP  = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
    private const PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const CT  = 'http://schemas.openxmlformats.org/package/2006/content-types';
    private const REL_NUMBERING = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering';
    private const CT_NUMBERING  = 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml';

    private const OFFICE   = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    private const TEXT     = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    private const STYLE    = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';
    private const TABLE    = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const DRAW     = 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0';
    private const FO       = 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0';
    private const XLINK    = 'http://www.w3.org/1999/xlink';
    private const MANIFEST = 'urn:oasis:names:tc:opendocument:xmlns:manifest:1.0';

    /** DOCX elements dropped from phrase content (they point at parts that are not copied). */
    private const DOCX_DROP = [
        'bookmarkStart', 'bookmarkEnd', 'commentRangeStart', 'commentRangeEnd', 'commentReference',
        'footnoteReference', 'endnoteReference', 'proofErr', 'permStart', 'permEnd', 'sectPr',
    ];

    /** ODT body elements copied from a phrase (declarations, forms etc. are skipped). */
    private const ODT_BODY_KEEP = [
        self::TEXT  => ['p', 'h', 'list', 'section', 'numbered-paragraph'],
        self::TABLE => ['table'],
    ];

    public function containsPlaceholder(string $xml): bool {
        foreach (self::PLACEHOLDERS as $placeholder) {
            if (stripos($xml, $placeholder) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * The phrases as plain text: paragraphs separated by "\n", tabs as "\t".
     *
     * @param array{path: string, mime_type: string}[] $phrases
     */
    public function plainText(array $phrases): string {
        $paragraphs = [];
        foreach ($phrases as $phrase) {
            foreach ($this->plainParagraphs($phrase) as $line) {
                $paragraphs[] = $line;
            }
        }
        return implode("\n", $paragraphs);
    }

    // ---------------------------------------------------------------
    // DOCX
    // ---------------------------------------------------------------

    /**
     * Replace placeholder-only paragraphs in word/document.xml ($xml) with the
     * phrases' content. Styles, numbering, relationships and content types
     * of the template package ($zip) are updated as needed: changed XML parts
     * are returned in $parts (name => XML, also read from there), binary
     * parts such as images are added to $zip directly.
     *
     * @param array{path: string, mime_type: string}[] $phrases
     * @param array<string, string> $parts
     */
    public function composeDocx(\ZipArchive $zip, string $xml, array $phrases, array &$parts): string {
        if (!$this->containsPlaceholder($xml)) {
            return $xml;
        }
        $doc = $this->loadXml($xml);
        $targets = [];
        foreach ($doc->getElementsByTagNameNS(self::W, 'p') as $p) {
            if ($this->isPlaceholderOnly($this->docxParagraphText($p))) {
                $targets[] = $p;
            }
        }
        if ($targets === []) {
            return $xml;
        }

        $package = new \stdClass();
        $package->zip = $zip;
        $package->rels = $this->loadPart($zip, 'word/_rels/document.xml.rels', $parts);
        $package->types = $this->loadPart($zip, '[Content_Types].xml', $parts);
        $package->styles = $this->loadPart($zip, 'word/styles.xml', $parts);
        $package->numbering = $this->loadPart($zip, 'word/numbering.xml', $parts);
        $package->dirty = [];

        // Content per phrase: DOM elements of a phrase document, rich-text
        // blocks (an array item), or plain lines.
        $contents = [];
        foreach (array_values($phrases) as $index => $phrase) {
            if ($this->isHtml($phrase['mime_type'])) {
                $contents[] = [['blocks' => $this->htmlBlocks($phrase), 'index' => $index]];
                continue;
            }
            $nodes = $this->isDocx($phrase['mime_type']) ? $this->docxPhraseNodes($package, $phrase['path'], $index) : null;
            $contents[] = $nodes ?? $this->plainParagraphs($phrase);
        }

        foreach ($targets as $target) {
            $parent = $target->parentNode;
            foreach ($contents as $content) {
                foreach ($content as $item) {
                    $nodes = match (true) {
                        is_array($item)  => $this->docxRichParagraphs($package, $doc, $target, $item['blocks'], $item['index']),
                        is_string($item) => [$this->docxTextParagraph($doc, $target, $item)],
                        default          => [$doc->importNode($item, true)],
                    };
                    foreach ($nodes as $node) {
                        $parent->insertBefore($node, $target);
                    }
                }
            }
            $parent->removeChild($target);
            $this->ensureDocxCellEndsWithParagraph($doc, $parent);
        }

        // Drawing ids must be unique; copied (and repeated) phrases duplicate them.
        $drawingId = 0;
        foreach ($doc->getElementsByTagNameNS(self::WP, 'docPr') as $docPr) {
            $docPr->setAttribute('id', (string)++$drawingId);
        }

        foreach ($package->dirty as $name => $part) {
            $parts[$name] = $part->saveXML();
        }

        return $doc->saveXML();
    }

    /**
     * The body content of a DOCX phrase, with its styles, numbering and
     * related parts already copied into the template package.
     *
     * @return \DOMElement[]|null null when the phrase cannot be read as DOCX
     */
    private function docxPhraseNodes(\stdClass $package, string $path, int $index): ?array {
        $phraseZip = new \ZipArchive();
        if ($phraseZip->open($path) !== true) {
            return null;
        }
        try {
            $documentXml = $phraseZip->getFromName('word/document.xml');
            if ($documentXml === false) {
                return null;
            }
            $doc = $this->loadXml($documentXml);
            $body = $doc->getElementsByTagNameNS(self::W, 'body')->item(0);
            if ($body === null) {
                return null;
            }

            $nodes = [];
            foreach ($body->childNodes as $child) {
                if ($child instanceof \DOMElement && !($child->namespaceURI === self::W && $child->localName === 'sectPr')) {
                    $nodes[] = $child;
                }
            }
            foreach ($nodes as $node) {
                $this->sanitizeDocxNode($node);
            }

            $this->copyDocxRelationships($package, $phraseZip, $nodes, $index);
            $copiedStyles = $this->copyDocxStyles($package, $phraseZip, $nodes);
            $this->copyDocxNumbering($package, $phraseZip, array_merge($nodes, $copiedStyles));

            return $nodes;
        } finally {
            $phraseZip->close();
        }
    }

    private function sanitizeDocxNode(\DOMElement $root): void {
        $drop = [];
        foreach ($this->elementsOf($root) as $el) {
            if ($el->namespaceURI === self::W && in_array($el->localName, self::DOCX_DROP, true)) {
                $drop[] = $el;
                continue;
            }
            // Revision ids and paragraph ids are document specific.
            foreach (iterator_to_array($el->attributes) as $attr) {
                if (($attr->namespaceURI === self::W && str_starts_with($attr->localName, 'rsid'))
                    || ($attr->namespaceURI === self::W14 && in_array($attr->localName, ['paraId', 'textId'], true))) {
                    $el->removeAttributeNode($attr);
                }
            }
        }
        foreach ($drop as $el) {
            $el->parentNode?->removeChild($el);
        }
    }

    /**
     * Copy the parts (images etc.) and external links a phrase refers to and
     * point its r:id / r:embed / r:link attributes at the new relationships.
     *
     * @param \DOMElement[] $nodes
     */
    private function copyDocxRelationships(\stdClass $package, \ZipArchive $phraseZip, array $nodes, int $index): void {
        if ($package->rels === null) {
            return;
        }
        $phraseRels = [];
        $relsDoc = $this->loadPart($phraseZip, 'word/_rels/document.xml.rels');
        if ($relsDoc !== null) {
            foreach ($relsDoc->getElementsByTagNameNS(self::PKG_REL, 'Relationship') as $rel) {
                $phraseRels[$rel->getAttribute('Id')] = $rel;
            }
        }
        $phraseTypes = $this->loadPart($phraseZip, '[Content_Types].xml');

        $existingIds = [];
        foreach ($package->rels->getElementsByTagNameNS(self::PKG_REL, 'Relationship') as $rel) {
            $existingIds[$rel->getAttribute('Id')] = true;
        }

        $map = [];
        foreach ($nodes as $node) {
            foreach ($this->elementsOf($node) as $el) {
                foreach (iterator_to_array($el->attributes) as $attr) {
                    if ($attr->namespaceURI !== self::R) {
                        continue;
                    }
                    $oldId = $attr->value;
                    if (!array_key_exists($oldId, $map)) {
                        $map[$oldId] = isset($phraseRels[$oldId])
                            ? $this->copyDocxRelationship($package, $phraseZip, $phraseTypes, $phraseRels[$oldId], $index, $existingIds)
                            : null;
                    }
                    if ($map[$oldId] === null) {
                        $el->removeAttributeNode($attr);
                    } else {
                        $attr->value = $map[$oldId];
                    }
                }
            }
        }
    }

    private function copyDocxRelationship(
        \stdClass $package,
        \ZipArchive $phraseZip,
        ?\DOMDocument $phraseTypes,
        \DOMElement $rel,
        int $index,
        array &$existingIds,
    ): ?string {
        $n = 1;
        do {
            $newId = 'rIdOcPhrase' . $index . '_' . $n++;
        } while (isset($existingIds[$newId]));
        $existingIds[$newId] = true;

        $target = $rel->getAttribute('Target');
        $newRel = $package->rels->createElementNS(self::PKG_REL, 'Relationship');
        $newRel->setAttribute('Id', $newId);
        $newRel->setAttribute('Type', $rel->getAttribute('Type'));

        if ($rel->getAttribute('TargetMode') === 'External') {
            $newRel->setAttribute('Target', $target);
            $newRel->setAttribute('TargetMode', 'External');
        } else {
            $sourcePart = str_starts_with($target, '/') ? ltrim($target, '/') : 'word/' . $target;
            $content = $phraseZip->getFromName($sourcePart);
            if ($content === false) {
                return null;
            }
            $newTarget = 'media/ocphrase' . $index . '_' . basename($sourcePart);
            $package->zip->addFromString('word/' . $newTarget, $content);
            $this->ensureDocxContentType($package, $phraseTypes, '/' . $sourcePart, '/word/' . $newTarget);
            $newRel->setAttribute('Target', $newTarget);
        }

        $package->rels->documentElement->appendChild($newRel);
        $package->dirty['word/_rels/document.xml.rels'] = $package->rels;
        return $newId;
    }

    /**
     * Give a copied part the content type it had in the phrase package.
     */
    private function ensureDocxContentType(\stdClass $package, ?\DOMDocument $phraseTypes, string $sourcePart, string $newPart): void {
        if ($package->types === null || $phraseTypes === null) {
            return;
        }
        $ext = strtolower(pathinfo($newPart, PATHINFO_EXTENSION));

        $contentType = null;
        foreach ($phraseTypes->getElementsByTagNameNS(self::CT, 'Override') as $o) {
            if (strcasecmp($o->getAttribute('PartName'), $sourcePart) === 0) {
                $contentType = $o->getAttribute('ContentType');
            }
        }
        if ($contentType === null) {
            foreach ($phraseTypes->getElementsByTagNameNS(self::CT, 'Default') as $d) {
                if (strtolower($d->getAttribute('Extension')) === $ext) {
                    $contentType = $d->getAttribute('ContentType');
                }
            }
        }
        if ($contentType === null) {
            return;
        }

        foreach ($package->types->getElementsByTagNameNS(self::CT, 'Default') as $d) {
            if (strtolower($d->getAttribute('Extension')) === $ext && $d->getAttribute('ContentType') === $contentType) {
                return;
            }
        }
        $override = $package->types->createElementNS(self::CT, 'Override');
        $override->setAttribute('PartName', $newPart);
        $override->setAttribute('ContentType', $contentType);
        $package->types->documentElement->appendChild($override);
        $package->dirty['[Content_Types].xml'] = $package->types;
    }

    /**
     * Copy paragraph/character/table styles the phrase uses but the template
     * lacks (with the styles they are based on).
     *
     * @param \DOMElement[] $nodes
     * @return \DOMElement[] the style elements added to the template
     */
    private function copyDocxStyles(\stdClass $package, \ZipArchive $phraseZip, array $nodes): array {
        if ($package->styles === null) {
            return [];
        }
        $phraseStyles = $this->loadPart($phraseZip, 'word/styles.xml');
        if ($phraseStyles === null) {
            return [];
        }

        $existing = [];
        foreach ($package->styles->getElementsByTagNameNS(self::W, 'style') as $s) {
            $existing[$s->getAttributeNS(self::W, 'styleId')] = true;
        }
        $available = [];
        foreach ($phraseStyles->getElementsByTagNameNS(self::W, 'style') as $s) {
            $available[$s->getAttributeNS(self::W, 'styleId')] = $s;
        }

        $queue = [];
        foreach ($nodes as $node) {
            foreach ($this->elementsOf($node) as $el) {
                if ($el->namespaceURI === self::W && in_array($el->localName, ['pStyle', 'rStyle', 'tblStyle'], true)) {
                    $queue[] = $el->getAttributeNS(self::W, 'val');
                }
            }
        }

        $copied = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            if ($id === '' || isset($existing[$id]) || !isset($available[$id])) {
                continue;
            }
            $existing[$id] = true;
            $style = $package->styles->importNode($available[$id], true);
            $package->styles->documentElement->appendChild($style);
            $copied[] = $style;
            foreach (['basedOn', 'link'] as $ref) {
                $refEl = $available[$id]->getElementsByTagNameNS(self::W, $ref)->item(0);
                if ($refEl !== null) {
                    $queue[] = $refEl->getAttributeNS(self::W, 'val');
                }
            }
        }
        if ($copied !== []) {
            $package->dirty['word/styles.xml'] = $package->styles;
        }
        return $copied;
    }

    /**
     * Copy the list definitions the phrase uses under new ids, so its lists
     * keep their bullets/numbering and do not continue the template's lists.
     *
     * @param \DOMElement[] $nodes phrase content and copied styles
     */
    private function copyDocxNumbering(\stdClass $package, \ZipArchive $phraseZip, array $nodes): void {
        $numIdEls = [];
        foreach ($nodes as $node) {
            foreach ($this->elementsOf($node) as $el) {
                if ($el->namespaceURI === self::W && $el->localName === 'numId' && $el->getAttributeNS(self::W, 'val') !== '0') {
                    $numIdEls[] = $el;
                }
            }
        }
        if ($numIdEls === []) {
            return;
        }

        $phraseNumbering = $this->loadPart($phraseZip, 'word/numbering.xml');
        $phraseNums = [];
        $phraseAbstracts = [];
        if ($phraseNumbering !== null) {
            foreach ($phraseNumbering->documentElement->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->localName === 'num') {
                    $phraseNums[$child->getAttributeNS(self::W, 'numId')] = $child;
                } elseif ($child instanceof \DOMElement && $child->localName === 'abstractNum') {
                    $phraseAbstracts[$child->getAttributeNS(self::W, 'abstractNumId')] = $child;
                }
            }
        }

        $numbering = $this->ensureDocxNumbering($package);
        if ($numbering === null) {
            return;
        }
        $maxNum = 0;
        $maxAbstract = -1;
        $firstNum = null;
        $cleanup = null;
        foreach ($numbering->documentElement->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($child->localName === 'num') {
                $maxNum = max($maxNum, (int)$child->getAttributeNS(self::W, 'numId'));
                $firstNum ??= $child;
            } elseif ($child->localName === 'abstractNum') {
                $maxAbstract = max($maxAbstract, (int)$child->getAttributeNS(self::W, 'abstractNumId'));
            } elseif ($child->localName === 'numIdMacAtCleanup') {
                $cleanup = $child;
            }
        }

        $map = [];
        foreach ($numIdEls as $el) {
            $oldId = $el->getAttributeNS(self::W, 'val');
            if (!array_key_exists($oldId, $map)) {
                $map[$oldId] = null;
                $num = $phraseNums[$oldId] ?? null;
                $abstractId = $num?->getElementsByTagNameNS(self::W, 'abstractNumId')->item(0)?->getAttributeNS(self::W, 'val');
                if ($num !== null && $abstractId !== null && isset($phraseAbstracts[$abstractId])) {
                    $abstract = $numbering->importNode($phraseAbstracts[$abstractId], true);
                    $abstract->setAttributeNS(self::W, 'w:abstractNumId', (string)++$maxAbstract);
                    // A fresh nsid keeps Word from merging this list with others.
                    $nsid = $abstract->getElementsByTagNameNS(self::W, 'nsid')->item(0);
                    $nsid?->setAttributeNS(self::W, 'w:val', strtoupper(bin2hex(random_bytes(4))));
                    $numbering->documentElement->insertBefore($abstract, $firstNum ?? $cleanup);

                    $newNum = $numbering->importNode($num, true);
                    $newNum->setAttributeNS(self::W, 'w:numId', (string)++$maxNum);
                    $newNum->getElementsByTagNameNS(self::W, 'abstractNumId')->item(0)
                        ->setAttributeNS(self::W, 'w:val', (string)$maxAbstract);
                    $numbering->documentElement->insertBefore($newNum, $cleanup);
                    $firstNum ??= $newNum;
                    $map[$oldId] = (string)$maxNum;
                }
            }
            // Unknown list → plain paragraph (numId 0 means "no numbering").
            $el->setAttributeNS(self::W, 'w:val', $map[$oldId] ?? '0');
        }
        $package->dirty['word/numbering.xml'] = $numbering;
    }

    /**
     * The template's numbering part, created (with relationship and content
     * type) when the template has none.
     */
    private function ensureDocxNumbering(\stdClass $package): ?\DOMDocument {
        if ($package->numbering !== null) {
            return $package->numbering;
        }
        if ($package->rels === null || $package->types === null) {
            return null;
        }
        $numbering = new \DOMDocument('1.0', 'UTF-8');
        $numbering->xmlStandalone = true;
        $numbering->appendChild($numbering->createElementNS(self::W, 'w:numbering'));

        $rel = $package->rels->createElementNS(self::PKG_REL, 'Relationship');
        $rel->setAttribute('Id', 'rIdOcPhraseNumbering');
        $rel->setAttribute('Type', self::REL_NUMBERING);
        $rel->setAttribute('Target', 'numbering.xml');
        $package->rels->documentElement->appendChild($rel);
        $package->dirty['word/_rels/document.xml.rels'] = $package->rels;

        $override = $package->types->createElementNS(self::CT, 'Override');
        $override->setAttribute('PartName', '/word/numbering.xml');
        $override->setAttribute('ContentType', self::CT_NUMBERING);
        $package->types->documentElement->appendChild($override);
        $package->dirty['[Content_Types].xml'] = $package->types;

        return $package->numbering = $numbering;
    }

    /**
     * A paragraph with one line of plain text, formatted like the
     * placeholder paragraph it replaces.
     */
    private function docxTextParagraph(\DOMDocument $doc, \DOMElement $target, string $text): \DOMElement {
        $p = $doc->createElementNS(self::W, 'w:p');
        $pPr = $this->firstChildNS($target, self::W, 'pPr');
        if ($pPr !== null) {
            $p->appendChild($pPr->cloneNode(true));
        }
        $r = $doc->createElementNS(self::W, 'w:r');
        $rPr = $this->docxPlaceholderRunProps($target);
        if ($rPr !== null) {
            $r->appendChild($rPr->cloneNode(true));
        }
        foreach (explode("\t", $text) as $i => $part) {
            if ($i > 0) {
                $r->appendChild($doc->createElementNS(self::W, 'w:tab'));
            }
            $t = $doc->createElementNS(self::W, 'w:t');
            $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $t->appendChild($doc->createTextNode($part));
            $r->appendChild($t);
        }
        $p->appendChild($r);
        return $p;
    }

    /**
     * A table cell must end with a paragraph.
     */
    private function ensureDocxCellEndsWithParagraph(\DOMDocument $doc, \DOMNode $parent): void {
        if (!($parent instanceof \DOMElement) || $parent->namespaceURI !== self::W || $parent->localName !== 'tc') {
            return;
        }
        $last = null;
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $last = $child;
            }
        }
        if ($last === null || $last->localName !== 'p') {
            $parent->appendChild($doc->createElementNS(self::W, 'w:p'));
        }
    }

    private function docxParagraphText(\DOMElement $p): string {
        $text = '';
        foreach ($p->getElementsByTagNameNS(self::W, 't') as $t) {
            if ($this->closestAncestor($t, self::W, 'p') === $p) {
                $text .= $t->textContent;
            }
        }
        return $text;
    }

    // ---------------------------------------------------------------
    // ODT
    // ---------------------------------------------------------------

    /**
     * Replace placeholder-only paragraphs in content.xml ($xml) with the
     * phrases' content. styles.xml, pictures and the manifest of the
     * template package ($zip) are updated as needed (see composeDocx() for
     * $parts).
     *
     * @param array{path: string, mime_type: string}[] $phrases
     * @param array<string, string> $parts
     */
    public function composeOdt(\ZipArchive $zip, string $xml, array $phrases, array &$parts): string {
        if (!$this->containsPlaceholder($xml)) {
            return $xml;
        }
        $doc = $this->loadXml($xml);
        $targets = [];
        foreach (['p', 'h'] as $name) {
            foreach ($doc->getElementsByTagNameNS(self::TEXT, $name) as $p) {
                if ($this->isPlaceholderOnly($p->textContent)) {
                    $targets[] = $p;
                }
            }
        }
        if ($targets === []) {
            return $xml;
        }

        $package = new \stdClass();
        $package->zip = $zip;
        $package->content = $doc;
        $package->styles = $this->loadPart($zip, 'styles.xml', $parts);
        $package->manifest = $this->loadPart($zip, 'META-INF/manifest.xml', $parts);
        $package->dirty = [];

        $contents = [];
        foreach (array_values($phrases) as $index => $phrase) {
            if ($this->isHtml($phrase['mime_type'])) {
                $contents[] = [['blocks' => $this->htmlBlocks($phrase), 'index' => $index]];
                continue;
            }
            $nodes = $this->isOdt($phrase['mime_type']) ? $this->odtPhraseNodes($package, $phrase['path'], $index) : null;
            $contents[] = $nodes ?? $this->plainParagraphs($phrase);
        }

        foreach ($targets as $target) {
            $parent = $target->parentNode;
            foreach ($contents as $content) {
                foreach ($content as $item) {
                    if (is_array($item)) {
                        foreach ($this->odtRichNodes($package, $doc, $target, $item['blocks'], $item['index']) as $node) {
                            $parent->insertBefore($node, $target);
                        }
                        continue;
                    }
                    if (is_string($item)) {
                        $node = $doc->createElementNS(self::TEXT, 'text:p');
                        if ($target->hasAttributeNS(self::TEXT, 'style-name')) {
                            $node->setAttributeNS(self::TEXT, 'text:style-name', $target->getAttributeNS(self::TEXT, 'style-name'));
                        }
                        foreach (explode("\t", $item) as $i => $part) {
                            if ($i > 0) {
                                $node->appendChild($doc->createElementNS(self::TEXT, 'text:tab'));
                            }
                            $node->appendChild($doc->createTextNode($part));
                        }
                    } else {
                        $node = $doc->importNode($item, true);
                    }
                    $parent->insertBefore($node, $target);
                }
            }
            $parent->removeChild($target);
            if ($parent instanceof \DOMElement && $parent->namespaceURI === self::TABLE
                && $parent->localName === 'table-cell' && $this->firstChildNS($parent, self::TEXT, 'p') === null) {
                $parent->appendChild($doc->createElementNS(self::TEXT, 'text:p'));
            }
        }

        foreach ($package->dirty as $name => $part) {
            $parts[$name] = $part->saveXML();
        }

        return $doc->saveXML();
    }

    /**
     * The body content of an ODT phrase, with its automatic styles (renamed),
     * missing common styles and pictures already copied into the template.
     *
     * @return \DOMElement[]|null null when the phrase cannot be read as ODT
     */
    private function odtPhraseNodes(\stdClass $package, string $path, int $index): ?array {
        $phraseZip = new \ZipArchive();
        if ($phraseZip->open($path) !== true) {
            return null;
        }
        try {
            $contentXml = $phraseZip->getFromName('content.xml');
            if ($contentXml === false) {
                return null;
            }
            $doc = $this->loadXml($contentXml);
            $text = $doc->getElementsByTagNameNS(self::OFFICE, 'text')->item(0);
            if ($text === null) {
                return null;
            }

            $nodes = [];
            foreach ($text->childNodes as $child) {
                if ($child instanceof \DOMElement
                    && in_array($child->localName, self::ODT_BODY_KEEP[$child->namespaceURI] ?? [], true)) {
                    $nodes[] = $child;
                }
            }

            // Automatic styles: copy under per-phrase names.
            $prefix = 'OcPh' . $index . '_';
            $autoStyles = [];
            $autoNames = [];
            $phraseAuto = $doc->getElementsByTagNameNS(self::OFFICE, 'automatic-styles')->item(0);
            if ($phraseAuto !== null) {
                foreach ($phraseAuto->childNodes as $style) {
                    if ($style instanceof \DOMElement && $style->hasAttributeNS(self::STYLE, 'name')) {
                        $autoStyles[] = $style;
                        $autoNames[$style->getAttributeNS(self::STYLE, 'name')] = true;
                    }
                }
            }
            $renameRoots = array_merge($nodes, $autoStyles);
            foreach ($renameRoots as $root) {
                $this->renameOdtStyleRefs($root, $autoNames, $prefix);
            }
            if ($autoStyles !== []) {
                $templateAuto = $this->odtAutomaticStyles($package->content);
                foreach ($autoStyles as $style) {
                    $style->setAttributeNS(self::STYLE, 'style:name', $prefix . $style->getAttributeNS(self::STYLE, 'name'));
                    // A master page on a copied style would start a new page.
                    $style->removeAttributeNS(self::STYLE, 'master-page-name');
                    $templateAuto->appendChild($package->content->importNode($style, true));
                }
            }

            $this->copyOdtCommonStyles($package, $phraseZip, $renameRoots);
            $this->copyOdtPictures($package, $phraseZip, $nodes, $index);

            return $nodes;
        } finally {
            $phraseZip->close();
        }
    }

    /**
     * Prefix every *style-name attribute that refers to one of the phrase's
     * automatic styles.
     *
     * @param array<string, true> $autoNames
     */
    private function renameOdtStyleRefs(\DOMElement $root, array $autoNames, string $prefix): void {
        foreach ($this->elementsOf($root) as $el) {
            foreach (iterator_to_array($el->attributes) as $attr) {
                if (str_ends_with($attr->localName, 'style-name') && isset($autoNames[$attr->value])) {
                    $attr->value = $prefix . $attr->value;
                }
            }
        }
    }

    private function odtAutomaticStyles(\DOMDocument $content): \DOMElement {
        $auto = $content->getElementsByTagNameNS(self::OFFICE, 'automatic-styles')->item(0);
        if ($auto === null) {
            $auto = $content->createElementNS(self::OFFICE, 'office:automatic-styles');
            $body = $content->getElementsByTagNameNS(self::OFFICE, 'body')->item(0);
            $content->documentElement->insertBefore($auto, $body);
        }
        return $auto;
    }

    /**
     * Copy named (common) styles the phrase refers to that the template's
     * styles.xml does not define.
     *
     * @param \DOMElement[] $roots phrase content and its automatic styles
     */
    private function copyOdtCommonStyles(\stdClass $package, \ZipArchive $phraseZip, array $roots): void {
        if ($package->styles === null) {
            return;
        }
        $phraseStylesDoc = $this->loadPart($phraseZip, 'styles.xml');
        $phraseCommon = $phraseStylesDoc?->getElementsByTagNameNS(self::OFFICE, 'styles')->item(0);
        $templateCommon = $package->styles->getElementsByTagNameNS(self::OFFICE, 'styles')->item(0);
        if ($phraseCommon === null || $templateCommon === null) {
            return;
        }

        $key = static fn(\DOMElement $el) => $el->localName . ':' . $el->getAttributeNS(self::STYLE, 'name');
        $existing = [];
        foreach ($templateCommon->childNodes as $el) {
            if ($el instanceof \DOMElement && $el->hasAttributeNS(self::STYLE, 'name')) {
                $existing[$key($el)] = true;
            }
        }
        $available = [];
        foreach ($phraseCommon->childNodes as $el) {
            if ($el instanceof \DOMElement && $el->hasAttributeNS(self::STYLE, 'name')) {
                $available[$el->getAttributeNS(self::STYLE, 'name')][] = $el;
            }
        }

        $queue = [];
        foreach ($roots as $root) {
            foreach ($this->elementsOf($root) as $el) {
                foreach ($el->attributes as $attr) {
                    if (str_ends_with($attr->localName, 'style-name')) {
                        $queue[] = $attr->value;
                    }
                }
            }
        }

        $copied = false;
        $seen = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            foreach ($available[$name] ?? [] as $style) {
                if (isset($existing[$key($style)])) {
                    continue;
                }
                $existing[$key($style)] = true;
                $templateCommon->appendChild($package->styles->importNode($style, true));
                $copied = true;
                foreach (['parent-style-name', 'list-style-name', 'next-style-name'] as $ref) {
                    if ($style->hasAttributeNS(self::STYLE, $ref)) {
                        $queue[] = $style->getAttributeNS(self::STYLE, $ref);
                    }
                }
            }
        }
        if ($copied) {
            $package->dirty['styles.xml'] = $package->styles;
        }
    }

    /**
     * Copy embedded pictures into the template package (Pictures/) and list
     * them in its manifest.
     *
     * @param \DOMElement[] $nodes
     */
    private function copyOdtPictures(\stdClass $package, \ZipArchive $phraseZip, array $nodes, int $index): void {
        $phraseManifest = $this->loadPart($phraseZip, 'META-INF/manifest.xml');
        $mediaTypes = [];
        foreach ($phraseManifest?->getElementsByTagNameNS(self::MANIFEST, 'file-entry') ?? [] as $entry) {
            $mediaTypes[$entry->getAttributeNS(self::MANIFEST, 'full-path')] = $entry->getAttributeNS(self::MANIFEST, 'media-type');
        }

        foreach ($nodes as $node) {
            foreach ($this->elementsOf($node) as $el) {
                if ($el->namespaceURI !== self::DRAW || !$el->hasAttributeNS(self::XLINK, 'href')) {
                    continue;
                }
                $href = $el->getAttributeNS(self::XLINK, 'href');
                if ($href === '' || str_starts_with($href, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
                    continue;
                }
                $source = ltrim(preg_replace('#^\./#', '', $href) ?? $href, '/');
                $content = $phraseZip->getFromName($source);
                if ($content === false) {
                    continue;
                }
                $newPath = 'Pictures/ocphrase' . $index . '_' . basename($source);
                $package->zip->addFromString($newPath, $content);
                $el->setAttributeNS(self::XLINK, 'xlink:href', $newPath);

                if ($package->manifest !== null) {
                    $entry = $package->manifest->createElementNS(self::MANIFEST, 'manifest:file-entry');
                    $entry->setAttributeNS(self::MANIFEST, 'manifest:full-path', $newPath);
                    $entry->setAttributeNS(self::MANIFEST, 'manifest:media-type', $mediaTypes[$source] ?? '');
                    $package->manifest->documentElement->appendChild($entry);
                    $package->dirty['META-INF/manifest.xml'] = $package->manifest;
                }
            }
        }
    }

    // ---------------------------------------------------------------
    // Rich text (PhraseHtml blocks)
    // ---------------------------------------------------------------

    /** Child order of w:pPr / w:rPr (ECMA-376), for inserting properties in place. */
    private const DOCX_PPR_ORDER = ['pStyle', 'keepNext', 'keepLines', 'pageBreakBefore', 'framePr', 'widowControl', 'numPr',
        'suppressLineNumbers', 'pBdr', 'shd', 'tabs', 'suppressAutoHyphens', 'kinsoku', 'wordWrap', 'overflowPunct',
        'topLinePunct', 'autoSpaceDE', 'autoSpaceDN', 'bidi', 'adjustRightInd', 'snapToGrid', 'spacing', 'ind',
        'contextualSpacing', 'mirrorIndents', 'suppressOverlap', 'jc', 'textDirection', 'textAlignment',
        'textboxTightWrap', 'outlineLvl', 'divId', 'cnfStyle', 'rPr', 'sectPr', 'pPrChange'];
    private const DOCX_RPR_ORDER = ['rStyle', 'rFonts', 'b', 'bCs', 'i', 'iCs', 'caps', 'smallCaps', 'strike', 'dstrike',
        'outline', 'shadow', 'emboss', 'imprint', 'noProof', 'snapToGrid', 'vanish', 'webHidden', 'color', 'spacing', 'w',
        'kern', 'position', 'sz', 'szCs', 'highlight', 'u', 'effect', 'bdr', 'shd', 'fitText', 'vertAlign', 'rtl', 'cs',
        'em', 'lang', 'eastAsianLayout', 'specVanish', 'oMath'];

    /** Heading font sizes in half-points (DOCX) and points (ODT). */
    private const HEADING_HALF_POINTS = ['h1' => 32, 'h2' => 28, 'h3' => 26];

    /** Indent per list level / quote, in twips (DOCX) and cm (ODT). */
    private const INDENT_TWIPS = 720;
    private const INDENT_CM = 1.27;

    /**
     * Rich-text blocks as DOCX paragraphs, formatted like the placeholder
     * paragraph (its paragraph properties and the placeholder run's font).
     *
     * @return \DOMElement[]
     */
    private function docxRichParagraphs(\stdClass $package, \DOMDocument $doc, \DOMElement $target, array $blocks, int $index): array {
        $basePPr = $this->firstChildNS($target, self::W, 'pPr');
        $baseRPr = $this->docxPlaceholderRunProps($target);

        $paragraphs = [];
        foreach ($blocks as $block) {
            $p = $doc->createElementNS(self::W, 'w:p');
            $pPr = $basePPr !== null ? $basePPr->cloneNode(true) : $doc->createElementNS(self::W, 'w:pPr');
            $this->removeChildNS($pPr, self::W, 'numPr');

            if ($block['list'] !== null) {
                // One numbering per list and per insertion of the phrase.
                $numId = $this->docxRichNumId($package, $block['list']['kind'], $index . ':' . $block['list']['id'] . ':' . spl_object_id($target));
                if ($numId !== null) {
                    // The list definition carries the indentation.
                    $this->removeChildNS($pPr, self::W, 'ind');
                    $numPr = $doc->createElementNS(self::W, 'w:numPr');
                    $ilvl = $doc->createElementNS(self::W, 'w:ilvl');
                    $ilvl->setAttributeNS(self::W, 'w:val', (string)min(2, $block['list']['level']));
                    $num = $doc->createElementNS(self::W, 'w:numId');
                    $num->setAttributeNS(self::W, 'w:val', $numId);
                    $numPr->appendChild($ilvl);
                    $numPr->appendChild($num);
                    $this->setOrderedChild($pPr, $numPr, self::DOCX_PPR_ORDER);
                }
            }
            $indentLevels = ($block['continued'] ?? 0) + ($block['quote'] ? 1 : 0);
            if ($indentLevels > 0 && $block['list'] === null) {
                $ind = $doc->createElementNS(self::W, 'w:ind');
                $ind->setAttributeNS(self::W, 'w:left', (string)(self::INDENT_TWIPS * $indentLevels));
                $this->setOrderedChild($pPr, $ind, self::DOCX_PPR_ORDER);
            }
            if (isset(self::HEADING_HALF_POINTS[$block['type']])) {
                $this->setOrderedChild($pPr, $doc->createElementNS(self::W, 'w:keepNext'), self::DOCX_PPR_ORDER);
            }
            if ($pPr->hasChildNodes()) {
                $p->appendChild($pPr);
            }

            foreach ($block['runs'] as $run) {
                $r = $doc->createElementNS(self::W, 'w:r');
                $rPr = $baseRPr !== null ? $baseRPr->cloneNode(true) : $doc->createElementNS(self::W, 'w:rPr');
                $heading = self::HEADING_HALF_POINTS[$block['type']] ?? null;
                foreach (['b' => $run['b'] || $heading !== null, 'i' => $run['i'], 'strike' => $run['s']] as $name => $on) {
                    if ($on) {
                        $this->setOrderedChild($rPr, $doc->createElementNS(self::W, 'w:' . $name), self::DOCX_RPR_ORDER);
                    }
                }
                if ($heading !== null) {
                    $sz = $doc->createElementNS(self::W, 'w:sz');
                    $sz->setAttributeNS(self::W, 'w:val', (string)$heading);
                    $this->setOrderedChild($rPr, $sz, self::DOCX_RPR_ORDER);
                }
                if ($run['u']) {
                    $u = $doc->createElementNS(self::W, 'w:u');
                    $u->setAttributeNS(self::W, 'w:val', 'single');
                    $this->setOrderedChild($rPr, $u, self::DOCX_RPR_ORDER);
                }
                if ($rPr->hasChildNodes()) {
                    $r->appendChild($rPr);
                }
                if ($run['text'] === "\n") {
                    $r->appendChild($doc->createElementNS(self::W, 'w:br'));
                } else {
                    $t = $doc->createElementNS(self::W, 'w:t');
                    $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                    $t->appendChild($doc->createTextNode($run['text']));
                    $r->appendChild($t);
                }
                $p->appendChild($r);
            }
            $paragraphs[] = $p;
        }
        return $paragraphs;
    }

    /**
     * numId for a rich-text list ($key: one per list, so each numbered list
     * starts at 1). Bullet and numbered list definitions are added to the
     * template's numbering part once.
     */
    private function docxRichNumId(\stdClass $package, string $kind, string $key): ?string {
        if (isset($package->richNums[$key])) {
            return $package->richNums[$key];
        }
        $numbering = $this->ensureDocxNumbering($package);
        if ($numbering === null) {
            return null;
        }
        $root = $numbering->documentElement;
        $maxNum = 0;
        $maxAbstract = -1;
        $firstNum = null;
        $cleanup = null;
        foreach ($root->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($child->localName === 'num') {
                $maxNum = max($maxNum, (int)$child->getAttributeNS(self::W, 'numId'));
                $firstNum ??= $child;
            } elseif ($child->localName === 'abstractNum') {
                $maxAbstract = max($maxAbstract, (int)$child->getAttributeNS(self::W, 'abstractNumId'));
            } elseif ($child->localName === 'numIdMacAtCleanup') {
                $cleanup = $child;
            }
        }

        if (!isset($package->richAbstracts[$kind])) {
            $abstractId = (string)($maxAbstract + 1);
            $abstract = $numbering->createElementNS(self::W, 'w:abstractNum');
            $abstract->setAttributeNS(self::W, 'w:abstractNumId', $abstractId);
            $nsid = $numbering->createElementNS(self::W, 'w:nsid');
            $nsid->setAttributeNS(self::W, 'w:val', strtoupper(bin2hex(random_bytes(4))));
            $abstract->appendChild($nsid);
            $multi = $numbering->createElementNS(self::W, 'w:multiLevelType');
            $multi->setAttributeNS(self::W, 'w:val', 'hybridMultilevel');
            $abstract->appendChild($multi);
            $bullets = ['•', '◦', '▪'];
            for ($level = 0; $level < 3; $level++) {
                $lvl = $numbering->createElementNS(self::W, 'w:lvl');
                $lvl->setAttributeNS(self::W, 'w:ilvl', (string)$level);
                foreach ([
                    'start'   => '1',
                    'numFmt'  => $kind === 'ol' ? 'decimal' : 'bullet',
                    'lvlText' => $kind === 'ol' ? '%' . ($level + 1) . '.' : $bullets[$level],
                    'lvlJc'   => 'left',
                ] as $name => $val) {
                    $el = $numbering->createElementNS(self::W, 'w:' . $name);
                    $el->setAttributeNS(self::W, 'w:val', $val);
                    $lvl->appendChild($el);
                }
                $pPr = $numbering->createElementNS(self::W, 'w:pPr');
                $ind = $numbering->createElementNS(self::W, 'w:ind');
                $ind->setAttributeNS(self::W, 'w:left', (string)(self::INDENT_TWIPS * ($level + 1)));
                $ind->setAttributeNS(self::W, 'w:hanging', '360');
                $pPr->appendChild($ind);
                $lvl->appendChild($pPr);
                $abstract->appendChild($lvl);
            }
            $root->insertBefore($abstract, $firstNum ?? $cleanup);
            $package->richAbstracts[$kind] = $abstractId;
        }

        $numId = (string)($maxNum + 1);
        $num = $numbering->createElementNS(self::W, 'w:num');
        $num->setAttributeNS(self::W, 'w:numId', $numId);
        $abstractRef = $numbering->createElementNS(self::W, 'w:abstractNumId');
        $abstractRef->setAttributeNS(self::W, 'w:val', $package->richAbstracts[$kind]);
        $num->appendChild($abstractRef);
        // Lists sharing a definition continue each other's numbering unless
        // told to restart.
        for ($level = 0; $level < 3; $level++) {
            $override = $numbering->createElementNS(self::W, 'w:lvlOverride');
            $override->setAttributeNS(self::W, 'w:ilvl', (string)$level);
            $start = $numbering->createElementNS(self::W, 'w:startOverride');
            $start->setAttributeNS(self::W, 'w:val', '1');
            $override->appendChild($start);
            $num->appendChild($override);
        }
        $root->insertBefore($num, $cleanup);

        $package->dirty['word/numbering.xml'] = $numbering;
        return $package->richNums[$key] = $numId;
    }

    /**
     * Rich-text blocks as ODT paragraphs / lists, in the placeholder
     * paragraph's style; formatting via automatic styles added to content.xml.
     *
     * @return \DOMElement[]
     */
    private function odtRichNodes(\stdClass $package, \DOMDocument $doc, \DOMElement $target, array $blocks, int $index): array {
        $baseStyle = $target->hasAttributeNS(self::TEXT, 'style-name') ? $target->getAttributeNS(self::TEXT, 'style-name') : null;
        $nodes = [];
        $seenLists = [];
        foreach ($blocks as $block) {
            $p = $doc->createElementNS(self::TEXT, 'text:p');
            $indent = ($block['continued'] ?? 0) + ($block['quote'] ? 1 : 0);
            $style = $indent > 0 && $block['list'] === null ? $this->odtIndentStyle($package, $baseStyle, $indent) : $baseStyle;
            if ($style !== null) {
                $p->setAttributeNS(self::TEXT, 'text:style-name', $style);
            }
            $heading = self::HEADING_HALF_POINTS[$block['type']] ?? null;
            foreach ($block['runs'] as $run) {
                if ($run['text'] === "\n") {
                    $p->appendChild($doc->createElementNS(self::TEXT, 'text:line-break'));
                    continue;
                }
                $textStyle = $this->odtTextStyle($package, $run['b'] || $heading !== null, $run['i'], $run['u'], $run['s'], $heading !== null ? $heading / 2 : null);
                if ($textStyle === null) {
                    $p->appendChild($doc->createTextNode($run['text']));
                } else {
                    $span = $doc->createElementNS(self::TEXT, 'text:span');
                    $span->setAttributeNS(self::TEXT, 'text:style-name', $textStyle);
                    $span->appendChild($doc->createTextNode($run['text']));
                    $p->appendChild($span);
                }
            }

            if ($block['list'] === null) {
                $nodes[] = $p;
                continue;
            }
            // One text:list per item, nested to its level; numbering continues
            // within the same list.
            $key = $index . ':' . $block['list']['id'];
            $node = $p;
            for ($level = $block['list']['level']; $level >= 0; $level--) {
                $item = $doc->createElementNS(self::TEXT, 'text:list-item');
                $item->appendChild($node);
                $list = $doc->createElementNS(self::TEXT, 'text:list');
                $list->appendChild($item);
                $node = $list;
            }
            $node->setAttributeNS(self::TEXT, 'text:style-name', $this->odtListStyle($package, $block['list']['kind']));
            if (isset($seenLists[$key])) {
                $node->setAttributeNS(self::TEXT, 'text:continue-numbering', 'true');
            }
            $seenLists[$key] = true;
            $nodes[] = $node;
        }
        return $nodes;
    }

    private function odtTextStyle(\stdClass $package, bool $b, bool $i, bool $u, bool $s, ?float $sizePt): ?string {
        if (!$b && !$i && !$u && !$s && $sizePt === null) {
            return null;
        }
        $name = 'OcPhT_' . ($b ? 'b' : '') . ($i ? 'i' : '') . ($u ? 'u' : '') . ($s ? 's' : '') . ($sizePt !== null ? (int)$sizePt : '');
        if (!isset($package->richStyles[$name])) {
            $doc = $package->content;
            $style = $doc->createElementNS(self::STYLE, 'style:style');
            $style->setAttributeNS(self::STYLE, 'style:name', $name);
            $style->setAttributeNS(self::STYLE, 'style:family', 'text');
            $props = $doc->createElementNS(self::STYLE, 'style:text-properties');
            if ($b) {
                $props->setAttributeNS(self::FO, 'fo:font-weight', 'bold');
            }
            if ($i) {
                $props->setAttributeNS(self::FO, 'fo:font-style', 'italic');
            }
            if ($u) {
                $props->setAttributeNS(self::STYLE, 'style:text-underline-style', 'solid');
                $props->setAttributeNS(self::STYLE, 'style:text-underline-width', 'auto');
                $props->setAttributeNS(self::STYLE, 'style:text-underline-color', 'font-color');
            }
            if ($s) {
                $props->setAttributeNS(self::STYLE, 'style:text-line-through-style', 'solid');
            }
            if ($sizePt !== null) {
                $props->setAttributeNS(self::FO, 'fo:font-size', $sizePt . 'pt');
            }
            $style->appendChild($props);
            $this->odtAutomaticStyles($doc)->appendChild($style);
            $package->richStyles[$name] = true;
        }
        return $name;
    }

    private function odtIndentStyle(\stdClass $package, ?string $baseStyle, int $levels): string {
        $name = 'OcPhIndent' . $levels . ($baseStyle !== null ? '_' . preg_replace('/[^A-Za-z0-9_]/', '', $baseStyle) : '');
        if (!isset($package->richStyles[$name])) {
            $doc = $package->content;
            $style = $doc->createElementNS(self::STYLE, 'style:style');
            $style->setAttributeNS(self::STYLE, 'style:name', $name);
            $style->setAttributeNS(self::STYLE, 'style:family', 'paragraph');
            // An automatic style cannot be a parent; then fall back to Standard.
            $parent = $baseStyle !== null && !$this->isOdtAutomaticStyle($doc, $baseStyle) ? $baseStyle : 'Standard';
            $style->setAttributeNS(self::STYLE, 'style:parent-style-name', $parent);
            $props = $doc->createElementNS(self::STYLE, 'style:paragraph-properties');
            $props->setAttributeNS(self::FO, 'fo:margin-left', round(self::INDENT_CM * $levels, 3) . 'cm');
            $style->appendChild($props);
            $this->odtAutomaticStyles($doc)->appendChild($style);
            $package->richStyles[$name] = true;
        }
        return $name;
    }

    private function odtListStyle(\stdClass $package, string $kind): string {
        $name = $kind === 'ol' ? 'OcPhListNumber' : 'OcPhListBullet';
        if (!isset($package->richStyles[$name])) {
            $doc = $package->content;
            $listStyle = $doc->createElementNS(self::TEXT, 'text:list-style');
            $listStyle->setAttributeNS(self::STYLE, 'style:name', $name);
            $bullets = ['•', '◦', '▪'];
            for ($level = 1; $level <= 3; $level++) {
                $levelStyle = $doc->createElementNS(self::TEXT, $kind === 'ol' ? 'text:list-level-style-number' : 'text:list-level-style-bullet');
                $levelStyle->setAttributeNS(self::TEXT, 'text:level', (string)$level);
                if ($kind === 'ol') {
                    $levelStyle->setAttributeNS(self::STYLE, 'style:num-format', '1');
                    $levelStyle->setAttributeNS(self::STYLE, 'style:num-suffix', '.');
                } else {
                    $levelStyle->setAttributeNS(self::TEXT, 'text:bullet-char', $bullets[$level - 1]);
                }
                $props = $doc->createElementNS(self::STYLE, 'style:list-level-properties');
                $props->setAttributeNS(self::TEXT, 'text:list-level-position-and-space-mode', 'label-alignment');
                $align = $doc->createElementNS(self::STYLE, 'style:list-level-label-alignment');
                $align->setAttributeNS(self::TEXT, 'text:label-followed-by', 'listtab');
                $align->setAttributeNS(self::TEXT, 'text:list-tab-stop-position', round(self::INDENT_CM * $level, 3) . 'cm');
                $align->setAttributeNS(self::FO, 'fo:text-indent', '-0.635cm');
                $align->setAttributeNS(self::FO, 'fo:margin-left', round(self::INDENT_CM * $level, 3) . 'cm');
                $props->appendChild($align);
                $levelStyle->appendChild($props);
                $listStyle->appendChild($levelStyle);
            }
            $this->odtAutomaticStyles($doc)->appendChild($listStyle);
            $package->richStyles[$name] = true;
        }
        return $name;
    }

    private function isOdtAutomaticStyle(\DOMDocument $doc, string $name): bool {
        $auto = $doc->getElementsByTagNameNS(self::OFFICE, 'automatic-styles')->item(0);
        foreach ($auto?->childNodes ?? [] as $style) {
            if ($style instanceof \DOMElement && $style->getAttributeNS(self::STYLE, 'name') === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * The run properties of the placeholder text (editors often leave empty
     * runs around it), or null.
     */
    private function docxPlaceholderRunProps(\DOMElement $target): ?\DOMElement {
        foreach ($target->getElementsByTagNameNS(self::W, 't') as $t) {
            if (trim($t->textContent) !== '' && $t->parentNode instanceof \DOMElement) {
                return $this->firstChildNS($t->parentNode, self::W, 'rPr');
            }
        }
        return null;
    }

    /**
     * Replace or insert $child in $parent, keeping the schema's child order.
     *
     * @param string[] $order local names in schema order
     */
    private function setOrderedChild(\DOMElement $parent, \DOMElement $child, array $order): void {
        $this->removeChildNS($parent, $child->namespaceURI, $child->localName);
        $position = array_search($child->localName, $order, true);
        foreach ($parent->childNodes as $existing) {
            if ($existing instanceof \DOMElement) {
                $existingPosition = array_search($existing->localName, $order, true);
                if ($existingPosition !== false && $position !== false && $existingPosition > $position) {
                    $parent->insertBefore($child, $existing);
                    return;
                }
            }
        }
        $parent->appendChild($child);
    }

    private function removeChildNS(\DOMElement $parent, string $ns, string $localName): void {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === $ns && $child->localName === $localName) {
                $parent->removeChild($child);
            }
        }
    }

    // ---------------------------------------------------------------
    // Plain text
    // ---------------------------------------------------------------

    /**
     * The phrase's paragraphs as plain text lines (tabs as "\t").
     *
     * @param array{path: string, mime_type: string} $phrase
     * @return string[]
     */
    private function plainParagraphs(array $phrase): array {
        if ($this->isHtml($phrase['mime_type'])) {
            return PhraseHtml::plainLines((string)file_get_contents($phrase['path']));
        }
        if (str_starts_with($phrase['mime_type'], 'text/plain')) {
            $text = file_get_contents($phrase['path']);
            return $text === false ? [] : explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        }
        $zip = new \ZipArchive();
        if ($zip->open($phrase['path']) !== true) {
            return [];
        }
        try {
            if ($this->isDocx($phrase['mime_type'])) {
                $xml = $zip->getFromName('word/document.xml');
                return $xml === false ? [] : $this->docxPlainParagraphs($this->loadXml($xml));
            }
            if ($this->isOdt($phrase['mime_type'])) {
                $xml = $zip->getFromName('content.xml');
                return $xml === false ? [] : $this->odtPlainParagraphs($this->loadXml($xml));
            }
            return [];
        } finally {
            $zip->close();
        }
    }

    /** @return string[] */
    private function docxPlainParagraphs(\DOMDocument $doc): array {
        $lines = [];
        $body = $doc->getElementsByTagNameNS(self::W, 'body')->item(0);
        if ($body === null) {
            return [];
        }
        foreach ($body->getElementsByTagNameNS(self::W, 'p') as $p) {
            $line = '';
            foreach ($this->elementsOf($p) as $el) {
                if ($el->namespaceURI !== self::W || $this->closestAncestor($el, self::W, 'p') !== $p) {
                    continue;
                }
                $line .= match ($el->localName) {
                    't' => $el->textContent,
                    'tab' => "\t",
                    'br', 'cr' => "\n",
                    default => '',
                };
            }
            array_push($lines, ...explode("\n", $line));
        }
        return $lines;
    }

    /** @return string[] */
    private function odtPlainParagraphs(\DOMDocument $doc): array {
        $lines = [];
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('office', self::OFFICE);
        $xp->registerNamespace('text', self::TEXT);
        foreach ($xp->query('//office:text//text:p | //office:text//text:h') as $p) {
            array_push($lines, ...explode("\n", $this->odtInlineText($p)));
        }
        return $lines;
    }

    private function odtInlineText(\DOMNode $node): string {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text .= $child->data;
            } elseif ($child instanceof \DOMElement && $child->namespaceURI === self::TEXT) {
                $text .= match ($child->localName) {
                    's' => str_repeat(' ', max(1, (int)($child->getAttributeNS(self::TEXT, 'c') ?: 1))),
                    'tab' => "\t",
                    'line-break' => "\n",
                    'note' => '',
                    default => $this->odtInlineText($child),
                };
            }
        }
        return $text;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function isPlaceholderOnly(string $text): bool {
        $names = implode('|', array_map(fn($p) => preg_quote($p, '/'), self::PLACEHOLDERS));
        return preg_match('/^\s*\{\{\s*(' . $names . ')\s*\}\}\s*$/iu', $text) === 1;
    }

    private function isDocx(string $mimeType): bool {
        return str_contains($mimeType, 'wordprocessingml');
    }

    private function isHtml(string $mimeType): bool {
        return str_starts_with($mimeType, 'text/html');
    }

    /**
     * @param array{path: string, mime_type: string} $phrase
     */
    private function htmlBlocks(array $phrase): array {
        return PhraseHtml::blocks((string)file_get_contents($phrase['path']));
    }

    private function isOdt(string $mimeType): bool {
        return str_contains($mimeType, 'opendocument.text');
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
     * @param array<string, string> $parts updated parts that take precedence over the ZIP
     */
    private function loadPart(\ZipArchive $zip, string $name, array $parts = []): ?\DOMDocument {
        $xml = $parts[$name] ?? $zip->getFromName($name);
        if ($xml === false) {
            return null;
        }
        try {
            return $this->loadXml($xml);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * $root and all its descendant elements (a snapshot, safe to modify).
     *
     * @return \DOMElement[]
     */
    private function elementsOf(\DOMElement $root): array {
        return [$root, ...iterator_to_array($root->getElementsByTagName('*'), false)];
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
