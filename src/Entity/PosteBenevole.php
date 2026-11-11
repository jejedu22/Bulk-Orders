<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Poste de bénévole pour la distribution d'une vente (installation,
 * distribution, rangement…), avec le nombre de personnes souhaité.
 */
#[ORM\Entity]
class PosteBenevole
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\ManyToOne(targetEntity: JourDistrib::class, inversedBy: 'postes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private $jourDistrib;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank(message: 'Donnez un nom au poste.')]
    #[Assert\Length(max: 100)]
    private $intitule;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private $description;

    #[ORM\Column(type: 'datetime_immutable')]
    private $debut;

    #[ORM\Column(type: 'datetime_immutable')]
    private $fin;

    // Nombre de bénévoles souhaité
    #[ORM\Column(type: 'integer')]
    #[Assert\Positive(message: 'Au moins une personne.')]
    private $besoin;

    #[ORM\OneToMany(targetEntity: InscriptionBenevole::class, mappedBy: 'poste', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private $inscriptions;

    public function __construct(JourDistrib $jourDistrib)
    {
        $this->jourDistrib = $jourDistrib;
        $this->inscriptions = new ArrayCollection();
        $jourDistrib->addPoste($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJourDistrib(): JourDistrib
    {
        return $this->jourDistrib;
    }

    public function getIntitule(): ?string
    {
        return $this->intitule;
    }

    public function setIntitule(?string $intitule): self
    {
        $this->intitule = $intitule;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getDebut(): ?\DateTimeImmutable
    {
        return $this->debut;
    }

    public function setDebut(\DateTimeImmutable $debut): self
    {
        $this->debut = $debut;

        return $this;
    }

    public function getFin(): ?\DateTimeImmutable
    {
        return $this->fin;
    }

    public function setFin(\DateTimeImmutable $fin): self
    {
        $this->fin = $fin;

        return $this;
    }

    public function getBesoin(): ?int
    {
        return $this->besoin;
    }

    public function setBesoin(?int $besoin): self
    {
        $this->besoin = $besoin;

        return $this;
    }

    /**
     * @return Collection|InscriptionBenevole[]
     */
    public function getInscriptions(): Collection
    {
        return $this->inscriptions;
    }

    public function addInscription(InscriptionBenevole $inscription): self
    {
        if (!$this->inscriptions->contains($inscription)) {
            $this->inscriptions[] = $inscription;
        }

        return $this;
    }

    public function removeInscription(InscriptionBenevole $inscription): self
    {
        $this->inscriptions->removeElement($inscription);

        return $this;
    }

    public function getInscription(User $user): ?InscriptionBenevole
    {
        foreach ($this->inscriptions as $inscription) {
            if ($inscription->getUser() === $user) {
                return $inscription;
            }
        }

        return null;
    }

    public function isInscrit(User $user): bool
    {
        return null !== $this->getInscription($user);
    }

    public function getManquants(): int
    {
        return max(0, $this->besoin - $this->inscriptions->count());
    }

    public function isComplet(): bool
    {
        return $this->inscriptions->count() >= $this->besoin;
    }

    public function getLibelle(): string
    {
        return $this->debut->format('H:i') . '–' . $this->fin->format('H:i');
    }
}
