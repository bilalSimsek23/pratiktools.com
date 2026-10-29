<?php

use App\Http\Controllers\Site\PublicSiteController;
use App\Http\Controllers\Site\SiteFilesController;
use App\Http\Controllers\Site\SiteInquiryController;
use Illuminate\Support\Facades\Route;

/*
| Public PratikTools website. No session, no cookie, no tenant context;
| published content only. The SaaS panel stays under /app.
*/

$slug = '[a-z0-9]+(-[a-z0-9]+)*';

Route::get('/', [PublicSiteController::class, 'home'])->name('home');
foreach (['hizmetler' => 'service', 'cozumler' => 'solution', 'projeler' => 'project', 'blog' => 'article'] as $prefix => $type) {
    Route::get($prefix, [PublicSiteController::class, 'index'])->defaults('type', $type)->name("site.{$type}.index");
    Route::get($prefix.'/{slug}', [PublicSiteController::class, 'show'])->defaults('type', $type)->where('slug', $slug)->name("site.{$type}.show");
}
Route::get('referanslar', [PublicSiteController::class, 'references'])->name('site.references');
Route::get('referanslar/{slug}', [PublicSiteController::class, 'reference'])->where('slug', $slug)->name('site.reference');
Route::get('hakkimizda', [PublicSiteController::class, 'page'])->defaults('slug', 'hakkimizda')->name('site.about');
Route::get('iletisim', [PublicSiteController::class, 'page'])->defaults('slug', 'iletisim')->name('site.contact');
Route::get('iletisim/tesekkurler', [PublicSiteController::class, 'thanks'])->name('site.thanks');
Route::post('iletisim/talep', SiteInquiryController::class)->middleware('throttle:site-inquiry')->name('site.inquiry');

Route::get('robots.txt', [SiteFilesController::class, 'robots'])->name('site.robots');
Route::get('sitemap.xml', [SiteFilesController::class, 'sitemap'])->name('site.sitemap');
Route::get('media/{id}/{width}.{ext}', [SiteFilesController::class, 'media'])->whereUuid('id')->where(['width' => '[0-9]{2,4}', 'ext' => 'webp|jpg'])->name('site.media');
Route::post('t', [SiteFilesController::class, 'event'])->middleware('throttle:site-events')->name('site.event');
