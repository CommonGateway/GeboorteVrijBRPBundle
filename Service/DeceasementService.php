<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use CommonGateway\CoreBundle\Service\MappingService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use EasyRdf\Literal\Date;
use Psr\Log\LoggerInterface;

class DeceasementService
{
    private ZgwToVrijbrpService $zgwToVrijbrpService;
    private MappingService $mappingService;
    private LoggerInterface $logger;
    private EntityManagerInterface $entityManager;

    public function __construct(MappingService $mappingService, ZgwToVrijbrpService $zgwToVrijbrpService, LoggerInterface $actionLogger, EntityManagerInterface $entityManager)
    {
        $this->mappingService = $mappingService;
        $this->zgwToVrijbrpService = $zgwToVrijbrpService;
        $this->logger = $actionLogger;
        $this->entityManager = $entityManager;
    }

    public function getMapping(string $reference): ?Mapping
    {
        $reference = $this->entityManager->getRepository('App:Mapping')->findOneBy(['reference' => $reference]);
        if ($reference instanceof Mapping === false) {
            if (isset($this->symfonyStyle) === true) {
                $this->symfonyStyle->error("No mapping found with reference: $reference");
            }
            $this->logger->error("No mapping found with reference: $reference");

            return null;
        }
        return $reference;
    }

    public function getSource(string $location): ?Source
    {
        // Todo: Add FromSchema function to Gateway Gateway.php, so that we can use .json files for sources as well.
        // Todo: ...For this to work, we also need to change CoreBundle installationService.
        // Todo: ...If we do this we can also add and use reference for Gateways / Sources.
        $source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['location' => $location]);
        if ($source instanceof Source === false) {
            if (isset($this->symfonyStyle) === true) {
                $this->symfonyStyle->error("No source found with location: $location");
            }
            $this->logger->error("No source found with location: $location");

            return null;
        }

