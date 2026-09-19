<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Exception\ShouldNotHappenException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

trait UuidableEntityTrait
{
    #[ORM\Column(type: Types::STRING, nullable: true)]
    protected string|UuidInterface|null $uuid = null;

    public function setUuid(UuidInterface|string $uuid): void
    {
        $this->uuid = $uuid;
    }

    /**
     * @throws ShouldNotHappenException
     */
    public function getUuid(): ?UuidInterface
    {
        if (is_string($this->uuid)) {
            if ('' === $this->uuid) {
                throw new ShouldNotHappenException();
            }

            return Uuid::fromString($this->uuid);
        }

        return $this->uuid;
    }

    public function generateUuid(): void
    {
        if ($this->uuid) {
            return;
        }

        $this->uuid = Uuid::uuid6();
    }
}