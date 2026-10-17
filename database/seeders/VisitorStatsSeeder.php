<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Enough made-up traffic to see the stats page working locally: a login and
 * a month of visits spread over real cities.
 */
class VisitorStatsSeeder extends Seeder
{
    use WithoutModelEvents;

    protected const DAYS = 30;

    protected const VISITS = 400;

    /**
     * City, region, region code, country code, lat, lng, and how popular it is
     * relative to the others.
     *
     * @var array<int, array{string, string, string, string, float, float, int}>
     */
    protected const CITIES = [
        ['Burlington', 'Vermont', 'VT', 'US', 44.4759, -73.2121, 9],
        ['New York', 'New York', 'NY', 'US', 40.7128, -74.0060, 10],
        ['Denver', 'Colorado', 'CO', 'US', 39.7392, -104.9903, 7],
        ['Chicago', 'Illinois', 'IL', 'US', 41.8781, -87.6298, 6],
        ['Boston', 'Massachusetts', 'MA', 'US', 42.3601, -71.0589, 6],
        ['Portland', 'Oregon', 'OR', 'US', 45.5152, -122.6784, 4],
        ['San Francisco', 'California', 'CA', 'US', 37.7749, -122.4194, 5],
        ['Los Angeles', 'California', 'CA', 'US', 34.0522, -118.2437, 4],
        ['Atlanta', 'Georgia', 'GA', 'US', 33.7490, -84.3880, 3],
        ['Austin', 'Texas', 'TX', 'US', 30.2672, -97.7431, 3],
        ['Toronto', 'Ontario', 'ON', 'CA', 43.6532, -79.3832, 3],
        ['London', 'England', 'ENG', 'GB', 51.5074, -0.1278, 2],
        ['Berlin', 'Berlin', 'BE', 'DE', 52.5200, 13.4050, 1],
    ];

    protected const PATHS = ['/', '/', '/', '/setlist-browser', '/setlist-browser', '/recent-setlists'];

    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password', 'email_verified_at' => now()],
        );

        $locations = collect(self::CITIES)->mapWithKeys(function (array $city) {
            [$name, $region, $regionCode, $countryCode, $lat, $lng] = $city;

            return [$name => Location::query()->firstOrCreate(
                ['key' => Location::keyFor($countryCode, $regionCode, $name, $lat, $lng)],
                [
                    'city' => $name,
                    'region' => $region,
                    'region_code' => $regionCode,
                    'country_code' => $countryCode,
                    'country' => $countryCode === 'US' ? 'United States' : $countryCode,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'accuracy_radius' => 20,
                ],
            )];
        });

        $weights = collect(self::CITIES)->mapWithKeys(fn (array $city) => [$city[0] => $city[6]]);
        $start = CarbonImmutable::now()->subDays(self::DAYS)->startOfDay();
        $rows = [];

        for ($i = 0; $i < self::VISITS; $i++) {
            $rows[] = $this->row($locations[$this->weightedCity($weights)], $start->addDays(random_int(0, self::DAYS)));
        }

        // A handful of visits whose address could not be placed.
        foreach (range(1, 6) as $n) {
            $rows[] = $this->row(null, $start->addDays(random_int(0, self::DAYS)));
        }

        Visit::query()->insert($rows);
    }

    /**
     * @param  Collection<string, int>  $weights
     */
    protected function weightedCity($weights): string
    {
        $pick = random_int(1, (int) $weights->sum());

        foreach ($weights as $city => $weight) {
            $pick -= $weight;

            if ($pick <= 0) {
                return $city;
            }
        }

        return (string) $weights->keys()->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(?Location $location, CarbonImmutable $day): array
    {
        return [
            'location_id' => $location?->id,
            'country_code' => $location?->country_code,
            'path' => self::PATHS[array_rand(self::PATHS)],
            'visited_at' => $day->addSeconds(random_int(0, 86_399)),
        ];
    }
}
