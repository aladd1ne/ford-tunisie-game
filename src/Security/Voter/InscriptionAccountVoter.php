<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Un compte n'est gérable depuis « Comptes accueil » que s'il est limité aux
 * inscriptions : les comptes administrateurs restent hors d'atteinte, même
 * en forgeant l'URL avec leur identifiant (voir UserCrudController).
 *
 * Sur la page de création, EasyAdmin vérifie la permission avant même
 * d'instancier le compte : le sujet est alors nul, et le compte créé sera
 * de toute façon limité aux inscriptions.
 *
 * @extends Voter<string, User|null>
 */
final class InscriptionAccountVoter extends Voter
{
    public const MANAGE = 'MANAGE_INSCRIPTION_ACCOUNT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && (null === $subject || $subject instanceof User);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return null === $subject || $subject->isInscriptionOnly();
    }
}
