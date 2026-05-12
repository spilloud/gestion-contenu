<?php

namespace App\Controller;

use App\Repository\ClientRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/clients-sous-gestion')]
class ClientsTableController extends AbstractController
{
    #[Route('', name: 'app_clients_table', methods: ['GET'])]
    public function index(Request $request, ClientRepository $clientRepository): Response
    {
        $sort = $request->query->getString('sort', 'client');
        if (!in_array($sort, ['client', 'cm', 'monteur'], true)) {
            $sort = 'client';
        }
        $dir = strtolower($request->query->getString('dir', 'asc'));
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        return $this->render('clients_table/index.html.twig', [
            'clients' => $clientRepository->findActiveForClientsTableOrdered($sort, strtoupper($dir)),
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }
}
