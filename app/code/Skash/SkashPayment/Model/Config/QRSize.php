<?php

/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Skash\SkashPayment\Model\Config;

use \Magento\Framework\Option\ArrayInterface;

/**
 * QR sizes
 */
class QRSize implements ArrayInterface
{

    public function toOptionArray()
    {
        return [
            ['value' => 'skash-large', 'label' => __('Large')],
            ['value' => 'skash-medium', 'label' => __('Medium')],
            ['value' => 'skash-small', 'label' => __('Small')]
        ];
    }

}
