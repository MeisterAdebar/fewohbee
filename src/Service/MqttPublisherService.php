<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\MqttClient;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class MqttPublisherService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache,
        private readonly TranslatorInterface $translator,
        private readonly string $mqttHost,
        private readonly int $mqttPort,
        private readonly string $mqttUsername,
        private readonly string $mqttPassword,
        private readonly string $mqttClientId,
        private readonly string $mqttTopicPrefix,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->mqttHost;
    }

    public function publishApartmentStatusAll(): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $apartments = $this->em->getRepository(Appartment::class)->findAll();
        foreach ($apartments as $apartment) {
            $this->publishApartmentStatus($apartment);
        }
    }

    public function publishApartmentStatus(Appartment $apartment): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $today = new \DateTime('today');
        $todayStr = $today->format('Y-m-d');

        $reservations = $this->em->getRepository(Reservation::class)
            ->loadReservationsForPeriod($todayStr, $todayStr);

        $arriving = null;
        $departing = null;
        $staying = null;

        foreach ($reservations as $reservation) {
            if ($reservation->getAppartment()?->getId() !== $apartment->getId()) {
                continue;
            }

            $start = $reservation->getStartDate()->format('Y-m-d');
            $end = $reservation->getEndDate()->format('Y-m-d');

            if ($start === $todayStr) {
                $arriving = $reservation;
            } elseif ($end === $todayStr) {
                $departing = $reservation;
            } else {
                $staying = $reservation;
            }
        }

        if ($arriving && $departing) {
            $payload = json_encode([
                'status' => $this->translator->trans('mqtt.status.changeover', domain: 'Mqtt'),
                'departing' => [
                    'name'    => $departing->getBooker()->getFirstName() . ' ' . $departing->getBooker()->getLastName(),
                    'endDate' => $departing->getEndDate()->format('Y-m-d'),
                ],
                'arriving' => [
                    'name'      => $arriving->getBooker()->getFirstName() . ' ' . $arriving->getBooker()->getLastName(),
                    'startDate' => $arriving->getStartDate()->format('Y-m-d'),
                    'endDate'   => $arriving->getEndDate()->format('Y-m-d'),
                ],
            ]);
        } elseif ($arriving) {
            $payload = json_encode([
                'status'    => $this->translator->trans('mqtt.status.arrival', domain: 'Mqtt'),
                'name'      => $arriving->getBooker()->getFirstName() . ' ' . $arriving->getBooker()->getLastName(),
                'startDate' => $arriving->getStartDate()->format('Y-m-d'),
                'endDate'   => $arriving->getEndDate()->format('Y-m-d'),
            ]);
        } elseif ($staying) {
            $payload = json_encode([
                'status'    => $this->translator->trans('mqtt.status.occupied', domain: 'Mqtt'),
                'name'      => $staying->getBooker()->getFirstName() . ' ' . $staying->getBooker()->getLastName(),
                'startDate' => $staying->getStartDate()->format('Y-m-d'),
                'endDate'   => $staying->getEndDate()->format('Y-m-d'),
            ]);
        } elseif ($departing) {
            $payload = json_encode([
                'status'  => $this->translator->trans('mqtt.status.departure', domain: 'Mqtt'),
                'name'    => $departing->getBooker()->getFirstName() . ' ' . $departing->getBooker()->getLastName(),
                'endDate' => $departing->getEndDate()->format('Y-m-d'),
            ]);
        } else {
            $payload = json_encode([
                'status' => $this->translator->trans('mqtt.status.free', domain: 'Mqtt'),
            ]);
        }

        $cacheKey = 'mqtt_zimmer_' . $apartment->getNumber();
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit() && $cacheItem->get() === $payload) {
            return;
        }

        $cacheItem->set($payload);
        $this->cache->save($cacheItem);

        try {
            $mqtt = new MqttClient($this->mqttHost, $this->mqttPort, $this->mqttClientId);
            $settings = (new ConnectionSettings())
                ->setUsername($this->mqttUsername)
                ->setPassword($this->mqttPassword);

            $mqtt->connect($settings, true);

            $topic = sprintf('%s/%s', $this->mqttTopicPrefix, $apartment->getNumber());
            $mqtt->publish($topic, $payload, 0, true);
            $this->logger->info("Published changed status for room {$apartment->getNumber()}");

            $mqtt->disconnect();
        } catch (MqttClientException $e) {
            $this->logger->error('MQTT publish failed: ' . $e->getMessage());
        }
    }
}
