<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\BankManager;
use App\Utils\Functions;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\PublisherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/bank')]
final class BankController extends AbstractController
{
    #[Route('/transfer', name: 'transfer', methods: ['PATCH'])]
    public function transfer(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
        SerializerInterface $serializer,
        PublisherInterface $publisher,
        BankManager $bankManager
    ): JsonResponse {
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);

        if (!isset($data['idFriend'], $data['amount'])) {
            return new JsonResponse(['error' => 'Invalid request data'], 400);
        }

        $idFriend = $data['idFriend'];
        $amount = (float) $data['amount'];

        if ($amount <= 0) {
            return new JsonResponse(['error' => 'Amount must be greater than zero'], 400);
        }

        if ($user->getId() === $idFriend) {
            return new JsonResponse(['error' => 'You cannot transfer money to yourself'], 400);
        }

        $friend = $userRepository->find($idFriend);
        if (!$friend) {
            return new JsonResponse(['error' => 'Friend not found'], 404);
        }

        if ($user->getMoney() < $amount) {
            return new JsonResponse(['error' => 'Insufficient funds'], 400);
        }

        $bankManager->transfer($user, $friend, $amount);

        $context = SerializationContext::create()->setGroups(['getUser']);
        $jsonUser = $serializer->serialize($user, 'json', $context);

        Functions::postNotification($publisher, $entityManager, $user, 'transfer', "You transfered \${$amount} to {$friend->getUsername()}");
        Functions::postNotification($publisher, $entityManager, $friend, $user->getUsername(), "You received \${$amount} from {$friend->getUsername()}");
        return new JsonResponse($jsonUser, 200, [], true);
    }



    #[Route('/percentage', name: 'get_percentage', methods: ['GET'])]
    public function getPercentage(UserRepository $userRepository): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $userMoney = $user->getMoney();
        $totalUsers = $userRepository->count([]);

        $usersWithLessMoney = $userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.money < :userMoney')
            ->setParameter('userMoney', $userMoney)
            ->getQuery()
            ->getSingleScalarResult();

        if ($totalUsers > 0) {
            $percentage = ($usersWithLessMoney / ($totalUsers - 1)) * 100;
        } else {
            $percentage = 0;
        }

        // Retourne le pourcentage en JSON
        return new JsonResponse([
            'percentage' => $percentage
        ], Response::HTTP_OK);
    }
}
