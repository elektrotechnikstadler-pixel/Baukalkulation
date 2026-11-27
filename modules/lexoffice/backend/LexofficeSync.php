<?php
// ============================================================
// Lexware Office – Abgleich Kunden ↔ Kontakte (AP-20261126, B2)
// ============================================================
// Regeln: Verknüpfungen gelten nur für das zuletzt geprüfte Lexware-
// Konto (organisationId) – Sicherungen eines fremden Kontos werden
// ignoriert. Vor jedem schreibenden Vorgang wird das Konto erneut
// geprüft. Jeder Schreibpfad (Anlegen und Verknüpfen) läuft über
// denselben Claim (status 'sendet', Token in lexId) und schließt nur
// bedingt ab (E-003, E-022); Anlegen erst nach Dublettensuche.
// ============================================================

namespace App\Modules\Lexoffice;

use App\Database;

final class LexofficeSync
{
    /** Ein laufender Claim gilt nach dieser Zeit als abgebrochen. */
    private const CLAIM_SEK = 300;
    private const MAX_SEITEN = 40;
    private const TOKEN_PRAEFIX = 'claim:';

    private bool $kontoGeprueft = false;

    public function __construct(
        private \PDO $db,
        private LexofficeClient $client,
        private string $organisationId,
    ) {}

    public static function fuer(\PDO $db, array $settings): self
    {
        $org = (string) ($settings['lexoffice_profil']['organisationId'] ?? '');
        if ($org === '') {
            throw new LexofficeFehler('nicht_geprueft');
        }
        return new self($db, LexofficeClient::ausEinstellungen($settings), $org);
    }

    public function client(): LexofficeClient
    {
        return $this->client;
    }

    public function organisationId(): string
    {
        return $this->organisationId;
    }

    /** Schützt davor, mit einem Schlüssel eines anderen Kontos zu schreiben. */
    public function kontoPruefen(): void
    {
        if ($this->kontoGeprueft) {
            return;
        }
        $profil = $this->client->get('/v1/profile');
        if ((string) ($profil['organizationId'] ?? '') !== $this->organisationId) {
            throw new LexofficeFehler('anderes_konto');
        }
        $this->kontoGeprueft = true;
    }

    /** @return array<int, array{status:string, lexId:string, fehlerCode:string}> */
    public function kundenStatus(): array
    {
        return self::kundenStatusFuer($this->db, $this->organisationId);
    }

