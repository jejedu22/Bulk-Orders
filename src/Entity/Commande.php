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

    /**
     * Paiement définitif reçu (chèque enregistré par un administrateur ou
     * paiement en ligne). « confirmed » indique seulement la validation de la
     * commande (chèque d'engagement reçu).
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private $payee = false;

    /** Envoi de l'e-mail de demande de paiement (montant définitif) */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private $paiementDemandeLe;

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

    public function getPayee(): bool
    {
        return (bool) $this->payee;
    }

    public function setPayee(bool $payee): self
    {
        $this->payee = $payee;

        return $this;
    }

    public function getPaiementDemandeLe(): ?\DateTimeInterface
    {
        return $this->paiementDemandeLe;
    }

    public function setPaiementDemandeLe(?\DateTimeInterface $paiementDemandeLe): self
    {
        $this->paiementDemandeLe = $paiementDemandeLe;

        return $this;
    }

    /**
     * Les prix définitifs de tous les produits de la commande sont-ils connus ?
     */
    public function hasFinalPrices(): bool
    {
        if ($this->ligneCommandes->isEmpty()) {
            return false;
        }
        foreach ($this->ligneCommandes as $ligne) {
            if (null === $ligne->getProduct()->getPrixFinal()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Total de la commande : prix finaux s'ils sont tous renseignés, prix
     * initiaux sinon (comme sur la page « Ma dernière commande »).
     */
    public function getTotal(): float
    {
        $final = $this->hasFinalPrices();
        $total = 0.0;
        foreach ($this->ligneCommandes as $ligne) {
            $product = $ligne->getProduct();
            $total += $ligne->getQuantite() * (float) ($final ? $product->getPrixFinal() : $product->getPrixInit());
        }

        return $total;
    }
}
