<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Empêche les comptes ROLE_CLIENT de naviguer ailleurs que l'agenda (lecture seule).
 */
final class ClientAccountAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isClientAccount()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo() ?? '';
        $allowed = [
            '/agenda',
            '/login',
            '/logout',
            '/mot-de-passe-oublie',
            '/reinitialiser-mot-de-passe',
            '/_wdt',
            '/_profiler',
            '/assets',
            '/build',
        ];

        foreach ($allowed as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return;
            }
        }

        $event->setResponse(new RedirectResponse($this->router->generate('app_client_agenda_home')));
    }
}

