<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Inscription d'un utilisateur sur un poste de bénévole.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'inscription_benevole_unique', columns: ['poste_id', 'user_id'])]
class InscriptionBenevole
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\ManyToOne(targetEntity: PosteBenevole::class, inversedBy: 'inscriptions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private $poste;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'inscriptionsBenevole')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private $user;

    #[ORM\Column(type: 'datetime_immutable')]
    private $createdAt;

    // Pointé le jour de la distribution
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private $present = false;

    // Rappel envoyé la veille
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private $rappelEnvoyeLe;

    public function __construct(PosteBenevole $poste, User $user)
    {
        $this->poste = $poste;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $poste->addInscription($this);
        $user->addInscriptionBenevole($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPoste(): PosteBenevole
    {
        return $this->poste;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isPresent(): bool
    {
        return $this->present;
    }

    public function setPresent(bool $present): self
    {
        $this->present = $present;

        return $this;
    }

    public function getRappelEnvoyeLe(): ?\DateTimeImmutable
    {
        return $this->rappelEnvoyeLe;
    }

    public function setRappelEnvoyeLe(?\DateTimeImmutable $rappelEnvoyeLe): self
    {
        $this->rappelEnvoyeLe = $rappelEnvoyeLe;

        return $this;
    }
}
