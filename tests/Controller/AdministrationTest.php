<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\JourDistrib;
use App\Entity\Product;
use App\Entity\Settings;
use App\Entity\User;
use App\Tests\WebTestCase;

/**
 * Écrans d'administration : produits, catégories, ventes (jours de
 * distribution), configuration et utilisateurs.
 */
class AdministrationTest extends WebTestCase
{
    /** @var User */
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->login($this->admin);
    }

    public function testCreateProduct(): void
    {
        $category = $this->createCategory('Céréales');

        $crawler = $this->client->request('GET', '/product/new');
        $this->client->submit($crawler->filter('form[name="product"]')->form([
            'product[nom]' => 'Farine T65',
            'product[category]' => $category->getId(),
            'product[conditionnement]' => '2,5',
            'product[unit]' => 'kg',
            'product[prixInit]' => '7,20',
        ]));

        $this->assertRedirectsTo('/product/');
        $product = $this->em()->getRepository(Product::class)->findOneBy(['nom' => 'Farine T65']);
        $this->assertNotNull($product);
        $this->assertEquals(2.5, $product->getConditionnement());
        $this->assertEquals(7.2, $product->getPrixInit());
        $this->assertSame('Céréales', $product->getCategory()->getNom());

        $this->client->followRedirect();
        $this->assertStringContainsString('Farine T65', $this->client->getResponse()->getContent());
    }

    public function testEditAndDeleteProduct(): void
    {
        $product = $this->createProduct('Farine');

        $crawler = $this->client->request('GET', '/product/'.$product->getId().'/edit');
        $this->client->submit($crawler->filter('form[name="product"]')->form([
            'product[nom]' => 'Farine bio',
            'product[prixFinal]' => '9,90',
        ]));
        $this->assertRedirectsTo('/product/');
        $product = $this->reload(Product::class, $product->getId());
        $this->assertSame('Farine bio', $product->getNom());
        $this->assertEquals(9.9, $product->getPrixFinal());

        $this->submitFormWithAction('/product/'.$product->getId().'/edit', '/product/'.$product->getId());
        $this->assertRedirectsTo('/product/');
        $this->assertNull($this->reload(Product::class, $product->getId()));
    }

    public function testCreateCategory(): void
    {
        $crawler = $this->client->request('GET', '/category/new');
        $this->client->submit($crawler->filter('form[name="category"]')->form([
            'category[nom]' => 'Épicerie',
            'category[position]' => '3',
        ]));

        $this->assertRedirectsTo('/category/');
        $category = $this->em()->getRepository(Category::class)->findOneBy(['nom' => 'Épicerie']);
        $this->assertNotNull($category);
        $this->assertSame(3, $category->getPosition());
    }

    public function testDeleteCategoryKeepsProducts(): void
    {
        $category = $this->createCategory('Céréales');
        $product = $this->createProduct('Farine', 5, 10, $category);

        $this->submitFormWithAction('/category/'.$category->getId().'/edit', '/category/'.$category->getId());

        $this->assertRedirectsTo('/category/');
        $this->assertNull($this->reload(Category::class, $category->getId()));
        $product = $this->reload(Product::class, $product->getId());
        $this->assertNotNull($product);
        $this->assertNull($product->getCategory());
    }

    public function testCreateDistributionDayWithAllProductsCheckedByDefault(): void
    {
        $farine = $this->createProduct('Farine');
        $riz = $this->createProduct('Riz');

        $crawler = $this->client->request('GET', '/jour/distrib/new');
        $this->assertCount(2, $crawler->filter('input[name="jour_distrib[products][]"][checked]'));

        $date = new \DateTime('+14 days');
        $this->client->submit($crawler->filter('form[name="jour_distrib"]')->form([
            'jour_distrib[date]' => $date->format('Y-m-d'),
            'jour_distrib[datelivraison]' => (new \DateTime('+16 days'))->format('Y-m-d'),
            'jour_distrib[total]' => '150',
            'jour_distrib[limite]' => true,
        ]));

        $this->assertRedirectsTo('/jour/distrib/');
        $jours = $this->em()->getRepository(JourDistrib::class)->findAll();
        $this->assertCount(1, $jours);
        $jour = $jours[0];
        $this->assertSame($date->format('Y-m-d'), $jour->getDate()->format('Y-m-d'));
        $this->assertEquals(150, $jour->getTotal());
        $this->assertTrue($jour->getLimite());
        $this->assertFalse((bool) $jour->getClosed());
        $this->assertCount(2, $jour->getProducts());
    }

    public function testEditDistributionDay(): void
    {
        $farine = $this->createProduct('Farine');
        $riz = $this->createProduct('Riz');
        $jour = $this->createJourDistrib([$farine, $riz], 100);

        $crawler = $this->client->request('GET', '/jour/distrib/'.$jour->getId().'/edit');
        $form = $crawler->filter('form[name="jour_distrib"]')->form();
        $form['jour_distrib[closed]']->tick();
        $form['jour_distrib[products]'][1]->untick();
        $this->client->submit($form);

        $this->assertRedirectsTo('/jour/distrib/');
        $jour = $this->reload(JourDistrib::class, $jour->getId());
        $this->assertTrue($jour->getClosed());
        $this->assertCount(1, $jour->getProducts());
    }

    public function testDeleteDistributionDayDeletesItsOrders(): void
    {
        $farine = $this->createProduct('Farine');
        $jour = $this->createJourDistrib([$farine]);
        $this->createCommande($this->createUser(), $jour, [[$farine, 1]]);

        $this->submitFormWithAction('/jour/distrib/'.$jour->getId().'/edit', '/jour/distrib/'.$jour->getId());

        $this->assertRedirectsTo('/jour/distrib/');
        $this->assertNull($this->reload(JourDistrib::class, $jour->getId()));
        $this->assertCount(0, $this->em()->getRepository(\App\Entity\Commande::class)->findAll());
    }

    public function testEditTextSetting(): void
    {
        $crawler = $this->client->request('GET', '/settings/3/edit');
        $this->client->submit($crawler->filter('form[name="settings"]')->form([
            'settings[value]' => 'Mon groupement',
        ]));

        $this->assertRedirectsTo('/settings/');
        $this->assertSame('Mon groupement', $this->setting('name'));

        // Le nom est affiché dans le titre des pages
        $this->client->followRedirect();
        $this->assertSelectorTextContains('title', 'Mon groupement');
    }

    public function testEditColorSetting(): void
    {
        $crawler = $this->client->request('GET', '/settings/1/edit');
        $this->client->submit($crawler->filter('form[name="settings"]')->form([
            'settings[value]' => 'green',
        ]));

        $this->assertRedirectsTo('/settings/');
        $this->assertSame('green', $this->setting('color'));
    }

    public function testContactEmailMustBeValid(): void
    {
        $crawler = $this->client->request('GET', '/settings/5/edit');
        $this->client->submit($crawler->filter('form[name="settings"]')->form([
            'settings[value]' => 'pas-un-email',
        ]));

        $this->assertResponseIsSuccessful();
        $this->assertSame('contact@example.com', $this->setting('contact_email'));
    }

    public function testListAndShowUsers(): void
    {
        $user = $this->createUser('jean@example.com', [], 'Martin', 'Jean');

        $this->client->request('GET', '/user/');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Martin', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/user/'.$user->getId());
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('jean@example.com', $this->client->getResponse()->getContent());
    }

    public function testPromoteUserToAdmin(): void
    {
        $user = $this->createUser('jean@example.com');

        $crawler = $this->client->request('GET', '/user/'.$user->getId().'/edit');
        $form = $crawler->filter('form[name="user_admin"]')->form();
        $form['user_admin[roles][0]']->tick();
        $form['user_admin[nom]'] = 'Martin';
        $this->client->submit($form);

        $this->assertRedirectsTo('/user/');
        $user = $this->reload(User::class, $user->getId());
        $this->assertSame('Martin', $user->getNom());
        $this->assertContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testDeleteUser(): void
    {
        $user = $this->createUser('jean@example.com');

        $this->submitFormWithAction('/user/'.$user->getId(), '/user/'.$user->getId());

        $this->assertRedirectsTo('/user/');
        $this->assertNull($this->reload(User::class, $user->getId()));
    }

    public function testFirstUserCannotBeDeleted(): void
    {
        // L'administrateur créé dans setUp() est l'utilisateur n°1, mais il
        // est aussi connecté : on se connecte avec un second administrateur.
        $this->assertSame(1, $this->admin->getId());
        $this->login($this->createAdmin('admin2@example.com'));

        $this->submitFormWithAction('/user/1', '/user/1');

        $this->assertRedirectsTo('/user/');
        $this->assertNotNull($this->reload(User::class, 1));
        $this->client->followRedirect();
        $this->assertSelectorExists('.alert-danger');
    }

    public function testConnectedUserCannotDeleteHimself(): void
    {
        $admin = $this->createAdmin('admin2@example.com');
        $this->login($admin);

        $this->submitFormWithAction('/user/'.$admin->getId(), '/user/'.$admin->getId());

        $this->assertRedirectsTo('/user/');
        $this->assertNotNull($this->reload(User::class, $admin->getId()));
        $this->client->followRedirect();
        $this->assertSelectorExists('.alert-danger');
    }
}
