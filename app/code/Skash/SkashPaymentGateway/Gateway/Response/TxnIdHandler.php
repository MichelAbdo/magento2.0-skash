<?php
/**
 * Response Handler is the component of Magento payment provider gateway, that processes payment provider response.
 * Typically, the response requires one of the following actions:
 *   Modify the order status
 *   Save information that was provided in a transaction response
 *   Send an email
 * The response handler only modifies the order state, based on the payment gateway response. It does not perform any other required actions.
 * https://devdocs.magento.com/guides/v2.0/payments-integrations/payment-gateway/response-handler.html
 *
 */
namespace Skash\SkashPaymentGateway\Gateway\Response;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;

class TxnIdHandler implements HandlerInterface
{
    const TXN_ID = 'TXN_ID';

    /**
     * Handles transaction id
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response)
    {
        if (!isset($handlingSubject['payment'])
            || !$handlingSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $handlingSubject['payment'];

        $payment = $paymentDO->getPayment();

        /** @var $payment \Magento\Sales\Model\Order\Payment */
        $payment->setTransactionId($response[self::TXN_ID]);
        $payment->setIsTransactionClosed(false);
    }
}
