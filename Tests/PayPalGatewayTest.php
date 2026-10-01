<?php

namespace Omnitrade\PayPal\Tests;

use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Model\Money;
use Omnitrade\Model\Status;
use Omnitrade\PayPal\PayPalGatewayFactory;
use Omnitrade\Tests\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PayPalGatewayTest extends TestCase
{
    /** @var list<array{string, string, array}> */
    private array $calls = [];
    private bool $captured = false;
    private int $tokens = 0;

    private function gateway(): \Omnitrade\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringStartsWith('https://api-m.sandbox.paypal.com', $url);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $body = $options['body'] ?? '';
            $sent = \is_string($body) && '' !== $body && str_starts_with($body, '{') ? json_decode($body, true) : [];
            $this->calls[] = [$method, $path, \is_array($sent) ? $sent : []];
            if ('/v1/oauth2/token' === $path) {
                ++$this->tokens;

                return new MockResponse(json_encode(['access_token' => 'A21AA', 'expires_in' => 32400]));
            }
            self::assertContains('Authorization: Bearer A21AA', $options['headers']);

            return match (true) {
                'POST' === $method && '/v2/checkout/orders' === $path => new MockResponse(json_encode(self::order('CREATED', $sent['intent'] ?? 'CAPTURE'))),
                'GET' === $method && '/v2/checkout/orders/5O190127TN364715T' === $path => new MockResponse(json_encode($this->captured ? self::order('COMPLETED', 'CAPTURE', true) : self::order('APPROVED', 'CAPTURE'))),
                'POST' === $method && '/v2/checkout/orders/5O190127TN364715T/capture' === $path => (function () { $this->captured = true; return new MockResponse(json_encode(self::order('COMPLETED', 'CAPTURE', true))); })(),
                'POST' === $method && '/v2/payments/captures/3C679366HH908993F/refund' === $path => new MockResponse(json_encode(['id' => '1JU08902781691411', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '5.00']])),
                'POST' === $method && '/v1/notifications/verify-webhook-signature' === $path => new MockResponse(json_encode(['verification_status' => 'SUCCESS' === ($sent['transmission_sig'] ?? null) ? 'SUCCESS' : 'FAILURE'])),
                default => new MockResponse(json_encode(['name' => 'RESOURCE_NOT_FOUND', 'message' => 'No such resource: '.$method.' '.$path]), ['http_code' => 404]),
            };
        });

        return (new PayPalGatewayFactory($http))->create(['client_id' => 'cid', 'secret' => 'sec', 'sandbox' => true, 'webhook_id' => 'WH-1']);
    }

    public function testAPurchaseIsAnOrderApprovedOnPayPalsPageThenCaptured(): void
    {
        $gateway = $this->gateway();
        $transaction = $gateway->purchase(Fixtures::payment());

        self::assertTrue($transaction->isRedirect());
        self::assertSame('5O190127TN364715T', $transaction->reference);
        self::assertSame('https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T', $transaction->redirectUrl);
        [, , $sent] = $this->calls[1];
        self::assertSame('CAPTURE', $sent['intent']);
        self::assertSame('12.50', $sent['purchase_units'][0]['amount']['value']);
        self::assertSame('ORDER-1042', $sent['purchase_units'][0]['custom_id']);
        self::assertSame('Dix heures', $sent['purchase_units'][0]['items'][0]['name']);
        self::assertSame('https://shop.example/retour', $sent['payment_source']['paypal']['experience_context']['return_url']);
        self::assertSame(1, $this->tokens, 'one token for the session');

        // The buyer is back: the money is taken, and taking it twice is harmless.
        $paid = $gateway->capture('5O190127TN364715T', null, 'attempt-1');
        self::assertTrue($paid->isPaid());
        self::assertSame('3C679366HH908993F', $paid->metadata['capture']);
        self::assertTrue($gateway->capture('5O190127TN364715T')->isPaid());
        self::assertCount(1, array_filter($this->calls, fn ($c) => str_ends_with($c[1], '/capture')), 'captured once');
        self::assertSame(1, $this->tokens, 'the token is kept');
    }

    public function testARefundGoesOnTheOrdersCapture(): void
    {
        $gateway = $this->gateway();
        $gateway->capture('5O190127TN364715T');
        $refund = $gateway->refund('5O190127TN364715T', Money::of(500, 'EUR'), 'refund-42', 'Trop perçu');

        self::assertSame('1JU08902781691411', $refund->reference);
        self::assertSame(Status::REFUNDED, $refund->status);
        self::assertSame(500, $refund->amount->amount);
        $post = array_values(array_filter($this->calls, fn ($c) => str_ends_with($c[1], '/refund')))[0];
        self::assertSame('5.00', $post[2]['amount']['value']);
        self::assertSame('Trop perçu', $post[2]['note_to_payer']);
    }

    public function testANotificationIsVerifiedByPayPalThenRead(): void
    {
        $gateway = $this->gateway();
        $event = json_encode(['id' => 'WH-EVT-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => '3C679366HH908993F', 'status' => 'COMPLETED', 'supplementary_data' => ['related_ids' => ['order_id' => '5O190127TN364715T']]]]);
        $headers = fn (string $sig) => ['PAYPAL-TRANSMISSION-ID' => 't1', 'PAYPAL-TRANSMISSION-TIME' => '2026-10-01T10:00:00Z', 'PAYPAL-TRANSMISSION-SIG' => $sig, 'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/cert', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA'];

        $notification = $gateway->notify($event, $headers('SUCCESS'));
        self::assertSame('PAYMENT.CAPTURE.COMPLETED', $notification->event);
        self::assertSame('5O190127TN364715T', $notification->reference, 'the order the capture belongs to');
        self::assertSame(Status::PAID, $notification->status);
        self::assertSame('WH-EVT-1', $notification->id);

        $this->expectException(InvalidNotificationException::class);
        $gateway->notify($event, $headers('forged'));
    }

    private static function order(string $status, string $intent, bool $captured = false): array
    {
        $unit = ['reference_id' => 'default', 'custom_id' => 'ORDER-1042', 'amount' => ['currency_code' => 'EUR', 'value' => '12.50']];
        if ($captured) {
            $unit['payments'] = ['captures' => [['id' => '3C679366HH908993F', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '12.50']]]];
        }

        return ['id' => '5O190127TN364715T', 'intent' => $intent, 'status' => $status, 'create_time' => '2026-10-01T10:00:00Z', 'purchase_units' => [$unit],
            'payer' => ['email_address' => 'camille@example.org'], 'payment_source' => ['paypal' => []],
            'links' => [['href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/5O190127TN364715T', 'rel' => 'self'], ['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T', 'rel' => 'payer-action']]];
    }
}
