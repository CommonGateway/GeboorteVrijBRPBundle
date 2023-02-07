<?php

namespace CommonGateway\OpenCatalogiBundle\ActionHandler;

use App\Exception\GatewayException;
use CommonGateway\GeboorteVrijBRPBundle\Service\ZgwToVrijbrpService;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Respect\Validation\Exceptions\ComponentException;

/**
 * This ActionHandler handles the mapping and sending of ZGW zaak data to the Vrijbrp api with a corresponding Service.
 * @author Wilco Louwerse <wilco@conduction.nl>
 */
class ZgwToVrijbrpHandler implements ActionHandlerInterface
{
    private ZgwToVrijbrpService $zgwToVrijbrpService;

    public function __construct(ZgwToVrijbrpService $zgwToVrijbrpService)
    {
        $this->zgwToVrijbrpService = $zgwToVrijbrpService;
    }

    /**
     *  This function returns the requered configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @throws array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://example.com/person.schema.json',
            '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
            'title'       => 'ZgwToVrijbrpHandler',
            'description' => 'This handler posts zaak eigenschappen from ZGW to VrijBrp',
            'required'   => ['source', 'location', 'mapping', 'conditionEntity'],
            'properties' => [
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
                    'example'     => 'https://vrijbrp.nl/mapping/vrijbrp.ZgwToVrijbrp.mapping.json',
                    'required'    => true,
                    '$ref'        => 'https://commongroundgateway.nl/commongroundgateway.mapping.entity.json',
                ],
                'conditionEntity' => [
                    'type'        => 'string',
                    'description' => 'The reference of the entity we use as trigger for this handler, we need this to find a synchronization object',
                    'example'     => 'https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json',
                    'required'    => true,
                    '$ref'        => 'https://commongroundgateway.nl/commongroundgateway.entity.entity.json',
                ],
            ],
        ];
    }
    
    /**
     * This function will call the handler function to the corresponding service of this Handler.
     *
     * @param array $data           The data from the call
     * @param array $configuration  The configuration from the call
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->zgwToVrijbrpService->zgwToVrijbrpHandler($data, $configuration);
    }
}
