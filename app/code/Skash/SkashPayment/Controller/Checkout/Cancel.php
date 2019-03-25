<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 *
 */
namespace Skash\SkashPayment\Controller\Checkout;

use \Magento\Sales\Model\Order;

/**
 * Skash Cancel QR Transaction Controller
 */
class Cancel extends \Magento\Framework\App\Action\Action
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
    protected $_skashPaymentMethod;

    /**
    *
    */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Skash\SkashPayment\Model\Skash $_skashPaymentMethod,
        \Magento\Paypal\Helper\Checkout $checkoutHelper,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->_orderFactory = $orderFactory;
        $this->_logger = $logger;
        $this->_skashPaymentMethod = $_skashPaymentMethod;
        $this->_checkoutHelper = $checkoutHelper;
        $this->_orderManagement = $orderManagement;
        parent::__construct($context);
    }

    /**
     * When the user clicks on the back to checkout button
     * check the status of the order, if pending, cancel it
     * and redirect to the checkout page.
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

            if ($order->getIncrementId()
                && $order->getState() == Order::STATE_PENDING_PAYMENT
            ) {
                $order_id = $order->getIncrementId();
                $transaction_id = $order->getSkashTransactionReference();
                $result = $this->_skashPaymentMethod->cancelQRPayment($transaction_id);

                switch ($result['Flag']) {
                    case 1:
                        $this->_logger->debug("Cancel Payment - N/A | Transaction was approved before cancellation for order: $order_id, " . $result['ReturnText']);
                        $this->messageManager->addNotice(__("The sKash Transaction was approved."));
                        break;
                    case 3:
                        $this->_logger->debug("Cancel Payment - Error | Transaction Timed-out for order: $order_id, " . $result['ReturnText']);
                        break;
                    case 7:
                        $order->setState(Order::STATE_CANCELED);
                        $order->setStatus(Order::STATE_CANCELED);
                        $message = __('sKash Transaction Canceled.');
                        $this->_orderManagement->cancel(
                            $order->getEntityId()
                        );
                        $order->addStatusHistoryComment(
                            __("sKash Transaction Canceled"),
                            Order::STATE_CANCELED
                        )->setIsCustomerNotified(false)
                        ->setSkashTransactionReference($result['ReferenceNo'])
                        ->save();

                        $this->_logger->debug("Cancel Payment - Success | Order $order_id canceled ");
                        $this->messageManager->addNotice(__("Order $order_id canceled."));
                        break;
                    case -1:
                        $this->_logger->debug("Cancel - Error | Transaction unsuccessful for order: $order_id, " . $result['ReturnText']);
                        break;
                    case 10:
                        $this->_logger->debug("Cancel - Error | Invalid data submission for order: $order_id, " . $result['ReturnText']);
                        break;
                }
            }
        }

        return $this->resultRedirectFactory->create()->setPath('/checkout');
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
