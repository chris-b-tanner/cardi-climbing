<?php

namespace App\Controller\Admin;

use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Headline membership numbers and contacts-by-tag for the team. */
#[Route('/admin/dashboard')]
#[IsGranted('ROLE_TEAM')]
class AdminDashboardController extends AbstractController
{
    // Fixed reference point for the "+N since 20 Aug" figure — not a rolling window.
    private const BASELINE_DATE = '2026-08-20';

    #[Route('', name: 'app_admin_dashboard')]
    public function index(UserRepository $userRepository, TagRepository $tagRepository): Response
    {
        $optedInDates = $userRepository->findOptedInCreatedDates();

        return $this->render('admin/dashboard/index.html.twig', [
            'totalOptedIn' => count($optedInDates),
            'startCount'   => $this->countAsOf($optedInDates, new \DateTimeImmutable(self::BASELINE_DATE)),
            'totalMembers' => $userRepository->countActive(),
            'tagCounts'    => $tagRepository->findWithContactCounts(),
        ]);
    }

    /** How many of {createdDates} fall on or before the end of {day}. */
    private function countAsOf(array $createdDates, \DateTimeImmutable $day): int
    {
        $endOfDay = $day->setTime(23, 59, 59);

        $count = 0;
        foreach ($createdDates as $createdAt) {
            if ($createdAt <= $endOfDay) {
                $count++;
            }
        }

        return $count;
    }
}
