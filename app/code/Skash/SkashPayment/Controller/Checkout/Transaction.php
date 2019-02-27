<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 *
 * After checkout, the js redirection will forward to the transaction controller which will
 * either submit the data through an api call to skash to get the QR and display it
 * or call the app using a deeplink if on mobile
 */
namespace Skash\SkashPayment\Controller\Checkout;

/**
 * Class Start
 */
class Transaction extends \Magento\Framework\App\Action\Action
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
    *
    */
	public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Skash\SkashPayment\Model\Skash $sKashFactory,
        \Magento\Paypal\Helper\Checkout $checkoutHelper,
        \Psr\Log\LoggerInterface $logger
    ) {
    	$this->_checkoutSession = $checkoutSession;
        $this->_orderFactory = $orderFactory;
        $this->_logger = $logger;
        $this->_sKashFactory = $sKashFactory;
        $this->_checkoutHelper = $checkoutHelper;
        parent::__construct($context);
    }

	/**
     * Submit transaction to Payflow getaway into iframe
     *
     * @return void
     */
    public function execute()
    {
    	if (!$this->_objectManager->get('Magento\Checkout\Model\Session\SuccessValidator')->isValid()) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
        if ($this->_checkoutSession->getLastRealOrderId()) {
    		$order = $this->_orderFactory->create()->loadByIncrementId(
                $this->_checkoutSession->getLastRealOrderId()
            );

    		if ($order->getIncrementId()) {
				$message = __("Getting sKash QR.");
				$order->addStatusHistoryComment(
                    $message,
                    $order->getStatus()
                )->setIsCustomerNotified(false)->save();
			}
    	}
    	$this->_view->loadLayout(false)->renderLayout();
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
