<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\Versionierung;
use App\Support\Db;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class VersionierungTest extends TestCase
{
    private Db $db;
    private Versionierung $versionierung;

    protected function setUp(): void
    {
        $this->db = TestDb::erstellen();
        $this->versionierung = new Versionierung($this->db);
    }

    private function anlegen(string $name = 'Anna Muster', string $beitrag = '30.00'): int
    {
        $this->db->ausfuehren(
            'INSERT INTO testperson (name, jahresbeitrag, created_at, updated_at) VALUES (:n, :b, :c, :c)',
            ['n' => $name, 'b' => $beitrag, 'c' => '2026-01-01 10:00:00'],
        );

        return (int) $this->db->letzteId();
    }

    public function testSnapshotUndDiffWerdenGeschrieben(): void
    {
        $id = $this->anlegen();

        $ergebnis = $this->versionierung->mitSnapshot('testperson', $id, 7, function (Db $db) use ($id): void {
            $db->ausfuehren('UPDATE testperson SET name = :n WHERE id = :id', ['n' => 'Anna Neu', 'id' => $id]);
        });

        self::assertSame(['name'], $ergebnis['geaenderte_felder']);

        $version = $this->db->eineZeile('SELECT * FROM testperson_version WHERE id = :id', ['id' => $ergebnis['version_id']]);
        self::assertNotNull($version);
        self::assertSame(1, (int) $version['version_nr']);
        self::assertSame(7, (int) $version['geaendert_von']);

        // Snapshot hält den Zustand VOR der Änderung.
        $snapshot = json_decode((string) $version['snapshot'], true);
        self::assertSame('Anna Muster', $snapshot['name']);

        // Der Datensatz selbst trägt den neuen Wert.
        self::assertSame('Anna Neu', $this->db->einWert('SELECT name FROM testperson WHERE id = :id', ['id' => $id]));
    }

    public function testVersionsnummerZaehltHoch(): void
    {
        $id = $this->anlegen();

        $this->versionierung->mitSnapshot('testperson', $id, 1, fn (Db $db) => $db->ausfuehren('UPDATE testperson SET name = :n WHERE id = :id', ['n' => 'B', 'id' => $id]));
        $this->versionierung->mitSnapshot('testperson', $id, 1, fn (Db $db) => $db->ausfuehren('UPDATE testperson SET name = :n WHERE id = :id', ['n' => 'C', 'id' => $id]));

        $nummern = $this->db->alleZeilen('SELECT version_nr FROM testperson_version ORDER BY version_nr');
        self::assertSame([1, 2], array_map(static fn ($z) => (int) $z['version_nr'], $nummern));
    }

    public function testNurGeaenderteFelderImDiff(): void
    {
        $id = $this->anlegen(beitrag: '30.00');

        $ergebnis = $this->versionierung->mitSnapshot('testperson', $id, 1, function (Db $db) use ($id): void {
            // Beitrag ändern, Name gleich lassen.
            $db->ausfuehren('UPDATE testperson SET jahresbeitrag = :b WHERE id = :id', ['b' => '60.00', 'id' => $id]);
        });

        self::assertSame(['jahresbeitrag'], $ergebnis['geaenderte_felder']);
    }

    public function testIbanBleibtImSnapshotVerschluesselt(): void
    {
        $id = $this->anlegen();
        $chiffre = 'BASE64VERSCHLUESSELT==';
        $this->db->ausfuehren('UPDATE testperson SET iban_verschluesselt = :i WHERE id = :id', ['i' => $chiffre, 'id' => $id]);

        // Änderung des Namens → Snapshot enthält den (verschlüsselten) IBAN-Wert im Klartext-Chiffrat, nie die IBAN selbst.
        $ergebnis = $this->versionierung->mitSnapshot('testperson', $id, 1, function (Db $db) use ($id): void {
            $db->ausfuehren('UPDATE testperson SET name = :n WHERE id = :id', ['n' => 'X', 'id' => $id]);
        });

        $version = $this->db->eineZeile('SELECT snapshot FROM testperson_version WHERE id = :id', ['id' => $ergebnis['version_id']]);
        $snapshot = json_decode((string) $version['snapshot'], true);
        self::assertSame($chiffre, $snapshot['iban_verschluesselt']);
    }

    public function testRevertStelltAeltererStandAlsNeueVersionWiederHer(): void
    {
        $id = $this->anlegen(name: 'Original');

        // Version 1: Original → Zwischenstand
        $v1 = $this->versionierung->mitSnapshot('testperson', $id, 1, fn (Db $db) => $db->ausfuehren('UPDATE testperson SET name = :n WHERE id = :id', ['n' => 'Zwischenstand', 'id' => $id]));

        // Revert auf Version 1 (Snapshot = "Original")
        $revert = $this->versionierung->revert('testperson', $id, (int) $v1['version_id'], 9);

        // Datensatz trägt wieder den Originalnamen.
        self::assertSame('Original', $this->db->einWert('SELECT name FROM testperson WHERE id = :id', ['id' => $id]));

        // Neue Version mit ist_revert_von gesetzt, Historie lückenlos (3 Versionen).
        $revVersion = $this->db->eineZeile('SELECT * FROM testperson_version WHERE id = :id', ['id' => $revert['version_id']]);
        self::assertSame((int) $v1['version_id'], (int) $revVersion['ist_revert_von']);
        self::assertSame(9, (int) $revVersion['geaendert_von']);
        self::assertSame(2, (int) $this->db->einWert('SELECT COUNT(*) FROM testperson_version'));
    }

    public function testFehlerImUpdateRolltAllesZurueck(): void
    {
        $id = $this->anlegen(name: 'Unverändert');

        try {
            $this->versionierung->mitSnapshot('testperson', $id, 1, function (Db $db) use ($id): void {
                $db->ausfuehren('UPDATE testperson SET name = :n WHERE id = :id', ['n' => 'Zwischen', 'id' => $id]);
                throw new \RuntimeException('Absichtlicher Fehler nach dem UPDATE.');
            });
            self::fail('Es wurde keine Exception geworfen.');
        } catch (\RuntimeException) {
            // erwartet
        }

        // Weder Datenänderung noch Versionszeile dürfen bestehen.
        self::assertSame('Unverändert', $this->db->einWert('SELECT name FROM testperson WHERE id = :id', ['id' => $id]));
        self::assertSame(0, (int) $this->db->einWert('SELECT COUNT(*) FROM testperson_version'));
    }

    public function testUngueltigerTabellennameWirdAbgelehnt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->versionierung->mitSnapshot('testperson; DROP TABLE benutzer', 1, 1, fn (Db $db) => null);
    }
}
