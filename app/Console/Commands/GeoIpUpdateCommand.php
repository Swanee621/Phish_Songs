<?php

namespace App\Console\Commands;

use GeoIp2\Database\Reader;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PharData;
use Psr\Http\Message\StreamInterface;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Downloads the GeoLite2 City database that visitor addresses are resolved
 * against. The new file is unpacked and checked beside the live one and only
 * then renamed over it, so a failed download never leaves the site without a
 * database.
 */
class GeoIpUpdateCommand extends Command
{
    protected $signature = 'geoip:update';

    protected $description = 'Download the latest MaxMind GeoLite2 City database for visitor stats';

    /**
     * Every MMDB file ends with this marker in front of its metadata.
     */
    protected const METADATA_MARKER = "\xab\xcd\xefMaxMind.com";

    public function handle(): int
    {
        $accountId = (string) config('services.maxmind.account_id');
        $licenseKey = (string) config('services.maxmind.license_key');
        $edition = (string) config('services.maxmind.edition');
        $destination = (string) config('services.maxmind.database_path');

        if ($accountId === '' || $licenseKey === '') {
            $this->error('MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY must be set (free account at maxmind.com).');

            return self::FAILURE;
        }

        $workDir = dirname($destination).'/tmp';
        File::ensureDirectoryExists($workDir);

        $archive = $workDir.'/'.$edition.'.tar.gz';
        $extracted = $workDir.'/'.$edition.'.mmdb';

        try {
            $this->components->task("Downloading {$edition}", function () use ($accountId, $licenseKey, $edition, $archive) {
                $response = Http::withBasicAuth($accountId, $licenseKey)
                    ->timeout(120)
                    ->get("https://download.maxmind.com/geoip/databases/{$edition}/download", ['suffix' => 'tar.gz']);

                if (! $response->successful()) {
                    throw new RuntimeException("MaxMind responded {$response->status()}.");
                }

                $this->streamToFile($response->toPsrResponse()->getBody(), $archive);
            });

            $this->components->task('Unpacking', fn () => $this->extractDatabase($archive, $extracted));

            $this->components->task('Checking', fn () => $this->assertLooksLikeCityDatabase($extracted, $edition));

            File::ensureDirectoryExists(dirname($destination));

            if (! rename($extracted, $destination)) {
                throw new RuntimeException("Could not move the database into place at {$destination}.");
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($workDir);
        }

        $builtAt = $this->buildDate($destination);
        $this->components->info($edition.' installed'.($builtAt ? " (data from {$builtAt})" : '').'.');

        return self::SUCCESS;
    }

    /**
     * Copies the body to disk in chunks: the archive runs to tens of
     * megabytes, which has no business sitting in a PHP string.
     */
    protected function streamToFile(StreamInterface $body, string $path): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Could not write to {$path}.");
        }

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            while (! $body->eof()) {
                fwrite($handle, $body->read(1024 * 1024));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The archive holds one dated folder with the `.mmdb` plus licence text;
     * only the database is kept.
     */
    protected function extractDatabase(string $archive, string $target): void
    {
        $tar = new PharData($archive);

        /** @var SplFileInfo $entry */
        foreach (new RecursiveIteratorIterator($tar) as $entry) {
            if (str_ends_with($entry->getFilename(), '.mmdb')) {
                copy($entry->getPathname(), $target);

                return;
            }
        }

        throw new RuntimeException('The archive did not contain an .mmdb file.');
    }

    /**
     * A cheap sanity check that the bytes are an MMDB of the expected edition,
     * without depending on the reader's full parse.
     */
    protected function assertLooksLikeCityDatabase(string $path, string $edition): void
    {
        $tail = (string) file_get_contents($path, false, null, max(0, filesize($path) - 131072));

        if (! str_contains($tail, self::METADATA_MARKER) || ! str_contains($tail, $edition)) {
            throw new RuntimeException("The downloaded file does not look like a {$edition} database.");
        }
    }

    protected function buildDate(string $path): ?string
    {
        try {
            $reader = new Reader($path);
            $date = Carbon::createFromTimestamp($reader->metadata()->buildEpoch)->toDateString();
            $reader->close();

            return $date;
        } catch (Throwable) {
            return null;
        }
    }
}
