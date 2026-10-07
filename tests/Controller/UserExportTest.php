<?php

namespace App\Tests\Controller;

use App\Entity\NewsletterGroup;
use App\Tests\WebTestCase;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Export Excel des utilisateurs (administration).
 */
class UserExportTest extends WebTestCase
{
    public function testReservedToAdmins(): void
    {
        $this->login($this->createUser());
        $this->client->request('GET', '/user/export');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testExportUsers(): void
    {
        $admin = $this->createAdmin();
        $alice = $this->createUser('alice@example.com', [], 'Martin', 'Alice');
        $alice->setPhone('0612345678');
        $bob = $this->createUser('bob@example.com', [], 'Durand', 'Bob');
        $bob->setNewsletter(false);
        $group = new NewsletterGroup();
        $group->setName('Bureau');
        $group->addUser($alice);
        $this->persist($group);
        $product = $this->createProduct();
        $jour = $this->createJourDistrib([$product]);
        $this->createCommande($alice, $jour, [[$product, 1]]);
        $this->createCommande($alice, $this->createJourDistrib([$product], 0, false, false, '+14 days'), [[$product, 2]]);

        $this->login($admin);
        $this->client->request('GET', '/user/export');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('attachment; filename=utilisateurs-'.date('Y-m-d').'.xlsx', $this->client->getResponse()->headers->get('Content-Disposition'));

        $rows = $this->read($this->client->getInternalResponse()->getContent());

        $this->assertSame(['Nom', 'Prénom', 'E-mail', 'Téléphone', 'Rôle', 'Newsletter', 'Groupes de newsletter', 'Commandes', 'Dernière commande'], $rows[0]);
        // Triés par nom : Admin, Durand, Martin
        $this->assertSame(['Admin', 'Durand', 'Martin'], array_column(\array_slice($rows, 1), 0));
        $this->assertSame('Administrateur', $rows[1][4]);
        $this->assertSame(['Durand', 'Bob', 'bob@example.com', '0600000000', 'Utilisateur', 'Non', '', 0, ''], $rows[2]);
        $this->assertSame(['Martin', 'Alice', 'alice@example.com', '0612345678', 'Utilisateur', 'Oui', 'Bureau', 2], \array_slice($rows[3], 0, 8));
        $this->assertInstanceOf(\DateTimeInterface::class, $rows[3][8]);
        $this->assertSame(date('Y-m-d'), $rows[3][8]->format('Y-m-d'));
    }

    private function read(string $content): array
    {
        $file = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($file, $content);
        $reader = new Reader();
        $reader->open($file);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $this->assertSame('Utilisateurs', $sheet->getName());
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        unlink($file);

        return $rows;
    }
}
