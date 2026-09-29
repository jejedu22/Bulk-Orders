<?php

namespace App\Twig;

use App\Entity\Product;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('group_by_category', [$this, 'groupByCategory']),
            new TwigFilter('initials', [$this, 'initials']),
        ];
    }

    /**
     * Regroupe des produits par catégorie, triés par position de catégorie puis par nom.
     * Les produits sans catégorie sont placés à la fin (category = null).
     *
     * @param iterable|Product[] $products
     *
     * @return array<array{category: ?\App\Entity\Category, products: Product[]}>
     */
    public function groupByCategory(iterable $products): array
    {
        $groups = [];
        foreach ($products as $product) {
            $category = $product->getCategory();
            $key = $category ? $category->getId() : 0;
            if (!isset($groups[$key])) {
                $groups[$key] = ['category' => $category, 'products' => []];
            }
            $groups[$key]['products'][] = $product;
        }

        uasort($groups, function ($a, $b) {
            if (null === $a['category'] || null === $b['category']) {
                return null === $a['category'] ? 1 : -1;
            }

            return [$a['category']->getPosition(), $a['category']->getNom()]
                <=> [$b['category']->getPosition(), $b['category']->getNom()];
        });

        foreach ($groups as &$group) {
            usort($group['products'], function (Product $a, Product $b) {
                return strcasecmp($a->getNom(), $b->getNom());
            });
        }

        return array_values($groups);
    }

    /**
     * Initiales pour les avatars (« Jean Dupont » → « JD »).
     */
    public function initials(?string ...$parts): string
    {
        $initials = '';
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ('' !== $part) {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }

        return $initials;
    }
}
