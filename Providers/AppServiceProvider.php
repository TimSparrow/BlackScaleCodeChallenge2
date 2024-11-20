<?php

namespace App\Providers;

use App\Providers\Captcha\CaptchaAdapter;
use App\Providers\Captcha\CaptchaSolverInterface;
use App\Providers\Email\MailSlurpRandomEmail;
use App\Providers\Email\RandomEmailInterface;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // bind interfaces to implementations
        $this->app->bind(RandomEmailInterface::class, MailSlurpRandomEmail::class);
        $this->app->bind(CaptchaSolverInterface::class, CaptchaAdapter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
