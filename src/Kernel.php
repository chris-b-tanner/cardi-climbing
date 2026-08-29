<?php

namespace App;

use Rollbar\Rollbar;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        $accessToken = $_SERVER['ROLLBAR_ACCESS_TOKEN'] ?? $_ENV['ROLLBAR_ACCESS_TOKEN'] ?? '';

        if ($accessToken !== '' && Rollbar::logger() === null) {
            try {
                Rollbar::init([
                    'access_token' => $accessToken,
                    'environment' => $this->environment,
                    'include_error_code_context' => true,
                    'include_exception_code_context' => true,
                ]);
            } catch (\Throwable) {
                // A misconfigured Rollbar token must never take the site down.
            }
        }
    }
}
