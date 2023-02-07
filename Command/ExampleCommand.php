<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Command;

use CommonGateway\GeboorteVrijBRPBundle\Service\ExampleService;
use CommonGateway\GeboorteVrijBRPBundle\Service\ZdsToZgwService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to execute the FindGithubRepositoryThroughOrganizationService.
 */
class ExampleCommand extends Command
{
    protected static $defaultName = 'vrijbrp:geboorte:convert';
    private ZdsToZgwService  $componentenCatalogusService;

    public function __construct(ZdsToZgwService $componentenCatalogusService)
    {
        $this->componentenCatalogusService = $componentenCatalogusService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers CommonGateway ExampleService')
            ->setHelp('This command allows you to get all applications or one application from componentencatalogus.commonground.nl/api/products')
            ->addOption('application', 'a', InputOption::VALUE_OPTIONAL, 'Get a single application by id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->componentenCatalogusService->setStyle($io);

        // Handle the command optiosn
        $applicationId = $input->getOption('application', false);

        if (!$applicationId) {
            $this->componentenCatalogusService->getApplications();
        } elseif (!$this->componentenCatalogusService->getApplication($applicationId)) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
