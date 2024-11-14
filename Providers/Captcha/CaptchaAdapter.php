<?php

namespace App\Providers\Captcha;

use TwoCaptcha\TwoCaptcha;

/**
 * Adapter for 2Captcha service
 */
class CaptchaAdapter implements CaptchaSolverInterface
{
    private const API_KEY = '9e4174e887f7baa18e78a1d2f3de48c4';

    /**
     * An adapter method for 2Captcha service
     * @param string $sitekey - Recaptcha v2 sitekey
     * @param string $url url of the page containing the challenge
     * @return string - code
     * @throws \TwoCaptcha\Exception\ApiException - @see TwoCaptcha documentation
     * @throws \TwoCaptcha\Exception\NetworkException
     * @throws \TwoCaptcha\Exception\TimeoutException
     * @throws \TwoCaptcha\Exception\ValidationException
     */
    public function solve(string $sitekey, string $url): string
    {
        $solver = new TwoCaptcha(self::API_KEY);
        $result =  $solver->recaptcha([
            'sitekey' => $sitekey,
            'url' => $url,
        ]);

        return $result->code;
    }

}
