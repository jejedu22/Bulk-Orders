<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Créneau de retrait des commandes le jour de la distribution d'une vente.
 * Les clients dont la commande est validée y réservent une place.
 */
#[ORM\Entity(repositoryClass: \App\Repository\CreneauRetraitRepository::class)]
class CreneauRetrait
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\ManyToOne(targetEntity: JourDistrib::class, inversedBy: 'creneaux')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private $jourDistrib;

    #[ORM\Column(type: 'datetime_immutable')]
    private $debut;

    #[ORM\Column(type: 'datetime_immutable')]
    private $fin;

    // Nombre de personnes pouvant venir sur ce créneau
    #[ORM\Column(type: 'integer')]
    #[Assert\Positive(message: 'Au moins une place par créneau.')]
    private $places;

    #[ORM\OneToMany(targetEntity: Commande::class, mappedBy: 'creneau')]
    private $commandes;

    public function __construct(JourDistrib $jourDistrib, \DateTimeImmutable $debut, \DateTimeImmutable $fin, int $places)
    {
        $this->jourDistrib = $jourDistrib;
        $this->debut = $debut;
        $this->fin = $fin;
        $this->places = $places;
        $this->commandes = new ArrayCollection();
        $jourDistrib->addCreneau($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJourDistrib(): JourDistrib
    {
        return $this->jourDistrib;
    }

    public function getDebut(): \DateTimeImmutable
    {
        return $this->debut;
    }

    public function setDebut(\DateTimeImmutable $debut): self
    {
        $this->debut = $debut;

        return $this;
    }

    public function getFin(): \DateTimeImmutable
    {
        return $this->fin;
    }

    public function setFin(\DateTimeImmutable $fin): self
    {
        $this->fin = $fin;

        return $this;
    }

    public function getPlaces(): int
    {
        return $this->places;
    }

    public function setPlaces(int $places): self
    {
        $this->places = $places;

        return $this;
    }

    /**
     * @return Collection|Commande[]
     */
    public function getCommandes(): Collection
    {
        return $this->commandes;
    }

    public function getReservees(): int
    {
        return $this->commandes->count();
    }

    public function getRestantes(): int
    {
        return max(0, $this->places - $this->commandes->count());
    }

    public function isComplet(): bool
    {
        return $this->commandes->count() >= $this->places;
    }

    /** « 10:00–10:15 » */
    public function getLibelle(): string
    {
        return $this->debut->format('H:i') . '–' . $this->fin->format('H:i');
    }
}
