<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The optional inclusive day range the stats endpoints filter on. Either end
 * may be left off for "from the first visit" / "to the latest".
 */
class StatsRangeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function from(): ?CarbonImmutable
    {
        $value = $this->validated('from');

        return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
    }

    public function to(): ?CarbonImmutable
    {
        $value = $this->validated('to');

        return $value === null ? null : CarbonImmutable::parse($value)->endOfDay();
    }
}
