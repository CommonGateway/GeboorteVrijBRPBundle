<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 *  This class handles the interaction with componentencatalogus.commonground.nl.
 */
class ZdsToZgwService
{
    private EntityManagerInterface $entityManager;
    private CallService $callService;
    private Source $source;
    private SynchronizationService $synchronizationService;
    private ?Entity $zaakEntity;
    private ?Entity $zaakTypeEntity;
    private ?Entity $eigenschapEntity;
    private ?Mapping $applicationMapping;
    private ?Entity $componentEntity;
    private ?Mapping $componentMapping;
    private MappingService $mappingService;
    private SymfonyStyle $io;
    private CacheService $cacheService;

    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService,
        CacheService $cacheService
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->synchronizationService = $synchronizationService;
        $this->mappingService = $mappingService;
        $this->cacheService = $cacheService;
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

    /**
     * Get the componentencatalogus source.
     *
     * @return ?Source
     */
    public function getSource(): ?Source
    {
        if (!$this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location'=>'https://componentencatalogus.commonground.nl/api'])) {
            isset($this->io) && $this->io->error('No source found for https://componentencatalogus.commonground.nl/api');
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
        if (!$this->zaakEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json');
        }

        return $this->zaakEntity;
    }

    /**
     * Get the zaaktype entity.
     *
     * @return ?Entity
     */
    public function getZaakTypeEntity(): ?Entity
    {
        if (!$this->zaakTypeEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://vng.opencatalogi.nl/schemas/ztc.zaakType.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://vng.opencatalogi.nl/schemas/ztc.zaakType.schema.json');
        }

        return $this->zaakTypeEntity;
    }

    /**
     * Get the eigenschap entity.
     *
     * @return ?Entity
     */
    public function getEigenschapEntity(): ?Entity
    {
        if (!$this->eigenschapEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://vng.opencatalogi.nl/schemas/ztc.eigenschap.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://vng.opencatalogi.nl/schemas/ztc.eigenschap.schema.json');
        }

        return $this->eigenschapEntity;
    }

    public function getMapping(string $reference): Mapping
    {
        return $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => $reference]);
    }

    public function zaakIdentificatieActionHandler (array $data, array $config): array
    {
        $this->getZaakEntity();

        $mapping = $this->getMapping('https://opencatalogi.nl/schemas/zds.zdsZaakIdToZgwZaak.schema.json');

        $zaakArray = $this->mappingService->mapping($mapping, $data);
        $zaken = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['identificatie']], [$this->zaakEntity->getId()->toString()])['results'];
        if (!$zaken) {
            $zaak = new ObjectEntity($this->zaakEntity);
            $zaak->hydrate($zaakArray);
        } else {
            $zaak = $zaken[0];
        }

        $this->entityManager->persist($zaak);
        $this->entityManager->flush();

        return $zaak->toArray();
    }

    public function connectEigenschappen(array $zaakArray, ObjectEntity $zaakType): array
    {
        $this->getEigenschapEntity();
        $eigenschappenAsObjects = $zaakType->getValue('eigenschappen');
        foreach($zaakArray['eigenschappen'] as $key => $eigenschap) {
            if($eigenschappen = $this->cacheService->searchObjects(null, ['naam' => $eigenschap['eigenschap']['naam'], 'zaaktype' => $zaakType->getSelf()], [$this->eigenschapEntity->getId()->toString()])['results']) {
                $zaakArray['eigenschappen'][$key]['eigenschap'] = $eigenschappen[0]['_self']['id'];
                $eigenschap = $this->entityManager->find('App:ObjectEntity', $eigenschappen[0]['_self']['id']);
            } else {
                $eigenschapObject = new ObjectEntity($this->eigenschapEntity);
                $eigenschap['eigenschap']['zaaktype'] = $zaakType->getSelf();
                $eigenschapObject->hydrate($eigenschap['eigenschap']);

                $this->entityManager->persist($eigenschapObject);
                $this->entityManager->flush();
                var_dump($eigenschapObject->getId()->toString());
                $eigenschappenAsObjects[] = $zaakArray['eigenschappen'][$key]['eigenschap'] = $eigenschapObject->getId()->toString();
            }
        }
        var_dump($eigenschappenAsObjects);
        $zaakType->setValue('eigenschappen', $eigenschappenAsObjects);

        var_dump($zaakType->getValue('eigenschappen'));


        $this->entityManager->persist($zaakType);
        $this->entityManager->flush();
        return $zaakArray;
    }

    public function convertZaakType(array $zaakArray): array
    {
        $this->getZaakTypeEntity();
        $zaaktypes = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['zaaktype']['identificatie']], [$this->zaakTypeEntity->getId()->toString()])['results'];
        if(count($zaaktypes) > 0) {
            $zaaktype = $zaaktypes[0];
            $zaaktype = $this->entityManager->find('App:ObjectEntity', $zaaktypes[0]['_self']['id']);
            $zaakArray['zaaktype'] = $zaaktype->getId()->toString();
        } else {
            $zaaktype = new ObjectEntity($this->zaakTypeEntity);
            $zaaktype->hydrate($zaakArray['zaaktype']);

            $this->entityManager->persist($zaaktype);
            $this->entityManager->flush();

            $zaakArray['zaaktype'] = $zaaktype->getId()->toString();
        }

        $zaakArray = $this->connectEigenschappen($zaakArray, $zaaktype);

        return $zaakArray;
    }

    public function zaakActionHandler(array $data, array $config): array
    {
        $this->getZaakEntity();
        $mapping = $this->getMapping('https://opencatalogi.nl/schemas/zds.zdsZaakToZgwZaak.schema.json');

        $zaakArray = $this->mappingService->mapping($mapping, $data);

        $zaakArray = $this->convertZaakType($zaakArray);

        var_dump($zaakArray);
        $zaken = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['identificatie']], [$this->zaakEntity->getId()->toString()])['results'];
        if (count($zaken) == 1) {
            $zaak = $this->entityManager->find('App:ObjectEntity', $zaken[0]['_self']['id']);
            $zaak->hydrate($zaakArray);
            $this->entityManager->persist($zaak);
            $this->entityManager->flush();
            return $zaak->toArray();
        } elseif (count($zaken) > 1) {
            var_dump('more than one case with identifier '.$zaakArray['identificatie']);
        } else {
            var_dump('no case found with identifier '.$zaakArray['identificatie']);
        }

        return $data;
    }
}
