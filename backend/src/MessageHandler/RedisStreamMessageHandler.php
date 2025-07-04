<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\RedisStreamMessage;
use App\Repository\UserRepository;
use App\Service\GameManager;
use App\Utils\Functions;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\PublisherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Redis;
use Symfony\Contracts\Cache\ItemInterface;

#[AsMessageHandler]
final class RedisStreamMessageHandler
{
    private Redis $redis;

    public function __construct(
        private CacheInterface $cache,
        private UserRepository $userRepository,
        private PublisherInterface $mercureHub,
        private LoggerInterface $logger,
        private GameManager $gameManager
    ) {
        $this->redis = new Redis();
        $this->redis->connect('redis', 6379);
    }



    public function __invoke(RedisStreamMessage $message): void {
        // "Hyperparamètres" de l'algorithme de matchmaking
        $deltaMoney = 10_000;

        // Récupérer les données du message
        $receivedData = $message->getData();
        $receivedUser = $this->userRepository->findOneBy(['username' => $receivedData['username']]);
        $matchedUser = null;

        // Lire les utilisateurs en attente dans Redis
        $waitingUsers = $this->redis->xRead(['matchmaking_stream' => '0'], null, 1000);
        $this->logger->info(print_r($waitingUsers, true));

        // Vérifier si des utilisateurs sont disponibles pour une partie
        if (is_array($waitingUsers) && count($waitingUsers) > 0) {
            foreach ($waitingUsers['matchmaking_stream'] as $id => $data) {
                // Condition de matching basée sur la différence d'argent
                if (abs($data['money'] - $receivedData['money']) < $deltaMoney && $data['username'] != $receivedData['username']) {
                    $possibleMatchedUser = $this->userRepository->find($receivedData['id']);

                    if ($possibleMatchedUser && $data['communicationPreference'] == $possibleMatchedUser->getSettings()['communicationPreference']) {
                        $this->logger->info('found matching user ' . $data['username'] . ' of timestamp ' . $data['connection_time']);

                        $matchedUser = $this->userRepository->findOneBy(['username' => $data['username']]);
                        $indicator = $this->handleMatchedUser($receivedUser, $matchedUser, $id);

                        if ($indicator === true || $indicator === null) {
                            return; // Dans les deux cas, le joueur n'est plus à traiter
                        } else if ($indicator === false) {
                            continue;
                        }
                    }
                }
            }
        }

        $this->addUserToQueue($message->getData());
    }



    private function handleMatchedUser(
        User $receivedUser,
        User $matchedUser,
        string $matchedId
    ): ?bool { // Retourne true si la partie est initiée, null si le joueur n'est pas là, false sinon
        $attempts = 0;
        $maxAttempts = 5;
        $interval = 1; // en secondes
        $this->cache->delete('pong_'. $matchedUser->getUsername());
        $this->cache->delete('pong_' . $receivedUser->getUsername());

        while ($attempts < $maxAttempts) {
            $attempts++;
            $isMatchedUserThere = $this->cache->get('pong_' . $matchedUser->getUsername(), function (ItemInterface $item) use ($matchedUser, $maxAttempts, $interval): bool {
                $item->expiresAfter($maxAttempts * $interval);
                $this->logger->info('adding matched user to cache, send ping.');
                Functions::sendMatchmakingUpdate($this->mercureHub, $matchedUser->getUsername(), 'ping');
                return false;
            });

            $isReceivedUserThere = $this->cache->get('pong_' . $receivedUser->getUsername(), function (ItemInterface $item) use ($receivedUser, $maxAttempts, $interval): bool {
                $item->expiresAfter($maxAttempts * $interval);
                $this->logger->info('adding received user to cache, send ping.');
                Functions::sendMatchmakingUpdate($this->mercureHub, $receivedUser->getUsername(), 'ping');
                return false;
            });

            // Si les deux utilisateurs sont là, on initie la partie
            $this->logger->info('isMatchedUserThere : ' . ($isMatchedUserThere ? 'true' : 'false') . ' | isReceivedUserThere : ' . ($isReceivedUserThere ? 'true' : 'false'));
            if ($isMatchedUserThere && $isReceivedUserThere) {
                $this->removeUserFromQueue($matchedId);
                $this->logger->info('delete message ' . $matchedId . 'from redis matchmaking_stream');
                $this->gameManager->initializeGame($receivedUser, $matchedUser);
                return true;
            } else if ($attempts == $maxAttempts) {
                $this->logger->info('max attempts reached, user ' . $receivedUser->getUsername() . ' is not matched with ' . $matchedUser->getUsername() . ', adding to queue.');

                if (!$isReceivedUserThere)  {
                    return null;
                }
                if (!$isMatchedUserThere) {
                    $this->removeUserFromQueue($matchedId);
                    return false;
                }
                return false;
            }

            sleep($interval); // Attendre 1 seconde entre chaque essai
        }
        return false;
    }



    private function removeUserFromQueue(string $id): void {
        $this->redis->xAck('matchmaking_stream', 'matchmaking_group', [$id]);
        $this->redis->xDel('matchmaking_stream', [$id]);
    }

    private function addUserToQueue(array $data): string {
        $messageId = $this->redis->xAdd('matchmaking_stream', '*', $data);
        Functions::sendMatchmakingUpdate($this->mercureHub, $data['username'], 'in_queue', $messageId);
        return $messageId;
    }

    private function searchUserInQueue(string $username): ?array {
        $waitingUsers = $this->redis->xRead(['matchmaking_stream' => '0'], null, 9223372036854775807);
        if (is_array($waitingUsers) && count($waitingUsers) > 0) {
            foreach ($waitingUsers['matchmaking_stream'] as $id => $data) {
                if ($data['username'] === $username) {
                    $this->removeUserFromQueue($id);
                    return $data;
                }
            }
        }
        return null;
    }
}
