<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Techork\PaymentService\Laravel\Logger\Sanitizer\ByPropertyNameSanitizer;
use Techork\PaymentService\Laravel\Logger\Sanitizer\CardNumberSanitizer;
use Techork\PaymentService\Laravel\Logger\Sanitizer\EmailSanitizer;
use Techork\PaymentService\Laravel\Logger\Sanitizer\PhoneNumberSanitizer;
use Techork\PaymentService\Laravel\Logger\SanitizingLogger;

/**
 * Field list derives from what `Loggingthe gateway stack actually emits
 * — i.e. `PaymentInstrument::toPayload()`, `BillingAddress::toArray()`, and
 * the `VirtualCardResult` shape it summarises. This is the gateway-layer
 * contract, not the legacy Nuvei HTTP wire format.
 *
 *   instrument (CreditCard.toPayload) → holder
 *   billing_address (BillingAddress.toArray) → first_name, last_name, line,
 *                                              line_extra, city, postal_code,
 *                                              email (shape), phone (shape)
 *   VirtualCardResult summary → card_number (shape + name), cvv
 */
function gatewaySensitiveFields(): array
{
    return [
        'holder',
        'first_name',
        'last_name',
        'line',
        'line_extra',
        'city',
        'postal_code',
        'card_number',
        'cvv',
    ];
}

function recordingPsrLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var array<int, array{level: mixed, message: string|Stringable, context: array}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = compact('level', 'message', 'context');
        }
    };
}

function gatewaySanitizingLogger(AbstractLogger $inner): SanitizingLogger
{
    return new SanitizingLogger(
        $inner,
        LogLevel::INFO,
        new CardNumberSanitizer,
        new EmailSanitizer,
        new PhoneNumberSanitizer,
        new ByPropertyNameSanitizer(...gatewaySensitiveFields()),
    );
}

it('forwards the message at the configured level', function () {
    $inner = recordingPsrLogger();
    gatewaySanitizingLogger($inner)->log('gateway.request');

    expect($inner->records)->toHaveCount(1)
        ->and($inner->records[0]['level'])->toBe(LogLevel::INFO)
        ->and((string) $inner->records[0]['message'])->toBe('gateway.request');
});

it('masks a realistic gateway.request payload', function () {
    $inner = recordingPsrLogger();
    gatewaySanitizingLogger($inner)->log('gateway.request', [
        'request_id' => 'abc-123',
        'gateway_id' => 'stripe',
        'operation' => 'authorize',
        'input' => [
            'instrument' => [
                'type' => 'payment_method',
                'id' => '01J9X0F0G0AAABBBCCCDDD',
                'credit_card' => [
                    'type' => 'credit_card',
                    'first6' => '424242',
                    'last4' => '4242',
                    'brand' => 'visa',
                    'expiration' => '1230',
                    'holder' => 'Jane Doe',
                ],
                'billing_address' => [
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'line' => '1 Main St',
                    'line_extra' => 'Apt 4',
                    'city' => 'NYC',
                    'country' => 'US',
                    'postal_code' => '10001',
                    'state' => 'NY',
                    'email' => 'jane@example.com',
                    'phone' => '+14155552671',
                ],
            ],
            'amount' => ['amount' => '100', 'currency' => 'USD'],
        ],
    ]);

    $sanitized = $inner->records[0]['context'];
    $billing = $sanitized['input']['instrument']['billing_address'];
    $card = $sanitized['input']['instrument']['credit_card'];

    expect($card['holder'])->toBe('********')
        ->and($card['first6'])->toBe('424242')
        ->and($card['last4'])->toBe('4242')
        ->and($card['brand'])->toBe('visa')
        ->and($billing['first_name'])->toBe('****')
        ->and($billing['last_name'])->toBe('***')
        ->and($billing['line'])->toBe('*********')
        ->and($billing['line_extra'])->toBe('*****')
        ->and($billing['city'])->toBe('***')
        ->and($billing['postal_code'])->toBe('*****')
        ->and($billing['email'])->toBe('j***@example.com')
        ->and($billing['phone'])->toBe('+14155******')
        ->and($billing['country'])->toBe('US')
        ->and($billing['state'])->toBe('NY')
        ->and($sanitized['request_id'])->toBe('abc-123')
        ->and($sanitized['gateway_id'])->toBe('stripe')
        ->and($sanitized['operation'])->toBe('authorize');
});

it('masks card_number and cvv in a VirtualCardResult summary', function () {
    $inner = recordingPsrLogger();
    gatewaySanitizingLogger($inner)->log('gateway.response', [
        'output' => [
            'success' => true,
            'card_guid' => 'vc_01J9X0F0G0',
            'card_number' => '4242424242424242',
            'cvv' => '123',
            'expiration_date' => '12/30',
            'status' => 'active',
        ],
    ]);

    $out = $inner->records[0]['context']['output'];

    expect($out['card_number'])->toBe('************4242')
        ->and($out['cvv'])->toBe('***')
        ->and($out['success'])->toBeTrue()
        ->and($out['card_guid'])->toBe('vc_01J9X0F0G0')
        ->and($out['expiration_date'])->toBe('12/30')
        ->and($out['status'])->toBe('active');
});

it('catches a PAN by value-shape even under an unexpected key', function () {
    $inner = recordingPsrLogger();
    gatewaySanitizingLogger($inner)->log('gateway.error', [
        'message' => 'Invalid card 4242424242424242 — declined',
        'note' => 'no PAN here',
    ]);

    // Free-form PAN inside a string is NOT covered by the current pipeline
    // (the walker only feeds atomic values to sanitizers). But a PAN sitting
    // as its own value under any key is caught by CardNumberSanitizer.
    gatewaySanitizingLogger($inner)->log('gateway.error', [
        'random_key' => '4242424242424242',
    ]);

    expect($inner->records[1]['context']['random_key'])->toBe('************4242');
});
