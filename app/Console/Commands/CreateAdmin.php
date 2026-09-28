<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;

class CreateAdmin extends Command
{
    protected $signature = 'cccc:create-admin {email} {name} {--member= : CCCC member number to link} {--password=}';

    protected $description = 'Create (or promote) an administrator account';

    public function handle(): int
    {
        $member = null;

        if ($number = $this->option('member')) {
            $member = Member::where('member_number', $number)->first();

            if (! $member) {
                $this->error("Member #{$number} not found.");

                return self::FAILURE;
            }
        }

        $user = User::firstOrNew(['email' => Str::lower($this->argument('email'))]);
        $user->name = $this->argument('name');
        $user->password = $this->option('password') ?? ($user->exists ? $user->password : password('Password', required: true));
        $user->email_verified_at ??= now();
        $user->is_admin = true;
        $user->member()->associate($member ?? $user->member);
        $user->save();

        $this->info("Administrator {$user->email} ready".($member ? " (member #{$member->member_number})." : '.'));

        return self::SUCCESS;
    }
}
