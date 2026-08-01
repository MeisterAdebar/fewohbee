<?php

declare(strict_types=1);

namespace App\Workflow\Condition;

use App\Entity\Invoice;

class InvoiceReservationOriginCondition implements WorkflowConditionInterface
{
    public function getType(): string
    {
        return 'invoice.reservation_origin_is';
    }

    public function getLabelKey(): string
    {
        return 'workflow.condition.invoice_reservation_origin_is';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Invoice::class];
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key'   => 'originId',
                'type'  => 'reservation_origin_select',
                'label' => 'workflow.condition.invoice_reservation_origin_is',
            ],
        ];
    }

    /**
     * Matches as soon as ANY reservation on the invoice has the configured
     * origin - an invoice can carry several reservations, and they need not
     * share an origin. Actions run on the invoice as a whole afterwards, so
     * this cannot be used to single out the matching reservation.
     */
    public function evaluate(array $config, mixed $entity, array $context): bool
    {
        if (!$entity instanceof Invoice) {
            return false;
        }

        $expectedId = (int) ($config['originId'] ?? -1);

        foreach ($entity->getReservations() as $reservation) {
            $origin = $reservation->getReservationOrigin();
            if (null !== $origin && $origin->getId() === $expectedId) {
                return true;
            }
        }

        return false;
    }
}
