<?php declare(strict_types=1);

namespace Yireo\AutoInvoiceZeroSubtotalOrders\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\TransactionFactory;
use Magento\Payment\Helper\Data;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;

class AutoInvoice
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private SearchCriteriaBuilder $searchCriteriaBuilder,
        private Data $paymentHelper,
        private InvoiceService $invoiceService,
        private TransactionFactory $transactionFactory
    ){
    }

    public function execute()
    {
        if (true !== (bool)$this->paymentHelper->getZeroSubTotalPaymentAutomaticInvoice()) {
            return;
        }

        $orderStatus =  $this->paymentHelper->getZeroSubTotalOrderStatus();
        $this->searchCriteriaBuilder->addFilter('status', $orderStatus);

        $searchCriteria = $this->searchCriteriaBuilder->create();
        $searchResults = $this->orderRepository->getList($searchCriteria);
        $orders = $searchResults->getItems();
        foreach ($orders as $order) {
            $this->autoInvoiceOrder($order);
        }
    }

    private function autoInvoiceOrder(OrderInterface $order)
    {
        if ($order->getSubtotal() > 0) {
            return;
        }

        /** @var Order $order */
        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $invoice->getOrder()->setCustomerNoteNotify(false);
        $invoice->getOrder()->setIsInProcess(true);
        $order->addStatusHistoryComment(__('Automatically invoiced'), false);
        $transactionSave = $this->transactionFactory->create()->addObject($invoice)->addObject($invoice->getOrder());
        $transactionSave->save();
    }
}
