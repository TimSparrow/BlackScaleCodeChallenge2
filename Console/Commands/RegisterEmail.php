<?php

namespace App\Console\Commands;

use App\Providers\Captcha\CaptchaSolverInterface;
use App\Providers\Captcha\Exceptions\ParserException;
use App\Providers\ChallengePageParser;
use App\Providers\Email\RandomEmailInterface;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use PHPHtmlParser\Dom;


class RegisterEmail extends Command
{
    private const DOMAIN = 'https://challenge.blackscale.media/';

    private const CAPTCHA_BOT = 'captcha_bot.php';

    private const VERIFY_PAGE = 'verify.php';

    private const EMAIL_VERIFY_PAGE = 'captcha_human.php';

    private const FINAL_CHALLENGE_PAGE = 'complete.php';

    public function __construct(
        private readonly RandomEmailInterface $emailService,
        private readonly Client $client,
        private readonly CaptchaSolverInterface $captchaSolver,
        private readonly Dom $dom,
    )
    {
        parent::__construct();

        // the following parameters should be added through CLI options, init here to save time
        $this->botName  = str()->random($this->nameLength);
        $this->challengeBotUri = self::DOMAIN . self::CAPTCHA_BOT;
        $this->verifyPageUri = self::DOMAIN . self::VERIFY_PAGE;
        $this->emailVerifyPageUri = self::DOMAIN . self::EMAIL_VERIFY_PAGE;
        $this->finalChallengePageUri = self::DOMAIN . self::FINAL_CHALLENGE_PAGE;
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:register-email';


    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Registers an email with the SERVICE, bypassing bot detection';

    // properties - can be made command line options
    private int $nameLength = 8;
    private string $botName;

    private string $challengeBotUri;
    private string $verifyPageUri;

    private string $emailVerifyPageUri;
    private string $finalChallengePageUri;



    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $captchaResponse = $this->submitRegistrationGetCaptchaChallenge($this->botName, $this->getEmail());

            $verifyResponse = $this->substituteCaptcha($captchaResponse);
            // get email code
            $emailCode = $this->emailService->findCode();
            $mathChallenge = $this->sendEmailCode($emailCode);
            // solve math challenge
            $mathChallengeResult = $this->solveMathChallenge($mathChallenge);
            // return email and token
            $token = $this->getTokenFromResponse($mathChallengeResult);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
        }
    }


    /**
     * Fills out the step 1 registration form, returning the page with CAPTCHA challenge
     * @param string $botName - user name [fullname]
     * @param string $email - generated disposable email
     * @return string - raw HTML page returned by the service
     * @throws \GuzzleHttp\Exception\GuzzleException - handled by the controller
     */
    private function submitRegistrationGetCaptchaChallenge(string $botName, string $email): string
    {
       $response = $this->client->post($this->challengeBotUri, [
           'form_params' => [
               'fullname' => $botName,
               'email'    => $email,
           ]
       ]);
       return $response->getBody()->getContents();
    }

    /**
     * Returns the email used for this session, created a new one if not done yet
     * @return string
     */
    private function getEmail():string
    {
        return $this->emailService->getEmail();
    }

    /**
     * Solves the CAPTCHA using CaptchaSolverInterface and submits the result
     * @param $response
     * @return string
     * @throws ParserException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\CircularException
     * @throws \PHPHtmlParser\Exceptions\NotLoadedException
     * @throws \PHPHtmlParser\Exceptions\StrictException
     */
    private function substituteCaptcha($response): string
    {
        // use DOM top locate the challenge
        $parser = new ChallengePageParser($response, $this->dom);
        $sitekey = $parser->findCaptchaChallenge();
        // use CaptchaSolverInterface to obtain code

        $code = $this->captchaSolver->solve($sitekey, $this->challengeBotUri);
        // submit the received CAPTCHA response to the challenge page
        $response = $this->client->post($this->verifyPageUri, [
            'form_params' => [
                'g-captcha-response' => $code,
                'sitekey' => $sitekey,
            ]
        ]);
        return $response->getBody()->getContents();
    }

    /**
     * Sends the code received in the email
     * @param string $code code from the email
     * @return string - page delivered
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function sendEmailCode(string $code): string
    {
        $response = $this->client->post($this->emailVerifyPageUri, [
            'form_params' => [
                'code' => $code,
            ]
        ]);

        return $response->getBody()->getContents();
    }

    /**
     * Parse, solve and submit the math challenge
     *
     * @param string $mathChallenge - Html document containing the challenge
     * @return string - Html document containing the response
     * @throws ParserException - if challlenge not found
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\CircularException
     * @throws \PHPHtmlParser\Exceptions\NotLoadedException
     * @throws \PHPHtmlParser\Exceptions\StrictException
     */
    private function solveMathChallenge(string $mathChallenge): string
    {
        $parser = new ChallengePageParser($mathChallenge, $this->dom);
        $answer = $parser->solveMathChallenge();
        $ts = $parser->getMathChallengeTs();

        $response = $this->client->post($this->finalChallengePageUri, [
            'form_params' => [
                'ts' => $ts,
                'solution' => $answer,
            ]
        ]);

        return $response->getBody()->getContents();
    }

    private function getTokenFromResponse(string $response): string
    {
        $parser = new ChallengePageParser($response, $this->dom);
        return $paeser->getToken();
    }
}
