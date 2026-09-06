<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ProductRepository;
use App\Service\CartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public self-serve shop — buy a membership or drop-in credits for yourself or a dependent.
 * Event tickets aren't listed here; they're added to the cart from the event preview modal instead
 * (add() below is shared by both — the modal calls it over AJAX, this page's own forms plain POST).
 */
#[Route('/shop')]
#[IsGranted('ROLE_USER')]
class ShopController extends AbstractController
{
    #[Route('', name: 'app_shop')]
    public function index(ProductRepository $productRepository, CartService $cartService): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('shop/index.html.twig', [
            'products'  => $productRepository->findActiveForShop(),
            'cartCount' => count($cartService->getLines($user)),
        ]);
    }

    #[Route('/add', name: 'app_shop_add', methods: ['POST'])]
    public function add(Request $request, CartService $cartService): Response
    {
        if (!$this->isCsrfTokenValid('shop_add', $request->request->get('_csrf_token'))) {
            return $this->addFailure($request, 'Access denied.', 403);
        }

        /** @var User $user */
        $user = $this->getUser();

        $productId         = (int) $request->request->get('productId', 0);
        $beneficiaryRaw     = trim($request->request->get('beneficiaryId', ''));
        $beneficiaryId      = $beneficiaryRaw !== '' ? (int) $beneficiaryRaw : null;
        $occurrenceDateRaw  = trim($request->request->get('occurrenceDate', '')) ?: null;

        try {
            $cartService->addLine($user, $productId, $beneficiaryId, $occurrenceDateRaw);
        } catch (\InvalidArgumentException $e) {
            return $this->addFailure($request, $e->getMessage(), 422);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'cartCount' => count($cartService->getLines($user))]);
        }

        $this->addFlash('success', 'Added to your cart.');
        return $this->redirectToRoute('app_shop');
    }

    private function addFailure(Request $request, string $message, int $status): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['error' => $message], $status);
        }

        $this->addFlash('error', $message);
        return $this->redirectToRoute('app_shop');
    }
}
