<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('member_number')->unique(); // the "CCCC#"
            $table->string('name');
            $table->string('title')->nullable();   // club function, e.g. "MD-10"
            $table->boolean('is_om')->default(false); // Originating Member
            $table->string('om_code', 10)->nullable(); // e.g. "HAE"
            $table->string('address')->nullable();
            $table->string('city')->nullable();       // postcode + city, free form
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->text('interest_countries')->nullable();
            $table->text('interest_themes')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable()->unique()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_admin')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
            $table->dropColumn('is_admin');
        });

        Schema::dropIfExists('members');
    }
};
