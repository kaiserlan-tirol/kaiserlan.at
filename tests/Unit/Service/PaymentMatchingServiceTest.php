<?php

namespace App\Tests\Unit\Service;

use App\Entity\IncomingPayment;
use App\Entity\Ticket;
use App\Entity\User;
use App\Idm\Collection;
use App\Idm\IdmRepository;
use App\Repository\TicketRepository;
use App\Service\PaymentMatchingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class PaymentMatchingServiceTest extends TestCase
{
    /**
     * The "gamertag, Catering" reference is prescribed by us, so the account it
     * names must win over an account that only matches the payer's real name.
     */
    public function testReferenceNicknameOutranksPlainNameMatch(): void
    {
        $staleAccount = $this->user('Christopher', 'Mössner', null);
        $realAccount = $this->user('Christopher', 'Mössner', 'Pyroflux');

        $payment = $this->payment('Christopher Mössner', 'Pyroflux, Catering');

        $service = $this->buildService([$staleAccount, $realAccount], []);

        $this->assertTrue($service->autoMatchPayment($payment));
        $this->assertSame(
            $realAccount->getUuid()->toString(),
            $payment->getMatchedUser()->toString()
        );
    }

    /**
     * Without a reference both accounts of the same person score identically.
     * The one holding a ticket is the one that was actually at the LAN.
     */
    public function testTicketBreaksTieBetweenEqualNameMatches(): void
    {
        $staleAccount = $this->user('Christian', 'Kogler', null);
        $realAccount = $this->user('Christian', 'Kogler', null);

        $payment = $this->payment('Christian Kogler', null);

        $service = $this->buildService(
            [$staleAccount, $realAccount],
            [$realAccount->getUuid()->toString()]
        );

        $this->assertTrue($service->autoMatchPayment($payment));
        $this->assertSame(
            $realAccount->getUuid()->toString(),
            $payment->getMatchedUser()->toString()
        );
    }

    private function user(string $first, string $surname, ?string $nickname): User
    {
        $user = new User();
        $user->setUuid(Uuid::uuid4())
            ->setFirstname($first)
            ->setSurname($surname)
            ->setNickname($nickname);

        return $user;
    }

    private function payment(string $payerName, ?string $reference): IncomingPayment
    {
        $payment = new IncomingPayment();
        $payment->setSource(IncomingPayment::SOURCE_PAYPAL)
            ->setPayerName($payerName)
            ->setReference($reference);

        return $payment;
    }

    /**
     * IdmManager is final and cannot be mocked, so the service is built without
     * its constructor and the collaborators are injected directly.
     *
     * @param User[]   $users
     * @param string[] $uuidsWithTicket
     */
    private function buildService(array $users, array $uuidsWithTicket): PaymentMatchingService
    {
        $userRepo = $this->createMock(IdmRepository::class);
        $userRepo->method('findAll')->willReturn($this->collection($users));

        $ticketRepository = $this->createMock(TicketRepository::class);
        $ticketRepository->method('findOneByRedeemer')->willReturnCallback(
            fn(UuidInterface $uuid) => in_array($uuid->toString(), $uuidsWithTicket, true)
                ? new Ticket()
                : null
        );

        $reflection = new \ReflectionClass(PaymentMatchingService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'userRepo' => $userRepo,
            'ticketRepository' => $ticketRepository,
            'logger' => new NullLogger(),
        ] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($service, $value);
        }

        return $service;
    }

    /**
     * IdmRepository::findAll() returns an App\Idm\Collection; the service only
     * iterates it, so a thin array-backed implementation is enough here.
     *
     * @param User[] $items
     */
    private function collection(array $items): Collection
    {
        return new class(array_values($items)) implements Collection {
            private int $position = 0;

            public function __construct(private readonly array $items)
            {
            }

            public function get($offset): mixed
            {
                return $this->items[$offset] ?? null;
            }

            public function isEmpty(): bool
            {
                return $this->items === [];
            }

            public function current(): mixed
            {
                return $this->items[$this->position];
            }

            public function key(): mixed
            {
                return $this->position;
            }

            public function next(): void
            {
                $this->position++;
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function valid(): bool
            {
                return isset($this->items[$this->position]);
            }

            public function count(): int
            {
                return count($this->items);
            }

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->items[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->items[$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                throw new \LogicException('read-only');
            }

            public function offsetUnset(mixed $offset): void
            {
                throw new \LogicException('read-only');
            }
        };
    }
}
