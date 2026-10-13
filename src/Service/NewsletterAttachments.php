<?php

namespace App\Service;

use App\Entity\Newsletter;
use App\Entity\NewsletterAttachment;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stockage des pièces jointes des newsletters.
 *
 * Les fichiers sont enregistrés hors du dossier public (jamais servis
 * directement par le serveur web), sous un nom aléatoire : seuls les
 * administrateurs peuvent les télécharger, par une route protégée.
 */
class NewsletterAttachments
{
    // Mailjet refuse les messages de plus de 15 Mo, pièces jointes encodées
    // en base64 comprises (+ 33 %) : 8 Mo de fichiers restent dessous.
    public const MAX_FILE_SIZE = 5 * 1024 * 1024;
    public const MAX_TOTAL_SIZE = 8 * 1024 * 1024;

    /** Types acceptés, déterminés d'après le contenu du fichier. */
    public const MIME_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'text/plain',
        'text/csv',
        'application/zip',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
    ];

    private $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');
    }

    /**
     * Enregistre le fichier et le rattache à la newsletter (à persister par l'appelant).
     */
    public function store(Newsletter $newsletter, UploadedFile $file): NewsletterAttachment
    {
        // Lus avant le déplacement : le fichier temporaire disparaît ensuite
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $size = (int) $file->getSize();
        $originalName = $this->cleanName($file->getClientOriginalName(), $file->guessExtension());
        $storedName = bin2hex(random_bytes(16));

        $file->move($this->directory, $storedName);

        $attachment = new NewsletterAttachment($newsletter, $originalName, $storedName, $mimeType, $size);
        $newsletter->addAttachment($attachment);

        return $attachment;
    }

    public function path(NewsletterAttachment $attachment): string
    {
        return $this->directory . '/' . $attachment->getStoredName();
    }

    public function exists(NewsletterAttachment $attachment): bool
    {
        return is_file($this->path($attachment));
    }

    /**
     * Pièces jointes dont le fichier a disparu du serveur (volume non monté, par exemple).
     *
     * @return NewsletterAttachment[]
     */
    public function missing(Newsletter $newsletter): array
    {
        return array_values(array_filter($newsletter->getAttachments()->toArray(), function (NewsletterAttachment $a) {
            return !$this->exists($a);
        }));
    }

    /**
     * Supprime le fichier (l'enregistrement en base est supprimé par l'appelant).
     */
    public function removeFile(NewsletterAttachment $attachment): void
    {
        @unlink($this->path($attachment));
    }

    public function removeFiles(Newsletter $newsletter): void
    {
        foreach ($newsletter->getAttachments() as $attachment) {
            $this->removeFile($attachment);
        }
    }

    /**
     * Contenu des fichiers pour l'envoi : [nom, contenu, type].
     *
     * @return array<array{0: string, 1: string, 2: string}>
     *
     * @throws \RuntimeException si un fichier est introuvable
     */
    public function load(Newsletter $newsletter): array
    {
        $files = [];
        foreach ($newsletter->getAttachments() as $attachment) {
            $content = $this->exists($attachment) ? file_get_contents($this->path($attachment)) : false;
            if (false === $content) {
                throw new \RuntimeException(sprintf('Pièce jointe « %s » introuvable sur le serveur : retirez-la et ajoutez-la de nouveau.', $attachment->getOriginalName()));
            }
            $files[] = [$attachment->getOriginalName(), $content, $attachment->getMimeType()];
        }

        return $files;
    }

    public function totalSize(iterable $attachments): int
    {
        $total = 0;
        foreach ($attachments as $attachment) {
            $total += $attachment->getSize();
        }

        return $total;
    }

    /**
     * Nom affiché et utilisé dans l'e-mail : sans chemin ni caractère de contrôle.
     */
    private function cleanName(string $name, ?string $guessedExtension): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/[\x00-\x1f\x7f"<>|:*?]+/u', '_', $name) ?? '');
        if (mb_strlen($name) > 120) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 100) . ('' !== $extension ? '.' . substr($extension, 0, 10) : '');
        }
        if ('' === trim($name, '._ ')) {
            $name = 'piece-jointe' . ($guessedExtension ? '.' . $guessedExtension : '');
        }

        return $name;
    }
}
