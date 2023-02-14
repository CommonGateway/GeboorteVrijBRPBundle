<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use Adbar\Dot;
use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * This Service handles the mapping and sending of ZGW zaak data to the Vrijbrp api.
 * todo: I have written this service as abstract as possible (in the little time i had for this) so that we could
 * todo: maybe use this as a basis for creating a new SynchronizationService->push / syncToSource function.
 *
 * @author Wilco Louwerse <wilco@conduction.nl>
 */
class ZgwToVrijbrpService
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var SymfonyStyle SymfonyStyle for writing user feedback to console.
     */
    private SymfonyStyle $symfonyStyle;

    /**
     * @var array ActionHandler configuration.
     */
    private array $configuration;

    /**
     * @var array Data of the api call.
     */
    private array $data;

    /**
     * @var Source|null The Source we are using for the outgoing call.
     */
    private ?Source $source;

    /**
     * @var Mapping|null The mapping we are using for the outgoing call.
     */
    private ?Mapping $mapping;

    /**
     * @var Entity|null The entity used for creating a Synchronization object. (and also the entity that triggers the ActionHandler).
     */
    private ?Entity $synchronizationEntity;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $mappingLogger;

    /**
     * Construct a ZgwToVrijbrpService.
     *
     * @param EntityManagerInterface $entityManager  EntityManagerInterface.
     * @param CallService            $callService    CallService.
     * @param SynchronizationService $syncService    SynchronizationService.
     * @param MappingService         $mappingService MappingService.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $syncService,
        MappingService $mappingService,
        LoggerInterface $actionLogger,
        LoggerInterface $mappingLogger
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->syncService = $syncService;
        $this->mappingService = $mappingService;
        $this->logger = $actionLogger;
        $this->mappingLogger = $mappingLogger;
    }//end __construct()

    /**
     * Set symfony style in order to output to the console when running the handler function through a command.
     *
     * @param SymfonyStyle $symfonyStyle SymfonyStyle for writing user feedback to console.
     *
     * @return self This.
     */
    public function setStyle(SymfonyStyle $symfonyStyle): self
    {
        $this->symfonyStyle = $symfonyStyle;
        $this->syncService->setStyle($symfonyStyle);
        $this->mappingService->setStyle($symfonyStyle);

        return $this;
    }//end setStyle()

    /**
     * Gets and sets Source object using the required configuration['source'] to find the correct Source.
     *
     * @return Source|null The Source object we found or null if we don't find one.
     */
    private function setSource(): ?Source
    {
        // Todo: Add FromSchema function to Gateway Gateway.php, so that we can use .json files for sources as well.
        // Todo: ...For this to work, we also need to change CoreBundle installationService.
        // Todo: ...If we do this we can also add and use reference for Gateways / Sources.
        $this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => $this->configuration['source']]);
        if ($this->source instanceof Source === false) {
            if (isset($this->symfonyStyle) === true) {
                $this->symfonyStyle->error("No source found with location: {$this->configuration['source']}");
            }
            $this->logger->error("No source found with location: {$this->configuration['source']}");

            return null;
        }

        return $this->source;
    }//end setSource()

    /**
     * Gets and sets a Mapping object using the required configuration['mapping'] to find the correct Mapping.
     *
     * @return Mapping|null The Mapping object we found or null if we don't find one.
     */
    private function setMapping(): ?Mapping
    {
        $this->mapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => $this->configuration['mapping']]);
        if ($this->mapping instanceof Mapping === false) {
            if (isset($this->symfonyStyle) === true) {
                $this->symfonyStyle->error("No mapping found with reference: {$this->configuration['mapping']}");
            }
            $this->logger->error("No mapping found with reference: {$this->configuration['mapping']}");

            return null;
        }

        return $this->mapping;
    }//end setMapping()

    /**
     * Gets and sets a synchronizationEntity object using the required configuration['synchronizationEntity'] to find the correct Entity.
     *
     * @return Entity|null The synchronizationEntity object we found or null if we don't find one.
     */
    private function setSynchronizationEntity(): ?Entity
    {
        $this->synchronizationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $this->configuration['synchronizationEntity']]);
        if ($this->synchronizationEntity instanceof Entity === false) {
            if (isset($this->symfonyStyle) === true) {
                $this->symfonyStyle->error("No entity found with reference: {$this->configuration['synchronizationEntity']}");
            }
            $this->logger->error("No entity found with reference: {$this->configuration['synchronizationEntity']}");

            return null;
        }

        return $this->synchronizationEntity;
    }//end setSynchronizationEntity()

    /**
     * Maps zgw eigenschappen to vrijbrp mapping.
     *
     * @param array $zgw    The ZGW case
     * @param array $output The output data
     *
     * @throws Exception
     *
     * @return array
     */
    private function getBirthProperties(array $zgw, array $output): array
    {
        $this->mappingLogger->info('Do additional mapping with case properties');

        $properties = $this->getEigenschapValues($zgw['eigenschappen']);
        $output['qualificationForDeclaringType'] = $properties['relatie'] ?? null;

        if (isset($properties['sub.telefoonnummer'])) {
            $output['declarant']['contactInformation']['telephoneNumber'] = $properties['sub.telefoonnummer'];
        }
        if (isset($properties['sub.emailadres'])) {
            $output['declarant']['contactInformation']['email'] = $properties['sub.emailadres'];
        }

        if (isset($properties['inp.bsn'])) {
            $output['mother']['bsn'] = $properties['inp.bsn'];
            $output['fatherDuoMother']['bsn'] = $output['declarant']['bsn'];
        } else {
            $output['mother']['bsn'] = $output['declarant']['bsn'];
            !isset($output['declarant']['contactInformation']) ?: $output['mother']['contactInformation'] = $output['declarant']['contactInformation'];
        }

        foreach ($properties['children'] as $key => $child) {
            $output['children'][$key]['firstname'] = $child['voornamen'];
            $output['children'][$key]['gender'] = $child['geslachtsaanduiding'];
            $birthDate = new \DateTime($child['geboortedatum']);
            $birthTime = new \DateTime($child['geboortetijd']);

            $output['children'][$key]['birthDateTime'] = $birthDate->format('Y-m-d').'T'.$birthTime->format('H:i:s');
        }

        $output['children'] = array_values($output['children']);

        $output['nameSelection']['lastname'] = $properties['geslachtsnaam'];
        !isset($properties['voorvoegselGeslachtsnaam']) ?: $output['nameSelection']['prefix'] = $properties['voorvoegselGeslachtsnaam'];

        $this->mappingLogger->info('Done with additional mapping');

        return $output;
    }//end getSpecificProperties()

    /**
     * Converts ZGW eigenschappen to key, value pairs.
     *
     * @param array $eigenschappen The properties of the case
     *
     * @return array
     */
    private function getEigenschapValues(array $eigenschappen): array
    {
        $this->mappingLogger->debug('Flatten properties to key value pairs');
        $flatProperties = [];
        foreach ($eigenschappen as $eigenschap) {
            // Check if last character of $eigenschap['naam'] is a string or an integer. === 0 if it is a string.
            if (intval(substr($eigenschap['naam'], -1)) === 0) {
                $flatProperties[$eigenschap['naam']] = $eigenschap['waarde'];
            } else {
                $flatProperties['children'][intval(substr($eigenschap['naam'], -1)) - 1][substr_replace($eigenschap['naam'], '', -1)] = $eigenschap['waarde'];
            }
        }

        return $flatProperties;
    }//end getEigenschapValues()

    /**
     * Handles a ZgwToVrijBrp action.
     *
     * @param array $data          The data from the call.
     * @param array $configuration The configuration from the ActionHandler.
     *
     * @throws Exception
     *
     * @return array Data.
     */
    public function zgwToVrijbrpHandler(array $data, array $configuration): array
    {
        $this->logger->info('Converting ZGW object to VrijBRP');
        $this->configuration = $configuration;
        $this->data = $data;
        if ($this->setSource() === null || $this->setMapping() === null || $this->setSynchronizationEntity() === null) {
            return [];
        }

        $dataId = $data['object']['_self']['id'];

        // Get (zaak) object that was created.
        if (isset($this->symfonyStyle) === true) {
            $this->symfonyStyle->comment("(Zaak) Object with id $dataId was created");
        }
        $this->logger->debug("(Zaak) Object with id $dataId was created");

        $object = $this->entityManager->getRepository('App:ObjectEntity')->find($dataId);
        $objectArray = $object->toArray();
        $zaakTypeId = $objectArray['zaaktype']['identificatie'];

        // Do mapping with Zaak ObjectEntity as array.
        $objectArray = $this->mappingService->mapping($this->mapping, $objectArray);

        // todo: make this a function? when merging all Vrijbrp Bundles:
        switch ($zaakTypeId) {
            case 'B0237':
                $objectArray = $this->getBirthProperties($object->toArray(), $objectArray);
                break;
            default:
                return [];
        }

        // Create synchronization.
        $synchronization = $this->syncService->findSyncByObject($object, $this->source, $this->synchronizationEntity);
        $synchronization->setMapping($this->mapping);

        // Send request to source.
        if (isset($this->symfonyStyle) === true) {
            $this->symfonyStyle->comment("Synchronize (Zaak) Object to: {$this->source->getLocation()}{$this->configuration['location']}");
        }
        $this->logger->debug("Synchronize (Zaak) Object to: {$this->source->getLocation()}{$this->configuration['location']}");

        // Todo: change synchronize function so it can also push to a source and not only pull from a source:
        // $this->syncService->synchronize($synchronization, $objectArray);

        // Todo: temp way of doing this without updated synchronize() function...
        if ($this->synchronizeTemp($synchronization, $objectArray) === [] &&
            isset($this->symfonyStyle) === true) {
            // Return empty array on error for when we got here through a command.
            return [];
        }

        return $data;
    }//end zgwToVrijbrpHandler()

    /**
     * Todo: just re-use the zgwToVrijbrpHandler function^ but add a switch, this is a duplicate that should not exist this way.
     *
     * @param array $data
     * @param array $configuration
     *
     * @return array
     */
    public function zgwToVrijbrpDocumentHandler(array $data, array $configuration): array
    {
        $this->configuration = $configuration;
        $this->logger->info('Converting ZGW document to VrijBRP document');
        if ($this->setSource() === null || $this->setMapping() === null || $this->setSynchronizationEntity() === null) {
            return [];
        }

        $dataId = $data['object']['_self']['id'];

        $this->logger->debug("(Document) Object with id $dataId was created");

        $object = $this->entityManager->getRepository('App:ObjectEntity')->find($dataId);

        // Do mapping with Document ObjectEntity as array.
        $objectArray = $this->mappingService->mapping($this->mapping, $object->toArray());

        // todo: make this a switch (in a function?) or something when merging all Vrijbrp Bundles:
        $this->configuration['location'] = $this->configuration['location'].'/'.$objectArray['dossierId'].'/documents';
        unset($objectArray['dossierId']);

        // Create synchronization.
        $synchronization = $this->syncService->findSyncByObject($object, $this->source, $this->synchronizationEntity);
        $synchronization->setMapping($this->mapping);

        // Send request to source.
        $this->logger->debug("Synchronize (Document) Object to: {$this->source->getLocation()}{$this->configuration['location']}");

        // Todo: change synchronize function so it can also push to a source and not only pull from a source:
        // $this->syncService->synchronize($synchronization, $objectArray);

        // Todo: temp way of doing this without updated synchronize() function...
        if ($this->synchronizeTemp($synchronization, $objectArray) === [] &&
            isset($this->symfonyStyle) === true) {
            // Return empty array on error for when we got here through a command.
            return [];
        }

        return $data;
    }//end zgwToVrijbrpDocumentHandler()

    /**
     * Temporary function as replacement of the $this->syncService->synchronize() function.
     * Because currently synchronize function can only pull from a source and not push to a source.
     * // Todo: temp way of doing this without updated synchronize() function...
     *
     * @param Synchronization $synchronization The synchronization we are going to synchronize.
     * @param array           $objectArray     The object data we are going to synchronize.
     *
     * @return array The response body of the outgoing call, or an empty array on error.
     */
    private function synchronizeTemp(Synchronization $synchronization, array $objectArray): array
    {
        $objectString = $this->syncService->getObjectString($objectArray);

        $this->logger->info('Sending message with body '.$objectString);
        try {
            $result = $this->callService->call(
                $this->source,
                $this->configuration['location'],
                'POST',
                [
                    'body'    => $objectString,
                    //'query'   => [],
                    //'headers' => [],
                ]
            );
        } catch (Exception|GuzzleException $exception) {
            $this->syncService->ioCatchException(
                $exception,
                [
                    'line',
                    'file',
                    'message' => [
                        'preMessage' => 'Error while doing syncToSource in zgwToVrijbrpHandler: ',
                    ],
                ]
            );
            $this->logger->error('Could not synchronize object. Error message: '.$exception->getMessage().'\nFull Response'. ($exception instanceof ServerException||$exception instanceof ClientException||$exception instanceof RequestException === true ? $exception->getResponse()->getBody() : ''));

            return [];
        }//end try

        $body = $this->callService->decodeResponse($this->source, $result);

        $bodyDot = new Dot($body);
        $now = new DateTime();
        $synchronization->setLastSynced($now);
        $synchronization->setSourceLastChanged($now);
        $synchronization->setLastChecked($now);
        $synchronization->setHash(hash('sha384', serialize($bodyDot->jsonSerialize())));

        return $body;
    }//end synchronizeTemp()
}
