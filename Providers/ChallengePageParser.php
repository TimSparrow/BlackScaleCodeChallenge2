<?php

namespace App\Providers;

use App\Providers\Captcha\Exceptions\ParserException;
use PHPHtmlParser\Dom;

/**
 * A DOM parser adapter for specific tasks
 */
class ChallengePageParser
{

    /**
     * Creates the adapter
     * @param string $document - X/HTml to be parsed
     * @param Dom $dom - DOM parser
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\CircularException
     * @throws \PHPHtmlParser\Exceptions\StrictException
     *@see PHPHtmlParser
     */
    public function __construct(
        string $document,
        private readonly Dom $dom,
    )
    {
        // load and parse the document
        $this->dom->loadStr($document);
    }


    /**
     * Finds and returns Recaptcha siteId code
     * @return string
     * @throws ParserException if code is not present
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\NotLoadedException
     */
    public function findCaptchaChallenge(): string {
        $result = $this->dom->find('[attr=data-captcha-code]');
        if (null === $result) {
            throw new ParserException("Cannot find the site key");
        }
        return $result->getAttribute('data-captcha-code');
    }

    /**
     * Solves the test task math challenge
     * @return string
     * @throws ParserException if cannot find either of the operands
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\NotLoadedException
     */
    public function solveMathChallenge(): string{
        $challenge1 = $this->dom->find('.verification-box b#a')->text();
        $challenge2 = $this->dom->find('.verification-box b#b')->text();

        if (null === $challenge1) {
            throw new ParserException("Cannot find the first operand of the math challenge");
        }

        if (null === $challenge2) {
            throw new ParserException("Cannot find the second operand of the math challenge");
        }
        return (string)($challenge1->text() * $challenge2->text());
    }

    /**
     * Returns the timestamp of the math challenge, as supplied by the challenge page
     * @return string
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\NotLoadedException
     */
    public function getMathChallengeTs(): string
    {
        return $this->dom->find('form>input[name=ts]')->text();
    }
}
