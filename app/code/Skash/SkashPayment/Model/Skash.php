<?php

/**
 * Skash Payment Model
 *
 * @author  Michel Abdo <michel.f.abdo@gmail.com>
 * @license https://framework.zend.com/license  New BSD License
 */

namespace Skash\SkashPayment\Model;

use \Magento\Payment\Model\Method\AbstractMethod;
use \Magento\Framework\Model\Context;
use \Magento\Framework\Registry;
use \Magento\Framework\Api\ExtensionAttributesFactory;
use \Magento\Framework\Api\AttributeValueFactory;
use \Magento\Payment\Helper\Data as PaymentData;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use \Magento\Payment\Model\Method\Logger;
use \Magento\Framework\Module\ModuleListInterface;
use \Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use \Magento\Sales\Model\OrderFactory;
use \Magento\Framework\Encryption\EncryptorInterface;
use \Magento\Framework\UrlInterface;
use \Magento\Framework\Model\ResourceModel\AbstractResource;
use \Magento\Framework\Data\Collection\AbstractDb;

/**
 * SKash payment method model
 */
class Skash extends AbstractMethod
{


    /**
     * Skash payment status success
     *
     * @var integer
     */
    const PAYMENT_STATUS_SUCCESS = 2;

    /**
     * Skash payment status error
     *
     * @var integer
     */
    const PAYMENT_STATUS_ERROR = -1;

    /**
     * Skash payment status invalid data
     *
     * @var integer
     */
    const PAYMENT_STATUS_INVALID_DATA = 10;

    /**
     * Skash Payment Code
     *
     * @var string
     */
    const PAYMENT_CODE = 'skash';

    /**
     * Payment code
     *
     * @var string
     */
    protected $_code = self::PAYMENT_CODE;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_isOffline = false;

    /**
     * Is Gateway
     *
     * @var bool
     */
    protected $_isGateway = true;

    /**
     * Can Capture
     *
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * Can Capture Partial
     *
     * @var bool
     */
    protected $_canCapturePartial = false;

    /**
     * Can Refund
     *
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * Can Refund Invoice Partial
     *
     * @var bool
     */
    protected $_canRefundInvoicePartial = false;

    /**
     * Is Initialize Needed
     *
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * Order object
     *
     * @var \Magento\Sales\Model\Order
     */
    protected $_order;

    /**
     * URL Builder
     *
     * @var bool
     */
    protected $_urlBuilder;

    /**
     * OrderFactory
     *
     * @var OrderFactory
     */
    protected $_orderFactory;

    /**
     * Logger
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * Encryptor
     *
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    protected $_encryptor;


    /**
     * Construct
     *
     * @param \Magento\Framework\Model\Context                        $context                Context
     * @param \Magento\Framework\Registry                             $registry               Registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory       $extensionFactory       Extension Attributes Factory
     * @param \Magento\Framework\Api\AttributeValueFactory            $customAttributeFactory Attribute Value Factory
     * @param \Magento\Payment\Helper\Data                            $paymentData            Data
     * @param \Magento\Framework\App\Config\ScopeConfigInterface      $scopeConfig            Scope Config Interface
     * @param \Magento\Payment\Model\Method\Logger                    $logger                 Logger
     * @param \Magento\Framework\Module\ModuleListInterface           $moduleList             Module List Interface
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface    $localeDate             Timezone Interface
     * @param \Magento\Sales\Model\OrderFactory                       $orderFactory           Order Factory
     * @param \Magento\Framework\Encryption\EncryptorInterface        $encryptor              Encryptor Interface
     * @param \Magento\Framework\UrlInterface                         $urlBuilder             Url Interface
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource               Abstract Resource
     * @param \Magento\Framework\Data\Collection\AbstractDb           $resourceCollection     Abstract Db
     * @param array                                                   $data                   Data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        ModuleListInterface $moduleList,
        TimezoneInterface $localeDate,
        OrderFactory $orderFactory,
        EncryptorInterface $encryptor,
        UrlInterface $urlBuilder,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->_orderFactory = $orderFactory;
        $this->_encryptor = $encryptor;
        $this->_urlBuilder = $urlBuilder;
        $this->_logger = $context->getLogger();
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );

    }//end __construct()


    /**
     * Return the transaction related fields required for the sKash API call
     *
     * @param \Magento\Sales\Model\Order $order Order object
     *
     * @return array
     */
    public function getRequestFields($order)
    {
        $merchantId = $this->getMerchantId();
        $certificate = $this->getCertificate();
        $callbackURL = $this->getCallbackUrl();
        $orderId = $order->getRealOrderId();
        $orderAmount = (double) $order->getBaseGrandTotal();
        $orderCurrency = $order->getBaseCurrencyCode();
        $orderTimestamp = strtotime($order->getCreatedAt());
        $additionalInfo = '';
        $currentTimestamp = (round(microtime(true) * 1000) - strtotime(date("1-1-1970")));
        $hashData = $orderId.$currentTimestamp.$orderAmount.$orderCurrency.$callbackURL.$orderTimestamp.$additionalInfo.$certificate;
        $secureHash = base64_encode(hash('sha512', $hashData, true));

        $fields = array(
            'TranID' => $orderId,
            'Amount' => $orderAmount,
            'Currency' => $orderCurrency,
            'CallBackURL' => $callbackURL,
            'SecureHash' => $secureHash,
            'TS' => (string) $currentTimestamp,
            'TranTS' => (string) $orderTimestamp,
            'MerchantID' => $merchantId,
            'AdditionalInfo' => $additionalInfo,
        );
        $this->_logger->debug("QR Transaction | Fields: ".json_encode($fields));

        return $fields;

    }//end getRequestFields()


