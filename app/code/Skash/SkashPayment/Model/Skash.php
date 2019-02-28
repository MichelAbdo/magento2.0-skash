<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 *
 * https://www.classyllama.com/blog/how-to-create-payment-method-magento-2
 */
namespace Skash\SkashPayment\Model;

/**
 * Pay In Store payment method model
 */
class Skash extends \Magento\Payment\Model\Method\AbstractMethod
{

	/*
	 * sKash Payment
	 */
	const METHOD_SKASH = 'skash';

	/**
	 * Payment code
	 *
	 * @var string
	 */
	protected $_code = self::METHOD_SKASH;

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

	public function __construct(
		\Magento\Framework\Model\Context $context,
		\Magento\Framework\Registry $registry,
		\Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
		\Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
		\Magento\Payment\Helper\Data $paymentData,
		\Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
		\Magento\Payment\Model\Method\Logger $logger,
		\Magento\Framework\Module\ModuleListInterface $moduleList,
		\Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
		\Magento\Sales\Model\OrderFactory $orderFactory,
		\Magento\Framework\Encryption\EncryptorInterface $encryptor,
		\Magento\Framework\UrlInterface $urlBuilder,
		\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
		\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
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
	 * PGet form fields and prepare the data to be sent
	 *
	 * @param object $order
	 *
	 * @return array
	 */
	public function getRequestFields($order)
	{
		// $merchantIP = $this->getMerchantIP();
		$merchantId = $this->getMerchantId();
		$certificate = $this->getCertificate();
		// $callbackURL = 'http://localhost.com/skash/checkout/response/';
		$callbackURL = $this->getCallbackUrl();

		$orderId = (int) $order->getRealOrderId();
		// $orderId = $order->getRealOrderId();
		$orderAmount = (double) $order->getBaseGrandTotal();
		// $orderCurrency = 'EUR';
		$orderCurrency = $order->getBaseCurrencyCode();
		$orderTimestamp = strtotime($order->getCreatedAt());
		// $clientIP = '67.43.3.231';
		$clientIP = $order->getRemoteIp();

		// $currentTimestamp = strtotime(date("Y-m-d"));
		$additionalInfo = '';

		// @todo: replace backend shakey with certiicate
		// $certificate = 23818E5AFF9A3B7EA60D35B0135A1EC523818E5AFF9A3B7EA60D35B0135A1EC523818E5AFF9A3B7EA60D35B0135A1EC523818E5AFF9A3B7EA60D35B0135A1EC5;
		$currentTimestamp = round(microtime(true) * 1000) - strtotime(date("1-1-1970"));
		// $currentTimestamp = round(microtime(true) * 1000) - strtotime(date("d-m-Y"));
		// $currentTimestamp = round(microtime(true) * 1000) - strtotime(date("Y-m-d"));

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
	 * Get callback url
	 *
	 * @param string $actionName
	 *
	 * @return string
	 */
	public function getCallbackUrl()
	{
		return $this->_urlBuilder->getUrl(
			'skash/checkout/response',
			['_secure' => true]
		);
	}

	public function getTransactionQR($requestFields)
	{
		$data_string = json_encode($requestFields);

		$url = $this->getTransactionUrl();

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

	public function IPNResponse($incrementId)
	{
		// @todo: replace urls Desktop VS Mobile
		// $url = 'https://stage.elbarid.com/OnlinePayment/PayQR';
		$url = $this->getTransactionUrl();

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, null);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		/*if($this->isTestMode()) {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		}
		else {
			curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST,'TLSv1');
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		}*/
		$response = curl_exec($ch);
		curl_close($ch);
		$response = explode("~",$response);
		$result['trans_id']  = (isset($response[0]) && $response[0]) ? $response[0] : '';
		$result['status'] = (isset($response[1]) && $response[1]) ? $response[1] : '';
		$result['timestamp'] = (isset($response[2]) && $response[2]) ? $response[2] : '';

		return $result;
	}

	public function getMerchantId()
	{
		return $this->_encryptor->decrypt($this->getConfigData('merchant_id'));
	}

	public function getCertificate()
	{
		return $this->_encryptor->decrypt($this->getConfigData('certificate'));
	}

	/**
	 * Getter for URL to perform sKash requests, based on test mode by default
	 *
	 * @param bool|null $testMode Ability to specify test mode using
	 *
	 * @return string
	 */
	public function getTransactionUrl($testMode = null)
	{
		//@todo: check if the url is fixed or needs to be dynamic
		$testMode = $testMode === null ? $this->getConfigData("test") : (bool)$testMode;
		if ($testMode) {
			return "https://stage.elbarid.com/OnlinePayment/PayQR";
		}
		return "https://stage.elbarid.com/OnlinePayment/PayQR";
	}

	/**
	 * Getter for URL to perform sKash requests, based on test mode by default
	 *
	 * @param bool|null $testMode Ability to specify test mode using
	 *
	 * @return string
	 */
	public function getDeeplinkUrl($testMode = null)
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
