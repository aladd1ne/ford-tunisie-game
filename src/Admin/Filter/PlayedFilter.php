<?php

declare(strict_types=1);

namespace App\Admin\Filter;

use App\Entity\Spin;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\BooleanFilterType;

/**
 * Filtre « A joué » de la liste des inscriptions.
 *
 * « A joué » n'est pas une colonne : un participant a joué dès qu'un tirage
 * (Spin) existe pour lui. « Non » permet à l'équipe de ne lister que les
 * inscrits qui ne sont pas encore passés par la roue.
 */
final class PlayedFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(string $propertyName = 'played', string $label = 'A joué'): self
    {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(BooleanFilterType::class)
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle');
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $alias = $filterDataDto->getEntityAlias();
        $spinAlias = $filterDataDto->getParameterName().'_spin';

        $spins = $queryBuilder->getEntityManager()->createQueryBuilder()
            ->select($spinAlias.'.id')
            ->from(Spin::class, $spinAlias)
            ->where(sprintf('%s.participant = %s', $spinAlias, $alias))
            ->getDQL();

        $queryBuilder->andWhere(sprintf($filterDataDto->getValue() ? 'EXISTS (%s)' : 'NOT EXISTS (%s)', $spins));
    }
}
