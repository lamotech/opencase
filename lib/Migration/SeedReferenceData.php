<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Seeds all reference / lookup data required for OpenCase to function.
 *
 * Registered as an install repair step in appinfo/info.xml so that it runs
 * during a fresh installation. During a fresh install Nextcloud deliberately
 * runs migrations in schema-only mode (postSchemaChange is skipped), so
 * seeding must happen here instead.
 *
 * The step is idempotent — running it multiple times is safe.
 */
class SeedReferenceData implements IRepairStep {

    public function __construct(private IDBConnection $db) {}

    public function getName(): string {
        return 'Seed OpenCase reference data';
    }

    public function run(IOutput $output): void {
        $this->seedDocumentStatuses($output);
        $this->seedDocumentCategories($output);
        $this->seedDocumentTypes($output);
        $this->seedSensitivities($output);
        $this->seedCaseStatuses($output);
        $this->seedCaseTypes($output);
        $this->seedInsightLevels($output);
        $this->seedRoles($output);
        $this->seedAdminUserAccess($output);
        $this->seedContactRoles($output);
        $this->seedParticipantRoles($output);
        $this->seedContactTypes($output);
        $this->seedConfig($output);
        $this->seedCprEvents($output);
        $this->seedAiPrompts($output);
        $this->seedAiActions($output);
    }

    private function seedDocumentStatuses(IOutput $output): void {
        $rows = [
            [1, 'da', 'Kladde',  false, false],
            [2, 'da', 'Passiv',  false, false],
            [3, 'da', 'Endelig', true,  false],
            [1, 'en', 'Draft',   false, false],
            [2, 'en', 'Passive', false, false],
            [3, 'en', 'Final',   true,  false],
        ];
        $inserted = 0;
        foreach ($rows as [$id, $lang, $name, $isFinal, $expired]) {
            $this->db->executeStatement(
                'INSERT IGNORE INTO `*PREFIX*opencase_documentstatus` (`id`, `language`, `name`, `is_final`, `expired`) VALUES (?, ?, ?, ?, ?)',
                [$id, $lang, $name, (int)$isFinal, (int)$expired]
            );
            $inserted++;
        }
        $output->info("Seeded {$inserted} document status rows.");
    }

    private function seedDocumentCategories(IOutput $output): void {
        $rows = [
            [1, 'da', 'Indgående'],
            [2, 'da', 'Udgående'],
            [3, 'da', 'Intern'],
            [1, 'en', 'Inbound'],
            [2, 'en', 'Outbound'],
            [3, 'en', 'Internal'],
        ];
        $inserted = 0;
        foreach ($rows as [$id, $lang, $name]) {
            $this->db->executeStatement(
                'INSERT IGNORE INTO `*PREFIX*opencase_documentcategory` (`id`, `language`, `name`) VALUES (?, ?, ?)',
                [$id, $lang, $name]
            );
            $inserted++;
        }
        $output->info("Seeded {$inserted} document category rows.");
    }

    private function seedDocumentTypes(IOutput $output): void {
        // ids < 100 are system types assigned by an integration; ids >= 100 are
        // the user-selectable ones offered in the UI.
        $rows = [
            [1,   'Digital post', true],
            [2,   'Fordeling',    true],
            [3,   'Email',        true],
            [4,   'Scanning',     true],
            [100, 'Notat',        false],
            [101, 'Brev',         false],
            [102, 'Bilag',        false],
            [103, 'Ansøgning',    false],
            [104, 'Referat',      false],
            [105, 'Afgørelse',    false],
        ];
        $inserted = 0;
        foreach ($rows as [$id, $name, $isSystem]) {
            $this->db->executeStatement(
                'INSERT IGNORE INTO `*PREFIX*opencase_documenttype` (`id`, `name`, `is_system`) VALUES (?, ?, ?)',
                [$id, $name, (int)$isSystem]
            );
            $inserted++;
        }
        $output->info("Seeded {$inserted} document type rows.");
    }

