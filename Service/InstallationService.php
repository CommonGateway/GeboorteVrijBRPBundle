<?php

// src/Service/LarpingService.php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use App\Entity\Action;
use App\Entity\CollectionEntity;
use App\Entity\Cronjob;
use App\Entity\DashboardCard;
use App\Entity\Endpoint;
use App\Entity\Entity;
use App\Entity\Gateway as Source;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OpenCatalogi\OpenCatalogiBundle\Service\CatalogiService;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

class InstallationService implements InstallerInterface
{
    private EntityManagerInterface $entityManager;
    private ContainerInterface $container;
    private SymfonyStyle $io;

    public const OBJECTS_THAT_SHOULD_HAVE_CARDS = [
    ];

    public const ENDPOINTS = [
        ['path' => 'stuf/zds', 'throws' => ['vrijbrp.zds.inbound'], 'name' => 'zds-endpoint']
    ];

    public const ACTION_HANDLERS = [
    ];

    public function __construct(EntityManagerInterface $entityManager, ContainerInterface $container)
    {
        $this->entityManager = $entityManager;
        $this->container = $container;
    }

    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $io
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $io): self
    {
        $this->io = $io;

        return $this;
    }

    public function install()
    {
        $this->checkDataConsistency();
    }

    public function update()
    {
        $this->checkDataConsistency();
    }

    public function uninstall()
    {
        // Do some cleanup
    }

    public function addActionConfiguration($actionHandler): array
    {
        $defaultConfig = [];

        // What if there are no properties?
        if (!isset($actionHandler->getConfiguration()['properties'])) {
            return $defaultConfig;
        }

        foreach ($actionHandler->getConfiguration()['properties'] as $key => $value) {
            switch ($value['type']) {
                case 'string':
                case 'array':
                    $defaultConfig[$key] = $value['example'];
                    break;
                case 'object':
                    break;
                case 'uuid':
                    if (key_exists('$ref', $value)) {
                        if ($entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=> $value['$ref']])) {
                            $defaultConfig[$key] = $entity->getId()->toString();
                        }
                    }
                    break;
                default:
                    return $defaultConfig;
            }
        }

        return $defaultConfig;
    }

    /**
     * This function creates actions for all the actionHandlers in OpenCatalogi.
     *
     * @return void
     */
    public function addActions(): void
    {
        $sourceRepository = $this->entityManager->getRepository('App:Gateway');

        $actionHandlers = $this::ACTION_HANDLERS;
        (isset($this->io) ? $this->io->writeln(['', '<info>Looking for actions</info>']) : '');

        foreach ($actionHandlers as $handler) {
            $actionHandler = $this->container->get($handler);

            if ($this->entityManager->getRepository('App:Action')->findOneBy(['class' => get_class($actionHandler)])) {
                (isset($this->io) ? $this->io->writeln(['Action found for '.$handler]) : '');
                continue;
            }

            if (!$schema = $actionHandler->getConfiguration()) {
                continue;
            }

            $defaultConfig = $this->addActionConfiguration($actionHandler);
            $action = new Action($actionHandler);

            if ($schema['$id'] == 'https://vrijbrp.nl/vrijbrp.zds.creerzaakid.schema.json') {
                $action->setListens(['vrijbrp.zds.inbound']);
                $action->setConditions([
                    ['var' => 'SOAP-ENV:Envelope.SOAP-ENV:Body.ns2:genereerZaakIdentificatie_Di02'],
                ]);
            } elseif ($schema['$id'] == 'https://vrijbrp.nl/vrijbrp.zds.creerdocumentid.schema.json') {
                $action->setListens(['vrijbrp.zds.inbound']);
                $action->setConditions([
                    ['var' => 'SOAP-ENV:Envelope.SOAP-ENV:Body.ns2:genereerDocumentIdentificatie_Di02'],
                ]);
            } elseif ($schema['$id'] == 'https://opencatalogi.nl/vrijbrp.zds.creerzaak.schema.json') {
                $action->setListens(['vrijbrp.zds.inbound']);
                $action->setConditions([
                    ['var' => 'SOAP-ENV:Envelope.SOAP-ENV:Body.ns2:zakLk01'],
                ]);
            } elseif ($schema['$id'] == 'https://opencatalogi.nl/vrijbrp.zds.creerdocument.schema.json') {
                $action->setListens(['vrijbrp.zds.inbound']);
                $action->setConditions([
                    ['var' => 'SOAP-ENV:Envelope.SOAP-ENV:Body.ns2:edcLK01'],
                ]);
            } else {
                $action->setListens(['vrijbrp.default.listens']);
            }

            // set the configuration of the action
            $action->setConfiguration($defaultConfig);
            $action->setAsync(false);

            $this->entityManager->persist($action);

            (isset($this->io) ? $this->io->writeln(['Action created for '.$handler]) : '');
        }
    }

    private function createEndpoints(array $endpoints): array
    {
        $endpointRepository = $this->entityManager->getRepository('App:Endpoint');
        $createdEndpoints = [];
        foreach ($endpoints as $endpoint) {
            $explodedPath = explode('/', $endpoint['path']);
            if ($explodedPath[0] == '') {
                array_shift($explodedPath);
            }
            $pathRegEx = '^' . $endpoint['path'] . '$';
            if (!$endpointRepository->findOneBy(['pathRegex' => $pathRegEx])) {
                $createdEndpoint = new Endpoint();
                $createdEndpoint->setName($endpoint['name']);
                $createdEndpoint->setPath($explodedPath);
                $createdEndpoint->setPathRegex($pathRegEx);

                $createdEndpoint->setThrows($endpoint['throws']);
                $createdEndpoints[] = $createdEndpoint;
            }
        }
        (isset($this->io) ? $this->io->writeln(count($createdEndpoints).' Endpoints Created') : '');

        return $createdEndpoints;
    }

    public function createDashboardCards($objectsThatShouldHaveCards)
    {
        foreach ($objectsThatShouldHaveCards as $object) {
            (isset($this->io) ? $this->io->writeln('Looking for a dashboard card for: '.$object) : '');
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $object]);
            if (
                !$dashboardCard = $this->entityManager->getRepository('App:DashboardCard')->findOneBy(['entityId' => $entity->getId()])
            ) {
                $dashboardCard = new DashboardCard();
                $dashboardCard->setType('schema');
                $dashboardCard->setEntity('App:Entity');
                $dashboardCard->setObject('App:Entity');
                $dashboardCard->setName($entity->getName());
                $dashboardCard->setDescription($entity->getDescription());
                $dashboardCard->setEntityId($entity->getId());
                $dashboardCard->setOrdering(1);
                $this->entityManager->persist($dashboardCard);
                (isset($this->io) ? $this->io->writeln('Dashboard card created') : '');
                continue;
            }
            (isset($this->io) ? $this->io->writeln('Dashboard card found') : '');
        }
    }

    public function createCronjobs()
    {
        (isset($this->io) ? $this->io->writeln(['', '<info>Looking for cronjobs</info>']) : '');
        // We only need 1 cronjob so lets set that
        if (!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name' => 'Open Catalogi'])) {
            $cronjob = new Cronjob();
            $cronjob->setName('Open Catalogi');
            $cronjob->setDescription('This cronjob fires all the open catalogi actions ever 5 minutes');
            $cronjob->setThrows(['vrijbrp.default.listens']);
            $cronjob->setIsEnabled(true);

            $this->entityManager->persist($cronjob);

            (isset($this->io) ? $this->io->writeln(['', 'Created a cronjob for '.$cronjob->getName()]) : '');
        } else {
            (isset($this->io) ? $this->io->writeln(['', 'There is alreade a cronjob for '.$cronjob->getName()]) : '');
        }
    }

    public function createSources()
    {
        $sourceRepository = $this->entityManager->getRepository('App:Gateway');

        // vrijbrp dossiers api
        $vrijbrpDossiers = $sourceRepository->findOneBy(['name' => 'vrijbrp-dossiers']) ?? new Source();
        $vrijbrpDossiers->setName('vrijbrp-dossiers');
        $vrijbrpDossiers->setAuth('vrijbrp-jwt');
        $vrijbrpDossiers->setLocation('https://vrijbrp.nl/dossiers');
        $vrijbrpDossiers->setIsEnabled(true);
        $this->entityManager->persist($vrijbrpDossiers);
        isset($this->io) && $this->io->writeln('Gateway: '.$vrijbrpDossiers->getName().' created');

    }

    public function checkDataConsistency()
    {
        // Lets create some generic dashboard cards
        $this->createDashboardCards($this::OBJECTS_THAT_SHOULD_HAVE_CARDS);

        // cretae endpoints
        $this->createEndpoints($this::ENDPOINTS);

        // create cronjobs
        $this->createCronjobs();

        // create sources
        $this->createSources();

        // create actions from the given actionHandlers
        $this->addActions();

        /*@todo register this catalogi to the federation*/
        // This requers a post to a pre set webhook

        $this->entityManager->flush();
    }
}
