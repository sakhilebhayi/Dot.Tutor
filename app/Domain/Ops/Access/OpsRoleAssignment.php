<?php

namespace App\Domain\Ops\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One operator role held by one person (ops_role_assignments). Changed only through RoleAdministration. */
class OpsRoleAssignment extends Model
{
    protected $table = 'ops_role_assignments';

    protected $fillable = ['user_id', 'role', 'granted_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
