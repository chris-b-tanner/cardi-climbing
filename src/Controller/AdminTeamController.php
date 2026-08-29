<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/settings/team')]
#[IsGranted('ROLE_ADMIN')]
class AdminTeamController extends AbstractController
{
    #[Route('', name: 'app_admin_settings_team')]
    public function index(UserRepository $userRepository): Response
    {
        return $this->render('admin/settings/team/index.html.twig', [
            'team' => $userRepository->findTeam(),
        ]);
    }

    #[Route('/{id}/remove', name: 'app_admin_settings_team_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(Request $request, User $user, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('admin_team_remove_' . $user->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_admin_settings_team');
        }

        if (!in_array(User::ROLE_TEAM, $user->getRoles(), true)) {
            $this->addFlash('error', 'That member is not on the team.');
            return $this->redirectToRoute('app_admin_settings_team');
        }

        $user->setRoles([User::ROLE_MEMBER]);
        $em->flush();

        $displayName = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? '')) ?: $user->getEmail();
        $this->addFlash('success', $displayName . ' is no longer on the team. They can still sign in as a member.');

        return $this->redirectToRoute('app_admin_settings_team');
    }
}
