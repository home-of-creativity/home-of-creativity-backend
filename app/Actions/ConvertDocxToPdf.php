<?php

namespace App\Actions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/** Turn a Word file into a PDF with LibreOffice. No page is photographed. */
class ConvertDocxToPdf
{
    public function handle(string $contents): string
    {
        abort_if($contents === '', 422, 'The Word file is empty.');

        $binary = $this->binary();
        abort_if($binary === null, 422, 'LibreOffice is not installed. Set LIBREOFFICE_BINARY to soffice.');

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hoc-docx-'.bin2hex(random_bytes(6));
        File::makeDirectory($dir, 0700, true);
        $profile = $dir.DIRECTORY_SEPARATOR.'profile';
        File::makeDirectory($profile, 0700, true);
        $docx = $dir.DIRECTORY_SEPARATOR.'report.docx';
        file_put_contents($docx, $contents);

        try {
            $result = Process::timeout(120)->run([
                $binary,
                '--headless',
                '--norestore',
                '--nolockcheck',
                '--nologo',
                '-env:UserInstallation='.$this->fileUrl($profile),
                '--convert-to',
                'pdf',
                '--outdir',
                $dir,
                $docx,
            ]);

            $pdf = $dir.DIRECTORY_SEPARATOR.'report.pdf';
            abort_unless(
                $result->successful() && is_file($pdf) && str_starts_with((string) file_get_contents($pdf, false, null, 0, 5), '%PDF-'),
                422,
                'LibreOffice could not convert the Word file.',
            );

            return (string) file_get_contents($pdf);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function binary(): ?string
    {
        $configured = trim((string) config('services.libreoffice.binary'));
        if ($configured !== '' && $configured !== 'soffice') {
            return is_file($configured) ? $configured : null;
        }

        $finder = new ExecutableFinder();
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->windowsDefault()
                ?: $finder->find('soffice.com')
                ?: $finder->find('soffice');
        }

        return $finder->find('soffice');
    }

    private function windowsDefault(): ?string
    {
        foreach ([
            'C:\\Program Files\\LibreOffice\\program\\soffice.com',
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function fileUrl(string $path): string
    {
        $slash = str_replace('\\', '/', $path);
        $slash = str_replace(' ', '%20', $slash);

        return PHP_OS_FAMILY === 'Windows' ? 'file:///'.$slash : 'file://'.$slash;
    }
}
