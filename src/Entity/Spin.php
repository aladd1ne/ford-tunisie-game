<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Interface\UuidableInterface;
use App\Entity\Trait\TimestampableEntityTrait;
use App\Entity\Trait\UuidableEntityTrait;
use App\Enum\PrizeType;
use App\Repository\SpinRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tirage effectué par un participant.
 *
 * La relation vers le participant est un OneToOne : Doctrine crée donc un
 * index unique sur « participant_id ». C'est cette contrainte, au niveau de
 * la base de données, qui garantit en dernier ressort qu'un participant ne
 * peut pas jouer deux fois (double-clic, rejeu réseau, requêtes simultanées).
 *
 * Le nom et le type du lot sont recopiés dans le tirage : le résultat reste
 * traçable même si le lot est renommé, désactivé ou supprimé par la suite.
 */
#[ORM\Entity(repositoryClass: SpinRepository::class)]
#[ORM\Table(name: 'spin')]
#[ORM\UniqueConstraint(name: 'spin_uuid_uq', columns: ['uuid'])]
#[ORM\Index(name: 'spin_spun_at_idx', columns: ['spun_at'])]
class Spin implements UuidableInterface
{
    use TimestampableEntityTrait;
    use UuidableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'spin', targetEntity: Participant::class)]
    #[ORM\JoinColumn(name: 'participant_id', nullable: false, onDelete: 'CASCADE')]
    private Participant $participant;

    #[ORM\ManyToOne(targetEntity: Prize::class)]
    #[ORM\JoinColumn(name: 'prize_id', nullable: false, onDelete: 'RESTRICT')]
    private Prize $prize;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $prizeName;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PrizeType::class)]
    private PrizeType $prizeType;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $spunAt;

    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $userAgent = null;

    public function __construct(
        Participant $participant,
        Prize $prize,
        DateTimeImmutable $spunAt,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ) {
        $this->participant = $participant;
        $this->prize = $prize;
        $this->prizeName = $prize->getName();
        $this->prizeType = $prize->getType();
        $this->spunAt = $spunAt;
        $this->ipAddress = $ipAddress;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);

        $this->generateUuid();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParticipant(): Participant
    {
        return $this->participant;
    }

    public function getPrize(): Prize
    {
        return $this->prize;
    }

    public function getPrizeName(): string
    {
        return $this->prizeName;
    }

    public function getPrizeType(): PrizeType
    {
        return $this->prizeType;
    }

    public function getSpunAt(): DateTimeImmutable
    {
        return $this->spunAt;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function isWinning(): bool
    {
        return $this->prizeType->isWinning();
    }
}
