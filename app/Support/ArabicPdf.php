<?php

namespace App\Support;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders an HTML view to a PDF whose Arabic letters join and run right to left.
 * Dompdf draws Arabic as separate glyphs; mPDF shapes IBM Plex Sans Arabic.
 */
class ArabicPdf
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data): string
    {
        $temp = storage_path('framework/cache/mpdf');
        if (! is_dir($temp)) {
            mkdir($temp, 0775, true);
        }

        $defaults = (new ConfigVariables)->getDefaults();
        $fontData = (new FontVariables)->getDefaults();
        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'margin_header' => 0,
            'margin_footer' => 0,
            'fontDir' => array_merge($defaults['fontDir'], [resource_path('fonts')]),
            'fontdata' => $fontData['fontdata'] + [
                'ibmplexarabic' => [
                    'R' => 'IBMPlexSansArabic-Regular.ttf',
                    'B' => 'IBMPlexSansArabic-Bold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font' => 'ibmplexarabic',
            'tempDir' => $temp,
        ]);
        $pdf->WriteHTML(view($view, $data)->render());

        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