    private function seedSensitivities(IOutput $output): void {
        $seeds = [
            ['uuid' => '1d81c472-0808-44cc-963d-f5ef0170ae1d', 'key' => 'Ikke_fortrolige_data',            'title' => 'Ikke-fortrolige data'],
            ['uuid' => '292e85a9-8ad4-46df-9e50-f97d6837ad74', 'key' => 'Fortrolige_personoplysninger',    'title' => 'Almindelige personoplysninger eller fortrolige forretningsdata'],
            ['uuid' => '31c09910-e011-46a5-86fb-254374421fe8', 'key' => 'Foelsomme_personoplysninger',     'title' => 'Følsomme personoplysninger eller følsomme forretningsdata'],
            ['uuid' => '44f4108b-26d4-46de-a90f-35e35b55b8d8', 'key' => 'Saerligt_beskyttede_oplysninger', 'title' => 'Særligt beskyttede oplysninger'],
        ];
        $inserted = 0;
        foreach ($seeds as $seed) {
            $check = $this->db->getQueryBuilder();
            $check->select('uuid')->from('opencase_sensitivity')
                ->where($check->expr()->eq('uuid', $check->createNamedParameter($seed['uuid'])));
            $res = $check->executeQuery();
            $row = $res->fetch();
            $res->closeCursor();
            if ($row) {
                continue;
            }
            $ins = $this->db->getQueryBuilder();
            $ins->insert('opencase_sensitivity')->values([
                'uuid'        => $ins->createNamedParameter($seed['uuid']),
                'key'         => $ins->createNamedParameter($seed['key']),
                'title'       => $ins->createNamedParameter($seed['title']),
                'description' => $ins->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                'active'      => $ins->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            ])->executeStatement();
            $inserted++;
        }
        $output->info("Seeded {$inserted} sensitivity row(s).");
    }

