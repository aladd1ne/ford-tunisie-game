<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Filter\PhoneFilter;
use App\Admin\Filter\PlayedFilter;
use App\Entity\Participant;
use App\Exception\Game\GameException;
use App\Service\Export\ParticipantSpreadsheetExporter;
use App\Service\Game\PlayAuthorization;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FilterFactory;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

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
    public const EXPORT_ACTION = 'exportXlsx';

    public function __construct(
        private readonly PlayAuthorization $playAuthorization,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly ParticipantSpreadsheetExporter $spreadsheetExporter,
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

    /**
     * Filtres ouverts à toute l'équipe (accueil et administrateurs) : « A joué
     * : Non » liste les inscrits qui ne sont pas encore passés par la roue, et
     * l'e-mail ou le téléphone retrouvent un visiteur précis.
     */
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(PlayedFilter::new())
            ->add(TextFilter::new('email', 'Adresse e-mail'))
            ->add(PhoneFilter::new());
    }

    public function configureActions(Actions $actions): Actions
    {
        // Un participant autorisé qui n'a pas encore joué peut être
        // ré-autorisé : il redevient alors le joueur attendu sur la roue.
        $authorizePlay = Action::new(self::AUTHORIZE_PLAY_ACTION, 'Autoriser à jouer', 'fa fa-play')
            ->linkToCrudAction(self::AUTHORIZE_PLAY_ACTION)
            ->displayIf(static fn (Participant $participant): bool => !$participant->hasPlayed())
            ->addCssClass('btn btn-success');

        // Ouvert à toute l'équipe (accueil et administrateurs) : l'URL de
        // l'action conserve la recherche et les filtres de la liste, que
        // l'export reprend.
        $export = Action::new(self::EXPORT_ACTION, 'Exporter en Excel', 'fa fa-file-excel')
            ->linkToCrudAction(self::EXPORT_ACTION)
            ->createAsGlobalAction()
            ->addCssClass('btn btn-success');

        return parent::configureActions($actions)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, $export)
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

    /**
     * Télécharge les inscriptions au format .xlsx, avec la même recherche,
     * les mêmes filtres et le même tri que la liste affichée (toutes pages
     * confondues).
     */
    public function exportXlsx(AdminContext $context): Response
    {
        $fields = FieldCollection::new($this->configureFields(Crud::PAGE_INDEX));
        $filters = $this->container->get(FilterFactory::class)->create($context->getCrud()->getFiltersConfig(), $fields, $context->getEntity());

        // Charge les tirages en même temps pour « A joué » et « Cadeau obtenu ».
        $participants = $this->createIndexQueryBuilder($context->getSearch(), $context->getEntity(), $fields, $filters)
            ->leftJoin('entity.spins', 'export_spin')
            ->addSelect('export_spin')
            ->getQuery()
            ->getResult();

        $response = new BinaryFileResponse($this->spreadsheetExporter->export($participants));
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('inscriptions-roue-ford-%s.xlsx', (new \DateTimeImmutable())->format('Y-m-d-His')),
        );

        return $response->deleteFileAfterSend();
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
            ->formatValue(static fn (?string $value): string => $value ?? '—');
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
