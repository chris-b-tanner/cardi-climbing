<?php

namespace App\Twig;

use App\Service\AvatarUploader;
use App\Service\ProductImageUploader;
use App\Service\UkPhoneFormatter;
use App\Service\UnsubscribeToken;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly UnsubscribeToken $unsubscribeToken,
        private readonly UkPhoneFormatter $ukPhoneFormatter,
        private readonly AvatarUploader $avatarUploader,
        private readonly ProductImageUploader $productImageUploader,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unsubscribe_url', $this->unsubscribeUrl(...)),
            new TwigFunction('avatar_url', $this->avatarUploader->getUrl(...)),
            new TwigFunction('product_image_url', $this->productImageUploader->getUrl(...)),
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
        return $this->router->generate('app_unsubscribe', [
            'email' => UnsubscribeToken::normaliseEmail($email),
            'token' => $this->unsubscribeToken->generate($email),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
