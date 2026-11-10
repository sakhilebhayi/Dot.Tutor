<?php

namespace App\Domain\Learning\Models;

use App\Domain\Learning\Contracts\Sellable;
use App\Domain\Learning\Enums\QualityTier;
use App\Domain\Learning\Enums\ResourceLicence;
use App\Domain\Learning\Enums\ResourceStatus;
use App\Domain\Learning\Enums\ResourceType;
use App\Domain\Safety\Contracts\Moderatable;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryResource extends Model implements Moderatable, Sellable
{
    protected $table = 'learning_resources';

    protected $fillable = [
        'owner_id', 'title', 'description', 'type', 'country_code', 'curriculum_id', 'level_id', 'subject_id',
        'topic_id', 'topic', 'language', 'difficulty', 'institution', 'ownership', 'licence', 'source_url',
        'contributed_as_tutor', 'is_free', 'price_minor', 'currency', 'status', 'quality_tier', 'path', 'mime',
        'size', 'sha256', 'original_name', 'takedown_reason', 'taken_down_at',
    ];

    protected $hidden = ['path', 'sha256'];

    protected function casts(): array
    {
        return [
            'type' => ResourceType::class,
            'licence' => ResourceLicence::class,
            'status' => ResourceStatus::class,
            'quality_tier' => QualityTier::class,
            'is_free' => 'boolean',
            'contributed_as_tutor' => 'boolean',
            'price_minor' => 'integer',
            'size' => 'integer',
            'taken_down_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return HasMany<ResourceReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(ResourceReview::class, 'resource_id')->orderByDesc('id');
    }

    /** @return HasMany<ResourceReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(ResourceReport::class, 'resource_id');
    }

    /** @return HasMany<ResourceRecommendation, $this> */
    public function recommendations(): HasMany
    {
        return $this->hasMany(ResourceRecommendation::class, 'resource_id');
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->owner_id === $user->id;
    }

    public function sellableType(): string
    {
        return 'resource';
    }

    public function sellableId(): int
    {
        return (int) $this->getKey();
    }

    public function priceMinor(): int
    {
        return $this->is_free ? 0 : (int) $this->price_minor;
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
        return 'Study resource: '.$this->title;
    }

    /** Safety moderation queue "hide": taken down (the file is kept for review and a possible restore). */
    public function moderationHide(User $operator): void
    {
        if ($this->status === ResourceStatus::Published) {
            $this->forceFill(['status' => ResourceStatus::TakenDown, 'takedown_reason' => 'Hidden by a moderator after a report.', 'taken_down_at' => now()])->save();
        }
    }

    public function moderationRestore(User $operator): void
    {
        if ($this->status === ResourceStatus::TakenDown) {
            $this->forceFill(['status' => ResourceStatus::Published, 'takedown_reason' => null, 'taken_down_at' => null])->save();
        }
    }
}
