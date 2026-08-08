<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller\PublicApi;

use OCA\OpenCase\Service\CaseExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Shared response-formatting infrastructure for PublicDataApiController
 * endpoints: XML/JSON content negotiation and a generic nested-array-to-XML
 * serializer (namespace https://opencase.dk/case, matching
 * appinfo/schema/case-export.xsd).
 *
 * Authentication for all subclasses is handled upstream by
 * PublicDataApiMiddleware (bearer SAML assertion token); the request runs
 * as the Nextcloud user mapped to the matched API client.
 */
abstract class AbstractPublicDataApiController extends Controller implements PublicDataApiController {

    /** Maps a list wrapper field name to its per-item element name, for cases
     *  the default (strip a trailing "s") doesn't cover. */
    private const XML_LIST_ITEM_NAMES = [
        'WorkflowHistory' => 'Workflow',
    ];

    public function __construct(
        string $appName,
        IRequest $request,
        protected IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    protected function actingUserId(): ?string {
        return $this->userSession->getUser()?->getUID();
    }

    /** Return the first non-empty value found among the given keys (checks both PascalCase and snake_case forms). */
    protected function bodyValue(array $params, string ...$keys): ?string {
        foreach ($keys as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                return (string)$params[$key];
            }
        }
        return null;
    }

