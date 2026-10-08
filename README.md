# omnitrade/paypal

PayPal for [glitchr/omnitrade](https://github.com/glitchr-studio/omnitrade): orders approved on
PayPal's page and captured when the buyer comes back, authorizations, refunds, and the webhook's
events - PayPal's v2 REST API, over the application's HTTP client.

```php
$gateway = (new PayPalGatewayFactory($http))->create(['client_id' => '...', 'secret' => '...', 'sandbox' => true]);   // $http: the application's HTTP client; none given, the factory makes its own
```

No framework needed: the package requires `glitchr/omnitrade` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnitrade:
    gateways:
        paypal:
            factory: paypal
            options:
                client_id: '%env(PAYPAL_CLIENT_ID)%'
                secret: '%env(PAYPAL_SECRET)%'
                sandbox: true                          # sandbox API and credentials
                webhook_id: '%env(PAYPAL_WEBHOOK_ID)%'  # for notify(): PayPal verifies the signature itself
```

`purchase()` creates an order (intent CAPTURE) and answers PENDING with PayPal's page; PayPal
brings the buyer back to `returnUrl?token=<order id>`, and `capture($orderId)` takes the money -
twice is fine, an order already captured is answered as it is. `authorize()` does the same with
intent AUTHORIZE: `capture()` then takes the money, `void()` lets it go. `refund()` pays back on
the order's capture; `fetch()` reads the order; `notify()` reads `CHECKOUT.ORDER.*` and
`PAYMENT.CAPTURE.*` events. The idempotency key goes in `PayPal-Request-Id`.

Credentials: a REST app in the Developer Dashboard (its client id and secret, sandbox and live
are separate), and a webhook on it for `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`,
`PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED` - its id is `webhook_id`.

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
