<?php

namespace App\Service;

use App\Entity\UserCertification;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/** Renders a completed certification record as a PDF snapshot. */
class CertificationPdfGenerator
{
    public function __construct(private readonly Environment $twig) {}

    public function generate(UserCertification $record): string
    {
        $html = $this->twig->render('pdf/certification_certificate.html.twig', [
            'record' => $record,
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
