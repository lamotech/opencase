<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Process;

/**
 * The automatic actions a BPMN service task can perform. A service task is
 * modelled as a Flowable external worker task:
 *
 *   <serviceTask flowable:type="external-worker" flowable:topic="opencase.addJournalNote">
 *     <extensionElements>
 *       <flowable:field name="title" stringValue="Afgørelse sendt"/>
 *     </extensionElements>
 *   </serviceTask>
 *
 * and run by ProcessWorker. The designer, the publish check and the worker
 * all use these definitions.
 *
 * Parameter types: text | textarea | template (template id) |
 * document_category (category id) | boolean ("true"/"false", with a default) |
 * casetype (case type id) | process (process id) |
 * choice (one of the param's "options", with a default) | user (Nextcloud user id) |
 * number (whole number of at least 1) | custom_field (custom field key) |
 * form_field (id of a user task form field in the diagram) |
 * user_field (id of a "Medarbejder" form field in the diagram)
 *
 * A param with "when" => [other param => value] only applies (is shown, and
 * required if "required") when the other param has that value.
 *
 * An action with "outcomes" stores the outcome id in the process variable
 * "udfald" when done, like a user task's choice, for gateway conditions.
 *
 * Some actions depend on optional features; available() leaves out those
 * that can't run on this instance.
 */
class ProcessTopics {

    public const ADD_JOURNAL_NOTE = 'opencase.addJournalNote';
    public const CREATE_DOCUMENT = 'opencase.createDocument';
    public const CLOSE_CASE = 'opencase.closeCase';
    public const SEND_DIGITAL_POST = 'opencase.sendDigitalPost';
    public const CREATE_SUB_CASE = 'opencase.createSubCase';
    public const SEND_NOTIFICATION = 'opencase.sendNotification';
    public const WAIT_FOR_INACTIVITY = 'opencase.waitForInactivity';
    public const WAIT_FOR_DOCUMENT = 'opencase.waitForDocument';
    public const REQUEST_APPROVAL = 'opencase.requestApproval';
    public const FINALIZE_DOCUMENT = 'opencase.finalizeDocument';
    public const ASSIGN_CASE = 'opencase.assignCase';
    public const SET_CUSTOM_FIELD = 'opencase.setCustomField';
    public const ADD_RECIPIENT = 'opencase.addRecipient';
    public const CREATE_REMINDER = 'opencase.createReminder';

    /** Topics whose result is a document that later steps work on. */
    public const DOCUMENT_TOPICS = [self::CREATE_DOCUMENT, self::WAIT_FOR_DOCUMENT];

    /** "recipients" parameter of SEND_NOTIFICATION */
    public const RECIPIENTS_RESPONSIBLE = 'responsible';
    public const RECIPIENTS_CASEWORKERS = 'caseworkers';
    public const RECIPIENTS_USER = 'user';

    /** "role" parameter of ASSIGN_CASE */
    public const ROLE_RESPONSIBLE = 'responsible';
    public const ROLE_CASEWORKER = 'caseworker';

    /** "source" parameter: a value set in the designer, or a task's form field. */
    public const SOURCE_FIXED = 'fixed';
    public const SOURCE_FIELD = 'field';

    /** "deadline" parameter of CREATE_REMINDER */
    public const DEADLINE_NONE = 'none';
    public const DEADLINE_DAYS = 'days';
    public const DEADLINE_FIELD = 'field';

    /** "attach" parameter of CREATE_REMINDER: what the reminder is on */
    public const ATTACH_CASE = 'case';
    public const ATTACH_DOCUMENT = 'document';

    /** "parties" parameter of ADD_RECIPIENT */
    public const PARTIES_PRIMARY = 'primary';
    public const PARTIES_ALL = 'all';

    /** "type" parameter of REQUEST_APPROVAL: the document workflow types. */
    public const APPROVAL_TYPE_APPROVAL = 'approval';
    public const APPROVAL_TYPE_REVIEW = 'review';

    /** Outcomes of REQUEST_APPROVAL (process variable "udfald"). */
    public const OUTCOME_APPROVED = 'godkendt';
    public const OUTCOME_REJECTED = 'afvist';
    public const OUTCOME_REVIEWED = 'gennemset';
    public const OUTCOME_CANCELLED = 'annulleret';

    /** "target" parameter: which case an action works on. */
    public const TARGET_CASE = 'case';
    public const TARGET_SUB_CASE = 'subcase';

