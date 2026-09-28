<?php

namespace App\Console\Commands;

use App\Enums\MemberStatus;
use App\Models\Country;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Imports the member directory exported by the legacy site as a PDF.
 *
 * The PDF must first be converted with xpdf/poppler:
 *   pdftotext -enc UTF-8 -table My-File-Name.pdf storage/app/private/legacy/members.txt
 */
class ImportLegacyMembers extends Command
{
    protected $signature = 'cccc:import-members
        {file=storage/app/private/legacy/members.txt : Text produced by pdftotext -table}
        {--dry-run : Parse and report without writing to the database}';

    protected $description = 'Import the legacy CCCC member directory';

    private const COLUMNS = [
        'id' => 'Id',
        'name' => 'Name',
        'address' => 'Address',
        'city' => 'City',
        'country' => 'Country',
        'email' => 'E-mail',
        'interest_countries' => 'Interest Countries',
        'interest_themes' => 'Interest Themes',
        'remarks' => 'Remarks',
    ];

    private const COUNTRY_ALIASES = [
        'FYROM' => 'NORTH MACEDONIA',
        'EAST TIMOR' => 'TIMOR-LESTE',
        'KOREA' => 'SOUTH KOREA',
        'MACAO' => 'MACAU',
        'PHILLIPINES' => 'PHILIPPINES',
        'TUNESIA' => 'TUNISIA',
        'BRUNEI DARUSSALEM' => 'BRUNEI DARUSSALAM',
        'BOSNIA & HERZEGOVINA' => 'BOSNIA AND HERZEGOVINA',
        'LAO P.D.R.' => "LAO PEOPLE'S DEMOCRATIC REPUBLIC",
        'RUSSIAN FEDERATION' => 'RUSSIA',
        'SLOVAK REPUBLIC' => 'SLOVAKIA',
    ];

    public function handle(): int
    {
        $path = base_path($this->argument('file'));

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $records = $this->parse(file_get_contents($path));
        $this->info(count($records).' records found.');

        $seen = [];
        $duplicates = [];
        $rows = [];

        $temporary = [];

        foreach ($records as $record) {
            // Registrations never given a CCCC number: the club must create them by hand.
            if (str_starts_with($record['id'][0], 'temp')) {
                $temporary[] = implode('', $record['id']).' '.implode(' ', $record['name']);

                continue;
            }

            $row = $this->toMember($record);

            if (isset($seen[$row['member_number']])) {
                $duplicates[] = $row['member_number'].' '.$row['name'];

                continue;
            }

            $seen[$row['member_number']] = true;
            $rows[] = $row;
        }

        if ($temporary) {
            $this->warn(count($temporary).' registrations without a member number skipped:');
            $this->line('  '.implode("\n  ", $temporary));
        }

        if ($duplicates) {
            $this->warn(count($duplicates).' duplicate member numbers skipped (first occurrence kept):');
            $this->line('  '.implode("\n  ", $duplicates));
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['#', 'Name', 'Title', 'OM', 'Code', 'City', 'Country', 'Email', 'Status'],
                collect($rows)->random(min(15, count($rows)))->map(fn ($r) => [
                    $r['member_number'], $r['name'], $r['title'], $r['is_om'] ? 'yes' : '', $r['om_code'],
                    $r['city'], $r['country'], $r['email'], $r['status']->value,
                ])
            );

            return self::SUCCESS;
        }

        $countries = Country::pluck('id', 'name');
        $created = [];

        foreach ($rows as $row) {
            $country = $row['country'];

            if ($country !== null && ! $countries->has($country)) {
                $countries[$country] = Country::create(['name' => $country])->id;
                $created[] = $country;
            }

            Member::updateOrCreate(
                ['member_number' => $row['member_number']],
                [...collect($row)->except(['member_number', 'country'])->all(), 'country_id' => $countries[$country] ?? null]
            );
        }

        if ($created) {
            $this->warn('Countries not in the reference list, created: '.implode(', ', $created));
        }

        $this->info(count($rows).' members imported.');
        $this->table(['Status', 'Members'], collect($rows)->countBy(fn ($r) => $r['status']->value)->map(fn ($n, $s) => [$s, $n])->values());
        $this->line('With e-mail: '.collect($rows)->whereNotNull('email')->count().' | OMs: '.collect($rows)->where('is_om', true)->count());

