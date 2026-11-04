<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Adhésion (cotisation) d'un utilisateur pour une période donnée.
 * Un utilisateur est « adhérent à jour » si l'une de ses adhésions couvre la date du jour.
 */
#[ORM\Entity(repositoryClass: \App\Repository\AdhesionRepository::class)]
#[ORM\Index(columns: ['date_fin'])]
class Adhesion
{
    public const MODES_PAIEMENT = [
        'Espèces' => 'especes',
        'Chèque' => 'cheque',
        'Virement' => 'virement',
        'Carte bancaire' => 'carte',
        'HelloAsso' => 'helloasso',
        'Gratuit / exonéré' => 'gratuit',
        'Autre' => 'autre',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'adhesions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private $user;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull(message: 'Indiquez la date de début.')]
    private $dateDebut;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull(message: 'Indiquez la date de fin.')]
    private $dateFin;

    // Montant de la cotisation, en euros
    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\PositiveOrZero(message: 'Le montant ne peut pas être négatif.')]
    private $montant;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[Assert\Choice(choices: self::MODES_PAIEMENT, message: 'Mode de paiement inconnu.')]
    private $modePaiement;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private $note;

    #[ORM\Column(type: 'datetime_immutable')]
    private $createdAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validatePeriode(ExecutionContextInterface $context): void
    {
        if ($this->dateDebut && $this->dateFin && $this->dateFin < $this->dateDebut) {
            $context->buildViolation('La date de fin doit être postérieure à la date de début.')
                ->atPath('dateFin')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): self
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): self
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getMontant(): ?float
    {
        return $this->montant;
    }

    public function setMontant(?float $montant): self
    {
        $this->montant = $montant;

        return $this;
    }

    public function getModePaiement(): ?string
    {
        return $this->modePaiement;
    }

    public function getModePaiementLabel(): ?string
    {
        $label = array_search($this->modePaiement, self::MODES_PAIEMENT, true);

        return false === $label ? null : $label;
    }

    public function setModePaiement(?string $modePaiement): self
    {
        $this->modePaiement = $modePaiement;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * La période couvre-t-elle cette date (aujourd'hui par défaut) ?
     */
    public function couvre(?\DateTimeInterface $date = null): bool
    {
        $jour = ($date ? \DateTimeImmutable::createFromInterface($date) : new \DateTimeImmutable())->format('Y-m-d');

        return $this->dateDebut->format('Y-m-d') <= $jour && $jour <= $this->dateFin->format('Y-m-d');
    }

    public function isExpiree(?\DateTimeInterface $date = null): bool
    {
        $jour = ($date ? \DateTimeImmutable::createFromInterface($date) : new \DateTimeImmutable())->format('Y-m-d');

        return $this->dateFin->format('Y-m-d') < $jour;
    }
}
