<?php
/**
 * Response Validator is a component of the Magento payment provider gateway that performs gateway response verification.
 * This may include low level data formatting, security verification, and even execution of some business logic required by the store configuration.
 * Response Validator returns a Result object, containing validation result as Boolean value and errors description as a list of Phrase.
 * https://devdocs.magento.com/guides/v2.0/payments-integrations/payment-gateway/response-validator.html
 *
 * Author: Michel Abdo <michel.f.abdo@gmail.com>
 */
namespace Skash\SkashPaymentGateway\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
// @todo: client mock
use Skash\SkashPaymentGateway\Gateway\Http\Client\ClientMock;

class ResponseCodeValidator extends AbstractValidator
{
    const RESULT_CODE = 'RESULT_CODE';

    /**
     * Performs validation of result code
     *
     * @param array $validationSubject
     * @return ResultInterface
     */
    public function validate(array $validationSubject)
    {
        if (!isset($validationSubject['response']) || !is_array($validationSubject['response'])) {
            throw new \InvalidArgumentException('Response does not exist');
        }

        $response = $validationSubject['response'];

        if ($this->isSuccessfulTransaction($response)) {
            return $this->createResult(
                true,
                []
            );
        } else {
            return $this->createResult(
                false,
                [__('Gateway rejected the transaction.')]
            );
        }
    }
    // public function validate(array $validationSubject)
    //     {
    //         $response = SubjectReader::readResponse($validationSubject);
    //         $paymentDO = SubjectReader::readPayment($validationSubject);

    //         $isValid = true;
    //         $fails = [];

    //         $statements = [
    //             [
    //                 $paymentDO->getOrder()->getCurrencyCode() === $response['authCurrency'],
    //                 __('Currency doesn\'t match.')
    //             ],
    //             [
    //                 sprintf(
    //                     '%.2F',
    //                     $paymentDO->getOrder()->getGrandTotalAmount()) === $response['authCost'],
    //                     __('Amount doesn\'t match.'
    //                 )
    //             ],
    //             [
    //                 in_array($response['authMode'], ['A', 'E']),
    //                 __('Not supported response.')
    //             ]
    //         ];

    //         foreach ($statements as $statementResult) {
    //             if (!$statementResult[0]) {
    //                 $isValid = false;
    //                 $fails[] = $statementResult[1];
    //             }
    //         }

    //         return $this->createResult($isValid, $fails);
    //     }
    // }
    /**
     * @param array $response
     * @return bool
     */
    private function isSuccessfulTransaction(array $response)
    {
        return isset($response[self::RESULT_CODE])
        && $response[self::RESULT_CODE] !== ClientMock::FAILURE;
    }
}
