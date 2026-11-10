<?php

namespace App\Domain\Academic\Enums;

enum ReviewStatus: string
{
    case Unreviewed = 'unreviewed';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('academic.review_status.'.$this->value);
    }
}
