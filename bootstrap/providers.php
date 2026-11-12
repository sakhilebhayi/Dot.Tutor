<?php

use App\Domain\Academic\TutorAcademicServiceProvider;
use App\Domain\Ai\TutorAiServiceProvider;
use App\Domain\Classroom\TutorClassroomServiceProvider;
use App\Domain\Comms\TutorCommsServiceProvider;
use App\Domain\Community\TutorCommunityServiceProvider;
use App\Domain\Integration\TutorIntegrationServiceProvider;
use App\Domain\Learning\Providers\TutorLearningServiceProvider;
use App\Domain\Marketplace\Providers\TutorMarketplaceServiceProvider;
use App\Domain\Money\TutorMoneyServiceProvider;
use App\Domain\Ops\TutorOpsServiceProvider;
use App\Domain\Safety\TutorSafetyServiceProvider;
use App\Domain\Security\TutorSecurityServiceProvider;
use App\Domain\Student\TutorStudentServiceProvider;
use App\Domain\Teach\TutorTeachServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\JetstreamServiceProvider;
use App\Providers\TutorDesignServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
    TutorSafetyServiceProvider::class,
    TutorMoneyServiceProvider::class,
    TutorLearningServiceProvider::class,
    TutorAcademicServiceProvider::class,
    TutorMarketplaceServiceProvider::class,
    TutorDesignServiceProvider::class,
    TutorAiServiceProvider::class,
    TutorClassroomServiceProvider::class,
    TutorCommsServiceProvider::class,
    TutorCommunityServiceProvider::class,
    TutorTeachServiceProvider::class,
    TutorStudentServiceProvider::class,
    TutorOpsServiceProvider::class,
    TutorSecurityServiceProvider::class,
    TutorIntegrationServiceProvider::class,
];
