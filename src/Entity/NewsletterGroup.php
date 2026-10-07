<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Groupe de destinataires des newsletters, géré par les administrateurs.
 */
#[ORM\Entity(repositoryClass: \App\Repository\NewsletterGroupRepository::class)]
#[UniqueEntity(fields: ['name'], message: 'Un groupe porte déjà ce nom.')]
class NewsletterGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    #[Assert\NotBlank(message: 'Vous devez renseigner un nom.')]
    #[Assert\Length(max: 100)]
    private $name;

    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'newsletterGroups')]
    #[ORM\JoinTable(name: 'newsletter_group_user')]
    #[ORM\OrderBy(['nom' => 'ASC', 'prenom' => 'ASC'])]
    private $users;

    public function __construct()
    {
        $this->users = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Collection|User[]
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): self
    {
        if (!$this->users->contains($user)) {
            $this->users[] = $user;
        }

        return $this;
    }

    public function removeUser(User $user): self
    {
        $this->users->removeElement($user);

        return $this;
    }
}
