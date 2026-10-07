<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\ProductRepository::class)]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $nom;

    #[ORM\Column(type: 'float')]
    private $conditionnement;

    #[ORM\Column(type: 'float')]
    private $prixInit;

    #[ORM\Column(type: 'float', nullable: true)]
    private $prixFinal;

    #[ORM\OneToMany(targetEntity: LigneCommande::class, mappedBy: 'product')]
    private $ligneCommandes;

    #[ORM\ManyToMany(targetEntity: JourDistrib::class, mappedBy: 'products')]
    private $jourDistribs;

    #[ORM\Column(type: 'string', length: 10)]
    private $unit;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private $category;

    public function __construct()
    {
        $this->ligneCommandes = new ArrayCollection();
        $this->jourDistribs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getConditionnement(): ?float
    {
        return $this->conditionnement;
    }

    public function setConditionnement(float $conditionnement): self
    {
        $this->conditionnement = $conditionnement;

        return $this;
    }

    public function getPrixInit(): ?float
    {
        return $this->prixInit;
    }

    public function setPrixInit(float $prixInit): self
    {
        $this->prixInit = $prixInit;

        return $this;
    }

    public function getPrixFinal(): ?float
    {
        return $this->prixFinal;
    }

    public function setPrixFinal(?float $prixFinal): self
    {
        $this->prixFinal = $prixFinal;

        return $this;
    }

    /**
     * @return Collection|LigneCommande[]
     */
    public function getLigneCommandes(): Collection
    {
        return $this->ligneCommandes;
    }

    public function addLigneCommande(LigneCommande $ligneCommande): self
    {
        if (!$this->ligneCommandes->contains($ligneCommande)) {
            $this->ligneCommandes[] = $ligneCommande;
            $ligneCommande->setProduct($this);
        }

        return $this;
    }

    public function removeLigneCommande(LigneCommande $ligneCommande): self
    {
        if ($this->ligneCommandes->contains($ligneCommande)) {
            $this->ligneCommandes->removeElement($ligneCommande);
            // set the owning side to null (unless already changed)
            if ($ligneCommande->getProduct() === $this) {
                $ligneCommande->setProduct(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|JourDistrib[]
     */
    public function getJourDistribs(): Collection
    {
        return $this->jourDistribs;
    }

    public function addJourDistrib(JourDistrib $jourDistrib): self
    {
        if (!$this->jourDistribs->contains($jourDistrib)) {
            $this->jourDistribs[] = $jourDistrib;
        }

        return $this;
    }

    public function removeJourDistrib(JourDistrib $jourDistrib): self
    {
        if ($this->jourDistribs->contains($jourDistrib)) {
            $this->jourDistribs->removeElement($jourDistrib);
        }

        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(string $unit): self
    {
        $this->unit = $unit;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;

        return $this;
    }
}
