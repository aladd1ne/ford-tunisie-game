<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Interface\UuidableInterface;
use App\Entity\Trait\BlamableEntityTrait;
use App\Entity\Trait\SoftDeleteableEntityTrait;
use App\Entity\Trait\TimestampableEntityTrait;
use App\Entity\Trait\UuidableEntityTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
#[ORM\Index(name: 'uuid_idx', columns: ['uuid'])]
abstract class BaseEntity implements UuidableInterface
{
    use BlamableEntityTrait;
    use SoftDeleteableEntityTrait;
    use UuidableEntityTrait;
    use TimestampableEntityTrait;
}