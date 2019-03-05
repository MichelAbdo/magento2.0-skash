<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Skash\SkashPayment\Controller\Checkout;

use \Magento\Sales\Model\Order;
use \Magento\Framework\App\Action\Context;
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

/**
 * Skash Reponse
 */
class Response extends \Magento\Framework\App\Action\Action
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
     * @param \Magento\Framework\App\Action\Context                   $context
     * @param \Magento\Sales\Model\OrderFactory                       $orderFactory
     * @param \Skash\SkashPayment\Model\Skash                         $sKashFactory
     * @param \Magento\Paypal\Helper\Checkout                         $checkoutHelper
     * @param \Magento\Sales\Api\OrderManagementInterface             $orderManagement
     * @param \Magento\Sales\Model\Service\InvoiceService             $invoiceService
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender     $orderSender
     * @param \Magento\Sales\Model\Order\Email\Sender\InvoiceSender   $invoiceSender
     * @param \Magento\Framework\Encryption\EncryptorInterface        $encryptor
     * @param \Magento\Framework\App\Config\ScopeConfigInterface      $scopeConfig
     * @param \Magento\Framework\DB\Transaction                       $resultJsonFactory
     * @param \Magento\Framework\Controller\Result\JsonFactory        $dbTransaction
     * @param \Psr\Log\LoggerInterface                                $logger
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
        parent::__construct($context);
    }

	public function execute()
    {
        $postData = $this->getRequest()->getPostValue();
        $transactionId = isset($postData['transaction_id']) ? $postData['transaction_id'] : '';
        $status = isset($postData['status']) ? $postData['status'] : '';
        $timestamp = isset($postData['timestamp']) ? $postData['timestamp'] : '';
        $merchantId = isset($postData['merchant_id']) ? $postData['merchant_id'] : '';
        $amount = isset($postData['amount']) ? $postData['amount'] : '';
        $currency = isset($postData['currency']) ? $postData['currency'] : '';
        $secureHash = isset($postData['secure_hash']) ? $postData['secure_hash'] : '';

        /** @var \Magento\Framework\Controller\Result\Json $resultJson */
        $result = $this->_resultJsonFactory->create();

        if (empty($transactionId) || empty($status)
            || empty($timestamp) || empty($merchantId)
            || empty($amount) || empty($currency)
            || empty($secureHash)
        ) {
            $this->_logger->debug("Callback | Error: Order Invalid / empty params");
            return $result->setData(array(
             'status' => 'error',
             'message' => 'Invalid / Empty Transaction Params.'
            ));
        }

        // Validate the status' value
        if (!$this->is_valid_status($status)) {
            $this->_logger->debug("Callback | Error: Order $transactionId invalid status $status");
            return $result->setData(array(
             'status' => 'error',
             'message' => 'Invalid Transaction Status'
            ));
        }

        // Validate the transactionId's value
        $order = $this->_orderFactory->create()->load(
            $transactionId, 'skash_transaction_reference'
        );
        if (!$order || empty($order) || !$order->getRealOrderId()) {
            $this->_logger->debug("Callback | Error: Transaction $transactionId not found");
            return $result->setData(array(
                'status' => 'error',
                'message' =>  "Order not found for transaction '$transactionId'"
            ));
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
            $this->_logger->debug("Callback | Error: Order $transactionId rejected");
            return $result->setData(array(
                'status' => 'rejected',
                'message' => $message
            ));
        }

        if ($order->getStatus() !== Order::STATE_PENDING_PAYMENT) {
            $this->_logger->debug("Callback | Error: Order $transactionId already updated");
            return $result->setData(array(
                'status' => __('error'),
                'message' => __('Order already Updated.')
            ));
        }

        $orderId = $order->getRealOrderId();
        $skashTransactionReference =  $order->getSkashTransactionReference();
        $merchantId = $this->getMerchantId();
        $orderAmount = (double) $order->getBaseGrandTotal();
        $orderCurrency = $order->getBaseCurrencyCode();
        $orderTimestamp = strtotime($order->getCreatedAt());
        $orderHashData = $skashTransactionReference . $status . $orderTimestamp . $merchantId . $orderAmount . $orderCurrency;
        $orderSecureHash = base64_encode(hash('sha512', $orderHashData, true));

        if ($secureHash != $orderSecureHash) {
            $this->_logger->debug('Callback | Error: Hash does not match for order ' . $orderId);
            return $result->setData(array(
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

        $this->_logger->debug("Callback | Success: Order $orderId Accepted");
        return $result->setData(array(
            'status' => 'success',
            'message' => 'Transaction made successfully.'
        ));
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
        $merchantId = $this->_scopeConfig->getValue(
            'payment/skash/merchant_id',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $this->_encryptor->decrypt($merchantId);
    }

}
