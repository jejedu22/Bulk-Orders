<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: \App\Repository\NewsletterRepository::class)]
class Newsletter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Vous devez renseigner un sujet.')]
    #[Assert\Length(max: 255)]
    private $subject;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Le contenu de la newsletter est vide.')]
    private $content;

    #[ORM\Column(type: 'datetime_immutable')]
    private $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private $sentAt;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $recipientCount;

    #[ORM\Column(type: 'integer', nullable: true)]
    private $failedCount;

    // Groupes destinataires ; aucun = tous les abonnés
    #[ORM\ManyToMany(targetEntity: NewsletterGroup::class)]
    #[ORM\JoinTable(name: 'newsletter_target_group')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private $groups;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->groups = new ArrayCollection();
    }

    /**
     * @return Collection|NewsletterGroup[]
     */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(NewsletterGroup $group): self
    {
        if (!$this->groups->contains($group)) {
            $this->groups[] = $group;
        }

        return $this;
    }

    public function removeGroup(NewsletterGroup $group): self
    {
        $this->groups->removeElement($group);

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function isSent(): bool
    {
        return null !== $this->sentAt;
    }

    public function getRecipientCount(): ?int
    {
        return $this->recipientCount;
    }

    public function getFailedCount(): ?int
    {
        return $this->failedCount;
    }

    public function markSent(int $recipientCount, int $failedCount): self
    {
        $this->sentAt = new \DateTimeImmutable();
        $this->recipientCount = $recipientCount;
        $this->failedCount = $failedCount;

        return $this;
    }
}
