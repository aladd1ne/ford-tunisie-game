<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

trait BlamableEntityTrait
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    #[Gedmo\Blameable(on: 'create')]
    protected ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    #[Gedmo\Blameable(on: 'update')]
    protected ?User $updatedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'deleted_by_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    #[Gedmo\Blameable(on: 'change', field: ['deletedAt'])]
    protected ?User $deletedBy = null;

    public function setCreatedBy($createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): void
    {
        $this->updatedBy = $updatedBy;
    }

    public function getDeletedBy(): ?User
    {
        return $this->deletedBy;
    }

    public function setDeletedBy(?User $deletedBy): void
    {
        $this->deletedBy = $deletedBy;
    }
}