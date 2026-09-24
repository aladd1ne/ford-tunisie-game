<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Interface\UuidableInterface;
use App\Entity\Trait\TimestampableEntityTrait;
use App\Entity\Trait\UuidableEntityTrait;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Utilisateur applicatif (back-office).
 *
 * Cette entité est référencée par la configuration de sécurité
 * (config/packages/security.yaml) et par BlamableEntityTrait : elle est le
 * socle sur lequel une interface d'administration des lots pourra
 * s'authentifier.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'user_email_uq', columns: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cette adresse e-mail.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, UuidableInterface
{
    /**
     * Accès limité aux inscriptions du back-office (recherche des visiteurs
     * et autorisation de jouer). ROLE_ADMIN en hérite (voir security.yaml).
     */
    public const ROLE_INSCRIPTION = 'ROLE_INSCRIPTION';

    use TimestampableEntityTrait;
    use UuidableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(type: Types::STRING)]
    private string $password;

    /**
     * Mot de passe en clair saisi dans le back-office, jamais persisté : il
     * est haché dans le mot de passe puis effacé (voir UserCrudController).
     */
    #[Assert\Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')]
    private ?string $plainPassword = null;

    public function __construct(string $email, string $password = '')
    {
        $this->email = $email;
        $this->password = $password;

        $this->generateUuid();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function isInscriptionOnly(): bool
    {
        $roles = $this->getRoles();

        return \in_array(self::ROLE_INSCRIPTION, $roles, true)
            && !\in_array('ROLE_ADMIN', $roles, true)
            && !\in_array('ROLE_SUPER_ADMIN', $roles, true);
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }
}
