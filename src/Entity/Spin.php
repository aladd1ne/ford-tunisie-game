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
 * Chaque participant ne joue qu'une seule fois, qu'il gagne ou non. La
 * relation reste un ManyToOne (sans index unique) car l'historique antérieur
 * peut contenir plusieurs tentatives par participant : c'est SpinService,
 * sous verrou pessimiste, qui garantit qu'aucun second tirage n'est créé.
 *
 * Le nom et le type du lot sont recopiés dans le tirage : le résultat reste
 * traçable même si le lot est renommé, désactivé ou supprimé par la suite.
 * C'est justement pour cela que la suppression d'un lot ne supprime jamais
 * les tirages qui le référencent (ON DELETE SET NULL sur prize_id) : seule
 * la relation $prize disparaît, $prizeName et $prizeType restent intacts.
 *
 * Un participant a 70 % de chances de gagner un lot lors de son tirage (voir
 * SpinService) : un tirage peut donc ne désigner aucun lot, auquel cas
 * $prize, $prizeName et $prizeType sont null depuis l'origine.
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

    #[ORM\ManyToOne(inversedBy: 'spins', targetEntity: Participant::class)]
    #[ORM\JoinColumn(name: 'participant_id', nullable: false, onDelete: 'CASCADE')]
    private Participant $participant;

    #[ORM\ManyToOne(targetEntity: Prize::class)]
    #[ORM\JoinColumn(name: 'prize_id', nullable: true, onDelete: 'SET NULL')]
    private ?Prize $prize = null;

    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    private ?string $prizeName = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PrizeType::class, nullable: true)]
    private ?PrizeType $prizeType = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $spunAt;

    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $userAgent = null;

    public function __construct(
        Participant $participant,
        ?Prize $prize,
        DateTimeImmutable $spunAt,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ) {
        $this->participant = $participant;
        $this->prize = $prize;
        $this->prizeName = $prize?->getName();
        $this->prizeType = $prize?->getType();
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

    public function getPrize(): ?Prize
    {
        return $this->prize;
    }

    public function getPrizeName(): ?string
    {
        return $this->prizeName;
    }

    public function getPrizeType(): ?PrizeType
    {
        return $this->prizeType;
    }

    /**
     * Faux : le tirage est tombé sur une case « perdu » de la roue.
     *
     * S'appuie sur $prizeType (recopié à la volée) plutôt que sur $prize :
     * un lot supprimé après coup met $prize à null (ON DELETE SET NULL)
     * sans changer le résultat historique du tirage.
     */
    public function isWin(): bool
    {
        return null !== $this->prizeType;
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
}
