<?php

/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 *
 * https://www.classyllama.com/blog/how-to-create-payment-method-magento-2
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
 * sKash payment method model
 */
class Skash extends AbstractMethod
{

    /**
     * Skash payment status numbers
     */
    const PAYMENT_STATUS_SUCCESS = 2;
    const PAYMENT_STATUS_ERROR = -1;
    const PAYMENT_STATUS_INVALID_DATA = 10;

    /**
     * skash Payment Code
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
    protected $_isGateway = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_isInitializeNeeded = true;
    protected $_order;
    protected $_urlBuilder;
    protected $_orderFactory;
    protected $_logger;

    /**
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    protected $_encryptor;

    /**
     * @param \Magento\Framework\Model\Context                          $context
     * @param \Magento\Framework\Registry                               $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory         $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory              $customAttributeFactory
     * @param \Magento\Payment\Helper\Data                              $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface        $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger                      $logger
     * @param \Magento\Framework\Module\ModuleListInterface             $moduleList
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface      $localeDate
     * @param \Magento\Sales\Model\OrderFactory                         $orderFactory
     * @param \Magento\Framework\Encryption\EncryptorInterface          $encryptor
     * @param \Magento\Framework\UrlInterface                           $urlBuilder
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource   $resourceCollection
     * @param \Magento\Framework\Data\Collection\AbstractDb             $resourceCollection
     * @param array                                                     $data
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
    }

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
        $currentTimestamp = round(microtime(true) * 1000) - strtotime(date("1-1-1970"));
        $hashData = $orderId . $currentTimestamp . $orderAmount . $orderCurrency . $callbackURL . $orderTimestamp . $additionalInfo . $certificate;
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
            'AdditionalInfo' => $additionalInfo
        );
        $this->_logger->debug("QR Transaction | Fields: " . json_encode($fields));
        return $fields;
    }

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
        $callbackURL = $this->getCallbackUrl() . '?source=mobile';
        $orderId = $order->getRealOrderId();
        $orderAmount = (double) $order->getBaseGrandTotal();
        $orderCurrency = $order->getBaseCurrencyCode();
        $orderTimestamp = strtotime($order->getCreatedAt());
        $currentURL = $this->getTransactionUrl() . '?data=' . $orderId;
        $browserType = $this->getBrowserType();
        $mobileHashData = $orderId . $merchantId . $orderAmount . $orderCurrency . $callbackURL . $orderTimestamp . $certificate;
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
            'browsertype' => $browserType
        );
        $this->_logger->debug("Mobile Transaction | Deeplink: " . $appURL . json_encode($fields));
        return $appURL . json_encode($fields);
    }

    /**
     * Get the callback URL
     *
     * @return string
     */
    public function getCallbackUrl()
    {
        return $this->_urlBuilder->getUrl(
                'skash/checkout/response', ['_secure' => true]
        );
    }

    /**
     * Get the Transaction URL
     *
     * @return string
     */
    public function getTransactionUrl()
    {
        return $this->_urlBuilder->getUrl(
                'skash/checkout/transaction', ['_secure' => true]
        );
    }

