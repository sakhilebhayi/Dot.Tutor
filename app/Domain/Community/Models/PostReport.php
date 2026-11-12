<?php

namespace App\Domain\Community\Models;

use Illuminate\Database\Eloquent\Model;

/** One person's report of one post (the report itself lives in the safety queue; this counts distinct reporters). */
class PostReport extends Model
{
    protected $table = 'community_post_reports';

    protected $fillable = ['post_id', 'reporter_id', 'safety_report_id', 'category'];
}
