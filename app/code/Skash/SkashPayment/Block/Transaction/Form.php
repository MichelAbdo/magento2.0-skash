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
     * Get order id
     *
     * @return int
     */
    public function getOrderID()
    {
        return $this->_getCheckout()->getLastRealOrderId();;
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
     * Get transaction deeplink URL
     *
     * @return string
     */
    public function getDeeplinkUrl()
    {
        return $this->_skashPaymentMethod->getDeeplinkUrl($this->_getOrder());
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
     * Get the cancel payment URL
// $block->getBaseUrl() . "skash/checkout/cancel"
     *
     * @return string
     */
    public function getCancelAndRedirectToCheckoutUrl()
    {
        return $this->getUrl('skash/checkout/cancel', ['_secure' => true]);
    }

    /**
     * Get the QR image size
     *
     * @return string
     */
    public function getQRSize()
    {
        return $this->_skashPaymentMethod->getQRSize();
    }

    /**
     * Set the skash transaction Reference
     *
     * @return Skash\SkashPayment\Block\Transaction\Form
     */
    public function setSkashTransactionReference($transactionID)
    {
        $order = $this->_getOrder();
        $order->setSkashTransactionReference($transactionID);
        $order->save();
        return $this;
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

    /**
     * Check if the website is opened on mobile
     */
    public function isMobile()
    {
        if (preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i',$_SERVER['HTTP_USER_AGENT'])||preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i',substr($_SERVER['HTTP_USER_AGENT'],0,4))
        ) {
            return true;
        }
        return false;
    }

}
