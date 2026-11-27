<?php
// ============================================================
// Lexware Office – Projekt übertragen (AP-20261126, B4, Modus B)
// ============================================================
// Vorschau ohne API-Aufruf; Senden mit Sperre je Projekt (Token,
// E-003/E-022), Kontoprüfung, Kontakt sicherstellen und nur Entwürfe
// (nie finalize). Rechnungsentwürfe vermerken die abgerechneten
// Positionen (Teilabrechnung ohne Doppelungen).
// ============================================================

namespace App\Modules\Lexoffice;

use App\Database;
use App\Services\ProjektPositionen;

final class LexofficeProjekt
{
    public const APP_URL = 'https://app.lexware.de/permalink/';
    private const SPERRE_SEK = 300;

    public function __construct(private \PDO $db, private LexofficeSync $sync) {}

    /** Vorschau für die Übertragungsmaske. */
    public function vorschau(int $baustelleId, array $auswahlRoh, array $settings): array
    {
        $projekt = $this->projekt($baustelleId);
        $auswahl = LexofficeBeleg::auswahl($auswahlRoh, ($projekt['data']['reverseCharge13b'] ?? false) === true);
        $alle = $this->positionen($baustelleId, $projekt['data']);
        $beleg = LexofficeBeleg::bilden($alle, $auswahl, self::firma($settings));
        $kunde = $projekt['kundeId'] > 0 ? Database::fetchOne($this->db, 'SELECT firma, vorname, nachname, leitwegId FROM kunden WHERE id = ?', [$projekt['kundeId']]) : null;

        $hinweise = [];
        if (!$kunde) {
            $hinweise[] = 'Dem Projekt ist kein Kunde zugeordnet – bitte zuerst einen Kunden wählen.';
        } elseif (trim((string) ($kunde['leitwegId'] ?? '')) !== '') {
            $hinweise[] = 'Der Kunde hat eine Leitweg-ID: für eine XRechnung in Lexware Office am Kontakt hinterlegen.';
        }
        if (array_filter($beleg['positionen'], static fn($p) => $p['ep'] == 0)) {
            $hinweise[] = 'Einige Positionen haben keinen Preis.';
        }
        if (array_filter($beleg['positionen'], static fn($p) => $p['menge'] * $p['ep'] < 0)) {
            $hinweise[] = 'Enthält Gutschrift-Positionen (negative Beträge).';
        }
        if (array_filter($alle, static fn($p) => $p['id'] === '')) {
            $hinweise[] = 'Einige Positionen haben keine feste Nummer und werden nicht als abgerechnet vermerkt.';
        }
        $abweichung = round($beleg['summen']['netto'] - $beleg['positionenNetto'], 2);
        if ($abweichung != 0.0) {
            $hinweise[] = sprintf('Durch das Zusammenfassen weicht der Nettobetrag um %s € von der Summe der Einzelpositionen ab.', number_format($abweichung, 2, ',', '.'));
        }
        $inBeleg = [];
        foreach ($beleg['positionen'] as $p) {
            $inBeleg[$p['art'] . ':' . $p['id']] = true;
        }

        return [
            'projekt'    => ['id' => $baustelleId, 'name' => $projekt['name'], 'kunde' => $kunde ? trim(($kunde['firma'] ?: $kunde['vorname'] . ' ' . $kunde['nachname'])) : ''],
            'auswahl'    => $auswahl,
            'positionen' => array_map(static fn($p) => [
                'art' => $p['art'], 'id' => $p['id'], 'bezeichnung' => $p['bezeichnung'], 'menge' => $p['menge'], 'einheit' => $p['einheit'],
                'ep' => round($p['ep'], 2), 'betrag' => LexofficeBeleg::betrag($p['menge'], $p['ep']), 'datum' => $p['datum'],
                'abgerechnet' => $p['abgerechnet'], 'imBeleg' => isset($inBeleg[$p['art'] . ':' . $p['id']]),
            ], $alle),
            'lineItems'  => array_map(static fn($li) => $li['type'] === 'custom'
                ? $li + ['betrag' => LexofficeBeleg::betrag($li['quantity'], $li['unitPrice']['netAmount'])] : $li, $beleg['lineItems']),
            'summen'     => $beleg['summen'],
            'taxType'    => $beleg['taxType'],
            'von'        => $beleg['von'],
            'bis'        => $beleg['bis'],
            'hinweise'   => $hinweise,
        ];
    }

