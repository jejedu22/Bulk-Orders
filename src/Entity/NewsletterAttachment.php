<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Pièce jointe d'une newsletter. Le fichier est enregistré hors du dossier
 * public sous un nom aléatoire (voir App\Service\NewsletterAttachments) ;
 * le nom d'origine n'est conservé que pour l'e-mail et l'affichage.
 */
#[ORM\Entity]
class NewsletterAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\ManyToOne(targetEntity: Newsletter::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private $newsletter;

    #[ORM\Column(type: 'string', length: 255)]
    private $originalName;

    // Nom du fichier sur le disque (aléatoire)
    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private $storedName;

    #[ORM\Column(type: 'string', length: 127)]
    private $mimeType;

    // Taille en octets
    #[ORM\Column(type: 'integer')]
    private $size;

    public function __construct(Newsletter $newsletter, string $originalName, string $storedName, string $mimeType, int $size)
    {
        $this->newsletter = $newsletter;
        $this->originalName = $originalName;
        $this->storedName = $storedName;
        $this->mimeType = $mimeType;
        $this->size = $size;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNewsletter(): Newsletter
    {
        return $this->newsletter;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getStoredName(): string
    {
        return $this->storedName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }
}
