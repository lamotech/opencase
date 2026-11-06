<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

/**
 * The rich text of a phrase (opencase_phrases.content) — the HTML produced
 * by the RichTextEditor (TipTap StarterKit + Underline).
 *
 * sanitize() keeps only the formatting the editor offers: paragraphs,
 * headings 1–3, bullet/numbered lists (nested), block quotes, line breaks,
 * bold, italic, underline and strike-through — no attributes.
 *
 * blocks() turns that HTML into a flat list of paragraphs with formatted
 * runs, which PhraseComposer renders as DOCX/ODT paragraphs or plain text.
 */
class PhraseHtml {

    private const BLOCK_TAGS = ['p', 'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote'];
    private const INLINE_TAGS = ['strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'br'];

    /**
     * HTML reduced to the supported tags, without attributes.
     */
    public static function sanitize(?string $html): string {
        $html = trim((string)$html);
        if ($html === '') {
            return '';
        }
        $root = self::load($html);
        if ($root === null) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }
        $out = self::sanitizeChildren($root);
        // An editor that was left empty serialises as <p></p>.
        return trim(strip_tags($out)) === '' ? '' : $out;
    }

    /**
     * Whether the HTML has any text.
     */
    public static function hasText(?string $html): bool {
        return trim(html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== '';
    }

    /**
     * The HTML as paragraphs:
     *   type  — p | h1 | h2 | h3
     *   list  — null, or {kind: ul|ol, id: int (one per list), level: 0..}
     *   quote — inside a block quote
     *   runs  — [{text, b, i, u, s}]; a line break is a run with text "\n"
 *   continued — (optional) list depth of a list item's 2nd+ paragraph:
 *               indented like the item, without bullet/number
     *
     * @return array<int, array{type: string, list: ?array{kind: string, id: int, level: int}, quote: bool, runs: array<int, array{text: string, b: bool, i: bool, u: bool, s: bool}>}>
     */
    public static function blocks(?string $html): array {
        $root = self::load((string)$html);
        if ($root === null) {
            return [];
        }
        $blocks = [];
        $listId = 0;
        self::collectBlocks($root, $blocks, [], false, $listId);
        return self::tidy($blocks);
    }

    /**
     * Plain text lines: one per paragraph, list items prefixed with "• " or
     * "1. ", line breaks kept as separate lines.
     *
     * @return string[]
     */
    public static function plainLines(?string $html): array {
        $lines = [];
        $counters = [];
        foreach (self::blocks($html) as $block) {
            $prefix = '';
            if ($block['list'] !== null) {
                $indent = str_repeat('    ', $block['list']['level']);
                if ($block['list']['kind'] === 'ol') {
                    $key = $block['list']['id'];
                    $counters[$key] = ($counters[$key] ?? 0) + 1;
                    $prefix = $indent . $counters[$key] . '. ';
                } else {
                    $prefix = $indent . '• ';
                }
            }
            $text = implode('', array_column($block['runs'], 'text'));
            foreach (explode("\n", $prefix . $text) as $line) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    // ---------------------------------------------------------------

    private static function load(string $html): ?\DOMElement {
        if (trim($html) === '') {
            return null;
        }
        $doc = new \DOMDocument();
        $ok = @$doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="oc-root">' . $html . '</div>',
            LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED,
        );
        if (!$ok) {
            return null;
        }
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === 'oc-root') {
                return $div;
            }
        }
        return null;
    }

