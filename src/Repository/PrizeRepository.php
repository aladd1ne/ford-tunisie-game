<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Prize;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Prize>
 */
class PrizeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Prize::class);
    }

    /**
     * Lots réellement tirables : actifs, pondérés et encore en stock.
     *
     * @return Prize[]
     */
    public function findSelectable(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = :active')
            ->andWhere('p.weight > 0')
            ->andWhere('p.remainingStock IS NULL OR p.remainingStock > 0')
            ->setParameter('active', true)
            ->orderBy('p.displayOrder', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lots affichés sur la roue : tous les lots actifs, stock épuisé compris,
     * afin que la roue conserve la même apparence pendant toute l'opération.
     *
     * @return Prize[]
     */
    public function findForWheel(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = :active')
            ->setParameter('active', true)
            ->orderBy('p.displayOrder', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Décrémente le stock de façon atomique.
     *
     * L'UPDATE conditionnel (« ... AND remaining_stock > 0 ») est exécuté par
     * la base : deux requêtes concurrentes ne peuvent donc pas réserver la
     * même dernière unité. La méthode renvoie false quand le stock vient
     * d'être épuisé par quelqu'un d'autre, ce qui permet à l'appelant de
     * retirer ce lot des candidats et de retirer au sort.
     */
    public function decrementStock(Prize $prize): bool
    {
        if (!$prize->hasLimitedStock()) {
            return true;
        }

        $affectedRows = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE prize SET remaining_stock = remaining_stock - 1 WHERE id = :id AND remaining_stock > 0',
            ['id' => $prize->getId()],
        );

        if (0 === $affectedRows) {
            return false;
        }

        // Resynchronise l'entité gérée avec la valeur écrite en base, sinon
        // l'UnitOfWork réécrirait un stock périmé au prochain flush.
        $this->getEntityManager()->refresh($prize);

        return true;
    }

    public function save(Prize $prize, bool $flush = true): void
    {
        $this->getEntityManager()->persist($prize);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
