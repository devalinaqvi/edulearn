<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets the history record an action with no human behind it.
     *
     * The scheduled Trash purge removes content nobody touched, so attributing it to whichever
     * administrator happened to configure the schedule would be a fiction in an audit trail. A
     * null actor reads as "the system" and is honest about what happened.
     */
    public function up(): void
    {
        Schema::table('content_lifecycle_changes', function (Blueprint $table) {
            $table->foreignId('actor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('content_lifecycle_changes', function (Blueprint $table) {
            $table->foreignId('actor_id')->nullable(false)->change();
        });
    }
};
