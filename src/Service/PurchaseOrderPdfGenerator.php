<?php

namespace App\Service;

use App\Entity\PurchaseOrder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/** Renders a purchase order as a PDF to download or email to the supplier — same approach as CertificationPdfGenerator. */
class PurchaseOrderPdfGenerator
{
    public function __construct(private readonly Environment $twig) {}

    public function generate(PurchaseOrder $po): string
    {
        $html = $this->twig->render('pdf/purchase_order.html.twig', [
            'po' => $po,
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

    public function filename(PurchaseOrder $po): string
    {
        return 'y-wal-' . strtolower((string) $po->getNumber()) . '.pdf';
    }
}
