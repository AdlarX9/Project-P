<?php

namespace App\Controller;

use App\Message\RedisStreamMessage;
use App\Repository\UserRepository;
use App\Service\BankManager;
use App\Service\GameManager;
use App\Utils\Functions;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\PublisherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Redis;

#[Route('/api/matchmaking')]
final class MatchmakingController extends AbstractController
{
    private $bus;
    private $logger;
    private $cache;
    private $redis;

    public function __construct(MessageBusInterface $bus, LoggerInterface $logger, CacheInterface $cacheRedis)
    {
        $this->bus = $bus;
        $this->logger = $logger;
        $this->cache = $cacheRedis;
        $this->redis = new Redis();
        $this->redis->connect('redis', 6379);
    }

    #[Route("/add", "add_user_to_queue", methods: ["POST"])]
    public function addToCache(): JsonResponse
    {
        $user = $this->getUser();
        $data = [
            'connection_time' => (new \DateTime())->format('Y-m-d\TH:i:sP'),
            'money' => $user->getMoney(),
            'username' => $user->getUsername(),
            'id' => $user->getId(),
            'communicationPreference' => $user->getSettings()['communicationPreference'] ?? 'text'
        ];
        $this->bus->dispatch(new RedisStreamMessage(data: $data));

        return new JsonResponse(['message' => 'added stream successfully'], Response::HTTP_OK, [], false);
    }



    #[Route('/pong', name: 'pong', methods: ['POST'])]
    public function pong(): JsonResponse {
        // Décoder les données JSON envoyées dans la requête
        $user = $this->getUser();

        $this->cache->delete('pong_' . $user->getUsername());
        $value = $this->cache->get('pong_' . $user->getUsername(), function (): bool { return true; });

        return new JsonResponse(['pong_' . $user->getUsername() => $value], Response::HTTP_OK, [], false);
    }



    #[Route('/cancel_play', name: 'cancelPlay', methods: ['DELETE'])]
    public function cancelPlay(Request $request): JsonResponse {
        // Décoder les données JSON envoyées dans la requête
        $data = json_decode($request->getContent(), true);
        $user = $this->getUser();
        $this->cache->delete('pong_' . $user->getUsername());
        $this->redis->xAck('matchmaking_stream', 'matchmaking_group', [$data['messageId']]);
        $this->redis->xDel('matchmaking_stream', [$data['messageId']]);
        return new JsonResponse(['message' => 'pang'], Response::HTTP_OK, [], false);
    }



    #[Route('/peer/ask_id', name: 'peerAskId', methods: ['POST'])]
    public function peerAskId(Request $request, PublisherInterface $publisher): JsonResponse {
        $data = json_decode($request->getContent(), true);
        Functions::usePeerIdCommunication($publisher, $data['peerUsername'], 'ask');
        return new JsonResponse(['message' => 'Peer asked successfully!'], Response::HTTP_NO_CONTENT);
    }


    #[Route('/peer/send_id', name: 'peerSendId', methods: ['POST'])]
    public function peerSendId(Request $request, PublisherInterface $publisher): JsonResponse {
        $data = json_decode($request->getContent(), true);
        Functions::usePeerIdCommunication($publisher, $data['peerUsername'], 'send', $data['id']);
        return new JsonResponse(['message' => 'PeerId sent successfully!'], Response::HTTP_NO_CONTENT);
    }


    #[Route('/peer/switch_chat', name: 'switchChat', methods: ['POST'])]
    public function switchChat(Request $request, PublisherInterface $publisher): JsonResponse {
        $data = json_decode($request->getContent(), true);
        Functions::sendMatchmakingUpdate($publisher, $data['peerUsername'], 'send', $data['id']);
        return new JsonResponse(['message' => 'PeerId sent successfully!'], Response::HTTP_NO_CONTENT);
    }



    #[Route('/game_expired', name: 'gameExpired', methods: ['POST'])]
    public function gameExpired(Request $request, GameManager $gameManager, BankManager $bankManager, UserRepository $userRepository): JsonResponse {
        $data = $request->toArray();
        $gameId = $data['gameId'] ?? '';

        $game = $gameManager->getGame($gameId);
        if ($gameManager->isGameExpired($gameId)) {
            $bankManager->transferToBank($userRepository->getUserByUsername($game['user1']), $bankManager->getCentralBank(), 200);
            $bankManager->transferToBank($userRepository->getUserByUsername($game['user2']), $bankManager->getCentralBank(), 200);
            return new JsonResponse(['message' => 'Game is done'], Response::HTTP_OK);
        }

        return new JsonResponse(['message' => 'Game is still active'], Response::HTTP_TOO_EARLY);
    }



    #[Route('/lose_game', name: 'loseGame', methods: ['POST'])]
    public function loseGame(Request $request, GameManager $gameManager, BankManager $bankManager, UserRepository $userRepository): JsonResponse {
        $data = $request->toArray();
        $gameId = $data['gameId'] ?? '';
        $user = $this->getUser();
        $trueUser = $userRepository->find($user->getId());

        if (!$gameManager->isGameExpired($gameId)) {
            $receiverName = $gameManager->getGameReceiver($gameId, $trueUser);
            $receiver = $userRepository->getUserByUsername($receiverName);
            $gameManager->expireGame($gameId);
            $bankManager->transfer($trueUser, $receiver, 50, true);
            return new JsonResponse(['message' => 'You successfully lost the game (sarcasm)'], Response::HTTP_OK);
        }

        return new JsonResponse(['message' => 'Game is not active anymore'], Response::HTTP_OK);
    }



    #[Route('/get_time/{gameId}', name: 'getTime', methods: ['GET'])]
    public function getTime(GameManager $gameManager, string $gameId): JsonResponse {
        $game = $gameManager->getGame($gameId);
        if ($game) {
            $timeLeft = 300 - (time() - $game['startTime']);
            return new JsonResponse(['timeLeft' => max(0, $timeLeft)], Response::HTTP_OK);
        }

        $games = $gameManager->getGameIds();

        return new JsonResponse(['message' => 'Game not found', 'games' => $games], Response::HTTP_NOT_FOUND);
    }
}
