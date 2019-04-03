<?php

/**
 * QR sizes
 *
 * @author  Michel Abdo <michel.f.abdo@gmail.com>
 * @license https://framework.zend.com/license  New BSD License
 */

namespace Skash\SkashPayment\Model\Config;

use \Magento\Framework\Option\ArrayInterface;

/**
 * QR sizes
 */
class QRSize implements ArrayInterface
{


    /**
     * The Skash QR dropdown values
     *
     * @return array
     */
    public function toOptionArray()
    {
        return array(
            array(
                'value' => 'skash-large',
                'label' => __('Large'),
            ),
            array(
                'value' => 'skash-medium',
                'label' => __('Medium'),
            ),
            array(
                'value' => 'skash-small',
                'label' => __('Small'),
            ),
        );

    }//end toOptionArray()


}//end class
