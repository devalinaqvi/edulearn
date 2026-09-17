<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', fn (Blueprint $table) => $table->string('request_hash', 64)->nullable());
        Schema::create('submission_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('body')->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('replaced_at');
            $table->unique(['submission_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_revisions');
        Schema::table('submissions', fn (Blueprint $table) => $table->dropColumn('request_hash'));
    }
};
