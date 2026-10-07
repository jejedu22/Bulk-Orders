<?php

namespace App\Tests\Twig;

use App\Entity\Category;
use App\Entity\Product;
use App\Twig\AppExtension;
use PHPUnit\Framework\TestCase;

class AppExtensionTest extends TestCase
{
    public function testGroupByCategory(): void
    {
        $cereales = $this->category(1, 'Céréales', 2);
        $epicerie = $this->category(2, 'Épicerie', 1);
        $boissons = $this->category(3, 'Boissons', 1);

        $groups = (new AppExtension())->groupByCategory([
            $this->product('riz', $cereales),
            $this->product('Sel', null),
            $this->product('Farine', $cereales),
            $this->product('Sucre', $epicerie),
            $this->product('Jus', $boissons),
        ]);

        // Par position puis par nom, produits sans catégorie à la fin
        $this->assertSame(['Boissons', 'Épicerie', 'Céréales', null], array_map(function ($group) {
            return $group['category'] ? $group['category']->getNom() : null;
        }, $groups));
        // Produits triés par nom, sans tenir compte de la casse
        $this->assertSame(['Farine', 'riz'], array_map(function (Product $p) {
            return $p->getNom();
        }, $groups[2]['products']));
    }

    public function testGroupByCategoryWithoutProducts(): void
    {
        $this->assertSame([], (new AppExtension())->groupByCategory([]));
    }

    public function testInitials(): void
    {
        $extension = new AppExtension();

        $this->assertSame('JD', $extension->initials('jean', 'Dupont'));
        $this->assertSame('ÉL', $extension->initials('élodie', ' Laurent '));
        $this->assertSame('M', $extension->initials('Martin', null, ''));
        $this->assertSame('', $extension->initials());
    }

    private function category(int $id, string $nom, int $position): Category
    {
        $category = new Category();
        $category->setNom($nom);
        $category->setPosition($position);
        $reflection = new \ReflectionProperty(Category::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($category, $id);

        return $category;
    }

    private function product(string $nom, ?Category $category): Product
    {
        $product = new Product();
        $product->setNom($nom);
        $product->setCategory($category);

        return $product;
    }
}
