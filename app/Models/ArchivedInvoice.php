<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invoice from the system used before (MI07): read-only.
 *
 * @property int $id
 * @property string $number
 * @property Carbon|null $issued_on
 * @property string|null $payer_name
 * @property int|null $person_id
 * @property int $total_cents
 * @property string $currency
 * @property string $status
 * @property Carbon|null $paid_on
 * @property string|null $note
 * @property string|null $pdf_path
 */
class ArchivedInvoice extends Model
{
    public const STATUSES = ['paid', 'open', 'cancelled'];

    public const DIRECTORY = 'invoice-archive';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'paid_on' => 'date', 'total_cents' => 'integer'];
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