    /**
     * Whether any of the given keys is present in the body at all — used to
     * distinguish "field omitted, leave unchanged" from "field explicitly
     * sent as empty/null, clear it" on update endpoints.
     */
    protected function bodyKeyProvided(array $params, string ...$keys): bool {
        foreach ($keys as $key) {
            if (array_key_exists($key, $params)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Request body as a flat key => value array, for POST/PUT endpoints.
     * Nextcloud's IRequest::getParams() only auto-decodes JSON and
     * form-encoded bodies — for an XML Content-Type (matching the shape
     * this API's GET responses use) this parses the raw body's top-level
     * elements instead (e.g. <Case><Title>...</Title></Case> → ['Title' => ...]).
     *
     * @return array<string, string>
     */
    protected function requestBodyParams(): array {
        if (str_contains(strtolower($this->request->getHeader('Content-Type')), 'xml')) {
            return $this->parseXmlBodyParams();
        }

        return $this->request->getParams();
    }

    /** @return array<string, string> */
    private function parseXmlBodyParams(): array {
        $body = file_get_contents('php://input');
        if ($body === false || trim($body) === '') {
            return [];
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        $doc    = new \DOMDocument();
        $loaded = $doc->loadXML($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if (!$loaded || $doc->documentElement === null) {
            return [];
        }

        $params = [];
        foreach ($doc->documentElement->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $params[$node->localName] = trim($node->textContent);
            }
        }
        return $params;
    }

    /**
     * Parse a request body containing top-level scalar fields plus one list
     * field (e.g. <AddThings><CaseNumber>...</CaseNumber><Things><Thing>...
     * </Thing>...</Things></AddThings>) — for bulk endpoints that take
     * several items in one request (participants, caseworkers, ...).
     *
     * Handles both JSON (native nested arrays via getParams()) and XML
     * ($listWrapperTag/$listItemTag elements, always parsed as a list
     * regardless of item count — avoids the classic single-vs-multiple XML
     * ambiguity).
     *
     * @return array{fields: array<string, string>, items: list<array<string, string>>}
     */
    protected function requestBodyWithList(string $listWrapperTag, string $listItemTag): array {
        if (str_contains(strtolower($this->request->getHeader('Content-Type')), 'xml')) {
            return $this->parseXmlBodyWithList($listWrapperTag, $listItemTag);
        }

        $params = $this->request->getParams();
        $items  = $params[$listWrapperTag] ?? $params[lcfirst($listWrapperTag)] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        // A JSON body with a single item object (not wrapped in a list) is
        // normalized to a one-item list.
        if (!empty($items) && !isset($items[0])) {
            $items = [$items];
        }
        unset($params[$listWrapperTag], $params[lcfirst($listWrapperTag)]);

        return ['fields' => $params, 'items' => array_values($items)];
    }

    /** @return array{fields: array<string, string>, items: list<array<string, string>>} */
    private function parseXmlBodyWithList(string $listWrapperTag, string $listItemTag): array {
        $body = file_get_contents('php://input');
        if ($body === false || trim($body) === '') {
            return ['fields' => [], 'items' => []];
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        $doc    = new \DOMDocument();
        $loaded = $doc->loadXML($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if (!$loaded || $doc->documentElement === null) {
            return ['fields' => [], 'items' => []];
        }

        $fields = [];
        $items  = [];
        foreach ($doc->documentElement->childNodes as $node) {
            if (!($node instanceof \DOMElement)) {
                continue;
            }
            if ($node->localName === $listWrapperTag) {
                foreach ($node->childNodes as $itemNode) {
                    if ($itemNode instanceof \DOMElement && $itemNode->localName === $listItemTag) {
                        $item = [];
                        foreach ($itemNode->childNodes as $fieldNode) {
                            if ($fieldNode instanceof \DOMElement) {
                                $item[$fieldNode->localName] = trim($fieldNode->textContent);
                            }
                        }
                        $items[] = $item;
                    }
                }
                continue;
            }
            $fields[$node->localName] = trim($node->textContent);
        }

        return ['fields' => $fields, 'items' => $items];
    }

    protected function wantsXml(): bool {
        return str_contains(strtolower($this->request->getHeader('Accept')), 'xml');
    }

    protected function errorResponse(string $message, int $status): Response {
        if ($this->wantsXml()) {
            $doc = new \DOMDocument('1.0', 'UTF-8');
            $el  = $doc->createElementNS(CaseExportService::NS, 'Error');
            $el->appendChild($doc->createTextNode($message));
            $doc->appendChild($el);
            return new DataDisplayResponse($doc->saveXML(), $status, ['Content-Type' => 'application/xml; charset=UTF-8']);
        }

        return new JSONResponse(['error' => $message], $status);
    }

    /**
     * Respond with a single root element (e.g. Case, Document) wrapping $fields.
     *
     * @param array<string, mixed> $fields
     */
    protected function respond(string $rootName, array $fields, int $status = Http::STATUS_OK): Response {
        if ($this->wantsXml()) {
            $doc    = new \DOMDocument('1.0', 'UTF-8');
            $doc->formatOutput = true;
            $rootEl = $doc->createElementNS(CaseExportService::NS, $rootName);
            $doc->appendChild($rootEl);
            $this->appendFieldsRecursive($doc, $rootEl, $fields);

            return new DataDisplayResponse($doc->saveXML(), $status, ['Content-Type' => 'application/xml; charset=UTF-8']);
        }

        return new JSONResponse([$rootName => $fields], $status);
    }

    /**
     * Respond with a root element (e.g. Documents) wrapping a list of items
     * (e.g. Document), each named $itemName.
     *
     * @param list<array<string, mixed>> $items
     */
    protected function respondList(string $rootName, string $itemName, array $items, int $status = Http::STATUS_OK): Response {
        if ($this->wantsXml()) {
            $doc    = new \DOMDocument('1.0', 'UTF-8');
            $doc->formatOutput = true;
            $rootEl = $doc->createElementNS(CaseExportService::NS, $rootName);
            $doc->appendChild($rootEl);
            foreach ($items as $item) {
                $itemEl = $doc->createElementNS(CaseExportService::NS, $itemName);
                $rootEl->appendChild($itemEl);
                $this->appendFieldsRecursive($doc, $itemEl, $item);
            }

            return new DataDisplayResponse($doc->saveXML(), $status, ['Content-Type' => 'application/xml; charset=UTF-8']);
        }

        return new JSONResponse([$rootName => $items], $status);
    }

    /**
     * Append $fields as children of $parentEl. Scalar values become leaf
     * elements; a value that is itself a list of arrays becomes a wrapper
     * element containing one child per item (item name = XML_LIST_ITEM_NAMES
     * override, or the field name with a trailing "s" stripped).
     *
     * @param array<string, mixed> $fields
     */
    protected function appendFieldsRecursive(\DOMDocument $doc, \DOMElement $parentEl, array $fields): void {
        foreach ($fields as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $wrapEl = $doc->createElementNS(CaseExportService::NS, $key);
                $parentEl->appendChild($wrapEl);

                $itemName = self::XML_LIST_ITEM_NAMES[$key] ?? rtrim($key, 's');
                foreach ($value as $item) {
                    $itemEl = $doc->createElementNS(CaseExportService::NS, $itemName);
                    $wrapEl->appendChild($itemEl);
                    $this->appendFieldsRecursive($doc, $itemEl, $item);
                }
                continue;
            }

            $el = $doc->createElementNS(CaseExportService::NS, (string)$key);
            $el->appendChild($doc->createTextNode($this->xmlScalarValue($value)));
            $parentEl->appendChild($el);
        }
    }

    /**
     * (string) casts bool false to "" and true to "1" — indistinguishable
     * from an empty field in XML. Render booleans as "true"/"false" instead,
     * matching the convention used elsewhere (e.g. CaseExportService).
     */
    private function xmlScalarValue(mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string)$value;
    }
}