    /**
     * Get the sKash application deeplink and its related transaction fields
     *
     * @param \Magento\Sales\Model\Order $order Order object
     *
     * @return array
     */
    public function getDeeplinkUrl($order)
    {
        $appURL = 'skashpay://skash.com/skash=?';
        $merchantId = $this->getMerchantId();
        $certificate = $this->getCertificate();
        $callbackURL = $this->getCallbackUrl().'?source=mobile';
        $orderId = $order->getRealOrderId();
        $orderAmount = (double) $order->getBaseGrandTotal();
        $orderCurrency = $order->getBaseCurrencyCode();
        $orderTimestamp = strtotime($order->getCreatedAt());
        $currentURL = $this->getTransactionUrl().'?data='.$orderId;
        $browserType = $this->getBrowserType();
        $mobileHashData = $orderId.$merchantId.$orderAmount.$orderCurrency.$callbackURL.$orderTimestamp.$certificate;
        $mobileSecureHash = base64_encode(hash('sha512', $mobileHashData, true));
        $fields = array(
            'strTranID' => $orderId,
            'MerchantID' => $merchantId,
            'Amount' => $orderAmount,
            'Currency' => $orderCurrency,
            'CallBackURL' => $callbackURL,
            'TS' => (string) $orderTimestamp,
            'secureHash' => $mobileSecureHash,
            'currentURL' => $currentURL,
            'browsertype' => $browserType,
        );
        $this->_logger->debug("Mobile Transaction | Deeplink: ".$appURL.json_encode($fields));

        return $appURL.json_encode($fields);

    }//end getDeeplinkUrl()


    /**
     * Get the callback URL
     *
     * @return string
     */
    public function getCallbackUrl()
    {
        return $this->_urlBuilder->getUrl(
            'skash/checkout/response',
            ['_secure' => true]
        );

    }//end getCallbackUrl()


    /**
     * Get the Transaction URL
     *
     * @return string
     */
    public function getTransactionUrl()
    {
        return $this->_urlBuilder->getUrl(
            'skash/checkout/transaction',
            ['_secure' => true]
        );

    }//end getTransactionUrl()


    /**
     * Make an API call to obtain the sKash QR
     *
     * @param array $requestFields Transaction related fields
     *
     * @return array
     */
    public function getTransactionQR($requestFields)
    {
        $dataString = json_encode($requestFields);
        $url = $this->getQRTransactionUrl();
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        if (!$result || !json_decode($result)) {
            return array('error' => __('Error while establishing connection.'));
        }

        $result = json_decode($result);

        $result = array(
            'Flag' => $result->Flag,
            'TranID' => $result->TranID,
            'PictureURL' => $result->PictureURL,
            'ReturnText' => $result->ReturnText,
        );

        $this->_logger->debug("QR Transaction | Response: ".json_encode($result));

        return $result;

    }//end getTransactionQR()


    /**
     * Make an API call to cancel the QR Payment using the transaction id
     *
     * @param array $transactionId QR transaction id
     *
     * @return array
     */
    public function cancelQRPayment($transactionId)
    {
        $timestamp = (round(microtime(true) * 1000) - strtotime(date("1-1-1970")));
        $hashData = $timestamp.$transactionId.$this->getCertificate();
        $secureHash = base64_encode(hash('sha512', $hashData, true));
        $dataString = json_encode(
            array(
                'TranID' => $transactionId,
                'TS' => $timestamp,
                'MerchantID' => $this->getMerchantId(),
                'SecureHash' => $secureHash,
            )
        );
        $this->_logger->debug("Cancel QR Payment | Request Body: ".$dataString);

        $url = dirname($this->getQRTransactionUrl()).'/CancelQRPayment';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        if (!$result || !json_decode($result)) {
            return array('error' => __('Error while establishing connection.'));
        }

        $result = json_decode($result);

        $result = array(
            'Flag' => $result->Flag,
            'ReferenceNo' => $result->ReferenceNo,
            'ReturnText' => $result->ReturnText,
        );

        $this->_logger->debug("Cancel QR Payment | Response: ".json_encode($result));

        return $result;

    }//end cancelQRPayment()


    /**
     * Get the merchant id from the module's backend configuration
     *
     * @return string Merchant id
     */
    public function getMerchantId()
    {
        return $this->_encryptor->decrypt($this->getConfigData('merchant_id'));

    }//end getMerchantId()


    /**
     * Get the merchant certificate from the module's backend configuration
     *
     * @return string Certificate
     */
    public function getCertificate()
    {
        return $this->_encryptor->decrypt($this->getConfigData('certificate'));

    }//end getCertificate()


