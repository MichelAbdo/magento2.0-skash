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

	const PAYMENT_STATUS_SUCCESS = 2;

	const PAYMENT_STATUS_ERROR = -1;

	const PAYMENT_STATUS_INVALID_DATA = 10;

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

	/**
	 * @var \Magento\Framework\Encryption\EncryptorInterface
	 */
	protected $_encryptor;

	/**
	 * @param \Magento\Framework\Model\Context 						  $context
	 * @param \Magento\Framework\Registry 							  $registry
	 * @param \Magento\Framework\Api\ExtensionAttributesFactory 	  $extensionFactory
	 * @param \Magento\Framework\Api\AttributeValueFactory 			  $customAttributeFactory
	 * @param \Magento\Payment\Helper\Data 							  $paymentData
	 * @param \Magento\Framework\App\Config\ScopeConfigInterface 	  $scopeConfig
	 * @param \Magento\Payment\Model\Method\Logger 					  $logger
	 * @param \Magento\Framework\Module\ModuleListInterface 		  $moduleList
	 * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface    $localeDate
	 * @param \Magento\Sales\Model\OrderFactory 					  $orderFactory
	 * @param \Magento\Framework\Encryption\EncryptorInterface 		  $encryptor
	 * @param \Magento\Framework\UrlInterface 						  $urlBuilder
	 * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resourceCollection
	 * @param \Magento\Framework\Data\Collection\AbstractDb 		  $resourceCollection
	 * @param array 												  $data
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
	 * @param object $order Order object
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
		$clientIP = $order->getRemoteIp();
		$additionalInfo = '';
		$currentTimestamp = round(microtime(true) * 1000) - strtotime(date("1-1-1970"));
		$hashData = $orderId . $currentTimestamp . $orderAmount . $orderCurrency . $callbackURL . $orderTimestamp . $additionalInfo . $certificate;
		$secureHash = base64_encode(hash('sha512', $hashData, true));

		return array(
			'TranID' => $orderId,
			'Amount' => $orderAmount,
			'Currency' => $orderCurrency,
			'CallBackURL' => $callbackURL,
			'SecureHash' => $secureHash,
			'TS' => (string) $currentTimestamp,
			'TranTS' => (string) $orderTimestamp,
			'MerchantID' => $merchantId,
			'ClientIP' => $clientIP,
			'AdditionalInfo' => $additionalInfo
		);
	}

	/**
	 * Get the callback url
	 *
	 * @return string
	 */
	public function getCallbackUrl()
	{
		return $this->_urlBuilder->getUrl(
			'skash/callback/response',
			['_secure' => true]
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
		// @todo: Check https://devdocs.magento.com/guides/v2.3/get-started/gs-web-api-request.html
		/*
		$client = new \Zend\Http\Client();
		$options = [
		   'adapter'   => 'Zend\Http\Client\Adapter\Curl',
		   'curloptions' => [CURLOPT_FOLLOWLOCATION => true],
		   'maxredirects' => 0,
		   'timeout' => 30
		 ];
		 $client->setOptions($options);

		 $response = $client->send($request);
		*/
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

		return array(
			'Flag' => $result->Flag,
			'TranID' => $result->TranID,
			'PictureURL' => $result->PictureURL,
			'ReturnText' => $result->ReturnText
		);
	}

	/**
	 * Get the merchant id from the modules' backend configiguration
	 *
	 * @return string Merchant id
	 */
	public function getMerchantId()
	{
		return $this->_encryptor->decrypt($this->getConfigData('merchant_id'));
	}

	/**
	 * Get the merchant certificate from the modules' backend configiguration
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
		//@todo: check if the url is fixed or needs to be dynamic
		return "https://stage.elbarid.com/OnlinePayment/PayQR";
	}

	/**
	 * Get the sKash app deeplink requests URL
	 *
	 * @return string
	 */
	public function getDeeplinkUrl()
	{
		//@todo: check if the url is fixed or needs to be dynamic
		$testMode = $testMode === null ? $this->getConfigData("test") : (bool)$testMode;
		if ($testMode) {
			return "https://stage.elbarid.com/OnlinePayment/PayQR";
		}
		return "https://stage.elbarid.com/OnlinePayment/PayQR";
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
	 * Get config action to process initialization
	 *
	 * @return string
	 */
	public function getConfigPaymentAction()
	{
		$paymentAction = $this->getConfigData('payment_action');
		return empty($paymentAction) ? true : $paymentAction;
	}

}
