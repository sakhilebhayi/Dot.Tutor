<?php

namespace App\Domain\Classroom\Models;

use Illuminate\Database\Eloquent\Model;

/** One whiteboard operation (a pen stroke, an eraser stroke, a shape, a text, a page clear). The board is their replay. */
class BoardOp extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'classroom_board_ops';

    protected $fillable = ['classroom_id', 'user_id', 'page', 'kind', 'data', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'page' => 'integer'];
    }
}
