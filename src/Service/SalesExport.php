<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\JourDistrib;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Export Excel (.xlsx) d'une vente : une ligne par commande (donc par
 * personne), les produits commandés étant regroupés dans une seule cellule.
 */
class SalesExport
{
    private const COLUMNS = [
        'N° commande' => 13, 'Nom' => 22, 'Prénom' => 18, 'E-mail' => 32, 'Téléphone' => 16,
        'Date de commande' => 18, 'Produits' => 50, 'Total (€)' => 12, 'Commentaire' => 30,
        'Validée' => 10, 'Payée' => 10, 'Remise' => 10, 'Créneau de retrait' => 22,
    ];

    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Écrit le classeur dans $path (« php://output » pour l'envoyer directement).
     */
    public function write(JourDistrib $jourDistrib, string $path): void
    {
        $options = new Options();
        $column = 1;
        foreach (self::COLUMNS as $width) {
            $options->setColumnWidth($width, $column++);
        }
        $options->setPageSetup(new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A4, 0, 1));
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Ventes');
        $writer->getCurrentSheet()->setSheetView((new SheetView())->setFreezeRow(2));

        $writer->addRow(Row::fromValues(array_keys(self::COLUMNS), (new Style())->setFontBold()->setBackgroundColor('EEF0F3')));
        $date = (new Style())->setFormat('dd/mm/yyyy');
        $euros = (new Style())->setFormat('#,##0.00');
        $wrap = (new Style())->setShouldWrapText();

        $commandes = $this->commandes($jourDistrib);
        foreach ($commandes as $commande) {
            $user = $commande->getUser();
            $creneau = $commande->getCreneau();
            $writer->addRow(new Row([
                Cell::fromValue($commande->getId()),
                Cell::fromValue((string) $user->getNom()),
                Cell::fromValue((string) $user->getPrenom()),
                Cell::fromValue((string) $user->getMail()),
                // Texte : garde le zéro initial du numéro
                Cell::fromValue((string) $user->getPhone()),
                Cell::fromValue(\DateTimeImmutable::createFromInterface($commande->getDate()), $date),
                Cell::fromValue($this->produits($commande), $wrap),
                Cell::fromValue(round($commande->getTotal(), 2), $euros),
                Cell::fromValue((string) $commande->getCommentaire(), $wrap),
                Cell::fromValue($commande->getConfirmed() ? 'Oui' : 'Non'),
                Cell::fromValue($commande->getPayee() ? 'Oui' : 'Non'),
                Cell::fromValue($commande->getLivree() ? 'Oui' : 'Non'),
                Cell::fromValue($creneau ? $creneau->getDebut()->format('d/m/Y H:i').' - '.$creneau->getFin()->format('H:i') : ''),
            ]));
        }

        $writer->getCurrentSheet()->setAutoFilter(new AutoFilter(0, 1, \count(self::COLUMNS) - 1, \count($commandes) + 1));
        $writer->close();
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

    /**
     * @return Commande[]
     */
    private function commandes(JourDistrib $jourDistrib): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c', 'u', 'l', 'p', 'cr')
            ->from(Commande::class, 'c')
            ->join('c.user', 'u')
            ->leftJoin('c.ligneCommandes', 'l')
            ->leftJoin('l.product', 'p')
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
