<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Skash\SkashPayment\Controller\Checkout;

/**
 * Class Start
 */
class Response extends \Magento\Framework\App\Action\Action
{

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
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Skash\SkashPayment\Model\Skash $sKashFactory
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Skash\SkashPayment\Model\Skash $sKashFactory,
        \Magento\Paypal\Helper\Checkout $checkoutHelper,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
		\Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Framework\DB\Transaction $transaction,
        \Psr\Log\LoggerInterface $logger
    ) {
    	$this->_checkoutSession = $checkoutSession;
        $this->_orderFactory = $orderFactory;
        $this->_orderManagement = $orderManagement;
        $this->_orderSender = $orderSender;
        $this->_invoiceService = $invoiceService;
        $this->_invoiceSender = $invoiceSender;
        $this->_transaction = $transaction;
        $this->_logger = $logger;
        $this->_sKashFactory = $sKashFactory;
        $this->_checkoutHelper = $checkoutHelper;
        parent::__construct($context);
    }

	public function execute()
    {
    	if (!$this->_objectManager->get('Magento\Checkout\Model\Session\SuccessValidator')->isValid()) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
        if($this->_checkoutSession->getLastRealOrderId()) {
    		$order = $this->_orderFactory->create()->loadByIncrementId($this->_checkoutSession->getLastRealOrderId());
    		if ($order->getIncrementId()) {
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

}
