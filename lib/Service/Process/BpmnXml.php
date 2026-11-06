<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Process;

/**
 * Parsing and checks of the BPMN 2.0 XML stored for process versions.
 *
 * OpenCase owns the process id: it is always the process_key of the
 * process, so the Flowable definition key never drifts from the OpenCase
 * process, even when a diagram is imported from a file.
 */
class BpmnXml {

    public const NS_MODEL = 'http://www.omg.org/spec/BPMN/20100524/MODEL';
    public const NS_DI = 'http://www.omg.org/spec/BPMN/20100524/DI';
    public const NS_FLOWABLE = 'http://flowable.org/bpmn';
    /** BPMN element types publishing refuses, with why (completing "\"Name\" er …") */
    private const UNSUPPORTED_ELEMENTS = [
        'sendTask' => 'en sendeopgave, som OpenCase ikke understøtter. Brug en serviceopgave med "Send notifikation" eller "Send Digital Post".',
        'receiveTask' => 'en modtageopgave: den venter på en besked, som OpenCase aldrig sender, så processen ville vente for altid. Brug "Vent på dokument" eller en brugeropgave.',
        'businessRuleTask' => 'en forretningsregelopgave (beslutningstabel), som procesmotoren ikke er sat op til. Brug en brugeropgave med udfald og en gateway.',
        'scriptTask' => 'en scriptopgave: scripts kører uden for OpenCases adgangskontrol og log og er ikke tilladt. Brug en serviceopgave.',
        'callActivity' => 'en kaldaktivitet: den kaldte proces kører uden for sagen, så dens opgaver ikke vises, og dens automatiske trin fejler. Brug "Opret undersag" med en proces eller en underproces.',
        'transaction' => 'en transaktion, som OpenCase ikke understøtter. Brug en almindelig underproces.',
        'adHocSubProcess' => 'en ad hoc-underproces, som OpenCase ikke understøtter. Brug en almindelig underproces.',
    ];

    /** Flowable attributes a diagram may use. Others (class, expression, delegateExpression, ...) run code in the engine. */
    private const ALLOWED_FLOWABLE_ATTRIBUTES = [
        'assignee', 'candidateUsers', 'candidateGroups', 'dueDate', 'priority', 'formKey',
        'type', 'topic', 'exclusive', 'async',
    ];
    /** Flowable elements a diagram may use. Others (executionListener, taskListener, ...) run code in the engine. */
    private const ALLOWED_FLOWABLE_ELEMENTS = ['formProperty', 'value', 'field', 'string'];
    private const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';
    private const NS_XMLNS = 'http://www.w3.org/2000/xmlns/';
    /** Words of the expression language that are operators or constants, not variables. */
    private const EXPRESSION_WORDS = ['and', 'or', 'not', 'eq', 'ne', 'lt', 'gt', 'le', 'ge', 'div', 'mod', 'empty'];

    /** fieldType of a form field holding a Nextcloud user id */
    public const USER_FIELD_TYPE = 'user';

    /** Max size of a stored diagram. */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * A new diagram with only a start event.
     */
    public static function initial(string $processKey, string $name): string {
        $key = htmlspecialchars($processKey, ENT_XML1 | ENT_QUOTES);
        $name = htmlspecialchars($name, ENT_XML1 | ENT_QUOTES);
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" xmlns:bpmndi="http://www.omg.org/spec/BPMN/20100524/DI" xmlns:dc="http://www.omg.org/spec/DD/20100524/DC" xmlns:flowable="http://flowable.org/bpmn" id="Definitions_{$key}" targetNamespace="http://opencase.dk/bpmn">
  <bpmn:process id="{$key}" name="{$name}" isExecutable="true">
    <bpmn:startEvent id="StartEvent_1" name="Start" />
  </bpmn:process>
  <bpmndi:BPMNDiagram id="BPMNDiagram_1">
    <bpmndi:BPMNPlane id="BPMNPlane_1" bpmnElement="{$key}">
      <bpmndi:BPMNShape id="StartEvent_1_di" bpmnElement="StartEvent_1">
        <dc:Bounds x="180" y="160" width="36" height="36" />
      </bpmndi:BPMNShape>
    </bpmndi:BPMNPlane>
  </bpmndi:BPMNDiagram>
</bpmn:definitions>

XML;
    }