    /** Legt das Angebot bzw. den Rechnungsentwurf in Lexware Office an. */
    public function senden(int $baustelleId, array $auswahlRoh, array $settings, bool $trotzdem): array
    {
        $projekt = $this->projekt($baustelleId);
        if ($projekt['kundeId'] <= 0) {
            throw new LexofficeFehler('kein_kunde', 400);
        }
        $auswahl = LexofficeBeleg::auswahl($auswahlRoh, ($projekt['data']['reverseCharge13b'] ?? false) === true);
        $beleg = LexofficeBeleg::bilden($this->positionen($baustelleId, $projekt['data']), $auswahl, self::firma($settings));
        if (!array_filter($beleg['lineItems'], static fn($li) => $li['type'] === 'custom')) {
            throw new LexofficeFehler('keine_positionen', 400);
        }

        $this->sync->kontoPruefen();
        $token = $this->sperren($baustelleId, $trotzdem);
        $belegGesendet = false;
        try {
            $contactId = $this->sync->kontaktSicherstellen($projekt['kundeId']);
            $heute = date('Y-m-d');
            $endpunkt = $auswahl['belegart'] === 'angebot' ? 'quotations' : 'invoices';
            $belegGesendet = true;
            $antwort = $this->sync->client()->post('/v1/' . $endpunkt, LexofficeBeleg::anfrage($beleg, $auswahl, $contactId, $heute));
            $lexId = (string) ($antwort['id'] ?? '');
            if ($lexId === '') {
                throw new LexofficeFehler('nicht_erreichbar', 0, 'Antwort ohne Beleg-ID');
            }
            $this->vermerken($baustelleId, $projekt['kundeId'], $lexId, $auswahl['belegart'], $beleg, $heute);
        } catch (LexofficeFehler $e) {
            if ($belegGesendet) {
                $this->sperreBeenden($baustelleId, $token, $e);
            } else {
                $this->entsperren($baustelleId, $token);
            }
            throw $e;
        }
        // Beleg ist angelegt und vermerkt – ein Fehler beim Freigeben darf das Ergebnis nicht verfälschen (E-022).
        try {
            $this->entsperren($baustelleId, $token);
        } catch (\Throwable $e) {
            error_log('[Lexoffice] Sperre für Projekt ' . $baustelleId . ' nicht freigegeben: ' . $e->getMessage());
        }
        return ['lexId' => $lexId, 'belegart' => $auswahl['belegart'], 'link' => self::APP_URL . $endpunkt . '/edit/' . rawurlencode($lexId), 'summen' => $beleg['summen']];
    }

