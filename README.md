# Laravel bridge

`techork/payment-service-laravel` binds the Common / Domain / Gateway contracts
to a Laravel application: Eloquent persistence, EventSauce event-sourcing glue,
webhook ingestion (spatie/laravel-webhook-client), PII shredding, sanitized
gateway logging and validation rules. Two auto-discovered service providers do
all the wiring: `GatewayServiceProvider` and `Webhook\WebhookServiceProvider`.

## Gateway wiring

`GatewayServiceProvider` binds every Gateway-package repository contract to an
Eloquent implementation and builds `LaravelGatewayFactory` (also installed as
the global Omnipay factory). Gateway implementations are **discovered, not
hard-wired**: each gateway package declares its class under
`extra.laravel.gateway` in its own `composer.json`; the provider walks
Laravel's `PackageManifest` and registers every class implementing
`Gateway`, keyed by `getName()`.

`LaravelGatewayFactory` layers infrastructure parameters from the
`services.{gateway_name}` app config on top of the per-tenant credentials and
then **re-initializes** the gateway, so a stored `environment=production`
credential can never leak into a dev build (and clients that bake base URLs at
`initialize()` time, e.g. ConnexPay, pick up the override).

## Persistence

| Model | Table | Role |
| --- | --- | --- |
| `Models\Gateway` | `gateways` | Per-tenant credential record, implements `GatewayCredential`; `credentials` cast `encrypted:json` |
| `Models\GatewayReference` | `gateway_references` | Polymorphic map (gateway, referenceable_type, referenceable_id) → gateway-side `reference`, plus `failure_reason` and JSON `metadata` |
| `Models\GatewayCustomer` | `gateway_customers` (+ pivot `gateway_reference_customer`) | Gateway-side customer references; the pivot's unique `gateway_reference_id` ties a reference to exactly one customer |
| `Models\ShreddingValue` | `shredding_values` | sha256-hash-keyed plaintext PII store |
| `Webhook\Model\WebhookCall` | `webhook_calls` (extended) | Spatie webhook record + `gateway_id`, `external_id`, `status`, `processed_at` |

Repositories (all Eloquent-backed, bound as singletons):
`EloquentGatewayCredentialRepository`, `EloquentCustomerRepository`,
`EloquentGatewayInstrumentRepository` (morph type = instrument `::type()`;
only `Token` / `PaymentMethod` instruments carry an id worth persisting),
`EloquentGatewayTransactionRepository` (morph types `payment_intent` /
`refund`; one row per aggregate, the reference is **overwritten on
transition** — auth-ref becomes charge-ref on capture — while empty `metadata`
never erases previously stored metadata) and
`EloquentVirtualCardReferenceRepository` (morph type `virtual_card`).

## Event sourcing

* `IlluminateMessageRepository` — EventSauce `MessageRepository` on the
  `stored_events` table (inlined from `eventsauce/message-repository-for-illuminate`
  to drop its Laravel version ceiling); string aggregate ids, UUIDv7 event ids.
* `IlluminateSnapshotRepository` — one upserted snapshot per aggregate in
  `aggregate_snapshots`. Neither table's migration ships with this package;
  the consuming app provides them.
* `CheckoutAggregateRepository`, `PaymentIntentAggregateRepository`,
  `SubscriptionAggregateRepository` — snapshotting repositories bound to the
  Domain package's `*AggregateRepositoryInterface`s; `retrieve()` restores
  from snapshot.
* `SymfonyPayloadSerializer` serializes event payloads through a
  Symfony Serializer built from `PropertyNormalizer` plus `UuidNormalizer`,
  `PhoneNumberNormalizer` (E164 string round-trip) and visitor-based
  discriminator normalizers (`PaymentInstrumentNormalizer`,
  `ChallengeNormalizer`, `ChallengeResultNormalizer`) that resolve
  interface-typed slots to concrete classes via a `type` key.
* `LaravelMessageConsumer` re-dispatches every persisted event through
  Laravel's event dispatcher as `[$event, $message]` keyed by the event class;
  before a replay it truncates every model tagged `es.replayable_models`.
