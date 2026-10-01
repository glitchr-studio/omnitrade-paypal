<?php

namespace Omnitrade\PayPal\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Model\PaymentMethod;
use Omnitrade\Request\GetPaymentMethods;
use Omnitrade\Request\Request;

/** What PayPal's page offers: the PayPal account, and the cards it takes as a guest. */
final class GetPaymentMethodsAction implements ActionInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof GetPaymentMethods;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof GetPaymentMethods);
        $request->setResult([
            new PaymentMethod('paypal', 'PayPal', PaymentMethod::WALLET),
            new PaymentMethod('card', 'Card (guest checkout)', PaymentMethod::CARD),
            new PaymentMethod('paylater', 'Pay Later', PaymentMethod::BUY_NOW_PAY_LATER),
        ]);
    }
}
