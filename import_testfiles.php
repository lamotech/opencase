#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Import test cases from /var/tmp/testfiles/Testfiles into OpenCase.
 *
 * Usage:
 *   sudo -u www-data php /var/www/nextcloud/apps/opencase/import_testfiles.php <nextcloud-user-id> [<testfiles-dir>]
 *
 * Example:
 *   sudo -u www-data php /var/www/nextcloud/apps/opencase/import_testfiles.php admin
 */

if (PHP_SAPI !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

if ($argc < 2) {
    echo "Usage: php import_testfiles.php <nextcloud-user-id> [<testfiles-dir>]\n";
    echo "Example: sudo -u www-data php import_testfiles.php admin\n";
    exit(1);
}

$userId      = $argv[1];
$testfilesDir = $argv[2] ?? '/var/tmp/testfiles/Testfiles';

if (!is_dir($testfilesDir)) {
    echo "Error: directory not found: {$testfilesDir}\n";
    exit(1);
}

function stripBom(string $s): string {
    return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
}

// Bootstrap Nextcloud
define('OC_CONSOLE', 1);
require_once '/var/www/nextcloud/lib/base.php';

use OCP\Server;
use OCP\IDBConnection;
use OCA\OpenCase\Service\CaseService;
use OCA\OpenCase\Service\DocumentService;
use OCA\OpenCase\Service\FileService;
use OCA\OpenCase\Db\OrganisationMapper;
use OCA\OpenCase\Db\SensitivityMapper;

// Resolve services from the DI container
$caseService        = Server::get(CaseService::class);
$documentService    = Server::get(DocumentService::class);
$fileService        = Server::get(FileService::class);
$organisationMapper = Server::get(OrganisationMapper::class);
$sensitivityMapper  = Server::get(SensitivityMapper::class);
$db                 = Server::get(IDBConnection::class);

// Fetch all UIDs from oc_users for random responsibleUserId assignment
$result   = $db->executeQuery('SELECT uid FROM oc_users');
$allUids  = $result->fetchAll(\PDO::FETCH_COLUMN);
$result->closeCursor();

if (empty($allUids)) {
    echo "Warning: no users found in oc_users, responsibleUserId will be null.\n";
}

// Collect subfolders (each is one test case)
$folders = array_filter(glob($testfilesDir . '/*'), 'is_dir');
sort($folders);

if (empty($folders)) {
    echo "No subfolders found in {$testfilesDir}\n";
    exit(0);
}

$ok    = 0;
$fails = 0;

foreach ($folders as $folder) {
    $folderName = basename($folder);
    echo "\n--- Processing {$folderName} ---\n";

    try {
        // Read case.json
        $caseJsonPath = $folder . '/case.json';
        if (!is_file($caseJsonPath)) {
            throw new \RuntimeException("Missing case.json in {$folder}");
        }
        $caseJson = json_decode(stripBom(file_get_contents($caseJsonPath)), true, 512, JSON_THROW_ON_ERROR);
        $caseData = $caseJson['case'];

        // Read document.json
        $docJsonPath = $folder . '/document.json';
        if (!is_file($docJsonPath)) {
            throw new \RuntimeException("Missing document.json in {$folder}");
        }
        $docJson = json_decode(stripBom(file_get_contents($docJsonPath)), true, 512, JSON_THROW_ON_ERROR);
        $docData = $docJson['document'];

        // Resolve org name from UUID
        $org = $organisationMapper->findByUuid($caseData['organisation']);
        if ($org === null) {
            throw new \RuntimeException("Organisation UUID not found: {$caseData['organisation']}");
        }
        $orgName = $org->getOrgName();

        // Resolve sensitivity key from UUID
        $sensitivity = $sensitivityMapper->findByUuid($caseData['sensitivity']);
        if ($sensitivity === null) {
            throw new \RuntimeException("Sensitivity UUID not found: {$caseData['sensitivity']}");
        }
        $sensitivityKey = $sensitivity->getKey();

        $responsibleUserId = !empty($allUids) ? $allUids[array_rand($allUids)] : $userId;

        // Create case
        $case = $caseService->create(
            title:                  $caseData['title'],
            organisation:           $orgName,
            classificationCode:     $caseData['class_subject_code'],
            sensitivity:            $sensitivityKey,
            userId:                 $responsibleUserId,
            responsibleUserId:      $responsibleUserId,
            insightLevelId:         isset($caseData['insightlevel']) ? (int)$caseData['insightlevel'] : null,
            classificationFacetUuid: $caseData['class_facet'],
        );
        echo "  Case created:    {$case->getCaseNumber()} — {$case->getTitle()}\n";

        // Create document on the case
        $document = $documentService->create(
            caseId:             $case->getId(),
            title:              $docData['title'],
            documentType:       null,
            userId:             $responsibleUserId,
            insightLevelId:     isset($docData['insightlevel']) ? (int)$docData['insightlevel'] : null,
            documentDate:       $docData['document_date'] ?? null,
            receivedDate:       null,
            registeredDate:     $docData['registrered_date'] ?? null,
            documentCategoryId: isset($docData['documentcategory']) ? (int)$docData['documentcategory'] : null,
        );
        echo "  Document created: {$document->getDocumentNumber()} — {$document->getTitle()}\n";

        // Find and upload the .docx file
        $docxFiles = glob($folder . '/*.docx');
        if (empty($docxFiles)) {
            echo "  Warning: no .docx file found in {$folder}, skipping file upload.\n";
        } else {
            $docxPath = $docxFiles[0];
            $filename = basename($docxPath);

            $content = file_get_contents($docxPath);
            if ($content === false) {
                throw new \RuntimeException("Failed to read file: {$docxPath}");
            }

            $fileService->upload(
                documentId:       $document->getId(),
                originalFilename: $filename,
                mimeType:         'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                content:          $content,
                userId:           $responsibleUserId,
            );
            echo "  File uploaded:   {$filename}\n";
        }

        $ok++;

    } catch (\Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
        if (getenv('DEBUG')) {
            echo $e->getTraceAsString() . "\n";
        }
        $fails++;
    }
}

echo "\n=== Done: {$ok} succeeded, {$fails} failed ===\n";
exit($fails > 0 ? 1 : 0);
