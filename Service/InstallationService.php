<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use App\Entity\Action;
use App\Entity\Cronjob;
use App\Entity\DashboardCard;
use App\Entity\Endpoint;
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
        ['path' => 'stuf/zds', 'throws' => ['zds.inbound'], 'name' => 'zds-endpoint', 'methods' => []],
    ];

    public const SOURCES = [
        ['name'             => 'vrijbrp-dossiers', 'location' => 'https://vrijbrp.nl/dossiers', 'auth' => 'vrijbrp-jwt',
            'username'      => 'sim-!ChangeMe!', 'password' => '!secret-ChangeMe!', 'accept' => 'application/json',
            'configuration' => ['verify' => false], ],
    ];

    public const ACTION_HANDLERS = [
        'CommonGateway\GeboorteVrijBRPBundle\ActionHandler\ZaakIdentificatieActionHandler',
        'CommonGateway\GeboorteVrijBRPBundle\ActionHandler\DocumentIdentificatieActionHandler',
        'CommonGateway\GeboorteVrijBRPBundle\ActionHandler\ZdsZaakActionHandler',
        'CommonGateway\GeboorteVrijBRPBundle\ActionHandler\ZdsDocumentActionHandler',
    ];

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


            $schema = $actionHandler->getConfiguration();
            if ($schema === null) {
                continue;
            }
            if ($this->entityManager->getRepository('App:Action')->findOneBy(['reference' => $schema['$id']]) instanceof Action === true) {
                if (isset($this->symfonyStyle) === true) {
                    $this->symfonyStyle->writeln(['Action found for '.$handler]);
                }
                continue;
            }

            $defaultConfig = $this->addActionConfiguration($actionHandler);
            $action = new Action($actionHandler);

            $action->setReference($schema['$id']);
            if ($schema['$id'] === 'https://zds.nl/zds.creerzaakid.handler.json') {
                $action->setListens(['zds.inbound']);
                $action->setConditions([
                    'var' => 'body.SOAP-ENV:Body.ns2:genereerZaakIdentificatie_Di02',
                ]);
            } elseif ($schema['$id'] == 'https://zds.nl/zds.creerdocumentid.handler.json') {
                $action->setListens(['zds.inbound']);
                $action->setConditions([
                    'var' => 'body.SOAP-ENV:Body.ns2:genereerDocumentIdentificatie_Di02',
                ]);
            } elseif ($schema['$id'] === 'https://zds.nl/zds.creerzaak.handler.json') {
                $action->setListens(['zds.inbound']);
                $action->setThrows(['vrijbrp.zaak.created']);
                $action->setConditions([
                    'var' => 'body.SOAP-ENV:Body.ns2:zakLk01',
                ]);
            } elseif ($schema['$id'] === 'https://zds.nl/zds.creerdocument.handler.json') {
                $action->setListens(['zds.inbound']);
                $action->setThrows(['vrijbrp.zaak.document.created']);
                $action->setConditions([
                    'var' => 'body.SOAP-ENV:Body.ns2:edcLk01',
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
            if ($endpointRepository->findOneBy(['pathRegex' => $pathRegEx]) instanceof Endpoint === false) {
                $createdEndpoint = new Endpoint();
                $createdEndpoint->setName($endpoint['name']);
                $createdEndpoint->setPath($explodedPath);
                $createdEndpoint->setPathRegex($pathRegEx);
                $createdEndpoint->setMethods(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
                $createdEndpoint->setThrows($endpoint['throws']);
                $createdEndpoint->getDefaultContentType('text/xml');
                $createdEndpoints[] = $createdEndpoint;

                $this->entityManager->persist($createdEndpoint);
                $this->entityManager->flush();
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
        $cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name' => 'VrijBRP']);
        if ($cronjob instanceof Cronjob === false) {
            $cronjob = new Cronjob();
            $cronjob->setName('VrijBRP');
            $cronjob->setDescription('This cronjob fires all the VrijBRP actions ever 5 minutes');
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

        foreach ($createSources as $createSource) {
            if ($sourceRepository->findOneBy(['name' => $createSource['name']]) instanceof Source === false) {
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

        $this->entityManager->flush();
    }//end checkDataConsistency()
}
