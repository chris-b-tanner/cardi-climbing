<?php

namespace App\Controller\Web;

use App\Repository\UserRepository;
use App\Service\UnsubscribeToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UnsubscribeController extends AbstractController
{
    /**
     * GET is the link in the email footer. POST is RFC 8058 one-click unsubscribe: mail clients
     * (Gmail, Yahoo, Apple Mail) POST to the List-Unsubscribe header's URL — query string intact —
     * with the body "List-Unsubscribe=One-Click", so the same token check covers both.
     */
    #[Route('/unsubscribe', name: 'app_unsubscribe', methods: ['GET', 'POST'])]
    public function unsubscribe(Request $request, UserRepository $userRepository, EntityManagerInterface $em, UnsubscribeToken $unsubscribeToken): Response
    {
        $email = UnsubscribeToken::normaliseEmail($request->query->get('email', ''));

        if (!$unsubscribeToken->isValid($email, $request->query->get('token', ''))) {
            return $this->render('unsubscribe/confirm.html.twig', ['success' => false]);
        }

        $user = $userRepository->findByAnyEmail($email);

        if ($user && $user->isOptIn()) {
            $user->setOptIn(false);
            $em->flush();
        }

        return $this->render('unsubscribe/confirm.html.twig', ['success' => true]);
    }
}
