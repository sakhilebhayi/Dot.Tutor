<?php

/*
| R-47 polish (polish-learner, D-2014): search-engine routes for the public pages. robots.txt is a static file in
| public/ (the deploy copies public files to the docroot) and points at this sitemap.
*/

use App\Domain\Student\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/sitemap.xml', SitemapController::class)->middleware('throttle:tutor-public-pages')->name('sitemap');
