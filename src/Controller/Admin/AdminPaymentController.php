<?php

namespace App\Controller\Admin;

use App\Entity\Payment;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        $query  = trim($request->query->get('q', ''));
        $status = $this->parseStatus($request);
        $method = $this->parseMethod($request);
        [$from, $to] = $this->parseDateRange($request);

        $payments = $this->filterByStatus($paymentRepository->search($query, $from, $to, $method), $status);
        $total    = number_format(array_sum(array_map(static fn (Payment $p) => (float) $p->getAmount(), $payments)), 2, '.', '');

        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/payments/_list.html.twig', [
                'payments' => $payments,
                'total'    => $total,
            ]);
        }

        return $this->render('admin/payments/index.html.twig', [
            'payments'      => $payments,
            'total'         => $total,
            'currentQuery'  => $query,
            'currentFrom'   => $request->query->get('from', ''),
            'currentTo'     => $request->query->get('to', ''),
            'currentStatus' => $status,
            'currentMethod' => $method,
        ]);
    }

    #[Route('/export', name: 'app_admin_payments_export')]
    public function export(Request $request, PaymentRepository $paymentRepository): StreamedResponse
    {
        $query  = trim($request->query->get('q', ''));
        $status = $this->parseStatus($request);
        $method = $this->parseMethod($request);
        [$from, $to] = $this->parseDateRange($request);

        $payments = $this->filterByStatus($paymentRepository->search($query, $from, $to, $method), $status);

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
                    $payment->getOrder() ? 'Sale #' . $payment->getOrder()->getId() : ($payment->isDonation() ? 'Donation' : 'Booking'),
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

    /** Removes a payment record outright — restricted to admins, and only while it never succeeded (or has since been fully/partially refunded away), since a still-succeeded payment is real financial history worth keeping. */
    #[Route('/{id}/delete', name: 'app_admin_payment_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Payment $payment, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('admin_payment_delete_' . $payment->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Invalid request — please try again.');
            return $this->redirectToRoute('app_admin_payments');
        }

        if ($payment->getStatus() === Payment::STATUS_SUCCEEDED) {
            $this->addFlash('error', 'A succeeded payment can\'t be deleted — it\'s kept as a record.');
            return $this->redirectToRoute('app_admin_payments');
        }

        $em->remove($payment);
        $em->flush();

        $this->addFlash('success', 'Payment deleted.');

        return $this->redirectToRoute('app_admin_payments');
    }

    private function parseStatus(Request $request): string
    {
        $status = $request->query->get('status', '');
        $valid  = [Payment::STATUS_PENDING, Payment::STATUS_SUCCEEDED, Payment::STATUS_FAILED, Payment::STATUS_PARTIALLY_REFUNDED, Payment::STATUS_REFUNDED];

        return in_array($status, $valid, true) ? $status : '';
    }

    private function parseMethod(Request $request): string
    {
        $method = $request->query->get('method', '');
        $valid  = [Payment::METHOD_ONLINE, Payment::METHOD_TERMINAL, Payment::METHOD_CASH];

        return in_array($method, $valid, true) ? $method : '';
    }

    /**
     * Payment::getStatus() is derived (from succeededAt/failedAt/refunds), not a persisted column,
     * so it can't be filtered in the repository's DQL — done here in PHP instead.
     *
     * @param Payment[] $payments
     * @return Payment[]
     */
    private function filterByStatus(array $payments, string $status): array
    {
        if ($status === '') {
            return $payments;
        }

        return array_values(array_filter($payments, static fn (Payment $p) => $p->getStatus() === $status));
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
