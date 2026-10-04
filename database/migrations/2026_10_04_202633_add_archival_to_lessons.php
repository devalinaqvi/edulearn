<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gives lessons the archival state that materials and video lectures already have.
     *
     * This extends the existing idiom rather than introducing Laravel's SoftDeletes alongside it.
     * Two parallel notions of "removed" would mean every query, policy and relation had to decide
     * which one it meant; one `status` column keeps the answer in a single place.
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->after('position')->index();
            $table->timestamp('archived_at')->nullable()->after('status');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(0)->after('archived_by');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['status', 'archived_at', 'version']);
        });
    }
};
