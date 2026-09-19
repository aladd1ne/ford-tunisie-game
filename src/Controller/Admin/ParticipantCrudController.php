<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Participant;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Liste des inscriptions à « La Roue Ford ».
 *
 * Volontairement en lecture seule : un participant et son tirage forment un
 * historique de jeu qui ne doit pas être altéré depuis le back-office —
 * SpinService garantit déjà qu'un participant ne joue qu'une seule fois, il
 * n'y a donc rien à corriger ici après coup.
 */
class ParticipantCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Participant::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Participant')
            ->setEntityLabelInPlural('Inscriptions')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPageTitle('index', 'Inscriptions à La Roue Ford')
            ->setPageTitle('detail', static fn (Participant $participant): string => $participant->getFullName());
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('firstName', 'Prénom');
        yield TextField::new('lastName', 'Nom');
        yield TextField::new('company', 'Société ou agence');
        yield EmailField::new('email', 'Adresse e-mail');
        yield TelephoneField::new('phone', 'Téléphone')
            ->setRequired(false)
            ->hideOnIndex();
        yield BooleanField::new('played', 'A joué')
            ->renderAsSwitch(false);
        yield TextField::new('wonPrizeName', 'Cadeau obtenu')
            ->formatValue(static fn (?string $value): string => $value ?? '—');
        yield DateTimeField::new('createdAt', 'Inscrit le')
            ->setFormat('dd/MM/yyyy HH:mm');
    }
}
