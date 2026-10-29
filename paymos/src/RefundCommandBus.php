<?php

declare(strict_types=1);

namespace PaymosPrestaShop;

use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\IssuePartialRefundCommand;
use PrestaShop\PrestaShop\Core\Domain\Order\Command\IssueStandardRefundCommand;

final class RefundCommandBus implements CommandBusInterface
{
    private $inner;

    public function __construct(CommandBusInterface $inner) { $this->inner = $inner; }

    public function handle($command)
    {
        if (($command instanceof IssueStandardRefundCommand || $command instanceof IssuePartialRefundCommand)
            && !RefundNativePlatform::ownsCommand($command)) {
            $orderId = $command->getOrderId()->getValue();
            $order = new \Order($orderId);
            if ($order->module === 'paymos') {
                $module = \Module::getInstanceByName('paymos');
                if (!$module || !$module->active) { throw new \RuntimeException('This order was paid through Paymos. Enable the Paymos module to refund it.'); }
                $module->paymosNativeRefund($orderId, $command);
                // Native controllers must not report a synchronous financial success.
                throw new \RuntimeException('Full refund requested through Paymos. PrestaShop creates the credit slip once the refund completes. Track its status in the Paymos refunds panel on this order.');
            }
        }
        return $this->inner->handle($command);
    }
}
