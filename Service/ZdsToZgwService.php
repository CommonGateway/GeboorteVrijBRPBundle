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
    }//end __construct()

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
    }//end setStyle()

    /**
     * Get an entity by reference
     *
     * @param string $reference The reference to look for
     * @return Entity|null
     */
    public function getEntity(string $reference): ?Entity
    {
        $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$reference]);
        if ($entity === null) {
            isset($this->io) && $this->io->error("No entity found for $reference");
        }//end if

        return $entity;
    }//end getEntity()

    /**
     * Gets mapping for reference
     *
     * @param string $reference The reference to look for
     * @return Mapping
     */
    public function getMapping (string $reference): Mapping
    {
        return $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => $reference]);
    }//end getMapping()

    public function createResponse(array $content, int $status): Response
    {
        $xmlEncoder = new XmlEncoder(['xml_root_node_name' => 'SOAP-ENV:Envelope']);
        $contentString = $xmlEncoder->encode($content, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);

        return new Response($contentString, $status);
    }//end createResponse()

    /**
     * Handles incoming creeerZaakIdentificatie messages, creates a case with incoming reference as identificatie field
     *
     * @param array $data    The inbound data from the request
     * @param array $config  The configuration for the handler
     * @return array
     */
    public function zaakIdentificatieActionHandler (array $data, array $config): array
    {
        $zaakEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json');
        $mapping = $this->getMapping('https://opencatalogi.nl/schemas/zds.zdsZaakIdToZgwZaak.schema.json');

        $zaakArray = $this->mappingService->mapping($mapping, $data['body']);
        $zaken = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['identificatie']], [$zaakEntity->getId()->toString()])['results'];
        if (!$zaken) {
            $zaak = new ObjectEntity($zaakEntity);
            $zaak->hydrate($zaakArray);

            $this->entityManager->persist($zaak);
            $this->entityManager->flush();

            if ($mappingOut = $this->getMapping('https://opencatalogi.nl/schemas/zds.zgwZaakToDu02.schema.json')) {
                $data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $zaak->toArray()), 200);
            }//end if
        } else {
            $data['response'] = $this->createResponse(['Error' => 'The case with id ' .$zaakArray['identificatie']. ' already exists'], 400);
        }//end if

        return $data;
    }//end zaakIdentificatieActionHandler()

    /**
     * Handles incoming creeerDocumentIdentificatie messages, creates a document with incoming reference as identificatie field
     *
     * @param array $data    The inbound data from the request
     * @param array $config  The configuration for the handler
     * @return array
     */
    public function documentIdentificatieActionHandler (array $data, array $config): array
    {
        $documentEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/drc.enkelvoudigInformatieObject.schema.json');

        $mapping = $this->getMapping('https://opencatalogi.nl/schemas/zds.zdsDocumentIdToZgwDocument.schema.json');

        $documentArray = $this->mappingService->mapping($mapping, $data['body']);
        $documents = $this->cacheService->searchObjects(null, ['identificatie' => $documentArray['identificatie']], [$documentEntity->getId()->toString()])['results'];
        if (!$documents) {
            $document = new ObjectEntity($documentEntity);
            $document->hydrate($documentArray);

            $this->entityManager->persist($document);
            $this->entityManager->flush();

            if ($mappingOut = $this->getMapping('https://opencatalogi.nl/schemas/zds.zgwDocumentToDu02.schema.json')) {
                $data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $document->toArray()), 200);
            }//end if
        } else {
            $data['response'] = $this->createResponse(['Error' => 'The document with id ' .$documentArray['identificatie']. ' already exists'], 400);
        }//end if

        return $data;
    }//end documentIdentificatieActionHandler()

    /**
     * Connects Eigenschappen to ZaakType if eigenschap does not exist yet, or connect existing Eigenschap to ZaakEigenschap
     *
     * @param array        $zaakArray The mapped zaak
     * @param ObjectEntity $zaakType  The zaakType to connect
     * @return array
     */
    public function connectEigenschappen(array $zaakArray, ObjectEntity $zaakType): array
    {
        $eigenschapEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/ztc.eigenschap.schema.json');
        $eigenschappenAsObjects = $zaakType->getValue('eigenschappen');
        foreach ($zaakArray['eigenschappen'] as $key => $eigenschap) {
            if ($eigenschappen = $this->cacheService->searchObjects(null, ['naam' => $eigenschap['eigenschap']['naam'], 'zaaktype' => $zaakType->getSelf()], [$eigenschapEntity->getId()->toString()])['results']) {
                $zaakArray['eigenschappen'][$key]['eigenschap'] = $eigenschappen[0]['_self']['id'];
            } else {
                $eigenschapObject = new ObjectEntity($eigenschapEntity);
                $eigenschap['eigenschap']['zaaktype'] = $zaakType->getSelf();
                $eigenschapObject->hydrate($eigenschap['eigenschap']);

                $this->entityManager->persist($eigenschapObject);
                $this->entityManager->flush();
                $eigenschappenAsObjects[] = $zaakArray['eigenschappen'][$key]['eigenschap'] = $eigenschapObject->getId()->toString();
            }//end if
        }//end foreach
        $zaakType->hydrate(['eigenschappen' => $eigenschappenAsObjects]);

        $this->entityManager->persist($zaakType);
        $this->entityManager->flush();

        return $zaakArray;
    }//end connectEigenschappen()

    /**
     * Connects RoleTypes to ZaakType if RoleType does not exist yet, or connect existing RoleType to Role
     *
     * @param array         $zaakArray The mapped zaak
     * @param ObjectEntity  $zaakType  The zaakType to connect
     * @return array
     */
    public function connectRolTypes(array $zaakArray, ObjectEntity $zaakType): array
    {
        $rolTypeEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/ztc.rolType.schema.json');
        $rolTypeObjects = $zaakType->getValue('roltypen');

        foreach ($zaakArray['rollen'] as $key => $role) {
            if ($rollen = $this->cacheService->searchObjects(null, ['omschrijvingGeneriek' => $role['roltype']['omschrijvingGeneriek'], 'zaaktype' => $zaakType->getSelf()], [$rolTypeEntity->getId()->toString()])['results']) {
                $zaakArray['rollen'][$key]['roltype'] = $rollen[0]['_self']['id'];
                $rolType = $this->entityManager->find('App:ObjectEntity', $rollen[0]['_self']['id']);
            } else {
                $rolType = new ObjectEntity($rolTypeEntity);
                $role['roltype']['zaaktype'] = $zaakType->getSelf();
                $rolType->hydrate($role['roltype']);

                $this->entityManager->persist($rolType);
                $this->entityManager->flush();

                $rolTypeObjects[] = $zaakArray['rollen'][$key]['roltype'] = $rolType->getId()->toString();
            }//end if
        }//end foreach

        $zaakType->hydrate(['roltypen' => $rolTypeObjects]);

        return $zaakArray;
    }//end connectRolTypes()

    /**
     * Creates ZaakType if no ZaakType exists, connect existing ZaakType if ZaakType with identifier exists
     *
     * @param array $zaakArray
     * @return array
     */
    public function convertZaakType(array $zaakArray): array
    {
        $zaakTypeEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/ztc.zaakType.schema.json');
        $zaaktypes = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['zaaktype']['identificatie']], [$zaakTypeEntity->getId()->toString()])['results'];
        if (count($zaaktypes) > 0) {
            $zaaktype = $this->entityManager->find('App:ObjectEntity', $zaaktypes[0]['_self']['id']);
            $zaakArray['zaaktype'] = $zaaktype->getId()->toString();
        } else {
            $zaaktype = new ObjectEntity($zaakTypeEntity);
            $zaaktype->hydrate($zaakArray['zaaktype']);

            $this->entityManager->persist($zaaktype);
            $this->entityManager->flush();

            $zaakArray['zaaktype'] = $zaaktype->getId()->toString();
        }//end if

        $zaakArray = $this->connectEigenschappen($zaakArray, $zaaktype);
        $zaakArray = $this->connectRolTypes($zaakArray, $zaaktype);

        return $zaakArray;
    }//end convertZaakType

    /**
     * Receives a case and maps it to a ZGW case
     *
     * @param array $data   The inbound data for the case
     * @param array $config The configuration for the action
     * @return array
     */
    public function zaakActionHandler(array $data, array $config): array
    {
        $zaakEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json');

        $mapping = $this->getMapping('https://opencatalogi.nl/schemas/zds.zdsZaakToZgwZaak.schema.json');

        $zaakArray = $this->mappingService->mapping($mapping, $data['body']);

        $zaakArray = $this->convertZaakType($zaakArray);

        $zaken = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['identificatie']], [$zaakEntity->getId()->toString()])['results'];
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
        }//end if

        return $data;
    }//end zaakActionHandler()

    /**
     * Receives a document and maps it to a ZGW EnkelvoudigInformatieObject
     *
     * @param array $data   The inbound data for the case
     * @param array $config The configuration for the action
     * @return array
     */
    public function documentActionHandler(array $data, array $config): array
    {
        $zaakEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json');
        $zaakInformatieObjectEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/zrc.zaakInformatieObject.schema.json');
        $documentEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/drc.enkelvoudigInformatieObject.schema.json');

        $mapping = $this->getMapping('https://opencatalogi.nl/schemas/zds.zdsDocumentToZgwDocument.schema.json');

        $zaakInformatieObjectArray = $this->mappingService->mapping($mapping, $data['body']);

        $documenten = $this->cacheService->searchObjects(null, ['identificatie' => $zaakInformatieObjectArray['informatieobject']['identificatie']], [$documentEntity->getId()->toString()])['results'];
        $zaken = $this->cacheService->searchObjects(null, ['identificatie' => $zaakInformatieObjectArray['zaak']], [$zaakEntity->getId()->toString()])['results'];
        if (count($documenten) == 1 && count($zaken) == 1) {
            $informatieobject = $this->entityManager->find('App:ObjectEntity', $documenten[0]['_self']['id']);
            $informatieobject->hydrate($zaakInformatieObjectArray['informatieobject']);
            $this->entityManager->persist($informatieobject);
            $this->entityManager->flush();

            $zaakInformatieObject = new ObjectEntity($zaakInformatieObjectEntity);
            $zaakInformatieObject->hydrate(['zaak' => $zaken[0]['_self']['id'], 'informatieobject' => $informatieobject->getId()->toString()]);

            $this->entityManager->persist($zaakInformatieObject);
            $this->entityManager->flush();

            if ($mappingOut = $this->getMapping('https://opencatalogi.nl/schemas/zds.zgwDocumentToBv03.schema.json')) {
                $data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $zaakInformatieObject->toArray()), 200);
            }//end if
        } elseif (count($documenten) > 1) {
            $data['response'] = $this->createResponse(['Error' => 'More than one document exists with id '.$zaakInformatieObjectArray['informatieobject']['identificatie']]);
        } else {
            $data['response'] = $this->createResponse(['Error' => 'The case with id '.$zaakInformatieObjectArray['informatieobject']['identificatie'].' does not exist']);
        }//end if

        return $data;
    }//end documentActionHandler()
}
