<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Command;

use CommonGateway\GeboorteVrijBRPBundle\Service\ZgwToVrijbrpService;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the ZgwToVrijbrpService.
 *
 * @author Wilco Louwerse <wilco@conduction.nl>
 */
class ZgwToVrijbrpBirthCommand extends Command
{
    /**
     * @var string The name of the command (the part after "bin/console").
     */
    protected static $defaultName = 'vrijbrp:ZgwToVrijbrp:birth';

    /**
     * @var ZgwToVrijbrpService The ZgwToVrijbrpService that will be used/tested with this command.
     */
    private ZgwToVrijbrpService $zgwToVrijbrpService;

    /**
     * Construct a ZgwToVrijbrpBirthCommand.
     *
     * @param ZgwToVrijbrpService $zgwToVrijbrpService The ZgwToVrijbrpService.
     */
    public function __construct(ZgwToVrijbrpService $zgwToVrijbrpService)
    {
        $this->zgwToVrijbrpService = $zgwToVrijbrpService;
        parent::__construct();
    }//end __construct()

    /**
     * Configure this command.
     *
     * @return void This function doesn't return anything.
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers ZgwToVrijbrpService->zgwToVrijbrpHandler() for a birth e-dienst')
            ->setHelp('This command allows you to test mapping and sending a ZGW zaak to the Vrijbrp api /dossiers')
            ->addOption('zaak', 'z', InputOption::VALUE_REQUIRED, 'The zaak uuid we should test with')
            ->addOption('source', 's', InputOption::VALUE_OPTIONAL, 'The location of the Source we will send a request to, location of an existing Source object')
            ->addOption('location', 'l', InputOption::VALUE_OPTIONAL, 'The endpoint we will use on the Source to send a request, just a string')
            ->addOption('mapping', 'm', InputOption::VALUE_OPTIONAL, 'The reference of the mapping we will use before sending the data to the source')
            ->addOption('synchronizationEntity', 'se', InputOption::VALUE_OPTIONAL, 'The reference of the entity we need to create a synchronization object');
    }//end configure()

    /**
     * What happens when this command is executed.
     *
     * @param InputInterface  $input  InputInterface.
     * @param OutputInterface $output OutputInterface.
     *
     * @throws Exception
     *
     * @return int 0 for Success, 1 for Failure.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $this->zgwToVrijbrpService->setStyle($symfonyStyle);

        // Handle the command options.
        $zaakId = $input->getOption('zaak', false);
        if ($zaakId === false) {
            $symfonyStyle->error('Please use vrijbrp:ZgwToVrijbrp:birth -z {uuid of a zaak}');

            return Command::FAILURE;
        }

        $data = ['object' => ['_self' => ['id' => $zaakId]]];

        $configuration = [
            'source'                => ($input->getOption('source', false) ?? 'https://vrijbrp.nl/dossiers'),
            'location'              => ($input->getOption('location', false) ?? '/api/v1/births'),
            'mapping'               => ($input->getOption('mapping', false) ?? 'https://vrijbrp.nl/mapping/vrijbrp.ZgwToVrijbrpGeboorte.mapping.json'),
            'synchronizationEntity' => ($input->getOption('synchronizationEntity', false) ?? 'https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json'),
        ];

        if ($this->zgwToVrijbrpService->zgwToVrijbrpHandler($data, $configuration) === []) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end execute()
}
