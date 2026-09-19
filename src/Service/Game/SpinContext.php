<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * Informations de traçabilité associées à un tirage.
 *
 * Volontairement découplé de la requête HTTP : la couche domaine ne dépend
 * pas de Symfony\Component\HttpFoundation.
 */
final class SpinContext
{
    public function __construct(
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {
    }
}
