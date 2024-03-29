<?php

/**
 * Callback APIs: Response and status check
 *
 * @author  Michel Abdo <michel.f.abdo@gmail.com>
 * @license https://framework.zend.com/license  New BSD License
 */

namespace Skash\SkashPayment\Api\Skash;

/**
 * Callback APIs
 */
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
     * @return \Magento\Framework\Controller\Result\Json
     */
//    public function response(
//        $transaction_id,
//        $status,
//        $timestamp,
//        $merchant_id,
//        $amount,
//        $currency,
//        $secure_hash
//    );


    /**
     * Checks if the order status changed
     *
     * @api
     *
     * @param string $order_id Order Id
     *
     * @return array[]
     */
    public function status_check($order_id);


}//end interface
