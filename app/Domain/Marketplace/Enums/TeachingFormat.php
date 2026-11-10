<?php

namespace App\Domain\Marketplace\Enums;

enum TeachingFormat: string
{
    case Online = 'online';
    case InPerson = 'in_person';
    case Either = 'either';

    public function allowsOnline(): bool
    {
        return $this !== self::InPerson;
    }

    public function allowsInPerson(): bool
    {
        return $this !== self::Online;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $f) => $f->value, self::cases());
    }
}
