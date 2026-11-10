<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\JetstreamServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
    App\Domain\Safety\TutorSafetyServiceProvider::class,
    App\Domain\Money\TutorMoneyServiceProvider::class,
    App\Domain\Learning\Providers\TutorLearningServiceProvider::class,
    App\Domain\Academic\TutorAcademicServiceProvider::class,
];