        return $source;
    }

    private function getSynchronizationEntity(string $reference): ?Entity
    {
        $synchronizationEntity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $reference]);
        if ($synchronizationEntity instanceof Entity === false) {
            if (isset($this->symfonyStyle) === true) {
                $this->symfonyStyle->error("No entity found with reference: $reference");
            }
            $this->logger->error("No entity found with reference: $reference");

            return null;
        }

        return $synchronizationEntity;
    }//end setSynchronizationEntity()
    
    /**
     * This function gets the zaakEigenschappen from the zgwZaak with the given properties (simXml elementen and Stuf extraElementen).
     *
     * @param ObjectEntity $zaakObjectEntity The zaak ObjectEntity.
     * @param array        $properties       The properties / eigenschappen we want to get.
     *
     * @return array zaakEigenschappen
     */
    public function getZaakEigenschappen(ObjectEntity $zaakObjectEntity, array $properties): array
    {
        $zaakEigenschappen = [];
        foreach ($zaakObjectEntity->getValue('eigenschappen') as $eigenschap) {
            if (in_array($eigenschap->getValue('naam'), $properties) || in_array('all', $properties)) {
                $zaakEigenschappen[$eigenschap->getValue('naam')] = $eigenschap->getValue('waarde');
            }
        }

        return $zaakEigenschappen;
    }

    public function getCorrespondence($properties): array
    {
        $correspondence = [
            'name' => $properties['contact.naam'] ?? null,
            'email' => $properties['sub.emailadres'] ?? null,
            'organization' => $properties['handelsnaam'] ?? null,
            'houseNumber' => isset($properties['aoa.huisnummer']) === true ? intval($properties['aoa.huisnummer']) : null,
            'postalCode' => $properties['aoa.postcode'] ?? null,
            'residence' => $properties['wpl.woonplaatsnaam'] ?? null,
            'street' => $properties['gor.openbareRuimteNaam'] ?? null,
        ];
        isset($properties['aoa.huisletter']) === false ?: $correspondence['houseNumberLetter'] = $properties['aoa.huisletter'];
        isset($properties['aoa.huisnummertoevoeging']) === false ?: $correspondence['houseNumberAddition'] = $properties['aoa.huisletter'];

        if(isset($properties['communicatietype']) === true && in_array($properties['communicatietype'], ['EMAIL', 'POST'])) {
            $correspondence['communicationType'] = $properties['communicatietype'];
        }

        return $correspondence;
    }

    public function getDeceasedObject(array $properties): array
    {
        $deceased = [
            'bsn' => $properties['inp.bsn'] ?? null,
            'firstname' => $properties['voornamen'] ?? null,
            'prefix' => $properties['voorvoegselGeslachtsnaam'] ?? null,
            'lastname' => $properties['geslachtsnaam'] ?? null,
            'birthdate' => $properties['geboortedatum'] ?? null,
        ];

        return $deceased;
    }
    
    public function getFuneralServices(array $properties): array
    {
        $funeralServices = [
            'outsideBenelux' => $properties['buitenbenelux'] === 'True',
            'countryOfDestination' => isset($properties['landcode']) ? ['code' => $properties['landcode']] : null,
            'placeOfDestination' => $properties['plaatsbest'] ?? null,
            'via' => $properties['viabest'] ?? null,
            'transportation' => $properties['voertuigbest'] ?? null,
        ];

        if(isset($properties['type']) === true && in_array($properties['type'], ['BURIAL_CREMATION', 'DISSECTION'])) {
            $funeralServices['serviceType'] = $properties['type'];
        }

        if(isset($properties['datum']) === true) {
            $date = new DateTime($properties['date']);
            $funeralServices['date'] = $date->format('Y-m-d');
        } else if (isset($properties['datumuitvaart']) === true) {
            $date = new DateTime($properties['datumuitvaart']);
            $funeralServices['date'] = $date->format('Y-m-d');
        }
        if(isset($properties['tijduitvaart'])) {
            $time = new DateTime($properties['tijduitvaart']);
            $funeralServices['time'] = $time->format('H:i');
        }

        return $funeralServices;
    }

    public function getExtracts(array $properties): array
    {
        $extracts = [];
        $index = 1;
        while(isset($properties['code'.$index])) {
            $extracts[] = [
                'code' => $properties['code'.$index],
                'amount' => (int) $properties['amount'.$index] ?? 1,
            ];
            $index++;
        }

        return $extracts;
    }

    public function getDeathProperties(ObjectEntity $object, array $objectArray, bool &$foundBody): array
    {
        $caseProperties = $this->getZaakEigenschappen($object, ['all']);

        $objectArray['deceased'] = $this->getDeceasedObject($caseProperties);
        $objectArray['deathByNaturalCauses'] = $caseProperties['natdood'] === "True";
        $objectArray['municipality']['code'] = $caseProperties['gemeentecode'] ?? null;
        if(isset($caseProperties['datumoverlijden']) === true) {
            $datum = new DateTime($caseProperties['datumoverlijden']);
            $objectArray['dateOfDeath'] = $datum->format('Y-m-d');
        }
        if(isset($caseProperties['datumlijkvinding']) === true) {
            $datum = new DateTime($caseProperties['datumlijkvinding']);
            $objectArray['dateOfFinding'] = $datum->format('Y-m-d');
        }
        if(isset($caseProperties['tijdoverlijden']) === true) {
            $datum = new DateTime($caseProperties['tijdoverlijden']);
            $objectArray['timeOfDeath'] = $datum->format('H:i');
        }
        if(isset($caseProperties['tijdlijkvinding']) === true) {
            $datum = new DateTime($caseProperties['tijdlijkvinding']);
            $objectArray['timeOfFinding'] = $datum->format('H:i');
        }
        $objectArray['correspondence'] = $this->getCorrespondence($caseProperties);
        $objectArray['extracts'] = $this->getExtracts($caseProperties);


        if(isset($caseProperties['aangevertype'])) {
            $foundBody = true;
        }

        return $objectArray;
    }

    public function zgwToVrijbrpHandler(array $data, array $configuration): array
    {
        $this->logger->info('Converting ZGW object to VrijBRP');
        $this->configuration = $configuration;
        $this->data = $data;

        $source = $this->getSource($configuration['source']);
        $mapping = $this->getMapping($configuration['mapping']);
        $synchronizationEntity = $this->getSynchronizationEntity($configuration['synchronizationEntity']);
        if ($source === null
            || $mapping === null
            || $synchronizationEntity === null
        ) {
            return [];
        }

        $dataId = $data['object']['_self']['id'];


        $object = $this->entityManager->getRepository('App:ObjectEntity')->find($dataId);
        $this->logger->debug("(Zaak) Object with id $dataId was created");

        $objectArray = $object->toArray();

        // Do mapping with Zaak ObjectEntity as array.
        $objectArray = $this->mappingService->mapping($mapping, $objectArray);

        $foundBody = false;
        $objectArray = $this->getDeathProperties($object, $objectArray, $foundBody);

        // Create synchronization.
        $this->zgwToVrijbrpService->getSynchronization($object, $source, $synchronizationEntity, $mapping);

        $this->logger->debug("Synchronize (Zaak) Object to: {$source->getLocation()}{$this->configuration['location']}");
        $synchronization = $this->zgwToVrijbrpService->getSynchronization($object, $source, $synchronizationEntity, $mapping);
        // Todo: change synchronize function so it can also push to a source and not only pull from a source:
        // $this->syncService->synchronize($synchronization, $objectArray);

        // Todo: temp way of doing this without updated synchronize() function...
        if ($this->zgwToVrijbrpService->synchronizeTemp($synchronization, $objectArray, $foundBody === true ? $this->configuration['foundBodyLocation'] : $this->configuration['inMunicipalityLocation']) === [] &&
            isset($this->symfonyStyle) === true) {
            // Return empty array on error for when we got here through a command.
            return [];
        }

        return $data;
    }//end zgwToVrijbrpHandler()
}
