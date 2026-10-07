<?php

namespace App\Tests\Service;

use App\Entity\Newsletter;
use App\Entity\User;
use App\Service\Mailjet;
use App\Service\MailSender;
use App\Service\NewsletterSender;
use App\Service\OptionsSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
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

        $deliveries = $this->newsletterSender($mailer)->send($this->newsletter(), [$this->user(1, 'a@example.com'), $this->user(2, 'b@example.com')]);

        $this->assertSame([null, null], array_map(function ($d) { return $d->getError(); }, $deliveries));
        $this->assertCount(2, $sent);
        $email = $sent[1];
        $this->assertSame('b@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('newsletter', $email->getHeaders()->getHeaderBody('X-Transport'));
        $this->assertStringStartsWith('<https://example.org/desinscription/2?', $email->getHeaders()->getHeaderBody('List-Unsubscribe'));
        $this->assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->getHeaderBody('List-Unsubscribe-Post'));
        $this->assertStringContainsString('Bonjour', $email->getHtmlBody());
    }

    public function testUsesMailjetConfiguredInAdministration(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');
        $sent = [];
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('send')->willReturnCallback(function (Email $email) use (&$sent) {
            $sent[] = $email;

            return null;
        });
        $mailjet = $this->createMock(Mailjet::class);
        $mailjet->method('isConfigured')->willReturn(true);
        $mailjet->method('sender')->willReturn('news@example.com');
        // Un seul transport pour tout l'envoi
        $mailjet->expects($this->once())->method('transport')->willReturn($transport);

        $deliveries = $this->newsletterSender($mailer, $mailjet)->send($this->newsletter(), [$this->user(1, 'a@example.com'), $this->user(2, 'b@example.com')]);

        $this->assertSame([null, null], array_map(function ($d) { return $d->getError(); }, $deliveries));
        $this->assertCount(2, $sent);
        $this->assertSame('news@example.com', $sent[0]->getFrom()[0]->getAddress());
        $this->assertSame('contact@example.com', $sent[0]->getReplyTo()[0]->getAddress());
        $this->assertFalse($sent[0]->getHeaders()->has('X-Transport'));
        $this->assertTrue($sent[0]->getHeaders()->has('List-Unsubscribe'));
    }

    public function testCountsMailjetFailures(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('send')->willThrowException(new \Symfony\Component\Mailer\Exception\HttpTransportException('Unauthorized', $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class)));
        $mailjet = $this->createMock(Mailjet::class);
        $mailjet->method('isConfigured')->willReturn(true);
        $mailjet->method('transport')->willReturn($transport);

        $deliveries = $this->newsletterSender($this->createMock(MailerInterface::class), $mailjet)->send($this->newsletter(), [$this->user(1, 'a@example.com')]);

        $this->assertCount(1, $deliveries);
        $this->assertFalse($deliveries[0]->isSuccessful());
        $this->assertSame('Unauthorized', $deliveries[0]->getError());
        $this->assertSame('a@example.com', $deliveries[0]->getEmail());
    }

    public function testLogoIsEmbeddedAndColorUsed(): void
    {
        $sent = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sent) {
            $sent[] = $email;
        });

        $this->newsletterSender($mailer, null, 'lapallogo.png')->send($this->newsletter(), [$this->user(1, 'a@example.com')]);

        $email = $sent[0];
        $this->assertStringContainsString('<img src="cid:', $email->getHtmlBody());
        $this->assertStringContainsString('#007bff', $email->getHtmlBody());
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('logo', $email->getAttachments()[0]->getFilename());
        $this->assertSame('inline', $email->getAttachments()[0]->getDisposition());
    }

    public function testMissingLogoIsSkipped(): void
    {
        $sent = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email) use (&$sent) {
            $sent[] = $email;
        });

        $this->newsletterSender($mailer, null, 'inexistant.png')->send($this->newsletter(), [$this->user(1, 'a@example.com')]);

        $this->assertStringNotContainsString('<img', $sent[0]->getHtmlBody());
        $this->assertCount(0, $sent[0]->getAttachments());
    }

    public function testRelativeUrlsBecomeAbsolute(): void
    {
        $html = $this->newsletterSender($this->createMock(MailerInterface::class))->absoluteUrls(
            '<img src="/uploads/newsletter/a.png"><a href=\'/commande\'>x</a><a href="https://autre.org/b">y</a><img src="//cdn.org/c.png">'
        );

        $this->assertSame('<img src="https://example.org/uploads/newsletter/a.png"><a href=\'https://example.org/commande\'>x</a><a href="https://autre.org/b">y</a><img src="//cdn.org/c.png">', $html);
    }

    public function testCountsFailures(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \Symfony\Component\Mailer\Exception\TransportException('Clé Mailjet refusée'));

        $deliveries = $this->newsletterSender($mailer)->send($this->newsletter(), [$this->user(1, 'a@example.com')]);

        $this->assertCount(1, $deliveries);
        $this->assertFalse($deliveries[0]->isSuccessful());
        $this->assertFalse($deliveries[0]->isTest());
    }

    private function newsletterSender(MailerInterface $mailer, ?Mailjet $mailjet = null, string $logo = ''): NewsletterSender
    {
        if (null === $mailjet) {
            $mailjet = $this->createMock(Mailjet::class);
            $mailjet->method('isConfigured')->willReturn(false);
        }
        $settings = $this->createMock(OptionsSettings::class);
        $settings->method('get')->willReturnMap([
            ['contact_email', '', 'contact@example.com'],
            ['name', '', 'Groupement'],
            ['color', '', 'blue'],
            ['logo', '', $logo],
        ]);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(function (string $route, array $params) {
            return 'newsletter_unsubscribe' === $route ? 'https://example.org/desinscription/'.$params['id'] : 'https://example.org/';
        });
        $twig = new Environment(new ArrayLoader([
            'newsletter/email.html.twig' => '{% if logo_cid %}<img src="{{ logo_cid }}">{% endif %}<div style="border-color: {{ accent }}">{{ content|raw }}</div> <a href="{{ unsubscribe_url }}">Se désinscrire</a>',
        ]));

        return new NewsletterSender(new MailSender($mailer, $settings, new NullLogger()), $twig, $urlGenerator, new UriSigner('secret'), $mailjet, new NullLogger(), $settings, \dirname(__DIR__, 2).'/public/dist/img');
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
