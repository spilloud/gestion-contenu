<?php

namespace App\Controller;

use App\Repository\ClientRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/clients-sous-gestion')]
class ClientsTableController extends AbstractController
{
    #[Route('', name: 'app_clients_table', methods: ['GET'])]
    public function index(ClientRepository $clientRepository): Response
    {
        return $this->render('clients_table/index.html.twig', [
            'clients' => $clientRepository->findAllOrderedByClientName(),
        ]);
    }
}