    private static function sanitizeChildren(\DOMNode $node): string {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $out .= htmlspecialchars($child->data, ENT_QUOTES, 'UTF-8');
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'br') {
                $out .= '<br>';
            } elseif (in_array($tag, self::BLOCK_TAGS, true) || in_array($tag, self::INLINE_TAGS, true)) {
                $tag = match ($tag) { 'b' => 'strong', 'i' => 'em', 'strike', 'del' => 's', default => $tag };
                $out .= '<' . $tag . '>' . self::sanitizeChildren($child) . '</' . $tag . '>';
            } elseif (!in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'template'], true)) {
                // Unknown element (span, a, code …): keep its content only.
                $out .= self::sanitizeChildren($child);
            }
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array{kind: string, id: int}> $lists open lists, outermost first
     */
    private static function collectBlocks(\DOMNode $node, array &$blocks, array $lists, bool $quote, int &$listId): void {
        $pending = null;
        foreach ($node->childNodes as $child) {
            $tag = $child instanceof \DOMElement ? strtolower($child->tagName) : null;

            if ($tag === 'ul' || $tag === 'ol') {
                $pending = self::flush($blocks, $pending);
                self::collectBlocks($child, $blocks, [...$lists, ['kind' => $tag, 'id' => ++$listId]], $quote, $listId);
            } elseif ($tag === 'li') {
                $pending = self::flush($blocks, $pending);
                // A list item holds paragraphs (TipTap) or inline content.
                $before = count($blocks);
                self::collectBlocks($child, $blocks, $lists, $quote, $listId);
                if (count($blocks) === $before) {
                    $blocks[] = self::newBlock('p', $lists, $quote);
                }
                // Only the item's first paragraph carries the bullet/number.
                for ($i = $before + 1, $n = count($blocks); $i < $n; $i++) {
                    if ($blocks[$i]['list'] !== null && $blocks[$i]['list']['level'] === count($lists) - 1) {
                        $blocks[$i]['list'] = null;
                        $blocks[$i]['continued'] = count($lists);
                    }
                }
            } elseif ($tag === 'blockquote') {
                $pending = self::flush($blocks, $pending);
                self::collectBlocks($child, $blocks, $lists, true, $listId);
            } elseif (in_array($tag, ['p', 'h1', 'h2', 'h3'], true)) {
                $pending = self::flush($blocks, $pending);
                $block = self::newBlock($tag, $lists, $quote);
                self::collectRuns($child, $block['runs'], ['b' => false, 'i' => false, 'u' => false, 's' => false]);
                $blocks[] = $block;
            } else {
                // Loose inline content becomes a paragraph of its own.
                $pending ??= self::newBlock('p', $lists, $quote);
                if ($child instanceof \DOMText) {
                    if (trim($child->data) !== '' || $pending['runs'] !== []) {
                        $pending['runs'][] = self::run($child->data, ['b' => false, 'i' => false, 'u' => false, 's' => false]);
                    }
                } elseif ($child instanceof \DOMElement) {
                    self::collectRuns($child, $pending['runs'], ['b' => false, 'i' => false, 'u' => false, 's' => false], true);
                }
            }
        }
        self::flush($blocks, $pending);
    }

    private static function flush(array &$blocks, ?array $pending): ?array {
        if ($pending !== null && $pending['runs'] !== []) {
            $blocks[] = $pending;
        }
        return null;
    }

    /**
     * @param array<int, array{kind: string, id: int}> $lists
     */
    private static function newBlock(string $type, array $lists, bool $quote): array {
        $list = null;
        if ($lists !== []) {
            $innermost = $lists[count($lists) - 1];
            $list = ['kind' => $innermost['kind'], 'id' => $innermost['id'], 'level' => count($lists) - 1];
        }
        return ['type' => $type, 'list' => $list, 'quote' => $quote, 'runs' => []];
    }

    /**
     * @param array{b: bool, i: bool, u: bool, s: bool} $format
     */
    private static function collectRuns(\DOMNode $node, array &$runs, array $format, bool $self = false): void {
        $nodes = $self ? [$node] : iterator_to_array($node->childNodes);
        foreach ($nodes as $child) {
            if ($child instanceof \DOMText) {
                if ($child->data !== '') {
                    $runs[] = self::run($child->data, $format);
                }
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'br') {
                $runs[] = self::run("\n", $format);
                continue;
            }
            $inner = $format;
            match ($tag) {
                'strong', 'b' => $inner['b'] = true,
                'em', 'i' => $inner['i'] = true,
                'u' => $inner['u'] = true,
                's', 'strike', 'del' => $inner['s'] = true,
                default => null,
            };
            self::collectRuns($child, $runs, $inner);
        }
    }

    private static function run(string $text, array $format): array {
        // HTML whitespace collapses; line breaks are explicit "\n" runs (<br>).
        if ($text !== "\n") {
            $text = preg_replace('/[ \t\r\n]+/', ' ', $text) ?? $text;
        }
        return ['text' => $text] + $format;
    }

    /**
     * Drop whitespace at the start and end of each paragraph and merge
     * neighbouring runs with the same formatting.
     */
    private static function tidy(array $blocks): array {
        foreach ($blocks as &$block) {
            $runs = [];
            foreach ($block['runs'] as $run) {
                $last = count($runs) - 1;
                if ($last >= 0 && $run['text'] !== "\n" && $runs[$last]['text'] !== "\n"
                    && array_slice($runs[$last], 1) === array_slice($run, 1)) {
                    $runs[$last]['text'] .= $run['text'];
                } else {
                    $runs[] = $run;
                }
            }
            if ($runs !== [] && $runs[0]['text'] !== "\n") {
                $runs[0]['text'] = ltrim($runs[0]['text']);
            }
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last]['text'] !== "\n") {
                $runs[$last]['text'] = rtrim($runs[$last]['text']);
            }
            $block['runs'] = array_values(array_filter($runs, fn($r) => $r['text'] !== ''));
        }
        unset($block);
        // Empty paragraphs are blank lines — except at the end.
        while ($blocks !== [] && $blocks[count($blocks) - 1]['runs'] === []) {
            array_pop($blocks);
        }
        return $blocks;
    }
}
