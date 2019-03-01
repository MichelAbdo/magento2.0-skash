<?php
namespace Skash\SkashPayment\Api\Skash;
/**
 * https://inchoo.net/magento-2/magento-2-custom-api/
 */
// @todo change documentation

interface CallbackInterface
{
    /**
     * Updates the order status if the sKash transaction is successful
     *
     * @api
     *
     * @param string $transaction_id Transaction Id
     * @param string $status         Transaction Status
     * @param string $timestamp      Transaction Timestamp
     * @param string $merchant_id    Merchant Id
     * @param string $amount         Transaction Amount
     * @param string $currency       Transaction Currency
     * @param string $secure_hash    Secure Hash
     *
     * @return boolean Success or failure
     */
	public function response($transaction_id, $status, $timestamp, $merchant_id, $amount, $currency, $secure_hash);
}
