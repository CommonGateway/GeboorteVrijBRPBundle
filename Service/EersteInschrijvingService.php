<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class EersteInschrijvingService
{
    private ZgwToVrijbrpService $zgwToVrijbrpService;
    private LoggerInterface $logger;
    private EntityManagerInterface $entityManager;

    private array $data;
    private array $configuration;

    public function __construct(ZgwToVrijbrpService $zgwToVrijbrpService, LoggerInterface $actionLogger, EntityManagerInterface $entityManager)
    {
        $this->zgwToVrijbrpService = $zgwToVrijbrpService;
        $this->logger = $actionLogger;
        $this->entityManager = $entityManager;
    }

    /**
     * Recursively removes self parameters from object.
     *
     * @param array $object The object to remove self parameters from.
     *
     * @return array The cleaned object.
     */
    public function removeSelf(array $object): array
    {
        if(isset($object['_self']) === true) {
            unset($object['_self']);
        }
        foreach($object as $key => $value) {
            if(is_array($value)) {
                $object[$key] = $this->removeSelf($value);
            }
        }
        return $object;
    }//end removeSelf()

    public function vrijbrpHandler(array $data, array $configuration): array
    {
        $this->logger->info('Syncing EersteInschrijving object to VrijBRP');
        $this->configuration = $configuration;
        $this->data = $data;

        $source = $this->zgwToVrijbrpService->getSource($configuration['source']);
        $synchronizationEntity = $this->zgwToVrijbrpService->getEntity($configuration['synchronizationEntity']);
        if ($source === null
            || $synchronizationEntity === null
        ) {
            return [];
        }

        $dataId = $data['response']->_id;

        $object = $this->entityManager->getRepository('App:ObjectEntity')->find($dataId);
        $this->logger->debug("EersteInschrijving Object with id $dataId was created");

        $objectArray = $object->toArray();
        $objectArray = $this->removeSelf($objectArray);

        //@TODO $objectArray unset _self etc..

        // Create synchronization.
        $synchronization = $this->zgwToVrijbrpService->getSynchronization($object, $source, $synchronizationEntity);

        $this->logger->debug("Synchronize (Zaak) Object to: {$source->getLocation()}".$this->configuration['location']);
        // Todo: change synchronize function so it can also push to a source and not only pull from a source:
        // $this->syncService->synchronize($synchronization, $objectArray);

        // Todo: temp way of doing this without updated synchronize() function...
        if ($data = $this->zgwToVrijbrpService->synchronizeTemp($synchronization, $objectArray, $this->configuration['location'])) {
            // Return empty array on error for when we got here through a command.
            return ['response' => $data];
        }

        return $data;
    }//end zgwToVrijbrpHandler()
}
