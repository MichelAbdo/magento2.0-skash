<?php

/**
 * Skash Callback Model
 *
 * @author  Michel Abdo <michel.f.abdo@gmail.com>
 * @license https://framework.zend.com/license  New BSD License
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

/**
 * Callback Class
 */
class Callback implements CallbackInterface
{


    /**
     * Skash payment status rejected
     *
     * @var integer
     */
    const PAYMENT_STATUS_REJECTED = 0;

    /**
     * Skash payment status approved
     *
     * @var integer
     */
    const PAYMENT_STATUS_APPROVED = 1;

    /**
     * Order Factory
     *
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $_orderFactory;

    /**
     * Order Repository Interface
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
     * Encryptor Interface
     *
     * @var \Magento\Framework\Encryption\EncryptorInterface
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
     * @param \Magento\Framework\Model\Context                      $context           Context
     * @param \Magento\Sales\Model\OrderFactory                     $orderFactory      Order Factory
     * @param \Skash\SkashPayment\Model\Skash                       $sKashFactory      Skash
     * @param \Magento\Paypal\Helper\Checkout                       $checkoutHelper    Checkout
     * @param \Magento\Sales\Api\OrderManagementInterface           $orderManagement   Order Management Interface
     * @param \Magento\Sales\Model\Service\InvoiceService           $invoiceService    Invoice Service
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSende    $orderSender       Order Sender
     * @param \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender     Invoice Sender
     * @param \Magento\Framework\Encryption\EncryptorInterface      $encryptor         Encryptor Interface
     * @param \Magento\Framework\App\Config\ScopeConfigInterface    $scopeConfig       Scope Config Interface
     * @param \Magento\Framework\Controller\Result\JsonFactory      $resultJsonFactory Json Factory
     * @param \Magento\Framework\DB\Transaction                     $dbTransaction     Transaction
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
        DbTransaction $dbTransaction
    ) {
        $this->_orderFactory = $orderFactory;
        $this->_orderManagement = $orderManagement;
        $this->_orderSender = $orderSender;
        $this->_invoiceService = $invoiceService;
        $this->_invoiceSender = $invoiceSender;
        $this->_transaction = $dbTransaction;
        $this->_logger = $context->getLogger();
        $this->_sKashFactory = $sKashFactory;
        $this->_checkoutHelper = $checkoutHelper;
        $this->_encryptor = $encryptor;
        $this->_scopeConfig = $scopeConfig;
        $this->_resultJsonFactory = $resultJsonFactory;

    }//end __construct()


    /**
     * Update the database order status if the transaction was successful
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
    public function response(
        $transaction_id,
        $status,
        $timestamp,
        $merchant_id,
        $amount,
        $currency,
        $secure_hash
    ) {
        if (empty($transaction_id) || empty($status) || empty($timestamp)
            || empty($merchant_id) || empty($amount) || empty($currency)
            || empty($secure_hash)
        ) {
            return array(
                array(
                    'status' => 'error',
                    'message' => 'Invalid / Empty Transaction Params.',
                ),
            );
        }

        // Validate the status' value
        if (!$this->isValidStatus($status)) {
            return array(
                array(
                'status' => 'error',
                'message' => 'Invalid Transaction Status',
                ),
            );
        }

        // Validate the transaction_id's value
        $order = $this->_orderFactory->create()->loadByIncrementId(
            $transaction_id
        );
        if (!$order || empty($order) || !$order->getRealOrderId()) {
            return array(
                array(
                'status' => 'error',
                'message' => "Order not found for transaction '$transaction_id'",
                ),
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
            return array(
                array(
                'status' => 'rejected',
                'message' => $message,
                ),
            );
        }

        if ($order->getStatus() !== Order::STATE_PENDING_PAYMENT) {
            return array(
                array(
                'status' => __('error'),
                'message' => __('Order already Updated.'),
                ),
            );
        }

        $merchantId = $this->getMerchantId();
        $orderId = $order->getRealOrderId();
        $orderAmount = (double) $order->getBaseGrandTotal();
        $orderCurrency = $order->getBaseCurrencyCode();
        $orderTimestamp = strtotime($order->getCreatedAt());
        $orderHashData = $orderId.$status.$orderTimestamp.$merchantId.$orderAmount.$orderCurrency;
        $orderSecureHash = base64_encode(hash('sha512', $orderHashData, true));
        if ($secure_hash != $orderSecureHash) {
            return json_encode(
                array(
                    'status' => __('error'),
                    'message' => __('Invalid Transaction Params.'),
                )
            );
        }

        if ($order->canInvoice()) {
            $invoice = $this->_invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
            $invoice->register();
            $invoice->setTransactionId($transaction_id);
            $invoice->pay();
            $invoice->save();

            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
            if ($invoice && !$order->getEmailSent()) {
                $this->_orderSender->send($order);
                $order->addStatusToHistory(Order::STATE_PROCESSING, null, true);
            }

            $order = $order->save();

        }
        $payment = $order->getPayment();
        $payment->setLastTransId($transaction_id);
        $payment->setTransactionId($transaction_id);
        $payment->setParentTransactionId($transaction_id);
        $payment->setIsTransactionClosed(1);
        $payment->setAdditionalInformation([
            \Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => array(
                'StatusId' => $status,
                'Timestamp' => $orderTimestamp,
            )
        ]);
        $transaction = $payment->addTransaction(
            \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE,
            null,
            true
        );
        $transaction->setIsClosed(true);

        $formatedPrice = $order->getBaseCurrency()->formatTxt($order->getGrandTotal());
        $message = __('The cuptured amount is %1.', $formatedPrice);
        $payment->addTransactionCommentsToOrder(
            $transaction,
            $message
        );
        $payment->save();
        $order->save();

        return array(
            array(
                'status' => 'success',
                'message' => 'Transaction made successfully.',
            ),
        );
    }//end response()


    /**
     * Checks if the order status changed
     *
     * @api
     *
     * @param string $order_id Order id
     *
     * @return array[]
     */
    public function status_check($order_id)
    {
        $order = $this->_orderFactory->create()->loadByIncrementId(
            $order_id
        );
        if (!$order || empty($order) || empty($order->getState())) {
            return array(
                array(
                    'status' => 'error',
                    'message' => 'Invalid order.',
                ),
            );
        }

        switch ($order->getState()) {
        case Order::STATE_CANCELED:
            return array(
                array(
                    'status' => 'changed',
                    'message' => 'Rejected',
                ),
            );
        case Order::STATE_PROCESSING:
            return array(
                array(
                    'status' => 'changed',
                    'message' => 'Accepted',
                ),
            );
        case Order::STATE_PENDING_PAYMENT:
        default:
            return array(
                array(
                'status' => 'not-changed',
                'message' => 'Pending',
                ),
            );
        }

    }//end status_check()


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


}//end class
