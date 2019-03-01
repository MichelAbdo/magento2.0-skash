<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 *
 */
namespace Skash\SkashPayment\Model;

use Skash\SkashPayment\Api\Skash\CallbackInterface;

// use Magento\Framework\Exception\InputException;
// use Magento\Framework\Exception\NoSuchEntityException;
// use \Magento\Framework\App\RequestInterface;


use \Magento\Framework\App\Action\Context;
use \Magento\Checkout\Model\Session as CheckoutSession;
use \Magento\Sales\Model\OrderFactory;
use \Skash\SkashPayment\Model\Skash as SKashFactory;
use \Magento\Paypal\Helper\Checkout as CheckoutHelper;
use \Magento\Sales\Api\OrderManagementInterface as OrderManagement;
use \Magento\Sales\Model\Service\InvoiceService;
use \Magento\Sales\Model\Order\Email\Sender\OrderSender;
use \Magento\Sales\Model\Order\Email\Sender\InvoiceSender;

use \Magento\Framework\Encryption\EncryptorInterface;
use \Magento\Framework\App\Config\ScopeConfigInterface;

use \Magento\Framework\DB\Transaction as DbTransaction;
use \Psr\Log\LoggerInterface;

//extends \Magento\Framework\Model\AbstractModel
class Callback implements CallbackInterface
{

	/**
     * @var \Magento\Framework\App\RequestInterface
     */
    // protected $_request;

