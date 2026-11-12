<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * R-47 student UI (D-1102): one row per person for how the learner screens look to them. Today only the
 * reading step: "auto" follows the academic stage and age band, "young" asks for the larger, simpler
 * step, "standard" turns it off. Nothing about the person's age or school is stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('step', 12)->default('auto');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_preferences');
    }
};
