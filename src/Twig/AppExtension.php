<?php

namespace App\Twig;

use App\Service\AvatarUploader;
use App\Service\UkPhoneFormatter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%kernel.secret%')] private readonly string $appSecret,
        private readonly UkPhoneFormatter $ukPhoneFormatter,
        private readonly AvatarUploader $avatarUploader,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unsubscribe_url', $this->unsubscribeUrl(...)),
            new TwigFunction('avatar_url', $this->avatarUploader->getUrl(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('uk_phone', $this->ukPhoneFormatter->format(...)),
            new TwigFilter('tel_link', $this->ukPhoneFormatter->dialable(...)),
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
