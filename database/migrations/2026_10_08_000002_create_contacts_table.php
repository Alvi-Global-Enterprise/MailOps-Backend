<?php

use App\Products\MailForge\Enums\ContactSourceType;
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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->nullable();
            $table->string('email');
            $table->string('company')->nullable();
            $table->string('job_title')->nullable();
            $table->string('phone')->nullable();
            $table->jsonb('custom_data')->nullable();
            $table->string('source')->default(ContactSourceType::MANUAL->value);
            $table->string('source_reference')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'email']);
            $table->index('user_id');
            $table->index('email');
            $table->index(['source', 'source_reference']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