    /**
     * Check the XML is a BPMN diagram with exactly one process, and make the
     * process id the process key, its name the process name and the process
     * executable. Used for every draft save, so drafts may be incomplete.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(string $xml, string $processKey, string $name): string {
        $doc = self::load($xml);
        $process = self::singleProcess($doc);

        $oldId = $process->getAttribute('id');
        $process->setAttribute('id', $processKey);
        $process->setAttribute('name', $name);
        $process->setAttribute('isExecutable', 'true');

        if ($oldId !== '' && $oldId !== $processKey) {
            // Keep references to the process (diagram plane, pool) pointing at it
            foreach ($doc->getElementsByTagNameNS(self::NS_DI, 'BPMNPlane') as $plane) {
                if ($plane->getAttribute('bpmnElement') === $oldId) {
                    $plane->setAttribute('bpmnElement', $processKey);
                }
            }
            foreach ($doc->getElementsByTagNameNS(self::NS_MODEL, 'participant') as $participant) {
                if ($participant->getAttribute('processRef') === $oldId) {
                    $participant->setAttribute('processRef', $processKey);
                }
            }
        }

        // Automatic steps must not be "exclusive" (Flowable's default): the
        // engine hands out no exclusive external-worker job of a process
        // while it has several, so parallel automatic steps would wait for ever
        foreach ($doc->getElementsByTagNameNS(self::NS_MODEL, 'serviceTask') as $task) {
            if ($task->getAttributeNS(self::NS_FLOWABLE, 'type') === 'external-worker') {
                $task->setAttributeNS(self::NS_FLOWABLE, 'flowable:exclusive', 'false');
            }
        }

        $root = $doc->documentElement;
        if ($root->getAttribute('targetNamespace') === '') {
            $root->setAttribute('targetNamespace', 'http://opencase.dk/bpmn');
        }

        return $doc->saveXML();
    }

    /**
     * Problems that must be fixed before a version can be published. The
     * engine validates the rest when the version is deployed.
     *
     * @return string[] messages for the user, empty when OK
     */
    public static function publishProblems(string $xml): array {
        $doc = self::load($xml);
        $process = self::singleProcess($doc);

        $problems = [];
        if (self::childElements($process, 'startEvent') === []) {
            $problems[] = 'Processen mangler en starthændelse.';
        }
        if (self::childElements($process, 'endEvent') === []) {
            $problems[] = 'Processen mangler en sluthændelse.';
        }
        // Started from a case through the API, so a plain start event
        foreach (self::childElements($process, 'startEvent') as $start) {
            foreach ($start->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->namespaceURI === self::NS_MODEL && str_ends_with($child->localName, 'EventDefinition')) {
                    $label = $start->getAttribute('name') ?: $start->getAttribute('id');
                    $problems[] = "Starthændelsen \"{$label}\" skal være almindelig (uden timer, besked eller signal), fordi processen startes fra en sag.";
                    break;
                }
            }
        }

        // Timers must say when they fire, in a form the engine understands
        foreach ($process->getElementsByTagNameNS(self::NS_MODEL, 'timerEventDefinition') as $timer) {
            $event = $timer->parentNode;
            $label = $event instanceof \DOMElement ? ($event->getAttribute('name') ?: $event->getAttribute('id')) : '';
            $problem = self::timerProblem($timer);
            if ($problem !== null) {
                $problems[] = "Timeren \"{$label}\" {$problem}";
            }
        }

        // Service tasks anywhere in the process, also inside sub-processes
        foreach ($process->getElementsByTagNameNS(self::NS_MODEL, 'serviceTask') as $task) {
            $label = $task->getAttribute('name') ?: $task->getAttribute('id');
            $topic = $task->getAttributeNS(self::NS_FLOWABLE, 'topic');
            $definition = ProcessTopics::get($topic);
            if ($task->getAttributeNS(self::NS_FLOWABLE, 'type') !== 'external-worker' || $definition === null) {
                $problems[] = "Serviceopgaven \"{$label}\" har ingen automatisk handling.";
                continue;
            }
            $fields = self::fieldsOf($task);
            foreach ($definition['params'] as $param) {
                if ($param['required'] && ProcessTopics::paramApplies($param, $definition, $fields)
                    && trim($fields[$param['name']] ?? '') === '') {
                    $problems[] = "Serviceopgaven \"{$label}\" mangler \"{$param['label']}\".";
                } elseif ($param['type'] === 'number' && trim($fields[$param['name']] ?? '') !== ''
                    && !preg_match('/^[1-9]\d{0,4}$/', trim($fields[$param['name']]))) {
                    $problems[] = "Serviceopgaven \"{$label}\": \"{$param['label']}\" skal være et helt tal på mindst 1.";
                } elseif (in_array($param['type'], ['form_field', 'user_field'], true)
                    && ProcessTopics::paramApplies($param, $definition, $fields)
                    && trim($fields[$param['name']] ?? '') !== '') {
                    $type = self::formFieldTypes($doc)[trim($fields[$param['name']])] ?? null;
                    if ($type === null || ($param['type'] === 'user_field' && $type !== self::USER_FIELD_TYPE)) {
                        $problems[] = "Serviceopgaven \"{$label}\": feltet \"{$fields[$param['name']]}\" findes ikke"
                            . ($param['type'] === 'user_field' ? ' som medarbejderfelt' : '') . ' på nogen opgave.';
                    }
                }
            }
        }

