<?php
declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Appartment;
use App\Entity\CustomerAddresses;
use App\Entity\Reservation;
use App\Service\MqttPublisherService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
class ReservationMqttListener
{
    /** @var array<int, Appartment> */
    private array $pendingApartments = [];

    public function __construct(
        private readonly MqttPublisherService $mqttPublisher,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->collect($args->getObject());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (empty($this->pendingApartments)) {
            return;
        }

        if (!$this->mqttPublisher->isConfigured()) {
            $this->pendingApartments = [];
            return;
        }

        foreach ($this->pendingApartments as $apartment) {
            $this->mqttPublisher->publishApartmentStatus($apartment);
        }

        $this->pendingApartments = [];
    }

    private function collect(object $entity): void
    {
        if ($entity instanceof Reservation) {
            $apartment = $entity->getAppartment();
            if (null !== $apartment) {
                $this->pendingApartments[$apartment->getId()] = $apartment;
            }
            return;
        }

        if ($entity instanceof CustomerAddresses) {
            foreach ($entity->getCustomers() as $customer) {
                foreach ($customer->getBookedReservations() as $reservation) {
                    $apartment = $reservation->getAppartment();
                    if (null !== $apartment) {
                        $this->pendingApartments[$apartment->getId()] = $apartment;
                    }
                }
            }
        }
    }
}

