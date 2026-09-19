<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Prize;
use App\Enum\PrizeType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Gestion des lots de « La Roue Ford ».
 *
 * Les lots pilotent entièrement le jeu (voir WeightedPrizeSelector et
 * SpinService) : les ajouter, les modifier ou les supprimer ici change le
 * comportement du tirage sans qu'aucune ligne de code du jeu n'ait à être
 * touchée.
 */
class PrizeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Prize::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Lot')
            ->setEntityLabelInPlural('Lots')
            ->setDefaultSort(['displayOrder' => 'ASC'])
            ->setPageTitle('index', 'Lots de La Roue Ford')
            ->setPageTitle('new', 'Ajouter un lot')
            ->setPageTitle('edit', static fn (Prize $prize): string => sprintf('Modifier « %s »', $prize->getName()))
            ->setPageTitle('detail', static fn (Prize $prize): string => $prize->getName())
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->reorder(Crud::PAGE_INDEX, [Action::EDIT, Action::DELETE]);
    }

    /**
     * Prize n'a pas de constructeur sans argument (le nom est obligatoire dès
     * la création) : EasyAdmin doit donc être instruit de sa construction.
     */
    public function createEntity(string $entityFqcn): Prize
    {
        return new Prize('');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield TextField::new('name', 'Nom du lot');

        yield TextareaField::new('description', 'Description')
            ->setRequired(false)
            ->hideOnIndex();

        yield ChoiceField::new('type', 'Type')
            ->setChoices([
                PrizeType::MAIN->label() => PrizeType::MAIN,
                PrizeType::CONSOLATION->label() => PrizeType::CONSOLATION,
            ])
            // EasyAdmin résout les choix « BackedEnum » vers leur valeur scalaire
            // en interne : le callback de badge et le formatage reçoivent donc
            // tantôt l'enum, tantôt sa valeur brute selon le contexte d'appel.
            ->renderAsBadges(static fn (mixed $value): string => PrizeType::MAIN === self::asPrizeType($value) ? 'success' : 'secondary')
            ->formatValue(static fn (mixed $value): string => self::asPrizeType($value)?->label() ?? '');

        yield IntegerField::new('weight', 'Poids (probabilité)')
            ->setHelp("Plus le poids est élevé, plus le lot a de chances d'être tiré. Un poids de 0 retire le lot du tirage.");

        yield IntegerField::new('remainingStock', 'Stock restant')
            ->setRequired(false)
            ->setHelp('Laisser vide pour un stock illimité. Passe automatiquement à 0 quand le lot est épuisé.');

        yield IntegerField::new('displayOrder', "Ordre d'affichage")
            ->hideOnIndex();

        yield ColorField::new('color', 'Couleur sur la roue')
            ->setRequired(false)
            ->hideOnIndex();

        yield BooleanField::new('active', 'Actif')
            ->renderAsSwitch(true)
            ->setHelp('Un lot inactif ne peut plus être tiré, mais reste visible sur la roue.');
    }

    private static function asPrizeType(mixed $value): ?PrizeType
    {
        if ($value instanceof PrizeType) {
            return $value;
        }

        return \is_string($value) ? PrizeType::tryFrom($value) : null;
    }
}
