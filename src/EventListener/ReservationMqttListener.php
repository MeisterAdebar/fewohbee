<?php
declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Reservation;
use App\Service\MqttPublisherService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
class ReservationMqttListener
{
    public function __construct(
        private readonly MqttPublisherService $mqttPublisher,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->handle($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->handle($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->handle($args->getObject());
    }

    private function handle(object $entity): void
    {
        if (!$entity instanceof Reservation) {
            return;
        }

        if (!$this->mqttPublisher->isConfigured()) {
            return;
        }

        $apartment = $entity->getAppartment();
        if (null === $apartment) {
            return;
        }

        $this->mqttPublisher->publishApartmentStatus($apartment);
    }
}
