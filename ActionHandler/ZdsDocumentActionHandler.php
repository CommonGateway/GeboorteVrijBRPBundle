<?php

namespace CommonGateway\GeboorteVrijBRPBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\GeboorteVrijBRPBundle\Service\ZdsToZgwService;

/**
 * Haalt applications op van de componenten catalogus.
 */
class ZdsDocumentActionHandler implements ActionHandlerInterface
{


    /**
     * @var ZdsToZgwService
     */
    private ZdsToZgwService $zdsToZgwService;

    /**
     * @param ZdsToZgwService $zdsToZgwService The ZdsToZgwService
     */
    public function __construct(ZdsToZgwService $zdsToZgwService)
    {
        $this->zdsToZgwService = $zdsToZgwService;

    }//end __construct()


    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
                '$id'         => 'https://opencatalogi.nl/vrijbrp.zds.creerdocument.schema.json',
                '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
                'title'       => 'ExampleActionHandler',
                'description' => 'This is a action to create objects from the fetched applications from the componenten catalogus.',
               ];

    }//end getConfiguration()


    /**
     * This function runs the application to gateway service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->zdsToZgwService->documentActionHandler($data, $configuration);

    }//end run()


}
