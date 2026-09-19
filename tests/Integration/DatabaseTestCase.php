<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Prize;
use App\Enum\PrizeType;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base des tests qui ont besoin d'une vraie base de données.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    use ResetsDatabase;

    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->resetDatabase($this->entityManager);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->close();
        }
    }

    protected function createPrize(
        string $name,
        int $weight,
        ?int $stock = null,
        PrizeType $type = PrizeType::CONSOLATION,
        bool $active = true,
    ): Prize {
        $prize = (new Prize($name, $type, $weight))
            ->setRemainingStock($stock)
            ->setActive($active);

        $this->entityManager->persist($prize);
        $this->entityManager->flush();

        return $prize;
    }
}
