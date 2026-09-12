<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('universities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->timestamps();
        });
        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('timezone')->default('Asia/Karachi');
            $table->timestamps();
        });
        Schema::create('faculties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained()->restrictOnDelete();
            $table->foreignId('faculty_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('curricula', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->string('version');
            $table->date('effective_from');
            $table->unsignedSmallInteger('max_credits');
            $table->boolean('advisor_approval')->default(true);
            $table->string('policy_label');
            $table->timestamps();
            $table->unique(['program_id', 'version']);
        });
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('academic_year');
            $table->dateTime('registration_opens_at');
            $table->dateTime('add_drop_closes_at');
            $table->dateTime('withdrawal_closes_at');
            $table->timestamps();
        });
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->string('role');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'program_id', 'role']);
        });
        Schema::create('program_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_id')->constrained('curricula')->restrictOnDelete();
            $table->string('cohort');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['user_id', 'curriculum_id']);
        });
        Schema::create('catalog_courses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->unsignedSmallInteger('credits');
            $table->timestamps();
        });
        Schema::create('curriculum_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('curricula')->restrictOnDelete();
            $table->foreignId('catalog_course_id')->constrained()->restrictOnDelete();
            $table->boolean('required')->default(true);
            $table->unique(['curriculum_id', 'catalog_course_id']);
        });
        Schema::create('course_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('curricula')->restrictOnDelete();
            $table->foreignId('catalog_course_id')->constrained()->restrictOnDelete();
            $table->foreignId('prerequisite_id')->constrained('catalog_courses')->restrictOnDelete();
            $table->unique(['curriculum_id', 'catalog_course_id', 'prerequisite_id'], 'curriculum_prerequisite_unique');
        });
        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('catalog_course_id')->constrained()->restrictOnDelete();
            $table->string('mode')->default('on-campus');
            $table->timestamps();
            $table->unique(['program_id', 'academic_term_id', 'catalog_course_id'], 'program_term_course_unique');
        });
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('capacity');
            $table->timestamps();
            $table->unique(['course_offering_id', 'name']);
        });
        Schema::create('academic_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_enrollment_id')->constrained()->restrictOnDelete();
            $table->string('reason');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->json('policy_snapshot');
            $table->timestamps();
            $table->unique(['program_enrollment_id', 'course_offering_id']);
            $table->index(['section_id', 'status', 'id']);
        });
        Schema::create('course_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('catalog_course_id')->constrained()->restrictOnDelete();
            $table->foreignId('registration_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('status');
            $table->string('evidence_reference')->nullable();
            $table->timestamps();
            $table->index(['program_enrollment_id', 'catalog_course_id', 'status'], 'attempt_enrollment_course_status');
        });
        Schema::create('academic_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('registration_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['academic_audit_events', 'course_attempts', 'registrations', 'academic_holds', 'sections', 'course_offerings', 'course_prerequisites', 'curriculum_courses', 'catalog_courses', 'program_enrollments', 'role_assignments', 'academic_terms', 'curricula', 'programs', 'departments', 'faculties', 'campuses', 'universities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
