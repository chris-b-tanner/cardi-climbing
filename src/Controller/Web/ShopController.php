<?php

namespace App\Controller\Web;

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
 * Public self-serve shop: memberships, drop-in credits, stock items and event tickets. Anyone can
 * browse it, but adding to the cart needs a login. Event tickets can also be added from the event
 * preview modal. add() below serves both: the modal calls it over AJAX, and this page's own forms
 * use a plain POST. Either way, a successful add takes the member straight to the cart.
 */
#[Route('/shop')]
class ShopController extends AbstractController
{
    #[Route('', name: 'app_shop')]
    public function index(ProductRepository $productRepository, CartService $cartService): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        return $this->render('shop/index.html.twig', [
            'products'  => $productRepository->findActiveForShop(),
            'cartCount' => $user !== null ? count($cartService->getLines($user)) : 0,
        ]);
    }

    #[Route('/add', name: 'app_shop_add', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
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
        $qty                = max(1, (int) $request->request->get('qty', 1));

        try {
            $cartService->addLine($user, $productId, $beneficiaryId, $occurrenceDateRaw, $qty);
        } catch (\InvalidArgumentException $e) {
            return $this->addFailure($request, $e->getMessage(), 422);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success'     => true,
                'cartCount'   => count($cartService->getLines($user)),
                'redirectUrl' => $this->generateUrl('app_cart'),
            ]);
        }

        $this->addFlash('success', 'Added to your cart.');
        return $this->redirectToRoute('app_cart');
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
