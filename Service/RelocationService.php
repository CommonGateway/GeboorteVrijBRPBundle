<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class RelocationService
{
    private ZgwToVrijbrpService $zgwToVrijbrpService;
    private MappingService $mappingService;
    private LoggerInterface $logger;
    private EntityManagerInterface $entityManager;

    private array $data;
    private array $configuration;

    public function __construct(MappingService $mappingService, ZgwToVrijbrpService $zgwToVrijbrpService, LoggerInterface $actionLogger, EntityManagerInterface $entityManager)
    {
        $this->mappingService = $mappingService;
        $this->zgwToVrijbrpService = $zgwToVrijbrpService;
        $this->logger = $actionLogger;
        $this->entityManager = $entityManager;
    }

    /**
     * This function gets the relocators from the zgwZaak with the given properties (simXml elementen and Stuf extraElementen).
     *
     * @param array $zaakEigenschappen The zaak eigenschappen.
     *
     * @return array relocators
     */
    public function getRelocators(array $zaakEigenschappen): array
    {
        $relocators = [];
        $relocators[] = [
            'bsn'             => $zaakEigenschappen['BSN'],
            'declarationType' => 'REGISTERED',
        ];

        if (isset($zaakEigenschappen['MEEVERHUIZENDE_GEZINSLEDEN.MEEVERHUIZEND_GEZINSLID.BSN'])) {
            $relocator = ['bsn' => $zaakEigenschappen['MEEVERHUIZENDE_GEZINSLEDEN.MEEVERHUIZEND_GEZINSLID.BSN']];
            if (isset($zaakEigenschappen['MEEVERHUIZENDE_GEZINSLEDEN.MEEVERHUIZEND_GEZINSLID.ROL'])) {
                switch($zaakEigenschappen['MEEVERHUIZENDE_GEZINSLEDEN.MEEVERHUIZEND_GEZINSLID.ROL']) {
                    case 'I':
                        $relocator['declarationType'] = 'REGISTERED';
                        break;
                    case 'G':
                        $relocator['declarationType'] = 'AUTHORITY_HOLDER';
                        break;
                    case 'K':
                        $relocator['declarationType'] = 'ADULT_CHILD_LIVING_WITH_PARENTS';
                        break;
                    case 'M':
                        $relocator['declarationType'] = 'ADULT_AUTHORIZED_REPRESENTATIVE';
                        break;
                    case 'P':
                        $relocator['declarationType'] = 'PARTNER';
                        break;
                    case 'O':
                        $relocator['declarationType'] = 'PARENT_LIVING_WITH_ADULT_CHILD';
                        break;
                    default:
                        $relocator['declarationType'] = 'REGISTERED';
                        break;

                }
            }

            $relocators[] = $relocator;

            return $relocators;
        } // end if

        $index = 0;
        while (isset($zaakEigenschappen["MEEVERHUIZENDE_GEZINSLEDEN.MEEVERHUIZEND_GEZINSLID.$index.BSN"])) {
            $relocator = ['bsn' => $zaakEigenschappen["MEEVERHUIZENDE_GEZINSLEDEN.MEEVERHUIZEND_GEZINSLID.$index.BSN"]];
            if (isset($zaakEigenschappen["MEEVERHUIZENDE_GEZINSLEDEN.MEEVERHUIZEND_GEZINSLID.$index.ROL"])) {
                switch ($zaakEigenschappen["MEEVERHUIZENDE_GEZINSLEDEN.MEEVERHUIZEND_GEZINSLID.$index.ROL"]) {
                    case 'I':
                        $relocator['declarationType'] = 'REGISTERED';
                        break;
                    case 'G':
                        $relocator['declarationType'] = 'AUTHORITY_HOLDER';
                        break;
                    case 'K':
                        $relocator['declarationType'] = 'ADULT_CHILD_LIVING_WITH_PARENTS';
                        break;
                    case 'M':
                        $relocator['declarationType'] = 'ADULT_AUTHORIZED_REPRESENTATIVE';
                        break;
                    case 'P':
                        $relocator['declarationType'] = 'PARTNER';
                        break;
                    case 'O':
                        $relocator['declarationType'] = 'PARENT_LIVING_WITH_ADULT_CHILD';
                        break;
                    default:
                        $relocator['declarationType'] = 'REGISTERED';
                        break;

                }
            }
            $relocators[] = $relocator;
            $index++;
        } // end while

        return $relocators;
    } //end getMeeEmigranten()

    public function getRelocationProperties(ObjectEntity $object, array $objectArray, string $gemeenteCode, string &$interOrIntra): array
    {
        $caseProperties = $this->zgwToVrijbrpService->getZaakEigenschappen($object, ['all']);

        $objectArray['newAddress']['street'] = $caseProperties['STRAATNAAM_NIEUW'];
        $objectArray['newAddress']['houseNumber'] = $caseProperties['HUISNUMMER_NIEUW'];
        $objectArray['newAddress']['houseLetter'] = $caseProperties['HUISLETTER_NIEUW'];
        $objectArray['newAddress']['houseNumberAddition'] = $caseProperties['TOEVOEGINGHUISNUMMER_NIEUW'];
        $objectArray['newAddress']['postalCode'] = $caseProperties['POSTCODE_NIEUW'];
        $objectArray['newAddress']['residence'] = $caseProperties['WOONPLAATS_NIEUW'];
        $objectArray['newAddress']['addressFunction'] = 'LIVING_ADDRESS'; // @TODO cant make a difference yet between LIVING or MAILING ADDRESS
        $objectArray['newAddress']['numberOfResidents'] = $caseProperties['AANTAL_PERS_NIEUW_ADRES'];
        $objectArray['newAddress']['destinationCurrentResidents'] = 'Unknown';
        $objectArray['newAddress']['liveIn'] = ['liveInApplicable' => false];
        $objectArray['declarant'] = $objectArray['newAddress']['mainOccupant'] = [
            'bsn'                => $caseProperties['BSN'],
            'contactInformation' => [
                'email'           => $caseProperties['EMAILADRES'],
                'telephoneNumber' => $caseProperties['TELEFOONNUMMER'],
            ],
        ];

        if(isset($caseProperties['BSN_HOOFDBEWONER']) === true) {
            $objectArray['newAddress']['mainOccupant'] = [
                'bsn' => $caseProperties['BSN_HOOFDBEWONER'],
            ];
            $objectArray['newAddress']['liveIn'] = ['liveInApplicable' => true];
        }

        $relocators = $this->getRelocators($caseProperties);
        $objectArray['relocators'] = $relocators;

        // if GEMEENTECODE is not the configured gemeentecode this is a inter relocation
        if (isset($caseProperties['GEMEENTECODE']) && $caseProperties['GEMEENTECODE'] !== $gemeenteCode) {
            $objectArray['previousMunicipality'] = [
                'code' => $caseProperties['GEMEENTECODE'],
            ];
            $interOrIntra = 'inter';
        }// end if

        return $objectArray;
    }

    public function zgwToVrijbrpHandler(array $data, array $configuration): array
    {
        $this->logger->info('Converting ZGW object to VrijBRP');
        $this->configuration = $configuration;
        $this->data = $data;

        if (!isset($configuration['gemeenteCode'])) {
            $this->logger->error('gemeenteCode not set in ZgwToVrijbrpRelocationAction configuration.');

            return [];
        }

        $source = $this->zgwToVrijbrpService->getSource($configuration['source']);
        $mapping = $this->zgwToVrijbrpService->getMapping($configuration['mapping']);
        $synchronizationEntity = $this->zgwToVrijbrpService->getEntity($configuration['synchronizationEntity']);
        if (
            $source === null
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

        $interOrIntra = 'intra';
        $objectArray = $this->getRelocationProperties($object, $objectArray, $configuration['gemeenteCode'], $interOrIntra);

        // Create synchronization.
        $synchronization = $this->zgwToVrijbrpService->getSynchronization($object, $source, $synchronizationEntity, $mapping);

        // @TODO different endpoints for inter or intra relocation
        $this->logger->debug("Synchronize (Zaak) Object to: {$source->getLocation()}".($interOrIntra == 'inter' ? $this->configuration['interLocation'] : $this->configuration['intraLocation']));
        // Todo: change synchronize function so it can also push to a source and not only pull from a source:
        // $this->syncService->synchronize($synchronization, $objectArray);

        // Todo: temp way of doing this without updated synchronize() function...
        if ($this->zgwToVrijbrpService->synchronizeTemp($synchronization, $objectArray, $interOrIntra == 'inter' ? $this->configuration['interLocation'] : $this->configuration['intraLocation']) === []) {
            // Return empty array on error for when we got here through a command.
            return [];
        }

        return $data;
    } //end zgwToVrijbrpHandler()
}
