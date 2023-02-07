<?php

namespace CommonGateway\GeboorteVrijBRPBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use CommonGateway\GeboorteVrijBRPBundle\Service\CatalogiService;
use CommonGateway\GeboorteVrijBRPBundle\Service\ZdsToZgwService;

/**
 * Haalt applications op van de componenten catalogus.
 */
class ZdsZaakActionHandler implements ActionHandlerInterface
{

    private ZdsToZgwService $zdsToZgwService;

    public function __construct(ZdsToZgwService $zdsToZgwService)
    {
        $this->zdsToZgwService = $zdsToZgwService;
    }

    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @throws array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'        => 'https://opencatalogi.nl/vrijbrp.zds.creerzaak.schema.json',
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => 'ExampleActionHandler',
            'description'=> 'This is a action to create objects from the fetched applications from the componenten catalogus.',
        ];
    }

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
        return $this->zdsToZgwService->zaakActionHandler($data, $configuration);
    }
}
