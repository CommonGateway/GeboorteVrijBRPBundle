<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 *  This class handles the interaction with componentencatalogus.commonground.nl.
 */
class ZgwToVrijbrpService
{
    private EntityManagerInterface $entityManager;
    private CallService $callService;
    private SynchronizationService $synchronizationService;
    private MappingService $mappingService;
    private SymfonyStyle $io;
    private Source $source;
    private Mapping $mapping;

    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->synchronizationService = $synchronizationService;
        $this->mappingService = $mappingService;
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
        $this->synchronizationService->setStyle($io);
        $this->mappingService->setStyle($io);

        return $this;
    }

    public function zaakIdentificatieActionHandler (array $data, array $config): array
    {
        // todo: find source by name
        // todo: find mapping by reference

        return $data;
    }
}
