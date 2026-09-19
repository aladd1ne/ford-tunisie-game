<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Prize;
use App\Enum\PrizeType;
use ReflectionProperty;

final class PrizeFactory
{
    public static function create(
        string $name,
        int $weight,
        ?int $stock = null,
        bool $active = true,
        PrizeType $type = PrizeType::CONSOLATION,
        ?int $id = null,
    ): Prize {
        $prize = (new Prize($name, $type, $weight))
            ->setRemainingStock($stock)
            ->setActive($active);

        if (null !== $id) {
            $property = new ReflectionProperty(Prize::class, 'id');
            $property->setValue($prize, $id);
        }

        return $prize;
    }
}
