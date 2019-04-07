/**
 * Skah JS
 *
 * @author  Michel Abdo <michel.f.abdo@gmail.com>
 * @license https://framework.zend.com/license  New BSD License
 */

/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component, rendererList) {
        'use strict';

        rendererList.push(
            {
                type: 'skash',
                component: 'Skash_SkashPayment/js/view/payment/method-renderer/skash-method'
            }
        );

        /** Add view logic here if needed */
        return Component.extend({});
    }
);
