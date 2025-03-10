<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;

class BankManager{
    public function __construct(
		private CacheInterface $cache,
		private EntityManagerInterface $entityManager
	) {}
}
