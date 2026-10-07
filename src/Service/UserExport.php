<?php

namespace App\Service;

use App\Entity\User;
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
 * Export Excel (.xlsx) des utilisateurs, pour l'administration.
 */
class UserExport
{
    private const COLUMNS = [
        'Nom' => 22, 'Prénom' => 18, 'E-mail' => 32, 'Téléphone' => 16, 'Rôle' => 16,
        'Newsletter' => 12, 'Groupes de newsletter' => 28, 'Commandes' => 12, 'Dernière commande' => 18,
    ];

    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Écrit le classeur dans $path (« php://output » pour l'envoyer directement).
     */
    public function write(string $path): void
    {
        $options = new Options();
        $column = 1;
        foreach (self::COLUMNS as $width) {
            $options->setColumnWidth($width, $column++);
        }
        // Impression : A4 paysage, toutes les colonnes sur la largeur de la page
        $options->setPageSetup(new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A4, 0, 1));
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Utilisateurs');
        // Ligne d'en-tête figée, avec filtres
        $writer->getCurrentSheet()->setSheetView((new SheetView())->setFreezeRow(2));

        $header = (new Style())->setFontBold()->setBackgroundColor('EEF0F3');
        $writer->addRow(Row::fromValues(array_keys(self::COLUMNS), $header));
        $date = (new Style())->setFormat('dd/mm/yyyy');

        $rows = $this->rows();
        foreach ($rows as [$user, $orders, $lastOrder]) {
            /** @var User $user */
            $writer->addRow(new Row([
                Cell::fromValue((string) $user->getNom()),
                Cell::fromValue((string) $user->getPrenom()),
                Cell::fromValue((string) $user->getMail()),
                // Texte : garde le zéro initial du numéro
                Cell::fromValue((string) $user->getPhone()),
                Cell::fromValue(\in_array('ROLE_ADMIN', $user->getRoles(), true) ? 'Administrateur' : 'Utilisateur'),
                Cell::fromValue($user->isNewsletter() ? 'Oui' : 'Non'),
                Cell::fromValue(implode(', ', $user->getNewsletterGroups()->map(function ($group) { return $group->getName(); })->toArray())),
                Cell::fromValue($orders),
                null !== $lastOrder ? Cell::fromValue(new \DateTimeImmutable($lastOrder), $date) : Cell::fromValue(''),
            ]));
        }

        $writer->getCurrentSheet()->setAutoFilter(new AutoFilter(0, 1, \count(self::COLUMNS) - 1, \count($rows) + 1));
        $writer->close();
    }

    /**
     * Utilisateurs triés par nom, avec leur nombre de commandes et la date de
     * la dernière (une seule requête, groupes chargés avec les utilisateurs).
     *
     * @return array<array{0: User, 1: int, 2: ?string}>
     */
    private function rows(): array
    {
        $users = $this->entityManager->createQueryBuilder()
            ->select('u', 'g')
            ->from(User::class, 'u')
            ->leftJoin('u.newsletterGroups', 'g')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        $stats = [];
        foreach ($this->entityManager->createQuery(
            'SELECT IDENTITY(c.user) AS user, COUNT(c.id) AS orders, MAX(c.date) AS last FROM App\Entity\Commande c GROUP BY c.user'
        )->getArrayResult() as $row) {
            $stats[$row['user']] = $row;
        }

        return array_map(function (User $user) use ($stats) {
            $stat = $stats[$user->getId()] ?? null;

            return [$user, (int) ($stat['orders'] ?? 0), $stat['last'] ?? null];
        }, $users);
    }
}
