<?php declare(strict_types=1);

namespace Yireo\AutoInvoiceZeroSubtotalOrders\Test\Integration\Cron;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yireo\AutoInvoiceZeroSubtotalOrders\Cron\AutoInvoice;

/**
 * @magentoAppArea crontab
 * @magentoDbIsolation enabled
 */
class AutoInvoiceTest extends TestCase
{
    private ObjectManagerInterface $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
    }

    /**
     * @magentoConfigFixture current_store yireo_auto_invoice_zero_subtotal_orders/general/enabled 1
     */
    public function testInvoicesFreeOrderWithZeroGrandTotal(): void
    {
        $orderId = $this->createOrder('900000001', 'free', 0.0, 0.0);

        $this->getCron()->execute();

        $order = $this->loadOrder($orderId);
        $this->assertSame(1, $order->getInvoiceCollection()->getSize());
        $this->assertSame(Order::STATE_PROCESSING, $order->getState());
    }

    /**
     * @magentoConfigFixture current_store yireo_auto_invoice_zero_subtotal_orders/general/enabled 1
     */
    public function testDoesNotInvoiceZeroSubtotalOrderWithPaidShipping(): void
    {
        $orderId = $this->createOrder('900000002', 'checkmo', 0.0, 5.0);

        $this->getCron()->execute();

        $this->assertNotInvoiced($orderId);
    }

    /**
     * @magentoConfigFixture current_store yireo_auto_invoice_zero_subtotal_orders/general/enabled 1
     */
    public function testDoesNotInvoiceFreeMethodOrderWithNonZeroGrandTotal(): void
    {
        $orderId = $this->createOrder('900000003', 'free', 0.0, 5.0);

        $this->getCron()->execute();

        $this->assertNotInvoiced($orderId);
    }

    /**
     * @magentoConfigFixture current_store yireo_auto_invoice_zero_subtotal_orders/general/enabled 1
     */
    public function testDoesNotInvoiceZeroTotalOrderWithOtherPaymentMethod(): void
    {
        $orderId = $this->createOrder('900000004', 'checkmo', 0.0, 0.0);

        $this->getCron()->execute();

        $this->assertNotInvoiced($orderId);
    }

    public function testDoesNothingWhenDisabledByDefault(): void
    {
        $orderId = $this->createOrder('900000005', 'free', 0.0, 0.0);

        $this->getCron()->execute();

        $this->assertNotInvoiced($orderId);
    }

    /**
     * @magentoConfigFixture current_store yireo_auto_invoice_zero_subtotal_orders/general/enabled 1
     */
    public function testDoesNotInvoiceOrderOnHold(): void
    {
        $orderId = $this->createOrder('900000006', 'free', 0.0, 0.0);
        $order = $this->loadOrder($orderId);
        $order->hold();
        $this->objectManager->get(OrderRepositoryInterface::class)->save($order);

        $this->getCron()->execute();

        $order = $this->loadOrder($orderId);
        $this->assertSame(0, $order->getInvoiceCollection()->getSize());
        $this->assertSame(Order::STATE_HOLDED, $order->getState());
    }

    /**
     * @magentoConfigFixture current_store yireo_auto_invoice_zero_subtotal_orders/general/enabled 1
     */
    public function testDoesNotInvoiceOrderThatCanNotBeInvoiced(): void
    {
        $orderId = $this->createOrder('900000013', 'free', 0.0, 0.0);
        $orderItemRepository = $this->objectManager->get(OrderItemRepositoryInterface::class);
        foreach ($this->loadOrder($orderId)->getAllItems() as $item) {
            $item->setQtyInvoiced($item->getQtyOrdered());
            $orderItemRepository->save($item);
        }

        $this->getCron()->execute();

        $this->assertNotInvoiced($orderId);
    }

    /**
     * @magentoDataFixture Magento/Store/_files/second_store.php
     * @magentoConfigFixture current_store yireo_auto_invoice_zero_subtotal_orders/general/enabled 1
     */
    public function testOnlyInvoicesOrdersOfEnabledStores(): void
    {
        $secondStoreId = (int)$this->objectManager->get(StoreManagerInterface::class)
            ->getStore('fixture_second_store')->getId();
        $enabledOrderId = $this->createOrder('900000007', 'free', 0.0, 0.0);
        $disabledOrderId = $this->createOrder('900000008', 'free', 0.0, 0.0, $secondStoreId);

        $this->getCron()->execute();

        $this->assertSame(1, $this->loadOrder($enabledOrderId)->getInvoiceCollection()->getSize());
        $this->assertNotInvoiced($disabledOrderId);
    }

    /**
     * @magentoConfigFixture current_store yireo_auto_invoice_zero_subtotal_orders/general/enabled 1
     */
    public function testSecondRunDoesNotCreateDuplicateInvoice(): void
    {
        $orderId = $this->createOrder('900000009', 'free', 0.0, 0.0);

        $this->getCron()->execute();
        $this->getCron()->execute();

        $this->assertSame(1, $this->loadOrder($orderId)->getInvoiceCollection()->getSize());
    }

    /**
     * @magentoConfigFixture current_store yireo_auto_invoice_zero_subtotal_orders/general/enabled 1
     */
    public function testDoesNothingWhenLockIsAlreadyTaken(): void
    {
        $orderId = $this->createOrder('900000010', 'free', 0.0, 0.0);
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $lockManager->expects($this->never())->method('unlock');

        $this->getCron(['lockManager' => $lockManager])->execute();

        $this->assertNotInvoiced($orderId);
    }

    /**
     * @magentoConfigFixture current_store yireo_auto_invoice_zero_subtotal_orders/general/enabled 1
     */
    public function testFailingOrderDoesNotBlockOtherOrders(): void
    {
        $failingOrderId = $this->createOrder('900000011', 'free', 0.0, 0.0);
        $otherOrderId = $this->createOrder('900000012', 'free', 0.0, 0.0);

        $realInvoiceService = $this->objectManager->get(InvoiceService::class);
        $invoiceService = $this->createMock(InvoiceService::class);
        $invoiceService->method('prepareInvoice')->willReturnCallback(
            function (Order $order) use ($realInvoiceService) {
                if ($order->getIncrementId() === '900000011') {
                    throw new RuntimeException('Broken order');
                }

                return $realInvoiceService->prepareInvoice($order);
            }
        );

        $this->getCron(['invoiceService' => $invoiceService])->execute();

        $this->assertNotInvoiced($failingOrderId);
        $this->assertSame(1, $this->loadOrder($otherOrderId)->getInvoiceCollection()->getSize());
    }

    private function getCron(array $arguments = []): AutoInvoice
    {
        return $this->objectManager->create(AutoInvoice::class, $arguments);
    }

    private function createOrder(
        string $incrementId,
        string $paymentMethod,
        float $subtotal,
        float $grandTotal,
        ?int $storeId = null
    ): int {
        $storeId ??= (int)$this->objectManager->get(StoreManagerInterface::class)->getStore()->getId();

        $payment = $this->objectManager->create(Payment::class);
        $payment->setMethod($paymentMethod);

        $orderItem = $this->objectManager->create(OrderItem::class);
        $orderItem->setQtyOrdered(1)
            ->setBasePrice($subtotal)
            ->setPrice($subtotal)
            ->setRowTotal($subtotal)
            ->setBaseRowTotal($subtotal)
            ->setProductType('simple')
            ->setName('Test product')
            ->setSku('test-product-' . $incrementId)
            ->setStoreId($storeId);

        $order = $this->objectManager->get(OrderFactory::class)->create();
        $order->setIncrementId($incrementId)
            ->setState(Order::STATE_NEW)
            ->setStatus('pending')
            ->setSubtotal($subtotal)
            ->setBaseSubtotal($subtotal)
            ->setGrandTotal($grandTotal)
            ->setBaseGrandTotal($grandTotal)
            ->setOrderCurrencyCode('USD')
            ->setBaseCurrencyCode('USD')
            ->setCustomerIsGuest(true)
            ->setCustomerEmail('customer@example.com')
            ->setStoreId($storeId)
            ->addItem($orderItem)
            ->setPayment($payment);

        $this->objectManager->get(OrderRepositoryInterface::class)->save($order);

        return (int)$order->getEntityId();
    }

    private function loadOrder(int $orderId): Order
    {
        $order = $this->objectManager->get(OrderFactory::class)->create();
        $order->load($orderId);

        return $order;
    }

    private function assertNotInvoiced(int $orderId): void
    {
        $order = $this->loadOrder($orderId);
        $this->assertSame(0, $order->getInvoiceCollection()->getSize());
        $this->assertSame(Order::STATE_NEW, $order->getState());
    }
}
