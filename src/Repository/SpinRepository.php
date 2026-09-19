<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Participant;
use App\Entity\Spin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Spin>
 */
class SpinRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Spin::class);
    }

    public function findOneByParticipant(Participant $participant): ?Spin
    {
        return $this->findOneBy(['participant' => $participant]);
    }

    public function save(Spin $spin, bool $flush = true): void
    {
        $this->getEntityManager()->persist($spin);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
