<?php

namespace App\Domain\Safety\Support;

/**
 * The exact words a guardian agrees to. Each consent row stores config('safety.guardians.consent_version') and a
 * sha256 of the text shown, so a later edit never rewrites what someone agreed to. Plain language, reviewed by
 * nobody legal yet (docs/R47_SAFETY.md lists this as an owner and lawyer item).
 */
final class ConsentText
{
    public const PROCESSING = 'I am this learner\'s parent or legal guardian. I agree that Dot.Tutor may hold and use '
        .'the learner\'s account details, date of birth, school level, subjects, languages, bookings, messages and '
        .'learning progress to provide tutoring and learning services, and to keep the learner safe. I can see the '
        .'learner\'s lessons, purchases, messages and reports, I approve bookings and purchases where the settings '
        .'require it, and I can withdraw this consent at any time from my guardian page, which limits the account again.';

    public const LESSON_RECORDING = 'I agree that live lessons between this learner and a tutor may be recorded, kept '
        .'privately for safeguarding and quality review, and shown only to me, the learner, the tutor and Dot.Tutor\'s '
        .'safeguarding staff. I can withdraw this at any time; lessons after that are not recorded.';

    public static function for(string $kind): string
    {
        return $kind === 'lesson_recording' ? self::LESSON_RECORDING : self::PROCESSING;
    }

    public static function hash(string $kind): string
    {
        return hash('sha256', self::for($kind));
    }
}
