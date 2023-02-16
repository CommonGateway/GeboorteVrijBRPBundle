<?php

namespace CommonGateway\GeboorteVrijBRPBundle\Service;

use App\Entity\ObjectEntity;
use EasyRdf\Literal\Date;

class DeceasementService
{
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
            $date = new \DateTime($properties['date']);
            $funeralServices['date'] = $date->format('Y-m-d');
        } else if (isset($properties['datumuitvaart']) === true) {
            $date = new \DateTime($properties['datumuitvaart']);
            $funeralServices['date'] = $date->format('Y-m-d');
        }
        if(isset($properties['tijduitvaart'])) {
            $time = new \DateTime($properties['tijduitvaart']);
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

    public function getDeathProperties(ObjectEntity $object, array $objectArray): array
    {
        $caseProperties = $this->getZaakEigenschappen($object, ['all']);

        $objectArray['deceased'] = $this->getDeceasedObject($caseProperties);
        $objectArray['deathByNaturalCauses'] = $caseProperties['natdood'] === "True";
        $objectArray['municipality']['code'] = $caseProperties['gemeentecode'] ?? null;
        if(isset($caseProperties['datumoverlijden']) === true) {
            $datum = new \DateTime($caseProperties['datumoverlijden']);
            $objectArray['dateOfDeath'] = $datum->format('Y-m-d');
        }
        if(isset($caseProperties['datumlijkvinding']) === true) {
            $datum = new \DateTime($caseProperties['datumlijkvinding']);
            $objectArray['dateOfFinding'] = $datum->format('Y-m-d');
        }
        if(isset($caseProperties['tijdoverlijden']) === true) {
            $datum = new \DateTime($caseProperties['tijdoverlijden']);
            $objectArray['timeOfDeath'] = $datum->format('H:i');
        }
        if(isset($caseProperties['tijdlijkvinding']) === true) {
            $datum = new \DateTime($caseProperties['tijdlijkvinding']);
            $objectArray['timeOfFinding'] = $datum->format('H:i');
        }
        $objectArray['correspondence'] = $this->getCorrespondence($caseProperties);
        $objectArray['extracts'] = $this->getExtracts($caseProperties);

        return $objectArray;
    }
}
