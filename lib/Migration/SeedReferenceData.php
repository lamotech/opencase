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
        $this->seedSensitivities($output);
        $this->seedCaseStatuses($output);
        $this->seedCaseTypes($output);
        $this->seedInsightLevels($output);
        $this->seedRoles($output);
        $this->seedContactRoles($output);
        $this->seedParticipantRoles($output);
        $this->seedConfig($output);
        $this->seedCprEvents($output);
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
}
