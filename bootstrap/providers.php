<?php

use App\Domain\Marketplace\Providers\TutorMarketplaceServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\JetstreamServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
    TutorMarketplaceServiceProvider::class,
];
