<?php

namespace App\Domain\Learning\Enums;

/** Licence a contributor declares for a library resource. */
enum ResourceLicence: string
{
    case CcBy = 'cc_by';
    case CcBySa = 'cc_by_sa';
    case AllRightsReserved = 'all_rights_reserved';
    case PublicDomain = 'public_domain';
    case OfficialSource = 'official_source';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::CcBy => 'CC BY 4.0',
            self::CcBySa => 'CC BY-SA 4.0',
            self::AllRightsReserved => 'All rights reserved',
            self::PublicDomain => 'Public domain',
            self::OfficialSource => 'Official source',
        };
    }
}
