<?php declare(strict_types=1);

namespace Yireo\AutoInvoiceZeroSubtotalOrders\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Payment\Model\Method\Free;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Yireo\AutoInvoiceZeroSubtotalOrders\Config\Config;

class AutoInvoice
{
    public const LOCK_NAME = 'yireo_auto_invoice_zero_subtotal_orders';
    private const PAGE_SIZE = 100;
    private const ZERO_TOTAL_THRESHOLD = 0.0001;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LockManagerInterface $lockManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (false === $this->lockManager->lock(self::LOCK_NAME, 0)) {
            return;
        }

        try {
            foreach ($this->storeManager->getStores() as $store) {
                $storeId = (int)$store->getId();
                if (false === $this->config->isEnabled($storeId)) {
                    continue;
                }

                $this->processStore($storeId);
            }
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    private function processStore(int $storeId): void
    {
        $orderStatus = $this->config->getOrderStatus($storeId);
        if ($orderStatus === '') {
            return;
        }

        $lastOrderId = 0;
        do {
            $orders = $this->getOrders($storeId, $orderStatus, $lastOrderId);
            foreach ($orders as $order) {
                $lastOrderId = max($lastOrderId, (int)$order->getEntityId());
                $this->processOrder($order);
            }
        } while (count($orders) === self::PAGE_SIZE);
    }

    /**
     * @return OrderInterface[]
     */
    private function getOrders(int $storeId, string $orderStatus, int $lastOrderId): array
    {
        $sortOrder = $this->sortOrderBuilder->setField(OrderInterface::ENTITY_ID)->setAscendingDirection()->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::STORE_ID, $storeId)
            ->addFilter(OrderInterface::STATUS, $orderStatus)
            ->addFilter(OrderInterface::STATE, Order::STATE_NEW)
            ->addFilter(OrderInterface::BASE_GRAND_TOTAL, self::ZERO_TOTAL_THRESHOLD, 'lteq')
            ->addFilter(OrderInterface::ENTITY_ID, $lastOrderId, 'gt')
            ->addSortOrder($sortOrder)
            ->setPageSize(self::PAGE_SIZE)
            ->create();

        return array_values($this->orderRepository->getList($searchCriteria)->getItems());
    }

    private function processOrder(OrderInterface $order): void
    {
        try {
            if (!$order instanceof Order || false === $this->isAllowedToInvoice($order)) {
                return;
            }

            $this->invoiceOrder($order);
            $this->logger->info('Automatically invoiced zero total order', [
                'order' => $order->getIncrementId(),
                'store_id' => $order->getStoreId(),
                'base_grand_total' => $order->getBaseGrandTotal(),
                'payment_method' => $order->getPayment()?->getMethod(),
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('Failed to automatically invoice order: ' . $exception->getMessage(), [
                'order' => $order->getIncrementId(),
                'exception' => $exception,
            ]);
        }
    }

    private function isAllowedToInvoice(Order $order): bool
    {
        if ($order->getPayment()?->getMethod() !== Free::PAYMENT_METHOD_FREE_CODE) {
            return false;
        }

        $baseGrandTotal = $order->getBaseGrandTotal();
        if ($baseGrandTotal === null || abs((float)$baseGrandTotal) > self::ZERO_TOTAL_THRESHOLD) {
            return false;
        }

        if ($order->getState() !== Order::STATE_NEW) {
            return false;
        }

        return $order->canInvoice();
    }

    private function invoiceOrder(Order $order): void
    {
        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $invoice->getOrder()->setCustomerNoteNotify(false);
        $invoice->getOrder()->setIsInProcess(true);
        $order->addCommentToStatusHistory(__('Automatically invoiced'), false);

        $this->transactionFactory->create()
            ->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();
    }
}
