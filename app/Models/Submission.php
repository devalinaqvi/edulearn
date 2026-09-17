<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    protected $fillable = ['published_result', 'published_grade_version', 'result_published_at', 'request_hash', 'assignment_id', 'user_id', 'body', 'path', 'original_name', 'submitted_at', 'is_late', 'status', 'grade', 'feedback', 'graded_by', 'graded_at', 'rubric_scores', 'grade_version'];

    protected function casts(): array
    {
        return ['published_result' => 'array', 'published_grade_version' => 'integer', 'result_published_at' => 'datetime', 'submitted_at' => 'datetime', 'graded_at' => 'datetime', 'is_late' => 'boolean', 'rubric_scores' => 'array', 'grade_version' => 'integer'];
    }

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
