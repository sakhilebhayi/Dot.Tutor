<?php

namespace App\Domain\Student\Http\Composers;

use App\Domain\Student\Services\LearnerContext;
use Illuminate\View\View;

/** Gives every student view the reader's context as $ctx unless the controller already passed one. */
class LearnerComposer
{
    public function __construct(private readonly LearnerContext $context) {}

    public function compose(View $view): void
    {
        $user = auth()->user();
        if ($user !== null && ! array_key_exists('ctx', $view->getData())) {
            $view->with('ctx', $this->context->for($user));
        }
    }
}
