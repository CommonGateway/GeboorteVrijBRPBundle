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

    public const SOURCES = [
        ['name'             => 'vrijbrp-dossiers', 'location' => 'https://vrijbrp.nl/dossiers', 'auth' => 'vrijbrp-jwt',
            'username'      => 'sim-!ChangeMe!', 'password' => '!secret-ChangeMe!', 'accept' => 'application/json',
            'configuration' => ['verify' => false], 'reference' => 'https://vrijbrp.nl/source/vrijbrp.dossiers.source.json'],
    ];

    /**
     * Construct an InstallationService.
     *
     * @param EntityManagerInterface $entityManager EntityManagerInterface.
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }//end __construct()

    /**
     * Install for this bundle.
     *
     * @return void This function doesn't return anything.
     */
    public function install()
    {
        $this->checkDataConsistency();
    }//end install()

    /**
     * Update for this bundle.
     *
     * @return void This function doesn't return anything.
     */
    public function update()
    {
        $this->checkDataConsistency();
    }//end update()

    /**
     * Uninstall for this bundle.
     *
     * @return void This function doesn't return anything.
     */
    public function uninstall()
    {
        // Do some cleanup.
    }//end uninstall()

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
            if ($sourceRepository->findOneBy(['reference' => $createSource['reference']]) instanceof Source === false) {
                $source = new Source($createSource);
                $source->setName($createSource['name']);
                $source->setReference($createSource['reference']);
                if (array_key_exists('password', $createSource) === true) {
                    $source->setPassword($createSource['password']);
                }
                
                $source->setHeaders(['Content-Type' => $createSource['accept']]);

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
     * @return void This function doesn't return anything.
     */
    public function checkDataConsistency()
    {
        // Create sources.
        $this->createSources($this::SOURCES);

        $this->entityManager->flush();
    }//end checkDataConsistency()
}