        return self::SUCCESS;
    }

    /**
     * Split the text export into records, one per member id, with each cell's lines joined.
     *
     * @return list<array<string, list<string>>>
     */
    private function parse(string $text): array
    {
        $records = [];
        $current = null;

        foreach (explode("\f", $text) as $page) {
            $lines = preg_split('/\R/u', $page);
            $headerIndex = collect($lines)->search(fn ($l) => str_starts_with(ltrim($l), 'Id ') && str_contains($l, 'Remarks'));

            if ($headerIndex === false) {
                continue;
            }

            $starts = $this->columnStarts($lines[$headerIndex]);

            // The header is usually on top, but the last page prints it below some rows.
            foreach ($lines as $index => $line) {
                if ($index === $headerIndex || trim($line) === '' || preg_match('#^\s*\d+\s*/\s*\d+\s*$|^Powered by#', $line)) {
                    continue; // header, blank line or page footer ("1/ 102")
                }

                $cells = $this->cells($line, $starts);

                // A record starts on the line holding both its id and its name; long temporary
                // ids ("temp1" / "77063" / "4095") wrap onto the following lines.
                if (isset($cells['id'], $cells['name']) && preg_match('/^(\d+|temp\d*)$/', $cells['id'])) {
                    if ($current) {
                        $records[] = $current;
                    }
                    $current = [];
                }

                if ($current === null) {
                    continue;
                }

                foreach ($cells as $column => $value) {
                    $current[$column][] = $value;
                }
            }
        }

        if ($current) {
            $records[] = $current;
        }

        return $records;
    }

    /** @return array<string, int> column name => character offset */
    private function columnStarts(string $header): array
    {
        $starts = [];

        foreach (self::COLUMNS as $key => $label) {
            // Some pages space the words of a label differently ("Interest  Countries").
            $pattern = '/(?<!\S)'.str_replace(' ', '\s+', preg_quote($label, '/')).'(?!\S)/u';

            if (preg_match($pattern, $header, $m, PREG_OFFSET_CAPTURE)) {
                $starts[$key] = mb_strlen(substr($header, 0, $m[0][1]));
            }
        }

        // Last column "A" holds + (active) or -; missing on a few pages.
        if (preg_match('/\sA\s*$/u', $header, $m, PREG_OFFSET_CAPTURE)) {
            $starts['active'] = mb_strlen(substr($header, 0, $m[0][1])) + 1;
        }

        asort($starts);

        return $starts;
    }

    /**
     * Cut a line into segments separated by 2+ spaces and assign each to a column.
     *
     * @param  array<string, int>  $starts
     * @return array<string, string>
     */
    private function cells(string $line, array $starts): array
    {
        preg_match_all('/\S+(?: \S+)*/u', $line, $matches, PREG_OFFSET_CAPTURE);

        $cells = [];

        foreach ($matches[0] as [$segment, $byteOffset]) {
            $offset = mb_strlen(substr($line, 0, $byteOffset)) + 2; // small tolerance for left overflow
            $column = 'id';

            foreach ($starts as $key => $start) {
                if ($start <= $offset) {
                    $column = $key;
                }
            }

            $cells[$column] = isset($cells[$column]) ? $cells[$column].' '.$segment : $segment;
        }

        return $cells;
    }

    /** @param  array<string, list<string>>  $record */
    private function toMember(array $record): array
    {
        $join = fn (string $column, string $glue = ' ') => ($v = trim(implode($glue, $record[$column] ?? []))) === '' ? null : $v;

        [$title, $isOm, $omCode, $name] = $this->splitName($join('name'));

        $email = Str::lower((string) $join('email', ''));
        $remarks = $join('remarks');
        // Pages without the "A" column give no information: assume active.
        $active = ! isset($record['active']) || str_contains(implode('', $record['active']), '+');

        $status = match (true) {
            str_contains((string) $remarks, 'deceased') => MemberStatus::Deceased,
            str_contains((string) $remarks, 'resigned') => MemberStatus::Resigned,
            str_contains((string) $remarks, 'returned mail') => MemberStatus::ReturnedMail,
            $active => MemberStatus::Active,
            default => MemberStatus::Inactive,
        };

        $country = $join('country');
        $country = $country ? str_replace('`', "'", preg_replace('/;\s*/', ', ', Str::upper($country))) : null;

        return [
            'member_number' => (int) $join('id'),
            'name' => $name,
            'title' => $title,
            'is_om' => $isOm,
            'om_code' => $omCode,
            'address' => $join('address'),
            'city' => $join('city'),
            'country' => self::COUNTRY_ALIASES[$country] ?? $country,
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'interest_countries' => $this->interest($join('interest_countries')),
            'interest_themes' => $this->interest($join('interest_themes')),
            'status' => $status,
            'remarks' => in_array($remarks, [null, 'no remarks'], true) ? null : $remarks,
        ];
    }

    /**
     * The legacy site stored club functions inside the name, CSV-escaped:
     * "OM ""HAE"" Kimmo Liljeroos", "MD-10 ""EI"" Holger Kaufhold", MD 6 Allan J. Bagnall, "OM" Nehemiah Ames.
     *
     * @return array{0: ?string, 1: bool, 2: ?string, 3: string}
     */
    private function splitName(?string $raw): array
    {
        $name = trim((string) $raw);

        if (str_starts_with($name, '"') && str_ends_with($name, '"') && strlen($name) > 1) {
            $name = substr($name, 1, -1);
        }

        $name = str_replace('""', '"', $name);

        if (! preg_match('/^"?(OM|MD[- ]?\d+)"?\s+(?:"([^"]*)"\s*)?(.+)$/u', $name, $m)) {
            return [null, false, null, $name];
        }

        $title = str_starts_with($m[1], 'MD') ? 'MD-'.preg_replace('/\D/', '', $m[1]) : null;
        $code = $m[2] !== '' ? $m[2] : null;

        return [$title, $m[1] === 'OM' || $code !== null, $code, trim($m[3], ' "')];
    }

    private function interest(?string $value): ?string
    {
        return in_array(Str::lower((string) $value), ['', 'not available'], true) ? null : $value;
    }
}
