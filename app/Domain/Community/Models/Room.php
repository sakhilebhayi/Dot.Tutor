<?php

namespace App\Domain\Community\Models;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Safety\Contracts\AgeGatedSpace;
use App\Domain\Safety\Enums\AgeBand;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A learning room. A subject room is one per curriculum offering (curriculum + level + subject) and audience,
 * created lazily from academic data. A tutor room is a tutor's own free or paid room or workshop series inside the
 * same academic scope. The audience separates learners under 18 from adults (safety's age bands).
 */
class Room extends Model implements AgeGatedSpace
{
    public const SUBJECT = 'subject';

    public const TUTOR = 'tutor';

    public const UNDER_18 = 'under_18';

    public const ADULT = 'adult';

    public const OPEN = 'open';

    public const APPROVAL = 'approval';

    public const INVITE = 'invite';

    public const JOIN_RULES = [self::OPEN, self::APPROVAL, self::INVITE];

    protected $table = 'community_rooms';

    protected $fillable = [
        'kind', 'subject_key', 'curriculum_subject_level_id', 'curriculum_id', 'academic_level_id', 'subject_id',
        'audience', 'owner_id', 'title', 'description', 'language', 'join_rule', 'invite_code', 'capacity',
        'price_minor', 'currency', 'status',
    ];

    protected $hidden = ['invite_code'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'capacity' => 'integer',
            'seats_taken' => 'integer',
        ];
    }

    public static function subjectKey(int $offeringId, string $audience): string
    {
        return "offering:{$offeringId}:{$audience}";
    }

    /** @return BelongsTo<SubjectOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class, 'curriculum_subject_level_id');
    }

    /** @return BelongsTo<Curriculum, $this> */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /** @return BelongsTo<AcademicLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'academic_level_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<RoomMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(RoomMember::class);
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return HasMany<StudyGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(StudyGroup::class);
    }

    /** @return HasMany<StudySession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(StudySession::class);
    }

    public function isTutorRoom(): bool
    {
        return $this->kind === self::TUTOR;
    }

    public function isPaid(): bool
    {
        return $this->price_minor > 0;
    }

    public function isForMinors(): bool
    {
        return $this->audience === self::UNDER_18;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** "Mathematics, Grade 10 (CAPS)" for subject rooms; the tutor's title for tutor rooms. */
    public function displayName(): string
    {
        if ($this->isTutorRoom() && $this->title) {
            return $this->title;
        }

        return trim(($this->subject?->name ?? 'Subject').', '.($this->level?->name ?? '').' ('.($this->curriculum?->short_name ?: $this->curriculum?->name).')');
    }

    public function audienceLabel(): string
    {
        return $this->isForMinors() ? 'Learners under 18' : 'Adults (18 or older)';
    }

    /** @return list<AgeBand> */
    public function safetyAgeBands(): array
    {
        return $this->isForMinors() ? [AgeBand::Child, AgeBand::Teen] : [AgeBand::Adult];
    }

    public function safetyIsPaid(): bool
    {
        return $this->isPaid();
    }

    /** @return list<int> */
    public function safetyMemberIds(): array
    {
        return $this->members()->where('status', RoomMember::ACTIVE)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }
}
