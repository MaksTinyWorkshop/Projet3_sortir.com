<?php

namespace App\Services;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Service d'envoi de mail -> penser à configurer le DSN Mailer dans .env et commenter
 * la ligne ---- Symfony\Component\Mailer\Messenger\SendEmailMessage: async ---- dans config/messenger.yaml
 * sinon les messages partent dans la table messenger en base
 */
class SendMailService
{
    public function __construct(private MailerInterface $mailer){}

    /**
     * @throws TransportExceptionInterface
     */
    public function sendMail(
        string $from,
        string $to,
        string $subject,
        string $template,
        array $context
    ):void

    {
        $email = (new TemplatedEmail())
            ->from($from)
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($context);

        $this->mailer->send($email);
    }
}