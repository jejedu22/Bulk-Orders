<?php

namespace App\Tests\Controller;

use App\Entity\JourDistrib;
use App\Entity\Newsletter;
use App\Entity\NewsletterAttachment;
use App\Entity\User;
use App\Tests\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

/**
 * Pièces jointes des newsletters et bouton « Commander ».
 */
class NewsletterAttachmentTest extends WebTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    /** @var User */
    private $admin;

    /** @var string[] */
    private $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->cleanDirectory();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        $this->cleanDirectory();

        parent::tearDown();
    }

    private function directory(): string
    {
        return static::getContainer()->getParameter('newsletter_attachment_directory');
    }

    private function cleanDirectory(): void
    {
        foreach (glob($this->directory() . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function storedFiles(): array
    {
        return glob($this->directory() . '/*') ?: [];
    }

    private function upload(string $name, string $content, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'nlatt');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return new UploadedFile($path, $name, $mime, null, true);
    }

    /**
     * Soumet le formulaire de la newsletter avec des fichiers (DomCrawler ne gère
     * pas bien les champs fichier multiples).
     *
     * @param UploadedFile[] $files
     */
    private function submitNewsletter(string $page, array $files, array $values = []): Crawler
    {
        $crawler = $this->client->request('GET', $page);
        $form = $crawler->filter('form[name="newsletter"]')->form();
        $data = $form->getPhpValues();
        $data['newsletter'] = array_replace($data['newsletter'], $values);
        // null = case décochée : le navigateur n'envoie pas le champ
        $data['newsletter'] = array_filter($data['newsletter'], function ($value) { return null !== $value; });

        return $this->client->request($form->getMethod(), $form->getUri(), $data, ['newsletter' => ['newAttachments' => $files]]);
    }

    private function newsletterWithPdf(string $name = 'tarifs.pdf'): Newsletter
    {
        $this->login($this->admin);
        $this->submitNewsletter('/newsletter/new', [$this->upload($name, self::PDF, 'application/pdf')], [
            'subject' => 'Vente de printemps',
            'content' => '<p>Bonjour</p>',
        ]);

        $newsletter = $this->em()->getRepository(Newsletter::class)->findOneBy(['subject' => 'Vente de printemps']);
        $this->assertNotNull($newsletter);

        return $newsletter;
    }

    private function setSale(Newsletter $newsletter, JourDistrib $jour): void
    {
        $newsletter = $this->reload(Newsletter::class, $newsletter->getId());
        $newsletter->setJourDistrib($this->em()->find(JourDistrib::class, $jour->getId()));
        $this->em()->flush();
    }

    private function sendTest(Newsletter $newsletter): Email
    {
        $this->submitFormWithAction('/newsletter/' . $newsletter->getId(), '/newsletter/' . $newsletter->getId() . '/test');
        $this->assertEmailCount(1);

        return $this->getMailerMessage();
    }

    /** @return \Symfony\Component\Mime\Part\DataPart[] */
    private function attachmentsOf(Email $email): array
    {
        return array_values(array_filter($email->getAttachments(), function ($part) {
            return 'attachment' === $part->getDisposition();
        }));
    }

    public function testNewsletterIsCreatedWithItsAttachments(): void
    {
        $newsletter = $this->newsletterWithPdf();

        $this->assertRedirectsTo('/newsletter/' . $newsletter->getId());
        $this->assertCount(1, $newsletter->getAttachments());
        $attachment = $newsletter->getAttachments()->first();
        $this->assertSame('tarifs.pdf', $attachment->getOriginalName());
        $this->assertSame('application/pdf', $attachment->getMimeType());
        $this->assertSame(\strlen(self::PDF), $attachment->getSize());
        // Nom aléatoire sur le disque, jamais le nom d'origine
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $attachment->getStoredName());
        $this->assertCount(1, $this->storedFiles());

        $crawler = $this->client->request('GET', '/newsletter/' . $newsletter->getId());
        $this->assertStringContainsString('tarifs.pdf', $crawler->filter('.rows')->text());
        $this->client->request('GET', '/newsletter/');
        $this->assertSelectorTextContains('.rows', '1 pièce jointe');
    }

    public function testAttachmentIsSentWithTheEmail(): void
    {
        $newsletter = $this->newsletterWithPdf();

        $email = $this->sendTest($newsletter);

        $attachments = $this->attachmentsOf($email);
        $this->assertCount(1, $attachments);
        $this->assertSame('tarifs.pdf', $attachments[0]->getFilename());
        $this->assertSame('application/pdf', $attachments[0]->getMediaType() . '/' . $attachments[0]->getMediaSubtype());
        $this->assertSame(self::PDF, $attachments[0]->getBody());
    }

    public function testAttachmentsAreNotPubliclyReachable(): void
    {
        $newsletter = $this->newsletterWithPdf();
        $stored = $newsletter->getAttachments()->first()->getStoredName();

        // Hors du dossier public, donc jamais servi par le serveur web
        $this->assertStringNotContainsString('/public/', $this->directory());
        $this->assertFileExists($this->directory() . '/' . $stored);
        $this->assertFileDoesNotExist(\dirname(__DIR__, 2) . '/public/uploads/newsletter/' . $stored);
    }

    public function testOrderButtonPointsToTheSalesList(): void
    {
        $newsletter = $this->newsletterWithPdf();

        $html = $this->sendTest($newsletter)->getHtmlBody();

        $this->assertMatchesRegularExpression('#<a href="http://localhost/"[^>]*>Commander</a>#', $html);
    }

    public function testOrderButtonPointsToTheOrderPageOfTheTargetedSale(): void
    {
        $jour = $this->createJourDistrib([$this->createProduct()]);
        $newsletter = $this->newsletterWithPdf();
        $this->setSale($newsletter, $jour);

        $html = $this->sendTest($newsletter)->getHtmlBody();

        $this->assertMatchesRegularExpression('#<a href="http://localhost/new/' . $jour->getId() . '"[^>]*>Commander</a>#', $html);
    }

    public function testOrderButtonFallsBackToTheSalesListWhenTheSaleIsClosed(): void
    {
        $jour = $this->createJourDistrib([$this->createProduct()], 0, false, true);
        $newsletter = $this->newsletterWithPdf();
        $this->setSale($newsletter, $jour);

        $html = $this->sendTest($newsletter)->getHtmlBody();

        $this->assertMatchesRegularExpression('#<a href="http://localhost/"[^>]*>Commander</a>#', $html);
    }

    public function testOrderButtonCanBeDisabled(): void
    {
        $this->login($this->admin);
        $this->submitNewsletter('/newsletter/new', [], ['subject' => 'Sans bouton', 'content' => '<p>Bonjour</p>', 'orderButton' => null]);
        $newsletter = $this->em()->getRepository(Newsletter::class)->findOneBy(['subject' => 'Sans bouton']);
        $this->assertFalse($newsletter->hasOrderButton());

        $this->assertStringNotContainsString('Commander', $this->sendTest($newsletter)->getHtmlBody());
    }

    public function testOrderButtonIsEnabledByDefaultAndShownInThePreview(): void
    {
        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/newsletter/new');
        $this->assertSame('1', $crawler->filter('input[name="newsletter[orderButton]"]')->attr('value'));
        $this->assertNotNull($crawler->filter('input[name="newsletter[orderButton]"]')->attr('checked'));

        $newsletter = $this->newsletterWithPdf();
        $this->client->request('GET', '/newsletter/' . $newsletter->getId());
        $this->assertSelectorTextContains('.newsletter-preview', 'Commander');
    }

    public function testAttachmentCanBeRemovedWhileEditing(): void
    {
        $newsletter = $this->newsletterWithPdf();
        $id = $newsletter->getId();
        $attachmentId = $newsletter->getAttachments()->first()->getId();
        $this->assertCount(1, $this->storedFiles());

        $crawler = $this->client->request('GET', '/newsletter/' . $id . '/edit');
        $this->assertSelectorTextContains('form[name="newsletter"]', 'tarifs.pdf');
        $form = $crawler->filter('form[name="newsletter"]')->form();
        $form['newsletter[removedAttachments][0]']->tick();
        $this->client->submit($form);

        $this->assertRedirectsTo('/newsletter/' . $id);
        $this->assertNull($this->em()->getRepository(NewsletterAttachment::class)->find($attachmentId));
        $this->assertCount(0, $this->storedFiles());
    }

    public function testAttachmentsCanBeAddedLaterAndKept(): void
    {
        $newsletter = $this->newsletterWithPdf();

        $this->submitNewsletter('/newsletter/' . $newsletter->getId() . '/edit', [$this->upload('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), 'image/png')]);

        $this->assertRedirectsTo('/newsletter/' . $newsletter->getId());
        $names = array_map(function (NewsletterAttachment $a) { return $a->getOriginalName(); }, $this->reload(Newsletter::class, $newsletter->getId())->getAttachments()->toArray());
        $this->assertSame(['tarifs.pdf', 'photo.png'], $names);
        $this->assertCount(2, $this->storedFiles());
    }

    public function testDeletingTheNewsletterDeletesItsFiles(): void
    {
        $newsletter = $this->newsletterWithPdf();
        $id = $newsletter->getId();
        $this->assertCount(1, $this->storedFiles());

        $this->submitFormWithAction('/newsletter/' . $id, '/newsletter/' . $id);

        $this->assertRedirectsTo('/newsletter/');
        $this->assertNull($this->reload(Newsletter::class, $id));
        $this->assertCount(0, $this->em()->getRepository(NewsletterAttachment::class)->findAll());
        $this->assertCount(0, $this->storedFiles());
    }

    /**
     * @dataProvider rejectedFiles
     */
    public function testRejectedFiles(string $name, string $content, string $mime, string $expectedPattern): void
    {
        $this->login($this->admin);
        $crawler = $this->submitNewsletter('/newsletter/new', [$this->upload($name, $content, $mime)], ['subject' => 'Refusée', 'content' => '<p>x</p>']);

        // Formulaire réaffiché avec l'erreur
        $this->assertResponseIsSuccessful();
        $this->assertMatchesRegularExpression($expectedPattern, $crawler->filter('form[name="newsletter"]')->text());
        $this->assertNull($this->em()->getRepository(Newsletter::class)->findOneBy(['subject' => 'Refusée']));
        $this->assertCount(0, $this->storedFiles());
    }

    public static function rejectedFiles(): iterable
    {
        yield 'script PHP' => ['import.php', "<?php echo 'x';", 'application/pdf', '/type accepté/'];
        yield 'exécutable renommé en pdf' => ['facture.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff", 'application/pdf', '/type accepté/'];
        // Selon la configuration PHP (upload_max_filesize), c'est la limite de PHP ou celle de l'application qui répond
        yield 'fichier trop lourd' => ['gros.pdf', "%PDF-1.4\n" . str_repeat('a', 5 * 1024 * 1024 + 10), 'application/pdf', '/trop lourd|trop volumineux/'];
    }

    public function testTotalSizeIsLimited(): void
    {
        $this->login($this->admin);
        // 5 × 1,9 Mo : chaque fichier est accepté, pas leur total (8 Mo)
        $files = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
            $files[] = $this->upload($letter . '.pdf', "%PDF-1.4\n" . str_repeat('a', 1900 * 1024), 'application/pdf');
        }
        $crawler = $this->submitNewsletter('/newsletter/new', $files, ['subject' => 'Trop', 'content' => '<p>x</p>']);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('8 Mo maximum', $crawler->filter('form[name="newsletter"]')->text());
        $this->assertNull($this->em()->getRepository(Newsletter::class)->findOneBy(['subject' => 'Trop']));
    }

    public function testFileNameIsSanitized(): void
    {
        $newsletter = $this->newsletterWithPdf("../../etc/pass\"wd\n.pdf");

        $name = $newsletter->getAttachments()->first()->getOriginalName();
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('"', $name);
        $this->assertStringNotContainsString("\n", $name);
        $this->assertStringEndsWith('.pdf', $name);
    }

    public function testAdminCanDownloadAnAttachment(): void
    {
        $newsletter = $this->newsletterWithPdf();
        $attachment = $newsletter->getAttachments()->first();

        $this->client->request('GET', '/newsletter/attachment/' . $attachment->getId());

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        $this->assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment; filename=tarifs.pdf', $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->assertSame(self::PDF, file_get_contents($this->directory() . '/' . $attachment->getStoredName()));
    }

    public function testDownloadIsReservedToAdmins(): void
    {
        $newsletter = $this->newsletterWithPdf();
        $id = $newsletter->getAttachments()->first()->getId();
        $this->client->request('GET', '/logout');

        $this->client->request('GET', '/newsletter/attachment/' . $id);
        $this->assertRedirectsTo('/login');

        $this->login($this->createUser());
        $this->client->request('GET', '/newsletter/attachment/' . $id);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSendIsCancelledWhenAFileIsMissing(): void
    {
        $this->createUser('abonne@example.com');
        $newsletter = $this->newsletterWithPdf();
        // Volume non monté, fichier supprimé…
        $this->cleanDirectory();

        $this->submitFormWithAction('/newsletter/' . $newsletter->getId(), '/newsletter/' . $newsletter->getId() . '/send');

        $this->assertRedirectsTo('/newsletter/' . $newsletter->getId());
        $this->assertEmailCount(0);
        $this->assertFalse($this->reload(Newsletter::class, $newsletter->getId())->isSent());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'tarifs.pdf');
    }

    public function testNewsletterWithoutAttachmentIsUnchanged(): void
    {
        $this->login($this->admin);
        $this->submitNewsletter('/newsletter/new', [], ['subject' => 'Simple', 'content' => '<p>Bonjour</p>']);
        $newsletter = $this->em()->getRepository(Newsletter::class)->findOneBy(['subject' => 'Simple']);

        $this->assertCount(0, $newsletter->getAttachments());
        $this->assertSame([], $this->attachmentsOf($this->sendTest($newsletter)));
    }
}
