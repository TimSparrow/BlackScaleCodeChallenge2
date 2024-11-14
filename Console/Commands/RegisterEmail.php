<?php

namespace App\Console\Commands;

use App\Providers\Captcha\CaptchaSolverInterface;
use App\Providers\Captcha\ChallengePageParser;
use App\Providers\Email\RandomEmailInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Command;



class RegisterEmail extends Command
{
    private const DOMAIN = 'https://challenge.blackscale.media/';

    private const CAPTCHA_BOT = 'captcha_bot.php';

    private const VERIFY_PAGE = 'verify.php';

    private const EMAIL_VERIFY_PAGE = 'captcha_uman.php';

    public function __construct(
        private readonly RandomEmailInterface $emailService,
        private readonly Client $client,
        private readonly CaptchaSolverInterface $captchaSolver,
        private readonly ChallengePageParser $pageParser,
    )
    {
        parent::__construct();

        // the following parameters should be added through CLI options, init here to save time
        $this->botName  = str()->random($this->nameLength);
        $this->challengeBotUri = self::DOMAIN . self::CAPTCHA_BOT;
        $this->verifyPageUri = self::DOMAIN . self::VERIFY_PAGE;
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

            // solve math challenge
            // return email and token
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

    private function getEmail():string
    {
        return $this->emailService->getEmail();
    }

    private function substituteCaptcha($response): string
    {
        // use DOM top locate the challenge
        $sitekey = $this->pageParser->parse($response);
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
}