    /**
     * Make an API call to obtain the sKash QR
     *
     * @param array $requestFields Transaction related fields
     *
     * @return array
     */
    public function getTransactionQR($requestFields)
    {
        $data_string = json_encode($requestFields);

        $url = $this->getQRTransactionUrl();

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
            )
        );
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
            'ReturnText' => $result->ReturnText
        );

        $this->_logger->debug("QR Transaction | Response: " . json_encode($result));

        return $result;
    }

    /**
     * Make an API call to cancel the QR Payment using the transaction id
     *
     * @param array $transaction_id QR transaction id
     *
     * @return array
     */
    public function cancelQRPayment($transaction_id)
    {
        $timestamp = round(microtime(true) * 1000) - strtotime(date("1-1-1970"));
        $hash_data = $timestamp . $transaction_id . $this->getCertificate();
        $secure_hash = base64_encode(hash('sha512', $hash_data, true));
        $data_string = json_encode([
            'TranID' => $transaction_id,
            'TS' => $timestamp,
            'MerchantID' => $this->getMerchantId(),
            'SecureHash' => $secure_hash
        ]);
        $this->_logger->debug("Cancel QR Payment | Request Body: " . $data_string);

        $url = dirname($this->getQRTransactionUrl()) . '/CancelQRPayment';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
            )
        );
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
            'ReturnText' => $result->ReturnText
        );

        $this->_logger->debug("Cancel QR Payment | Response: " . json_encode($result));

        return $result;
    }

    /**
     * Get the merchant id from the module's backend configuration
     *
     * @return string Merchant id
     */
    public function getMerchantId()
    {
        return $this->_encryptor->decrypt($this->getConfigData('merchant_id'));
    }

    /**
     * Get the merchant certificate from the module's backend configuration
     *
     * @return string Certificate
     */
    public function getCertificate()
    {
        return $this->_encryptor->decrypt($this->getConfigData('certificate'));
    }

    /**
     * Get the sKash QR URL
     *
     * @return string
     */
    public function getQRTransactionUrl()
    {
        return $this->_encryptor->decrypt($this->getConfigData('qr_api'));
    }

    /**
     * Get the sKash QR Size
     *
     * @return string
     */
    public function getQRSize()
    {
        return $this->getConfigData('qr_size');
    }

    /**
     * Get initialized flag status
     *
     * @return true
     */
    public function isInitializeNeeded()
    {
        return true;
    }

    /**
     * @param string $paymentAction
     * @param object $stateObject
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
    }

    /**
     * Refund specified amount for payment
     *
     * @param \Magento\Framework\DataObject|InfoInterface $payment
     * @param float                                       $amount
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

        $transaction_id = $payment->getParentTransactionId();
        $timestamp = round(microtime(true) * 1000) - strtotime(date("1-1-1970"));
        $hash_data = $timestamp . $transaction_id . $this->getCertificate();
        $secure_hash = base64_encode(hash('sha512', $hash_data, true));
        $data_string = json_encode([
            'TranID' => $transaction_id,
            'TS' => $timestamp,
            'MerchantID' => $this->getMerchantId(),
            'SecureHash' => $secure_hash
        ]);
        $this->_logger->debug("Reverse Payment | Request Body: " . $data_string);

        $url = dirname($this->getQRTransactionUrl()) . '/ReverseQRPayment';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
            )
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        if (!$result || !json_decode($result)) {
            $this->_logger->debug("Reverse Payment | Error while establishing connection for transaction: " . $transaction_id);
            throw new \Magento\Framework\Exception\LocalizedException(__('Error while establishing connection.'));
        }

        $result = json_decode($result);

        $result = array(
            'Flag' => $result->Flag,
            'ReferenceNo' => $result->ReferenceNo,
            'ReturnText' => $result->ReturnText
        );

        $this->_logger->debug("Reverse Payment | Response: " . json_encode($result));

        switch ($result['Flag']) {
            case 1:
                $this->_logger->debug("Reverse Payment - Success | Transaction $transaction_id reversed successfully, " . $result['ReturnText']);
                $this->messageManager->addNotice(__("The sKash Transaction $transaction_id was reversed successfully."));
                break;
            case 3:
                $this->_logger->debug("Reverse Payment - Error | Reverse Transaction $transaction_id Timed-out, " . $result['ReturnText']);
                throw new \Magento\Framework\Exception\LocalizedException(__('Reverse Transaction $transaction_id Timed-out.'));
            case 7:
                $this->_logger->debug("Reverse Payment - Success | Transaction $transaction_id canceled.");
                $this->messageManager->addNotice(__("nsaction $transaction_id canceled."));
                throw new \Magento\Framework\Exception\LocalizedException(__('Reverse Transaction $transaction_id Timed-out.'));
            case -1:
                $this->_logger->debug("Reverse Payment - Error | Reverse Transaction $transaction_id unsuccessful, " . $result['ReturnText']);
                throw new \Magento\Framework\Exception\LocalizedException(__('Reverse Transaction $transaction_id unsuccessful.'));
                break;
            case 10:
                $this->_logger->debug("Reverse Payment - Error | Invalid data submission for Transaction $transaction_id, " . $result['ReturnText']);
                throw new \Magento\Framework\Exception\LocalizedException(__('Invalid data submission for Transaction $transaction_id.'));
            default:
                throw new \Magento\Framework\Exception\LocalizedException(__('Payment refunding error.'));
        }

        return $this;
    }

    /**
     * Get config action to process initialization
     *
     * @return string
     */
    public function getConfigPaymentAction()
    {
        $paymentAction = $this->getConfigData('payment_action');
        return empty($paymentAction) ? true : $paymentAction;
    }

    /**
     * Get browser type
     *
     * @return string
     */
    public function getBrowserType()
    {
        $ub = '';
        $u_agent = $_SERVER['HTTP_USER_AGENT'];
        if (preg_match('/Firefox/i', $u_agent) || preg_match('/FxiOS/', $u_agent)) {
            $ub = "firefox";
        } elseif (preg_match('/OPR/i', $u_agent)) {
            $ub = "opera";
        } elseif ((preg_match('/Chrome/i', $u_agent) && !preg_match('/Edge/i', $u_agent)) || preg_match('/CriOS/', $u_agent)
        ) {
            $ub = "chrome";
            //$ub = "Chrome";
        } else {
            $ub = "safari";
        }
        return $ub;
    }

}
