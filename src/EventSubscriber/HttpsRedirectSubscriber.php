<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class HttpsRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly string $appEnv) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->appEnv === 'dev' || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->headers->get('X-Forwarded-Proto') === 'http') {
            $url = preg_replace('/^http:/', 'https:', $request->getUri());
            $event->setResponse(new RedirectResponse($url, 301));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 30]],
        ];
    }
}
