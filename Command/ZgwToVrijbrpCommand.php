<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Command;

use CommonGateway\GeboorteVrijBRPBundle\Service\ZgwToVrijbrpService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the FindGithubRepositoryThroughOrganizationService.
 */
class ZgwToVrijbrpCommand extends Command
{
    protected static $defaultName = 'vrijbrp:ZgwToVrijbrp';
    private ZgwToVrijbrpService  $zgwToVrijbrpService;

    public function __construct(ZgwToVrijbrpService $zgwToVrijbrpService)
    {
        $this->zgwToVrijbrpService = $zgwToVrijbrpService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers ZgwToVrijbrpService->zgwToVrijbrpHandler()')
            ->setHelp('This command allows you to test mapping and sending a ZGW zaak to the Vrijbrp api /dossiers')
            ->addOption('zaak', 'z', InputOption::VALUE_REQUIRED, 'The zaak uuid we should test with')
            ->addOption('source', 's', InputOption::VALUE_OPTIONAL, 'The location of the Source we will send a request to, location of an existing Source object')
            ->addOption('location', 'l', InputOption::VALUE_OPTIONAL, 'The endpoint we will use on the Source to send a request, just a string')
            ->addOption('mapping', 'm', InputOption::VALUE_OPTIONAL, 'The reference of the mapping we will use before sending the data to the source')
            ->addOption('conditionEntity', 'ce', InputOption::VALUE_OPTIONAL, 'The reference of the entity we use as trigger for this handler, we need this to find a synchronization object');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->zgwToVrijbrpService->setStyle($io);

        // Handle the command options
        $zaakId = $input->getOption('zaak', false);
        $data = ['id' => $zaakId];
        
        $configuration = [
            'source' => $input->getOption('source', false) ?? 'https://vrijbrp.nl/dossiers',
            'location' => $input->getOption('location', false) ?? '/api/births',
            'mapping' => $input->getOption('mapping', false) ?? 'https://vrijbrp.nl/mapping/vrijbrp.ZgwToVrijbrp.mapping.json',
            'conditionEntity' => $input->getOption('conditionEntity', false) ?? 'https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json',
        ];

        if (!$this->zgwToVrijbrpService->zgwToVrijbrpHandler($data, $configuration)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
