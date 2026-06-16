<?php

namespace App\Controller;

use App\Service\EditorWorkloadPlanningBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/planning')]
class EditorPlanningController extends AbstractController
{
    public function __construct(
        private readonly EditorWorkloadPlanningBuilder $planningBuilder,
    ) {
    }

    #[Route('/monteurs', name: 'app_editor_planning', methods: ['GET'])]
    public function index(): Response
    {
        $planning = $this->planningBuilder->build();

        return $this->render('planning/editors.html.twig', [
            'planning' => $planning,
        ]);
    }

    #[Route('/monteurs.json', name: 'app_editor_planning_json', methods: ['GET'])]
    public function jsonExport(): JsonResponse
    {
        return $this->json($this->planningBuilder->build());
    }
}