    /** @return list<array<string,mixed>> Lexware-Belege des Projekts (geprüftes Konto) */
    public function belege(int $baustelleId): array
    {
        $stmt = $this->db->prepare('SELECT lexId, belegart, nummer, datum, netto, brutto, status, bezahltAm FROM lexoffice_belege WHERE baustelleId = ? AND organisationId = ? ORDER BY id');
        $stmt->execute([$baustelleId, $this->sync->organisationId()]);
        return array_map(static function ($b) {
            $pfad = ['quotation' => 'quotations', 'invoice' => 'invoices', 'downpaymentinvoice' => 'down-payment-invoices', 'creditnote' => 'credit-notes'][$b['belegart']] ?? 'invoices';
            return $b + ['link' => self::APP_URL . $pfad . '/view/' . rawurlencode((string) $b['lexId'])];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    // ── intern ───────────────────────────────────────────────

    private static function firma(array $settings): array
    {
        return [
            'kleinunternehmer' => (bool) ($settings['firma_kleinunternehmer'] ?? false),
            'steuersatz'       => (float) str_replace(',', '.', (string) ($settings['firma_mwst_satz'] ?? '19')),
        ];
    }

    /** @return array{name:string, kundeId:int, data:array<string,mixed>} */
    private function projekt(int $baustelleId): array
    {
        $row = Database::fetchOne($this->db, 'SELECT name, kundeId, data FROM baustellen WHERE id = ?', [$baustelleId]);
        if (!$row) {
            throw new LexofficeFehler('projekt_fehlt', 404);
        }
        $data = json_decode((string) $row['data'], true) ?: [];
        return ['name' => (string) $row['name'], 'kundeId' => (int) ($row['kundeId'] ?? $data['kundeId'] ?? 0), 'data' => $data];
    }

    /** Projektpositionen mit Kennzeichen „bereits in einem Rechnungsentwurf/einer Rechnung“. */
    private function positionen(int $baustelleId, array $data): array
    {
        $stmt = $this->db->prepare('SELECT a.positionArt, a.positionId FROM lexoffice_abrechnung a JOIN lexoffice_belege b ON b.lexId = a.lexId
            WHERE a.baustelleId = ? AND b.organisationId = ? AND b.status <> ?');
        $stmt->execute([$baustelleId, $this->sync->organisationId(), 'voided']);
        $abgerechnet = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $abgerechnet[$r['positionArt'] . ':' . $r['positionId']] = true;
        }
        $alle = ProjektPositionen::aus($data, ProjektPositionen::pauschalenKatalog($this->db), ProjektPositionen::stundenKategorien($this->db));
        foreach ($alle as &$p) {
            $p['abgerechnet'] = isset($abgerechnet[$p['art'] . ':' . $p['id']]);
        }
        return $alle;
    }

    private function vermerken(int $baustelleId, int $kundeId, string $lexId, string $belegart, array $beleg, string $heute): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare("INSERT INTO lexoffice_belege (lexId, organisationId, belegart, datum, netto, brutto, status, baustelleId, kundeId, geprueftAm)
                VALUES (?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?)")
                ->execute([$lexId, $this->sync->organisationId(), $belegart === 'angebot' ? 'quotation' : 'invoice', $heute,
                    $beleg['summen']['netto'], $beleg['summen']['brutto'], $baustelleId, $kundeId, date('c')]);
            if ($belegart === 'rechnung') {
                $ins = $this->db->prepare('INSERT INTO lexoffice_abrechnung (baustelleId, positionArt, positionId, lexId) VALUES (?, ?, ?, ?)');
                $vermerkt = [];
                foreach ($beleg['positionen'] as $p) {
                    $schluessel = $p['art'] . ':' . $p['id'];
                    if ((string) $p['id'] === '' || isset($vermerkt[$schluessel])) {
                        continue;
                    }
                    $vermerkt[$schluessel] = true;
                    $ins->execute([$baustelleId, $p['art'], (string) $p['id'], $lexId]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            // Beleg existiert in Lexware bereits – Sperre als unklar stehen lassen, nie still erneut senden.
            error_log('[Lexoffice] Beleg ' . $lexId . ' angelegt, aber nicht vermerkt: ' . $e->getMessage());
            throw new LexofficeFehler('nicht_erreichbar', 0, 'Vermerk fehlgeschlagen');
        }
    }

    private function sperren(int $baustelleId, bool $trotzdem): string
    {
        $token = 'sperre:' . bin2hex(random_bytes(8));
        $jetzt = date('c');
        $org = $this->sync->organisationId();
        $zeile = Database::fetchOne($this->db, "SELECT status, lexId, organisationId, geprueftAm FROM lexoffice_verknuepfung WHERE objektTyp = 'projekt' AND objektId = ?", [(string) $baustelleId]);
        if (!$zeile) {
            try {
                $this->db->prepare("INSERT INTO lexoffice_verknuepfung (objektTyp, objektId, lexId, organisationId, status, geprueftAm) VALUES ('projekt', ?, ?, ?, 'sendet', ?)")
                    ->execute([(string) $baustelleId, $token, $org, $jetzt]);
                return $token;
            } catch (\PDOException $e) {
                throw new LexofficeFehler('laeuft', 409, 'parallele Sperre');
            }
        }
        if ($zeile['status'] === 'sendet' && strtotime((string) $zeile['geprueftAm']) > time() - self::SPERRE_SEK) {
            throw new LexofficeFehler('laeuft', 409);
        }
        if ($zeile['organisationId'] === $org && !$trotzdem) {
            throw new LexofficeFehler('unklar_beleg', 409);
        }
        $upd = $this->db->prepare("UPDATE lexoffice_verknuepfung SET status = 'sendet', lexId = ?, organisationId = ?, fehlerCode = '', geprueftAm = ?
            WHERE objektTyp = 'projekt' AND objektId = ? AND status = ? AND lexId = ?");
        $upd->execute([$token, $org, $jetzt, (string) $baustelleId, $zeile['status'], $zeile['lexId']]);
        if ($upd->rowCount() !== 1) {
            throw new LexofficeFehler('laeuft', 409, 'Sperre verloren');
        }
        return $token;
    }

    private function entsperren(int $baustelleId, string $token): void
    {
        $this->db->prepare("DELETE FROM lexoffice_verknuepfung WHERE objektTyp = 'projekt' AND objektId = ? AND lexId = ?")
            ->execute([(string) $baustelleId, $token]);
    }

    /** Ohne Antwort (Timeout/5xx) bleibt die Sperre als „unklar“ stehen – Neuversand nur bewusst („trotzdem“). */
    private function sperreBeenden(int $baustelleId, string $token, LexofficeFehler $e): void
    {
        if ($e->fehlerCode === 'nicht_erreichbar') {
            $this->db->prepare("UPDATE lexoffice_verknuepfung SET status = 'fehler', fehlerCode = 'nicht_erreichbar' WHERE objektTyp = 'projekt' AND objektId = ? AND lexId = ?")
                ->execute([(string) $baustelleId, $token]);
            return;
        }
        $this->entsperren($baustelleId, $token);
    }
}
