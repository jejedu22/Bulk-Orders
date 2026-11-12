<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\JourDistrib;
use App\Entity\LigneCommande;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Export Excel (.xlsx) complet d'une vente, en plusieurs onglets :
 *  - Commandes : une ligne par commande (donc par personne) ;
 *  - Par produit : quantités totales, pour la commande au fournisseur ;
 *  - Détail : une ligne par produit commandé ;
 *  - Retraits : commandes triées par créneau, à imprimer pour la distribution.
 */
class SalesExport
{
    private const COMMANDES = [
        'N° commande' => 13, 'Nom' => 22, 'Prénom' => 18, 'E-mail' => 32, 'Téléphone' => 16,
        'Date de commande' => 18, 'Produits' => 50, 'Total (€)' => 12, 'Commentaire' => 30,
        'Validée' => 10, 'Payée' => 10, 'Remise' => 10, 'Créneau de retrait' => 22,
    ];

    private const PRODUITS = [
        'Catégorie' => 20, 'Produit' => 30, 'Conditionnement' => 16, 'Unité' => 10,
        'Quantité validée' => 16, 'Total validé' => 14, 'Commandes validées' => 18,
        'Quantité (toutes commandes)' => 26, 'Prix initial (€)' => 16, 'Prix final (€)' => 15, 'Montant validé (€)' => 18,
    ];

    private const DETAIL = [
        'N° commande' => 13, 'Nom' => 22, 'Prénom' => 18, 'Catégorie' => 20, 'Produit' => 30,
        'Conditionnement' => 16, 'Unité' => 10, 'Quantité' => 10, 'Prix initial (€)' => 16,
        'Prix final (€)' => 15, 'Montant (€)' => 13, 'Validée' => 10, 'Remise' => 10,
    ];

    private const RETRAITS = [
        'Créneau' => 22, 'Nom' => 22, 'Prénom' => 18, 'Téléphone' => 16, 'Produits' => 50,
        'Total (€)' => 12, 'Payée' => 10, 'Remis' => 10,
    ];

