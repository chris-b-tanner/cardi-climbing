<?php

namespace App\Controller;

use App\Entity\Payment;
use App\Entity\User;
use App\Service\CartService;
use App\Service\PaymentMailer;
use App\Service\SalesOrderService;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The cart itself, and paying for it — the cart is session-only right up until checkout(), which is the one moment a real SalesOrder gets created. */
#[Route('/cart')]
#[IsGranted('ROLE_USER')]
class CartController extends AbstractController
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly string $stripePublishableKey,
    ) {}

    #[Route('', name: 'app_cart')]
    public function index(CartService $cartService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('shop/cart.html.twig', [
            'lines'                => $cartService->getLines($user),
            'total'                => $cartService->getTotal($user),
            'stripePublishableKey' => $this->stripePublishableKey,
        ]);
    }

    #[Route('/remove', name: 'app_cart_remove', methods: ['POST'])]
    public function remove(Request $request, CartService $cartService): Response
    {
        if (!$this->isCsrfTokenValid('cart_remove', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_cart');
        }

        $cartService->removeLine($request->request->get('key', ''));

        return $this->redirectToRoute('app_cart');
    }

    #[Route('/qty', name: 'app_cart_qty', methods: ['POST'])]
    public function updateQty(Request $request, CartService $cartService): Response
    {
        if (!$this->isCsrfTokenValid('cart_qty', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_cart');
        }

        /** @var User $user */
        $user = $this->getUser();

        $key = $request->request->get('key', '');
        $qty = max(1, (int) $request->request->get('qty', 1));

        try {
            $cartService->updateQty($user, $key, $qty);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cart');
    }

    /** Materialises the cart into a real draft SalesOrder and starts an online Stripe payment for it — mirrors PaymentController::createIntent for donations. */
    #[Route('/checkout', name: 'app_cart_checkout', methods: ['POST'])]
    public function checkout(Request $request, CartService $cartService, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('cart_checkout', $request->request->get('_csrf_token'))) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        /** @var User $user */
        $user = $this->getUser();

        try {
            $order = $cartService->checkout($user);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        $payment = new Payment();
        $payment->setUser($user);
        $payment->setOrder($order);
        $payment->setAmount($order->getTotal());
        $payment->setMethod(Payment::METHOD_ONLINE);

        $intent = $this->stripe->paymentIntents->create([
            'amount'               => $this->toMinorUnits($order->getTotal()),
            'currency'             => $payment->getCurrency(),
            'payment_method_types' => ['card'],
            'description'          => 'Y Wal order #' . $order->getId(),
            'metadata'             => ['user_id' => (string) $user->getId(), 'order_id' => (string) $order->getId()],
            'receipt_email'        => $user->getEmail(),
        ]);

        $payment->setStripePaymentIntentId($intent->id);

        $em->persist($payment);
        $em->flush();

        return new JsonResponse([
            'paymentId'    => $payment->getId(),
            'clientSecret' => $intent->client_secret,
        ]);
    }

    /** Polled by the checkout page while waiting for the payment to settle — mirrors PaymentController::status, plus completing the order once it does. */
    #[Route('/status/{id}', name: 'app_cart_status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function status(Payment $payment, EntityManagerInterface $em, PaymentMailer $paymentMailer, SalesOrderService $salesOrderService): JsonResponse
    {
        if ($payment->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        // The webhook is the normal way this gets set, but it can be delayed (or, locally, not
        // configured at all via `stripe listen`) — so fall back to asking Stripe directly.
        if ($payment->getSucceededAt() === null && $payment->getFailedAt() === null && $payment->getStripePaymentIntentId()) {
            $intent = $this->stripe->paymentIntents->retrieve($payment->getStripePaymentIntentId());

            if ($intent->status === 'succeeded') {
                $payment->setSucceededAt(new \DateTimeImmutable());
                $em->flush();

                $salesOrderService->completeFromPayment($payment);

                try {
                    $paymentMailer->sendReceipt($payment);
                } catch (\Throwable $e) {
                    // The payment (and order) are already saved as succeeded at this point — an
                    // email failure here shouldn't turn into a 500 and leave the buyer unsure.
                    error_log('Payment receipt email failed for payment ' . $payment->getId() . ': ' . $e->getMessage());
                }
            } elseif ($intent->status === 'canceled' || $intent->last_payment_error) {
                $payment->setFailedAt(new \DateTimeImmutable());
                $payment->setFailureReason($intent->last_payment_error->message ?? null);
                $em->flush();
            }
        }

        return new JsonResponse(['status' => $payment->getStatus()]);
    }

    private function toMinorUnits(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
