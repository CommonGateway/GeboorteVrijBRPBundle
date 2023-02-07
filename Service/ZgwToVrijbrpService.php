<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use Adbar\Dot;
use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Log;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
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
    private EntityManagerInterface $entityManager;
    private CallService $callService;
    private SynchronizationService $synchronizationService;
    private MappingService $mappingService;
    private SymfonyStyle $io;
    
    private array $configuration;
    private array $data;
    private ?Source $source;
    private ?Mapping $mapping;
    private ?Entity $conditionEntity;

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
     * Set symfony style in order to output to the console when running the handler function through a command.
     * todo: use monolog
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
     * Gets and sets Source object using the required configuration['source'] to find the correct Source.
     *
     * @return Source|null
     */
    private function setSource(): ?Source
    {
        // todo: Add FromSchema function to Gateway Gateway.php, so that we can use .json files for sources as well.
        // todo: ...For this to work, we also need to change CoreBundle installationService.
        // todo: ...If we do this we can also add and use reference for Gateways / Sources
        if (!$this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location'=>$this->configuration['source']])) {
            isset($this->io) && $this->io->error("No source found with location: {$this->configuration['source']}");
        
            return null;
        }
    
        return $this->source;
    }
    
    /**
     * Gets and sets a Mapping object using the required configuration['mapping'] to find the correct Mapping.
     *
     * @return Mapping|null
     */
    private function setMapping(): ?Mapping
    {
        if (!$this->mapping = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference'=>$this->configuration['mapping']])) {
            isset($this->io) && $this->io->error("No mapping found with reference: {$this->configuration['mapping']}");
        
            return null;
        }
    
        return $this->mapping;
    }
    
    /**
     * Gets and sets a conditionEntity object using the required configuration['conditionEntity'] to find the correct Entity.
     *
     * @return Entity|null
     */
    private function setConditionEntity(): ?Entity
    {
        if (!$this->conditionEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$this->configuration['conditionEntity']])) {
            isset($this->io) && $this->io->error("No entity found with reference: {$this->configuration['conditionEntity']}");
        
            return null;
        }
    
        return $this->conditionEntity;
    }
    
    /**
     * Handles a ZgwToVrijBrp action.
     *
     * @param array $data           The data from the call
     * @param array $configuration  The configuration from the call
     *
     * @return array|null
     */
    public function zgwToVrijbrpHandler(array $data, array $configuration): ?array
    {
        $this->configuration = $configuration;
        $this->data = $data;
        if ($this->setSource() === null || $this->setMapping() === null || $this->setConditionEntity() === null) {
            return [];
        }
        $id = $data['id'];
    
        // Get (zaak) object that was created
        isset($this->io) && $this->io->comment("(Zaak) Object with id $id was created");
        $object = $this->entityManager->getRepository('App:ObjectEntity')->find($id);
        
        // Do mapping with Zaak ObjectEntity as array
        $objectArray = $this->mappingService->mapping($this->mapping, $object->toArray());
        
        // Create synchronization
        $synchronization = $this->synchronizationService->findSyncByObject($object, $this->source, $this->conditionEntity);
        $synchronization->setMapping($this->mapping);
    
        // Send request to source
        isset($this->io) && $this->io->comment("Synchronize (Zaak) Object to: {$this->source->getLocation()}{$this->configuration['location']}");
        // todo: change synchronize function so it can also push to a source and not only pull from a source:
//        $this->synchronizationService->synchronize($synchronization, $objectArray);
    
        // todo: temp way of doing this without updated synchronize() function...
        if (empty($this->synchronizeTemp($synchronization, $objectArray))) {
            return [];
        }

        return $data;
    }
    
    /**
     * Temporary function as replacement of the $this->synchronizationService->synchronize() function.
     * Because currently synchronize function can only pull from a source and not push to a source
     * // todo: temp way of doing this without updated synchronize() function...
     *
     * @param Synchronization $synchronization
     * @param array $objectArray
     *
     * @return array|void
     */
    private function synchronizeTemp(Synchronization $synchronization, array $objectArray)
    {
        $objectString = $this->synchronizationService->getObjectString($objectArray);

        // todo: remove this code, here for testing purposes
        var_dump($objectString);
        $log = new Log();
        $log->setRequestContent($objectString);
        $log->setType('out');$log->setCallId(Uuid::uuid4());$log->setRequestMethod('POST');$log->setRequestHeaders([]);
        $log->setRequestQuery([]);$log->setRequestPathInfo('');$log->setRequestLanguages([]);$log->setSession('');
        $log->setResponseTime(0);
        $this->entityManager->persist($log);
        $this->entityManager->flush();
        // todo: END "remove, here for testing purposes"

        try {
            $result = $this->callService->call(
                $this->source,
                $this->configuration['location'],
                'POST',
                [
                    'body'    => $objectString,
//                    'query'   => [],
//                    'headers' => [],
                ]
            );
        } catch (Exception|GuzzleException $exception) {
            $this->synchronizationService->ioCatchException($exception, ['line', 'file', 'message' => [
                'preMessage' => 'Error while doing syncToSource in zgwToVrijbrpHandler: ',
            ]]);
        
            return [];
        }
    
        $body = $this->callService->decodeResponse($this->source, $result);
        
        $bodyDot = new Dot($body);
        $now = new DateTime();
        $synchronization->setLastSynced($now);
        $synchronization->setSourceLastChanged($now);
        $synchronization->setLastChecked($now);
        $synchronization->setHash(hash('sha384', serialize($bodyDot->jsonSerialize())));
        
        return $body;
    }
}
