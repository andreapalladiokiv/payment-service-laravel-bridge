<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Techork\PaymentService\Laravel\Casts\UuidValueObjectCast;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * `gateway_customers`, keyed on our customer through `customer_id`.
 *
 * The `references()` relation is gone with the pivot's last reader.
 * `gateway_reference_customer` linked a provider-side customer to an instrument's *reference*,
 * which is why identity was unreachable for a raw card and reachable through an expiring token.
 * The table itself is left standing and empty of readers: it is the only surviving record of which
 * cards a provider knew under one customer, so it is the evidence anything that later decides two
 * of our customers are one person would start from. History, not a dependency — nothing here reads
 * it, and nothing should start to.
 *
 * @property string $id
 * @property GatewayId $gateway_id
 * @property ?string $customer_id
 * @property string $customer_reference
 */
final class GatewayCustomer extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $casts = [
        'gateway_id' => UuidValueObjectCast::class.':'.GatewayId::class,
    ];

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }
}
