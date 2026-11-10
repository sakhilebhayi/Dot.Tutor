<?php

namespace App\Domain\Learning\Enums;

/** Who may fetch a downloadable course material. */
enum MaterialPermission: string
{
    case EnrolledDownload = 'enrolled_download';
    case EnrolledViewOnly = 'enrolled_view_only';
    case PublicPreview = 'public_preview';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
