<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('auth_version')->default(0);
            $table->unsignedInteger('account_version')->default(0);
            $table->timestamp('last_login_at')->nullable();
        });
        Schema::create('account_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event');
            $table->json('details')->nullable();
            $table->timestamp('created_at')->index();
        });
        DB::table('settings')->where('key', 'registration_open')->delete();
        DB::table('settings')->where('key', 'site_name')->where('value', 'Acumen LMS')->update(['value' => 'EduLearn']);
    }

    public function down(): void
    {
        Schema::dropIfExists('account_activity');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_active', 'auth_version', 'account_version', 'last_login_at']));
    }
};
