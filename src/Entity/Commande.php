<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\CommandeRepository::class)]
class Commande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\OneToMany(targetEntity: LigneCommande::class, mappedBy: 'commande', cascade: ['persist', 'remove'])]
    private $ligneCommandes;

    #[ORM\ManyToOne(targetEntity: JourDistrib::class, inversedBy: 'commandes')]
    private $jourDistrib;

    #[ORM\Column(type: 'boolean')]
    private $livree;

    #[ORM\Column(type: 'text', nullable: true)]
    private $commentaire;

    #[ORM\Column(type: 'boolean', nullable: false)]
    private $confirmed;

    #[ORM\Column(type: 'date')]
    private $date;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'commandes')]
    #[ORM\JoinColumn(nullable: false)]
    private $user;

    /** Identifiant du paiement HelloAsso (« checkout intent ») en cours */
    #[ORM\Column(type: 'integer', nullable: true)]
    private $helloassoCheckoutId;

    /** Identifiant de la commande HelloAsso une fois le paiement en ligne reçu */
    #[ORM\Column(type: 'integer', nullable: true)]
    private $helloassoOrderId;

    public function __construct()
    {
        $this->ligneCommandes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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
            $ligneCommande->setCommande($this);
        }

        return $this;
    }

    public function removeLigneCommande(LigneCommande $ligneCommande): self
    {
        if ($this->ligneCommandes->contains($ligneCommande)) {
            $this->ligneCommandes->removeElement($ligneCommande);
            // set the owning side to null (unless already changed)
            if ($ligneCommande->getCommande() === $this) {
                $ligneCommande->setCommande(null);
            }
        }

        return $this;
    }

    public function getJourDistrib(): ?JourDistrib
    {
        return $this->jourDistrib;
    }

    public function setJourDistrib(?JourDistrib $jourDistrib): self
    {
        $this->jourDistrib = $jourDistrib;

        return $this;
    }

    public function getLivree(): ?bool
    {
        return $this->livree;
    }

    public function setLivree(bool $livree): self
    {
        $this->livree = $livree;

        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): self
    {
        $this->commentaire = $commentaire;

        return $this;
    }

    public function getconfirmed(): ?bool
    {
        return $this->confirmed;
    }

    public function setconfirmed(?bool $confirmed): self
    {
        $this->confirmed = $confirmed;

        return $this;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getHelloassoCheckoutId(): ?int
    {
        return $this->helloassoCheckoutId;
    }

    public function setHelloassoCheckoutId(?int $helloassoCheckoutId): self
    {
        $this->helloassoCheckoutId = $helloassoCheckoutId;

        return $this;
    }

    public function getHelloassoOrderId(): ?int
    {
        return $this->helloassoOrderId;
    }

    public function setHelloassoOrderId(?int $helloassoOrderId): self
    {
        $this->helloassoOrderId = $helloassoOrderId;

        return $this;
    }

    /**
     * Total de la commande : prix finaux s'ils sont tous renseignés, prix
     * initiaux sinon (comme sur la page « Ma dernière commande »).
     */
    public function getTotal(): float
    {
        $totalInit = 0.0;
        $totalFinal = 0.0;
        $hasFinal = true;
        foreach ($this->ligneCommandes as $ligne) {
            $product = $ligne->getProduct();
            $totalInit += $ligne->getQuantite() * (float) $product->getPrixInit();
            if (null === $product->getPrixFinal()) {
                $hasFinal = false;
            } else {
                $totalFinal += $ligne->getQuantite() * $product->getPrixFinal();
            }
        }

        return $hasFinal && !$this->ligneCommandes->isEmpty() ? $totalFinal : $totalInit;
    }
}
