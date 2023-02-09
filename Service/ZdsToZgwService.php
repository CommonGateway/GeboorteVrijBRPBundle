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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

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
    private ?Entity $rolTypeEntity;
    private ?Entity $documentEntity;
    private ?Entity $zaakInformatieObjectEntity;
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

    /**
     * Get the eigenschap entity.
     *
     * @return ?Entity
     */
    public function getRolTypeEntity(): ?Entity
    {
        if (!$this->rolTypeEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://vng.opencatalogi.nl/schemas/ztc.rolType.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://vng.opencatalogi.nl/schemas/ztc.rolType.schema.json');
        }

        return $this->rolTypeEntity;
    }

    public function getDocumentEntity(): ?Entity
    {
        if (!$this->documentEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://vng.opencatalogi.nl/schemas/drc.enkelvoudigInformatieObject.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://vng.opencatalogi.nl/schemas/drc.enkelvoudigInformatieObject.schema.json');
        }

        return $this->documentEntity;
    }

    public function getZaakInformatieObjectEntity(): ?Entity
    {
        if (!$this->zaakInformatieObjectEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>'https://vng.opencatalogi.nl/schemas/zrc.zaakInformatieObject.schema.json'])) {
            isset($this->io) && $this->io->error('No entity found for https://vng.opencatalogi.nl/schemas/zrc.zaakInformatieObject.schema.json');
        }

        return $this->zaakInformatieObjectEntity;
    }

    public function getMapping(string $reference): Mapping
    {
        return $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => $reference]);
    }

    public function createResponse(array $content, int $status): Response
    {
        $xmlEncoder = new XmlEncoder(['xml_root_node_name' => 'SOAP-ENV:Envelope']);
        $contentString = $xmlEncoder->encode($content, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);

        return new Response($contentString, $status);
    }

    public function zaakIdentificatieActionHandler(array $data, array $config): array
    {
        $this->getZaakEntity();

        $mapping = $this->getMapping('https://opencatalogi.nl/schemas/zds.zdsZaakIdToZgwZaak.schema.json');

        $zaakArray = $this->mappingService->mapping($mapping, $data['body']);
        $zaken = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['identificatie']], [$this->zaakEntity->getId()->toString()])['results'];
        if (!$zaken) {
            $zaak = new ObjectEntity($this->zaakEntity);
            $zaak->hydrate($zaakArray);

            $this->entityManager->persist($zaak);
            $this->entityManager->flush();

            if ($mappingOut = $this->getMapping('https://opencatalogi.nl/schemas/zds.zgwZaakToDu02.schema.json')) {
                $data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $zaak->toArray()), 200);
            }
        } else {
            $data['response'] = $this->createResponse(['Error' => 'The case with id '.$zaakArray['identificatie'].' already exists'], 400);
        }

        return $data;
    }

    public function documentIdentificatieActionHandler(array $data, array $config): array
    {
        $this->getDocumentEntity();

        $mapping = $this->getMapping('https://opencatalogi.nl/schemas/zds.zdsDocumentIdToZgwDocument.schema.json');

        $documentArray = $this->mappingService->mapping($mapping, $data['body']);
        $documents = $this->cacheService->searchObjects(null, ['identificatie' => $documentArray['identificatie']], [$this->documentEntity->getId()->toString()])['results'];
        if (!$documents) {
            $document = new ObjectEntity($this->documentEntity);
            $document->hydrate($documentArray);

            $this->entityManager->persist($document);
            $this->entityManager->flush();

            if ($mappingOut = $this->getMapping('https://opencatalogi.nl/schemas/zds.zgwDocumentToDu02.schema.json')) {
                $data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $document->toArray()), 200);
            }
        } else {
            $data['response'] = $this->createResponse(['Error' => 'The document with id '.$documentArray['identificatie'].' already exists'], 400);
        }

        return $data;
    }

    public function connectEigenschappen(array $zaakArray, ObjectEntity $zaakType): array
    {
        $this->getEigenschapEntity();
        $eigenschappenAsObjects = $zaakType->getValue('eigenschappen');
        foreach ($zaakArray['eigenschappen'] as $key => $eigenschap) {
            if ($eigenschappen = $this->cacheService->searchObjects(null, ['naam' => $eigenschap['eigenschap']['naam'], 'zaaktype' => $zaakType->getSelf()], [$this->eigenschapEntity->getId()->toString()])['results']) {
                $zaakArray['eigenschappen'][$key]['eigenschap'] = $eigenschappen[0]['_self']['id'];
                $eigenschapObject = $this->entityManager->find('App:ObjectEntity', $eigenschappen[0]['_self']['id']);
            } else {
                $eigenschapObject = new ObjectEntity($this->eigenschapEntity);
                $eigenschap['eigenschap']['zaaktype'] = $zaakType->getSelf();
                $eigenschapObject->hydrate($eigenschap['eigenschap']);

                $this->entityManager->persist($eigenschapObject);
                $this->entityManager->flush();
                $eigenschappenAsObjects[] = $zaakArray['eigenschappen'][$key]['eigenschap'] = $eigenschapObject->getId()->toString();
            }
        }
        $zaakType->hydrate(['eigenschappen' => $eigenschappenAsObjects]);

        $this->entityManager->persist($zaakType);
        $this->entityManager->flush();

        return $zaakArray;
    }

    public function connectRolTypes(array $zaakArray, ObjectEntity $zaakType): array
    {
        $this->getRolTypeEntity();
        $rolTypeObjects = $zaakType->getValue('roltypen');
        foreach ($zaakArray['rollen'] as $key => $role) {
            if ($rollen = $this->cacheService->searchObjects(null, ['omschrijvingGeneriek' => $role['roltype']['omschrijvingGeneriek'], 'zaaktype' => $zaakType->getSelf()], [$this->rolTypeEntity->getId()->toString()])['results']) {
                $zaakArray['rollen'][$key]['roltype'] = $rollen[0]['_self']['id'];
                $rolType = $this->entityManager->find('App:ObjectEntity', $rollen[0]['_self']['id']);
            } else {
                $rolType = new ObjectEntity($this->rolTypeEntity);
                $role['roltype']['zaaktype'] = $zaakType->getSelf();
                $rolType->hydrate($role['roltype']);

                $this->entityManager->persist($rolType);
                $this->entityManager->flush();

                $rolTypeObjects[] = $zaakArray['rollen'][$key]['roltype'] = $rolType->getId()->toString();
            }
        }
        $zaakType->hydrate(['roltypen' => $rolTypeObjects]);

        return $zaakArray;
    }

    public function convertZaakType(array $zaakArray): array
    {
        $this->getZaakTypeEntity();
        $zaaktypes = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['zaaktype']['identificatie']], [$this->zaakTypeEntity->getId()->toString()])['results'];
        if (count($zaaktypes) > 0) {
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
        $zaakArray = $this->connectRolTypes($zaakArray, $zaaktype);

        return $zaakArray;
    }

    public function zaakActionHandler(array $data, array $config): array
    {
        $this->getZaakEntity();
        $mapping = $this->getMapping('https://opencatalogi.nl/schemas/zds.zdsZaakToZgwZaak.schema.json');

        $zaakArray = $this->mappingService->mapping($mapping, $data['body']);

        $zaakArray = $this->convertZaakType($zaakArray);

        $zaken = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['identificatie']], [$this->zaakEntity->getId()->toString()])['results'];
        if (count($zaken) == 1) {
            $zaak = $this->entityManager->find('App:ObjectEntity', $zaken[0]['_self']['id']);
            $zaak->hydrate($zaakArray);

            $this->entityManager->persist($zaak);
            $this->entityManager->flush();

            if ($mappingOut = $this->getMapping('https://opencatalogi.nl/schemas/zds.zgwZaakToBv03.schema.json')) {
                $data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $zaak->toArray()), 200);
            }
        } elseif (count($zaken) > 1) {
            $data['response'] = $this->createResponse(['Error' => 'More than one case exists with id '.$zaakArray['identificatie']]);
        } else {
            $data['response'] = $this->createResponse(['Error' => 'The case with id '.$zaakArray['identificatie'].' does not exist']);
        }

        return $data;
    }

    public function documentActionHandler(array $data, array $config): array
    {
        $this->getZaakInformatieObjectEntity();
        $this->getDocumentEntity();
        $this->getZaakEntity();
        $mapping = $this->getMapping('https://opencatalogi.nl/schemas/zds.zdsDocumentToZgwDocument.schema.json');

        $zaakInformatieObjectArray = $this->mappingService->mapping($mapping, $data['body']);

        $documenten = $this->cacheService->searchObjects(null, ['identificatie' => $zaakInformatieObjectArray['informatieobject']['identificatie']], [$this->documentEntity->getId()->toString()])['results'];
        $zaken = $this->cacheService->searchObjects(null, ['identificatie' => $zaakInformatieObjectArray['zaak']], [$this->zaakEntity->getId()->toString()])['results'];
        if (count($documenten) == 1 && count($zaken) == 1) {
            $informatieobject = $this->entityManager->find('App:ObjectEntity', $documenten[0]['_self']['id']);
            $informatieobject->hydrate($zaakInformatieObjectArray['informatieobject']);
            $this->entityManager->persist($informatieobject);
            $this->entityManager->flush();

            $zaakInformatieObject = new ObjectEntity($this->zaakInformatieObjectEntity);
            $zaakInformatieObject->hydrate(['zaak' => $zaken[0]['_self']['id'], 'informatieobject' => $informatieobject->getId()->toString()]);

            $this->entityManager->persist($zaakInformatieObject);
            $this->entityManager->flush();

            if ($mappingOut = $this->getMapping('https://opencatalogi.nl/schemas/zds.zgwDocumentToBv03.schema.json')) {
                $data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $zaakInformatieObject->toArray()), 200);
            }
        } elseif (count($documenten) > 1) {
            $data['response'] = $this->createResponse(['Error' => 'More than one document exists with id '.$zaakInformatieObjectArray['informatieobject']['identificatie']]);
        } else {
            $data['response'] = $this->createResponse(['Error' => 'The case with id '.$zaakInformatieObjectArray['informatieobject']['identificatie'].' does not exist']);
        }

        return $data;
    }
}
