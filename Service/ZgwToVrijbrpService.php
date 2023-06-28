<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use Adbar\Dot;
use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Service\SynchronizationService;
use Cassandra\Date;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\MappingService;
use DateTime;
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
        $this->source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['reference' => $this->configuration['source']]);
        if ($this->source instanceof Source === false) {
            if (isset($this->symfonyStyle) === true) {
                $this->symfonyStyle->error("No source found with reference: {$this->configuration['source']}");
            }
            $this->logger->error("No source found with reference: {$this->configuration['source']}");
            
            return null;
        }
        
        return $this->source;
    } //end setSource()

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
     * Finds mapping by reference.
     *
     * @param string $reference The reference to look for.
     *
     * @return Mapping|null The resulting mapping.
     */
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
    
    /**
     * Finds source by reference.
     *
     * @param string $reference The reference to look a source for.
     *
     * @return Source|null The resulting source.
     */
    public function getSource(string $reference): ?Source
    {
        $source = $this->entityManager->getRepository('App:Gateway')->findOneBy(['reference' => $reference]);
        if ($source instanceof Source === false) {
            if (isset($this->symfonyStyle) === true) {
                $this->symfonyStyle->error("No source found with reference: $reference");
            }
            
            $this->logger->error("No source found with reference: $reference");
            return null;
        }
        
        return $source;
    }//end getSource()

    /**
     * Finds entity by reference.
     *
     * @param string $reference The reference to look for.
     *
     * @return Entity|null The resulting entity.
     */
    public function getEntity(string $reference): ?Entity
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

    /**
     * Maps zgw eigenschappen to vrijbrp mapping for a Commitment e-dienst.
     *
     * @param ObjectEntity $object The zgw case ObjectEntity.
     * @param array        $output The output data.
     *
     * @throws Exception
     *
     * @return array The updated output array.
     */
    private function getCommitmentProperties(ObjectEntity $object, array $output): array
    {
        $this->mappingLogger->info('Do additional mapping with case properties');

        $properties = ['verbintenisDatum', 'verbintenisTijd', 'verbintenisType', 'naam',
            'trouwboekje', 'naam1', 'naam2', 'verzorgdgem', ];
        $zaakEigenschappen = $this->getCommitmentZaakEigenschappen($object, $properties);

        // Partners Todo: make this a function?
        $output['partner1'] = $zaakEigenschappen['partner1'];
        $output['partner2'] = $zaakEigenschappen['partner2'];

//        if (isset($output['partner1']['nameAfterCommitment']['nameUseType']) === false) {
//            $output['partner1']['nameAfterCommitment']['nameUseType'] = 'N';
//        }
//        if (isset($output['partner2']['nameAfterCommitment']['nameUseType']) === false) {
//            $output['partner2']['nameAfterCommitment']['nameUseType'] = 'N';
//        }
//
        if (isset($output['partner1']['bsn']) === false) {
            $output['partner1']['bsn'] = $this->getZaakInitiatorValue($object, 'inpBsn');
        }
//
//        if (isset($output['partner2']['nameAfterCommitment']['title']) === false) {
//            $output['partner2']['nameAfterCommitment']['title'] = $this->getZaakInitiatorValue($object, 'voornamen');
//        }
//        if (isset($output['partner2']['nameAfterCommitment']['lastname']) === false) {
//            $output['partner2']['nameAfterCommitment']['lastname'] = $this->getZaakInitiatorValue($object, 'geslachtsnaam');
//            if (isset($output['partner2']['nameAfterCommitment']['prefix']) === false) {
//                $output['partner2']['nameAfterCommitment']['prefix'] = $this->getZaakInitiatorValue($object, 'voorvoegselGeslachtsnaam');
//            }
//        }

        // Todo: maybe check if all these $properties['key'] exist?
        $now = new DateTime();
        $output['planning']['intentionDate'] = $now->format('Y-m-d');

        // Planning. Todo: make this a function?
        if(isset($zaakEigenschappen['verbintenisDatum']) === true
            && $zaakEigenschappen['verbintenisDatum'] !== true
            && isset($zaakEigenschappen['verbintenisTijd']) === true
            && $zaakEigenschappen['verbintenisTijd'] !== null
        ) {
            $date = new DateTime($zaakEigenschappen['verbintenisDatum']);
            $time = new DateTime($zaakEigenschappen['verbintenisTijd']);
            $dateTime = new DateTime($date->format('Y-m-d\T').$time->format('H:i:s'));
            $output['planning']['commitmentDateTime'] = $dateTime->format('Y-m-d\TH:i:s');
        } else {

            $output['planning']['commitmentDateTime'] = $now->format('Y-m-d\TH:i:s');
        }

        if (in_array($zaakEigenschappen['verbintenisType'], ['MARRIAGE', 'GPS'])) {
            $output['planning']['commitmentType'] = $zaakEigenschappen['verbintenisType'];
        }

        // Location. Todo: make this a function?
        $output['location']['name'] = $zaakEigenschappen['naam'];
        $output['location']['aliases'][0] = $zaakEigenschappen['naam'];
        if (array_key_exists('trouwboekje', $zaakEigenschappen) === true && $zaakEigenschappen['trouwboekje'] == true) {
            $output['location']['options'][0] = [
                'name'    => 'trouwboekje',
                'value'   => 'true',
                'type'    => 'BOOLEAN',
                'aliases' => [
                    'trouwboekje',
                ],
            ];
        }

        if(isset($zaakEigenschappen['naam1'])) {
            $output['officials']['preferences'][0] = [
                'name'    => $zaakEigenschappen['naam1'],
                'aliases' => [
                    $zaakEigenschappen['naam1'],
                ],
            ];
        }
        if (isset($zaakEigenschappen['naam2'])) {
            $output['officials']['preferences'][1] = [
                'name'    => $zaakEigenschappen['naam2'],
                'aliases' => [
                    $zaakEigenschappen['naam2'],
                ],
            ];
        }

        // Witnesses.
        // Todo: See todo comments in the getCommitmentZaakEigenschappen() function !!!
        $output['witnesses'] = $zaakEigenschappen['witnesses'];
        $output['witnesses']['numberOfMunicipalWitnesses'] = intval($zaakEigenschappen['verzorgdgem']);

        $this->mappingLogger->info('Done with additional mapping');

        return $output;
    }//end getSpecificProperties()

    /**
     * This function gets a specific value from the case->rollen->betrokkeneIdentificatie->[$key] for a Commitment eDienst.
     * For the role with omschrijvingGeneriek = 'initiator' and betrokkeneType = 'natuurlijk_persoon'.
     *
     * @param ObjectEntity $zaakObjectEntity The zaak ObjectEntity.
     * @param string       $key              The key to get the value from.
     *
     * @return string|null The value or null.
     */
    private function getZaakInitiatorValue(ObjectEntity $zaakObjectEntity, string $key): ?string
    {
        foreach ($zaakObjectEntity->getValue('rollen') as $rol) {
            if ($rol->getValue('roltoelichting') == 'initiator' && $rol->getValue('betrokkeneType') == 'natuurlijk_persoon') {
                return $rol->getValue('betrokkeneIdentificatie')->toArray()[$key];
            }
        }

        return null;
    }

    /**
     * Maps zgw eigenschappen to vrijbrp mapping for a Birth e-dienst.
     *
     * @param array $zgw    The ZGW case
     * @param array $output The output data
     *
     * @throws Exception
     *
     * @return array The updated output array.
     */
    private function getBirthProperties(array $zgw, array $output): array
    {
        $this->mappingLogger->info('Do additional mapping with case properties');

        $properties = $this->getBirthZaakEigenschappen($zgw['eigenschappen']);
        $output['qualificationForDeclaringType'] = $properties['relatie'] ?? null;

        if (isset($properties['sub.telefoonnummer'])) {
            $output['declarant']['contactInformation']['telephoneNumber'] = $properties['sub.telefoonnummer'];
        }
        if (isset($properties['sub.emailadres'])) {
            $output['declarant']['contactInformation']['email'] = $properties['sub.emailadres'];
        }

        if (isset($properties['inp.bsn']) === true && $properties['relatie'] !== 'MOTHER') {
            $output['mother']['bsn'] = $properties['inp.bsn'];
            $output['fatherDuoMother']['bsn'] = $output['declarant']['bsn'];
        } elseif (isset($properties['inp.bsn']) === true) {
            $output['mother']['bsn'] = $output['declarant']['bsn'];
            $output['fatherDuoMother']['bsn'] = $properties['inp.bsn'];
        } else {
            $output['mother']['bsn'] = $output['declarant']['bsn'];
            !isset($output['declarant']['contactInformation']) ?: $output['mother']['contactInformation'] = $output['declarant']['contactInformation'];
        }

        foreach ($properties['children'] as $key => $child) {
            $output['children'][$key]['firstname'] = $child['voornamen'];
            $output['children'][$key]['gender'] = $child['geslachtsaanduiding'];
            $birthDate = new DateTime($child['geboortedatum']);
            $birthTime = new DateTime($child['geboortetijd']);

            $output['children'][$key]['birthDateTime'] = $birthDate->format('Y-m-d').'T'.$birthTime->format('H:i:s');
        }

        $output['children'] = array_values($output['children']);

        $output['nameSelection']['lastname'] = $properties['geslachtsnaam'];
        !isset($properties['voorvoegselGeslachtsnaam']) ?: $output['nameSelection']['prefix'] = $properties['voorvoegselGeslachtsnaam'];

        $this->mappingLogger->info('Done with additional mapping');

        return $output;
    }//end getSpecificProperties()

    /**
     * This function gets the zaakEigenschappen from the zgwZaak for a Commitment eDienst.
     *
     * @param ObjectEntity $zaakObjectEntity The zaak ObjectEntity.
     * @param array        $properties       The properties / eigenschappen we want to get.
     *
     * @return array zaakEigenschappen
     */
    private function getCommitmentZaakEigenschappen(ObjectEntity $zaakObjectEntity, array $properties): array
    {
        $zaakEigenschappen = [];
        $eigenschappen = $this->getZaakEigenschappen($zaakObjectEntity, ['all']);
        $tempCountForBsn = 1; // Todo: fix this bsn shizzle
        foreach ($zaakObjectEntity->getValue('eigenschappen') as $eigenschap) {
            switch ($eigenschap->getValue('naam')) {
                case 'inp.bsn':
                    // Todo: fix this bsn shizzle:
                    $this->getCommitmentPartnerEigenschap($zaakEigenschappen, ['bsn'], $eigenschap);
                    $tempCountForBsn++;
                    break;
                case 'sub.telefoonnummer':
                    $this->getCommitmentPartnerEigenschap($zaakEigenschappen, ['contactInformation', 'telephoneNumber'], $eigenschap);
                    break;
                case 'sub.emailadres':
                    $this->getCommitmentPartnerEigenschap($zaakEigenschappen, ['contactInformation', 'email'], $eigenschap);
                    break;
                case 'geselecteerdNaamgebruik':
                    if (isset($zaakEigenschappen['partner1']['nameAfterCommitment']['nameUseType']) === true) {
                        $zaakEigenschappen['partner2']['nameAfterCommitment']['nameUseType'] = $eigenschap->getValue('waarde');
        
                        break;
                    }
                    $zaakEigenschappen['partner1']['nameAfterCommitment']['nameUseType'] = $eigenschap->getValue('waarde');
                    break;
//                case 'voornamen':
//                    // Probably only for partner1.
//                    $this->getCommitmentPartnerEigenschap($zaakEigenschappen, ['nameAfterCommitment', 'title'], $eigenschap);
//                    break;
//                case 'voorvoegselGeslachtsnaam':
//                    // Probably only for partner1.
//                    $this->getCommitmentPartnerEigenschap($zaakEigenschappen, ['nameAfterCommitment', 'prefix'], $eigenschap);
//                    break;
//                case 'geslachtsnaam':
//                    // Probably only for partner1.
//                    $this->getCommitmentPartnerEigenschap($zaakEigenschappen, ['nameAfterCommitment', 'lastname'], $eigenschap);
//                    break;
                case 'bsn1':
                    $this->getCommitmentWitness($zaakEigenschappen, 1, $eigenschappen);
                    break;
                case 'bsn2':
                    $this->getCommitmentWitness($zaakEigenschappen, 2, $eigenschappen);
                    break;
                case 'bsn3':
                    $this->getCommitmentWitness($zaakEigenschappen, 3, $eigenschappen);
                    break;
                case 'bsn4':
                    $this->getCommitmentWitness($zaakEigenschappen, 4, $eigenschappen);
                    break;
                default:
                    if (in_array($eigenschap->getValue('naam'), $properties) || in_array('all', $properties)) {
                        $zaakEigenschappen[$eigenschap->getValue('naam')] = $eigenschap->getValue('waarde');
                    }
                    break;
            }
        }

        return $zaakEigenschappen;
    }//end getCommitmentZaakEigenschappen()

    /**
     * Adds a single Eigenschap value to the zaakEigenschappen array for Partner1 or Partner2 if it already is set for Partner1.
     *
     * @param array        $zaakEigenschappen Array of key value pairs of the zaakEigenschappen of a Case.
     * @param array        $keys              The keys we will check. Can be 1 or 2 strings used to check, used like this: [$key[0]][$key[1]].
     * @param ObjectEntity $eigenschap        An eigenschap ObjectEntity of a Case.
     *
     * @return void This function doesn't return anything.
     */
    private function getCommitmentPartnerEigenschap(array &$zaakEigenschappen, array $keys, ObjectEntity $eigenschap)
    {
        if (count($keys) > 2 || count($keys) === 0) {
            return;
        }
        if (count($keys) === 2) {
            if (isset($zaakEigenschappen['partner2'][$keys[0]][$keys[1]]) === true) {
                $zaakEigenschappen['partner1'][$keys[0]][$keys[1]] = $eigenschap->getValue('waarde');

                return;
            }
            $zaakEigenschappen['partner2'][$keys[0]][$keys[1]] = $eigenschap->getValue('waarde');

            return;
        }

        // If count($keys) == 1
        // Todo: This with only 1 key is only used for bsn, so could be removed if we don't use it for bsn anymore
        if (isset($zaakEigenschappen['partner2'][$keys[0]]) === true) {
            $zaakEigenschappen['partner1'][$keys[0]] = $eigenschap->getValue('waarde');

            return;
        }
        $zaakEigenschappen['partner2'][$keys[0]] = $eigenschap->getValue('waarde');
    }

    //end getCommitmentPartnerEigenschap()
    /**
     * Adds a single Witness to the zaakEigenschappen array.{
     * "title": "ZDSToZGWZaak",
     * "$id": "https://zds.nl/mapping/zds.zdsHeeftAlsInitiatorToRol.mapping.json",
     * "$schema": "https://json-schema.org/draft/2020-12/mapping",
     * "version": "0.0.6",
     * "passTrough": false,
     * "mapping": {
     * "betrokkeneType": "natuurlijk_persoon",
     * "roltype": "{{ map('https://zds.nl/mapping/zds.zdsHeeftAlsInitiatorToRolType.mapping.json', _context) | json_encode }}",
     * "roltoelichting": "initiator",
     * "betrokkeneIdentificatie.inpBsn": "ns2:gerelateerde.ns2:natuurlijkPersoon.ns3:inp&#46;bsn",
     * "betrokkeneIdentificatie.geslachtsnaam": "ns2:gerelateerde.ns2:natuurlijkPersoon.ns3:geslachtsnaam",
     * "betrokkeneIdentificatie.voorvoegselGeslachtsnaam": "ns2:gerelateerde.ns2:natuurlijkPersoon.ns3:voorvoegselgeslachtsnaam",
     * "betrokkeneIdentificatie.voorletters": "ns2:gerelateerde.ns2:natuurlijkPersoon.ns3:voorletters",
     * "betrokkeneIdentificatie.voornamen": "ns2:gerelateerde.ns2:natuurlijkPersoon.ns3:voornamen",
     * "betrokkeneIdentificatie.geslachtaanduiding": "ns2:gerelateerde.ns2:natuurlijkPersoon.ns3:geslachtsaanduiding",
     * "betrokkeneIdentificatie.geboortedatum": "ns2:gerelateerde.ns2:natuurlijkPersoon.ns3:geboortedatum",
     * "betrokkeneIdentificatie.verblijfsadres.wplWoonplaatsNaam": "ns2:gerelateerde.ns2:natuurlijkPersoon.ns3:verblijfsadres.ns3:wpl&#46;woonplaatsnaam",
     * "betrokkeneIdentificatie.verblijfsadres.gorOpenbareRuimteNaam": "ns2:gerelateerde.ns2:natuurlijkPersoon.ns3:verblijfsadres.ns3:gor&#46;openbareruimtenaam",
     * "betrokkeneIdentificatie.verblijfsadres.aoaPostcode": "ns2:gerelateerde.ns2:natuurlijkPersoon.ns3:verblijfsadres.ns3:aoa&#46;postcode",
     * "betrokkeneIdentificatie.verblijfsadres.aoaHuisnummer": "ns2:gerelateerde.ns2:natuurlijkPersoon.ns3:verblijfsadres.ns3:aoa&#46;huisnummer",
     * "betrokkeneIdentificatie.verblijfsadres.aoaHuisletter": "ns2:gerelateerde.ns2:natuurlijkPersoon.ns3:verblijfsadres.ns3:aoa&#46;huisletter",
     * "betrokkeneIdentificatie.verblijfsadres.aoaHuisnummertoevoeging": "ns2:gerelateerde.ns2:natuurlijkPersoon.ns3:verblijfsadres.ns3:aoa&#46;huisnummertoevoeging"
     * },
     * "cast": {
     * "roltype": "jsonToArray",
     * "betrokkeneIdentificatie.inpBsn": "keyCantBeValue",
     * "betrokkeneIdentificatie.geslachtsnaam": "keyCantBeValue",
     * "betrokkeneIdentificatie.voornamen": "keyCantBeValue",
     * "betrokkeneIdentificatie.voorvoegselGeslachtsnaam": "keyCantBeValue"
     * }
     * }
     * Will use $number to find the correct data for this witness.
     *
     * @param array        $zaakEigenschappen Array of key value pairs of the zaakEigenschappen of a Case.
     * @param int          $number            Number of the witness, used to get the correct keys.
     * @param ObjectEntity $zaakObjectEntity  The zaak ObjectEntity.
     *
     * @return void This function doesn't return anything.
     */
    private function getCommitmentWitness(array &$zaakEigenschappen, int $number, array $eigenschappen): void
    {
        // Todo: $zaakObjectEntity->getValue('eigenschappen')->get("bsn$number") Does not work like this, but you get the idea :P
        // Todo: do an if key exists check for voornamen1 etc?

        $zaakEigenschappen['witnesses']['chosen'][] = [
            'bsn'       => $eigenschappen["bsn$number"],
            'firstname' => $eigenschappen["voornamen$number"],
            'prefix'    => $eigenschappen["voorvoegselGeslachtsnaam$number"],
            'lastname'  => $eigenschappen["geslachtsnaam$number"],
            'birthdate' => $eigenschappen["geboortedatum$number"],
        ];
    }//end getCommitmentPartnerEigenschap()

    /**
     * Converts ZGW eigenschappen to key, value pairs for the Birth eDienst.
     *
     * @param array $eigenschappen The properties of the case
     *
     * @return array
     */
    private function getBirthZaakEigenschappen(array $eigenschappen): array
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
            case 'B0337':
                $objectArray = $this->getCommitmentProperties($object, $objectArray);
                break;
            default:
                return [];
        }

        var_dump($objectArray);die;

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
        if ($this->synchronizeTemp($synchronization, $objectArray, $this->configuration['location']) === [] &&
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

        if (!isset($data['documents'])) {
            return $data;
        }

        foreach ($data['documents'] as $document) {
            $dataId = $document['_self']['id'];

            $this->logger->debug("(Document) Object with id $dataId was created");

            $object = $this->entityManager->getRepository('App:ObjectEntity')->find($dataId);

            // Do mapping with Document ObjectEntity as array.
            $objectArray = $this->mappingService->mapping($this->mapping, $object->toArray());

            // todo: make this a switch (in a function?) or something when merging all Vrijbrp Bundles:
            $configuration['location'] = $this->configuration['location'].'/'.$objectArray['dossierId'].'/documents';
            unset($objectArray['dossierId']);

            // Create synchronization.
            $synchronization = $this->syncService->findSyncByObject($object, $this->source, $this->synchronizationEntity);
            $synchronization->setMapping($this->mapping);

            // Send request to source.
            $this->logger->debug("Synchronize (Document) Object to: {$this->source->getLocation()}{$configuration['location']}");

            // Todo: change synchronize function so it can also push to a source and not only pull from a source:
            // $this->syncService->synchronize($synchronization, $objectArray);

            // Todo: temp way of doing this without updated synchronize() function...
            if ($this->synchronizeTemp($synchronization, $objectArray, $configuration['location']) === [] &&
                isset($this->symfonyStyle) === true) {
                // Return empty array on error for when we got here through a command.
                return [];
            }
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
    public function synchronizeTemp(Synchronization $synchronization, array $objectArray, string $location): array
    {
        $objectString = $this->syncService->getObjectString($objectArray);

        $this->logger->info('Sending message with body '.$objectString);

        try {
            $result = $this->callService->call(
                $synchronization->getSource(),
                $location,
                'POST',
                [
                    'body'    => $objectString,
                    //'query'   => [],
                    'headers' => $synchronization->getSource()->getHeaders(),
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
            $this->logger->error('Could not synchronize object. Error message: '.$exception->getMessage().'\nFull Response'.($exception instanceof ServerException || $exception instanceof ClientException || $exception instanceof RequestException === true ? $exception->getResponse()->getBody() : ''));

            return [];
        }//end try

        $body = $this->callService->decodeResponse($synchronization->getSource(), $result);

        $bodyDot = new Dot($body);
        $now = new DateTime();
        $synchronization->setLastSynced($now);
        $synchronization->setSourceLastChanged($now);
        $synchronization->setLastChecked($now);
        $synchronization->setHash(hash('sha384', serialize($bodyDot->jsonSerialize())));

        return $body;
    }//end synchronizeTemp()

    public function getSynchronization(ObjectEntity $object, Source $source, Entity $synchronizationEntity, ?Mapping $mapping = null): Synchronization
    {
        $synchronization = $this->syncService->findSyncByObject($object, $source, $synchronizationEntity);
        isset($mapping) && $synchronization->setMapping($mapping);

        return $synchronization;
    }
}
