<?php

namespace App\Tests\Service;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Product;
use App\Entity\User;
use App\Service\MailSender;
use App\Service\OptionsSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MailSenderTest extends TestCase
{
    public function testSenderIsContactEmailByDefault(): void
    {
        $email = $this->mailSender($this->createMock(MailerInterface::class), 'contact@example.com')->withSender(new Email());

        $this->assertSame('contact@example.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('Groupement', $email->getFrom()[0]->getName());
        $this->assertSame([], $email->getReplyTo());
    }

    public function testMailerFromTakesPrecedenceAndContactIsReplyTo(): void
    {
        $email = $this->mailSender($this->createMock(MailerInterface::class), 'contact@example.com', ' smtp@example.com ')->withSender(new Email());

        $this->assertSame('smtp@example.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('contact@example.com', $email->getReplyTo()[0]->getAddress());
    }

    public function testWithoutSenderThrowsAndLogs(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->expectException(\LogicException::class);
        $this->mailSender($this->createMock(MailerInterface::class), '', '', $logger)->withSender(new Email());
    }

    public function testSendReturnsFalseAndLogsOnTransportError(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('SMTP injoignable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->anything(), $this->callback(function (array $context) {
            return 'dest@example.com' === $context['to'] && 'SMTP injoignable' === $context['error'];
        }));

        $sent = $this->mailSender($mailer, 'contact@example.com', '', $logger)
            ->send((new Email())->to('dest@example.com')->subject('Sujet')->text('Texte'));

        $this->assertFalse($sent);
    }

    public function testSendCommande(): void
    {
        $user = new User();
        $user->setMail('client@example.com');
        $product = new Product();
        $product->setNom('Farine <bio>');
        $product->setConditionnement(2.5);
        $product->setUnit('kg');
        $product->setPrixInit(1234.5);
        $ligne = new LigneCommande();
        $ligne->setProduct($product);
        $ligne->setQuantite(3);
        $commande = new Commande();
        $commande->setUser($user);
        $commande->addLigneCommande($ligne);

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->willReturnCallback(function (Email $email) use (&$sent) {
            $sent = $email;
        });

        $this->assertTrue($this->mailSender($mailer, 'contact@example.com')->sendCommande($commande, 'Commande enregistrée', 'Merci !'));

        $this->assertSame('Commande enregistrée', $sent->getSubject());
        $this->assertSame(['client@example.com'], array_map(function (Address $a) {
            return $a->getAddress();
        }, $sent->getTo()));
        $html = $sent->getHtmlBody();
        $this->assertStringStartsWith('Merci !', $html);
        $this->assertStringContainsString('Farine &lt;bio&gt;', $html);
        $this->assertStringContainsString('2.5kg', $html);
        $this->assertStringContainsString('1 234,50 €', $html);
        $this->assertStringContainsString('>3</td>', $html);
    }

    public function testSendCommandeWithoutSenderReturnsFalse(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $commande = new Commande();
        $commande->setUser((new User())->setMail('client@example.com'));

        $this->assertFalse($this->mailSender($mailer, '')->sendCommande($commande, 'Sujet', 'Message'));
    }

    private function mailSender(MailerInterface $mailer, string $contactEmail, string $mailerFrom = '', ?LoggerInterface $logger = null): MailSender
    {
        $settings = $this->createMock(OptionsSettings::class);
        $settings->method('get')->willReturnMap([
            ['contact_email', '', $contactEmail],
            ['name', '', 'Groupement'],
        ]);

        return new MailSender($mailer, $settings, $logger ?? $this->createMock(LoggerInterface::class), $mailerFrom);
    }
}
