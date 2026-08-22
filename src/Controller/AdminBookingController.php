<?php

namespace App\Controller;

use App\Repository\AttendeeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/bookings')]
#[IsGranted('ROLE_ADMIN')]
class AdminBookingController extends AbstractController
{
    #[Route('', name: 'app_admin_bookings')]
    public function index(Request $request, AttendeeRepository $attendeeRepository): Response
    {
        $query     = trim($request->query->get('q', ''));
        $attendees = $attendeeRepository->search($query);

        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/bookings/_list.html.twig', [
                'attendees' => $attendees,
            ]);
        }

        return $this->render('admin/bookings/index.html.twig', [
            'attendees'    => $attendees,
            'currentQuery' => $query,
        ]);
    }
}
