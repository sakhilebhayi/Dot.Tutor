<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| R-47 AI assistant (owner: ai agent). Every request to the assistant, including the ones refused before anything
| was sent, is one ai_interactions row: the safeguarding log. Text columns are encrypted at rest (casts on the model).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 20); // learner | tutor | translator
            $table->string('task', 40);
            $table->string('age_band', 10)->nullable();
            $table->boolean('age_known')->default(false);
            $table->string('mode', 20)->nullable();
            $table->string('status', 30);
            $table->string('refusal_code', 60)->nullable();
            $table->string('language', 20)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('prompt_version', 40)->nullable();
            $table->text('request_text')->nullable();
            $table->longText('prompt_text')->nullable();
            $table->longText('output_text')->nullable();
            $table->longText('output_data')->nullable();
            $table->json('context')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->boolean('reached_model')->default(false);
            $table->boolean('flagged')->default(false);
            $table->string('flag_reason', 120)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('purge_after')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['flagged', 'created_at']);
            $table->index('purge_after');
            $table->index(['reached_model', 'created_at']);
        });

        Schema::create('ai_question_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interaction_id')->nullable()->constrained('ai_interactions')->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('bank_id')->constrained('learning_question_banks')->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('prompt');
            $table->json('options')->nullable();
            $table->json('answer');
            $table->text('explanation')->nullable();
            $table->string('topic', 120)->nullable();
            $table->string('difficulty', 20)->nullable();
            $table->unsignedSmallInteger('marks')->default(1);
            $table->string('status', 20)->default('draft'); // draft | accepted | discarded
            $table->foreignId('question_id')->nullable()->constrained('learning_questions')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_question_drafts');
        Schema::dropIfExists('ai_interactions');
    }
};
