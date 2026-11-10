<?php

namespace App\Controller\Web;

use App\Controller\Concern\SafeLocalRedirectTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class SecurityController extends AbstractController
{
    use SafeLocalRedirectTrait;
    use TargetPathTrait;

    private const FIREWALL = 'main';

    #[Route('/login/success', name: 'app_login_success')]
    public function loginSuccess(): Response
    {
        if ($this->isGranted('ROLE_TEAM')) {
            return $this->redirectToRoute('app_admin_dashboard');
        }
        return $this->redirectToRoute('app_account');
    }

    #[Route('/login', name: 'app_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        // ?returnTo= sends the member back to the page they came from after signing in (e.g. the
        // shop). It must be a local path, so it can't be used as an open redirect. It's saved as
        // the firewall's target path, which form login already redirects to on success.
        $returnTo = $this->localPathOr($request->query->get('returnTo'), '');

        if ($this->getUser()) {
            return $returnTo !== '' ? $this->redirect($returnTo) : $this->redirectToRoute('app_login_success');
        }

        if ($returnTo !== '' && $request->hasSession()) {
            $this->saveTargetPath($request->getSession(), self::FIREWALL, $returnTo);
        }

        return $this->render('security/login.html.twig', [
            'error'         => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('This is intercepted by the firewall — should never be reached.');
    }
}
