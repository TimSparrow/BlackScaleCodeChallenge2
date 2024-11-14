<?php

namespace App\Providers\Captcha;

interface CaptchaSolverInterface
{
    public function solve(string $sitekey, string $url): string;
}
