<?php

/**
 * Add Skash transaction reference to the orders table
 *
 * @author  Michel Abdo <michel.f.abdo@gmail.com>
 * @license https://framework.zend.com/license  New BSD License
 */

namespace Skash\SkashPayment\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Add Skash transaction reference to the orders table
 */
class UpgradeSchema implements UpgradeSchemaInterface
{


    /**
     * Upgrade script
     *
     * @param SchemaSetupInterface   $setup   Setup
     * @param ModuleContextInterface $context Context
     */
    public function upgrade(
        SchemaSetupInterface $setup,
        ModuleContextInterface $context
    ) {
        $installer = $setup;
        $installer->startSetup();
        $installer->getConnection()->addColumn(
            $installer->getTable('sales_order'),
            'skash_transaction_reference',
            [
                'type' => 'text',
                'nullable' => true,
                'comment' => 'Skash Reference Transaction ID',
            ]
        );
        $setup->endSetup();

    }//end upgrade()


}//end class
