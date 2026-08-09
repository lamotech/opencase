<?php

declare(strict_types=1);

namespace OCA\OpenCase\Versions;

use OCA\Files_Versions\Versions\IVersion;
use OCA\Files_Versions\Versions\IVersionBackend;
use OCP\Files\FileInfo;
use OCP\IUser;

/**
 * Represents a single stored version of an OpenCase file.
 *
 * Versions are stored on disk at:
 *   {baseDir}/{year}/{month}/{case_id}/{doc_id}/_versions/{timestamp}
 *
 * The timestamp (unix) doubles as both the revision ID and the version filename.
 */
class OpenCaseVersion implements IVersion {

    public function __construct(
        private int $timestamp,
        private string $name,
        private int|float $size,
        private string $mimetype,
        /** Absolute path to the version file on disk */
        private string $physicalPath,
        private FileInfo $sourceFile,
        private IVersionBackend $backend,
        private IUser $user,
    ) {
    }

    public function getBackend(): IVersionBackend {
        return $this->backend;
    }

    public function getSourceFile(): FileInfo {
        return $this->sourceFile;
    }

    /** @return int */
    public function getRevisionId() {
        return $this->timestamp;
    }

    public function getTimestamp(): int {
        return $this->timestamp;
    }

    public function getSize(): int|float {
        return $this->size;
    }

    public function getSourceFileName(): string {
        return $this->name;
    }

    public function getMimeType(): string {
        return $this->mimetype;
    }

    /** Returns the absolute disk path of this version file. */
    public function getVersionPath(): string {
        return $this->physicalPath;
    }

    public function getUser(): IUser {
        return $this->user;
    }
}