    private $entityManager;
    private $styles;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        $this->styles = [
            'header' => (new Style())->setFontBold()->setBackgroundColor('EEF0F3'),
            'date' => (new Style())->setFormat('dd/mm/yyyy'),
            'euros' => (new Style())->setFormat('#,##0.00'),
            'wrap' => (new Style())->setShouldWrapText(),
        ];
    }

    /**
     * Écrit le classeur dans $path (« php://output » pour l'envoyer directement).
     */
    public function write(JourDistrib $jourDistrib, string $path): void
    {
        $options = new Options();
        // Impression : A4 paysage, toutes les colonnes sur la largeur de la page
        $options->setPageSetup(new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A4, 0, 1));
        $writer = new Writer($options);
        $writer->openToFile($path);
        $commandes = $this->commandes($jourDistrib);

        $this->sheet($writer, $writer->getCurrentSheet(), 'Commandes', self::COMMANDES, $this->commandesRows($commandes));
        $this->sheet($writer, $writer->addNewSheetAndMakeItCurrent(), 'Par produit', self::PRODUITS, $this->produitsRows($commandes));
        $this->sheet($writer, $writer->addNewSheetAndMakeItCurrent(), 'Détail', self::DETAIL, $this->detailRows($commandes));
        $this->sheet($writer, $writer->addNewSheetAndMakeItCurrent(), 'Retraits', self::RETRAITS, $this->retraitsRows($commandes));

        $writer->setCurrentSheet($writer->getSheets()[0]);
        $writer->close();
    }

    /**
     * Onglet avec en-tête figé et filtres.
     *
     * @param array<string, int> $columns titre => largeur
     * @param Row[]              $rows
     */
    private function sheet(Writer $writer, Sheet $sheet, string $name, array $columns, array $rows): void
    {
        $sheet->setName($name);
        $sheet->setSheetView((new SheetView())->setFreezeRow(2));
        $column = 1;
        foreach ($columns as $width) {
            $sheet->setColumnWidth($width, $column++);
        }
        $writer->addRow(Row::fromValues(array_keys($columns), $this->styles['header']));
        $writer->addRows($rows);
        $sheet->setAutoFilter(new AutoFilter(0, 1, \count($columns) - 1, \count($rows) + 1));
    }

    /**
     * @param Commande[] $commandes
     *
     * @return Row[]
     */
    private function commandesRows(array $commandes): array
    {
        return array_map(function (Commande $commande) {
            $user = $commande->getUser();

            return new Row([
                Cell::fromValue($commande->getId()),
                Cell::fromValue((string) $user->getNom()),
                Cell::fromValue((string) $user->getPrenom()),
                Cell::fromValue((string) $user->getMail()),
                // Texte : garde le zéro initial du numéro
                Cell::fromValue((string) $user->getPhone()),
                Cell::fromValue(\DateTimeImmutable::createFromInterface($commande->getDate()), $this->styles['date']),
                Cell::fromValue($this->produits($commande), $this->styles['wrap']),
                Cell::fromValue(round($commande->getTotal(), 2), $this->styles['euros']),
                Cell::fromValue((string) $commande->getCommentaire(), $this->styles['wrap']),
                $this->ouiNon($commande->getConfirmed()),
                $this->ouiNon($commande->getPayee()),
                $this->ouiNon($commande->getLivree()),
                Cell::fromValue($this->creneau($commande)),
            ]);
        }, $commandes);
    }

    /**
     * Une ligne par produit commandé : quantités des commandes validées (celles
     * à commander au fournisseur) et de toutes les commandes.
     *
     * @param Commande[] $commandes
     *
     * @return Row[]
     */
    private function produitsRows(array $commandes): array
    {
        $stats = [];
        foreach ($commandes as $commande) {
            foreach ($commande->getLigneCommandes() as $ligne) {
                $product = $ligne->getProduct();
                $stat = &$stats[$product->getId() ?? spl_object_id($product)];
                $stat ??= ['product' => $product, 'valide' => 0, 'commandes' => 0, 'toutes' => 0];
                $stat['toutes'] += $ligne->getQuantite();
                if ($commande->getConfirmed()) {
                    $stat['valide'] += $ligne->getQuantite();
                    ++$stat['commandes'];
                }
                unset($stat);
            }
        }

        // Comme le récapitulatif : par catégorie (sans catégorie en dernier), puis par nom
        usort($stats, function (array $a, array $b) {
            $ca = $a['product']->getCategory();
            $cb = $b['product']->getCategory();

            return [null === $ca, $ca ? $ca->getPosition() : 0, $ca ? $ca->getNom() : '', $a['product']->getNom()]
                <=> [null === $cb, $cb ? $cb->getPosition() : 0, $cb ? $cb->getNom() : '', $b['product']->getNom()];
        });

        return array_map(function (array $stat) {
            /** @var Product $product */
            $product = $stat['product'];
            $prix = $product->getPrixFinal() ?? $product->getPrixInit();

            return new Row([
                Cell::fromValue($product->getCategory() ? (string) $product->getCategory()->getNom() : ''),
                Cell::fromValue((string) $product->getNom()),
                Cell::fromValue((float) $product->getConditionnement()),
                Cell::fromValue((string) $product->getUnit()),
                Cell::fromValue($stat['valide']),
                Cell::fromValue($stat['valide'] * (float) $product->getConditionnement()),
                Cell::fromValue($stat['commandes']),
                Cell::fromValue($stat['toutes']),
                $this->euros($product->getPrixInit()),
                $this->euros($product->getPrixFinal()),
                Cell::fromValue(round($stat['valide'] * (float) $prix, 2), $this->styles['euros']),
            ]);
        }, $stats);
    }

    /**
     * Une ligne par produit commandé, comme l'ancien export CSV.
     *
     * @param Commande[] $commandes
     *
     * @return Row[]
     */
    private function detailRows(array $commandes): array
    {
        $rows = [];
        foreach ($commandes as $commande) {
            $user = $commande->getUser();
            // Mêmes prix que le total de la commande
            $final = $commande->hasFinalPrices();
            foreach ($commande->getLigneCommandes() as $ligne) {
                /** @var LigneCommande $ligne */
                $product = $ligne->getProduct();
                $prix = $final ? $product->getPrixFinal() : $product->getPrixInit();
                $rows[] = new Row([
                    Cell::fromValue($commande->getId()),
                    Cell::fromValue((string) $user->getNom()),
                    Cell::fromValue((string) $user->getPrenom()),
                    Cell::fromValue($product->getCategory() ? (string) $product->getCategory()->getNom() : ''),
                    Cell::fromValue((string) $product->getNom()),
                    Cell::fromValue((float) $product->getConditionnement()),
                    Cell::fromValue((string) $product->getUnit()),
                    Cell::fromValue((int) $ligne->getQuantite()),
                    $this->euros($product->getPrixInit()),
                    $this->euros($product->getPrixFinal()),
                    Cell::fromValue(round($ligne->getQuantite() * (float) $prix, 2), $this->styles['euros']),
                    $this->ouiNon($commande->getConfirmed()),
                    $this->ouiNon($commande->getLivree() || $ligne->getLivree()),
                ]);
            }
        }

        return $rows;
    }

    /**
     * Commandes validées, triées par créneau (sans créneau en dernier) puis par
     * nom, avec une colonne « Remis » à cocher à la main.
     *
     * @param Commande[] $commandes
     *
     * @return Row[]
     */
    private function retraitsRows(array $commandes): array
    {
        $commandes = array_values(array_filter($commandes, function (Commande $commande) {
            return $commande->getConfirmed();
        }));
        // Tri stable : les commandes arrivent déjà triées par nom
        usort($commandes, function (Commande $a, Commande $b) {
            $ca = $a->getCreneau();
            $cb = $b->getCreneau();

            return [null === $ca, $ca ? $ca->getDebut() : null] <=> [null === $cb, $cb ? $cb->getDebut() : null];
        });

        return array_map(function (Commande $commande) {
            $user = $commande->getUser();

            return new Row([
                Cell::fromValue($this->creneau($commande) ?: 'Sans créneau'),
                Cell::fromValue((string) $user->getNom()),
                Cell::fromValue((string) $user->getPrenom()),
                Cell::fromValue((string) $user->getPhone()),
                Cell::fromValue($this->produits($commande), $this->styles['wrap']),
                Cell::fromValue(round($commande->getTotal(), 2), $this->styles['euros']),
                $this->ouiNon($commande->getPayee()),
                Cell::fromValue($commande->getLivree() ? 'Oui' : ''),
            ]);
        }, $commandes);
    }

    /**
     * « Farine 2 × 5 kg ; Riz 1 × 1 kg » : une cellule pour toute la commande.
     */
    private function produits(Commande $commande): string
    {
        $produits = [];
        foreach ($commande->getLigneCommandes() as $ligne) {
            $product = $ligne->getProduct();
            $produits[] = sprintf('%s %d × %s %s', $product->getNom(), $ligne->getQuantite(), $product->getConditionnement(), $product->getUnit());
        }

        return implode(' ; ', $produits);
    }

    private function creneau(Commande $commande): string
    {
        $creneau = $commande->getCreneau();

        return $creneau ? $creneau->getDebut()->format('d/m/Y H:i').' - '.$creneau->getFin()->format('H:i') : '';
    }

    private function ouiNon(?bool $value): Cell
    {
        return Cell::fromValue($value ? 'Oui' : 'Non');
    }

    private function euros(?float $value): Cell
    {
        return null === $value ? Cell::fromValue('') : Cell::fromValue($value, $this->styles['euros']);
    }

    /**
     * Commandes de la vente, triées par nom, avec utilisateurs, produits,
     * catégories et créneaux (une seule requête).
     *
     * @return Commande[]
     */
    private function commandes(JourDistrib $jourDistrib): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c', 'u', 'l', 'p', 'cat', 'cr')
            ->from(Commande::class, 'c')
            ->join('c.user', 'u')
            ->leftJoin('c.ligneCommandes', 'l')
            ->leftJoin('l.product', 'p')
            ->leftJoin('p.category', 'cat')
            ->leftJoin('c.creneau', 'cr')
            ->andWhere('c.jourDistrib = :jour')
            ->setParameter('jour', $jourDistrib)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
