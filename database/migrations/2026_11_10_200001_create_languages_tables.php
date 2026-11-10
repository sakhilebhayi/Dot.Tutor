<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-47 academic domain (D-200 block): languages as data, and the languages a
 * person holds by role (interface, understands, prefers_teaching, learning,
 * can_teach_in). Matching reads teaching languages, never the interface locale.
 * Reference rows are inserted by `php artisan academic:sync`, not here, so the
 * schema migration stays data-free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique(); // BCP 47
            $table->string('name_en', 80);
            $table->string('name_native', 80);
            $table->string('script', 4)->nullable(); // ISO 15924; null for a signed language
            $table->string('modality', 10)->default('written'); // written | signed
            $table->boolean('is_official_za')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('aliases')->nullable();
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();
        });

        Schema::create('user_languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->restrictOnDelete();
            $table->string('role', 20);
            $table->string('proficiency', 20)->nullable(); // self-declared; required for can_teach_in
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'language_id', 'role']);
            $table->index(['language_id', 'role']);
            $table->index(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        // Reference rows point at each other with RESTRICT; the rollback drops them together.
        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::dropIfExists('user_languages');
            Schema::dropIfExists('languages');
        });
    }
};
