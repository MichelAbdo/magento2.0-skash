<?php

/**
 * Cancel Transaction Controller
 *
 * @author  Michel Abdo <michel.f.abdo@gmail.com>
 * @license https://framework.zend.com/license  New BSD License
 */

namespace Skash\SkashPayment\Controller\Checkout;

use \Magento\Sales\Model\Order;

/**
 * Skash Cancel QR Transaction Controller
 */
class Cancel extends \Magento\Framework\App\Action\Action
{


    /**
     * Checkout session
     *
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     * Order factory
     *
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $_orderFactory;

    /**
     * Order repository interface
     *
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $_orderManagement;

    /**
     * Checkout helper
     *
     * @var \Magento\Paypal\Helper\Checkout
     */
    protected $_checkoutHelper;

    /**
     * Logger
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * Skash payment model
     *
     * @var \Skash\SkashPayment\Model\Skash
     */
    protected $_skashPaymentMethod;


    /**
     * Construct
     *
     * @param \Magento\Framework\App\Action\Context       $context            Context
     * @param \Magento\Checkout\Model\Session             $checkoutSession    Checkout Session
     * @param \Magento\Sales\Model\OrderFactory           $orderFactory       Order Factory
     * @param \Skash\SkashPayment\Model\Skash             $skashPaymentMethod Skash Payment Method
     * @param \Magento\Paypal\Helper\Checkout             $checkoutHelper     Checkout Helper
     * @param \Magento\Sales\Api\OrderManagementInterface $orderManagement    Order Management
     * @param \Psr\Log\LoggerInterface                    $logger             Logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Skash\SkashPayment\Model\Skash $skashPaymentMethod,
        \Magento\Paypal\Helper\Checkout $checkoutHelper,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->_orderFactory = $orderFactory;
        $this->_logger = $logger;
        $this->_skashPaymentMethod = $skashPaymentMethod;
        $this->_checkoutHelper = $checkoutHelper;
        $this->_orderManagement = $orderManagement;
        parent::__construct($context);

    }//end __construct()


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

            if ($order->getIncrementId() && $order->getState() == Order::STATE_PENDING_PAYMENT
            ) {
                $orderId = $order->getIncrementId();
                $transactionId = $order->getSkashTransactionReference();
                $result = $this->_skashPaymentMethod->cancelQRPayment($transactionId);

                switch ($result['Flag']) {
                case 1:
                        $this->_logger->debug("Cancel Payment - N/A | Transaction was approved before cancellation for order: $orderId, ".$result['ReturnText']);
                        $this->messageManager->addNotice(__("The sKash Transaction was approved."));
                    break;
                case 3:
                        $this->_logger->debug("Cancel Payment - Error | Transaction Timed-out for order: $orderId, ".$result['ReturnText']);
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

                        $this->_logger->debug("Cancel Payment - Success | Order $orderId canceled ");
                        $this->messageManager->addNotice(__("Order $orderId canceled."));
                    break;
                case -1:
                        $this->_logger->debug("Cancel - Error | Transaction unsuccessful for order: $orderId, ".$result['ReturnText']);
                    break;
                case 10:
                        $this->_logger->debug("Cancel - Error | Invalid data submission for order: $orderId, ".$result['ReturnText']);
                    break;
                }//end switch
            }//end if
        }//end if

        return $this->resultRedirectFactory->create()->setPath('/checkout');

    }//end execute()


    /**
     * Get frontend checkout session object
     *
     * @return \Magento\Checkout\Model\Session
     */
    protected function _getCheckout()
    {
        return $this->_checkoutSession;

    }//end _getCheckout()


}//end class
