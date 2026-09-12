<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->unique();
        });
        DB::table('courses')->orderBy('id')->chunkById(200, function ($courses) {
            foreach ($courses as $course) {
                DB::table('courses')->where('id', $course->id)->update(['code' => 'EL-'.str_pad((string) $course->id, 6, '0', STR_PAD_LEFT)]);
            }
        });
        Schema::table('courses', fn (Blueprint $table) => $table->string('code', 40)->nullable(false)->change());
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
