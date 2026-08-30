<?php

declare(strict_types=1);

namespace {
// PrestaShop front-controller base: the module's callback controller extends
// it; inside a running shop it always exists.
if (!class_exists('ModuleFrontController')) {
    class ModuleFrontController
    {
        public function initContent()
        {
        }

        public function setTemplate($template)
        {
        }
    }
}

if (!class_exists('Module')) {
    class Module
    {
    }
}

if (!class_exists('PaymentModule')) {
    class PaymentModule
    {
    }
}

}

namespace PrestaShop\PrestaShop\Core\Payment {
    if (!class_exists(PaymentOption::class)) {
        class PaymentOption
        {
        }
    }
}
