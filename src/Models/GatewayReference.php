<?php

declare(strict_types=1);

namespace Techork\PaymentService\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Techork\PaymentService\Laravel\Casts\UuidValueObjectCast;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

/**
 * @property string $id
 * @property GatewayId $gateway_id
 * @property string $referenceable_type
 * @property string $referenceable_id
 * @property string|null $reference
 * @property string|null $failure_reason
 * @property array|null $metadata JSON-encoded gateway-specific transaction attributes
 */
final class GatewayReference extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $casts = [
        'gateway_id' => UuidValueObjectCast::class.':'.GatewayId::class,
        'metadata' => 'json',
    ];

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function referenceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(
            GatewayCustomer::class,
            'gateway_reference_customer',
            'gateway_reference_id',
            'gateway_customer_id',
        );
    }
}
