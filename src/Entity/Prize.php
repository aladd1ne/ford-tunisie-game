<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Interface\UuidableInterface;
use App\Entity\Trait\TimestampableEntityTrait;
use App\Entity\Trait\UuidableEntityTrait;
use App\Enum\PrizeType;
use App\Repository\PrizeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lot pouvant être remporté sur « La Roue Ford ».
 *
 * Les lots sont entièrement pilotés par les données : poids (probabilité),
 * stock, activation et ordre d'affichage sont stockés en base, ce qui permet
 * de les faire évoluer (via une future interface d'administration) sans
 * toucher à la logique de jeu.
 */
#[ORM\Entity(repositoryClass: PrizeRepository::class)]
#[ORM\Table(name: 'prize')]
#[ORM\UniqueConstraint(name: 'prize_uuid_uq', columns: ['uuid'])]
#[ORM\Index(name: 'prize_selectable_idx', columns: ['is_active', 'remaining_stock'])]
class Prize implements UuidableInterface
{
    use TimestampableEntityTrait;
    use UuidableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PrizeType::class)]
    private PrizeType $type = PrizeType::CONSOLATION;

    /**
     * Poids relatif du lot dans le tirage aléatoire pondéré.
     * Un poids de 0 retire le lot du tirage sans le désactiver.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    private int $weight = 0;

    /**
     * Stock restant, ou null pour un lot en quantité illimitée.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $remainingStock = null;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $displayOrder = 0;

    /**
     * Couleur du secteur sur la roue (format hexadécimal, ex. « #00095B »).
     */
    #[ORM\Column(type: Types::STRING, length: 7, nullable: true)]
    private ?string $color = null;

    public function __construct(string $name, PrizeType $type = PrizeType::CONSOLATION, int $weight = 0)
    {
        $this->name = $name;
        $this->type = $type;
        $this->weight = $weight;

        $this->generateUuid();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getType(): PrizeType
    {
        return $this->type;
    }

    public function setType(PrizeType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): static
    {
        $this->weight = max(0, $weight);

        return $this;
    }

    public function getRemainingStock(): ?int
    {
        return $this->remainingStock;
    }

    public function setRemainingStock(?int $remainingStock): static
    {
        $this->remainingStock = null === $remainingStock ? null : max(0, $remainingStock);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): static
    {
        $this->displayOrder = $displayOrder;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function hasLimitedStock(): bool
    {
        return null !== $this->remainingStock;
    }

    public function isOutOfStock(): bool
    {
        return $this->hasLimitedStock() && $this->remainingStock <= 0;
    }

    /**
     * Un lot n'entre dans le tirage que s'il est actif, pondéré et disponible.
     */
    public function isSelectable(): bool
    {
        return $this->active && $this->weight > 0 && !$this->isOutOfStock();
    }
}
