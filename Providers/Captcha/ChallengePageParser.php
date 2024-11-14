<?php

namespace App\Providers\Captcha;

use App\Providers\Captcha\Exceptions\ParserException;
use PHPHtmlParser\Dom;

class ChallengePageParser
{
    public function __construct(
        string $challenge,
        private readonly Dom $dom,
    )
    {
        $this->dom->loadStr($challenge);
    }


    public function parse($challenge) {
        return $this->dom->find('.captcha-code')->text();
    }
}
