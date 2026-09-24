<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\Voter\InscriptionAccountVoter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Comptes « accueil » du back-office.
 *
 * Ces comptes (ROLE_INSCRIPTION) n'accèdent qu'aux inscriptions : recherche
 * des visiteurs et autorisation de jouer. Seul le super administrateur les
 * gère ; les administrateurs, eux, restent créés en ligne de commande
 * (app:admin:create-super-admin) et n'apparaissent jamais ici.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Compte accueil')
            ->setEntityLabelInPlural('Comptes accueil')
            ->setDefaultSort(['email' => 'ASC'])
            ->setSearchFields(['email'])
            // Empêche d'afficher, modifier ou supprimer un compte
            // administrateur en forgeant l'URL avec son identifiant.
            ->setEntityPermission(InscriptionAccountVoter::MANAGE)
            ->setPageTitle('index', 'Comptes accueil')
            ->setPageTitle('new', 'Créer un compte accueil')
            ->setPageTitle('edit', static fn (User $user): string => sprintf('Modifier « %s »', $user->getEmail()))
            ->setPageTitle('detail', static fn (User $user): string => $user->getEmail())
            ->setHelp('index', 'Ces comptes ont uniquement accès aux inscriptions : ils recherchent les visiteurs et les autorisent à jouer.')
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->disable(Action::BATCH_DELETE)
            ->reorder(Crud::PAGE_INDEX, [Action::EDIT, Action::DELETE]);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        // Les rôles sont stockés en JSON : on ne liste que les comptes dont
        // l'unique rôle est ROLE_INSCRIPTION.
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.roles LIKE :inscriptionRole')
            ->andWhere('entity.roles NOT LIKE :adminRole')
            ->setParameter('inscriptionRole', '%"'.User::ROLE_INSCRIPTION.'"%')
            ->setParameter('adminRole', '%ADMIN%');
    }

    public function createEntity(string $entityFqcn): User
    {
        return (new User(''))->setRoles([User::ROLE_INSCRIPTION]);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->prepareUser($entityInstance);

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->prepareUser($entityInstance);

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield EmailField::new('email', 'Adresse e-mail');

        yield TextField::new('plainPassword', 'Mot de passe')
            ->onlyOnForms()
            ->setFormType(RepeatedType::class)
            ->setFormTypeOptions([
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'Mot de passe',
                    'attr' => ['autocomplete' => 'new-password'],
                    'help' => Crud::PAGE_EDIT === $pageName ? 'Laisser vide pour conserver le mot de passe actuel.' : 'Au moins 8 caractères.',
                ],
                'second_options' => [
                    'label' => 'Confirmer le mot de passe',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'constraints' => Crud::PAGE_NEW === $pageName
                    ? [new NotBlank(message: 'Choisissez un mot de passe.')]
                    : [],
            ])
            ->setRequired(Crud::PAGE_NEW === $pageName);

        yield DateTimeField::new('createdAt', 'Créé le')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->hideOnForm();
    }

    private function prepareUser(User $user): void
    {
        $user->setEmail(mb_strtolower(trim($user->getEmail())));
        // Quoi qu'il arrive, un compte géré ici reste limité aux inscriptions.
        $user->setRoles([User::ROLE_INSCRIPTION]);

        $plainPassword = $user->getPlainPassword();

        if (null !== $plainPassword && '' !== $plainPassword) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        }

        $user->eraseCredentials();
    }
}
