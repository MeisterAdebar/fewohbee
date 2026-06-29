<?php
namespace App\Controller;

use App\Entity\Reservation;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ApiController extends AbstractController
{
    #[Route('/api/reservations/current', name: 'api.reservations.current', methods: ['GET'])]
    public function currentReservations(ManagerRegistry $doctrine): JsonResponse
    {
        $today = new \DateTime();
        $em = $doctrine->getManager();
        
        $reservations = $em->getRepository(Reservation::class)
            ->loadReservationsForPeriod(
                $today->format('Y-m-d'),
                $today->format('Y-m-d')
            );
        
        $data = [];
        foreach ($reservations as $r) {
            $data[] = [
                'id'        => $r->getId(),
                'name'      => $r->getBooker()->getFirstName() . ' ' . $r->getBooker()->getLastName(),
                'startDate' => $r->getStartDate()->format('Y-m-d'),
                'endDate'   => $r->getEndDate()->format('Y-m-d'),
                'apartment' => $r->getAppartment()?->getNumber(),
            ];
        }
        
        return new JsonResponse($data);
    }

    #[Route('/api/reservations/arrivals', name: 'api.reservations.arrivals', methods: ['GET'])]
    public function todayArrivals(ManagerRegistry $doctrine): JsonResponse
    {
        $today = new \DateTime();
        $em = $doctrine->getManager();
        
        $reservations = $em->getRepository(Reservation::class)
            ->loadReservationsForPeriod(
                $today->format('Y-m-d'),
                (clone $today)->modify('+1 day')->format('Y-m-d')
            );
        
        $data = [];
        foreach ($reservations as $r) {
            if ($r->getStartDate()->format('Y-m-d') === $today->format('Y-m-d')) {
                $data[] = [
                    'id'        => $r->getId(),
                    'name'      => $r->getBooker()->getFirstName() . ' ' . $r->getBooker()->getLastName(),
                    'startDate' => $r->getStartDate()->format('Y-m-d'),
                    'endDate'   => $r->getEndDate()->format('Y-m-d'),
                    'apartment' => $r->getAppartment()?->getNumber(),
                ];
            }
        }
        
        return new JsonResponse($data);
    }
}
