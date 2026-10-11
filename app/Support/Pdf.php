<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Makes a PDF from HTML, in-process, with dompdf. It fetches nothing over the network and runs
 * no script or PHP from the document, so the HTML must stand alone: its own styles, no images
 * by URL. Every document is A4 with a small grey footer on each page.
 */
class Pdf
{
    /**
     * @param  string  $footer  printed at the foot of every page; {PAGE_NUM} and {PAGE_COUNT} are filled in
     */
    public static function render(string $html, string $footer): string
    {
        $dirs = storage_path('framework/cache/dompdf');

        if (! is_dir($dirs)) {
            mkdir($dirs, 0775, true);
        }

        $dompdf = new Dompdf(new Options([
            'isRemoteEnabled' => false,
            'isJavascriptEnabled' => false,
            'isPhpEnabled' => false,
            'fontDir' => $dirs,
            'fontCache' => $dirs,
            'tempDir' => $dirs,
            'defaultFont' => 'DejaVu Sans',
        ]));
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($html);
        $dompdf->render();
        $dompdf->getCanvas()->page_text(36, 810, $footer, $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.45, 0.45, 0.45]);

        return (string) $dompdf->output();
    }
}
