<?php

namespace App\EventSubscriber;

use Rollbar\Payload\Level;
use Rollbar\Rollbar;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Symfony's HttpKernel catches all throwables internally, so they never reach
 * Rollbar's global exception handler. Report them explicitly instead.
 */
class RollbarExceptionSubscriber implements EventSubscriberInterface
{
    public function onKernelException(ExceptionEvent $event): void
    {
        if (Rollbar::logger() === null) {
            return;
        }

        $throwable = $event->getThrowable();
        $level = $throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() < 500
            ? Level::WARNING
            : Level::ERROR;

        Rollbar::logUncaught($level, $throwable);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }
}
