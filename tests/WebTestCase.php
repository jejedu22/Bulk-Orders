<?php

namespace App\Tests;

use App\Entity\Category;
use App\Entity\Commande;
use App\Entity\JourDistrib;
use App\Entity\LigneCommande;
use App\Entity\Product;
use App\Entity\Settings;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as BaseWebTestCase;

/**
 * Base des tests fonctionnels.
 *
 * Chaque test part d'une base SQLite vide (schéma généré depuis le mapping
 * Doctrine) contenant uniquement les paramètres de l'application. Les tests
 * passent par l'application comme un navigateur (formulaire de connexion
 * compris) afin de rester valables d'une version de Symfony à l'autre.
 */
abstract class WebTestCase extends BaseWebTestCase
{
    public const PASSWORD = 'motdepasse';

    /** @var KernelBrowser */
    protected $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $em = $this->em();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($metadata);

        $this->createSettings();
    }

    /**
     * Entity manager du noyau courant : le client redémarre le noyau entre
     * deux requêtes, on le récupère donc à chaque fois.
     */
    protected function em(): EntityManagerInterface
    {
        return $this->client->getContainer()->get('doctrine')->getManager();
    }

    /**
     * Recharge une entité depuis la base (après une requête).
     *
     * @template T
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    protected function reload(string $class, int $id)
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository($class)->find($id);
    }

    /**
     * Paramètres saisis à l'installation (table settings). Les identifiants
     * comptent : SettingsType choisit le champ affiché selon l'id.
     */
    protected function createSettings(): void
    {
        $values = [
            1 => ['color', 'blue'],
            2 => ['logo', 'logo.png'],
            3 => ['name', 'Groupement de test'],
            4 => ['favicon', ''],
            5 => ['contact_email', 'contact@example.com'],
            6 => ['text_confirm_email', 'Votre commande est confirmée.'],
            7 => ['text_register_command', 'Votre commande est enregistrée.'],
        ];
        $connection = $this->em()->getConnection();
        foreach ($values as $id => [$name, $value]) {
            $connection->insert('settings', ['id' => $id, 'name' => $name, 'value' => $value]);
        }
    }

    protected function createUser(string $username = 'user@example.com', array $roles = [], string $nom = 'Dupont', string $prenom = 'Jean'): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setMail($username);
        $user->setNom($nom);
        $user->setPrenom($prenom);
        $user->setPhone('0600000000');
        $user->setRoles($roles);
        // Hachage compatible avec l'encodeur « auto » (bcrypt, coût réduit)
        $user->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));

        $this->persist($user);

        return $user;
    }

    protected function createAdmin(string $username = 'admin@example.com'): User
    {
        return $this->createUser($username, ['ROLE_ADMIN'], 'Admin', 'Alice');
    }

    protected function createCategory(string $nom = 'Céréales', int $position = 0): Category
    {
        $category = new Category();
        $category->setNom($nom);
        $category->setPosition($position);

        $this->persist($category);

        return $category;
    }

    protected function createProduct(string $nom = 'Farine', float $conditionnement = 5, float $prixInit = 10.5, ?Category $category = null): Product
    {
        $product = new Product();
        $product->setNom($nom);
        $product->setConditionnement($conditionnement);
        $product->setUnit('kg');
        $product->setPrixInit($prixInit);
        $product->setCategory($category);

        $this->persist($product);

        return $product;
    }

    /**
     * Jour de distribution ouvert : date limite dans une semaine.
     *
     * @param Product[] $products
     */
    protected function createJourDistrib(array $products = [], float $total = 0, bool $limite = false, bool $closed = false, string $date = '+7 days'): JourDistrib
    {
        $jour = new JourDistrib();
        $jour->setDate(new \DateTime($date));
        $jour->setDateLivraison(new \DateTime($date.' +3 days'));
        $jour->setTotal($total);
        $jour->setPoidRestant(0);
        $jour->setLimite($limite);
        $jour->setClosed($closed);
        foreach ($products as $product) {
            $jour->addProduct($product);
        }

        $this->persist($jour);

        return $jour;
    }

    /**
     * @param array<array{0: Product, 1: int}> $lignes [produit, quantité]
     */
    protected function createCommande(User $user, JourDistrib $jour, array $lignes, bool $confirmed = false, bool $livree = false): Commande
    {
        $commande = new Commande();
        $commande->setUser($user);
        $commande->setJourDistrib($jour);
        $commande->setDate(new \DateTime());
        $commande->setConfirmed($confirmed);
        $commande->setLivree($livree);
        $poids = $jour->getPoidRestant();
        foreach ($lignes as [$product, $quantite]) {
            $ligne = new LigneCommande();
            $ligne->setProduct($product);
            $ligne->setQuantite($quantite);
            $ligne->setLivree($livree);
            $commande->addLigneCommande($ligne);
            $poids += $product->getConditionnement() * $quantite;
        }
        $jour->setPoidRestant($poids);

        $this->persist($commande);

        return $commande;
    }

    protected function persist(object $entity): void
    {
        $em = $this->em();
        $em->persist($entity);
        $em->flush();
    }

    /**
     * Connexion par le formulaire de login, comme un utilisateur.
     */
    protected function login(User $user, string $password = self::PASSWORD): void
    {
        $this->client->request('POST', '/login', [
            'username' => $user->getUserIdentifier(),
            'password' => $password,
        ]);
    }

    /**
     * Vérifie une redirection vers un chemin, que l'en-tête Location soit
     * absolu (« http://localhost/login ») ou relatif (« /login ») : cela
     * dépend du composant qui redirige et varie selon les versions.
     */
    protected function assertRedirectsTo(string $path): void
    {
        $response = $this->client->getResponse();
        $this->assertTrue($response->isRedirect(), sprintf('Redirection attendue vers %s, code %d obtenu.', $path, $response->getStatusCode()));

        $location = $response->headers->get('Location');
        $this->assertSame($path, preg_replace('#^https?://[^/]+#', '', $location));
    }

    /**
     * Affiche $page puis soumet le formulaire dont l'action est $action
     * (formulaires de suppression avec jeton CSRF).
     */
    protected function submitFormWithAction(string $page, string $action): void
    {
        $crawler = $this->client->request('GET', $page);
        $this->assertResponseIsSuccessful();

        $forms = $crawler->filter(sprintf('form[action="%s"]', $action));
        $this->assertCount(1, $forms, sprintf('Formulaire « %s » introuvable sur %s.', $action, $page));
        $this->client->submit($forms->form());
    }

    protected function setting(string $name): ?string
    {
        $em = $this->em();
        $em->clear();
        $setting = $em->getRepository(Settings::class)->findOneBy(['name' => $name]);

        return $setting ? $setting->getValue() : null;
    }
}
