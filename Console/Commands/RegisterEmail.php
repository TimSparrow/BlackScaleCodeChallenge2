<?php

namespace App\Console\Commands;

use App\Providers\Captcha\CaptchaSolverInterface;
use App\Providers\Captcha\Exceptions\ParserException;
use App\Providers\ChallengePageParser;
use App\Providers\Email\RandomEmailInterface;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use PHPHtmlParser\Dom;
use Psr\Log\LoggerInterface;


class RegisterEmail extends Command
{
    private const DOMAIN = 'https://challenge.blackscale.media/';

    private const FIRST_PAGE = 'register.php';

    private const CAPTCHA_BOT = 'captcha_bot.php';

    private const VERIFY_PAGE = 'verify.php';

    private const EMAIL_VERIFY_PAGE = 'captcha_human.php';

    private const FINAL_CHALLENGE_PAGE = 'complete.php';


    public function __construct(
        private readonly RandomEmailInterface $emailService,
        private readonly Client $client,
        private readonly CaptchaSolverInterface $captchaSolver,
        private readonly Dom $dom,
        private readonly LoggerInterface $logger
    )
    {
        parent::__construct();

        // try a generated name and last  name
        $this->botName  = str()->random($this->nameLength) . ' ' . str()->random($this->nameLength);
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

    private string $sToken;

    public function getName(): string
    {
        return $this->signature;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $this->info("Starting", 2);

            $captchaResponse = $this->submitRegistrationGetCaptchaChallenge($this->botName, $this->getEmail());

            $verifyResponse = $this->substituteCaptcha($captchaResponse);
            // get email code
            $emailCode = $this->emailService->findCode();
            $mathChallenge = $this->sendEmailCode($emailCode);
            // solve math challenge
            $mathChallengeResult = $this->solveMathChallenge($mathChallenge);
            // return email and token
            $token = $this->getTokenFromResponse($mathChallengeResult);

            $this->info("Successfully completed");
            $this->newLine();
            $this->line($token);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->fail($exception);
        }
    }


    private function getServiceUri($page): string
    {
        return self::DOMAIN . $page;
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
        // fetch session id, etc
        $this->sToken = $this->getSessionToken($this->getServiceUri(self::FIRST_PAGE));
        $request = ['form_params' =>
            [
                'stoken' => $this->sToken,
                'fullname' => $botName,
                'email'    => $email,
                'request_signature' => base64_encode($email)
            ]
        ];
        $response = $this->client->post($this->getServiceUri(self::CAPTCHA_BOT), $request);

        $this->logger->info("Submitted initial request", $request);
        $html = $response->getBody()->getContents();
        $this->logger->debug("Got raw response: $html");
        // early error catch
        if (preg_match("/Error\s*:/", $html)) {
            throw new ParserException("Invalid request at stage 1: $html");
        }

        return $html;
    }

    /**
     * Returns the email used for this session, created a new one if not done yet
     * @return string
     */
    private function getEmail():string
    {
        $email =  $this->emailService->getEmail();
        $this->logger->info("Created email: " . $email);

        return $email;
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

        $code = $this->captchaSolver->solve($sitekey, $this->getServiceUri(self::CAPTCHA_BOT));
        // submit the received CAPTCHA response to the challenge page
        $response = $this->client->post($this->getServiceUri(self::VERIFY_PAGE), [
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
        $response = $this->client->post($this->getServiceUri(self::EMAIL_VERIFY_PAGE), [
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

        $response = $this->client->post($this->getServiceUri(self::FINAL_CHALLENGE_PAGE), [
            'form_params' => [
                'ts' => $ts,
                'solution' => $answer,
            ]
        ]);

        return $response->getBody()->getContents();
    }

    /**
     * Parse final challenge page and get the response
     *
     * @param string $response
     * @return string
     * @throws \PHPHtmlParser\Exceptions\ChildNotFoundException
     * @throws \PHPHtmlParser\Exceptions\CircularException
     * @throws \PHPHtmlParser\Exceptions\NotLoadedException
     * @throws \PHPHtmlParser\Exceptions\StrictException
     */
    private function getTokenFromResponse(string $response): string
    {
        $parser = new ChallengePageParser($response, $this->dom);
        return $parser->getToken();
    }

    /**
     * Fetches the form page and computes its hidden fields
     *
     * @param string $html
     * @return array
     */
    private function getSessionToken(string $uri): string
    {
        $html = $this->client->get($uri);
        $parser = new ChallengePageParser($html, $this->dom);
        return $parser->getSessionToken($this->getEmail());
    }
}
