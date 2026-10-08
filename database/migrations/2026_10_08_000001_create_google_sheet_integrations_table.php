<?php

use App\Products\MailForge\Enums\SyncStatus;
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
        Schema::create('google_sheet_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('spreadsheet_id');
            $table->string('sheet_name')->default('Sheet1');
            $table->unsignedInteger('header_row')->default(1);
            $table->jsonb('column_mapping')->nullable();
            $table->text('credentials_path')->nullable();
            $table->string('sync_status')->default(SyncStatus::IDLE->value);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedInteger('total_synced_contacts')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_sheet_integrations');
    }
};
