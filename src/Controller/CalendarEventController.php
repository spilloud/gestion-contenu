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
    public function index(Request $request): Response
    {
        $sort = $request->query->getString('sort', 'date');
        if (!\in_array($sort, ['date', 'title'], true)) {
            $sort = 'date';
        }

        $globalEvents = [];
        /** @var array<int, array{client: Client, events: CalendarEvent[]}> $eventsByClient */
        $eventsByClient = [];

        foreach ($this->calendarEventRepository->findAllForManagement($sort) as $event) {
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

        $sortFn = static fn (CalendarEvent $a, CalendarEvent $b): int => CalendarEventRepository::compareForManagement($a, $b, $sort);
        usort($globalEvents, $sortFn);
        foreach ($eventsByClient as &$group) {
            usort($group['events'], $sortFn);
        }
        unset($group);

        return $this->render('calendar_event/index.html.twig', [
            'globalEvents' => $globalEvents,
            'eventsByClient' => $eventsByClient,
            'sort' => $sort,
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

        $form = $this->createForm(CalendarEventType::class, $event, [
            'global_event' => $event->isGlobal(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->entityManager->persist($event);
            }
            $this->entityManager->flush();

            $this->addFlash('success', $isNew ? 'Événement créé.' : 'Événement modifié.');

            return $this->redirect($this->resolveReturnTo($request));
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
        $candidates = [
            trim($request->request->getString('_return_to')),
            trim($request->query->getString('return_to')),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeReturnTo($candidate, $request);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $referer = $request->headers->get('referer');
        if (is_string($referer) && $referer !== '') {
            $parts = parse_url($referer);
            if (\is_array($parts) && isset($parts['path'])) {
                $path = (string) $parts['path'];
                $query = isset($parts['query']) ? '?'.$parts['query'] : '';
                $normalized = $this->normalizeReturnTo($path.$query, $request);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return $this->generateUrl('app_calendar_events_index', ['sort' => 'date']);
    }

    private function normalizeReturnTo(string $value, Request $request): ?string
    {
        if ($value === '' || !str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false || !isset($parts['path'])) {
            return null;
        }

        $path = (string) $parts['path'];
        if ($path === '' || str_starts_with($path, '//')) {
            return null;
        }

        if (str_starts_with($path, '/evenements/') && (str_contains($path, '/modifier') || str_ends_with($path, '/nouveau'))) {
            return null;
        }

        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }
}
