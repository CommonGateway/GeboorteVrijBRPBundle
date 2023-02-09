<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use App\Entity\Action;
use App\Entity\Cronjob;
use App\Entity\DashboardCard;
use App\Entity\Endpoint;
use App\Entity\Entity;
use App\Entity\Gateway as Source;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The installationService for this bundle.
 *
 * @author Wilco Louwerse <wilco@conduction.nl>, Robert Zondervan <robert@conduction.nl>
 */
class InstallationService implements InstallerInterface
{
    /**
     * @var EntityManagerInterface The EntityManagerInterface.
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var ContainerInterface ContainerInterface.
     */
    private ContainerInterface $container;

    /**
     * @var SymfonyStyle SymfonyStyle for writing user feedback to console.
     */
    private SymfonyStyle $symfonyStyle;

    public const OBJECTS_WITH_CARDS = [];

    public const ENDPOINTS = [
        ['path' => 'stuf/zds', 'throws' => ['vrijbrp.zds.inbound'], 'name' => 'zds-endpoint'],
    ];

    public const SOURCES = [
        ['name'             => 'vrijbrp-dossiers', 'location' => 'https://vrijbrp.nl/dossiers', 'auth' => 'vrijbrp-jwt',
            'username'      => 'sim-!ChangeMe!', 'password' => '!secret-ChangeMe!', 'accept' => 'application/json',
            'configuration' => ['verify' => false], ],
    ];

    public const ACTION_HANDLERS = [];

    /**
     * Construct an InstallationService.
     *
     * @param EntityManagerInterface $entityManager EntityManagerInterface.
     * @param ContainerInterface     $container     ContainerInterface.
     */
    public function __construct(EntityManagerInterface $entityManager, ContainerInterface $container)
    {
        $this->entityManager = $entityManager;
        $this->container = $container;
    }//end __construct()

    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $symfonyStyle SymfonyStyle for writing user feedback to console.
     *
     * @return self This.
     */
    public function setStyle(SymfonyStyle $symfonyStyle): self
    {
        $this->symfonyStyle = $symfonyStyle;

        return $this;
    }

    /**
     * Install for this bundle.
     *
     * @return void Nothing.
     */
    public function install()
    {
        $this->checkDataConsistency();
    }//end install()

    /**
     * Update for this bundle.
     *
     * @return void Nothing.
     */
    public function update()
    {
        $this->checkDataConsistency();
    }//end update()

    /**
     * Uninstall for this bundle.
     *
     * @return void Nothing.
     */
    public function uninstall()
    {
        // Do some cleanup.
    }//end uninstall()

