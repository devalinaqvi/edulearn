<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('uploader_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('uploaded_at')->nullable();
        });
        DB::table('materials')->update(['uploaded_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uploader_id');
            $table->dropColumn(['size_bytes', 'uploaded_at']);
        });
    }
};
