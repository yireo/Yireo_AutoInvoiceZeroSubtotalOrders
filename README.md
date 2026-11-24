# Yireo AutoInvoiceZeroSubtotalOrders

**The option "Payment Methods > Zero Subtotal Checkout > Automatically Invoice All Items" (`payment/free/payment_action`) was removed in 2.4.0. This module brings back the automatic invoicing of free orders via cron.**

### Installation
```bash
composer require yireo/magento2-auto-invoice-zero-subtotal-orders
bin/magento module:enable Yireo_AutoInvoiceZeroSubtotalOrders
```

### Configuration
The module is disabled by default. Enable it under **Stores > Configuration > Yireo > Yireo Auto Invoice Zero Subtotal Orders** (`yireo_auto_invoice_zero_subtotal_orders/general/enabled`), per store view if needed.

### How it works
Every 5 minutes, a cron job looks for orders that:

- were placed with the payment method **No Payment Information Required** (`free`);
- have a grand total of zero;
- have the state `new` and the order status configured under **Zero Subtotal Checkout > New Order Status** (`payment/free/order_status`);
- can still be invoiced (not on hold, not in payment review, not canceled).

These orders are invoiced offline. Results and errors are logged to `var/log/yireo_auto_invoice_zero_subtotal_orders.log`.
