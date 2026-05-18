<?php

namespace App\Controller;

use App\Entity\CalendarEvent;
use App\Entity\Client;
use App\Form\CalendarEventType;
use App\Repository\CalendarEventRepository;
use App\Repository\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/evenements')]
class CalendarEventController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CalendarEventRepository $calendarEventRepository,
        private readonly ClientRepository $clientRepository,
    ) {
    }

    #[Route('', name: 'app_calendar_events_index', methods: ['GET'])]
    public function index(): Response
    {
        $globalEvents = [];
        /** @var array<int, array{client: Client, events: CalendarEvent[]}> $eventsByClient */
        $eventsByClient = [];

        foreach ($this->calendarEventRepository->findAllForManagement() as $event) {
            if ($event->isGlobal()) {
                $globalEvents[] = $event;
                continue;
            }
            $client = $event->getClient();
            if ($client === null) {
                continue;
            }
            $clientId = $client->getId();
            if (!isset($eventsByClient[$clientId])) {
                $eventsByClient[$clientId] = [
                    'client' => $client,
                    'events' => [],
                ];
            }
            $eventsByClient[$clientId]['events'][] = $event;
        }

        uasort($eventsByClient, static fn (array $a, array $b): int => strcasecmp(
            (string) $a['client']->getName(),
            (string) $b['client']->getName()
        ));

        return $this->render('calendar_event/index.html.twig', [
            'globalEvents' => $globalEvents,
            'eventsByClient' => $eventsByClient,
        ]);
    }

    #[Route('/nouveau', name: 'app_calendar_event_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $event = new CalendarEvent();
        $event->setColor(CalendarEvent::DEFAULT_COLOR);
        $event->setTextColor(CalendarEvent::DEFAULT_TEXT_COLOR);

        $prefillClientId = $request->query->getInt('client');
        if ($prefillClientId > 0) {
            $client = $this->clientRepository->find($prefillClientId);
            if ($client instanceof Client) {
                $event->setClient($client);
            }
        }

        return $this->handleForm($request, $event, true);
    }

    #[Route('/{id}/modifier', name: 'app_calendar_event_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(CalendarEvent $event, Request $request): Response
    {
        return $this->handleForm($request, $event, false);
    }

    #[Route('/{id}/supprimer', name: 'app_calendar_event_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(CalendarEvent $event, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete_calendar_event'.$event->getId(), $request->request->getString('_token'))) {
            $this->entityManager->remove($event);
            $this->entityManager->flush();
            $this->addFlash('success', 'Événement supprimé.');
        }

        return $this->redirect($this->resolveReturnTo($request));
    }

    private function handleForm(Request $request, CalendarEvent $event, bool $isNew): Response
    {
        $returnTo = $this->resolveReturnTo($request);

        $form = $this->createForm(CalendarEventType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->entityManager->persist($event);
            }
            $this->entityManager->flush();

            $this->addFlash('success', $isNew ? 'Événement créé.' : 'Événement modifié.');

            return $this->redirect($returnTo);
        }

        return $this->render('calendar_event/form.html.twig', [
            'event' => $event,
            'form' => $form,
            'returnTo' => $returnTo,
            'isNew' => $isNew,
        ]);
    }

    private function resolveReturnTo(Request $request): string
    {
        $fromRequest = trim($request->request->getString('_return_to'));
        if ($fromRequest === '') {
            $fromRequest = trim($request->query->getString('return_to'));
        }
        if ($fromRequest !== '' && str_starts_with($fromRequest, '/') && !str_starts_with($fromRequest, '//')) {
            return $fromRequest;
        }

        $referer = $request->headers->get('referer');
        if (is_string($referer) && $referer !== '') {
            $parts = parse_url($referer);
            if (isset($parts['path']) && str_starts_with((string) $parts['path'], '/')) {
                $host = $parts['host'] ?? '';
                if ($host === $request->getHost()) {
                    return (string) $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
                }
            }
        }

        return $this->generateUrl('app_calendar_events_index');
    }
}
