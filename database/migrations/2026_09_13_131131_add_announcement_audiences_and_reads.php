<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->unsignedBigInteger('course_id')->nullable()->change();
            $table->foreignId('author_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable()->index();
        });
        DB::table('announcements')->update(['published_at' => DB::raw('created_at')]);
        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->foreignId('announcement_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('read_at');
            $table->primary(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain platform announcements; restore a matching backup to roll back this schema.');
    }
};
