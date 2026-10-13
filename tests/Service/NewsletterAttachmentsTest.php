<?php

namespace App\Tests\Service;

use App\Entity\Newsletter;
use App\Entity\NewsletterAttachment;
use App\Service\NewsletterAttachments;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class NewsletterAttachmentsTest extends TestCase
{
    private $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/nl-attachments-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);
    }

    private function upload(string $name, string $content = 'contenu'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'nlatt');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function testStoreMovesTheFileUnderARandomNameAndLinksIt(): void
    {
        $service = new NewsletterAttachments($this->directory . '/');
        $newsletter = new Newsletter();

        $attachment = $service->store($newsletter, $this->upload('tarifs.txt', 'prix'));

        $this->assertSame('tarifs.txt', $attachment->getOriginalName());
        $this->assertSame('text/plain', $attachment->getMimeType());
        $this->assertSame(4, $attachment->getSize());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $attachment->getStoredName());
        $this->assertSame($this->directory . '/' . $attachment->getStoredName(), $service->path($attachment));
        $this->assertSame('prix', file_get_contents($service->path($attachment)));
        $this->assertSame([$attachment], $newsletter->getAttachments()->toArray());
        $this->assertSame([], $service->missing($newsletter));
    }

    /**
     * @dataProvider names
     */
    public function testOriginalNameIsSanitized(string $given, string $expected): void
    {
        $attachment = (new NewsletterAttachments($this->directory))->store(new Newsletter(), $this->upload($given));

        $this->assertSame($expected, $attachment->getOriginalName());
    }

    public static function names(): iterable
    {
        yield 'nom simple' => ['Tarifs été 2026.txt', 'Tarifs été 2026.txt'];
        yield 'chemin' => ['../../etc/passwd.txt', 'passwd.txt'];
        yield 'chemin Windows' => ['C:\\Users\\moi\\liste.txt', 'liste.txt'];
        yield 'guillemets et retour ligne' => ["a\"b\nc.txt", 'a_b_c.txt'];
        yield 'nom vide' => ['..', 'piece-jointe.txt'];
        yield 'nom trop long' => [str_repeat('x', 200) . '.txt', str_repeat('x', 100) . '.txt'];
    }

    public function testMissingLoadAndRemove(): void
    {
        $service = new NewsletterAttachments($this->directory);
        $newsletter = new Newsletter();
        $present = $service->store($newsletter, $this->upload('present.txt'));
        $absent = new NewsletterAttachment($newsletter, 'absent.txt', 'inexistant', 'text/plain', 1);
        $newsletter->addAttachment($absent);

        $this->assertSame([$absent], $service->missing($newsletter));
        $this->assertSame(8, $service->totalSize($newsletter->getAttachments()));

        try {
            $service->load($newsletter);
            $this->fail('Une pièce jointe introuvable doit lever une exception.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('absent.txt', $e->getMessage());
        }

        $newsletter->removeAttachment($absent);
        $this->assertSame([['present.txt', 'contenu', 'text/plain']], $service->load($newsletter));

        $service->removeFiles($newsletter);
        $this->assertFileDoesNotExist($service->path($present));
    }
}
