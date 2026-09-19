<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Participant;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Email;

/**
 * Données saisies dans le formulaire d'inscription.
 *
 * Le formulaire n'est jamais mappé directement sur l'entité Participant :
 * le DTO porte les contraintes de validation d'entrée, l'entité reste
 * maîtresse de son état.
 */
class RegistrationDto
{
    #[Assert\NotBlank(message: 'Veuillez saisir votre prénom.')]
    #[Assert\Length(
        min: 2,
        max: 80,
        minMessage: 'Votre prénom doit contenir au moins {{ limit }} caractères.',
        maxMessage: 'Votre prénom ne peut pas dépasser {{ limit }} caractères.',
    )]
    public ?string $firstName = null;

    #[Assert\NotBlank(message: 'Veuillez saisir votre nom.')]
    #[Assert\Length(
        min: 2,
        max: 80,
        minMessage: 'Votre nom doit contenir au moins {{ limit }} caractères.',
        maxMessage: 'Votre nom ne peut pas dépasser {{ limit }} caractères.',
    )]
    public ?string $lastName = null;

    #[Assert\NotBlank(message: 'Veuillez indiquer votre société ou votre agence.')]
    #[Assert\Length(
        min: 2,
        max: 160,
        minMessage: 'Ce champ doit contenir au moins {{ limit }} caractères.',
        maxMessage: 'Ce champ ne peut pas dépasser {{ limit }} caractères.',
    )]
    public ?string $company = null;

    #[Assert\NotBlank(message: 'Veuillez saisir votre adresse e-mail.')]
    #[Assert\Email(
        message: "L'adresse e-mail {{ value }} n'est pas valide.",
        mode: Email::VALIDATION_MODE_HTML5,
    )]
    #[Assert\Length(
        max: 180,
        maxMessage: "L'adresse e-mail ne peut pas dépasser {{ limit }} caractères.",
    )]
    public ?string $email = null;

    #[Assert\Length(
        max: 40,
        maxMessage: 'Le numéro de téléphone ne peut pas dépasser {{ limit }} caractères.',
    )]
    #[Assert\Regex(
        pattern: '/^[0-9+().\s-]{6,40}$/',
        message: "Le numéro de téléphone n'est pas valide.",
    )]
    public ?string $phone = null;

    public function toParticipant(): Participant
    {
        return new Participant(
            trim((string) $this->firstName),
            trim((string) $this->lastName),
            trim((string) $this->company),
            mb_strtolower(trim((string) $this->email)),
            null === $this->phone || '' === trim($this->phone) ? null : trim($this->phone),
        );
    }
}
