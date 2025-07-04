<?php

namespace App\Controller;

use App\Entity\Bank;
use App\Entity\Loan;
use App\Entity\LoanRequest;
use App\Message\PaymentMessage;
use App\Repository\BankRepository;
use App\Repository\LoanRepository;
use App\Repository\LoanRequestRepository;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/bank')]
final class BankController extends AbstractController
{
    #[Route('/transfer', name: 'transfer', methods: ['PATCH'])]
    public function transfer(
        Request $request,
        UserRepository $userRepository,
        SerializerInterface $serializer,
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



    #[Route('/create_bank', name: 'create_bank', methods: ['POST'])]
    public function createBank(
            Request $request,
            SerializerInterface $serializer,
            EntityManagerInterface $entityManager
        ): JsonResponse {
        $user = $this->getUser();

        $data = $request->toArray();
        $bankName = $data['bankName'];
        $bankDescription = $data['bankDescription'];
        if (empty($bankName)) {
            return new JsonResponse(['message' => 'Bank name cannot be empty'], Response::HTTP_BAD_REQUEST);
        }

        $bank = new Bank();
        $bank->setName($bankName);
        $bank->setDescription($bankDescription);
        $bank->setMoney(0);
        $user->addBank($bank);
        $bank->setCreatedAt(new \DateTimeImmutable());
        $entityManager->persist($user);
        $entityManager->persist($bank);
        $entityManager->flush();

        $context = SerializationContext::create()->setGroups(['getBank']);
        $jsonBank = $serializer->serialize($bank, 'json', $context);
        $associativeBank = json_decode($jsonBank, true);

        return new JsonResponse(['status' => 'success', 'bank' => $associativeBank], Response::HTTP_CREATED);
    }



    #[Route('/get', name: 'get_banks', methods: ['GET'])]
    public function getBanks(
        SerializerInterface $serializer,
        LoanRequestRepository $loanRequestRepository,
        LoanRepository $loanRepository
    ): JsonResponse {
        $user = $this->getUser();

        $context = SerializationContext::create()->setGroups(['getBank']);
        $jsonBanks = $serializer->serialize($user, 'json', $context);

        $banks = json_decode($jsonBanks, true);

        foreach ($banks['banks'] as &$bank) {
            foreach ($bank['loan_requests'] as &$loanRequest) {
                $loanRequestEntity = $loanRequestRepository->find($loanRequest['id']);
                $loanRequest['applicant'] = [
                    'id' => $loanRequestEntity->getApplicant()->getId(),
                    'name' => $loanRequestEntity->getApplicant()->getUsername()
                ];
            }
            foreach ($bank['loans'] as &$loan) {
                $loanEntity = $loanRepository->find($loan['id']);
                $loan['poor'] = [
                    'id' => $loanEntity->getPoor()->getId(),
                    'name' => $loanEntity->getPoor()->getUsername()
                ];
            }
        }
        unset($bank);

        foreach ($banks['loans'] as &$loan) {
            $realLoan = $loanRepository->find($loan['id']);
            $loan['bank'] = [
                'id' => $realLoan->getBank()->getId(),
                'name' => $realLoan->getBank()->getName()
            ];
        }
        unset($loan);

        return new JsonResponse($banks, Response::HTTP_OK, [], false);
    }



    #[Route('/request_loan', name: 'requestLoan', methods: ['POST'])]
    public function requestLoan(
        Request $request,
        EntityManagerInterface $entityManager,
        BankRepository $bankRepository,
        PublisherInterface $publisher
    ): JsonResponse {
        $user = $this->getUser();
        $data = $request->toArray();

        $bankId = (int) $data['bankId'];
        $amount = (int) $data['amount'];
        $duration = (int) $data['duration'];
        $request = (string) $data['request'];
        $interestRate = (float) $data['interestRate'];
        $bank = $bankRepository->find($bankId);

        if ($amount <= 0) {
            return new JsonResponse(['message' => 'Amount must be greater than zero'], 400);
        }
        if (!$bank) {
            return new JsonResponse(['message' => 'Bank not found'], 404);
        }

        $loanRequest = new LoanRequest();
        $bank->addLoanRequest($loanRequest);
        $user->addLoanRequest($loanRequest);
        $loanRequest->setAmount($amount);
        $loanRequest->setDuration($duration);
        $loanRequest->setInterestRate($interestRate);
        $loanRequest->setRequest($request);

        $entityManager->persist($loanRequest);
        $entityManager->persist($bank);
        $entityManager->flush();

        Functions::postNotification($publisher, $entityManager, $bank->getOwner(), 'Loan Request', "You received a loan request from {$user->getUsername()}");

        Functions::postNotification($publisher, $entityManager, $user, 'Loan Request', "You successfully sent a loan request to {$bank->getOwner()->getUsername()}");

        return new JsonResponse(['status' => 'success'], Response::HTTP_CREATED);
    }



    #[Route('/accept_loan/{id}', name: 'acceptLoan', methods: ['POST'])]
    public function acceptLoan(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        LoanRequestRepository $loanRequestRepository,
        PublisherInterface $publisher,
        SerializerInterface $serializer,
        MessageBusInterface $bus
    ): JsonResponse {
        $user = $this->getUser();
        $data = $request->toArray();

        $interestRate = $data['interestRate'];
        $loanRequest = $loanRequestRepository->find($id);

        if ($interestRate > $loanRequest->getInterestRate()) {
            return new JsonResponse(['message' => 'Interest rate cannot be higher than the requested one'], 400);
        }

        $loan = new Loan();
        $loanRequest->getBank()->addLoan($loan);
        $applicant = $loanRequest->getApplicant();
        $applicant->addLoan($loan);

        $end = new \DateTime();
        $interval = new \DateInterval("P{$loanRequest->getDuration()}W");
        $end->add($interval);
        $loan->setStart(new \DateTime());
        $loan->setDeadline($end);

        $loan->setAmount($loanRequest->getAmount());
        $loan->setRepaid(0);
        $loan->setInterestRate($interestRate);

        $entityManager->persist($loan);
        $entityManager->persist($loan->getBank());
        $entityManager->persist($user);
        $entityManager->remove($loanRequest);
        $entityManager->flush();

        $bus->dispatch(new PaymentMessage($loan->getId()), [new DelayStamp($loan->getInterval())]);

        Functions::postNotification($publisher, $entityManager, $user, 'Loan', "You approved a loan request from {$applicant->getUsername()}");

        Functions::postNotification($publisher, $entityManager, $applicant, 'Loan', "The bank {$loan->getBank()->getName()} approved your loan request");

        $context = SerializationContext::create()->setGroups(['getBank']);
        $jsonLoan = $serializer->serialize($loan, 'json', $context);

        return new JsonResponse(['status' => 'success', 'loan' => $jsonLoan, 'bankId' => $loan->getBank()->getId(), 'loanRequestId' => $id], Response::HTTP_OK, [], false);
    }



    #[Route('/search', name: 'search_banks', methods: ['GET'])]
    public function searchBanks(
        Request $request,
        BankRepository $bankRepository,
        SerializerInterface $serializer
    ): JsonResponse {
        $name = $request->query->get('name', '');
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = max(1, min(50, (int)$request->query->get('limit', 10)));
        $offset = ($page - 1) * $limit;

        $banks = $bankRepository->searchByNamePagination($name, $offset, $limit);

        $context = SerializationContext::create()->setGroups(['getBank']);
        $jsonBanks = $serializer->serialize($banks, 'json', $context);

        return new JsonResponse($jsonBanks, Response::HTTP_OK, [], true);
    }



    #[Route('/{bankId}', name: 'get_bank', methods: ['GET'])]
    public function getBank(
        int $bankId,
        BankRepository $bankRepository,
        SerializerInterface $serializer
    ): JsonResponse {
        $bank = $bankRepository->find($bankId);
        if (!$bank) {
            return new JsonResponse(['error' => 'Bank not found'], Response::HTTP_NOT_FOUND);
        }

        $context = SerializationContext::create()->setGroups(['getPublicBank']);
        $jsonBank = $serializer->serialize($bank, 'json', $context);
        
        return new JsonResponse($jsonBank, Response::HTTP_OK, [], true);
    }



    #[Route('/{bankId}/change_name', name: 'change_bank_name', methods: ['PUT'])]
    public function changeBankName(
        Request $request,
        int $bankId,
        BankRepository $bankRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        if (!isset($data['name'])) {
            return new JsonResponse(['message' => 'Invalid name'], 400);
        }

        $bank = $bankRepository->find($bankId);
        if (!$bank) {
            return new JsonResponse(['message' => 'Bank not found'], 404);
        }

        if ($bank->getOwner() !== $user) {
            return new JsonResponse(['message' => 'You are not the owner of this bank'], 403);
        }

        $bank->setName($data['name']);
        $entityManager->persist($bank);
        $entityManager->flush();

        return new JsonResponse(['status' => 'success', 'bankId' => $bank->getId(), 'name' => $bank->getName()], Response::HTTP_OK);
    }



    #[Route('/{bankId}/change_description', name: 'change_bank_description', methods: ['PUT'])]
    public function changeBankDescription(
        Request $request,
        int $bankId,
        BankRepository $bankRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        if (!isset($data['description'])) {
            return new JsonResponse(['message' => 'Invalid description'], 400);
        }

        $bank = $bankRepository->find($bankId);
        if (!$bank) {
            return new JsonResponse(['message' => 'Bank not found'], 404);
        }

        if ($bank->getOwner() !== $user) {
            return new JsonResponse(['message' => 'You are not the owner of this bank'], 403);
        }

        $bank->setDescription($data['description']);
        $entityManager->persist($bank);
        $entityManager->flush();

        return new JsonResponse(['status' => 'success', 'bankId' => $bank->getId(), 'description' => $bank->getDescription()], Response::HTTP_OK);
    }



    #[Route('/{bankId}/money_in', name: 'bank_money_in', methods: ['POST'])]
    public function moneyIn(
        int $bankId,
        Request $request,
        BankRepository $bankRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();
        $data = $request->toArray();
        $amount = (int) $data['amount'];

        if (!isset($amount)) {
            return new JsonResponse(['message' => 'Invalid amount'], 400);
        }

        $bank = $bankRepository->find($bankId);
        if (!$bank) {
            return new JsonResponse(['message' => 'Bank not found'], 404);
        }

        if ($bank->getOwner() !== $user) {
            return new JsonResponse(['message' => 'You are not the owner of this bank'], 403);
        }

        $bank->setMoney($bank->getMoney() + $amount);
        $user->setMoney($user->getMoney() - $amount);
        $entityManager->persist($bank);
        $entityManager->persist($user);
        $entityManager->flush();

        return new JsonResponse(['status' => 'success', 'amount' => $amount, 'bankMoney' => $bank->getMoney(), 'userMoney' => $user->getMoney()], Response::HTTP_OK);
    }
}
