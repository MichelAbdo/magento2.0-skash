<?php

/**
 * Skash QR Transaction Response Controller
 *
 * @author  Michel Abdo <michel.f.abdo@gmail.com>
 * @license https://framework.zend.com/license  New BSD License
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


    /**
     * Payment status rejected
     *
     * @var integer
     */
    const PAYMENT_STATUS_REJECTED = 0;

    /**
     * Payment status approved
     *
     * @var integer
     */
    const PAYMENT_STATUS_APPROVED = 1;

    /**
     * Order factory
     *
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $_orderFactory;

    /**
     * Order management
     *
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $_orderManagement;

    /**
     * Invoice Service
     *
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $_invoiceService;

    /**
     * Transaction
     *
     * @var \Magento\Framework\DB\Transaction
     */
    protected $_transaction;

    /**
     * Checkout
     *
     * @var \Magento\Paypal\Helper\Checkout
     */
    protected $_checkoutHelper;

    /**
     * Logger Interface
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * Skash Payment Model
     *
     * @var \Skash\SkashPayment\Model\Skash
     */
    protected $_sKashFactory;

    /**
     * Order Sender
     *
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    protected $_orderSender;

    /**
     * Invoice Sender
     *
     * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
     */
    protected $_invoiceSender;

    /**
     * Encryptor Interface
     *
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    protected $_encryptor;

    /**
     * Scope Config Interface
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * Json Factory
     *
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected $_resultJsonFactory;


    /**
     * Construct
     *
     * @param \Magento\Framework\App\Action\Context                 $context           Context
     * @param \Magento\Sales\Model\OrderFactory                     $orderFactory      Order Factory
     * @param \Skash\SkashPayment\Model\Skash                       $sKashFactory      Skash
     * @param \Magento\Paypal\Helper\Checkout                       $checkoutHelper    Checkout
     * @param \Magento\Sales\Api\OrderManagementInterface           $orderManagement   Order Management Interface
     * @param \Magento\Sales\Model\Service\InvoiceService           $invoiceService    Invoice Service
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender   $orderSender       Order Sender
     * @param \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender     Invoice Sender
     * @param \Magento\Framework\Encryption\EncryptorInterface      $encryptor         Encryptor Interface
     * @param \Magento\Framework\App\Config\ScopeConfigInterface    $scopeConfig       Scope Config Interface
     * @param \Magento\Framework\DB\Transaction                     $resultJsonFactory Transaction
     * @param \Magento\Framework\Controller\Result\JsonFactory      $dbTransaction     JsonFactory
     * @param \Psr\Log\LoggerInterface                              $logger            LoggerInterface
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

    }//end __construct()


    /**
     * Handle skash callback response
     *
     * @return void
     */
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

        $result = $this->_resultJsonFactory->create();

        if (empty($transactionId) || empty($timestamp) || empty($merchantId)
            || empty($amount) || empty($currency) || empty($secureHash)
        ) {
            return $result->setData(
                array(
                    'status' => 'error',
                    'message' => 'Invalid / Empty Transaction Params.',
                )
            );
        }

        // Validate the status' value
        if (!$this->isValidStatus($status)) {
            return $result->setData(array(
                'status' => 'error',
                'message' => 'Invalid Transaction Status',
            ));
        }

        // Validate the transactionId's value
        $order = $this->_orderFactory->create()->loadByIncrementId(
            $transactionId
        );
        if (!$order || empty($order) || !$order->getRealOrderId()) {
            return $result->setData(
                array(
                    'status' => 'error',
                    'message' => "Order not found for transaction '$transactionId'",
                )
            );
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
            return $result->setData(
                array(
                    'status' => 'rejected',
                    'message' => $message,
                )
            );
        }

        if ($order->getStatus() !== Order::STATE_PENDING_PAYMENT) {
            return $result->setData(
                array(
                    'status' => __('error'),
                    'message' => __('Order already Updated.'),
                )
            );
        }

        $merchantId = $this->getMerchantId();
        $certificate = $this->getCertificate();
        $orderId = $order->getRealOrderId();
        $orderAmount = (double) $order->getBaseGrandTotal();
        $orderCurrency = $order->getBaseCurrencyCode();
        $orderTimestamp = strtotime($order->getCreatedAt());
        $orderHashData = $orderId.$status.$orderTimestamp.$merchantId.$orderAmount.$orderCurrency.$certificate;
        $orderSecureHash = base64_encode(hash('sha512', $orderHashData, true));

        if ($secureHash != $orderSecureHash) {
            return $result->setData(
                array(
                    'status' => __('error'),
                    'message' => __('Invalid Transaction Params.'),
                )
            );
        }

        if ($order->canInvoice()) {
            $invoice = $this->_invoiceService->prepareInvoice($order);
            $invoice->register();
            $invoice->save();
            $transactionSave = $this->_transaction->addObject($invoice)
                ->addObject($invoice->getOrder());
            $transactionSave->save();
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            if ($invoice && !$order->getEmailSent()) {
                $this->_orderSender->send($order);
                $order->addStatusToHistory(Order::STATE_PROCESSING, null, true);
            }

            $order = $order->save();
        }//end if

        $payment = $order->getPayment();
        $payment->setLastTransId($orderId);
        $payment->setTransactionId($orderId);
        $formatedPrice = $order->getBaseCurrency()->formatTxt($order->getGrandTotal());
        $message = __('The cuptured amount is %1.', $formatedPrice);
        $payment->setAdditionalInformation(
            array(
                \Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => array(
                    'StatusId' => $status,
                    'Timestamp' => $orderTimestamp,
                ),
            )
        );
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

        return $result->setData(
            array(
                'status' => 'success',
                'message' => 'Transaction made successfully.',
            )
        );

    }//end execute()


    /**
     * Check if the status of the order is valid
     *
     * @param string $status Transaction status
     *
     * @return boolean
     */
    protected function isValidStatus($status)
    {
        return in_array(
            $status,
            array(
                self::PAYMENT_STATUS_REJECTED,
                self::PAYMENT_STATUS_APPROVED,
            )
        );

    }//end isValidStatus()


    /**
     * Get the merchant id from the module's backend configuration
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

    }//end getMerchantId()


    /**
     * Get the certificate from the module's backend configuration
     *
     * @return string Merchant id
     */
    public function getCertificate()
    {
        $certificate = $this->_scopeConfig->getValue(
            'payment/skash/certificate',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        return $this->_encryptor->decrypt($certificate);

    }//end getCertificate()


}//end class
