<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks an account whose personal data has been erased under a right-to-erasure request.
     *
     * This is a flag, not a mapping. It records that erasure happened and when, which an
     * institution must be able to evidence, but holds nothing that could identify who the
     * account belonged to. Keeping any reversible link would make the operation
     * pseudonymisation rather than erasure, and the data would still be personal data.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('erased_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('erased_at'));
    }
};
