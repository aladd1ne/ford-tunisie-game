<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Game;

use App\Entity\Participant;
use App\Entity\Spin;
use App\Exception\Game\AlreadyPlayedException;
use App\Repository\ParticipantRepository;
use App\Service\Game\PlayAuthorization;
use App\Tests\Integration\DatabaseTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * @covers \App\Service\Game\PlayAuthorization
 */
final class PlayAuthorizationTest extends DatabaseTestCase
{
    public function testNoPlayerIsExpectedUntilSomeoneIsAuthorized(): void
    {
        $this->createParticipant('marc.dupont@exemple.fr');

        self::assertNull($this->playAuthorization()->currentPlayer());
    }

    public function testAuthorizingARegisteredParticipantMakesThemTheExpectedPlayer(): void
    {
        $participant = $this->createParticipant('marc.dupont@exemple.fr');

        $this->playAuthorization(new MockClock('2026-09-24 10:00:00'))->authorize($participant);

        self::assertSame('2026-09-24 10:00:00', $participant->getPlayAuthorizedAt()?->format('Y-m-d H:i:s'));
        self::assertTrue($participant->canPlay());
        self::assertSame($participant->getId(), $this->playAuthorization()->currentPlayer()?->getId());
    }

    public function testTheLatestAuthorizationWinsAndPlayersDropOutOnceTheyHavePlayed(): void
    {
        $marc = $this->createParticipant('marc.dupont@exemple.fr');
        $claire = $this->createParticipant('claire.martin@exemple.fr');

        $this->playAuthorization(new MockClock('2026-09-24 10:00:00'))->authorize($marc);
        $this->playAuthorization(new MockClock('2026-09-24 10:01:00'))->authorize($claire);

        self::assertSame($claire->getId(), $this->playAuthorization()->currentPlayer()?->getId());

        $this->entityManager->persist(new Spin($claire, null, new \DateTimeImmutable()));
        $this->entityManager->flush();

        self::assertSame($marc->getId(), $this->playAuthorization()->currentPlayer()?->getId());
    }

    public function testAParticipantWhoAlreadyPlayedCannotBeAuthorizedAgain(): void
    {
        $participant = $this->createParticipant('marc.dupont@exemple.fr');
        $spin = new Spin($participant, null, new \DateTimeImmutable());
        $participant->getSpins()->add($spin);
        $this->entityManager->persist($spin);
        $this->entityManager->flush();

        $this->expectException(AlreadyPlayedException::class);

        $this->playAuthorization()->authorize($participant);
    }

    private function playAuthorization(?MockClock $clock = null): PlayAuthorization
    {
        return new PlayAuthorization(
            self::getContainer()->get(ParticipantRepository::class),
            $clock ?? new MockClock(),
            new NullLogger(),
        );
    }

    private function createParticipant(string $email): Participant
    {
        $participant = new Participant('Marc', 'Dupont', 'Agence Nord', $email);

        $this->entityManager->persist($participant);
        $this->entityManager->flush();

        return $participant;
    }
}
