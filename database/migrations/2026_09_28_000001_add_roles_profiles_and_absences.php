<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('roles')->nullable()->after('password');
        });

        // Former admin flag and OM status of the linked member become roles.
        $omMembers = DB::table('members')->where('is_om', true)->pluck('id')->flip();

        foreach (DB::table('users')->get(['id', 'is_admin', 'member_id']) as $user) {
            $roles = array_values(array_filter([
                $user->is_admin ? 'admin' : null,
                $user->member_id && $omMembers->has($user->member_id) ? 'om' : null,
            ]));

            DB::table('users')->where('id', $user->id)->update(['roles' => json_encode($roles)]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->string('phone', 40)->nullable()->after('email');
            $table->text('cover_preferences')->nullable()->after('interest_themes');
            $table->text('philatelic_references')->nullable()->after('cover_preferences');
        });

        Schema::create('member_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_absences');

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['phone', 'cover_preferences', 'philatelic_references']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        foreach (DB::table('users')->get(['id', 'roles']) as $user) {
            DB::table('users')->where('id', $user->id)
                ->update(['is_admin' => in_array('admin', json_decode($user->roles ?? '[]', true), true)]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('roles');
        });
    }
};
