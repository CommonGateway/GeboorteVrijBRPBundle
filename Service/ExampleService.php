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
class ExampleService
{
    private EntityManagerInterface $entityManager;
    private CallService $callService;
    private Source $source;
    private SynchronizationService $synchronizationService;
    private ?Entity $zaakEntity;
    private ?Mapping $applicationMapping;
    private ?Entity $componentEntity;
    private ?Mapping $componentMapping;
    private MappingService $mappingService;
    private SymfonyStyle $symfonyStyle;

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
     * @param SymfonyStyle $symfonyStyle
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $symfonyStyle): self
    {
        $this->symfonyStyle = $symfonyStyle;
        $this->synchronizationService->setStyle($symfonyStyle);
        $this->mappingService->setStyle($symfonyStyle);

        return $this;
    }

    /**
     * Get the componentencatalogus source.
     *
     * @return ?Source
     */
    public function getSource(): ?Source
    {
        if (!$this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location'=>'https://componentencatalogus.commonground.nl/api'])) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('No source found for https://componentencatalogus.commonground.nl/api');
        }

        return $this->source;
    }

    /**
     * Get the application entity.
     *
     * @return ?Entity
     */
    public function getZaakEntity(): ?Entity
    {
        if (!$this->zaakEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://vng.opencatalogi.nl/zrc.zaak.schema.json'])) {
            isset($this->symfonyStyle) && $this->symfonyStyle->error('No entity found for https://vng.opencatalogi.nl/zrc.zaak.schema.json');
        }

        return $this->zaakEntity;
    }

    public function zaakIdentificatieActionHandler (array $data, array $config): array
    {
        $this->getZaakEntity();

        if($data['']) {
            $zaakArray = $this->mappingService->map();
            $zaak = new ObjectEntity($this->zaakEntity);
            $zaak->hydrate($zaakArray);
        }

        return $data;
    }
}
