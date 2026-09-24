<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Participant;
use App\Exception\Game\GameException;
use App\Service\Game\PlayAuthorization;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liste des inscriptions à « La Roue Ford ».
 *
 * C'est l'outil de l'équipe à l'entrée : elle recherche le visiteur pour
 * vérifier son inscription, puis l'autorise à jouer (« Autoriser à jouer »).
 * La roue accueille alors ce participant. Un visiteur non inscrit passe
 * d'abord par le formulaire d'inscription (lien « Inscrire un visiteur »).
 *
 * Les données restent en lecture seule : un participant et son tirage
 * forment un historique de jeu qui ne doit pas être altéré depuis le
 * back-office — SpinService garantit déjà qu'un participant ne joue qu'une
 * seule fois.
 */
class ParticipantCrudController extends AbstractCrudController
{
    public const AUTHORIZE_PLAY_ACTION = 'authorizePlay';

    public function __construct(
        private readonly PlayAuthorization $playAuthorization,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

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
            ->setSearchFields(['firstName', 'lastName', 'email', 'company', 'phone'])
            ->setPageTitle('index', 'Inscriptions à La Roue Ford')
            ->setPageTitle('detail', static fn (Participant $participant): string => $participant->getFullName());
    }

    public function configureActions(Actions $actions): Actions
    {
        // Un participant autorisé qui n'a pas encore joué peut être
        // ré-autorisé : il redevient alors le joueur attendu sur la roue.
        $authorizePlay = Action::new(self::AUTHORIZE_PLAY_ACTION, 'Autoriser à jouer', 'fa fa-play')
            ->linkToCrudAction(self::AUTHORIZE_PLAY_ACTION)
            ->displayIf(static fn (Participant $participant): bool => !$participant->hasPlayed())
            ->addCssClass('btn btn-success');

        return parent::configureActions($actions)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, $authorizePlay)
            ->add(Crud::PAGE_DETAIL, $authorizePlay);
    }

    public function authorizePlay(AdminContext $context): Response
    {
        $participant = $context->getEntity()->getInstance();

        if (!$participant instanceof Participant) {
            throw $this->createNotFoundException();
        }

        try {
            $this->playAuthorization->authorize($participant);
            $this->addFlash('success', sprintf('%s est autorisé(e) à jouer : la roue l’attend.', $participant->getFullName()));
        } catch (GameException $exception) {
            $this->addFlash('danger', sprintf('%s : %s', $participant->getFullName(), $exception->getMessage()));
        }

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->unset('entityId')
            ->generateUrl());
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
        yield DateTimeField::new('playAuthorizedAt', 'Autorisé à jouer le')
            ->setFormat('dd/MM/yyyy HH:mm');
    }
}
