<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Skash\SkashPayment\Block\Transaction;

use \Magento\Customer\Helper\Session\CurrentCustomer;
use \Magento\Framework\Locale\ResolverInterface;
use \Magento\Framework\View\Element\Template;
use \Magento\Framework\View\Element\Template\Context;
use \Magento\Sales\Model\OrderFactory;
use \Magento\Checkout\Model\Session;
use \Magento\Paypal\Model\Config;
use \Magento\Paypal\Model\ConfigFactory;
use \Magento\Paypal\Model\Express\Checkout;
use \Skash\SkashPayment\Model\Skash;

/**
 * Skash Transaction Form Block
 */
class Form extends \Magento\Payment\Block\Form
{

    public $PAYMENT_STATUS_SUCCESS = Skash::PAYMENT_STATUS_SUCCESS;

    public $PAYMENT_STATUS_ERROR = Skash::PAYMENT_STATUS_ERROR;

    public $PAYMENT_STATUS_INVALID_DATA = Skash::PAYMENT_STATUS_INVALID_DATA;

    /**
     * Order object
     *
     * @var \Magento\Sales\Model\Order
     */
    protected $_order;

    /**
     * @var OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var ResolverInterface
     */
    protected $_localeResolver;

    /**
     * @var bool
     */
    protected $_isScopePrivate;

    /**
     * @var CurrentCustomer
     */
    protected $currentCustomer;

    /**
     * @var \Magento\Checkout\Model\Session
     */

     protected $_checkoutSession;

    /**
     * @var \Skash\SkashPayment\Model\Skash
     */
     protected $_skashPaymentMethod;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Paypal\Model\ConfigFactory              $paypalConfigFactory
     * @param \Magento\Framework\Locale\ResolverInterface      $localeResolver
     * @param \Magento\Customer\Helper\Session\CurrentCustomer $currentCustomer
     * @param \Skash\SkashPayment\Model\Skash                  $skash
     * @param array                                            $data
     */
    public function __construct(
        Context $context,
        OrderFactory $orderFactory,
        ResolverInterface $localeResolver,
        Session $checkoutSession,
        CurrentCustomer $currentCustomer,
        Skash $skash,
        array $data = []
    ) {
        $this->_orderFactory = $orderFactory;
        $this->_checkoutSession = $checkoutSession;
        $this->_localeResolver = $localeResolver;
        $this->_isScopePrivate = true;
        $this->currentCustomer = $currentCustomer;
        parent::__construct($context, $data);
        $this->_skashPaymentMethod = $skash;
    }

    /**
     * Get order object
     *
     * @return \Magento\Sales\Model\Order
     */
    protected function _getOrder()
    {
        if (!$this->_order) {
            $incrementId = $this->_getCheckout()->getLastRealOrderId();
            $this->_order = $this->_orderFactory->create()->loadByIncrementId($incrementId);
        }
        return $this->_order;
    }

    /**
     * Return the transaction related fields required for the sKash API call
     *
     * @return array
     */
    public function getRequestFields()
    {
    	return $this->_skashPaymentMethod->getRequestFields($this->_getOrder());
    }

    /**
     * Get transaction QR
     *
     * @return string
     */
    public function getTransactionQR()
    {
        return $this->_skashPaymentMethod->getTransactionQR($this->getRequestFields());
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

    /**
     * Get the checkout URL
     *
     * @return string
     */
    public function getCheckoutUrl()
    {
        return $this->getUrl('checkout', ['_secure' => true]);
    }

    /**
     * Get the homepage url
     *
     * @return string
     */
    public function getContinueShoppingUrl()
    {
        $url = $this->getData('continue_shopping_url');
        if ($url === null) {
            $url = $this->_checkoutSession->getContinueShoppingUrl(true);
            if (!$url) {
                $url = $this->_urlBuilder->getUrl();
            }
            $this->setData('continue_shopping_url', $url);
        }
        return $url;
    }

    /**
     * Get the callback url
     *
     * @return string
     */
    public function getStatusChangeUrl()
    {
        // @todo: fix the url
        $order = $this->_getOrder();
        return $this->_urlBuilder->getUrl(
            'rest/V1/api/skash/callback/'
            // 'rest/V1/api/skash/callback/status_changed?order_id=' . $order->getRealOrderId()
            // ['_secure' => true]
        );
    }

}
