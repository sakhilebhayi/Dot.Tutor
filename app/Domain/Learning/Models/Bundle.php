<?php

namespace App\Domain\Learning\Models;

use App\Domain\Learning\Contracts\Sellable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Bundle extends Model implements Sellable
{
    protected $table = 'learning_bundles';

    protected $fillable = ['owner_id', 'title', 'description', 'price_minor', 'currency', 'status'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<Course, $this> */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'learning_bundle_courses', 'bundle_id', 'course_id');
    }

    public function sellableType(): string
    {
        return 'bundle';
    }

    public function sellableId(): int
    {
        return (int) $this->getKey();
    }

    public function priceMinor(): int
    {
        return (int) $this->price_minor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function sellerId(): ?int
    {
        return $this->owner_id;
    }

    public function sellableDescription(): string
    {
        return 'Course bundle: '.$this->title;
    }
}
