<?php declare(strict_types=1);

namespace Yireo\AutoInvoiceZeroSubtotalOrders\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Method\Free;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ENABLED = 'yireo_auto_invoice_zero_subtotal_orders/general/enabled';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getOrderStatus(int $storeId): string
    {
        return (string)$this->scopeConfig->getValue(
            Free::XML_PATH_PAYMENT_FREE_ORDER_STATUS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