    /**
     * Adds configuration to an Action.
     *
     * @param mixed $actionHandler The action Handler to add configuration for.
     *
     * @return array The configuration.
     */
    public function addActionConfiguration($actionHandler): array
    {
        $defaultConfig = [];

        // What if there are no properties?
        if (isset($actionHandler->getConfiguration()['properties']) === false) {
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
                    if (key_exists('$ref', $value) === true) {
                        $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $value['$ref']]);
                        if ($entity instanceof Entity) {
                            $defaultConfig[$key] = $entity->getId()->toString();
                        }
                    }
                    break;
                default:
                    return $defaultConfig;
            }
        }

        return $defaultConfig;
    }//end addActionConfiguration()

    /**
     * This function creates actions for all the actionHandlers in OpenCatalogi.
     *
     * @return void Nothing.
     */
    public function addActions(): void
    {
        $actionHandlers = $this::ACTION_HANDLERS;
        if (isset($this->symfonyStyle) === true) {
            $this->symfonyStyle->writeln(['', '<info>Looking for actions</info>']);
        }

        foreach ($actionHandlers as $handler) {
            $actionHandler = $this->container->get($handler);

            if ($this->entityManager->getRepository('App:Action')->findOneBy(['class' => get_class($actionHandler)]) === true) {
                if (isset($this->symfonyStyle) === true) {
                    $this->symfonyStyle->writeln(['Action found for '.$handler]);
                }
                continue;
            }

            $schema = $actionHandler->getConfiguration();
            if ($schema === null) {
                continue;
            }

            $defaultConfig = $this->addActionConfiguration($actionHandler);
            $action = new Action($actionHandler);

            if ($schema['$id'] === 'https://vrijbrp.nl/vrijbrp.zds.creerzaakid.schema.json') {
                $action->setListens(['vrijbrp.zds.inbound']);
                $action->setConditions(
                    [
                        ['var' => 'SOAP-ENV:Envelope.SOAP-ENV:Body.ns2:genereerZaakIdentificatie_Di02'],
                    ]
                );
            } elseif ($schema['$id'] === 'https://vrijbrp.nl/vrijbrp.zds.creerdocumentid.schema.json') {
                $action->setListens(['vrijbrp.zds.inbound']);
                $action->setConditions([
                    ['var' => 'SOAP-ENV:Envelope.SOAP-ENV:Body.ns2:genereerDocumentIdentificatie_Di02'],
                ]);
            } elseif ($schema['$id'] === 'https://opencatalogi.nl/vrijbrp.zds.creerzaak.schema.json') {
                $action->setListens(['vrijbrp.zds.inbound']);
                $action->setConditions([
                    ['var' => 'SOAP-ENV:Envelope.SOAP-ENV:Body.ns2:zakLk01'],
                ]);
            } elseif ($schema['$id'] === 'https://opencatalogi.nl/vrijbrp.zds.creerdocument.schema.json') {
                $action->setListens(['vrijbrp.zds.inbound']);
                $action->setConditions([
                    ['var' => 'SOAP-ENV:Envelope.SOAP-ENV:Body.ns2:edcLK01'],
                ]);
            } else {
                $action->setListens(['vrijbrp.default.listens']);
            }//end if

            // Set the configuration of the action.
            $action->setConfiguration($defaultConfig);
            $action->setAsync(false);

            $this->entityManager->persist($action);

            if (isset($this->symfonyStyle) === true) {
                $this->symfonyStyle->writeln(['Action created for '.$handler]);
            }
        }
    }//end addActions()

    /**
     * Create endpoints for this bundle.
     *
     * @param array $endpoints An array of data used to create Endpoints.
     *
     * @return array Created endpoints.
     */
    private function createEndpoints(array $endpoints): array
    {
        $endpointRepository = $this->entityManager->getRepository('App:Endpoint');
        $createdEndpoints = [];
        foreach ($endpoints as $endpoint) {
            $explodedPath = explode('/', $endpoint['path']);
            if ($explodedPath[0] === '') {
                array_shift($explodedPath);
            }

            $pathRegEx = '^'.$endpoint['path'].'$';
            if ($endpointRepository->findOneBy(['pathRegex' => $pathRegEx]) === false) {
                $createdEndpoint = new Endpoint();
                $createdEndpoint->setName($endpoint['name']);
                $createdEndpoint->setPath($explodedPath);
                $createdEndpoint->setPathRegex($pathRegEx);

                $createdEndpoint->setThrows($endpoint['throws']);
                $createdEndpoints[] = $createdEndpoint;
            }
        }
        if (isset($this->symfonyStyle) === true) {
            $this->symfonyStyle->writeln(count($createdEndpoints).' Endpoints Created');
        }

        return $createdEndpoints;
    }//end createEndpoints()

    /**
     * Creates dashboard cards for the given objects.
     *
     * @param array $objectsWithCards The objects to create cards for.
     *
     * @return void Nothing.
     */
    public function createDashboardCards(array $objectsWithCards)
    {
        foreach ($objectsWithCards as $object) {
            if (isset($this->symfonyStyle) === true) {
                $this->symfonyStyle->writeln('Looking for a dashboard card for: '.$object);
            }
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $object]);
            $dashboardCard = $this->entityManager->getRepository('App:DashboardCard')->findOneBy(['entityId' => $entity->getId()]);
            if ($dashboardCard instanceof DashboardCard === false) {
                $dashboardCard = new DashboardCard();
                $dashboardCard->setType('schema');
                $dashboardCard->setEntity('App:Entity');
                $dashboardCard->setObject('App:Entity');
                $dashboardCard->setName($entity->getName());
                $dashboardCard->setDescription($entity->getDescription());
                $dashboardCard->setEntityId($entity->getId());
                $dashboardCard->setOrdering(1);
                $this->entityManager->persist($dashboardCard);
                if (isset($this->symfonyStyle) === true) {
                    $this->symfonyStyle->writeln('Dashboard card created');
                }
                continue;
            }
            if (isset($this->symfonyStyle) === true) {
                $this->symfonyStyle->writeln('Dashboard card found');
            }
        }
    }//end createDashboardCards()

    /**
     * Create cronjobs for this bundle.
     *
     * @return void Nothing.
     */
    public function createCronjobs()
    {
        if (isset($this->symfonyStyle) === true) {
            $this->symfonyStyle->writeln(['', '<info>Looking for cronjobs</info>']);
        }
        // We only need 1 cronjob so lets set that.
        $cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name' => 'Open Catalogi']);
        if ($cronjob instanceof Cronjob === false) {
            $cronjob = new Cronjob();
            $cronjob->setName('Open Catalogi');
            $cronjob->setDescription('This cronjob fires all the open catalogi actions ever 5 minutes');
            $cronjob->setThrows(['vrijbrp.default.listens']);
            $cronjob->setIsEnabled(true);

            $this->entityManager->persist($cronjob);

            if (isset($this->symfonyStyle) === true) {
                $this->symfonyStyle->writeln(['', 'Created a cronjob for '.$cronjob->getName()]);
            }
        } elseif (isset($this->symfonyStyle) === true) {
            $this->symfonyStyle->writeln(['', 'There is already a cronjob for '.$cronjob->getName()]);
        }
    }//end createCronjobs()

    /**
     * Creates the Sources we need.
     *
     * @param array $createSources Data for Sources we want to create.
     *
     * @return array The created sources.
     */
    private function createSources(array $createSources): array
    {
        $sourceRepository = $this->entityManager->getRepository('App:Gateway');
        $sources = [];
        
        foreach($createSources as $createSource) {
            if ($sourceRepository->findOneBy(['name' => $createSource['name']]) === false) {
                $source = new Source($createSource);
                if (array_key_exists('password', $createSource) === true) {
                    $source->setPassword($createSource['password']);
                }
                
                $this->entityManager->persist($source);
                $this->entityManager->flush();
                $sources[] = $source;
            }
        }

        if (isset($this->symfonyStyle) === true) {
            $this->symfonyStyle->writeln(count($sources).' Sources Created');
        }

        return $sources;
    }//end createSources()

    /**
     * Check if we need to create or update data for this bundle.
     *
     * @return void Nothing.
     */
    public function checkDataConsistency()
    {
        // Lets create some generic dashboard cards.
        $this->createDashboardCards($this::OBJECTS_WITH_CARDS);

        // Create endpoints.
        $this->createEndpoints($this::ENDPOINTS);

        // Create cronjobs.
        $this->createCronjobs();

        // Create sources.
        $this->createSources($this::SOURCES);

        // Create actions from the given actionHandlers.
        $this->addActions();

        /*@todo register this catalogi to the federation*/
        // Todo: This requires a post to a preset webhook.

        $this->entityManager->flush();
    }//end checkDataConsistency()
}
