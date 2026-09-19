<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\RegistrationDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\EmailValidator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @covers \App\Dto\RegistrationDto
 */
final class RegistrationDtoTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        // Même mode de validation d'e-mail que l'application
        // (framework.validation.email_validation_mode: html5).
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                EmailValidator::class => new EmailValidator(Email::VALIDATION_MODE_HTML5),
            ]))
            ->getValidator();
    }

    public function testValidRegistrationHasNoViolation(): void
    {
        self::assertCount(0, $this->validator->validate($this->validDto()));
    }

    public function testPhoneIsOptional(): void
    {
        $dto = $this->validDto();
        $dto->phone = null;

        self::assertCount(0, $this->validator->validate($dto));

        $dto->phone = '';

        self::assertCount(0, $this->validator->validate($dto));
    }

    /**
     * @dataProvider requiredFieldProvider
     */
    public function testRequiredFieldsAreRejectedWhenBlank(string $property, string $expectedMessage): void
    {
        $dto = $this->validDto();
        $dto->{$property} = '';

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame($property, $violations->get(0)->getPropertyPath());
        self::assertSame($expectedMessage, $violations->get(0)->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function requiredFieldProvider(): iterable
    {
        yield 'prénom' => ['firstName', 'Veuillez saisir votre prénom.'];
        yield 'nom' => ['lastName', 'Veuillez saisir votre nom.'];
        yield 'société' => ['company', 'Veuillez indiquer votre société ou votre agence.'];
        yield 'e-mail' => ['email', 'Veuillez saisir votre adresse e-mail.'];
    }

    /**
     * @dataProvider invalidEmailProvider
     */
    public function testInvalidEmailIsRejected(string $email): void
    {
        $dto = $this->validDto();
        $dto->email = $email;

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count(), sprintf('« %s » aurait dû être rejeté.', $email));
        self::assertSame('email', $violations->get(0)->getPropertyPath());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEmailProvider(): iterable
    {
        yield 'sans arobase' => ['marc.dupont'];
        yield 'sans domaine' => ['marc@'];
        yield 'sans partie locale' => ['@exemple.fr'];
        yield 'avec espace' => ['marc dupont@exemple.fr'];
    }

    public function testValidEmailIsAccepted(): void
    {
        foreach (['marc.dupont@exemple.fr', 'm@e.fr', 'prenom+tag@sous.domaine.fr'] as $email) {
            $dto = $this->validDto();
            $dto->email = $email;

            self::assertCount(0, $this->validator->validate($dto), sprintf('« %s » aurait dû être accepté.', $email));
        }
    }

    public function testInvalidPhoneIsRejected(): void
    {
        $dto = $this->validDto();
        $dto->phone = 'appelez-moi';

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame("Le numéro de téléphone n'est pas valide.", $violations->get(0)->getMessage());
    }

    public function testTooShortFirstNameIsRejected(): void
    {
        $dto = $this->validDto();
        $dto->firstName = 'M';

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('Votre prénom doit contenir au moins 2 caractères.', $violations->get(0)->getMessage());
    }

    public function testTooLongCompanyIsRejected(): void
    {
        $dto = $this->validDto();
        $dto->company = str_repeat('a', 161);

        self::assertGreaterThan(0, $this->validator->validate($dto)->count());
    }

    public function testAllMessagesAreInFrench(): void
    {
        $dto = new RegistrationDto();
        $dto->email = 'invalide';
        $dto->phone = 'invalide';

        foreach ($this->validator->validate($dto) as $violation) {
            self::assertMatchesRegularExpression(
                '/^[A-ZÉÀÎÔÙ]/u',
                (string) $violation->getMessage(),
                'Chaque message de validation doit être une phrase française.',
            );
        }
    }

    public function testToParticipantNormalisesTheInput(): void
    {
        $dto = new RegistrationDto();
        $dto->firstName = '  Marc ';
        $dto->lastName = ' Dupont  ';
        $dto->company = ' Agence Nord ';
        $dto->email = '  Marc.DUPONT@Exemple.FR ';
        $dto->phone = '  +33 6 12 34 56 78 ';

        $participant = $dto->toParticipant();

        self::assertSame('Marc', $participant->getFirstName());
        self::assertSame('Dupont', $participant->getLastName());
        self::assertSame('Agence Nord', $participant->getCompany());
        self::assertSame('marc.dupont@exemple.fr', $participant->getEmail());
        self::assertSame('+33 6 12 34 56 78', $participant->getPhone());
        self::assertNotNull($participant->getUuid());
        self::assertFalse($participant->hasPlayed());
    }

    public function testToParticipantTurnsABlankPhoneIntoNull(): void
    {
        $dto = $this->validDto();
        $dto->phone = '   ';

        self::assertNull($dto->toParticipant()->getPhone());
    }

    private function validDto(): RegistrationDto
    {
        $dto = new RegistrationDto();
        $dto->firstName = 'Marc';
        $dto->lastName = 'Dupont';
        $dto->company = 'Agence Nord';
        $dto->email = 'marc.dupont@exemple.fr';
        $dto->phone = '+33 6 12 34 56 78';

        return $dto;
    }
}
