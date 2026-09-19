<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Participant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Participant>
 */
class ParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Participant::class);
    }

    public function findOneByUuid(string $uuid): ?Participant
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function save(Participant $participant, bool $flush = true): void
    {
        $this->getEntityManager()->persist($participant);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
