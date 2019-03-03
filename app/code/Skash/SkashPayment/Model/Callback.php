<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 *
 */
namespace Skash\SkashPayment\Model;

use Skash\SkashPayment\Api\Skash\CallbackInterface;

use \Magento\Sales\Model\Order;
use \Magento\Framework\Model\Context;
use \Magento\Sales\Model\OrderFactory;
use \Skash\SkashPayment\Model\Skash as SKashFactory;
use \Magento\Paypal\Helper\Checkout;
use \Magento\Sales\Api\OrderManagementInterface;
use \Magento\Sales\Model\Service\InvoiceService;
use \Magento\Sales\Model\Order\Email\Sender\OrderSender;
use \Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use \Magento\Framework\Encryption\EncryptorInterface;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use \Magento\Framework\DB\Transaction as DbTransaction;
use \Magento\Framework\Controller\Result\JsonFactory;
use \Psr\Log\LoggerInterface;

class Callback implements CallbackInterface
{

	const PAYMENT_STATUS_REJECTED = 0;

	const PAYMENT_STATUS_APPROVED = 1;

	/**
	 * @var \Magento\Sales\Model\OrderFactory
	 */
	protected $_orderFactory;

	/**
	 * @var \Magento\Sales\Api\OrderRepositoryInterface
	 */
	protected $_orderManagement;

	/**
	 * @var \Magento\Sales\Model\Service\InvoiceService
	 */
	protected $_invoiceService;

	/**
	 * @var \Magento\Framework\DB\Transaction
	 */
	protected $_transaction;

	/**
	 * @var \Magento\Paypal\Helper\Checkout
	 */
	protected $_checkoutHelper;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $_logger;

	/**
	 * @var \Skash\SkashPayment\Model\Skash
	 */
	protected $_sKashFactory;

	/**
	 * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
	 */
	protected $_orderSender;

	/**
	 * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
	 */
	protected $_invoiceSender;

	/**
	 * @var \Magento\Framework\Encryption\EncryptorInterface
	 */
	protected $_encryptor;
	/**
	 * @var \Magento\Framework\Encryption\EncryptorInterface
	 */
	protected $_scopeConfig;

	/**
	* @var \Magento\Framework\Controller\Result\JsonFactory
	*/
	protected $_resultJsonFactory;

	/**
	 * @param \Magento\Framework\Model\Context 							$context
	 * @param \Magento\Sales\Model\OrderFactory 						$orderFactory
	 * @param \Skash\SkashPayment\Model\Skash 							$sKashFactory
	 * @param \Magento\Paypal\Helper\Checkout 							$checkoutHelper
	 * @param \Magento\Sales\Api\OrderManagementInterface 				$orderManagement
	 * @param \Magento\Sales\Model\Service\InvoiceService 				$invoiceService
	 * @param \Magento\Sales\Model\Order\Email\Sender\OrderSende 		$orderSender
	 * @param \Magento\Sales\Model\Order\Email\Sender\InvoiceSenderr	$invoiceSender
	 * @param \Magento\Framework\Encryption\EncryptorInterface 			$encryptor
	 * @param \Magento\Framework\App\Config\ScopeConfigInterface 		$scopeConfig
	 * @param \Magento\Framework\DB\Transaction 						$resultJsonFactory
	 * @param \Magento\Framework\Controller\Result\JsonFactory 			$dbTransaction
	 * @param \Psr\Log\LoggerInterface 									$logger
	 */
	public function __construct(
		Context $context,
		OrderFactory $orderFactory,
		SKashFactory $sKashFactory,
		Checkout $checkoutHelper,
		OrderManagementInterface $orderManagement,
		InvoiceService $invoiceService,
		OrderSender $orderSender,
		InvoiceSender $invoiceSender,
		EncryptorInterface $encryptor,
		ScopeConfigInterface $scopeConfig,
		JsonFactory $resultJsonFactory,
		DbTransaction $dbTransaction,
		LoggerInterface $logger
	) {
		$this->_orderFactory = $orderFactory;
		$this->_orderManagement = $orderManagement;
		$this->_orderSender = $orderSender;
		$this->_invoiceService = $invoiceService;
		$this->_invoiceSender = $invoiceSender;
		$this->_transaction = $dbTransaction;
		$this->_logger = $logger;
		$this->_sKashFactory = $sKashFactory;
		$this->_checkoutHelper = $checkoutHelper;
		$this->_encryptor = $encryptor;
		$this->_scopeConfig = $scopeConfig;
		$this->_resultJsonFactory = $resultJsonFactory;
	}

