<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Estate (fast ejendom) schema.
 *
 * Tables:
 *   opencase_aggregated_estates          — "Samlet fast ejendom" master records
 *   opencase_estate_addresses            — addresses linked to an aggregated estate
 *   opencase_land_plots                  — cadastral land plots linked to an aggregated estate
 *   opencase_apartments                  — "Ejerlejlighed" units linked to an aggregated estate
 *   opencase_buildings_land_by_other     — "Bygning på fremmed grund" units linked to an aggregated estate
 *   opencase_estate                      — estate records (points at one of the above per type)
 *   opencase_caseestates                 — case ↔ estate mapping with a role
 */
class Version000002Date20260816000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── opencase_aggregated_estates ───────────────────────────────────
        if (!$schema->hasTable('opencase_aggregated_estates')) {
            $table = $schema->createTable('opencase_aggregated_estates');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('bfenumber', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('location_address', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('municipality_code', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->addColumn('streetname', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('road_code', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->addColumn('housenumber', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->addColumn('zipcode', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->addColumn('zipdistrict', Types::STRING, ['notnull' => false, 'length' => 128, 'default' => null]);
            $table->addColumn('additional_city_name', Types::STRING, ['notnull' => false, 'length' => 128, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['bfenumber'], 'oc_aggest_bfe');
        }

        // ── opencase_estate_addresses ─────────────────────────────────────
        if (!$schema->hasTable('opencase_estate_addresses')) {
            $table = $schema->createTable('opencase_estate_addresses');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('aggregated_estates_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('location_address', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('floor', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->addColumn('door', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['aggregated_estates_id'], 'oc_estaddr_agg_id');
        }

        // ── opencase_land_plots ───────────────────────────────────────────
        if (!$schema->hasTable('opencase_land_plots')) {
            $table = $schema->createTable('opencase_land_plots');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('aggregated_estates_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('cadastral_number', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->addColumn('cadastral_code', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('cadastral_name', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('registred_area', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null, 'comment' => 'Registreret areal, m²']);
            $table->addColumn('road_area', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null, 'comment' => 'Vejareal, m²']);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['aggregated_estates_id'], 'oc_landplot_agg_id');
        }

        // ── opencase_apartments ───────────────────────────────────────────
        if (!$schema->hasTable('opencase_apartments')) {
            $table = $schema->createTable('opencase_apartments');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('aggregated_estates_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('bfenumber', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('location_address', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->addColumn('floor', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->addColumn('door', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->addColumn('apartment_number', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $table->addColumn('alloc_factor_denom', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null, 'comment' => 'Fordelingstal nævner']);
            $table->addColumn('alloc_factor_nom', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null, 'comment' => 'Fordelingstal tæller']);
            $table->addColumn('area', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null, 'comment' => 'Samlet areal, m²']);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['aggregated_estates_id'], 'oc_apt_agg_id');
        }

        // ── opencase_buildings_land_by_other ──────────────────────────────
        if (!$schema->hasTable('opencase_buildings_land_by_other')) {
            $table = $schema->createTable('opencase_buildings_land_by_other');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('aggregated_estates_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('bfenumber', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('location_address', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['aggregated_estates_id'], 'oc_blbo_agg_id');
        }

        // ── opencase_estate ────────────────────────────────────────────────
        if (!$schema->hasTable('opencase_estate')) {
            $table = $schema->createTable('opencase_estate');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32,
                'comment' => 'Samlet fast ejendom | Ejerlejlighed | Bygning på fremmed grund']);
            $table->addColumn('bfenummer', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('aggregated_estates_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('apartment_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->addColumn('buildings_land_by_other_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['aggregated_estates_id'], 'oc_estate_agg_id');
            $table->addIndex(['apartment_id'], 'oc_estate_apt_id');
            $table->addIndex(['buildings_land_by_other_id'], 'oc_estate_blbo_id');
        }

        // ── opencase_caseestates ───────────────────────────────────────────
        if (!$schema->hasTable('opencase_caseestates')) {
            $table = $schema->createTable('opencase_caseestates');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('case_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('estate_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('role_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true, 'default' => null]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['case_id'], 'oc_ce_case_id');
            $table->addIndex(['estate_id'], 'oc_ce_estate_id');
        }

        // ── opencase_estateroles ──────────────────────────────────────────
        if (!$schema->hasTable('opencase_estateroles')) {
            $table = $schema->createTable('opencase_estateroles');
            $table->addColumn('id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('language', Types::STRING, ['notnull' => true, 'length' => 10]);
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $table->addColumn('expired', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->setPrimaryKey(['id', 'language']);
        }

        return $schema;
    }
}
