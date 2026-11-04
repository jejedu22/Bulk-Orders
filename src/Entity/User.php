<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
#[ORM\Entity(repositoryClass: \App\Repository\UserRepository::class)]
#[UniqueEntity(fields: ['mail'], message: 'L\'email renseigné est déjà utilisé.')]
#[UniqueEntity(fields: ['username'], message: 'Le login renseigné est déjà utilisé.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $username;

    #[ORM\Column(type: 'json')]
    private $roles = [];

    /**
     * @var string The hashed password
     */
    #[Assert\NotBlank]
    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\Length(min: 8, minMessage: 'Votre mot de passe doit faire au moins {{ limit }} caractères.')]
    private $password;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\Email]
    #[Assert\NotBlank]
    private $mail;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank]
    private $phone;

    #[Assert\EqualTo(propertyPath: 'password', message: 'Votre mot de passe est différent de celui de confirmation.')]
    private $confirmPassword;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Vous devez renseigner un nom.')]
    private $nom;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Vous devez renseigner un prenom.')]
    private $prenom;

    #[ORM\OneToMany(targetEntity: Commande::class, mappedBy: 'user')]
    private $commandes;

    // Abonné par défaut ; désinscription par le lien présent dans chaque newsletter
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private $newsletter = true;

    // Historique des newsletters reçues : supprimé avec l'utilisateur
    #[ORM\OneToMany(targetEntity: NewsletterDelivery::class, mappedBy: 'user', cascade: ['remove'])]
    private $newsletterDeliveries;

    #[ORM\ManyToMany(targetEntity: NewsletterGroup::class, mappedBy: 'users')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private $newsletterGroups;

    // Adhésions (cotisations), de la plus récente à la plus ancienne
    #[ORM\OneToMany(targetEntity: Adhesion::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['dateFin' => 'DESC', 'id' => 'DESC'])]
    private $adhesions;

    public function __construct()
    {
        $this->adhesions = new ArrayCollection();
        $this->commandes = new ArrayCollection();
        $this->newsletterGroups = new ArrayCollection();
        $this->newsletterDeliveries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }


    public function setUsername(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return (string) $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function getSalt(): ?string
    {
        // not needed when using the "bcrypt" algorithm in security.yaml
        return null;
    }

    /**
     * @see UserInterface
     */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getMail(): ?string
    {
        return $this->mail;
    }

    public function setMail(string $mail): self
    {
        $this->mail = $mail;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getConfirmPassword(): ?string
    {
        return $this->confirmPassword;
    }

    public function setConfirmPassword(string $confirmPassword): self
    {
        $this->confirmPassword = $confirmPassword;

        return $this;
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

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): self
    {
        $this->prenom = $prenom;

        return $this;
    }

    /**
     * @return Collection|Commande[]
     */
    public function getCommandes(): Collection
    {
        return $this->commandes;
    }

    public function addCommande(Commande $commande): self
    {
        if (!$this->commandes->contains($commande)) {
            $this->commandes[] = $commande;
            $commande->setUser($this);
        }

        return $this;
    }

    public function removeCommande(Commande $commande): self
    {
        if ($this->commandes->contains($commande)) {
            $this->commandes->removeElement($commande);
            // set the owning side to null (unless already changed)
            if ($commande->getUser() === $this) {
                $commande->setUser(null);
            }
        }

        return $this;
    }

    public function isNewsletter(): bool
    {
        return $this->newsletter;
    }

    public function setNewsletter(bool $newsletter): self
    {
        $this->newsletter = $newsletter;

        return $this;
    }

    /**
     * @return Collection|NewsletterGroup[]
     */
    public function getNewsletterGroups(): Collection
    {
        return $this->newsletterGroups;
    }

    // Côté propriétaire : NewsletterGroup::$users
    public function addNewsletterGroup(NewsletterGroup $group): self
    {
        if (!$this->newsletterGroups->contains($group)) {
            $this->newsletterGroups[] = $group;
            $group->addUser($this);
        }

        return $this;
    }

    public function removeNewsletterGroup(NewsletterGroup $group): self
    {
        if ($this->newsletterGroups->removeElement($group)) {
            $group->removeUser($this);
        }

        return $this;
    }

    public const ADHESION_A_JOUR = 'a_jour';
    public const ADHESION_A_VENIR = 'a_venir';
    public const ADHESION_EXPIREE = 'expiree';
    public const ADHESION_AUCUNE = 'aucune';

    /**
     * @return Collection|Adhesion[]
     */
    public function getAdhesions(): Collection
    {
        return $this->adhesions;
    }

    public function addAdhesion(Adhesion $adhesion): self
    {
        if (!$this->adhesions->contains($adhesion)) {
            $this->adhesions[] = $adhesion;
        }

        return $this;
    }

    public function removeAdhesion(Adhesion $adhesion): self
    {
        $this->adhesions->removeElement($adhesion);

        return $this;
    }

    /**
     * Adhésion couvrant la date du jour (celle qui finit le plus tard s'il y en a plusieurs).
     */
    public function getAdhesionEnCours(?\DateTimeInterface $date = null): ?Adhesion
    {
        $enCours = null;
        foreach ($this->adhesions as $adhesion) {
            if ($adhesion->couvre($date) && (!$enCours || $adhesion->getDateFin() > $enCours->getDateFin())) {
                $enCours = $adhesion;
            }
        }

        return $enCours;
    }

    /**
     * Adhésion qui se termine le plus tard (en cours, à venir ou expirée).
     */
    public function getDerniereAdhesion(): ?Adhesion
    {
        $derniere = null;
        foreach ($this->adhesions as $adhesion) {
            if (!$derniere || $adhesion->getDateFin() > $derniere->getDateFin()) {
                $derniere = $adhesion;
            }
        }

        return $derniere;
    }

    public function isAdherent(?\DateTimeInterface $date = null): bool
    {
        return null !== $this->getAdhesionEnCours($date);
    }

    /**
     * a_jour, a_venir (seulement une adhésion qui n'a pas commencé), expiree ou aucune.
     */
    public function getStatutAdhesion(?\DateTimeInterface $date = null): string
    {
        if ($this->isAdherent($date)) {
            return self::ADHESION_A_JOUR;
        }
        $derniere = $this->getDerniereAdhesion();
        if (null === $derniere) {
            return self::ADHESION_AUCUNE;
        }

        return $derniere->isExpiree($date) ? self::ADHESION_EXPIREE : self::ADHESION_A_VENIR;
    }
}
