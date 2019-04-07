<?php

/**
 * Module Registration
 *
 * @author  Michel Abdo <michel.f.abdo@gmail.com>
 * @license https://framework.zend.com/license  New BSD License
 */

use \Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Skash_SkashPayment',
    __DIR__
);
