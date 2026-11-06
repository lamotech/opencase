<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCA\OpenCase\Db\Organisation;
use OCA\OpenCase\Db\OrganisationMapper;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Organisation logos, stored as {storage_base_dir}/_logos/{logo_file} and
 * merged into templates at {{organisation.logo}} (see LogoComposer).
 *
 * An organisation without a logo of its own uses the logo of its nearest
 * ancestor that has one.
 */
class LogoService {

    /** Accepted logo file types: extension => MIME type. */
    public const TYPES = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
    ];

    private const MAX_SIZE = 5 * 1024 * 1024;

    private string $logosDir;

    public function __construct(
        private OrganisationMapper $organisationMapper,
        private IL10N $l10n,
        IConfig $config,
    ) {
        $defaultDir = rtrim($config->getSystemValueString('datadirectory', ''), '/') . '/opencase_storage';
        $baseDir = $config->getAppValue('opencase', 'storage_base_dir', $defaultDir);
        $this->logosDir = rtrim($baseDir, '/') . '/_logos';
    }

    /**
     * Store an uploaded image as the organisation's logo, replacing any
     * previous one.
     *
     * @throws NotFoundException if the organisation does not exist
     * @throws \InvalidArgumentException if the file is not a supported image
     */
    public function upload(string $orgUuid, string $originalFilename, string $tmpPath): Organisation {
        $org = $this->getOrganisation($orgUuid);

        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw new \InvalidArgumentException($this->l10n->t('Kun PNG-, JPEG-, GIF- og SVG-billeder kan bruges som logo'));
        }
        $size = filesize($tmpPath);
        if ($size === false || $size === 0 || $size > self::MAX_SIZE) {
            throw new \InvalidArgumentException($this->l10n->t('Logoet må højst fylde 5 MB'));
        }
        if ($this->dimensions($tmpPath, $ext === 'jpeg' ? 'jpg' : $ext) === null) {
            throw new \InvalidArgumentException($this->l10n->t('Filen er ikke et gyldigt billede'));
        }

        $this->ensureDir();
        $logoFile = bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        if (!copy($tmpPath, $this->logosDir . '/' . $logoFile)) {
            throw new \RuntimeException('Failed to store logo file');
        }

        $this->removeFile($org->getLogoFile());
        $this->organisationMapper->updateLogoFile($orgUuid, $logoFile);
        $org->setLogoFile($logoFile);
        return $org;
    }

    /**
     * @throws NotFoundException if the organisation does not exist
     */
    public function delete(string $orgUuid): void {
        $org = $this->getOrganisation($orgUuid);
        $this->removeFile($org->getLogoFile());
        $this->organisationMapper->updateLogoFile($orgUuid, null);
    }

    /**
     * The organisation's own logo file, for download.
     *
     * @return array{path: string, mime_type: string, filename: string}|null
     */
    public function getOwnLogo(string $orgUuid): ?array {
        $org = $this->organisationMapper->findByUuid($orgUuid);
        $path = $this->pathOf($org?->getLogoFile());
        if ($path === null) {
            return null;
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return ['path' => $path, 'mime_type' => self::TYPES[$ext], 'filename' => 'logo.' . $ext];
    }

    /**
     * The logo to use for an organisation: its own, or else the nearest
     * ancestor's. Dimensions are in pixels (SVG: user units).
     *
     * @return array{path: string, mime_type: string, width: int, height: int, org_uuid: string, org_name: string}|null
     */
    public function resolve(?string $orgUuid): ?array {
        $seen = [];
        while ($orgUuid !== null && $orgUuid !== '' && !isset($seen[$orgUuid])) {
            $seen[$orgUuid] = true;
            $org = $this->organisationMapper->findByUuid($orgUuid);
            if ($org === null) {
                return null;
            }
            $path = $this->pathOf($org->getLogoFile());
            if ($path !== null) {
                $ext = pathinfo($path, PATHINFO_EXTENSION);
                $dimensions = $this->dimensions($path, $ext);
                if ($dimensions !== null) {
                    return [
                        'path'      => $path,
                        'mime_type' => self::TYPES[$ext],
                        'width'     => $dimensions[0],
                        'height'    => $dimensions[1],
                        'org_uuid'  => $org->getOrgUuid(),
                        'org_name'  => $org->getOrgName(),
                    ];
                }
            }
            $orgUuid = $org->getOrgParentUuid();
        }
        return null;
    }

    /**
     * Width and height of an image, or null when it cannot be read.
     *
     * @return array{0: int, 1: int}|null
     */
    private function dimensions(string $path, string $ext): ?array {
        if ($ext === 'svg') {
            return $this->svgDimensions($path);
        }
        $info = @getimagesize($path);
        if ($info === false || $info[0] <= 0 || $info[1] <= 0) {
            return null;
        }
        $expected = self::TYPES[$ext];
        return $info['mime'] === $expected ? [$info[0], $info[1]] : null;
    }

    /**
     * SVG size from width/height (any unit, taken as-is) or the viewBox.
     * A file whose root element is not <svg> is rejected.
     *
     * @return array{0: int, 1: int}|null
     */
    private function svgDimensions(string $path): ?array {
        $xml = file_get_contents($path);
        if ($xml === false) {
            return null;
        }
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml, LIBXML_NONET) || $doc->documentElement?->localName !== 'svg') {
            return null;
        }
        $root = $doc->documentElement;
        $width = (float)$root->getAttribute('width');
        $height = (float)$root->getAttribute('height');
        if (($width <= 0 || $height <= 0 || str_ends_with(trim($root->getAttribute('width')), '%'))
            && preg_match('/^\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*$/', $root->getAttribute('viewBox'), $m)) {
            [$width, $height] = [(float)$m[1], (float)$m[2]];
        }
        if ($width <= 0 || $height <= 0) {
            // No usable size: assume a landscape logo.
            return [200, 100];
        }
        return [max(1, (int)round($width)), max(1, (int)round($height))];
    }

    private function getOrganisation(string $orgUuid): Organisation {
        $org = $this->organisationMapper->findByUuid($orgUuid);
        if ($org === null) {
            throw new NotFoundException("Organisation {$orgUuid} not found");
        }
        return $org;
    }

    private function pathOf(?string $logoFile): ?string {
        if ($logoFile === null || $logoFile === '' || basename($logoFile) !== $logoFile) {
            return null;
        }
        $ext = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
        $path = $this->logosDir . '/' . $logoFile;
        return isset(self::TYPES[$ext]) && is_file($path) ? $path : null;
    }

    private function removeFile(?string $logoFile): void {
        $path = $this->pathOf($logoFile);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function ensureDir(): void {
        if (!is_dir($this->logosDir) && !mkdir($this->logosDir, 0750, true) && !is_dir($this->logosDir)) {
            throw new \RuntimeException("Failed to create logos directory: {$this->logosDir}");
        }
    }
}
