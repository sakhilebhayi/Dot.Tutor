<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-47 marketplace: tutor profile v2. Additive only. Every new column is
 * nullable or defaulted, so existing rows and inserts keep working.
 *
 * hourly_rate_minor is the integer companion of the decimal hourly_rate
 * (architecture section 2). It is backfilled here from the decimal; the
 * decimal stays for history and the model keeps both in step on save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('hourly_rate_minor')->nullable()->after('hourly_rate');
            $table->text('teaching_approach')->nullable();
            $table->text('experience_summary')->nullable();
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamp('photo_updated_at')->nullable();
            $table->boolean('teaches_online')->default(true);
            $table->boolean('teaches_in_person')->default(false);
            $table->string('in_person_area', 120)->nullable();
            $table->unsignedSmallInteger('group_size_min')->nullable();
            $table->unsignedSmallInteger('group_size_max')->nullable();
            // Null until an operator has reviewed evidence and assigned a level.
            $table->string('verification_level', 40)->nullable();
            $table->foreignId('verification_level_set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verification_level_set_at')->nullable();
            $table->string('public_handle', 60)->nullable()->unique();
        });

        DB::table('tutor_profiles')->orderBy('id')->select(['id', 'hourly_rate'])->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('tutor_profiles')->where('id', $row->id)->update([
                    'hourly_rate_minor' => (int) round(((float) $row->hourly_rate) * 100),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verification_level_set_by');
            $table->dropUnique(['public_handle']);
        });
        Schema::table('tutor_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'hourly_rate_minor', 'teaching_approach', 'experience_summary', 'years_experience',
                'photo_path', 'photo_updated_at', 'teaches_online', 'teaches_in_person', 'in_person_area',
                'group_size_min', 'group_size_max', 'verification_level', 'verification_level_set_at', 'public_handle',
            ]);
        });
    }
};
