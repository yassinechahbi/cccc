<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the reference data. Members come from `php artisan cccc:import-members`.
     */
    public function run(): void
    {
        $now = now();

        Country::upsert(
            collect(require database_path('data/countries.php'))
                ->map(fn (string $name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now])
                ->all(),
            ['name']
        );
    }
}
