<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

/**
 * Wraps the headless LibreOffice binary for converting documents to PDF.
 */
class LibreOfficeService {

    /**
     * Convert a file to PDF using LibreOffice headless.
     *
     * LibreOffice writes the PDF to $outDir with the same base name as the input.
     *
     * @return string Path of the generated PDF file.
     */
    public function convertToPdf(string $inputPath, string $outDir): string {
        $libreOffice = $this->findBinary();

        // LibreOffice needs a writable HOME and a unique user-installation directory.
        // When run as www-data, $HOME is /var/www which is not writable, causing
        // "User installation could not be completed" (exit 77).
        $profileDir = sys_get_temp_dir() . '/opencase_lo_' . bin2hex(random_bytes(8));
        mkdir($profileDir, 0700, true);

        try {
            $cmd = sprintf(
                'HOME=%s %s -env:UserInstallation=%s --headless --convert-to pdf:writer_pdf_Export --outdir %s %s 2>&1',
                escapeshellarg(sys_get_temp_dir()),
                escapeshellarg($libreOffice),
                escapeshellarg('file://' . $profileDir),
                escapeshellarg($outDir),
                escapeshellarg($inputPath),
            );

            exec($cmd, $output, $exitCode);
        } finally {
            $this->removeDir($profileDir);
        }

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "LibreOffice PDF conversion failed (exit {$exitCode}): " . implode("\n", $output)
            );
        }

        // LibreOffice outputs <basename>.pdf in $outDir
        $pdfName = pathinfo($inputPath, PATHINFO_FILENAME) . '.pdf';
        $pdfPath = rtrim($outDir, '/') . '/' . $pdfName;

        if (!file_exists($pdfPath)) {
            throw new \RuntimeException("LibreOffice did not produce expected PDF: {$pdfPath}");
        }

        return $pdfPath;
    }

    /**
     * Verify LibreOffice is available, returning the resolved binary path.
     */
    public function findBinary(): string {
        foreach (['/usr/bin/libreoffice', '/usr/bin/soffice', '/usr/local/bin/libreoffice', '/usr/local/bin/soffice'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        throw new \RuntimeException('LibreOffice binary not found. Install libreoffice on the server.');
    }

    private function removeDir(string $path): void {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDir($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
