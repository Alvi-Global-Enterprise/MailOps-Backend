<?php

use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\EmailMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('subject', 500)->nullable();
            $table->string('source_type')->default('all_contacts');
            $table->string('source_reference')->nullable();
            $table->string('email_mode')->default(EmailMode::DIRECT->value);
            $table->string('ai_provider')->nullable();
            $table->text('ai_prompt')->nullable();
            $table->text('raw_input_email')->nullable();
            $table->text('email_body')->nullable();
            $table->text('email_body_text')->nullable();
            $table->string('status')->default(CampaignStatus::DRAFT->value);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('status');
            $table->index('scheduled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
