<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UnsubscribeController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.secret%')] private readonly string $appSecret,
    ) {}

    #[Route('/unsubscribe', name: 'app_unsubscribe', methods: ['GET'])]
    public function unsubscribe(Request $request, UserRepository $userRepository, EntityManagerInterface $em): Response
    {
        $email = strtolower(trim($request->query->get('email', '')));
        $token = $request->query->get('token', '');

        $valid = $email !== ''
            && hash_equals(hash_hmac('sha256', $email, $this->appSecret), $token);

        if (!$valid) {
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
