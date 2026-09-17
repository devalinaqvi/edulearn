<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiConfiguration extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'api_key' => 'encrypted', 'version' => 'integer', 'daily_limit' => 'integer', 'max_input_chars' => 'integer', 'max_output_tokens' => 'integer'];
    }
}
