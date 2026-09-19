<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

trait SoftDeleteableEntityTrait
{
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    protected ?DateTimeInterface $deletedAt = null;

    public function setDeletedAt(?DateTimeInterface $deletedAt = null): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function getDeletedAt(): ?DateTimeInterface
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }
}