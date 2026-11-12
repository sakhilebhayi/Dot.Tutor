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
    App\Domain\Marketplace\Providers\TutorMarketplaceServiceProvider::class,
    App\Providers\TutorDesignServiceProvider::class,
    App\Domain\Ai\TutorAiServiceProvider::class,
    App\Domain\Classroom\TutorClassroomServiceProvider::class,
    App\Domain\Comms\TutorCommsServiceProvider::class,
    App\Domain\Community\TutorCommunityServiceProvider::class,
    App\Domain\Ops\TutorOpsServiceProvider::class,
    App\Domain\Integration\TutorIntegrationServiceProvider::class,
];
