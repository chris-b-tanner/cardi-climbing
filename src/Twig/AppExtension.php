<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%kernel.secret%')] private readonly string $appSecret,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unsubscribe_url', $this->unsubscribeUrl(...)),
        ];
    }

    public function unsubscribeUrl(string $email): string
    {
        $email = strtolower(trim($email));
        $token = hash_hmac('sha256', $email, $this->appSecret);

        return $this->router->generate('app_unsubscribe', [
            'email' => $email,
            'token' => $token,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
