<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Interface\UuidableInterface;
use App\Entity\Trait\TimestampableEntityTrait;
use App\Entity\Trait\UuidableEntityTrait;
use App\Repository\ParticipantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Participant inscrit au jeu « La Roue Ford ».
 *
 * L'inscription seule ne donne pas accès à la roue : à l'entrée, l'équipe
 * vérifie l'inscription dans le back-office puis autorise le participant à
 * jouer ($playAuthorizedAt, voir PlayAuthorization). Chaque participant ne
 * joue qu'une seule fois, qu'il gagne ou non : SpinService n'enregistre
 * jamais plus d'un tirage par participant.
 */
#[ORM\Entity(repositoryClass: ParticipantRepository::class)]
#[ORM\Table(name: 'participant')]
#[ORM\UniqueConstraint(name: 'participant_uuid_uq', columns: ['uuid'])]
#[ORM\Index(name: 'participant_email_idx', columns: ['email'])]
#[ORM\Index(name: 'participant_play_authorized_at_idx', columns: ['play_authorized_at'])]
class Participant implements UuidableInterface
{
    use TimestampableEntityTrait;
    use UuidableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $firstName;

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $lastName;

    #[ORM\Column(type: Types::STRING, length: 160)]
    private string $company;

    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $phone = null;

    /**
     * Date à laquelle l'équipe a autorisé le participant à jouer depuis le
     * back-office. Null tant qu'il ne s'est pas présenté à l'entrée.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $playAuthorizedAt = null;

    /**
     * @var Collection<int, Spin>
     */
    #[ORM\OneToMany(mappedBy: 'participant', targetEntity: Spin::class)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $spins;

    public function __construct(
        string $firstName,
        string $lastName,
        string $company,
        string $email,
        ?string $phone = null,
    ) {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->company = $company;
        $this->email = $email;
        $this->phone = $phone;
        $this->spins = new ArrayCollection();

        $this->generateUuid();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getCompany(): string
    {
        return $this->company;
    }

    public function setCompany(string $company): static
    {
        $this->company = $company;

        return $this;
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

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    /**
     * @return Collection<int, Spin>
     */
    public function getSpins(): Collection
    {
        return $this->spins;
    }

    /**
     * Le tirage du participant, s'il a joué. Il y en a au plus un : SpinService
     * refuse toute nouvelle tentative une fois qu'un tirage existe.
     */
    public function getSpin(): ?Spin
    {
        return $this->spins->isEmpty() ? null : $this->spins->first();
    }

    public function getPlayAuthorizedAt(): ?\DateTimeImmutable
    {
        return $this->playAuthorizedAt;
    }

    public function authorizePlay(\DateTimeImmutable $authorizedAt): static
    {
        $this->playAuthorizedAt = $authorizedAt;

        return $this;
    }

    public function isPlayAuthorized(): bool
    {
        return null !== $this->playAuthorizedAt;
    }

    /**
     * Vrai tant que le participant, autorisé à l'entrée, n'a pas encore joué.
     */
    public function canPlay(): bool
    {
        return $this->isPlayAuthorized() && !$this->hasPlayed();
    }

    public function hasPlayed(): bool
    {
        return !$this->spins->isEmpty();
    }

    public function getFullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    /**
     * Nom du lot remporté, pour l'affichage (back-office notamment).
     *
     * Parcourt tous les tirages : l'historique antérieur à la règle « un seul
     * tirage » peut contenir des cases « perdu » suivies d'un gain.
     */
    public function getWonPrizeName(): ?string
    {
        foreach ($this->spins as $spin) {
            if ($spin->isWin()) {
                return $spin->getPrizeName();
            }
        }

        return null;
    }
}
