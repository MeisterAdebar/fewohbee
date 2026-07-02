<?php
declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use App\Service\MqttPublisherService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MqttPublisherServiceTest extends TestCase
{
private function buildService(
        EntityManagerInterface $em,
        CacheItemPoolInterface $cache,
        string $mqttHost = 'mqtt.example.com',
        bool $expectPublish = false,
    ): MqttPublisherService {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $service = $this->getMockBuilder(MqttPublisherService::class)
            ->setConstructorArgs([
                $em,
                $this->createStub(LoggerInterface::class),
                $cache,
                $translator,
                $mqttHost,
                1883,
                'user',
                'pass',
                'fewohbee',
                'fewohbee/zimmer',
            ])
            ->onlyMethods(['doPublish'])
            ->getMock();

        if ($expectPublish) {
            $service->expects($this->once())->method('doPublish');
        } else {
            $service->expects($this->never())->method('doPublish');
        }

        return $service;
    }

    private function buildApartment(int $id, string $number): Appartment
    {
        $apartment = $this->createStub(Appartment::class);
        $apartment->method('getId')->willReturn($id);
        $apartment->method('getNumber')->willReturn($number);

        return $apartment;
    }

    private function buildReservation(
        Appartment $apartment,
        string $startDate,
        string $endDate,
        string $lastname = 'Mustermann',
        ?string $company = null,
    ): Reservation {
        $address = $this->createStub(CustomerAddresses::class);
        $address->method('getCompany')->willReturn($company);

        $booker = $this->createStub(Customer::class);
        $booker->method('getLastname')->willReturn($lastname);
        $booker->method('getCustomerAddresses')->willReturn(new ArrayCollection([$address]));

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getAppartment')->willReturn($apartment);
        $reservation->method('getBooker')->willReturn($booker);
        $reservation->method('getStartDate')->willReturn(new \DateTime($startDate));
        $reservation->method('getEndDate')->willReturn(new \DateTime($endDate));

        return $reservation;
    }

    /** Returns a cache mock that expects save() to never be called. */
    private function cacheWithHit(string $cachedValue): CacheItemPoolInterface
    {
        $item = $this->createStub(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($cachedValue);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->expects($this->never())->method('save');

        return $cache;
    }

    /** Returns a cache mock that expects save() to be called once. */
    private function cacheMissExpectingSave(?string &$savedPayload = null): CacheItemPoolInterface
    {
        $item = $this->createStub(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('set')->willReturnCallback(function ($value) use ($item, &$savedPayload) {
            $savedPayload = $value;
            return $item;
        });

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->expects($this->once())->method('save')->willReturn(true);

        return $cache;
    }

    public function testIsConfiguredReturnsTrueWhenHostIsSet(): void
    {
        $service = $this->buildService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(CacheItemPoolInterface::class),
            'mqtt.example.com',
        );

        self::assertTrue($service->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenHostIsEmpty(): void
    {
        $service = $this->buildService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(CacheItemPoolInterface::class),
            '',
        );

        self::assertFalse($service->isConfigured());
    }

    public function testPublishIsSkippedWhenNotConfigured(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->never())->method('getItem');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');

        $service = $this->buildService($em, $cache, '');
        $service->publishApartmentStatus($this->buildApartment(1, '2'));
    }

    public function testPublishIsSkippedWhenCacheHitMatchesPayload(): void
    {
        $apartment = $this->buildApartment(1, '2');
        $expectedPayload = json_encode(['status' => 'mqtt.status.free']);

        $repo = $this->createStub(ReservationRepository::class);
        $repo->method('loadReservationsForPeriod')->willReturn([]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $cache = $this->cacheWithHit($expectedPayload);

        $service = $this->buildService($em, $cache);
        $service->publishApartmentStatus($apartment);
    }

public function testDisplayNamePrefersCompanyOverLastname(): void
    {
        $apartment = $this->buildApartment(1, '2');
        $today = (new \DateTime('today'))->format('Y-m-d');
        $tomorrow = (new \DateTime('tomorrow'))->format('Y-m-d');

        $reservation = $this->buildReservation($apartment, $today, $tomorrow, 'Mustermann', 'Mustermann GmbH');

        $repo = $this->createStub(ReservationRepository::class);
        $repo->method('loadReservationsForPeriod')->willReturn([$reservation]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $savedPayload = null;
        $cache = $this->cacheMissExpectingSave($savedPayload);

        $service = $this->buildService($em, $cache, 'mqtt.example.com', true);
        $service->publishApartmentStatus($apartment);

        self::assertNotNull($savedPayload);
        self::assertStringContainsString('Mustermann GmbH', $savedPayload);
    }

    public function testDisplayNameFallsBackToLastnameWhenNoCompany(): void
    {
        $apartment = $this->buildApartment(1, '2');
        $today = (new \DateTime('today'))->format('Y-m-d');
        $tomorrow = (new \DateTime('tomorrow'))->format('Y-m-d');

        $reservation = $this->buildReservation($apartment, $today, $tomorrow, 'Mustermann', null);

        $repo = $this->createStub(ReservationRepository::class);
        $repo->method('loadReservationsForPeriod')->willReturn([$reservation]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $savedPayload = null;
        $cache = $this->cacheMissExpectingSave($savedPayload);

        $service = $this->buildService($em, $cache, 'mqtt.example.com', true);
        $service->publishApartmentStatus($apartment);

        self::assertNotNull($savedPayload);
        self::assertStringContainsString('Mustermann', $savedPayload);
        self::assertStringNotContainsString('GmbH', $savedPayload);
    }
}