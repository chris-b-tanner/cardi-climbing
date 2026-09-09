<?php

namespace App\Controller;

use App\Service\MagicLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Redeems a password-free sign-in link — see MagicLinkService. Reachable while logged out (and while logged in, in which case it just switches the session to the link's own user, same as guest booking's auto-login). */
class MagicLinkController extends AbstractController
{
    #[Route('/go/{token}', name: 'app_magic_link')]
    public function __invoke(string $token, MagicLinkService $magicLinkService, Security $security): Response
    {
        $link = $magicLinkService->resolve($token);

        if (!$link) {
            $this->addFlash('error', 'That link has expired — please log in.');
            return $this->redirectToRoute('app_login');
        }

        $security->login($link->getUser());
        $magicLinkService->markUsed($link);

        $redirectPath = $link->getRedirectPath();

        return ($redirectPath !== null && str_starts_with($redirectPath, '/') && !str_starts_with($redirectPath, '//'))
            ? $this->redirect($redirectPath)
            : $this->redirectToRoute('app_home');
    }
}
