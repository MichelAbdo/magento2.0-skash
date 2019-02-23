<?php
/**
 * Transfer Factory allows to create transfer object with all data from request builders.
 * This object is then used by Gateway Client to process requests to payment processor.
 * https://devdocs.magento.com/guides/v2.0/payments-integrations/payment-gateway/gateway-client.html
 *
 * Author: Michel Abdo <michel.f.abdo@gmail.com>
 */
namespace Skash\SkashPaymentGateway\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Skash\SkashPaymentGateway\Gateway\Request\MockDataRequest;

class TransferFactory implements TransferFactoryInterface
{
    /**
     * @var TransferBuilder
     */
    private $transferBuilder;

    /**
     * @param TransferBuilder $transferBuilder
     */
    public function __construct(
        TransferBuilder $transferBuilder
    ) {
        $this->transferBuilder = $transferBuilder;
    }

    /**
     * Builds gateway transfer object
     *
     * @param array $request
     * @return TransferInterface
     */
    public function create(array $request)
    {
        return $this->transferBuilder
            ->setBody($request)
            ->setMethod('POST')
            ->setHeaders(
                [
                    'force_result' => isset($request[MockDataRequest::FORCE_RESULT])
                        ? $request[MockDataRequest::FORCE_RESULT]
                        : null
                ]
            )
            ->build();
    }

    /*
     * The ollowing is an example of a more complicated behavior.
     * Here transfer factory sets all required data to process requests using API credentials and all data is sent in JSON format.
    public function create(array $request)
    {
        return $this->transferBuilder
            ->setMethod(Curl::POST)
            ->setHeaders(['Content-Type' => 'application/json'])
            ->setBody(json_encode($request, JSON_UNESCAPED_SLASHES))
            ->setAuthUsername($this->getApiKey())
            ->setAuthPassword($this->getApiPassword())
            ->setUri($this->getUrl())
            ->build();
    }
    */
}
