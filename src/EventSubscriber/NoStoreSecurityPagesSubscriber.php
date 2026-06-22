<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Évite les pages (login / reset) servies depuis un cache HTTP/BFCache
 * qui cassent les jetons CSRF (token ≠ session courante).
 */
final class NoStoreSecurityPagesSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo() ?? '';

        // Pages avec formulaires / jetons CSRF liés à la session (éviter cache navigateur & BFCache).
        $noStorePrefixes = [
            '/login',
            '/mot-de-passe-oublie',
            '/reinitialiser-mot-de-passe',
            '/calendrier',
            '/clients',
            '/videos',
            '/contenu',
            '/derush',
            '/tournage',
        ];

        $match = false;
        foreach ($noStorePrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                $match = true;
                break;
            }
        }
        if (!$match) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('Vary', 'Cookie', false);
    }
}