	/**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

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
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Skash\SkashPayment\Model\Skash $sKashFactory
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
 		// RequestInterface $request,
		Context $context,
		CheckoutSession $checkoutSession,
		OrderFactory $orderFactory,
		SKashFactory $sKashFactory,
		CheckoutHelper $checkoutHelper,
		OrderManagement $orderManagement,
		InvoiceService $invoiceService,
		OrderSender $orderSender,
		InvoiceSender $invoiceSender,

		EncryptorInterface $encryptor,
		ScopeConfigInterface $scopeConfig,

		DbTransaction $dbTransaction,
		LoggerInterface $logger
    ) {
		// $this->_request = $request;
		$this->_checkoutSession = $checkoutSession;
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
    }

    /**
     * Returns greeting message to user
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
     * @return string Greeting message with users name.
     */
    public function response($transaction_id, $status, $timestamp, $merchant_id, $amount, $currency, $secure_hash)
    {
		// If status is 0 then transaction is rejected
		// If Status is 1 then transaction is approved

		// @todo: define status in \Skash\SkashPayment\Model\Config::PAYMENT_STATUS_PAID
		// @todo: switch case order status? is there a canceled status?
		if ($status == 0) {
			// @todo: json_encode response array
			// @todo: error log
			return 'Transaction Rejected';
			// return false;
		}
		$order = $this->_orderFactory->create()->loadByIncrementId(
			$transaction_id
		);
		if (!$order || empty($order)) {
			return 'Order not found';
			// return false;
			// @todo: error log
		}

		$merchantId = $this->getMerchantId();
		$orderId = $order->getRealOrderId();
		$orderAmount = (double) $order->getBaseGrandTotal();
		$orderCurrency = $order->getBaseCurrencyCode();
		$orderTimestamp = strtotime($order->getCreatedAt());

		$orderHashData = $orderId . $status . $orderTimestamp . $merchantId . $orderAmount . $orderCurrency;
		$orderSecureHash = base64_encode(hash('sha512', $orderHashData, true));

		if ($secure_hash != $orderSecureHash) {
			return 'Transaction secure hash invalid';
		}

		var_dump($orderId);
		var_dump($merchantId );
		var_dump($orderAmount);
		var_dump($orderCurrency);
		var_dump($order->getCreatedAt());
		var_dump($orderTimestamp);
		var_dump($secure_hash);
		var_dump($orderSecureHash);


		if($order->canInvoice()) {
			$invoice = $this->_invoiceService->prepareInvoice($order);
			$invoice->register();
			$invoice->save();
			$transactionSave = $this->_transaction->addObject(
				$invoice
			)->addObject(
				$invoice->getOrder()
			);
			$transactionSave->save();
			$order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
			$order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
			if ($invoice && !$order->getEmailSent()) {
				$this->_orderSender->send($order);
				$order->addStatusToHistory(\Magento\Sales\Model\Order::STATE_PROCESSING, null, true);
			}
			$order = $order->save();
			/*if ($invoice && !$invoice->getEmailSent() && $sendInvoice) {
				$this->_invoiceSender->send($invoice);
				$message = __('Notified customer about invoice #%1', $invoice->getIncrementId());
				$order->addStatusToHistory(\Magento\Sales\Model\Order::STATE_PROCESSING, $message, true)->save();
			}*/
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
	    $transaction = $payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE);
	    $payment->addTransactionCommentsToOrder(
			$transaction,
			$message
		);
		$payment->setParentTransactionId(null);
		$payment->save();
		$order->save();
		// $this->_redirect('checkout/onepage/success');
		return 'Success';
		// var_dump($a);
		die('end');
		if (!$this->_objectManager->get('Magento\Checkout\Model\Session\SuccessValidator')->isValid()) {
			return $this->resultRedirectFactory->create()->setPath('checkout/cart');
		}
		if($this->_checkoutSession->getLastRealOrderId()) {
			$order = $this->_orderFactory->create()->loadByIncrementId(
				$this->_checkoutSession->getLastRealOrderId()
			);

			if ($order->getIncrementId()) {

				// @todo: change the logic here. should receive calls from the callback instead of calling
				$response = $this->_sKashFactory->IPNResponse($order->getIncrementId());

				if ($response['status']== \Skash\SkashPayment\Model\Config::PAYMENT_STATUS_PAID) {
					if($order->canInvoice()) {
						$invoice = $this->_invoiceService->prepareInvoice($order);
						$invoice->register();
						$invoice->save();
						$transactionSave = $this->_transaction->addObject(
							$invoice
						)->addObject(
							$invoice->getOrder()
						);
						$transactionSave->save();
						$order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
						$order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
						if ($invoice && !$order->getEmailSent()) {
							$this->_orderSender->send($order);
							$order->addStatusToHistory(\Magento\Sales\Model\Order::STATE_PROCESSING, null, true);
						}
						$order = $order->save();
						/*if ($invoice && !$invoice->getEmailSent() && $sendInvoice) {
						$this->_invoiceSender->send($invoice);
						$message = __('Notified customer about invoice #%1', $invoice->getIncrementId());
						$order->addStatusToHistory(\Magento\Sales\Model\Order::STATE_PROCESSING, $message, true)->save();
					}*/
					}
					$payment = $order->getPayment();
 					$payment->setLastTransId($response['trans_id']);
					$payment->setTransactionId($response['trans_id']);
					$formatedPrice = $order->getBaseCurrency()->formatTxt($order->getGrandTotal());
					$message = __('The cuptured amount is %1.', $formatedPrice);
					$payment->setAdditionalInformation([\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => array('StatusId' => $response['status'], 'Timestamp' =>  $response['timestamp'])]);
					$transaction = $payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE);
					$payment->addTransactionCommentsToOrder(
						$transaction,
						$message
					);
					$payment->setParentTransactionId(null);
					$payment->save();
					$order->save();
					$this->_redirect('checkout/onepage/success');
					return;
				} elseif($response['status']==\Skash\SkashPayment\Model\Config::PAYMENT_STATUS_CANCELLED) {
					$message = __("Order has been cancelled by user");
					$this->_orderManagement->cancel($order->getEntityId());
					$order->addStatusHistoryComment($message, "canceled")->setIsCustomerNotified(false)->save();
					$this->messageManager->addErrorMessage($message);
					$this->_redirect('checkout/cart');
					return;
				} elseif($response['status']==\Skash\SkashPayment\Model\Config::PAYMENT_STATUS_EXPIRED) {
					$message = __("Your order has been expired.");
					$this->_orderManagement->cancel($order->getEntityId());
					$order->addStatusHistoryComment($message, "canceled")->setIsCustomerNotified(false)->save();
					$this->messageManager->addErrorMessage($message);
					$this->_redirect('checkout/cart');
					return;
				} else {
					$this->messageManager->addErrorMessage("Your order has been pay failed.");
				}
			}
		}
		$this->_redirect('checkout/cart');

		return;
	}

	/**
	 * Get frontend checkout session object
	 *
	 * @return \Magento\Checkout\Model\Session
	 */
    protected function _getCheckout()
    {
		return $this->_checkoutSession;
    }

	public function getMerchantId()
	{
		$merchant_id = $this->_scopeConfig->getValue(
			'payment/skash/merchant_id',
			\Magento\Store\Model\ScopeInterface::SCOPE_STORE
		);
		return $this->_encryptor->decrypt($merchant_id);
	}

}
