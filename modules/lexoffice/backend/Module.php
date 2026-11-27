<?php
// ============================================================
// Lexware Office (ehem. lexoffice) – Modul-Adapter für den ModuleLoader
// AP-20261126-lexoffice-schnittstelle, Bündel B1
// ============================================================
// Endpunkte: status, verbindung_testen, modus_setzen.
// Schalter `modul_lexoffice` (Standard aus) in Verwaltung → Allgemein.
// ============================================================

namespace App\Modules\Lexoffice;

use App\Auth;
use App\Core\AbstractModule;
use App\Services\AuditService;
use App\Services\RechnungsModus;

require_once __DIR__ . '/LexofficeTransport.php';
require_once __DIR__ . '/LexofficeClient.php';
require_once __DIR__ . '/LexofficeMapper.php';
require_once __DIR__ . '/LexofficeSync.php';

class Module extends AbstractModule
{
    public function dispatch(string $action): void
    {
        $settings = Auth::loadSettings($this->db);
        if (($settings['modul_lexoffice'] ?? false) !== true) {
            \jsonOut(['error' => 'Modul Lexware Office ist deaktiviert.'], 403);
        }

        switch ($action) {
            case 'status':
                $this->requirePerm('canLexoffice');
                $this->status($settings);
                break;
            case 'verbindung_testen':
                $this->nurAdminPerPost();
                $this->verbindungTesten($settings);
                break;
            case 'modus_setzen':
                $this->nurAdminPerPost();
                $this->modusSetzen($settings);
                break;
            case 'kunden_status':
                $this->requirePerm('canLexoffice');
                $this->requirePerm('canReadKunden');
                $this->kundenStatus($settings);
                break;
            case 'kunden_abgleich_vorschau':
                $this->nurAdminPerPost();
                $this->mitSync($settings, fn(LexofficeSync $s) => ['kunden' => $s->abgleichVorschau()]);
            case 'kunden_abgleich_ausfuehren':
                $this->nurAdminPerPost();
                $this->abgleichAusfuehren($settings);
                break;
            case 'kunde_uebertragen':
                $this->requirePerm('canLexoffice');
                $this->requirePerm('canWriteKunden');
                $this->nurPost();
                $kundeId = (int) ($this->body['kundeId'] ?? 0);
                $this->mitSync($settings, function (LexofficeSync $s) use ($kundeId) {
                    $lexId = $s->kontaktSicherstellen($kundeId);
                    $this->audit('kunde_uebertragen', "Kunde {$kundeId} → {$lexId}");
                    return ['lexId' => $lexId];
                });
            case 'kunde_verknuepfung_loesen':
                $this->nurAdminPerPost();
                $kundeId = (int) ($this->body['kundeId'] ?? 0);
                LexofficeSync::verknuepfungLoesen($this->db, $kundeId);
                $this->audit('kunde_verknuepfung_loesen', "Kunde {$kundeId}");
                \jsonOut(['ok' => true]);
            default:
                \jsonOut(['error' => 'Unbekannte Lexware-Aktion: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')], 400);
        }
    }

    private function requirePerm(string $key): void
    {
        if (!Auth::canDo($this->db, $key)) {
            \jsonOut(['error' => "Keine Berechtigung: $key"], 403);
        }
    }

    /** Rolle zuerst, dann Methode (E-015). */
    private function nurAdminPerPost(): void
    {
        Auth::requireRole('admin');
        $this->nurPost();
    }

    private function nurPost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            \jsonOut(['error' => 'Nur per POST.'], 405);
        }
    }

    /** Führt einen Abgleich-Vorgang aus und übersetzt Fehler in feste Meldungen (E-014). */
    private function mitSync(array $settings, callable $vorgang): never
    {
        try {
            $ergebnis = $vorgang(LexofficeSync::fuer($this->db, $settings));
        } catch (LexofficeFehler $e) {
            error_log('[Lexoffice] ' . $e->fehlerCode . ' – ' . $e->getMessage());
            \jsonOut(['error' => $e->meldung(), 'fehlerCode' => $e->fehlerCode], self::httpStatus($e));
        }
        \jsonOut(['ok' => true] + $ergebnis);
    }

    private static function httpStatus(LexofficeFehler $e): int
    {
        return match ($e->fehlerCode) {
            'schluessel_fehlt'                                  => 400,
            'nicht_geprueft', 'anderes_konto', 'mehrdeutig', 'laeuft', 'unklar', 'bereits_verknuepft' => 409,
            'kunde_fehlt'                                       => 404,
            default                                             => 502,
        };
    }

    private function kundenStatus(array $settings): void
    {
        $org = (string) ($settings['lexoffice_profil']['organisationId'] ?? '');
        $kunden = $org === '' ? [] : LexofficeSync::kundenStatusFuer($this->db, $org);
        \jsonOut(['ok' => true, 'verbunden' => $org !== '', 'kunden' => (object) $kunden]);
    }

    private function abgleichAusfuehren(array $settings): void
    {
        $zuordnungen = $this->body['zuordnungen'] ?? null;
        if (!is_array($zuordnungen) || count($zuordnungen) === 0 || count($zuordnungen) > 10) {
            \jsonOut(['error' => 'Bitte 1 bis 10 Zuordnungen je Aufruf senden.'], 400);
        }
        $this->mitSync($settings, function (LexofficeSync $s) use ($zuordnungen) {
            $s->kontoPruefen();
            $ergebnisse = [];
            foreach ($zuordnungen as $z) {
                $kundeId = (int) ($z['kundeId'] ?? 0);
                $aktion = (string) ($z['aktion'] ?? '');
                try {
                    $lexId = match ($aktion) {
                        'verknuepfen' => $s->verknuepfen($kundeId, (string) ($z['lexId'] ?? '')),
                        'anlegen'     => $s->kontaktSicherstellen($kundeId),
                        default       => throw new LexofficeFehler('fehler', 400, 'unbekannte Aktion'),
                    };
                    $ergebnisse[] = ['kundeId' => $kundeId, 'ok' => true, 'lexId' => $lexId];
                } catch (LexofficeFehler $e) {
                    if (in_array($e->fehlerCode, ['schluessel_ungueltig', 'tarif', 'anderes_konto', 'rate_limit'], true)) {
                        throw $e;
                    }
                    error_log('[Lexoffice] Abgleich Kunde ' . $kundeId . ': ' . $e->fehlerCode . ' – ' . $e->getMessage());
                    $ergebnisse[] = ['kundeId' => $kundeId, 'ok' => false, 'error' => $e->meldung()];
                }
            }
            $this->audit('kunden_abgleich', count(array_filter($ergebnisse, fn($e) => $e['ok'])) . ' von ' . count($ergebnisse) . ' Kunden zugeordnet');
            return ['ergebnisse' => $ergebnisse];
        });
    }

    private function status(array $settings): void
    {
        \jsonOut([
            'ok'                => true,
            'schluesselGesetzt' => ($settings['lexoffice_api_key'] ?? '') !== '',
            'modus'             => RechnungsModus::inLexware($settings) ? RechnungsModus::LEXWARE : RechnungsModus::BAUKALKULATION,
            'rechnungenAktiv'   => RechnungsModus::aktiv($settings),
            'profil'            => is_array($settings['lexoffice_profil'] ?? null) ? $settings['lexoffice_profil'] : [],
        ]);
    }

    private function verbindungTesten(array $settings): void
    {
        try {
            $antwort = LexofficeClient::ausEinstellungen($settings)->get('/v1/profile');
        } catch (LexofficeFehler $e) {
            error_log('[Lexoffice] verbindung_testen: ' . $e->fehlerCode . ' – ' . $e->getMessage());
            \jsonOut(['error' => $e->meldung(), 'fehlerCode' => $e->fehlerCode], $e->fehlerCode === 'schluessel_fehlt' ? 400 : 502);
        }

        $profil = [
            'firma'            => (string) ($antwort['companyName'] ?? ''),
            'organisationId'   => (string) ($antwort['organizationId'] ?? ''),
            'steuerart'        => (string) ($antwort['taxType'] ?? ''),
            'kleinunternehmer' => (bool) ($antwort['smallBusiness'] ?? false),
            'geprueftAm'       => date('c'),
        ];
        $hinweise = [];
        if ($profil['kleinunternehmer'] !== (bool) ($settings['firma_kleinunternehmer'] ?? false)) {
            $hinweise[] = $profil['kleinunternehmer']
                ? 'Lexware Office führt die Firma als Kleinunternehmer, die Baukalkulation nicht (Verwaltung → Firma).'
                : 'Die Baukalkulation führt die Firma als Kleinunternehmer, Lexware Office nicht (Verwaltung → Firma).';
        }

        // Nur das Profil schreiben – und nur, wenn während des Aufrufs niemand den Schlüssel geändert hat (E-003).
        if (!Auth::setzeEinstellung($this->db, 'lexoffice_profil', $profil, ['lexoffice_api_key' => (string) $settings['lexoffice_api_key']])) {
            \jsonOut(['error' => 'Der API-Schlüssel wurde während der Prüfung geändert – bitte erneut testen.'], 409);
        }
        $this->audit('verbindung_testen', 'Verbindung erfolgreich geprüft');
        \jsonOut(['ok' => true, 'profil' => $profil, 'hinweise' => $hinweise]);
    }

    private function modusSetzen(array $settings): void
    {
        $modus = (string) ($this->body['modus'] ?? '');
        if (!in_array($modus, [RechnungsModus::BAUKALKULATION, RechnungsModus::LEXWARE], true)) {
            \jsonOut(['error' => 'Ungültige Betriebsart.'], 400);
        }
        if ($modus === RechnungsModus::LEXWARE
            && (($settings['lexoffice_api_key'] ?? '') === '' || ($settings['lexoffice_profil']['organisationId'] ?? '') === '')) {
            \jsonOut(['error' => 'Zuerst den API-Schlüssel hinterlegen und die Verbindung erfolgreich testen.'], 409);
        }
        // B4: zusätzlich sperren, solange Übertragungen offen sind (AP 2.2.3).
        Auth::setzeEinstellung($this->db, 'rechnung_erzeugung', $modus);
        $settings['rechnung_erzeugung'] = $modus;
        $this->audit('modus_setzen', 'Rechnungen erzeugen in: ' . $modus);
        \jsonOut(['ok' => true, 'modus' => $modus, 'rechnungenAktiv' => RechnungsModus::aktiv($settings)]);
    }

    private function audit(string $sub, string $details): void
    {
        try {
            AuditService::log('lexoffice.' . $sub, $details);
        } catch (\Throwable $e) {
            error_log('[Lexoffice] Audit fehlgeschlagen: ' . $e->getMessage());
        }
    }
}
