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

    /**
     * Tirage gagnant d'un participant, s'il existe, lu directement en base
     * (et non via la collection déjà chargée sur l'entité) : c'est ce qui
     * permet à SpinService de vérifier l'état réel sous verrou pessimiste,
     * y compris un gain inséré entre-temps par une autre requête.
     */
    public function findWinningByParticipant(Participant $participant): ?Spin
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.participant = :participant')
            ->andWhere('s.prizeType IS NOT NULL')
            ->setParameter('participant', $participant)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(Spin $spin, bool $flush = true): void
    {
        $this->getEntityManager()->persist($spin);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