	/**
	 * Update the database order status if the transaction was succesfull
	 *
	 * @api
	 *
	 * @param string $transaction_id Transaction Id
	 * @param string $status		 Transaction Status
	 * @param string $timestamp      Transaction Timestamp
	 * @param string $merchant_id    Merchant Id
	 * @param string $amount		 Transaction Amount
	 * @param string $currency       Transaction Currency
	 * @param string $secure_hash    Secure Hash
	 *
	 * @return \Magento\Framework\Controller\Result\Json
	 */
	public function response(
		$transaction_id,
		$status,
		$timestamp,
		$merchant_id,
		$amount,
		$currency,
		$secure_hash
	) {
		if (empty($transaction_id) || empty($status)
			|| empty($timestamp) || empty($merchant_id)
			|| empty($amount) || empty($currency)
			|| empty($secure_hash)
		) {
			return [[
				'status' => 'error',
				'message' => 'Invalid / Empty Transaction Params.'
			]];
		}

		// Validate the status' value
		if (!$this->is_valid_status($status)) {
			return [[
				'status' => 'error',
				'message' => 'Invalid Transaction Status'
			]];
		}

		// Validate the transaction_id's value
		$order = $this->_orderFactory->create()->loadByIncrementId(
			$transaction_id
		);
		if (!$order || empty($order) || !$order->getRealOrderId()) {
			return [[
				'status' => 'error',
				'message' =>  "Order not found for transaction '$transaction_id'"
			]];
		}

		// Rejected Order
		if ($status == self::PAYMENT_STATUS_REJECTED) {
			$order->setState(Order::STATE_CANCELED);
			$order->setStatus(Order::STATE_CANCELED);
			$message = __('Skash Transaction Rejected.');
			$this->_orderManagement->cancel(
				$order->getEntityId()
			);
			$order->addStatusHistoryComment($message, "canceled / rejected")
				  ->setIsCustomerNotified(false)->save();
			return [[
				'status' => 'rejected',
				'message' => $message
			]];
		}

		if ($order->getStatus() !== Order::STATE_PENDING_PAYMENT) {
			// @todo: check why the json body is returned empty
			// https://www.brainacts.com/blog/how-to-return-a-json-response-from-a-controller-in-magento-2
			/** @var \Magento\Framework\Controller\Result\Json $resultJson */
			// $result = $this->_resultJsonFactory->create();
			// return $result->setData(array(
			// 	'status' => 'error',
			// 	'message' => 'Order already Updated.'
			// ));
			return [[
				'status' => __('error'),
				'message' => __('Order already Updated.')
			]];
		}

		$merchantId = $this->getMerchantId();
		$orderId = $order->getRealOrderId();
		$orderAmount = (double) $order->getBaseGrandTotal();
		$orderCurrency = $order->getBaseCurrencyCode();
		$orderTimestamp = strtotime($order->getCreatedAt());
		$orderHashData = $orderId . $status . $orderTimestamp . $merchantId . $orderAmount . $orderCurrency;
		$orderSecureHash = base64_encode(hash('sha512', $orderHashData, true));
		if ($secure_hash != $orderSecureHash) {
			$this->_logger->info('Invalid Transaction Params.');
			$this->_logger->debug('Invalid Transaction Params.');
			return json_encode(array(
				'status' => __('error'),
				'message' => __('Invalid Transaction Params.')
			));
		}

		if ($order->canInvoice()) {
			$invoice = $this->_invoiceService->prepareInvoice($order);
			$invoice->register();
			$invoice->save();
			$transactionSave = $this->_transaction->addObject(
				$invoice
			)->addObject(
				$invoice->getOrder()
			);
			$transactionSave->save();
			$order->setState(Order::STATE_PROCESSING);
			$order->setStatus(Order::STATE_PROCESSING);
			if ($invoice && !$order->getEmailSent()) {
				$this->_orderSender->send($order);
				$order->addStatusToHistory(Order::STATE_PROCESSING, null, true);
			}
			$order = $order->save();
		}
		$payment = $order->getPayment();
		$payment->setLastTransId($orderId);
		$payment->setTransactionId($orderId);
		$formatedPrice = $order->getBaseCurrency()->formatTxt($order->getGrandTotal());
		$message = __('The cuptured amount is %1.', $formatedPrice);
		$payment->setAdditionalInformation([
			\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => array(
				'StatusId' => $status,
				'Timestamp' =>  $orderTimestamp
			)
		]);
		$transaction = $payment->addTransaction(
			\Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE
		);
		$payment->addTransactionCommentsToOrder(
			$transaction,
			$message
		);
		$payment->setParentTransactionId(null);
		$payment->save();
		$order->save();

		return [[
			'status' => 'success',
			'message' => 'Transaction made successfully.'
		]];
	}

    /**
     * Checks if the order status changed
     *
     * @api
     *
     * @param string $transaction_id Transaction Id
     *
     * @return array[]
     */
     public function status_check($order_id)
     {
		$order = $this->_orderFactory->create()->loadByIncrementId(
			$order_id
		);
		if (!$order || empty($order) || empty($order->getState())) {
			return [[
				'status' => 'error',
				'message' =>  'Invalid order.'
			]];
		}

		switch ($order->getState()) {
			case Order::STATE_CANCELED:
				return [[
					'status' => 'changed',
					'message' =>  'Rejected'
				]];
			case Order::STATE_PROCESSING:
				return [[
					'status' => 'changed',
					'message' =>  'Accepted'
				]];
				break;
			case Order::STATE_PENDING_PAYMENT:
			default:
				return [[
					'status' => 'not-changed',
					'message' =>  'Pending'
				]];
				break;
		}
     }

	/**
	 * Check if the status of the order is valid
	 *
	 * @param string $status Transaction status
	 *
	 * @return boolean
	 */
	protected function is_valid_status($status)
	{
		return in_array(
			$status,
			array(self::PAYMENT_STATUS_REJECTED, self::PAYMENT_STATUS_APPROVED)
		);
	}

	/**
	 * Get the merchant id from the modules' backend configiguration
	 *
	 * @return string Merchant id
	 */
	public function getMerchantId()
	{
		$merchant_id = $this->_scopeConfig->getValue(
			'payment/skash/merchant_id',
			\Magento\Store\Model\ScopeInterface::SCOPE_STORE
		);
		return $this->_encryptor->decrypt($merchant_id);
	}

}
