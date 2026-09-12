<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    protected $fillable = ['course_id', 'title', 'instructions', 'due_at', 'max_marks', 'rubric', 'rubric_version'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'rubric' => 'array', 'rubric_version' => 'integer'];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }
}
