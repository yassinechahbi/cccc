<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('circuits', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->foreignId('originating_member_id')->constrained('members');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('in_progress')->index();
            $table->date('mailed_at');
            $table->date('completed_at')->nullable();
            $table->text('om_message')->nullable(); // printed on the backside
            $table->timestamps();
        });

        Schema::create('circuit_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circuit_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->foreignId('member_id')->constrained();
            $table->boolean('is_return')->default(false); // last leg, back to the OM
            $table->string('code', 8);                    // secret printed under the QR code
            $table->date('received_at')->nullable();
            $table->date('mailed_at')->nullable();
            $table->unsignedTinyInteger('quality')->nullable(); // 1 = poor .. 5 = superior
            $table->text('comment')->nullable();
            $table->string('recorded_via', 10)->nullable();     // qr, code, proxy
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['circuit_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circuit_legs');
        Schema::dropIfExists('circuits');
    }
};
