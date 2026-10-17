<?php

namespace App\Http\Requests;

/**
 * The visit list takes the same day range as the other stats endpoints, plus
 * which page of it to return.
 */
class StatsVisitsRequest extends StatsRangeRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
