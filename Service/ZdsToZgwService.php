<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use App\Entity\Entity;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\MappingService;
use CommonGateway\CoreBundle\Service\ObjectEntityService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

/**
 *  This class handles the interaction with componentencatalogus.commonground.nl.
 */
class ZdsToZgwService
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

    /**
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var array
     */
    private array $configuration;

    private ObjectEntityService $objectEntityService;

    /**
     * @param EntityManagerInterface $entityManager  The Entity Manager
     * @param MappingService         $mappingService The MappingService
     * @param CacheService           $cacheService   The CacheService
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        MappingService $mappingService,
        CacheService $cacheService,
        LoggerInterface $actionLogger,
        ObjectEntityService $objectEntityService
    ) {
        $this->entityManager = $entityManager;
        $this->mappingService = $mappingService;
        $this->cacheService = $cacheService;
        $this->logger = $actionLogger;
        $this->objectEntityService = $objectEntityService;
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
        $this->mappingService->setStyle($io);

        return $this;
    }//end setStyle()

    /**
     * Get an entity by reference.
     *
     * @param string $reference The reference to look for
     *
     * @return Entity|null
     */
    public function getEntity(string $reference): ?Entity
    {
        $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $reference]);
        if ($entity === null) {
            $this->logger->error("No entity found for $reference");
            isset($this->io) && $this->io->error("No entity found for $reference");
        }//end if

        return $entity;
    }//end getEntity()

    /**
     * Gets mapping for reference.
     *
     * @param string $reference The reference to look for
     *
     * @return Mapping
     */
    public function getMapping(string $reference): Mapping
    {
        $mapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => $reference]);
        if ($mapping === null) {
            $this->logger->error("No mapping found for $reference");
        }

        return $mapping;
    }//end getMapping()

    /**
     * Creates a response based on content.
     *
     * @param array $content The content to incorporate in the response
     * @param int   $status  The status code of the response
     *
     * @return Response
     */
    public function createResponse(array $content, int $status): Response
    {
        $this->logger->debug('Creating XML response');
        $xmlEncoder = new XmlEncoder(['xml_root_node_name' => 'SOAP-ENV:Envelope']);
        $contentString = $xmlEncoder->encode($content, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);

        return new Response($contentString, $status);
    }//end createResponse()

    /**
     * Handles incoming creeerZaakIdentificatie messages, creates a case with incoming reference as identificatie field.
     *
     * @param array $data   The inbound data from the request
     * @param array $config The configuration for the handler
     *
     * @return array
     */
    public function zaakIdentificatieActionHandler(array $data, array $config): array
    {
        $this->logger->info('Handling Create Case Identification');
        $this->configuration = $config;

        $zaakEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json');
        $mapping = $this->getMapping('https://zds.nl/mapping/zds.zdsZaakIdToZgwZaak.mapping.json');
        if ($zaakEntity === null || $mapping === null) {
            return $data;
        }

        $zaakArray = $this->mappingService->mapping($mapping, $data['body']);
        $zaken = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['identificatie']], [$zaakEntity->getId()->toString()])['results'];
        if ($zaken === []) {
            $this->logger->debug('Creating new case with identifier'.$zaakArray['identificatie']);
            $zaak = new ObjectEntity($zaakEntity);
            $zaak->hydrate($zaakArray);

            $this->entityManager->persist($zaak);
            $this->entityManager->flush();

            $this->logger->info('Created case with identifier '.$zaakArray['identificatie']);
            $mappingOut = $this->getMapping('https://zds.nl/mapping/zds.zgwZaakToDu02.mapping.json');
            $data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $this->objectEntityService->toArray($zaak)), 200);
        } else {
            $this->logger->warning('Case with identifier '.$zaakArray['identificatie'].' found, returning bad request error');
            $data['response'] = $this->createResponse(['Error' => 'The case with id '.$zaakArray['identificatie'].' already exists'], 400);
        }//end if

        return $data;
    }//end zaakIdentificatieActionHandler()

    /**
     * Handles incoming creeerDocumentIdentificatie messages, creates a document with incoming reference as identificatie field.
     *
     * @param array $data   The inbound data from the request
     * @param array $config The configuration for the handler
     *
     * @return array
     */
    public function documentIdentificatieActionHandler(array $data, array $config): array
    {
        $this->logger->info('Handling Create Document Identification');
        $this->configuration = $config;

        $documentEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/drc.enkelvoudigInformatieObject.schema.json');

        $mapping = $this->getMapping('https://zds.nl/mapping/zds.zdsDocumentIdToZgwDocument.mapping.json');

        $documentArray = $this->mappingService->mapping($mapping, $data['body']);
        $documents = $this->cacheService->searchObjects(null, ['identificatie' => $documentArray['identificatie']], [$documentEntity->getId()->toString()])['results'];
        if ($documents === []) {
            $this->logger->debug('Creating new document for identification'.$documentArray['identificatie']);
            $document = new ObjectEntity($documentEntity);
            $document->hydrate($documentArray);

            $this->entityManager->persist($document);
            $this->entityManager->flush();

            $this->logger->info('Created case with identifier '.$documentArray['identificatie']);
            $mappingOut = $this->getMapping('https://zds.nl/mapping/zds.zgwDocumentToDu02.mapping.json');
            $data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $this->objectEntityService->toArray($document)), 200);
        } else {
            $this->logger->warning('Case with identifier '.$documentArray['identificatie'].' found, returning bad request error');
            $data['response'] = $this->createResponse(['Error' => 'The document with id '.$documentArray['identificatie'].' already exists'], 400);
        }//end if

        return $data;
    }//end documentIdentificatieActionHandler()

    /**
     * Connects Eigenschappen to ZaakType if eigenschap does not exist yet, or connect existing Eigenschap to ZaakEigenschap.
     *
     * @param array        $zaakArray The mapped zaak
     * @param ObjectEntity $zaakType  The zaakType to connect
     *
     * @return array
     */
    public function connectEigenschappen(array $zaakArray, ObjectEntity $zaakType): array
    {
        $this->logger->info('Trying to connect case type properties to existing properties');

        $eigenschapEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/ztc.eigenschap.schema.json');
        $eigenschapObjects = [];
        foreach ($zaakArray['eigenschappen'] as $key => $eigenschap) {
            $eigenschappen = $this->cacheService->searchObjects(null, ['naam' => $eigenschap['eigenschap']['naam'], 'zaaktype' => $zaakType->getSelf()], [$eigenschapEntity->getId()->toString()])['results'];
            if ($eigenschappen !== []) {
                $this->logger->debug('Property has been found, connecting to property');

                $zaakArray['eigenschappen'][$key]['eigenschap'] = $eigenschappen[0]['_self']['id'];
            } else {
                $this->logger->debug('No existing property found, creating new property');

                $eigenschapObject = new ObjectEntity($eigenschapEntity);
                $eigenschap['eigenschap']['zaaktype'] = $zaakType->getSelf();
                $eigenschapObject->hydrate($eigenschap['eigenschap']);

                $this->entityManager->persist($eigenschapObject);
                $this->entityManager->flush();
                $eigenschapObjects[] = $zaakArray['eigenschappen'][$key]['eigenschap'] = $eigenschapObject;
            }//end if
        }//end foreach

        $zaakType->hydrate(['eigenschappen' => $eigenschapObjects]);

        $this->logger->info('Connected case properties to case type properties');

        return $zaakArray;
    }//end connectEigenschappen()

    /**
     * Connects RoleTypes to ZaakType if RoleType does not exist yet, or connect existing RoleType to Role.
     *
     * @param array        $zaakArray The mapped zaak
     * @param ObjectEntity $zaakType  The zaakType to connect
     *
     * @return array
     */
    public function connectRolTypes(array $zaakArray, ObjectEntity $zaakType): array
    {
        $this->logger->info('Trying to connect roles to existing role types');
        $rolTypeEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/ztc.rolType.schema.json');
        $rolTypeObjects = $zaakType->getValue('roltypen');

        foreach ($zaakArray['rollen'] as $key => $role) {
            $rollen = $this->cacheService->searchObjects(null, ['omschrijvingGeneriek' => $role['roltype']['omschrijvingGeneriek'], 'zaaktype' => $zaakType->getSelf()], [$rolTypeEntity->getId()->toString()])['results'];
            if ($rollen !== []) {
                $this->logger->debug('Role type has been found, connecting to existing role type');
                $zaakArray['rollen'][$key]['roltype'] = $rollen[0]['_self']['id'];
                $rolType = $this->entityManager->find('App:ObjectEntity', $rollen[0]['_self']['id']);
            } else {
                $this->logger->debug('No existing role type has been found, creating new role type');
                $rolType = new ObjectEntity($rolTypeEntity);
                $role['roltype']['zaaktype'] = $zaakType->getSelf();
                $rolType->hydrate($role['roltype']);

                $this->entityManager->persist($rolType);
                $this->entityManager->flush();

                $rolTypeObjects[] = $zaakArray['rollen'][$key]['roltype'] = $rolType->getId()->toString();
            }//end if
        }//end foreach

        $zaakType->hydrate(['roltypen' => $rolTypeObjects]);

        $this->logger->info('Connected roles to role types');

        return $zaakArray;
    }//end connectRolTypes()

    /**
     * Creates ZaakType if no ZaakType exists, connect existing ZaakType if ZaakType with identifier exists.
     *
     * @param array $zaakArray The mapped case
     *
     * @return array
     */
    public function convertZaakType(array $zaakArray): array
    {
        $this->logger->debug('Trying to connect case to existing case type');

        $zaakTypeEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/ztc.zaakType.schema.json');
        $zaaktypes = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['zaaktype']['identificatie']], [$zaakTypeEntity->getId()->toString()])['results'];
        if (count($zaaktypes) > 0) {
            $this->logger->debug('Case type found, connecting case to case type');

            $zaaktype = $this->entityManager->find('App:ObjectEntity', $zaaktypes[0]['_self']['id']);
            $zaakArray['zaaktype'] = $zaaktype->getId()->toString();
        } else {
            $this->logger->debug('No existing case type found, creating new case type');

            $zaaktype = new ObjectEntity($zaakTypeEntity);
            $zaaktype->hydrate($zaakArray['zaaktype']);

            $this->entityManager->persist($zaaktype);
            $this->entityManager->flush();

            $zaakArray['zaaktype'] = $zaaktype->getId()->toString();
        }//end if

        $this->logger->info('Case connected to case type with identification'.$this->objectEntityService->toArray($zaaktype)['identificatie']);

        $zaakArray = $this->connectEigenschappen($zaakArray, $zaaktype);
        $zaakArray = $this->connectRolTypes($zaakArray, $zaaktype);

        return $zaakArray;
    }//end convertZaakType()

    /**
     * Receives a case and maps it to a ZGW case.
     *
     * @param array $data   The inbound data for the case
     * @param array $config The configuration for the action
     *
     * @return array
     */
    public function zaakActionHandler(array $data, array $config): array
    {
        $this->logger->info('Populate case');
        $this->configuration = $config;

        $zaakEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json');
        $mapping = $this->getMapping('https://zds.nl/mapping/zds.zdsZaakToZgwZaak.mapping.json');

        $zaakArray = $this->mappingService->mapping($mapping, $data['body']);

        $zaakArray = $this->convertZaakType($zaakArray);

        $zaken = $this->cacheService->searchObjects(null, ['identificatie' => $zaakArray['identificatie']], [$zaakEntity->getId()->toString()])['results'];
        if (count($zaken) === 1) {
            $this->logger->debug('Populating case with identification '.$zaakArray['identificatie']);

            $zaak = $this->entityManager->find('App:ObjectEntity', $zaken[0]['_self']['id']);
            $zaak->hydrate($zaakArray);

            $this->entityManager->persist($zaak);
            $this->entityManager->flush();

            $this->logger->info('Populated case with identification'.$zaakArray['identificatie']);

            $data['object'] = $this->objectEntityService->toArray($zaak);
            $mappingOut = $this->getMapping('https://zds.nl/mapping/zds.zgwZaakToBv03.mapping.json');
            $data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $this->objectEntityService->toArray($zaak)), 200);
        } elseif (count($zaken) > 1) {
            $this->logger->warning('More than one case was found for identifier'.$zaakArray['identificatie']);

            $data['response'] = $this->createResponse(['Error' => 'More than one case exists with id '.$zaakArray['identificatie']], 400);
        } else {
            $this->logger->warning('No case was found for identifier'.$zaakArray['identificatie']);

            $data['response'] = $this->createResponse(['Error' => 'The case with id '.$zaakArray['identificatie'].' does not exist'], 400);
        }//end if

        return $data;
    }//end zaakActionHandler()

    /**
     * Receives a document and maps it to a ZGW EnkelvoudigInformatieObject.
     *
     * @param array $data   The inbound data for the case
     * @param array $config The configuration for the action
     *
     * @return array
     */
    public function documentActionHandler(array $data, array $config): array
    {
        $this->logger->info('Populating document');
        $this->configuration = $config;

        $zaakEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json');
        $zaakDocumentEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/zrc.zaakInformatieObject.schema.json');
        $documentEntity = $this->getEntity('https://vng.opencatalogi.nl/schemas/drc.enkelvoudigInformatieObject.schema.json');
        $mapping = $this->getMapping('https://zds.nl/mapping/zds.zdsDocumentToZgwDocument.mapping.json');

        $zaakDocumentArray = $this->mappingService->mapping($mapping, $data['body']);

        $documenten = $this->cacheService->searchObjects(null, ['identificatie' => $zaakDocumentArray['informatieobject']['identificatie']], [$documentEntity->getId()->toString()])['results'];
        $zaken = $this->cacheService->searchObjects(null, ['identificatie' => $zaakDocumentArray['zaak']], [$zaakEntity->getId()->toString()])['results'];
        if (count($documenten) === 1 && count($zaken) === 1) {
            $this->logger->debug('Populating document with identification'.$zaakDocumentArray['informatieobject']['identificatie']);

            $informatieobject = $this->entityManager->find('App:ObjectEntity', $documenten[0]['_self']['id']);
            $informatieobject->hydrate($zaakDocumentArray['informatieobject']);
            $this->entityManager->persist($informatieobject);
            $this->entityManager->flush();

            $zaakInformatieObject = new ObjectEntity($zaakDocumentEntity);
            $zaakInformatieObject->hydrate(['zaak' => $zaken[0]['_self']['id'], 'informatieobject' => $informatieobject->getId()->toString()]);

            $this->entityManager->persist($zaakInformatieObject);
            $this->entityManager->flush();

            $this->logger->info('Populated document with identification'.$zaakDocumentArray['informatieobject']['identificatie']);
            $data['documents'][] = $this->objectEntityService->toArray($zaakInformatieObject);
            $mappingOut = $this->getMapping('https://zds.nl/mapping/zds.zgwDocumentToBv03.mapping.json');
            $data['response'] = $this->createResponse($this->mappingService->mapping($mappingOut, $this->objectEntityService->toArray($zaakInformatieObject)), 200);
        } elseif (count($documenten) > 1 && count($zaken) > 1) {
            $this->logger->warning('More than one document exists with id '.$zaakDocumentArray['informatieobject']['identificatie'].' and more than one case found with id '.$zaakDocumentArray['zaak']);
            $data['response'] = $this->createResponse(['Error' => 'More than one document exists with id '.$zaakDocumentArray['informatieobject']['identificatie'].' and more than one case found with id '.$zaakDocumentArray['zaak']], 400);
        } elseif (count($documenten) > 1) {
            $this->logger->warning('More than one document exists with id '.$zaakDocumentArray['informatieobject']['identificatie']);
            $data['response'] = $this->createResponse(['Error' => 'More than one document exists with id '.$zaakDocumentArray['informatieobject']['identificatie']], 400);
        } elseif (count($zaken) > 1) {
            $this->logger->warning('More than one case exists with id '.$zaakDocumentArray['zaak']);
            $data['response'] = $this->createResponse(['Error' => 'More than one case exists with id '.$zaakDocumentArray['zaak']], 400);
        } else {
            $this->logger->warning('The case with id '.$zaakDocumentArray['informatieobject']['identificatie'].' does not exist');
            $data['response'] = $this->createResponse(['Error' => 'The case with id '.$zaakDocumentArray['informatieobject']['identificatie'].' does not exist'], 400);
        }//end if

        return $data;
    }//end documentActionHandler()
}
