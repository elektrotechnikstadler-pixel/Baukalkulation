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
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            \jsonOut(['error' => 'Nur per POST.'], 405);
        }
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
