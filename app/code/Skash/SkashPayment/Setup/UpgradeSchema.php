<?php
/**
 * Add Skash transaction reference to the orders table
 */
namespace Skash\SkashPayment\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
/**
 * @codeCoverageIgnore
 */
class UpgradeSchema implements UpgradeSchemaInterface
{
    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
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
    }
}
