<?php

/**
 * Order Status Pending Payment
 *
 * @author  Michel Abdo <michel.f.abdo@gmail.com>
 * @license https://framework.zend.com/license  New BSD License
 */

namespace Skash\SkashPayment\Model\Config\Source\Order\Status;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Config\Source\Order\Status;

/**
 * Order Status source model
 */
class Pendingpayment extends Status
{


    /**
     * Order statuses
     *
     * @var string[]
     */
    protected $_stateStatuses = array(Order::STATE_PENDING_PAYMENT);


}//end class
