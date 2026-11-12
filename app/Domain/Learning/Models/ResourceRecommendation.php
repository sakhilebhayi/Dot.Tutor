<?php

namespace App\Domain\Learning\Models;

use Illuminate\Database\Eloquent\Model;

class ResourceRecommendation extends Model
{
    protected $table = 'learning_resource_recommendations';

    protected $fillable = ['resource_id', 'user_id'];
}
