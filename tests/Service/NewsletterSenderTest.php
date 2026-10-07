<?php

namespace App\Tests\Service;

use App\Entity\Newsletter;
use App\Entity\User;
use App\Service\MailSender;
use App\Service\NewsletterSender;
use App\Service\OptionsSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class NewsletterSenderTest extends TestCase
{
    public function testEmailsGoThroughNewsletterTransportWithUnsubscribeHeaders(): void
    {
        $sent = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sent) {
            $sent[] = $email;
        });

        $failed = $this->newsletterSender($mailer)->send($this->newsletter(), [$this->user(1, 'a@example.com'), $this->user(2, 'b@example.com')]);

        $this->assertSame(0, $failed);
        $this->assertCount(2, $sent);
        $email = $sent[1];
        $this->assertSame('b@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('newsletter', $email->getHeaders()->getHeaderBody('X-Transport'));
        $this->assertStringStartsWith('<https://example.org/desinscription/2?', $email->getHeaders()->getHeaderBody('List-Unsubscribe'));
        $this->assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->getHeaderBody('List-Unsubscribe-Post'));
        $this->assertStringContainsString('Bonjour', $email->getHtmlBody());
    }

    public function testCountsFailures(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \Symfony\Component\Mailer\Exception\TransportException('Clé Mailjet refusée'));

        $failed = $this->newsletterSender($mailer)->send($this->newsletter(), [$this->user(1, 'a@example.com')]);

        $this->assertSame(1, $failed);
    }

    private function newsletterSender(MailerInterface $mailer): NewsletterSender
    {
        $settings = $this->createMock(OptionsSettings::class);
        $settings->method('get')->willReturnMap([
            ['contact_email', '', 'contact@example.com'],
            ['name', '', 'Groupement'],
        ]);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(function (string $route, array $params) {
            return 'https://example.org/desinscription/'.$params['id'];
        });
        $twig = new Environment(new ArrayLoader([
            'newsletter/email.html.twig' => '{{ newsletter.content|raw }} <a href="{{ unsubscribe_url }}">Se désinscrire</a>',
        ]));

        return new NewsletterSender(new MailSender($mailer, $settings, new NullLogger()), $twig, $urlGenerator, new UriSigner('secret'));
    }

    private function newsletter(): Newsletter
    {
        return (new Newsletter())->setSubject('Vente')->setContent('<p>Bonjour</p>');
    }

    private function user(int $id, string $mail): User
    {
        $user = new User();
        $user->setMail($mail);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
