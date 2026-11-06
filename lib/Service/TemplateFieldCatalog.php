<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCP\L10N\IFactory;

/**
 * The merge fields a template can contain, grouped and described. The one
 * list behind the "Skabelonfelter" page (src/views/TemplateFieldsView.vue,
 * via GET /api/v1/templates/fields) and the desktop API. Keep it in sync with
 * the placeholders TemplateMergeService replaces.
 *
 * Every field has a Danish and an English name; TemplateMergeService accepts
 * both, case-insensitively, written as {{name}}.
 */
class TemplateFieldCatalog {

    public function __construct(
        private IFactory $l10nFactory,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, note: ?string,
     *                    fields: list<array{da: string, en: string, description: string}>}>
     */
    public function groups(string $language): array {
        $l = $this->l10nFactory->get('opencase', $language);

        return [
            [
                'key'    => 'case',
                'label'  => $l->t('Sag'),
                'note'   => null,
                'fields' => [
                    ['da' => 'sag.nummer',            'en' => 'case.number',               'description' => $l->t('Sagsnummer')],
                    ['da' => 'sag.titel',             'en' => 'case.title',                'description' => $l->t('Sagsoverskrift')],
                    ['da' => 'sag.organisation',      'en' => 'case.organisation',         'description' => $l->t('Organisationsnavn')],
                    ['da' => 'sag.kle',               'en' => 'case.kle',                  'description' => $l->t('KLE-klassifikationskode')],
                    ['da' => 'sag.følsomhed',         'en' => 'case.sensitivity',          'description' => $l->t('Følsomhedsniveau')],
                    ['da' => 'sag.status',            'en' => 'case.status',               'description' => $l->t('Sagsstatus')],
                    ['da' => 'sag.oprettet',          'en' => 'case.created',              'description' => $l->t('Oprettelsesdato (dd.mm.åååå)')],
                    ['da' => 'sag.indsigtsgrad',      'en' => 'case.insightlevel',         'description' => $l->t('Sagens indsigtsgrad')],
                    ['da' => 'sag.handlingsfacet',    'en' => 'case.actionfacet',          'description' => $l->t('Handlingsfacet (kode og titel)')],
                    ['da' => 'sag.ansvarlig',         'en' => 'case.responsible',          'description' => $l->t('Navn på sagens ansvarlige sagsbehandler')],
                    ['da' => 'sag.ansvarlig.email',   'en' => 'case.responsible.email',    'description' => $l->t('Sagsbehandlerens e-mail')],
                    ['da' => 'sag.ansvarlig.telefon', 'en' => 'case.responsible.phone',    'description' => $l->t('Sagsbehandlerens telefon')],
                    ['da' => 'sag.ansvarlig.adresse', 'en' => 'case.responsible.location', 'description' => $l->t('Sagsbehandlerens lokation')],
                ],
            ],
            [
                'key'    => 'organisation',
                'label'  => $l->t('Organisation'),
                'note'   => null,
                'fields' => [
                    ['da' => 'organisation.logo',     'en' => 'organisation.logo',     'description' => $l->t('Organisationens logo (eller den nærmeste overordnede organisations). Skriv feltet som tekst for et logo på 2 cm i højden, eller indsæt et billede og sæt dets alternative tekst til feltet — så får logoet billedets størrelse og placering.')],
                    ['da' => 'organisation.email',    'en' => 'organisation.email',    'description' => $l->t('Organisationens e-mail')],
                    ['da' => 'organisation.telefon',  'en' => 'organisation.phone',    'description' => $l->t('Organisationens telefon')],
                    ['da' => 'organisation.adresse',  'en' => 'organisation.location', 'description' => $l->t('Organisationens adresse/lokation')],
                ],
            ],
            [
                'key'    => 'document',
                'label'  => $l->t('Dokument'),
                'note'   => null,
                'fields' => [
                    ['da' => 'dokument.nummer',          'en' => 'document.number',         'description' => $l->t('Dokumentnummer')],
                    ['da' => 'dokument.type',            'en' => 'document.type',           'description' => $l->t('Dokumenttype')],
                    ['da' => 'dokument.titel',           'en' => 'document.title',          'description' => $l->t('Dokumenttitel')],
                    ['da' => 'dokument.oprettet',        'en' => 'document.created',        'description' => $l->t('Oprettelsesdato (dd.mm.åååå)')],
                    ['da' => 'dokument.status',          'en' => 'document.status',         'description' => $l->t('Dokumentstatus')],
                    ['da' => 'dokument.kategori',        'en' => 'document.category',       'description' => $l->t('Dokumentkategori')],
                    ['da' => 'dokument.dokumentdato',    'en' => 'document.documentdate',   'description' => $l->t('Dokumentdato (dd.mm.åååå)')],
                    ['da' => 'dokument.modtagetdato',    'en' => 'document.receiveddate',   'description' => $l->t('Modtaget dato (dd.mm.åååå)')],
                    ['da' => 'dokument.registreretdato', 'en' => 'document.registereddate', 'description' => $l->t('Registreret dato (dd.mm.åååå)')],
                    ['da' => 'dokument.indsigtsgrad',    'en' => 'document.insightlevel',   'description' => $l->t('Dokumentets indsigtsgrad')],
                ],
            ],
            [
                'key'    => 'address',
                'label'  => $l->t('Adresse'),
                'note'   => $l->t('Adressefelterne udfyldes først, når dokumentet sendes med Digital Post — med den enkelte modtagers oplysninger, så hver modtager får sin egen udgave. Når en fil oprettes fra skabelonen, står felterne derfor uændret i dokumentet. Brug dem til modtagerens navn og adresse i brevet.'),
                'fields' => [
                    ['da' => 'adresse.cpr',          'en' => 'address.cpr',         'description' => $l->t('CPR-nummer')],
                    ['da' => 'adresse.navn',         'en' => 'address.name',        'description' => $l->t('Navn')],
                    ['da' => 'adresse.vejnavn',      'en' => 'address.streetname',  'description' => $l->t('Vejnavn')],
                    ['da' => 'adresse.husnummer',    'en' => 'address.housenumber', 'description' => $l->t('Husnummer')],
                    ['da' => 'adresse.etage',        'en' => 'address.floor',       'description' => $l->t('Etage')],
                    ['da' => 'adresse.dør',          'en' => 'address.door',        'description' => $l->t('Dør')],
                    ['da' => 'adresse.postnummer',   'en' => 'address.zipcode',     'description' => $l->t('Postnummer')],
                    ['da' => 'adresse.postdistrikt', 'en' => 'address.zipdistrict', 'description' => $l->t('Postdistrikt')],
                ],
            ],
            [
                'key'    => 'phrases',
                'label'  => $l->t('Fraser'),
                'note'   => null,
                'fields' => [
                    ['da' => 'fraser.indhold', 'en' => 'phrases.content', 'description' => $l->t('Indholdet af de valgte fraser. Placér feltet alene i et afsnit, så frasernes formatering bevares.')],
                ],
            ],
        ];
    }
}
