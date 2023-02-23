<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use CommonGateway\CoreBundle\Service\MappingService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
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

    public function getCorrespondence($properties): array
    {
        $correspondence = [
            'name'         => $properties['contact.naam'] ?? null,
            'email'        => $properties['sub.emailadres'] ?? null,
            'organization' => $properties['handelsnaam'] ?? null,
            'houseNumber'  => isset($properties['aoa.huisnummer']) === true ? intval($properties['aoa.huisnummer']) : null,
            'postalCode'   => $properties['aoa.postcode'] ?? null,
            'residence'    => $properties['wpl.woonplaatsnaam'] ?? null,
            'street'       => $properties['gor.openbareRuimteNaam'] ?? null,
        ];
        isset($properties['aoa.huisletter']) === false ?: $correspondence['houseNumberLetter'] = $properties['aoa.huisletter'];
        isset($properties['aoa.huisnummertoevoeging']) === false ?: $correspondence['houseNumberAddition'] = $properties['aoa.huisletter'];

        if (isset($properties['communicatietype']) === true && in_array($properties['communicatietype'], ['EMAIL', 'POST'])) {
            $correspondence['communicationType'] = $properties['communicatietype'];
        }

        return $correspondence;
    }

    public function getDeceasedObject(array $properties): array
    {
        $deceased = [
            'bsn'       => $properties['inp.bsn'] ?? null,
            'firstname' => $properties['voornamen'] ?? null,
            'prefix'    => $properties['voorvoegselGeslachtsnaam'] ?? null,
            'lastname'  => $properties['geslachtsnaam'] ?? null,
            'birthdate' => $properties['geboortedatum'] ?? null,
        ];

        return $deceased;
    }

    public function getFuneralServices(array $properties): array
    {
        $funeralServices = [
            'outsideBenelux'       => $properties['buitenbenelux'] === 'True',
            'countryOfDestination' => isset($properties['landcode']) ? ['code' => $properties['landcode']] : null,
            'placeOfDestination'   => $properties['plaatsbest'] ?? null,
            'via'                  => $properties['viabest'] ?? null,
            'transportation'       => $properties['voertuigbest'] ?? null,
        ];

        if (isset($properties['type']) === true && in_array($properties['type'], ['BURIAL_CREMATION', 'DISSECTION'])) {
            $funeralServices['serviceType'] = $properties['type'];
        }

        if (isset($properties['datum']) === true) {
            $date = new DateTime($properties['datum']);
            $funeralServices['date'] = $date->format('Y-m-d');
        } elseif (isset($properties['datumuitvaart']) === true) {
            $date = new DateTime($properties['datumuitvaart']);
            $funeralServices['date'] = $date->format('Y-m-d');
        }
        if (isset($properties['tijduitvaart'])) {
            $time = new DateTime($properties['tijduitvaart']);
            $funeralServices['time'] = $time->format('H:i');
        }

        return $funeralServices;
    }

    public function getExtracts(array $properties): array
    {
        $extracts = [];
        $index = 1;
        while (isset($properties['code'.$index])) {
            $extracts[] = [
                'code'   => $properties['code'.$index],
                'amount' => (int) $properties['amount'.$index] ?? 1,
            ];
            $index++;
        }

        return $extracts;
    }

    public function getDeathProperties(ObjectEntity $object, array $objectArray, bool &$foundBody): array
    {
        $caseProperties = $this->zgwToVrijbrpService->getZaakEigenschappen($object, ['all']);

        $objectArray['deceased'] = $this->getDeceasedObject($caseProperties);
        $objectArray['deathByNaturalCauses'] = $caseProperties['natdood'] === 'True';
        $objectArray['municipality']['code'] = $caseProperties['gemeentecode'] ?? null;
        if (isset($caseProperties['datumoverlijden']) === true) {
            $datum = new DateTime($caseProperties['datumoverlijden']);
            $objectArray['dateOfDeath'] = $datum->format('Y-m-d');
        }
        if (isset($caseProperties['datumlijkvinding']) === true) {
            $datum = new DateTime($caseProperties['datumlijkvinding']);
            $objectArray['dateOfFinding'] = $datum->format('Y-m-d');
        }
        if (isset($caseProperties['tijdoverlijden']) === true) {
            $datum = new DateTime($caseProperties['tijdoverlijden']);
            $objectArray['timeOfDeath'] = $datum->format('H:i');
        }
        if (isset($caseProperties['tijdlijkvinding']) === true) {
            $datum = new DateTime($caseProperties['tijdlijkvinding']);
            $objectArray['timeOfFinding'] = $datum->format('H:i');
        }
        $objectArray['correspondence'] = $this->getCorrespondence($caseProperties);
        $objectArray['extracts'] = $this->getExtracts($caseProperties);
        $objectArray['funeralServices'] = $this->getFuneralServices($caseProperties);

        $objectArray['declarant']['bsn'] = $caseProperties['contact.inp.bsn'] ?? $objectArray['declarant']['bsn'];

        if (isset($caseProperties['aangevertype'])) {
            $foundBody = true;
            unset($objectArray['declarant']);
        }

        return $objectArray;
    }

    public function zgwToVrijbrpHandler(array $data, array $configuration): array
    {
        $this->logger->info('Converting ZGW object to VrijBRP');
        $this->configuration = $configuration;
        $this->data = $data;

        $source = $this->zgwToVrijbrpService->getSource($configuration['source']);
        $mapping = $this->zgwToVrijbrpService->getMapping($configuration['mapping']);
        $synchronizationEntity = $this->zgwToVrijbrpService->getEntity($configuration['synchronizationEntity']);
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
        $synchronization = $this->zgwToVrijbrpService->getSynchronization($object, $source, $synchronizationEntity, $mapping);

        $this->logger->debug("Synchronize (Zaak) Object to: {$source->getLocation()}".($foundBody === true ? $this->configuration['foundBodyLocation'] : $this->configuration['inMunicipalityLocation']));
        // Todo: change synchronize function so it can also push to a source and not only pull from a source:
        // $this->syncService->synchronize($synchronization, $objectArray);

        // Todo: temp way of doing this without updated synchronize() function...
        if ($this->zgwToVrijbrpService->synchronizeTemp($synchronization, $objectArray, $foundBody === true ? $this->configuration['foundBodyLocation'] : $this->configuration['inMunicipalityLocation']) === []) {
            // Return empty array on error for when we got here through a command.
            return [];
        }

        return $data;
    }//end zgwToVrijbrpHandler()
}
