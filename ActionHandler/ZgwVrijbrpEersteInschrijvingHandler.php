<?php

namespace CommonGateway\GeboorteVrijBRPBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\GeboorteVrijBRPBundle\Service\EersteInschrijvingService;
use Exception;

/**
 * This ActionHandler handles the mapping and sending of eerste inschrijving to the Vrijbrp api with a corresponding Service.
 *
 * @author Barry Brands <barry@conduction.nl>
 */
class ZgwVrijbrpEersteInschrijvingHandler implements ActionHandlerInterface
{
    /**
     * @var EersteInschrijvingService The VrijbrpEersteInschrijvingService that will handle code for this Handler.
     */
    private EersteInschrijvingService $eersteInschrijvingService;

    /**
     * Construct a VrijbrpEersteInschrijvingHandler.
     *
     * @param EersteInschrijvingService $eersteInschrijvingService The EersteInschrijvingService that will handle code for this Handler.
     */
    public function __construct(EersteInschrijvingService $eersteInschrijvingService)
    {
        $this->eersteInschrijvingService = $eersteInschrijvingService;
    }//end __construct()

    /**
     * This function returns the requered configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://vrijbrp.nl/vrijbrp.zaak.handler.json',
            '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
            'title'       => 'VrijbrpEersteInschrijvingHandler',
            'description' => 'This handler syncs EersteInschrijving to VrijBrp',
            'required'    => ['source', 'location', 'mapping', 'zaakEntity'],
            'properties'  => [
                'source' => [
                    'type'        => 'string',
                    'description' => 'The location of the Source we will send a request to, location of an existing Source object',
                    'example'     => 'https://vrijbrp.nl/dossiers',
                    'required'    => true,
                    '$ref'        => 'https://commongroundgateway.nl/commongroundgateway.gateway.entity.json',
                ],
                'location' => [
                    'type'        => 'string',
                    'description' => 'The endpoint we will use on the Source to send a request, just a string',
                    'example'     => '/api/births',
                    'required'    => true,
                ],
                'mapping' => [
                    'type'        => 'string',
                    'description' => 'The reference of the mapping we will use before sending the data to the source',
                    'example'     => 'https://vrijbrp.nl/mapping/vrijbrp.Vrijbrp.mapping.json',
                    'required'    => true,
                    '$ref'        => 'https://commongroundgateway.nl/commongroundgateway.mapping.entity.json',
                ],
                'synchronizationEntity' => [
                    'type'        => 'string',
                    'description' => 'The reference of the entity we use as trigger for this handler, we need this to find a synchronization object',
                    'example'     => 'https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json',
                    'required'    => true,
                    '$ref'        => 'https://commongroundgateway.nl/commongroundgateway.entity.entity.json',
                ],
            ],
        ];
    }//end getConfiguration()

    /**
     * This function will call the handler function to the corresponding service of this Handler.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration from the call
     *
     * @throws Exception
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->eersteInschrijvingService->vrijbrpHandler($data, $configuration);
    }//end run()
}