    /**
     * Get the sKash QR URL
     *
     * @return string
     */
    public function getQRTransactionUrl()
    {
        return $this->_encryptor->decrypt($this->getConfigData('qr_api'));

    }//end getQRTransactionUrl()


    /**
     * Get the sKash QR Size
     *
     * @return string
     */
    public function getQRSize()
    {
        return $this->getConfigData('qr_size');

    }//end getQRSize()


    /**
     * Get initialized flag status
     *
     * @return true
     */
    public function isInitializeNeeded()
    {
        return true;

    }//end isInitializeNeeded()


    /**
     * Initialize
     *
     * @param string $paymentAction Payment Action
     * @param object $stateObject   State Object
     *
     * @return void
     */
    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);
        $order->setIsNotified(false);
        $order->setCustomerNoteNotify(false);
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);
        $stateObject->setCustomerNoteNotify(false);

    }//end initialize()


    /**
     * Refund specified amount for payment
     *
     * @param \Magento\Framework\DataObject|InfoInterface $payment payment
     * @param float                                       $amount  amount
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        if (!$payment->getParentTransactionId()) {
            $this->_logger->debug("Reverse Payment | Invalid transaction ID.");
            throw new \Magento\Framework\Exception\LocalizedException(__('Invalid transaction ID.'));
        }

        $transactionId = $payment->getParentTransactionId();
        $timestamp = (round(microtime(true) * 1000) - strtotime(date("1-1-1970")));
        $hashData = $timestamp.$transactionId.$this->getCertificate();
        $secureHash = base64_encode(hash('sha512', $hashData, true));
        $dataString = json_encode(
            array(
                'TranID' => $transactionId,
                'TS' => $timestamp,
                'MerchantID' => $this->getMerchantId(),
                'SecureHash' => $secureHash,
            )
        );
        $this->_logger->debug("Reverse Payment | Request Body: ".$dataString);

        $url = dirname($this->getQRTransactionUrl()).'/ReverseQRPayment';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        if (!$result || !json_decode($result)) {
            $this->_logger->debug("Reverse Payment | Error while establishing connection for transaction: ".$transactionId);
            throw new \Magento\Framework\Exception\LocalizedException(__('Error while establishing connection.'));
        }

        $result = json_decode($result);

        $result = array(
            'Flag' => $result->Flag,
            'ReferenceNo' => $result->ReferenceNo,
            'ReturnText' => $result->ReturnText,
        );

        $this->_logger->debug("Reverse Payment | Response: ".json_encode($result));

        switch ($result['Flag']) {
        case 1:
            $this->_logger->debug("Reverse Payment - Success | Transaction $transactionId reversed successfully, ".$result['ReturnText']);
            $this->messageManager->addNotice(__('The sKash Transaction %1 was reversed successfully.', $transactionId));
            break;
        case 3:
            $this->_logger->debug("Reverse Payment - Error | Reverse Transaction $transactionId Timed-out, ".$result['ReturnText']);
            throw new \Magento\Framework\Exception\LocalizedException(__('Reverse Transaction %1 Timed-out.', $transactionId));
        case 7:
            $this->_logger->debug("Reverse Payment - Success | Transaction $transactionId canceled.");
            $this->messageManager->addNotice(__('Transaction %1 canceled.', $transactionId));
            throw new \Magento\Framework\Exception\LocalizedException(__('Reverse Transaction %1 Timed-out.', $transactionId));
        case -1:
            $this->_logger->debug("Reverse Payment - Error | Reverse Transaction $transactionId unsuccessful, ".$result['ReturnText']);
            throw new \Magento\Framework\Exception\LocalizedException(__('Reverse Transaction %1 unsuccessful.', $transactionId));
            break;
        case 10:
            $this->_logger->debug("Reverse Payment - Error | Invalid data submission for Transaction $transactionId, ".$result['ReturnText']);
            throw new \Magento\Framework\Exception\LocalizedException(__('Invalid data submission for Transaction %1.', $transactionId));
        default:
            throw new \Magento\Framework\Exception\LocalizedException(__('Payment refunding error.'));
        }//end switch

        return $this;

    }//end refund()


    /**
     * Get config action to process initialization
     *
     * @return string
     */
    public function getConfigPaymentAction()
    {
        $paymentAction = $this->getConfigData('payment_action');

        return empty($paymentAction) ? true : $paymentAction;

    }//end getConfigPaymentAction()


    /**
     * Get browser type
     *
     * @return string
     */
    public function getBrowserType()
    {
        $ub = '';
        $uAgent = $_SERVER['HTTP_USER_AGENT'];
        if (preg_match('/Firefox/i', $uAgent) || preg_match('/FxiOS/', $uAgent)) {
            $ub = "firefox";
        } else if (preg_match('/OPR/i', $uAgent)) {
            $ub = "opera";
        } else if ((preg_match('/Chrome/i', $uAgent) && !preg_match('/Edge/i', $uAgent)) || preg_match('/CriOS/', $uAgent)
        ) {
            $ub = "chrome";
        } else {
            $ub = "safari";
        }

        return $ub;

    }//end getBrowserType()


}//end class
