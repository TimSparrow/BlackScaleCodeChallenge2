<?php

namespace App\Providers\Email;


use App\Providers\Email\Exceptions\CodeNotReceivedException;
use App\Providers\Email\RandomEmailInterface;
use MailSlurp\ApiException;
use MailSlurp\Apis\InboxControllerApi;
use MailSlurp\Configuration;
use MailSlurp\Models\InboxDto;

class MailSlurpRandomEmail implements RandomEmailInterface
{
    private InboxControllerApi $controller;
    private $config;

    private $email;

    private InboxDto $inbox;
    public function __construct()
    {
        $this->config = Configuration::getDefaultConfiguration()
        ->setApiKey('x-api-key', getenv("MAILSLURP_API_KEY"));

        $this->controller = new InboxControllerApi(null, $this->config);
    }

    /**
     * Returns email address if exists, creates it if not
     * @return string
     * @throws ApiException
     */
    public function getEmail(): string
    {
        return $this->inbox ? $this->inbox->getEmailAddress(): $this->createEmailInternally();
    }


    /**
     * Create a new email address and return it
     * @return string
     * @throws ApiException
     */
    private function createEmailInternally(): string
    {
        $options = new \MailSlurp\Models\CreateInboxDto();
        $options->setName("Test inbox");
        $options->setPrefix("test");
        $this->inbox = $this->controller->createInboxWithOptions($options);

        return $this->inbox->getEmailAddress();
    }
    public function findCode(): string
    {
        $emails = $this->controller->getEmails($this->inbox->getId());

        foreach ($emails as $email) {
            $subj = $email->getSubject();
            $from = $email->getFrom();

            if (preg_match('/@blackscale\.media/', $from) && preg_match('/Email\s+Verification\s+Code\s+-\s+(\w{6|)$/', $subj, $matches)) {
                return $matches[1];
            }
        }

        throw new CodeNotReceivedException("No code received im mail");
    }
}
