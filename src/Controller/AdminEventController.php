<?php

namespace App\Controller;

use App\Entity\Event;
use App\Entity\User;
use App\Repository\CertificationRepository;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/events')]
#[IsGranted('ROLE_ADMIN')]
class AdminEventController extends AbstractController
{
    #[Route('', name: 'app_admin_events')]
    public function index(EventRepository $eventRepository): Response
    {
        return $this->render('admin/events/index.html.twig', [
            'events' => $eventRepository->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'app_admin_event_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        CertificationRepository $certificationRepository,
    ): Response {
        $allCertifications = $certificationRepository->findBy([], ['name' => 'ASC']);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_event_new', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $event = new Event();

            /** @var User $admin */
            $admin = $this->getUser();
            $event->setAuthor($admin);

            $error = $this->applyRequestToEvent($request, $event, $allCertifications);

            if (!$error) {
                $em->persist($event);
                $em->flush();

                $this->addFlash('success', 'Event created.');
                return $this->redirectToRoute('app_admin_events');
            }
        }

        return $this->render('admin/events/new.html.twig', [
            'error'         => $error,
            'certifications' => $allCertifications,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_event_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Event $event,
        EntityManagerInterface $em,
        CertificationRepository $certificationRepository,
    ): Response {
        $allCertifications = $certificationRepository->findBy([], ['name' => 'ASC']);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_event_edit_' . $event->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $error = $this->applyRequestToEvent($request, $event, $allCertifications);

            if (!$error) {
                $em->flush();

                $this->addFlash('success', 'Event updated.');
                return $this->redirectToRoute('app_admin_events');
            }
        }

        return $this->render('admin/events/edit.html.twig', [
            'event'         => $event,
            'error'         => $error,
            'certifications' => $allCertifications,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_event_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Event $event, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_event_' . $event->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $em->remove($event);
        $em->flush();

        $this->addFlash('success', 'Event deleted.');
        return $this->redirectToRoute('app_admin_events');
    }

    /** @param \App\Entity\Certification[] $allCertifications */
    private function applyRequestToEvent(Request $request, Event $event, array $allCertifications): ?string
    {
        $title    = trim($request->request->get('title', ''));
        $location = trim($request->request->get('location', ''));
        $dateRaw  = $request->request->get('date', '');
        $timeFrom = trim($request->request->get('timeFrom', ''));
        $timeTo   = trim($request->request->get('timeTo', ''));

        if ($title === '' || $location === '' || $dateRaw === '' || $timeFrom === '' || $timeTo === '') {
            return 'Title, location, date, and start/end time are required.';
        }

        try {
            $date = new \DateTimeImmutable($dateRaw);
        } catch (\Exception) {
            return 'Please enter a valid date.';
        }

        $isRecurring = $request->request->has('isRecurring');
        $recurUntil  = null;

        if ($isRecurring) {
            $recurUntilRaw = $request->request->get('recurUntil', '');
            if ($recurUntilRaw === '') {
                return 'Please set a "recurs until" date for a recurring event.';
            }
            try {
                $recurUntil = new \DateTimeImmutable($recurUntilRaw);
            } catch (\Exception) {
                return 'Please enter a valid "recurs until" date.';
            }
            if ($recurUntil < $date) {
                return 'The "recurs until" date must be on or after the event date.';
            }
            if (!$request->request->all('recurDays')) {
                return 'Please select at least one day for a recurring event.';
            }
        }

        $maxAttendeesRaw = trim($request->request->get('maxAttendees', ''));
        $priceRaw        = trim($request->request->get('price', ''));

        $event->setTitle($title);
        $event->setDescription(trim($request->request->get('description', '')) ?: null);
        $event->setDate($date);
        $event->setTimeFrom($timeFrom);
        $event->setTimeTo($timeTo);
        $event->setLocation($location);
        $event->setExternalUrl(trim($request->request->get('externalUrl', '')) ?: null);
        $event->setMaxAttendees($maxAttendeesRaw !== '' ? (int) $maxAttendeesRaw : null);
        $event->setPrice($priceRaw !== '' ? number_format((float) $priceRaw, 2, '.', '') : null);
        $event->setStatus($request->request->has('published') ? Event::STATUS_PUBLISHED : Event::STATUS_DRAFT);

        $event->setIsRecurring($isRecurring);
        $event->setRecurUntil($isRecurring ? $recurUntil : null);
        $event->setRecurDaysArray($isRecurring ? array_map('intval', $request->request->all('recurDays')) : []);

        $submittedCertIds = array_map('intval', $request->request->all('restrictions'));
        foreach ($event->getRestrictions()->toArray() as $certification) {
            if (!in_array($certification->getId(), $submittedCertIds, true)) {
                $event->removeRestriction($certification);
            }
        }
        foreach ($allCertifications as $certification) {
            if (in_array($certification->getId(), $submittedCertIds, true)) {
                $event->addRestriction($certification);
            }
        }

        return null;
    }
}