        // Element types OpenCase can't run: they would wait forever, fail,
        // or run outside OpenCase's control
        foreach (self::UNSUPPORTED_ELEMENTS as $type => $why) {
            foreach ($process->getElementsByTagNameNS(self::NS_MODEL, $type) as $element) {
                $label = $element->getAttribute('name') ?: $element->getAttribute('id');
                $problems[] = "\"{$label}\" er {$why}";
            }
        }
        // Nothing in OpenCase sends messages to a process
        foreach ($process->getElementsByTagNameNS(self::NS_MODEL, 'messageEventDefinition') as $definition) {
            $event = $definition->parentNode;
            if ($event instanceof \DOMElement && in_array($event->localName, ['intermediateCatchEvent', 'boundaryEvent'], true)) {
                $label = $event->getAttribute('name') ?: $event->getAttribute('id');
                $problems[] = "Hændelsen \"{$label}\" venter på en besked, men OpenCase sender ikke beskeder til processer – processen ville vente for altid. Brug \"Vent på dokument\" eller en timer.";
            }
        }
        return array_merge($problems, self::codeProblems($doc));
    }

    /**
     * Anything that would make the engine run code rather than a process.
     *
     * The engine evaluates ${...} in almost any attribute or text, and an
     * expression can call Java methods. So this is an allow-list over the
     * whole diagram (the drawing excepted): only the extensions the designer
     * uses, and expressions that only compare variables — see
     * unsafeExpression().
     *
     * @return string[] messages for the user, empty when OK
     */
    private static function codeProblems(\DOMDocument $doc): array {
        $problems = [];
        foreach ($doc->documentElement->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI !== self::NS_DI) {
                self::collectCodeProblems($child, $problems);
            }
        }
        return array_keys($problems);
    }

    /**
     * @param array<string, true> $problems messages found so far
     */
    private static function collectCodeProblems(\DOMElement $element, array &$problems): void {
        $name = $element->localName;
        if ($element->namespaceURI === self::NS_FLOWABLE) {
            if (!in_array($name, self::ALLOWED_FLOWABLE_ELEMENTS, true)) {
                $problems["Elementet \"flowable:{$name}\" er ikke tilladt: det kan køre kode i procesmotoren."] = true;
            }
        } elseif ($element->namespaceURI !== self::NS_MODEL) {
            $problems["Elementet \"{$element->nodeName}\" er ikke tilladt: OpenCase kender ikke dets navnerum."] = true;
        }

        foreach ($element->attributes as $attribute) {
            $namespace = $attribute->namespaceURI;
            if ($namespace === self::NS_FLOWABLE) {
                if (!in_array($attribute->localName, self::ALLOWED_FLOWABLE_ATTRIBUTES, true)) {
                    $problems["Attributten \"flowable:{$attribute->localName}\" er ikke tilladt: den kan køre kode i procesmotoren."] = true;
                } elseif ($attribute->localName === 'type' && $attribute->value !== 'external-worker') {
                    $problems["Opgavetypen \"{$attribute->value}\" er ikke tilladt. Brug en serviceopgave med en automatisk handling."] = true;
                }
            } elseif ($namespace !== null && $namespace !== self::NS_XSI && $namespace !== self::NS_XMLNS) {
                $problems["Attributten \"{$attribute->nodeName}\" er ikke tilladt: OpenCase kender ikke dens navnerum."] = true;
            } elseif ($namespace === null && $attribute->localName === 'language' && $name === 'conditionExpression') {
                $problems['Betingelser skal være almindelige udtryk, ikke scripts.'] = true;
            }
            self::checkExpressions($attribute->value, $problems);
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                self::collectCodeProblems($child, $problems);
            } elseif ($child instanceof \DOMText) {
                self::checkExpressions($child->data, $problems);
            }
        }
    }

    /**
     * @param array<string, true> $problems
     */
    private static function checkExpressions(string $text, array &$problems): void {
        $expression = self::unsafeExpression($text);
        if ($expression !== null) {
            $problems["Udtrykket \"{$expression}\" er ikke tilladt. Et udtryk må kun bruge procesvariabler, tekst, tal og sammenligninger, f.eks. \${udfald == 'godkend'}."] = true;
        }
    }

    /**
     * The first expression in the text that does more than compare process
     * variables, or null when there is none.
     *
     * Allowed: variable names, 'text', numbers, comparison, logical and
     * arithmetic operators, and parentheses for grouping. So no ".", "[", ","
     * or ":" and no "name(...)": nothing that reaches a method or a property.
     *
     * Every "${" and "#{" is checked, also those that seem to be inside the
     * text of another expression: the engine may not read them that way, e.g.
     * after a backslash.
     */
    private static function unsafeExpression(string $text): ?string {
        $offset = 0;
        while (preg_match('/[$#]\{/', $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            if (!self::isSafeExpression($text, $start + 2)) {
                return mb_strimwidth(substr($text, $start), 0, 80, '…');
            }
            $offset = $start + 2;
        }
        return null;
    }

    /**
     * Whether the text from $position up to the closing "}" only has
     * allowed tokens.
     */
    private static function isSafeExpression(string $text, int $position): bool {
        $token = '/\G(?:(?<space>\s+)|(?<string>\'[^\'\\\\]*\'|"[^"\\\\]*")|(?<number>\d+(?:\.\d+)?)'
            . '|(?<word>[\p{L}_][\p{L}\p{N}_]*)|(?<operator>==|!=|<=|>=|&&|\|\||[<>!+\-*\/%])|(?<open>\()|(?<close>\))|(?<end>\}))/u';

        // Whether "(" here would be a call of what came before
        $afterValue = false;
        while (preg_match($token, $text, $match, 0, $position) === 1) {
            $position += strlen($match[0]);
            if (($match['end'] ?? '') !== '') {
                return true;
            }
            if (($match['space'] ?? '') !== '') {
                continue;
            }
            if (($match['open'] ?? '') !== '' && $afterValue) {
                return false;
            }
            $word = $match['word'] ?? '';
            $afterValue = (($match['string'] ?? '') !== '' || ($match['number'] ?? '') !== '' || ($match['close'] ?? '') !== ''
                || ($word !== '' && !in_array($word, self::EXPRESSION_WORDS, true)));
        }
        return false;
    }

    /**
     * Things that can be published but probably aren't what was meant:
     * tasks the engine passes straight through.
     *
     * @return string[] messages for the user
     */
    public static function publishWarnings(string $xml): array {
        $process = self::singleProcess(self::load($xml));
        $warnings = [];
        foreach (['task' => 'en opgave uden type', 'manualTask' => 'en manuel opgave'] as $type => $what) {
            foreach ($process->getElementsByTagNameNS(self::NS_MODEL, $type) as $element) {
                $label = $element->getAttribute('name') ?: $element->getAttribute('id');
                $warnings[] = "\"{$label}\" er {$what}: processen går lige igennem den, uden at nogen får en opgave, og uden at noget registreres. Skift til Brugeropgave eller Serviceopgave, hvis der skal ske noget.";
            }
        }
        return $warnings;
    }

    /**
     * Steps a running process can be at: tasks, intermediate events,
     * gateways and sub-processes, anywhere in the process.
     *
     * @return array<string, array{name: string, type: string}> element id => name and BPMN type
     * @throws \InvalidArgumentException
     */
    public static function flowNodes(string $xml): array {
        $doc = self::load($xml);
        $process = self::singleProcess($doc);
        $types = [
            'userTask', 'serviceTask', 'task', 'manualTask', 'receiveTask', 'sendTask', 'scriptTask', 'businessRuleTask',
            'intermediateCatchEvent', 'intermediateThrowEvent', 'boundaryEvent',
            'exclusiveGateway', 'parallelGateway', 'inclusiveGateway', 'eventBasedGateway',
            'subProcess', 'callActivity',
        ];
        $nodes = [];
        foreach ($types as $type) {
            foreach ($process->getElementsByTagNameNS(self::NS_MODEL, $type) as $element) {
                $id = $element->getAttribute('id');
                if ($id !== '') {
                    $nodes[$id] = ['name' => $element->getAttribute('name') ?: $id, 'type' => $type];
                }
            }
        }
        return $nodes;
    }

    /**
     * Form fields of user tasks with OpenCase's own field type: in Flowable
     * a "Medarbejder" field is a string, marked fieldType="user".
     *
     * @return string[] field ids
     */
    public static function userFieldIds(string $xml, string $taskId): array {
        $doc = self::load($xml);
        $ids = [];
        foreach ($doc->getElementsByTagNameNS(self::NS_MODEL, 'userTask') as $task) {
            if ($task->getAttribute('id') !== $taskId) {
                continue;
            }
            foreach ($task->getElementsByTagNameNS(self::NS_FLOWABLE, 'formProperty') as $property) {
                if ($property->getAttribute('fieldType') === self::USER_FIELD_TYPE) {
                    $ids[] = $property->getAttribute('id');
                }
            }
        }
        return $ids;
    }

    /**
     * Form fields of all user tasks: field id => type ("user" for
     * Medarbejder fields). The outcome is not a field.
     *
     * @return array<string, string>
     */
    private static function formFieldTypes(\DOMDocument $doc): array {
        $types = [];
        foreach ($doc->getElementsByTagNameNS(self::NS_MODEL, 'userTask') as $task) {
            foreach ($task->getElementsByTagNameNS(self::NS_FLOWABLE, 'formProperty') as $property) {
                $id = $property->getAttribute('id');
                if ($id !== 'udfald') {
                    $types[$id] = $property->getAttribute('fieldType') ?: ($property->getAttribute('type') ?: 'string');
                }
            }
        }
        return $types;
    }

    /**
     * Service tasks with this topic (or one of these) that lead to the element, nearest first,
     * following the sequence flows backwards. Used to find "the sub-case
     * created earlier in this branch": in parallel branches each step only
     * sees the steps before it in its own branch.
     *
     * @return string[] element ids
     * @throws \InvalidArgumentException
     */
    public static function precedingServiceTasks(string $xml, string $elementId, string|array $topic): array {
        return array_keys(self::precedingServiceTaskDistances($xml, $elementId, $topic));
    }

    /**
     * The nearest service tasks with this topic before the element. More
     * than one means the element is after a join of branches that each have
     * one, so "the one before this step" is ambiguous.
     *
     * @return string[] element ids
     * @throws \InvalidArgumentException
     */
    public static function nearestPrecedingServiceTasks(string $xml, string $elementId, string|array $topic): array {
        $distances = self::precedingServiceTaskDistances($xml, $elementId, $topic);
        if ($distances === []) {
            return [];
        }
        $nearest = min($distances);
        return array_keys(array_filter($distances, fn(int $d) => $d === $nearest));
    }

    /**
     * @return array<string, int> element id => number of steps back, nearest first
     */
    private static function precedingServiceTaskDistances(string $xml, string $elementId, string|array $topic): array {
        $doc = self::load($xml);
        $sources = [];
        foreach ($doc->getElementsByTagNameNS(self::NS_MODEL, 'sequenceFlow') as $flow) {
            $sources[$flow->getAttribute('targetRef')][] = $flow->getAttribute('sourceRef');
        }
        $topics = [];
        foreach ($doc->getElementsByTagNameNS(self::NS_MODEL, 'serviceTask') as $task) {
            $topics[$task->getAttribute('id')] = $task->getAttributeNS(self::NS_FLOWABLE, 'topic');
        }

        $found = [];
        $seen = [$elementId => true];
        $queue = array_map(fn(string $id) => [$id, 1], $sources[$elementId] ?? []);
        while ($queue !== []) {
            [$id, $distance] = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (in_array($topics[$id] ?? null, (array)$topic, true)) {
                $found[$id] = $distance;
            }
            foreach ($sources[$id] ?? [] as $source) {
                $queue[] = [$source, $distance + 1];
            }
        }
        return $found;
    }

    /**
     * The automatic actions the diagram's service tasks use.
     *
     * @return array<string, string> service task name (or id) => topic
     * @throws \InvalidArgumentException
     */
    public static function serviceTaskTopics(string $xml): array {
        $doc = self::load($xml);
        $topics = [];
        foreach ($doc->getElementsByTagNameNS(self::NS_MODEL, 'serviceTask') as $task) {
            $topic = $task->getAttributeNS(self::NS_FLOWABLE, 'topic');
            if ($topic !== '') {
                $topics[$task->getAttribute('name') ?: $task->getAttribute('id')] = $topic;
            }
        }
        return $topics;
    }

    /**
     * Parameters (flowable:field) of the element with this id, as name => value.
     *
     * @return array<string, string>
     * @throws \InvalidArgumentException
     */
    public static function serviceTaskFields(string $xml, string $elementId): array {
        $doc = self::load($xml);
        foreach ($doc->getElementsByTagNameNS(self::NS_MODEL, 'serviceTask') as $task) {
            if ($task->getAttribute('id') === $elementId) {
                return self::fieldsOf($task);
            }
        }
        throw new \InvalidArgumentException("Serviceopgaven {$elementId} findes ikke i processen.");
    }

    /**
     * <flowable:field name="x" stringValue="..."/> or
     * <flowable:field name="x"><flowable:string>...</flowable:string></flowable:field>
     *
     * @return array<string, string>
     */
    private static function fieldsOf(\DOMElement $task): array {
        $fields = [];
        foreach ($task->getElementsByTagNameNS(self::NS_FLOWABLE, 'field') as $field) {
            $name = $field->getAttribute('name');
            if ($name === '') {
                continue;
            }
            if ($field->hasAttribute('stringValue')) {
                $fields[$name] = $field->getAttribute('stringValue');
                continue;
            }
            $string = $field->getElementsByTagNameNS(self::NS_FLOWABLE, 'string')->item(0);
            $fields[$name] = $string !== null ? $string->textContent : '';
        }
        return $fields;
    }

    /**
     * Why a timer can't be used, or null when it's fine. Expressions
     * (${...}) are evaluated by the engine and not checked here.
     */
    private static function timerProblem(\DOMElement $timer): ?string {
        $values = [];
        foreach (['timeDuration', 'timeCycle', 'timeDate'] as $kind) {
            $element = $timer->getElementsByTagNameNS(self::NS_MODEL, $kind)->item(0);
            $value = $element !== null ? trim($element->textContent) : '';
            if ($value !== '') {
                $values[$kind] = $value;
            }
        }
        if ($values === []) {
            return 'mangler en tid.';
        }
        $kind = array_key_first($values);
        $value = $values[$kind];
        if (str_contains($value, '${') || str_contains($value, '#{')) {
            return null;
        }
        $duration = '/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/';
        $ok = match ($kind) {
            'timeDuration' => preg_match($duration, $value) === 1,
            'timeCycle' => preg_match('#^R\d*/(.+)$#', $value, $m) === 1 && preg_match($duration, $m[1]) === 1,
            default => strtotime($value) !== false,
        };
        return $ok ? null : "har en ugyldig tid (\"{$value}\").";
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function load(string $xml): \DOMDocument {
        if (trim($xml) === '') {
            throw new \InvalidArgumentException('BPMN-diagrammet er tomt.');
        }
        if (strlen($xml) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('BPMN-diagrammet er for stort.');
        }
        // No DTDs: rules out entity expansion and external entities
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new \InvalidArgumentException('BPMN-diagrammet må ikke indeholde en DOCTYPE.');
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok || $doc->documentElement === null) {
            throw new \InvalidArgumentException('BPMN-diagrammet er ikke gyldig XML.');
        }
        if ($doc->documentElement->namespaceURI !== self::NS_MODEL
            || $doc->documentElement->localName !== 'definitions') {
            throw new \InvalidArgumentException('Filen er ikke et BPMN 2.0-diagram.');
        }
        return $doc;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function singleProcess(\DOMDocument $doc): \DOMElement {
        $processes = self::childElements($doc->documentElement, 'process');
        if (count($processes) !== 1) {
            throw new \InvalidArgumentException('BPMN-diagrammet skal indeholde præcis én proces (én pool).');
        }
        return $processes[0];
    }

    /**
     * @return \DOMElement[] direct BPMN-namespace children with this local name
     */
    private static function childElements(\DOMElement $parent, string $localName): array {
        $result = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement
                && $child->namespaceURI === self::NS_MODEL
                && $child->localName === $localName) {
                $result[] = $child;
            }
        }
        return $result;
    }
}