    private function seedCaseStatuses(IOutput $output): void {
        $rows = [
            [1, 'da', 'Åben',      false, false],
            [2, 'da', 'Lukket',    true,  false],
            [3, 'da', 'Arkiveret', true,  true],
            [1, 'en', 'Open',      false, false],
            [2, 'en', 'Closed',    true,  false],
            [3, 'en', 'Archived',  true,  true],
        ];
        $inserted = 0;
        foreach ($rows as [$id, $lang, $name, $isClosed, $expired]) {
            $check = $this->db->getQueryBuilder();
            $check->select($check->func()->count('*'))
                ->from('opencase_casestatus')
                ->where($check->expr()->eq('id', $check->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                ->andWhere($check->expr()->eq('language', $check->createNamedParameter($lang)));
            $res   = $check->executeQuery();
            $count = (int)$res->fetchOne();
            $res->closeCursor();
            if ($count > 0) {
                continue;
            }
            $ins = $this->db->getQueryBuilder();
            $ins->insert('opencase_casestatus')
                ->setValue('id',        $ins->createNamedParameter($id,       IQueryBuilder::PARAM_INT))
                ->setValue('language',  $ins->createNamedParameter($lang))
                ->setValue('name',      $ins->createNamedParameter($name))
                ->setValue('is_closed', $ins->createNamedParameter($isClosed, IQueryBuilder::PARAM_BOOL))
                ->setValue('expired',   $ins->createNamedParameter($expired,  IQueryBuilder::PARAM_BOOL))
                ->executeStatement();
            $inserted++;
        }
        $output->info("Seeded {$inserted} case status row(s).");
    }

    private function seedCaseTypes(IOutput $output): void {
        $rows = [
            [1, 'da', 'Standard',      'None'],
            [2, 'da', 'Borgersag',     'Citizen'],
            [3, 'da', 'Virksomhedssag', 'Company'],
            [4, 'da', 'Personalesag',  'Employee'],
            [1, 'en', 'Standard',      'None'],
            [2, 'en', 'Citizen case',  'Citizen'],
            [3, 'en', 'Company case',  'Company'],
            [4, 'en', 'Employee case', 'Employee'],
        ];
        $inserted = 0;
        foreach ($rows as [$id, $lang, $name, $primaryParticipant]) {
            $check = $this->db->getQueryBuilder();
            $check->select($check->func()->count('*'))
                ->from('opencase_casetype')
                ->where($check->expr()->eq('id', $check->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                ->andWhere($check->expr()->eq('language', $check->createNamedParameter($lang)));
            $res   = $check->executeQuery();
            $count = (int)$res->fetchOne();
            $res->closeCursor();
            if ($count > 0) {
                continue;
            }
            $ins = $this->db->getQueryBuilder();
            $ins->insert('opencase_casetype')
                ->setValue('id',                  $ins->createNamedParameter($id,   IQueryBuilder::PARAM_INT))
                ->setValue('language',             $ins->createNamedParameter($lang))
                ->setValue('name',                 $ins->createNamedParameter($name))
                ->setValue('primary_participant',  $ins->createNamedParameter($primaryParticipant))
                ->executeStatement();
            $inserted++;
        }
        $output->info("Seeded {$inserted} case type row(s).");
    }

    private function seedInsightLevels(IOutput $output): void {
        $rows = [
            [1, 'da', 'Åben (Offentlig)', 'Dokumenter og sager, der som udgangspunkt er tilgængelige for alle brugere i organisationen (og potentielt via aktindsigt).'],
            [2, 'da', 'Intern', 'Adgang for alle medarbejdere i den pågældende forvaltning/institution, men ikke eksterne.'],
            [3, 'da', 'Begrænset (Fortrolig)', 'Adgang er forbeholdt en bestemt sagsbehandlergruppe, et team eller en afdeling. Det bruges ofte til personfølsomme oplysninger eller interne arbejdsprocesser, der endnu ikke er offentlige.'],
            [4, 'da', 'Lukket (Stærkt fortrolig/Personlig)', 'Kun udpegede medarbejdere med specifikke rettigheder kan se indholdet. Dette bruges typisk til personalesager, direktionssager eller følsomme børnesager.'],
            [1, 'en', 'Open (Public)', 'Documents and cases that are generally accessible to all users in the organization (and potentially via document access).'],
            [2, 'en', 'Internal', 'Access for all employees in the administration/institution in question, but not external ones.'],
            [3, 'en', 'Limited (Confidential)', 'Access is reserved for a specific case-handling group, team or department. This is often used for sensitive information or internal work processes that are not yet public.'],
            [4, 'en', 'Closed (Highly Confidential/Personal)', 'Only designated employees with specific rights can see the content. This is typically used for personnel cases, executive cases or sensitive children\'s cases.'],
        ];
        $inserted = 0;
        foreach ($rows as [$id, $lang, $name, $description]) {
            $check = $this->db->getQueryBuilder();
            $check->select($check->func()->count('*'))
                ->from('opencase_insightlevel')
                ->where($check->expr()->eq('id', $check->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                ->andWhere($check->expr()->eq('language', $check->createNamedParameter($lang)));
            $res   = $check->executeQuery();
            $count = (int)$res->fetchOne();
            $res->closeCursor();
            if ($count > 0) {
                continue;
            }
            $ins = $this->db->getQueryBuilder();
            $ins->insert('opencase_insightlevel')
                ->setValue('id',          $ins->createNamedParameter($id,          IQueryBuilder::PARAM_INT))
                ->setValue('language',    $ins->createNamedParameter($lang))
                ->setValue('name',        $ins->createNamedParameter($name))
                ->setValue('description', $ins->createNamedParameter($description))
                ->executeStatement();
            $inserted++;
        }
        $output->info("Seeded {$inserted} insight level row(s).");
    }

    private function seedRoles(IOutput $output): void {
        $inserted = 0;
        $qb = $this->db->getQueryBuilder();
        foreach (['User', 'Administrator', 'Template Designer'] as $roleName) {
            $qb->select('id')->from('opencase_roles')
                ->where($qb->expr()->eq('name', $qb->createNamedParameter($roleName)));
            $result = $qb->executeQuery();
            $exists = $result->fetchOne();
            $result->closeCursor();
            if ($exists === false) {
                $qb->resetQueryParts();
                $qb->insert('opencase_roles')
                    ->setValue('name', $qb->createNamedParameter($roleName))
                    ->executeStatement();
                $inserted++;
            }
            $qb->resetQueryParts();
        }
        $output->info("Seeded {$inserted} role(s).");
    }

    /**
     * Grants the local Nextcloud "admin" account full OpenCase access on a
     * fresh install, so there is at least one user who can configure the app.
     * Only acts if "admin" exists and has no rows yet in either table —
     * leaves everything alone once an admin has customized their own access.
     */
    private function seedAdminUserAccess(IOutput $output): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('uid')->from('users')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter('admin')));
        $result = $qb->executeQuery();
        $adminExists = $result->fetch() !== false;
        $result->closeCursor();

        if (!$adminExists) {
            return;
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from('opencase_user_priv_groups')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter('admin')));
        $result = $qb->executeQuery();
        $hasPrivGroups = (int)$result->fetchOne() > 0;
        $result->closeCursor();

        if (!$hasPrivGroups) {
            foreach (['opencaseadministrator', 'opencasetemplateadministrator'] as $privilegeType) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('opencase_user_priv_groups')
                    ->values([
                        'user_id'        => $qb->createNamedParameter('admin'),
                        'privilege_type' => $qb->createNamedParameter($privilegeType),
                        'updated_at'     => $qb->createNamedParameter($now),
                    ])
                    ->executeStatement();
            }
            $output->info('Granted admin OpenCase administrator privileges.');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from('opencase_user_roles')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter('admin')));
        $result = $qb->executeQuery();
        $hasRoles = (int)$result->fetchOne() > 0;
        $result->closeCursor();

        if (!$hasRoles) {
            // opencase_roles is seeded just above in this same run, in the
            // fixed order User, Administrator, Template Designer → ids 1, 2, 3.
            foreach ([1, 2, 3] as $roleId) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('opencase_user_roles')
                    ->values([
                        'user_id'     => $qb->createNamedParameter('admin'),
                        'role_id'     => $qb->createNamedParameter($roleId, IQueryBuilder::PARAM_INT),
                        'assigned_at' => $qb->createNamedParameter($now),
                        'assigned_by' => $qb->createNamedParameter('system'),
                    ])
                    ->executeStatement();
            }
            $output->info('Assigned all OpenCase roles to admin.');
        }
    }

    private function seedContactRoles(IOutput $output): void {
        $rows = [
            [1, 'da', 'Afsender'],
            [2, 'da', 'Modtager'],
            [1, 'en', 'Sender'],
            [2, 'en', 'Receiver'],
        ];
        $inserted = 0;
        foreach ($rows as [$id, $lang, $name]) {
            $this->db->executeStatement(
                'INSERT IGNORE INTO `*PREFIX*opencase_contactroles` (`id`, `language`, `name`) VALUES (?, ?, ?)',
                [$id, $lang, $name]
            );
            $inserted++;
        }
        $output->info("Seeded {$inserted} contact role rows.");
    }

    private function seedParticipantRoles(IOutput $output): void {
        $rows = [
            [1, 'da', 'Sagspart'],
            [2, 'da', 'Ansøger'],
            [3, 'da', 'Klager'],
            [4, 'da', 'Høringspart'],
            [1, 'en', 'Case party'],
            [2, 'en', 'Applicant'],
            [3, 'en', 'Complainant'],
            [4, 'en', 'Consultation party'],
        ];
        $inserted = 0;
        foreach ($rows as [$id, $lang, $name]) {
            $this->db->executeStatement(
                'INSERT IGNORE INTO `*PREFIX*opencase_participantroles` (`id`, `language`, `name`) VALUES (?, ?, ?)',
                [$id, $lang, $name]
            );
            $inserted++;
        }
        $output->info("Seeded {$inserted} participant role rows.");
    }

    private function seedContactTypes(IOutput $output): void {
        $rows = [
            [0, 'da', 'Ukendt'],
            [1, 'da', 'Medarbejder'],
            [2, 'da', 'Borger'],
            [3, 'da', 'Virksomhed'],
            [0, 'en', 'Unknown'],
            [1, 'en', 'Employee'],
            [2, 'en', 'Citizen'],
            [3, 'en', 'Company'],
        ];
        $inserted = 0;
        foreach ($rows as [$id, $lang, $name]) {
            $this->db->executeStatement(
                'INSERT IGNORE INTO `*PREFIX*opencase_contacttype` (`id`, `language`, `name`) VALUES (?, ?, ?)',
                [$id, $lang, $name]
            );
            $inserted++;
        }
        $output->info("Seeded {$inserted} contact type rows.");
    }

    private function seedConfig(IOutput $output): void {
        $description = 'Mønsteret der bruges til at generere sagsnumre automatisk. '
            . 'Brug yyyy for 4-cifret år, yy for 2-cifret år, og # for hvert ciffer i løbenummeret. '
            . 'Eksempel: yyyy-##### genererer 2026-00001.';

        $qb = $this->db->getQueryBuilder();
        $qb->select('config_key')->from('opencase_config')
            ->where($qb->expr()->eq('config_key', $qb->createNamedParameter('case_number_mask')));
        $result = $qb->executeQuery();
        $exists = $result->fetchOne();
        $result->closeCursor();

        if ($exists === false) {
            $qb->resetQueryParts();
            $qb->insert('opencase_config')->values([
                'config_key'   => $qb->createNamedParameter('case_number_mask'),
                'config_value' => $qb->createNamedParameter('yyyy-#####'),
                'name'         => $qb->createNamedParameter('Sagsnummermask'),
                'description'  => $qb->createNamedParameter($description),
            ])->executeStatement();
            $output->info('Seeded case_number_mask config.');
        } else {
            $output->info('Config case_number_mask already present, skipping.');
        }
    }

    private function seedCprEvents(IOutput $output): void {
        $rows = [
            ['003', 'Navngivning'],
            ['004', 'Navneændring'],
            ['005', 'Dødsfald'],
            ['006', 'Dødsfald - Som forsvundet'],
            ['007', 'Dødsfald - Som udvandret'],
            ['008', 'Dødsfald - Som nyfødt'],
            ['010', 'Forsvundet'],
            ['011', 'Genfinding'],
            ['013', 'Udvandret'],
            ['014', 'Genindvandret'],
            ['017', 'Sletning af personnummer'],
            ['018', 'Tidligere dobbeltnummer'],
            ['019', 'Udgår grundet omnummerering'],
            ['020', 'Myndiggjort'],
            ['021', 'Umyndiggjort'],
            ['023', 'Statsborgerskab ændret'],
            ['024', 'Faderskab tilkendt'],
            ['025', 'Fødsel - Mor'],
            ['027', 'Adoption - Barn'],
            ['028', 'Ægteskab indgået'],
            ['029', 'Seperation Danet'],
            ['030', 'Seperation ophørt'],
            ['031', 'Skilsmisse'],
            ['034', 'Flytning til kommunen'],
            ['035', 'Flytning fra Kommunen'],
            ['036', 'Flytning (fra) indenfor Kommunen'],
            ['037', 'Flytning (til) indenfor Kommunen'],
            ['038', 'Indkaldelse til militæret'],
            ['039', 'Hjemsendelse fra militæret'],
            ['040', 'Dan afsoning'],
            ['041', 'Slut afsoning'],
            ['042', 'Annullering af dødsfald'],
        ];
        $inserted = 0;
        foreach ($rows as [$code, $description]) {
            $check = $this->db->getQueryBuilder();
            $check->select($check->func()->count('*'))
                ->from('opencase_cprevents')
                ->where($check->expr()->eq('code', $check->createNamedParameter($code)));
            $res   = $check->executeQuery();
            $count = (int)$res->fetchOne();
            $res->closeCursor();
            if ($count > 0) {
                continue;
            }
            $ins = $this->db->getQueryBuilder();
            $ins->insert('opencase_cprevents')
                ->setValue('code',        $ins->createNamedParameter($code))
                ->setValue('description', $ins->createNamedParameter($description))
                ->executeStatement();
            $inserted++;
        }
        $output->info("Seeded {$inserted} CPR event row(s).");
    }

    /**
     * Ready-made AI prompt library entries (the built-in "quick action" prompts
     * shown in the AI assistant). created_by is set to 'system' since the
     * original author's user account won't exist on other installations.
     */
    private function seedAiPrompts(IOutput $output): void {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $rows = [
            [2, 'Opret borgersag', 'all', <<<'EOT'
                Opret en ny sag med sagstype Borgersag og titel '[Titel]', organisation #MinAfdeling, KLE nummer [KLE Nummer], handlingsfacet [Handlingsfacet ], følsomhed 'Følsomme personoplysninger eller følsomme forretningsdata' og indsigtsgrad Begrænset.
                Tilføj borgeren med cpr [CPR Nummer] som part med rollen "Primær part"
                EOT],
            [3, 'Opret virksomhedssag', 'all', <<<'EOT'
                Opret en ny sag med sagstype Virksomhedssag og titel '[Titel]', organisation #MinAfdeling, KLE nummer [KLE Nummer], handlingsfacet [Handlingsfacet ], følsomhed 'Ikke-fortrolige data' og indsigtsgrad Åben (Offentlig).
                Tilføj virksomheden med cvr [CVR Nummer] som part med rollen "Primær part"
                EOT],
            [4, 'Opret personalesag', 'all', <<<'EOT'
                Opret en ny sag med sagstype Personalesag og titel '[Titel]', organisation #MinAfdeling, KLE nummer [KLE Nummer], handlingsfacet [Handlingsfacet ], følsomhed 'Følsomme personoplysninger eller følsomme forretningsdata' og indsigtsgrad Begrænset.
                Tilføj medarbejderen med [Brugernavn] som part med rollen "Primær part"
                EOT],
            [5, 'Opret personalesagshierarki', 'all', <<<'EOT'
                Opret en ny sag med sagstype Personalesag og titel '[Titel]', organisation #MinAfdeling, KLE nummer 81.00.00, handlingsfacet A00, følsomhed 'Følsomme personoplysninger eller følsomme forretningsdata' og indsigtsgrad Begrænset.
                Tilføj medarbejderen med [Brugernavn] som part med rollen "Primær part"
                Tilføj 3 undersager:
                - Titel "Ferie" og KLE nummer 81.27.01 og tilføj medarbejderen med [Brugernavn] som part med rollen "Primær part".
                - Titel "Pension" og KLE nummer 81.22.00 og tilføj medarbejderen med [Brugernavn] som part med rollen "Primær part".
                - Titel "Sygdom og fravær" og KLE nummer 81.28.00 og tilføj medarbejderen med [Brugernavn] som part med rollen "Primær part".
                EOT],
            [6, 'Opret brev til borger', 'case', <<<'EOT'
                Opret et dokument med titlen '[Titel]' og kategori 'Udgående' og en fil med skabelonen '[Skabelon]' og tilføj borger med cpr nummer [CPR nummer] som modtager.
                Åben filen for redigering i Office.
                EOT],
            [7, 'Opret brev til virksomhed', 'case', <<<'EOT'
                Opret et dokument med titlen '[Titel]' og kategori 'Udgående' og en fil med skabelonen '[Skabelon]' og tilføj virksomhed med cvr nummer [CVR nummer] som modtager.
                Åben filen for redigering i Office.
                EOT],
            [8, 'Send kvittering til borger', 'case', <<<'EOT'
                Opret et dokument med titlen '[Titel]' og kategori 'Udgående' og en fil med skabelonen '[Kvitteringsskabelon]' og tilføj borger med cpr nummer [CPR nummer] som modtager.
                Send dokumentet med digital post.
                EOT],
            [9, 'Send kvittering til virksomhed', 'case', <<<'EOT'
                Opret et dokument med titlen '[Titel]' og kategori 'Udgående' og en fil med skabelonen '[Kvitteringsskabelon]' og tilføj virksomhed med cvr nummer [CVR nummer] som modtager.
                Send dokumentet med digital post.
                EOT],
            [10, 'Journaliser indkommet brev', 'inbox_document', <<<'EOT'
                Opret en ny sag med sagstype Borgersag og titel '[Titel]', organisation #MinAfdeling, KLE nummer [KLE Nummer], handlingsfacet [Handlingsfacet], følsomhed 'Følsomme personoplysninger eller følsomme forretningsdata' og indsigtsgrad Begrænset.
                Journaliser dokumentet på sagen.
                Tilføj afsender fra indkommet dokument som part med rollen "Primær part".
                EOT],
            [11, 'Journaliser og kvitter', 'inbox_document', <<<'EOT'
                Opret en ny sag med sagstype Borgersag og titel '[Titel]', organisation #MinAfdeling, KLE nummer [KLE Nummer], handlingsfacet [Handlingsfacet], følsomhed 'Følsomme personoplysninger eller følsomme forretningsdata' og indsigtsgrad Begrænset.
                Journaliser dokumentet på sagen.
                Tilføj afsender fra indkommet dokument som part med rollen "Primær part".
                Opret et nyt dokument med titlen 'Kvittering for modtagelse' og kategori 'Udgående' og en fil med skabelonen '[Kvitteringsskabelon].
                Tilføj afsender fra indkommende dokument som modtager.
                Send dokumentet med digital post.
                EOT],
            [12, 'Tilføj fil', 'document', <<<'EOT'
                Tilføj en fil med skabelonen '[Skabelon]'.
                Åben filen for redigering i Office.
                EOT],
            [13, 'Gennemse workflow', 'document', <<<'EOT'
                Opret Gennemse workflow med frist i dag plus [Frist: Antal dage] dage
                med disse brugere [Brugere]
                EOT],
            [14, 'Godkendelse workflow', 'document', <<<'EOT'
                Opret Godkendelse workflow med frist i dag plus [Frist: Antal dage] dage
                med disse brugere [Brugere]
                EOT],
        ];
        $inserted = 0;
        foreach ($rows as [$id, $title, $scope, $prompt]) {
            $check = $this->db->getQueryBuilder();
            $check->select($check->func()->count('*'))
                ->from('opencase_aiprompts')
                ->where($check->expr()->eq('id', $check->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $res   = $check->executeQuery();
            $count = (int)$res->fetchOne();
            $res->closeCursor();
            if ($count > 0) {
                continue;
            }
            $ins = $this->db->getQueryBuilder();
            $ins->insert('opencase_aiprompts')
                ->setValue('id',         $ins->createNamedParameter($id, IQueryBuilder::PARAM_INT))
                ->setValue('title',      $ins->createNamedParameter($title))
                ->setValue('scope',      $ins->createNamedParameter($scope))
                ->setValue('prompt',     $ins->createNamedParameter($prompt))
                ->setValue('created_by', $ins->createNamedParameter('system'))
                ->setValue('created_at', $ins->createNamedParameter($now))
                ->executeStatement();
            $inserted++;
        }
        $output->info("Seeded {$inserted} AI prompt row(s).");
    }

    /**
     * Action steps executed when an AI prompt from seedAiPrompts() is run.
     * prompt_id values below correspond to the fixed ids assigned there.
     */
    private function seedAiActions(IOutput $output): void {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $rows = [
            [9,  2,  0, 'create_case', ['title' => '[Titel]', 'kle_number' => '[KLE Nummer]', 'action_facet' => '[Handlingsfacet ]', 'organisation' => '#MinAfdeling', 'casetype' => 'Borgersag', 'sensitivity' => 'Følsomme personoplysninger eller følsomme forretningsdata', 'access_level' => 'Begrænset']],
            [10, 2,  1, 'add_citizen_participant', ['citizen_cpr' => '[CPR Nummer]', 'participant_role' => 'Primær part']],
            [13, 3,  0, 'create_case', ['title' => '[Titel]', 'kle_number' => '[KLE Nummer]', 'action_facet' => '[Handlingsfacet ]', 'organisation' => '#MinAfdeling', 'casetype' => 'Virksomhedssag', 'sensitivity' => 'Ikke-fortrolige data', 'access_level' => 'Åben (Offentlig)']],
            [14, 3,  1, 'add_company_participant', ['company_cvr' => '[CVR Nummer]', 'participant_role' => 'Primær part']],
            [17, 4,  0, 'create_case', ['title' => '[Titel]', 'kle_number' => '[KLE Nummer]', 'action_facet' => '[Handlingsfacet ]', 'organisation' => '#MinAfdeling', 'casetype' => 'Personalesag', 'sensitivity' => 'Følsomme personoplysninger eller følsomme forretningsdata', 'access_level' => 'Begrænset']],
            [18, 4,  1, 'add_employee_participant', ['employee' => '[Brugernavn]', 'participant_role' => 'Primær part']],
            [29, 5,  0, 'create_case', ['title' => '[Titel]', 'kle_number' => '81.00.00', 'action_facet' => 'A00', 'organisation' => '#MinAfdeling', 'casetype' => 'Personalesag', 'sensitivity' => 'Følsomme personoplysninger eller følsomme forretningsdata', 'access_level' => 'Begrænset']],
            [30, 5,  1, 'add_employee_participant', ['employee' => '[Brugernavn]', 'participant_role' => 'Primær part']],
            [31, 5,  2, 'create_sub_case', ['title' => 'Ferie', 'kle_number' => '81.27.01', 'employee' => '[Brugernavn]', 'participant_role' => 'Primær part']],
            [32, 5,  3, 'create_sub_case', ['title' => 'Pension', 'kle_number' => '81.22.00', 'employee' => '[Brugernavn]', 'participant_role' => 'Primær part']],
            [33, 5,  4, 'create_sub_case', ['title' => 'Sygdom og fravær', 'kle_number' => '81.28.00', 'employee' => '[Brugernavn]', 'participant_role' => 'Primær part']],
            [37, 6,  0, 'create_document', ['title' => '[Titel]', 'category' => 'Udgående', 'template' => '[Skabelon]', 'open_in_office' => true]],
            [38, 6,  1, 'add_citizen_contact', ['citizen_cpr' => '[CPR nummer]', 'citizen_role' => 'Modtager']],
            [39, 7,  0, 'create_document', ['title' => '[Titel]', 'category' => 'Udgående', 'template' => '[Skabelon]', 'open_in_office' => true]],
            [40, 7,  1, 'add_company_contact', ['company_cvr' => '[CVR nummer]', 'company_role' => 'Modtager']],
            [41, 8,  0, 'create_document', ['title' => '[Titel]', 'category' => 'Udgående']],
            [42, 8,  1, 'add_file_from_template', ['template' => '[Kvitteringsskabelon]']],
            [43, 8,  2, 'add_citizen_contact', ['citizen_cpr' => '[CPR nummer]', 'citizen_role' => 'Modtager']],
            [44, 8,  3, 'send_digital_post', ['can_be_replied' => true]],
            [45, 9,  0, 'create_document', ['title' => '[Titel]', 'category' => 'Udgående']],
            [46, 9,  1, 'add_file_from_template', ['template' => '[Kvitteringsskabelon]']],
            [47, 9,  2, 'add_company_contact', ['company_cvr' => '[CVR nummer]', 'company_role' => 'Modtager']],
            [48, 9,  3, 'send_digital_post', ['can_be_replied' => true]],
            [54, 10, 0, 'create_case_and_move_document', ['title' => '[Titel]', 'kle_number' => '[KLE Nummer]', 'action_facet' => '[Handlingsfacet]', 'organisation' => '#MinAfdeling', 'casetype' => 'Borgersag', 'sensitivity' => 'Følsomme personoplysninger eller følsomme forretningsdata', 'access_level' => 'Begrænset']],
            [55, 10, 1, 'add_sender_as_participant', ['participant_role' => 'Primær part']],
            [56, 11, 0, 'create_case_and_move_document', ['title' => '[Titel]', 'kle_number' => '[KLE Nummer]', 'action_facet' => '[Handlingsfacet]', 'organisation' => '#MinAfdeling', 'casetype' => 'Borgersag', 'sensitivity' => 'Følsomme personoplysninger eller følsomme forretningsdata', 'access_level' => 'Begrænset']],
            [57, 11, 1, 'add_sender_as_participant', ['participant_role' => 'Primær part']],
            [58, 11, 2, 'create_document', ['title' => 'Kvittering for modtagelse', 'category' => 'Udgående']],
            [59, 11, 3, 'add_file_from_template', ['template' => '[Kvitteringsskabelon]']],
            [60, 11, 4, 'add_sender_as_receiver', []],
            [61, 11, 5, 'send_digital_post', ['can_be_replied' => true]],
            [70, 12, 0, 'add_file_from_template', ['template' => '[Skabelon]', 'open_in_office' => true]],
            [71, 13, 0, 'create_workflow', ['workflow_type' => 'Gennemse', 'users' => '[Brugere]', 'deadline_days' => '[Frist: Antal dage]']],
            [72, 14, 0, 'create_workflow', ['workflow_type' => 'Godkendelse', 'users' => '[Brugere]', 'deadline_days' => '[Frist: Antal dage]']],
        ];
        $inserted = 0;
        foreach ($rows as [$id, $promptId, $sequenceIndex, $actionType, $actionParams]) {
            $check = $this->db->getQueryBuilder();
            $check->select($check->func()->count('*'))
                ->from('opencase_aiactions')
                ->where($check->expr()->eq('id', $check->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $res   = $check->executeQuery();
            $count = (int)$res->fetchOne();
            $res->closeCursor();
            if ($count > 0) {
                continue;
            }
            $ins = $this->db->getQueryBuilder();
            $ins->insert('opencase_aiactions')
                ->setValue('id',             $ins->createNamedParameter($id, IQueryBuilder::PARAM_INT))
                ->setValue('prompt_id',      $ins->createNamedParameter($promptId, IQueryBuilder::PARAM_INT))
                ->setValue('sequence_index', $ins->createNamedParameter($sequenceIndex, IQueryBuilder::PARAM_INT))
                ->setValue('action_type',    $ins->createNamedParameter($actionType))
                ->setValue('action_params',  $ins->createNamedParameter(json_encode($actionParams, JSON_UNESCAPED_UNICODE)))
                ->setValue('created_at',     $ins->createNamedParameter($now))
                ->executeStatement();
            $inserted++;
        }
        $output->info("Seeded {$inserted} AI action row(s).");
    }
}