    /** Nur Verknüpfungen des geprüften Kontos – fremde Sicherungen gelten als unverknüpft. */
    public static function kundenStatusFuer(\PDO $db, string $organisationId): array
    {
        $stmt = $db->prepare("SELECT objektId, status, lexId, fehlerCode FROM lexoffice_verknuepfung WHERE objektTyp = 'kunde' AND organisationId = ?");
        $stmt->execute([$organisationId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $lexId = str_starts_with((string) $r['lexId'], self::TOKEN_PRAEFIX) ? '' : (string) $r['lexId'];
            $out[(int) $r['objektId']] = ['status' => (string) $r['status'], 'lexId' => $lexId, 'fehlerCode' => (string) $r['fehlerCode']];
        }
        return $out;
    }

    /** Rein lokal – braucht weder Verbindung noch Kontoprüfung. */
    public static function verknuepfungLoesen(\PDO $db, int $kundeId): void
    {
        $db->prepare("DELETE FROM lexoffice_verknuepfung WHERE objektTyp = 'kunde' AND objektId = ?")
            ->execute([(string) $kundeId]);
    }

    /** @return list<array<string,mixed>> alle Kunden-Kontakte (seitenweise geladen) */
    public function alleKontakte(): array
    {
        $alle = [];
        for ($seite = 0; $seite < self::MAX_SEITEN; $seite++) {
            $antwort = $this->client->get('/v1/contacts?customer=true&size=250&page=' . $seite);
            foreach ((array) ($antwort['content'] ?? []) as $c) {
                $alle[] = $c;
            }
            if (($antwort['last'] ?? true) !== false) {
                break;
            }
        }
        return $alle;
    }

    /**
     * Vorschau für alle noch nicht verknüpften Kunden.
     * @return list<array<string,mixed>>
     */
    public function abgleichVorschau(): array
    {
        $this->kontoPruefen();
        $kontakte = $this->alleKontakte();
        $verknuepft = $this->kundenStatus();
        $out = [];
        foreach ($this->db->query('SELECT * FROM kunden ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $k) {
            $v = $verknuepft[(int) $k['id']] ?? null;
            if ($v !== null && $v['status'] === 'uebertragen') {
                continue;
            }
            $out[] = [
                'kundeId'      => (int) $k['id'],
                'kundennummer' => (string) ($k['kundennummer'] ?? ''),
                'name'         => trim((string) $k['firma']) !== '' ? (string) $k['firma'] : trim($k['vorname'] . ' ' . $k['nachname']),
                'ort'          => (string) $k['ort'],
                'vorschlag'    => LexofficeMapper::zuordnen($k, $kontakte),
            ];
        }
        return $out;
    }

    /** Verknüpft einen Kunden mit einem bestehenden Lexware-Kontakt (manuelle Zuordnung). */
    public function verknuepfen(int $kundeId, string $lexId): string
    {
        self::lexIdPruefen($lexId);
        $this->kundeLaden($kundeId);
        $this->kontoPruefen();
        $claim = $this->claim($kundeId);
        if ($claim['lexId'] !== null) {
            if ($claim['lexId'] === $lexId) {
                return $lexId;
            }
            throw new LexofficeFehler('bereits_verknuepft', 409);
        }
        try {
            return $this->kontaktUebernehmen($kundeId, $lexId, $claim['token']);
        } catch (LexofficeFehler $e) {
            $this->claimBeenden($kundeId, $claim['token'], $e);
            throw $e;
        }
    }

    /**
     * Liefert die Lexware-Kontakt-ID eines Kunden: vorhandene Verknüpfung, sonst eindeutiger
     * vorhandener Kontakt, sonst neu angelegter Kontakt.
     */
    public function kontaktSicherstellen(int $kundeId): string
    {
        $kunde = $this->kundeLaden($kundeId);
        $vorhanden = $this->kundenStatus()[$kundeId] ?? null;
        if ($vorhanden !== null && $vorhanden['status'] === 'uebertragen') {
            return $vorhanden['lexId'];
        }
        $this->kontoPruefen();
        $claim = $this->claim($kundeId);
        if ($claim['lexId'] !== null) {
            return $claim['lexId'];
        }
        try {
            if ($claim['vorherUnklar'] && !self::suchbar($kunde)) {
                // Ohne Suchmerkmal ließe sich ein beim letzten Versuch evtl. angelegter Kontakt nicht finden.
                throw new LexofficeFehler('unklar', 409);
            }
            $vorschlag = LexofficeMapper::zuordnen($kunde, $this->kandidaten($kunde));
            if ($vorschlag['status'] === 'mehrdeutig') {
                throw new LexofficeFehler('mehrdeutig');
            }
            if ($vorschlag['status'] === 'treffer') {
                return $this->kontaktUebernehmen($kundeId, (string) $vorschlag['lexId'], $claim['token']);
            }
            $antwort = $this->client->post('/v1/contacts', LexofficeMapper::kontakt($kunde));
            $lexId = (string) ($antwort['id'] ?? '');
            if ($lexId === '') {
                throw new LexofficeFehler('nicht_erreichbar', 0, 'Antwort ohne Kontakt-ID');
            }
            $this->abschliessen($kundeId, $claim['token'], $lexId, (int) ($antwort['version'] ?? 0), LexofficeMapper::inhaltHash($kunde));
            return $lexId;
        } catch (LexofficeFehler $e) {
            $this->claimBeenden($kundeId, $claim['token'], $e);
            throw $e;
        }
    }

    // ── intern ───────────────────────────────────────────────

    private static function lexIdPruefen(string $lexId): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $lexId)) {
            throw new LexofficeFehler('fehler', 400, 'ungültige Kontakt-ID');
        }
    }

    private static function suchbar(array $kunde): bool
    {
        $name = trim((string) ($kunde['firma'] ?? '')) !== '' ? trim((string) $kunde['firma']) : trim((string) ($kunde['nachname'] ?? ''));
        return trim((string) ($kunde['email'] ?? '')) !== '' || mb_strlen($name) >= 3;
    }

    private function kundeLaden(int $kundeId): array
    {
        $kunde = Database::fetchOne($this->db, 'SELECT * FROM kunden WHERE id = ?', [$kundeId]);
        if (!$kunde) {
            throw new LexofficeFehler('kunde_fehlt', 404);
        }
        return $kunde;
    }

    private function kontaktUebernehmen(int $kundeId, string $lexId, string $token): string
    {
        self::lexIdPruefen($lexId);
        $kontakt = $this->client->get('/v1/contacts/' . $lexId);
        if ((string) ($kontakt['organizationId'] ?? $this->organisationId) !== $this->organisationId || !isset($kontakt['roles']['customer'])) {
            throw new LexofficeFehler('fehler', 400, 'Kontakt gehört nicht zum Konto oder ist kein Kunde');
        }
        $this->abschliessen($kundeId, $token, $lexId, (int) ($kontakt['version'] ?? 0), '');
        return $lexId;
    }

    /** Gezielte Suche statt Volllast: E-Mail, sonst Name (Lexware filtert ab 3 Zeichen). */
    private function kandidaten(array $kunde): array
    {
        $email = trim((string) ($kunde['email'] ?? ''));
        $name = trim((string) ($kunde['firma'] ?? '')) !== '' ? trim((string) $kunde['firma']) : trim((string) ($kunde['nachname'] ?? ''));
        $treffer = [];
        if ($email !== '') {
            $treffer = (array) ($this->client->get('/v1/contacts?customer=true&email=' . rawurlencode($email))['content'] ?? []);
        }
        if (mb_strlen($name) >= 3) {
            foreach ((array) ($this->client->get('/v1/contacts?customer=true&name=' . rawurlencode($name))['content'] ?? []) as $c) {
                $treffer[] = $c;
            }
        }
        $eindeutig = [];
        foreach ($treffer as $c) {
            $eindeutig[(string) ($c['id'] ?? '')] = $c;
        }
        return array_values($eindeutig);
    }

    /**
     * Sperre gegen parallele/doppelte Übertragung desselben Kunden.
     * @return array{token:string, lexId:?string, vorherUnklar:bool} lexId gesetzt = bereits verknüpft (kein Claim)
     */
    private function claim(int $kundeId): array
    {
        $jetzt = date('c');
        $token = self::TOKEN_PRAEFIX . bin2hex(random_bytes(8));
        $zeile = Database::fetchOne($this->db, "SELECT status, organisationId, lexId, geprueftAm FROM lexoffice_verknuepfung WHERE objektTyp = 'kunde' AND objektId = ?", [(string) $kundeId]);
        if (!$zeile) {
            try {
                $this->db->prepare("INSERT INTO lexoffice_verknuepfung (objektTyp, objektId, lexId, organisationId, status, geprueftAm) VALUES ('kunde', ?, ?, ?, 'sendet', ?)")
                    ->execute([(string) $kundeId, $token, $this->organisationId, $jetzt]);
                return ['token' => $token, 'lexId' => null, 'vorherUnklar' => false];
            } catch (\PDOException $e) {
                throw new LexofficeFehler('laeuft', 409, 'paralleler Claim');
            }
        }
        $eigenesKonto = $zeile['organisationId'] === $this->organisationId;
        if ($eigenesKonto && $zeile['status'] === 'uebertragen') {
            return ['token' => '', 'lexId' => (string) $zeile['lexId'], 'vorherUnklar' => false];
        }
        if ($eigenesKonto && $zeile['status'] === 'sendet' && strtotime((string) $zeile['geprueftAm']) > time() - self::CLAIM_SEK) {
            throw new LexofficeFehler('laeuft', 409);
        }
        $upd = $this->db->prepare("UPDATE lexoffice_verknuepfung SET status = 'sendet', organisationId = ?, lexId = ?, fehlerCode = '', geprueftAm = ?
            WHERE objektTyp = 'kunde' AND objektId = ? AND status = ? AND lexId = ? AND organisationId = ?");
        $upd->execute([$this->organisationId, $token, $jetzt, (string) $kundeId, $zeile['status'], $zeile['lexId'], $zeile['organisationId']]);
        if ($upd->rowCount() !== 1) {
            throw new LexofficeFehler('laeuft', 409, 'Claim verloren');
        }
        // Abgebrochener Claim oder Zeitüberschreitung: Kontakt wurde evtl. schon angelegt.
        $unklar = $eigenesKonto && in_array($zeile['status'], ['sendet', 'fehler'], true);
        return ['token' => $token, 'lexId' => null, 'vorherUnklar' => $unklar];
    }

    /** Schließt nur den eigenen Claim ab – sonst hat ein paralleler Vorgang übernommen. */
    private function abschliessen(int $kundeId, string $token, string $lexId, int $version, string $hash): void
    {
        $jetzt = date('c');
        $upd = $this->db->prepare("UPDATE lexoffice_verknuepfung SET lexId = ?, lexVersion = ?, status = 'uebertragen', fehlerCode = '', inhaltHash = ?, uebertragenAm = ?, geprueftAm = ?
            WHERE objektTyp = 'kunde' AND objektId = ? AND status = 'sendet' AND lexId = ?");
        $upd->execute([$lexId, $version, $hash, $jetzt, $jetzt, (string) $kundeId, $token]);
        if ($upd->rowCount() !== 1) {
            error_log("[Lexoffice] Claim für Kunde {$kundeId} verloren – Kontakt {$lexId} nicht gespeichert");
            throw new LexofficeFehler('laeuft', 409, 'Claim beim Abschluss verloren');
        }
    }

    /** Eindeutig abgelehnt → Claim freigeben; unklar (Zeitüberschreitung, 5xx) → als Fehler stehen lassen. */
    private function claimBeenden(int $kundeId, string $token, LexofficeFehler $e): void
    {
        if ($e->fehlerCode === 'nicht_erreichbar') {
            $this->db->prepare("UPDATE lexoffice_verknuepfung SET status = 'fehler', fehlerCode = ? WHERE objektTyp = 'kunde' AND objektId = ? AND status = 'sendet' AND lexId = ?")
                ->execute([$e->fehlerCode, (string) $kundeId, $token]);
            return;
        }
        if ($e->fehlerCode === 'unklar') {
            $this->db->prepare("UPDATE lexoffice_verknuepfung SET status = 'fehler', fehlerCode = 'unklar' WHERE objektTyp = 'kunde' AND objektId = ? AND status = 'sendet' AND lexId = ?")
                ->execute([(string) $kundeId, $token]);
            return;
        }
        $this->db->prepare("DELETE FROM lexoffice_verknuepfung WHERE objektTyp = 'kunde' AND objektId = ? AND status = 'sendet' AND lexId = ?")
            ->execute([(string) $kundeId, $token]);
    }
}
