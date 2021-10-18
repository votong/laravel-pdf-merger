<?php

namespace VoTong\LaravelPDFMerger\Providers;

use Illuminate\Support\ServiceProvider;
use VoTong\LaravelPDFMerger\PDFMerger;

class PDFMergerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('PDFMerger', function ($app) {
            return new PDFMerger($app['files']);
        });
    }
}
