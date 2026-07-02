<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\MqttPublisherService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mqtt:publish:status',
    description: 'Publishes the current occupancy status of all apartments to MQTT.',
)]
class MqttPublishStatusCommand extends Command
{
    /** Provide dependencies for MQTT status publishing. */
    public function __construct(
        private readonly MqttPublisherService $mqttPublisherService,
    ) {
        parent::__construct();
    }

    /** Publish current occupancy status for all apartments to MQTT broker. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->mqttPublisherService->isConfigured()) {
            $io->warning('MQTT is not configured (MQTT_HOST is empty). Skipping.');
            return Command::SUCCESS;
        }

        $this->mqttPublisherService->publishApartmentStatusAll();
        $io->success('Apartment status published to MQTT.');

        return Command::SUCCESS;
    }
}