    private const TARGET_PARAM = [
        'name' => 'target', 'label' => 'Sag', 'type' => 'choice', 'required' => false, 'default' => self::TARGET_CASE,
        'options' => [
            ['id' => self::TARGET_CASE, 'name' => 'Sagen'],
            ['id' => self::TARGET_SUB_CASE, 'name' => 'Undersagen fra et tidligere trin'],
        ],
    ];

    /**
     * @return array<string, array{label: string, description: string, params: array{name: string, label: string, type: string, required: bool}[]}>
     */
    public static function all(): array {
        return [
            self::ADD_JOURNAL_NOTE => [
                'label' => 'Opret journalnotat',
                'description' => 'Opretter et journalnotat på sagen eller på undersagen fra et tidligere "Opret undersag"-trin i samme gren.',
                'params' => [
                    self::TARGET_PARAM,
                    ['name' => 'title', 'label' => 'Titel', 'type' => 'text', 'required' => true],
                    ['name' => 'text', 'label' => 'Tekst', 'type' => 'textarea', 'required' => false],
                ],
            ],
            self::CREATE_DOCUMENT => [
                'label' => 'Opret dokument',
                'description' => 'Opretter et dokument på sagen – eller på undersagen fra et tidligere "Opret undersag"-trin i samme gren – evt. med en fil dannet ud fra en skabelon med sagens oplysninger flettet ind.',
                'params' => [
                    self::TARGET_PARAM,
                    ['name' => 'title', 'label' => 'Titel', 'type' => 'text', 'required' => true],
                    ['name' => 'category', 'label' => 'Dokumentkategori', 'type' => 'document_category', 'required' => true],
                    ['name' => 'template', 'label' => 'Skabelon', 'type' => 'template', 'required' => false],
                ],
            ],
            self::CREATE_SUB_CASE => [
                'label' => 'Opret undersag',
                'description' => 'Opretter en undersag med samme organisation, KLE-nummer, handlingsfacet, følsomhed, indsigtsgrad og ansvarlige som sagen, og starter evt. en proces på den. I titlen kan {sagsnummer} og {sagstitel} bruges.',
                'params' => [
                    ['name' => 'title', 'label' => 'Titel', 'type' => 'text', 'required' => true],
                    ['name' => 'casetype', 'label' => 'Sagstype (ellers som sagen)', 'type' => 'casetype', 'required' => false],
                    ['name' => 'process', 'label' => 'Start proces på undersagen', 'type' => 'process', 'required' => false],
                ],
            ],
            self::SEND_DIGITAL_POST => [
                'label' => 'Send Digital Post',
                'description' => 'Sender dokumentet fra det nærmeste tidligere "Opret dokument"- eller "Vent på dokument"-trin i samme gren – eller, hvis der ikke er et, det nyeste dokument på sagen i den valgte kategori – med Digital Post til dokumentets modtagere, eller sagens primære part, hvis dokumentet ingen modtagere har. Modtagere uden Digital Post får brevet som fysisk post. Et dokument sendes kun én gang: er det allerede sendt, går processen videre uden at sende det igen. Med "Undersagen" bruges undersagen fra et tidligere "Opret undersag"-trin i samme gren.',
                'params' => [
                    self::TARGET_PARAM,
                    ['name' => 'category', 'label' => 'Dokumentkategori (hvis processen ikke har oprettet et dokument)', 'type' => 'document_category', 'required' => false],
                    ['name' => 'can_be_replied', 'label' => 'Kan besvares', 'type' => 'boolean', 'required' => false, 'default' => 'true'],
                    ['name' => 'include_attachments', 'label' => 'Medtag bilag', 'type' => 'boolean', 'required' => false, 'default' => 'true'],
                ],
                'requires' => 'digital_post',
            ],
            self::SEND_NOTIFICATION => [
                'label' => 'Send notifikation',
                'description' => 'Sender en Nextcloud-notifikation med link til sagen. Kun modtagere med adgang til sagen får den; andre springes over og vises ved trinnet. I overskrift og besked kan {sagsnummer} og {sagstitel} bruges.',
                'params' => [
                    self::TARGET_PARAM,
                    ['name' => 'recipients', 'label' => 'Modtagere', 'type' => 'choice', 'required' => false, 'default' => self::RECIPIENTS_RESPONSIBLE,
                        'options' => [
                            ['id' => self::RECIPIENTS_RESPONSIBLE, 'name' => 'Sagens ansvarlige sagsbehandler'],
                            ['id' => self::RECIPIENTS_CASEWORKERS, 'name' => 'Sagens andre sagsbehandlere'],
                            ['id' => self::RECIPIENTS_USER, 'name' => 'En bestemt medarbejder'],
                        ]],
                    ['name' => 'user', 'label' => 'Medarbejder', 'type' => 'user', 'required' => true, 'when' => ['recipients' => self::RECIPIENTS_USER]],
                    ['name' => 'title', 'label' => 'Overskrift (ellers trinnets navn)', 'type' => 'text', 'required' => false],
                    ['name' => 'message', 'label' => 'Besked', 'type' => 'textarea', 'required' => true],
                ],
            ],
            self::WAIT_FOR_INACTIVITY => [
                'label' => 'Vent på inaktivitet',
                'description' => 'Venter, til der ikke har været aktivitet på sagen i det angivne antal dage – og mindst så længe efter trinnet er nået, så en løkke tilbage hertil ikke gentages med det samme. Aktivitet er ændringer af sagen, dens dokumenter, filer, journalnotater og parter.',
                'params' => [
                    self::TARGET_PARAM,
                    ['name' => 'days', 'label' => 'Antal dage uden aktivitet', 'type' => 'number', 'required' => true],
                ],
            ],
            self::WAIT_FOR_DOCUMENT => [
                'label' => 'Vent på dokument',
                'description' => 'Venter, til der kommer et dokument på sagen – oprettet, modtaget (f.eks. Digital Post eller import) eller flyttet hertil fra indbakken – efter trinnet er nået, evt. kun i den valgte kategori. Senere trin, der bruger "dokumentet fra et tidligere trin", bruger det modtagne dokument. Sæt en timer på trinnet for at gøre noget andet, hvis der ikke kommer noget.',
                'params' => [
                    self::TARGET_PARAM,
                    ['name' => 'category', 'label' => 'Dokumentkategori (ellers alle)', 'type' => 'document_category', 'required' => false],
                ],
            ],
            self::REQUEST_APPROVAL => [
                'label' => 'Send til godkendelse',
                'description' => 'Sender dokumentet fra det nærmeste tidligere "Opret dokument"- eller "Vent på dokument"-trin i samme gren – ellers det nyeste dokument på sagen i den valgte kategori – til godkendelse eller gennemsyn, og venter på svaret. Svaret gemmes i variablen udfald (godkendt, afvist, gennemset eller annulleret) og kan bruges i betingelser efter en gateway. Er flere modtagere valgt, skal de svare én ad gangen.',
                'params' => [
                    self::TARGET_PARAM,
                    ['name' => 'category', 'label' => 'Dokumentkategori (hvis processen ikke har et dokument)', 'type' => 'document_category', 'required' => false],
                    ['name' => 'type', 'label' => 'Type', 'type' => 'choice', 'required' => false, 'default' => self::APPROVAL_TYPE_APPROVAL,
                        'options' => [
                            ['id' => self::APPROVAL_TYPE_APPROVAL, 'name' => 'Godkendelse (godkend/afvis)'],
                            ['id' => self::APPROVAL_TYPE_REVIEW, 'name' => 'Gennemsyn'],
                        ]],
                    ['name' => 'approvers', 'label' => 'Sendes til', 'type' => 'choice', 'required' => false, 'default' => self::RECIPIENTS_USER,
                        'options' => [
                            ['id' => self::RECIPIENTS_USER, 'name' => 'En bestemt medarbejder'],
                            ['id' => self::RECIPIENTS_RESPONSIBLE, 'name' => 'Sagens ansvarlige sagsbehandler'],
                            ['id' => self::RECIPIENTS_CASEWORKERS, 'name' => 'Sagens andre sagsbehandlere'],
                        ]],
                    ['name' => 'user', 'label' => 'Medarbejder', 'type' => 'user', 'required' => true, 'when' => ['approvers' => self::RECIPIENTS_USER]],
                    ['name' => 'days', 'label' => 'Frist i dage', 'type' => 'number', 'required' => false],
                ],
                'outcomes' => [
                    ['id' => self::OUTCOME_APPROVED, 'name' => 'Godkendt'],
                    ['id' => self::OUTCOME_REJECTED, 'name' => 'Afvist'],
                    ['id' => self::OUTCOME_REVIEWED, 'name' => 'Gennemset'],
                    ['id' => self::OUTCOME_CANCELLED, 'name' => 'Annulleret'],
                ],
            ],
            self::FINALIZE_DOCUMENT => [
                'label' => 'Gør dokument endeligt',
                'description' => 'Sætter status "Endelig" på dokumentet fra det nærmeste tidligere "Opret dokument"- eller "Vent på dokument"-trin i samme gren – ellers det nyeste dokument på sagen i den valgte kategori – så dets filer ikke længere kan ændres. Bruges f.eks. før "Send Digital Post".',
                'params' => [
                    self::TARGET_PARAM,
                    ['name' => 'category', 'label' => 'Dokumentkategori (hvis processen ikke har et dokument)', 'type' => 'document_category', 'required' => false],
                ],
            ],
            self::ASSIGN_CASE => [
                'label' => 'Tildel sag',
                'description' => 'Gør en medarbejder til sagens ansvarlige sagsbehandler eller tilføjer vedkommende som sagsbehandler. Medarbejderen vælges her eller i et "Medarbejder"-felt på en tidligere opgave. Den tidligere ansvarlige beholder ikke automatisk adgang, som når det gøres på sagen.',
                'params' => [
                    self::TARGET_PARAM,
                    ['name' => 'role', 'label' => 'Som', 'type' => 'choice', 'required' => false, 'default' => self::ROLE_RESPONSIBLE,
                        'options' => [
                            ['id' => self::ROLE_RESPONSIBLE, 'name' => 'Ansvarlig sagsbehandler'],
                            ['id' => self::ROLE_CASEWORKER, 'name' => 'Sagsbehandler (tilføjes)'],
                        ]],
                    ['name' => 'source', 'label' => 'Medarbejder fra', 'type' => 'choice', 'required' => false, 'default' => self::SOURCE_FIXED,
                        'options' => [
                            ['id' => self::SOURCE_FIXED, 'name' => 'Vælg her'],
                            ['id' => self::SOURCE_FIELD, 'name' => 'Medarbejderfelt på en opgave'],
                        ]],
                    ['name' => 'user', 'label' => 'Medarbejder', 'type' => 'user', 'required' => true, 'when' => ['source' => self::SOURCE_FIXED]],
                    ['name' => 'field', 'label' => 'Felt', 'type' => 'user_field', 'required' => true, 'when' => ['source' => self::SOURCE_FIELD]],
                ],
            ],
            self::SET_CUSTOM_FIELD => [
                'label' => 'Udfyld tillægsfelt',
                'description' => 'Sætter et af sagens tillægsfelter til en værdi angivet her eller udfyldt i et felt på en tidligere opgave. Værdien skal passe til tillægsfeltet: dato som ÅÅÅÅ-MM-DD, ja/nej som true/false, og for en liste en af listens værdier. Er opgavens felt tomt, ryddes tillægsfeltet.',
                'params' => [
                    self::TARGET_PARAM,
                    ['name' => 'custom_field', 'label' => 'Tillægsfelt', 'type' => 'custom_field', 'required' => true],
                    ['name' => 'source', 'label' => 'Værdi fra', 'type' => 'choice', 'required' => false, 'default' => self::SOURCE_FIXED,
                        'options' => [
                            ['id' => self::SOURCE_FIXED, 'name' => 'Angiv her'],
                            ['id' => self::SOURCE_FIELD, 'name' => 'Felt på en opgave'],
                        ]],
                    ['name' => 'value', 'label' => 'Værdi', 'type' => 'text', 'required' => true, 'when' => ['source' => self::SOURCE_FIXED]],
                    ['name' => 'field', 'label' => 'Felt', 'type' => 'form_field', 'required' => true, 'when' => ['source' => self::SOURCE_FIELD]],
                ],
            ],
            self::ADD_RECIPIENT => [
                'label' => 'Tilføj modtager',
                'description' => 'Tilføjer sagens primære part – eller alle sagens parter – med CPR-/CVR-nummer som modtager på processens dokument (fra det nærmeste tidligere "Opret dokument"- eller "Vent på dokument"-trin i samme gren, ellers det nyeste dokument på sagen i den valgte kategori). Parter, der allerede er modtagere, springes over.',
                'params' => [
                    self::TARGET_PARAM,
                    ['name' => 'category', 'label' => 'Dokumentkategori (hvis processen ikke har et dokument)', 'type' => 'document_category', 'required' => false],
                    ['name' => 'parties', 'label' => 'Parter', 'type' => 'choice', 'required' => false, 'default' => self::PARTIES_PRIMARY,
                        'options' => [
                            ['id' => self::PARTIES_PRIMARY, 'name' => 'Sagens primære part'],
                            ['id' => self::PARTIES_ALL, 'name' => 'Alle sagens parter'],
                        ]],
                ],
            ],
            self::CREATE_REMINDER => [
                'label' => 'Opret påmindelse',
                'description' => 'Opretter en almindelig påmindelse med titel og evt. frist til en medarbejder, som når den oprettes på sagen: den vises under "Foreslået arbejde", medarbejderen får en notifikation og skriveadgang til sagen, hvis vedkommende ikke har den. Processen venter ikke på påmindelsen. I titlen kan {sagsnummer} og {sagstitel} bruges.',
                'params' => [
                    self::TARGET_PARAM,
                    ['name' => 'title', 'label' => 'Titel', 'type' => 'text', 'required' => true],
                    ['name' => 'recipient', 'label' => 'Til', 'type' => 'choice', 'required' => false, 'default' => self::RECIPIENTS_RESPONSIBLE,
                        'options' => [
                            ['id' => self::RECIPIENTS_RESPONSIBLE, 'name' => 'Sagens ansvarlige sagsbehandler'],
                            ['id' => self::RECIPIENTS_USER, 'name' => 'En bestemt medarbejder'],
                            ['id' => self::SOURCE_FIELD, 'name' => 'Medarbejderfelt på en opgave'],
                        ]],
                    ['name' => 'user', 'label' => 'Medarbejder', 'type' => 'user', 'required' => true, 'when' => ['recipient' => self::RECIPIENTS_USER]],
                    ['name' => 'field', 'label' => 'Medarbejderfelt', 'type' => 'user_field', 'required' => true, 'when' => ['recipient' => self::SOURCE_FIELD]],
                    ['name' => 'deadline', 'label' => 'Frist', 'type' => 'choice', 'required' => false, 'default' => self::DEADLINE_DAYS,
                        'options' => [
                            ['id' => self::DEADLINE_DAYS, 'name' => 'Antal dage efter trinnet'],
                            ['id' => self::DEADLINE_FIELD, 'name' => 'Datofelt på en opgave'],
                            ['id' => self::DEADLINE_NONE, 'name' => 'Ingen frist'],
                        ]],
                    ['name' => 'days', 'label' => 'Antal dage', 'type' => 'number', 'required' => true, 'when' => ['deadline' => self::DEADLINE_DAYS]],
                    ['name' => 'deadline_field', 'label' => 'Datofelt', 'type' => 'form_field', 'required' => true, 'when' => ['deadline' => self::DEADLINE_FIELD]],
                    ['name' => 'attach', 'label' => 'Påmindelsen gælder', 'type' => 'choice', 'required' => false, 'default' => self::ATTACH_CASE,
                        'options' => [
                            ['id' => self::ATTACH_CASE, 'name' => 'Sagen'],
                            ['id' => self::ATTACH_DOCUMENT, 'name' => 'Processens dokument'],
                        ]],
                    ['name' => 'category', 'label' => 'Dokumentkategori (hvis processen ikke har et dokument)', 'type' => 'document_category', 'required' => false, 'when' => ['attach' => self::ATTACH_DOCUMENT]],
                ],
            ],
            self::CLOSE_CASE => [
                'label' => 'Luk sagen',
                'description' => 'Lukker sagen. Bør være processens sidste trin: når sagen lukkes, annulleres andre processer, der kører på den.',
                'params' => [],
            ],
        ];
    }

    /**
     * @return array{label: string, description: string, params: array{name: string, label: string, type: string, required: bool}[]}|null
     */
    public static function get(string $topic): ?array {
        return self::all()[$topic] ?? null;
    }

    /**
     * Whether a param applies given the step's other values (see "when").
     *
     * @param array<string, string> $fields the step's parameter values
     */
    public static function paramApplies(array $param, array $definition, array $fields): bool {
        foreach ($param['when'] ?? [] as $other => $value) {
            $default = null;
            foreach ($definition['params'] as $p) {
                if ($p['name'] === $other) {
                    $default = $p['default'] ?? null;
                }
            }
            if (($fields[$other] ?? $default) !== $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * The actions that can run on this instance.
     *
     * @param bool $digitalPost Digital Post is enabled and installed
     * @return array<string, array>
     */
    public static function available(bool $digitalPost): array {
        return array_filter(self::all(), fn(array $d) => ($d['requires'] ?? null) !== 'digital_post' || $digitalPost);
    }
}
