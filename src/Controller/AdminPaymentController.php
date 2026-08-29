<?php

namespace App\Controller;

use App\Repository\PaymentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/payments')]
#[IsGranted('ROLE_TEAM')]
class AdminPaymentController extends AbstractController
{
    #[Route('', name: 'app_admin_payments')]
    public function index(Request $request, PaymentRepository $paymentRepository): Response
    {
        $query = trim($request->query->get('q', ''));
        [$from, $to] = $this->parseDateRange($request);

        $payments = $paymentRepository->search($query, $from, $to);

        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/payments/_list.html.twig', [
                'payments' => $payments,
            ]);
        }

        return $this->render('admin/payments/index.html.twig', [
            'payments'     => $payments,
            'currentQuery' => $query,
            'currentFrom'  => $request->query->get('from', ''),
            'currentTo'    => $request->query->get('to', ''),
        ]);
    }

    #[Route('/export', name: 'app_admin_payments_export')]
    public function export(Request $request, PaymentRepository $paymentRepository): StreamedResponse
    {
        $query = trim($request->query->get('q', ''));
        [$from, $to] = $this->parseDateRange($request);

        $payments = $paymentRepository->search($query, $from, $to);

        $response = new StreamedResponse(function () use ($payments) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['ID', 'Date', 'Member', 'Email', 'Type', 'Method', 'Amount', 'Currency', 'Status', 'Refunded', 'Stripe Payment Intent ID']);

            foreach ($payments as $payment) {
                $user = $payment->getUser();
                fputcsv($handle, [
                    $payment->getId(),
                    $payment->getCreatedAt()->format('Y-m-d H:i'),
                    trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? '')),
                    $user->getEmail(),
                    $payment->isDonation() ? 'Donation' : 'Booking',
                    ucfirst($payment->getMethod()),
                    $payment->getAmount(),
                    strtoupper($payment->getCurrency()),
                    $payment->getStatusLabel(),
                    $payment->getTotalRefunded(),
                    $payment->getStripePaymentIntentId(),
                ]);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="payments-' . (new \DateTimeImmutable())->format('Y-m-d') . '.csv"');

        return $response;
    }

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} */
    private function parseDateRange(Request $request): array
    {
        $fromRaw = $request->query->get('from', '');
        $toRaw   = $request->query->get('to', '');

        $from = $fromRaw !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d', $fromRaw)?->setTime(0, 0, 0) : null;
        $to   = $toRaw !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d', $toRaw)?->setTime(23, 59, 59) : null;

        return [$from ?: null, $to ?: null];
    }
}
