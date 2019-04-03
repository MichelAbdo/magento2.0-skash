<?php

/**
 * Skash Transaction Controller
 *
 * After checkout, the JS redirection will forward to the transaction controller which will
 * either submit the data through an API call to sKash to get the QR and display it
 * or call the app using a deeplink if on mobile
 */

namespace Skash\SkashPayment\Controller\Checkout;

/**
 * Skash Transaction Controller
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
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Checkout\Model\Session       $checkoutSession
     * @param \Magento\Sales\Model\OrderFactory     $orderFactory
     * @param \Skash\SkashPayment\Model\Skash       $sKashFactory
     * @param \Magento\Paypal\Helper\Checkout       $checkoutHelper
     * @param \Psr\Log\LoggerInterface              $logger
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
     * Submit order info and obtain skash QR
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

                // If the order status was updated, !== pending_payment, redirect to homepage
                $orderState = $order->getState();
                if ($orderState !== \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
                    switch ($orderState) {
                        case \Magento\Sales\Model\Order::STATE_CANCELED:
                            $orderStateMsg = 'rejected';
                            break;
                        case \Magento\Sales\Model\Order::STATE_PROCESSING:
                            $orderStateMsg = 'accepted';
                            break;
                        default:
                            $orderStateMsg = 'updated';
                            break;
                    }
                    $this->messageManager->addNotice(__('Order already %1. Check your order history.', $orderStateMsg));
                    return $this->resultRedirectFactory->create()->setPath('/');
                }

                $order->addStatusHistoryComment(
                    __('Getting sKash QR.'), $order->getStatus()
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
