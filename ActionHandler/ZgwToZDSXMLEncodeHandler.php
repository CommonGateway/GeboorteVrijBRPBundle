<?php

namespace CommonGateway\GeboorteVrijBRPBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\GeboorteVrijBRPBundle\Service\ZgwToVrijbrpService;


/**
 * An action handler that does an xmlEncode on a proxy response after mapping returns json instead of xml. (temporary solution)
 * todo: add option to recognize we need to xml encode at the end of handleEndpointsConfigIn function in CoreBundle CallService.
 *
 * @author  Conduction.nl <info@conduction.nl>, Wilco Louwerse <wilco@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ZgwToZDSXMLEncodeHandler implements ActionHandlerInterface
{

    /**
     * The zgw to zds service used by the handler
     *
     * @var ZgwToVrijbrpService
     */
    private ZgwToVrijbrpService $service;


    /**
     * The constructor
     *
     * @param ZgwToVrijbrpService $service The zgw to zds service
     */
    public function __construct(ZgwToVrijbrpService $service)
    {
        $this->service = $service;

    }//end __construct()


    /**
     * Returns the required configuration as a https://json-schema.org array.
     *
     * @return array The configuration that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://example.com/ActionHandler/ZGWToZDSHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'ZGW Zaak to ZDS XmlEncode ActionHandler',
            'description' => 'ZGW Zaak to ZDS XmlEncode ActionHandler',
            'required'    => [],
            'properties'  => [],
        ];

    }//end getConfiguration()


    /**
     * This function runs the service.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     *
     * @SuppressWarnings("unused") Handlers ara strict implementations
     */
    public function run(array $data, array $configuration): array
    {

        return $this->service->zgwToZdsXmlEncodeHandler($data, $configuration);

    }//end run()


}//end class
