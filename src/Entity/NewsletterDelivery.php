<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Envoi d'une newsletter à un utilisateur (historique des envois).
 */
#[ORM\Entity(repositoryClass: \App\Repository\NewsletterDeliveryRepository::class)]
#[ORM\Index(columns: ['sent_at'])]
class NewsletterDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\ManyToOne(targetEntity: Newsletter::class, inversedBy: 'deliveries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private $newsletter;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'newsletterDeliveries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private $user;

    // Adresse au moment de l'envoi (l'utilisateur peut la changer ensuite)
    #[ORM\Column(type: 'string', length: 255)]
    private $email;

    #[ORM\Column(type: 'datetime_immutable')]
    private $sentAt;

    // Message d'erreur ; null = envoi accepté par le serveur d'envoi
    #[ORM\Column(type: 'text', nullable: true)]
    private $error;

    // Envoi de test à un administrateur
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private $test = false;

    public function __construct(Newsletter $newsletter, User $user, ?string $error = null, bool $test = false)
    {
        $this->newsletter = $newsletter;
        $this->user = $user;
        $this->email = (string) $user->getMail();
        $this->sentAt = new \DateTimeImmutable();
        $this->error = $error;
        $this->test = $test;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNewsletter(): Newsletter
    {
        return $this->newsletter;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function isSuccessful(): bool
    {
        return null === $this->error;
    }

    public function isTest(): bool
    {
        return $this->test;
    }
}
