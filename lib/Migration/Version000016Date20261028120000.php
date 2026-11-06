<?php

declare(strict_types=1);

namespace OCA\OpenCase\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * opencase_phrases.content — the phrase text as rich text (HTML from the
 * RichTextEditor, see PhraseHtml), used when the phrase has no file.
 *
 * Until now a phrase without a file used its description as plain text.
 * For those phrases the description is moved into content (one paragraph
 * per line); the description is cleared, as it was holding the letter text.
 */
class Version000016Date20261028120000 extends SimpleMigrationStep {

    public function __construct(private IDBConnection $db) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('opencase_phrases');
        if (!$table->hasColumn('content')) {
            $table->addColumn('content', Types::TEXT, ['notnull' => false]);
            return $schema;
        }
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $qb = $this->db->getQueryBuilder();
        $rows = $qb->select('id', 'description')
            ->from('opencase_phrases')
            ->where($qb->expr()->orX($qb->expr()->isNull('storage_filename'), $qb->expr()->eq('storage_filename', $qb->createNamedParameter(''))))
            ->andWhere($qb->expr()->isNull('content'))
            ->andWhere($qb->expr()->isNotNull('description'))
            ->executeQuery()
            ->fetchAll();

        foreach ($rows as $row) {
            $text = trim(str_replace(["\r\n", "\r"], "\n", (string)$row['description']));
            if ($text === '') {
                continue;
            }
            $html = implode('', array_map(
                fn(string $line) => '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>',
                explode("\n", $text),
            ));
            $update = $this->db->getQueryBuilder();
            $update->update('opencase_phrases')
                ->set('content', $update->createNamedParameter($html))
                ->set('description', $update->createNamedParameter(null))
                ->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])))
                ->executeStatement();
            $output->info("OpenCase: phrase {$row['id']} — description moved to the phrase text");
        }
    }
}
