<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Weekly recurring hours. weekday is ISO-8601 (1 = Monday ... 7 = Sunday);
        // times are local wall-clock 'HH:MM' in the tutor profile's timezone.
        Schema::create('tutor_availability_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->timestamps();

            $table->index(['tutor_profile_id', 'weekday']);
        });

        // Whole local dates a tutor is not teaching, on top of the weekly hours.
        Schema::create('tutor_blocked_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['tutor_profile_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_blocked_dates');
        Schema::dropIfExists('tutor_availability_windows');
    }
};
