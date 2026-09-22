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
 * Un participant peut accumuler plusieurs tirages : chaque case « perdu »
 * peut être suivie d'une nouvelle tentative (« Rejouer »). Dès qu'un tirage
 * gagnant existe, SpinService refuse toute nouvelle tentative pour ce
 * participant — seul « Nouveau joueur » (qui crée un tout autre participant)
 * permet alors de rejouer.
 */
#[ORM\Entity(repositoryClass: ParticipantRepository::class)]
#[ORM\Table(name: 'participant')]
#[ORM\UniqueConstraint(name: 'participant_uuid_uq', columns: ['uuid'])]
#[ORM\Index(name: 'participant_email_idx', columns: ['email'])]
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
     * Tentative la plus récente, pour l'affichage (page /jeu).
     */
    public function getLatestSpin(): ?Spin
    {
        return $this->spins->isEmpty() ? null : $this->spins->last();
    }

    /**
     * Le tirage gagnant, s'il existe. Il y en a au plus un : SpinService
     * refuse toute nouvelle tentative une fois qu'un participant a gagné.
     */
    public function getWinningSpin(): ?Spin
    {
        foreach ($this->spins as $spin) {
            if ($spin->isWin()) {
                return $spin;
            }
        }

        return null;
    }

    public function hasWon(): bool
    {
        return null !== $this->getWinningSpin();
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
     */
    public function getWonPrizeName(): ?string
    {
        return $this->getWinningSpin()?->getPrizeName();
    }
}