* `GatewayIdMessageDecorator` stamps a `__gateway_id` header from Laravel's
  `Context` (`gateway_id`) onto every message.

## PII shredding

Properties marked `#[Pii]` (Common) on event classes are intercepted by
`PiiAwareObjectNormalizer`: on write the value is swapped for a sha256 hash
stored via `EloquentPiiStore` in `shredding_values`; on read the hash is
resolved back — or, once the row has been deleted (shredded), replaced by the
attribute's stub. `PiiAttributeLoader` validates, when a class's metadata is
first loaded, that each stub is assignable to its property type. Non-`string` properties are `serialize()`-d
so the type survives storage. The event stream itself never needs rewriting.

## PaymentIntent ports

| Class | Domain port | Behavior |
| --- | --- | --- |
| `Port\OmnipayCreatePort` | `CreatePort` | `charge()` for `CaptureMethod::Immediate`, `authorize()` otherwise; persists reference + metadata; decline → `GatewayDeclinedException` |
| `Port\FraudScreeningCreatePort` | `CreatePort` (decorator) | Screens CIT card payments through `RiskDecisionPort`; short-circuits with a `ThreeDSChallenge` when step-up is required and no successful 3DS result is attached. MIT, non-card instruments and already-authenticated 3DS pass straight through |
| `Port\OmnipayCapturePort` | `CapturePort` | Captures the stored gateway transaction with idempotency key `{paymentIntentId}:capture` |
| `Port\OmnipayCancelPort` | `CancelPort` | Voids the stored gateway transaction with idempotency key `{paymentIntentId}:cancel` |
| `Port\OmnipayRefundPort` | `RefundPort` | Refunds against the parent PI's gateway transaction; saves the refund reference |

Create/capture ports carry the gateway's synchronous FX `convertedAmount` into
`CreateOutcome` / `CaptureOutcome`.

## Webhooks

One spatie config entry serves all providers. `WebhookServiceProvider`
discovers each gateway package's `WebhookSubscriber` from its
`extra.laravel.webhook` composer entry (lazily — nothing is instantiated until
`VerifierRegistry` / `HandlerRegistry` is first resolved).

Flow: `SpatieSignatureValidatorAdapter` bridges the request to PSR-7, asks
`WebhookRouter::identifyGateway()` to verify + identify the tenant, and stashes
`{gateway_id, kind, external_id}` as a request attribute. `IdempotencyProfile`
rejects duplicate `(kind, external_id)` deliveries (a unique index enforces the
same invariant). `WebhookCall::storeWebhook()` records the call as `pending`;
the queued `ProcessWebhookJob` routes it and maps `HandlerOutcome`
Processed / Skipped / Delay — Delay throws so the queue's backoff schedule
drives the retry.

Handlers land on Eloquent recorders that replay gateway-decided outcomes onto
the aggregates through no-op `ExternallyCompleted{Capture,Cancel,Refund}Port`s:
`EloquentPaymentIntentRecorder` (success / authorization / failure /
cancellation; inline-duplicate signals are Skipped) and
`EloquentRefundRecorder` (creates the refund child aggregate; a reference
already known to `EloquentTransactionIdResolver` is a duplicate).
`GatewayPaymentMethodRecorder` defaults to a no-op and `GatewayFeeRecorder`
has no default binding — apps with local storage bind their own.

## Logging & validation

`SanitizingLogger` (bound as `GatewayLoggerInterface`) walks the log context
tree and masks values via tagged sanitizers: `CardNumberSanitizer` (13–19
digit Luhn-valid runs, key-independent), `EmailSanitizer`,
`PhoneNumberSanitizer` and `ByPropertyNameSanitizer` (`first_name`,
`last_name`, `line`, `line_extra`, `holder`).

Registered validation rules: `country[:alpha2|alpha3|numeric]`,
`phone[:e164|international|national|rfc3966]`, `state:<country_field>`,
`currency` (ISO + crypto), `duration` (ISO 8601).

## Testing

Pure unit tests (Pest + Mockery) — no database, framework boot or credentials
required.
