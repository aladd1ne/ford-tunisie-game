<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

trait TimestampableEntityTrait
{
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    protected ?DateTimeInterface $createdAt;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    protected ?DateTimeInterface $updatedAt;

    public function setCreatedAt(?DateTime $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setUpdatedAt(?DateTime $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }
}