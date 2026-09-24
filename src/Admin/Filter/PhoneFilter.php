<?php

declare(strict_types=1);

namespace App\Admin\Filter;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Filtre « Téléphone » de la liste des inscriptions.
 *
 * Les numéros sont enregistrés tels que saisis (« 06 12 34 56 78 »,
 * « 06.12.34.56.78 »…) : seuls les chiffres recherchés comptent, dans
 * l'ordre, quels que soient les séparateurs de part et d'autre.
 */
final class PhoneFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(string $propertyName = 'phone', string $label = 'Téléphone'): self
    {
        return (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(TextType::class)
            ->setFormTypeOption('attr', ['placeholder' => '06 12 34 56 78']);
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $digits = preg_replace('/\D+/', '', (string) $filterDataDto->getValue());

        if ('' === $digits) {
            return;
        }

        $queryBuilder
            ->andWhere(sprintf('%s.%s LIKE :%s', $filterDataDto->getEntityAlias(), $filterDataDto->getProperty(), $filterDataDto->getParameterName()))
            ->setParameter($filterDataDto->getParameterName(), '%'.implode('%', str_split($digits)).'%');
    }
}
