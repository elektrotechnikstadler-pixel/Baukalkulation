<?php
// ============================================================
// VDE 0100 Prüfprotokolle – Backend-Modul (v0.2.0)
// ============================================================
// Endpoints (via ?action=module&module=vde0100&sub=...):
//   ping
//   protokoll_list, protokoll_get, protokoll_save, protokoll_delete
//   protokoll_fixieren, protokoll_pdf
//   gebaeude_save, gebaeude_delete
//   verteiler_save, verteiler_delete
//   verteiler_ki_scan, verteiler_ki_import
//   rcd_save, rcd_delete
//   sicherung_save, sicherung_delete
// ============================================================

namespace App\Modules\Vde0100;

use App\Core\AbstractModule;
use App\Auth;
use App\Services\FirmenLogo;
use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/../Vde0100Database.php';

class Module extends AbstractModule
{
    public static function migrate(\PDO $db): void
    {
        Vde0100Database::init($db);
    }

    public function dispatch(string $action): void
    {
        $settings = Auth::loadSettings($this->db);
        if (isset($settings['modul_vde0100']) && $settings['modul_vde0100'] === false) {
            \jsonOut(['error' => 'Modul VDE 0100 ist deaktiviert.'], 403);
        }

        switch ($action) {
            case 'ping':
                \jsonOut(['ok' => true, 'module' => 'vde0100', 'version' => $this->manifest['version'] ?? '0.1.0']);
                break;

            // ── Protokolle ────────────────────────────────────
            case 'protokoll_list':    $this->requirePerm('canReadVde0100');   $this->protokollList();    break;
            case 'protokoll_get':     $this->requirePerm('canReadVde0100');   $this->protokollGet();     break;
            case 'protokoll_save':    $this->requirePerm('canWriteVde0100');  $this->protokollSave();    break;
            case 'protokoll_delete':  $this->requirePerm('canWriteVde0100');  $this->protokollDelete();  break;
            case 'protokoll_fixieren':       $this->requirePerm('canFixateVde0100'); $this->protokollFixieren();       break;
            case 'protokoll_kopieren':        $this->requirePerm('canWriteVde0100'); $this->protokollKopieren();        break;
            case 'protokoll_save_pruefdatum': $this->requirePerm('canWriteVde0100'); $this->protokollSavePruefDatum(); break;
            case 'protokoll_pdf':             $this->requirePerm('canReadVde0100');   $this->protokollPdf();             break;

            // ── Gebäude ───────────────────────────────────────
            case 'gebaeude_save':   $this->requirePerm('canWriteVde0100'); $this->gebaeudeeSave();   break;
            case 'gebaeude_delete': $this->requirePerm('canWriteVde0100'); $this->gebaeudeDelete(); break;

            // ── Verteiler ─────────────────────────────────────
            case 'verteiler_save':   $this->requirePerm('canWriteVde0100'); $this->verteilerSave();   break;
            case 'verteiler_delete': $this->requirePerm('canWriteVde0100'); $this->verteilerDelete(); break;
            case 'verteiler_ki_scan':   $this->requirePerm('canWriteVde0100'); $this->verteilerKiScan();   break;
            case 'verteiler_ki_import': $this->requirePerm('canWriteVde0100'); $this->verteilerKiImport(); break;

            // ── RCD ───────────────────────────────────────────────
            case 'rcd_save':   $this->requirePerm('canWriteVde0100'); $this->rcdSave();   break;
            case 'rcd_delete': $this->requirePerm('canWriteVde0100'); $this->rcdDelete(); break;

            // ── Sicherungen ───────────────────────────────────
            case 'sicherung_save':   $this->requirePerm('canWriteVde0100'); $this->sicherungSave();   break;
            case 'sicherung_delete': $this->requirePerm('canWriteVde0100'); $this->sicherungDelete(); break;
            // ── Gruppen-Vorlagen ──────────────────────────────────
            case 'template_list':   $this->requirePerm('canReadVde0100');  $this->templateList();   break;
            case 'template_save':   $this->requirePerm('canWriteVde0100'); $this->templateSave();   break;
            case 'template_delete': $this->requirePerm('canWriteVde0100'); $this->templateDelete(); break;
            case 'template_insert': $this->requirePerm('canWriteVde0100'); $this->templateInsert(); break;
            default:
                \jsonOut(['error' => 'Unbekannte VDE-0100-Aktion: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')], 400);
        }
    }

    // ── Helpers ──────────────────────────────────────────────
    private function requirePerm(string $key): void
    {
        if (!Auth::canDo($this->db, $key)) {
            \jsonOut(['error' => "Keine Berechtigung: $key"], 403);
        }
    }

    private function isFixiert(int $id): bool
    {
        $row = $this->db->prepare("SELECT status FROM vde0100_protokolle WHERE id = ?");
        $row->execute([$id]);
        $r = $row->fetch(\PDO::FETCH_ASSOC);
        return $r && $r['status'] === 'fixiert';
    }

    private function h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ── Protokoll-Aktionen ────────────────────────────────────
    private function protokollList(): void
    {
        $rows = $this->db->query(
            "SELECT p.*, k.firma as k_firma, k.nachname as k_nachname, k.vorname as k_vorname,
                    b.name as b_name
             FROM vde0100_protokolle p
             LEFT JOIN kunden k ON k.id = p.kundeId
             LEFT JOIN baustellen b ON b.id = p.baustelleId
             ORDER BY p.id DESC"
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id']      = (int)$r['id'];
            $r['kundeId'] = $r['kundeId'] !== null ? (int)$r['kundeId'] : null;
            $r['kunde_display'] = trim(($r['k_firma'] ?: '') ?: (($r['k_vorname'] ?? '') . ' ' . ($r['k_nachname'] ?? '')));
            unset($r['k_firma'], $r['k_nachname'], $r['k_vorname'], $r['unterschrift']);
        }
        \jsonOut(['ok' => true, 'protokolle' => $rows]);
    }

    private function protokollGet(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        if ($id <= 0) \jsonOut(['error' => 'Fehlende ID.'], 400);

        $stmt = $this->db->prepare("SELECT * FROM vde0100_protokolle WHERE id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$p) \jsonOut(['error' => 'Protokoll nicht gefunden.'], 404);
        $p['id'] = (int)$p['id'];
        $p['kundeId'] = $p['kundeId'] !== null ? (int)$p['kundeId'] : null;

        // Gebäude
        $gebaeude = $this->db->prepare(
            "SELECT * FROM vde0100_gebaeude WHERE protokollId = ? ORDER BY sortPos, id"
        );
        $gebaeude->execute([$id]);
        $gebRows = $gebaeude->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($gebRows as &$g) {
            $g['id'] = (int)$g['id'];
            $g['protokollId'] = (int)$g['protokollId'];
            $g['sortPos'] = (int)$g['sortPos'];

            // Verteiler
            $vStmt = $this->db->prepare(
                "SELECT * FROM vde0100_verteiler WHERE gebaeudeId = ? ORDER BY sortPos, id"
            );
            $vStmt->execute([$g['id']]);
            $verteiler = $vStmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($verteiler as &$v) {
                $v['id'] = (int)$v['id'];
                $v['gebaeudeId'] = (int)$v['gebaeudeId'];
                $v['rcd_vorhanden'] = (int)$v['rcd_vorhanden'];
                $v['sortPos'] = (int)$v['sortPos'];

                // RCD-Einträge
                $rStmt = $this->db->prepare(
                    "SELECT * FROM vde0100_rcd WHERE verteilerId = ? ORDER BY sortPos, id"
                );
                $rStmt->execute([$v['id']]);
                $rcds = $rStmt->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($rcds as &$r) {
                    $r['id']          = (int)$r['id'];
                    $r['verteilerId'] = (int)$r['verteilerId'];
                    $r['sortPos']     = (int)$r['sortPos'];
                    foreach (['nennstrom','nennfehlerstrom','messung_rcd_id','messung_rcd_tt'] as $f) {
                        $r[$f] = isset($r[$f]) && $r[$f] !== null ? (float)$r[$f] : null;
                    }

                    // Abgänge (Sicherungen) je RCD
                    $sStmt = $this->db->prepare(
                        "SELECT * FROM vde0100_sicherungen WHERE rcdId = ? ORDER BY sortPos, id"
                    );
                    $sStmt->execute([$r['id']]);
                    $sicherungen = $sStmt->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($sicherungen as &$s) {
                        $s['id']         = (int)$s['id'];
                        $s['rcdId']      = (int)$s['rcdId'];
                        $s['verteilerId']= (int)$s['verteilerId'];
                        $s['sortPos']    = (int)$s['sortPos'];
                        foreach (['nennstrom','messung_iso','messung_zs','messung_rb','messung_re','messung_zi'] as $f) {
                            $s[$f] = isset($s[$f]) && $s[$f] !== null ? (float)$s[$f] : null;
                        }
                    }
                    $r['sicherungen'] = $sicherungen;
                }
                $v['rcd'] = $rcds;

                // Abgänge direkt am Verteiler (ohne RCD, rcdId IS NULL)
                $sDirektStmt = $this->db->prepare(
                    "SELECT * FROM vde0100_sicherungen WHERE verteilerId = ? AND rcdId IS NULL ORDER BY sortPos, id"
                );
                $sDirektStmt->execute([$v['id']]);
                $sicherungenDirekt = $sDirektStmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($sicherungenDirekt as &$sd) {
                    $sd['id']          = (int)$sd['id'];
                    $sd['rcdId']       = null;
                    $sd['verteilerId'] = (int)$sd['verteilerId'];
                    $sd['sortPos']     = (int)$sd['sortPos'];
                    foreach (['nennstrom','messung_iso','messung_zs','messung_rb','messung_re','messung_zi'] as $f) {
                        $sd[$f] = isset($sd[$f]) && $sd[$f] !== null ? (float)$sd[$f] : null;
                    }
                }
                unset($sd);
                $v['sicherungenDirekt'] = $sicherungenDirekt;
            }
            $g['verteiler'] = $verteiler;
        }
        $p['gebaeude'] = $gebRows;
        \jsonOut(['ok' => true, 'protokoll' => $p]);
    }

    private function protokollSave(): void
    {
        $b  = $this->body;
        $id = isset($b['id']) ? (int)$b['id'] : 0;

        if ($id > 0 && $this->isFixiert($id)) {
            \jsonOut(['error' => 'Fixiertes Protokoll kann nicht mehr bearbeitet werden.'], 400);
        }

        $titel           = trim((string)($b['titel'] ?? ''));
        $kundeId         = isset($b['kundeId']) && $b['kundeId'] !== '' ? (int)$b['kundeId'] : null;
        $baustelleId     = isset($b['baustelleId']) && $b['baustelleId'] !== '' ? trim((string)$b['baustelleId']) : null;
        $anlagenart      = trim((string)($b['anlagenart'] ?? ''));
        $schutzmassnahme = trim((string)($b['schutzmassnahme'] ?? ''));
        $nennspannung    = trim((string)($b['nennspannung'] ?? '230'));
        $nennfrequenz    = trim((string)($b['nennfrequenz'] ?? '50'));
        $nennstrom       = trim((string)($b['nennstrom'] ?? ''));
        $bemerkung       = trim((string)($b['bemerkung'] ?? ''));
        $norm            = in_array($b['norm'] ?? '', ['vde0100_600', 'vde0105', 'dguv_v3'], true)
                           ? $b['norm'] : 'vde0100_600';
        $projektnummer   = trim((string)($b['projektnummer'] ?? ''));
        $pruefDatum      = trim((string)($b['pruef_datum'] ?? ''));
        // Einfaches Datumsformat sicherstellen (YYYY-MM-DD oder leer)
        if ($pruefDatum && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pruefDatum)) {
            $pruefDatum = '';
        }
        $sichtStatus   = trim((string)($b['sichtpruefung_status'] ?? ''));
        $sichtBem      = trim((string)($b['sichtpruefung_bem'] ?? ''));
        $funkStatus    = trim((string)($b['funktionspruefung_status'] ?? ''));
        $funkBem       = trim((string)($b['funktionspruefung_bem'] ?? ''));
        $messgeraetHerstellerIn  = trim((string)($b['messgeraet_hersteller'] ?? ''));
        $messgeraetTypIn         = trim((string)($b['messgeraet_typ'] ?? ''));
        $messgeraetKalibrierungIn = trim((string)($b['messgeraet_kalibrierung'] ?? ''));
        if ($messgeraetKalibrierungIn && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $messgeraetKalibrierungIn)) {
            $messgeraetKalibrierungIn = '';
        }
        $anlagenanschrift  = trim((string)($b['anlagenanschrift'] ?? ''));
        $kundenanschrift   = trim((string)($b['kundenanschrift'] ?? ''));
        $netzbetreiber     = trim((string)($b['netzbetreiber'] ?? ''));

        if ($titel === '') \jsonOut(['error' => 'Titel ist erforderlich.'], 400);

        $now = date('Y-m-d H:i:s');
        $user = $this->user ?? '';

        if ($id > 0) {
            $stmt = $this->db->prepare(
                "UPDATE vde0100_protokolle
                 SET titel=?, kundeId=?, baustelleId=?, anlagenart=?, schutzmassnahme=?,
                     nennspannung=?, nennfrequenz=?, nennstrom=?, bemerkung=?, norm=?, projektnummer=?, pruef_datum=?,
                     sichtpruefung_status=?, sichtpruefung_bem=?, funktionspruefung_status=?, funktionspruefung_bem=?,
                     messgeraet_hersteller=?, messgeraet_typ=?, messgeraet_kalibrierung=?,
                     anlagenanschrift=?, kundenanschrift=?, netzbetreiber=?
                 WHERE id=?"
            );
            $stmt->execute([$titel, $kundeId, $baustelleId, $anlagenart, $schutzmassnahme,
                            $nennspannung, $nennfrequenz, $nennstrom, $bemerkung, $norm, $projektnummer, $pruefDatum,
                            $sichtStatus, $sichtBem, $funkStatus, $funkBem,
                            $messgeraetHerstellerIn, $messgeraetTypIn, $messgeraetKalibrierungIn,
                            $anlagenanschrift, $kundenanschrift, $netzbetreiber, $id]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO vde0100_protokolle
                 (titel, kundeId, baustelleId, status, anlagenart, schutzmassnahme,
                  nennspannung, nennfrequenz, nennstrom, erstellt_von, erstellt_am, bemerkung, norm, projektnummer, pruef_datum,
                  sichtpruefung_status, sichtpruefung_bem, funktionspruefung_status, funktionspruefung_bem,
                  messgeraet_hersteller, messgeraet_typ, messgeraet_kalibrierung,
                  anlagenanschrift, kundenanschrift, netzbetreiber)
                 VALUES (?,?,?,'entwurf',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([$titel, $kundeId, $baustelleId, $anlagenart, $schutzmassnahme,
                            $nennspannung, $nennfrequenz, $nennstrom, $user, $now, $bemerkung, $norm, $projektnummer, $pruefDatum,
                            $sichtStatus, $sichtBem, $funkStatus, $funkBem,
                            $messgeraetHerstellerIn, $messgeraetTypIn, $messgeraetKalibrierungIn,
                            $anlagenanschrift, $kundenanschrift, $netzbetreiber]);
            $id = (int)$this->db->lastInsertId();
        }
        \jsonOut(['ok' => true, 'id' => $id]);
    }

    private function protokollDelete(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        if ($id <= 0) \jsonOut(['error' => 'Fehlende ID.'], 400);
        if ($this->isFixiert($id)) \jsonOut(['error' => 'Fixiertes Protokoll kann nicht gelöscht werden.'], 400);

        // Cascade: sicherungen -> rcd -> verteiler -> gebaeude -> protokoll
        $this->db->prepare(
            "DELETE FROM vde0100_sicherungen WHERE rcdId IN (
               SELECT r.id FROM vde0100_rcd r
               INNER JOIN vde0100_verteiler v ON v.id = r.verteilerId
               INNER JOIN vde0100_gebaeude g  ON g.id = v.gebaeudeId
               WHERE g.protokollId = ?
             )"
        )->execute([$id]);
        $this->db->prepare(
            "DELETE FROM vde0100_rcd WHERE verteilerId IN (
               SELECT v.id FROM vde0100_verteiler v
               INNER JOIN vde0100_gebaeude g ON g.id = v.gebaeudeId
               WHERE g.protokollId = ?
             )"
        )->execute([$id]);
        $this->db->prepare(
            "DELETE FROM vde0100_verteiler WHERE gebaeudeId IN (
               SELECT id FROM vde0100_gebaeude WHERE protokollId = ?
             )"
        )->execute([$id]);
        $this->db->prepare("DELETE FROM vde0100_gebaeude WHERE protokollId = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM vde0100_protokolle WHERE id = ?")->execute([$id]);
        \jsonOut(['ok' => true]);
    }

    private function protokollKopieren(): void
    {
        $srcId = (int)($this->body['id'] ?? 0);
        if ($srcId <= 0) \jsonOut(['error' => 'Fehlende ID.'], 400);

        // Quell-Protokoll laden
        $stmt = $this->db->prepare("SELECT * FROM vde0100_protokolle WHERE id = ?");
        $stmt->execute([$srcId]);
        $src = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$src) \jsonOut(['error' => 'Protokoll nicht gefunden.'], 404);

        $now  = date('Y-m-d H:i:s');
        $user = $this->user ?? '';
        $neuTitel = 'Kopie von ' . $src['titel'];

        // Neues Protokoll als Entwurf anlegen (ohne Fixier-Daten und Unterschrift)
        $ins = $this->db->prepare(
            "INSERT INTO vde0100_protokolle
             (titel, kundeId, baustelleId, status, anlagenart, schutzmassnahme,
              nennspannung, nennfrequenz, nennstrom, erstellt_von, erstellt_am,
              bemerkung, norm, projektnummer, pruef_datum,
              sichtpruefung_status, sichtpruefung_bem,
              funktionspruefung_status, funktionspruefung_bem,
              anlagenanschrift, kundenanschrift, netzbetreiber)
             VALUES (?,?,?,'entwurf',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $ins->execute([
            $neuTitel, $src['kundeId'], $src['baustelleId'],
            $src['anlagenart'], $src['schutzmassnahme'],
            $src['nennspannung'], $src['nennfrequenz'], $src['nennstrom'],
            $user, $now,
            $src['bemerkung'], $src['norm'], $src['projektnummer'], '',
            $src['sichtpruefung_status'], $src['sichtpruefung_bem'],
            $src['funktionspruefung_status'], $src['funktionspruefung_bem'],
            $src['anlagenanschrift'] ?? '', $src['kundenanschrift'] ?? '', $src['netzbetreiber'] ?? '',
        ]);
        $newProtokollId = (int)$this->db->lastInsertId();

        // Gebäude kopieren
        $gebRows = $this->db->prepare(
            "SELECT * FROM vde0100_gebaeude WHERE protokollId = ? ORDER BY sortPos, id"
        );
        $gebRows->execute([$srcId]);
        foreach ($gebRows->fetchAll(\PDO::FETCH_ASSOC) as $g) {
            $this->db->prepare(
                "INSERT INTO vde0100_gebaeude (protokollId, bezeichnung, sortPos) VALUES (?,?,?)"
            )->execute([$newProtokollId, $g['bezeichnung'], $g['sortPos']]);
            $newGebId = (int)$this->db->lastInsertId();

            // Verteiler kopieren
            $vtRows = $this->db->prepare(
                "SELECT * FROM vde0100_verteiler WHERE gebaeudeId = ? ORDER BY sortPos, id"
            );
            $vtRows->execute([$g['id']]);
            foreach ($vtRows->fetchAll(\PDO::FETCH_ASSOC) as $v) {
                $this->db->prepare(
                    "INSERT INTO vde0100_verteiler
                     (gebaeudeId, bezeichnung, nennstrom, rcd_vorhanden, rcd_nennstrom,
                      rcd_nennfehler, rcd_typ, sortPos, bemerkung)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $newGebId, $v['bezeichnung'], $v['nennstrom'], $v['rcd_vorhanden'],
                    $v['rcd_nennstrom'], $v['rcd_nennfehler'], $v['rcd_typ'],
                    $v['sortPos'], $v['bemerkung'],
                ]);
                $newVtId = (int)$this->db->lastInsertId();

                // RCDs kopieren
                $rcdRows = $this->db->prepare(
                    "SELECT * FROM vde0100_rcd WHERE verteilerId = ? ORDER BY sortPos, id"
                );
                $rcdRows->execute([$v['id']]);
                foreach ($rcdRows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $this->db->prepare(
                        "INSERT INTO vde0100_rcd
                         (verteilerId, bezeichnung, nennstrom, nennfehlerstrom, typ, sortPos, bemerkung,
                          messung_rcd_id, messung_rcd_tt)
                         VALUES (?,?,?,?,?,?,?,?,?)"
                    )->execute([
                        $newVtId, $r['bezeichnung'], $r['nennstrom'], $r['nennfehlerstrom'],
                        $r['typ'], $r['sortPos'], $r['bemerkung'],
                        $r['messung_rcd_id'], $r['messung_rcd_tt'],
                    ]);
                    $newRcdId = (int)$this->db->lastInsertId();

                    // Sicherungen kopieren
                    $sRows = $this->db->prepare(
                        "SELECT * FROM vde0100_sicherungen WHERE rcdId = ? ORDER BY sortPos, id"
                    );
                    $sRows->execute([$r['id']]);
                    foreach ($sRows->fetchAll(\PDO::FETCH_ASSOC) as $s) {
                        $this->db->prepare(
                            "INSERT INTO vde0100_sicherungen
                             (rcdId, verteilerId, bezeichnung, typ, nennstrom, leiterquerschnitt,
                              messung_iso, messung_zs, messung_rcd_id, messung_rcd_tt,
                              messung_rb, messung_re, messung_zi, messung_polaritaet,
                              pruefstatus, sortPos, bemerkung)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                        )->execute([
                            $newRcdId, $newVtId,
                            $s['bezeichnung'], $s['typ'], $s['nennstrom'], $s['leiterquerschnitt'],
                            $s['messung_iso'], $s['messung_zs'], $s['messung_rcd_id'], $s['messung_rcd_tt'],
                            $s['messung_rb'], $s['messung_re'], $s['messung_zi'], $s['messung_polaritaet'],
                            $s['pruefstatus'], $s['sortPos'], $s['bemerkung'],
                        ]);
                    }
                }
            }
        }

        \jsonOut(['ok' => true, 'newId' => $newProtokollId]);
    }

    private function protokollSavePruefDatum(): void
    {
        $id         = (int)($this->body['id'] ?? 0);
        $pruefDatum = trim((string)($this->body['pruef_datum'] ?? ''));
        if ($id <= 0) \jsonOut(['error' => 'Fehlende ID.'], 400);
        if ($pruefDatum !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pruefDatum)) {
            \jsonOut(['error' => 'Ungültiges Datumsformat.'], 400);
        }
        $stmt = $this->db->prepare("UPDATE vde0100_protokolle SET pruef_datum=? WHERE id=?");
        $stmt->execute([$pruefDatum, $id]);
        \jsonOut(['ok' => true]);
    }

    private function protokollFixieren(): void
    {
        $id           = (int)($this->body['id'] ?? 0);
        $unterschrift = trim((string)($this->body['unterschrift'] ?? ''));
        if ($id <= 0) \jsonOut(['error' => 'Fehlende ID.'], 400);
        if ($this->isFixiert($id)) \jsonOut(['error' => 'Protokoll ist bereits fixiert.'], 400);

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "UPDATE vde0100_protokolle SET status='fixiert', fixiert_am=?, unterschrift=? WHERE id=?"
        );
        $stmt->execute([$now, $unterschrift, $id]);

        // PDF automatisch im Protokolle-Ordner der Baustelle speichern
        $savedFile = null;
        if (defined('UPLOADS_DIR')) {
            try {
                $pdf         = $this->renderPdf($id);
                $baustelleId = $pdf['baustelleId'];
                if ($baustelleId > 0) {
                    $bRow   = $this->db->prepare("SELECT name FROM baustellen WHERE id = ?");
                    $bRow->execute([$baustelleId]);
                    $b      = $bRow->fetch();
                    $bName  = $b ? $b['name'] : 'Baustelle_' . $baustelleId;
                    $folder = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\- ]/u', '_', $bName);
                    $dir    = UPLOADS_DIR . $folder . '/Protokolle/';
                    if (!is_dir($dir)) mkdir($dir, 0750, true);
                    $safeName = date('Y-m-d_His') . '_' . $pdf['fileName'];
                    file_put_contents($dir . $safeName, $pdf['pdfData']);
                    $savedFile = $safeName;
                }
            } catch (\Throwable $e) {
                error_log('[VDE0100] PDF-Speicherung beim Fixieren fehlgeschlagen: ' . $e->getMessage());
            }
        }

        \jsonOut(['ok' => true, 'savedFile' => $savedFile]);
    }

    // ── Gebäude-Aktionen ─────────────────────────────────────
    private function gebaeudeeSave(): void
    {
        $b           = $this->body;
        $id          = isset($b['id']) ? (int)$b['id'] : 0;
        $protokollId = (int)($b['protokollId'] ?? 0);
        $bezeichnung = trim((string)($b['bezeichnung'] ?? ''));
        $sortPos     = (int)($b['sortPos'] ?? 0);

        if ($protokollId <= 0) \jsonOut(['error' => 'protokollId fehlt.'], 400);
        if ($bezeichnung === '') \jsonOut(['error' => 'Bezeichnung ist erforderlich.'], 400);
        if ($this->isFixiert($protokollId)) \jsonOut(['error' => 'Protokoll ist fixiert.'], 400);

        if ($id > 0) {
            $stmt = $this->db->prepare("UPDATE vde0100_gebaeude SET bezeichnung=?, sortPos=? WHERE id=? AND protokollId=?");
            $stmt->execute([$bezeichnung, $sortPos, $id, $protokollId]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO vde0100_gebaeude (protokollId, bezeichnung, sortPos) VALUES (?,?,?)");
            $stmt->execute([$protokollId, $bezeichnung, $sortPos]);
            $id = (int)$this->db->lastInsertId();
        }
        \jsonOut(['ok' => true, 'id' => $id]);
    }

    private function gebaeudeDelete(): void
    {
        $id          = (int)($this->body['id'] ?? 0);
        $protokollId = (int)($this->body['protokollId'] ?? 0);
        if ($id <= 0) \jsonOut(['error' => 'Fehlende ID.'], 400);
        if ($protokollId > 0 && $this->isFixiert($protokollId)) \jsonOut(['error' => 'Protokoll ist fixiert.'], 400);

        $this->db->prepare(
            "DELETE FROM vde0100_sicherungen WHERE rcdId IN (
               SELECT r.id FROM vde0100_rcd r
               INNER JOIN vde0100_verteiler v ON v.id = r.verteilerId
               WHERE v.gebaeudeId = ?
             )"
        )->execute([$id]);
        $this->db->prepare(
            "DELETE FROM vde0100_rcd WHERE verteilerId IN (
               SELECT id FROM vde0100_verteiler WHERE gebaeudeId = ?
             )"
        )->execute([$id]);
        $this->db->prepare("DELETE FROM vde0100_verteiler WHERE gebaeudeId = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM vde0100_gebaeude WHERE id = ?")->execute([$id]);
        \jsonOut(['ok' => true]);
    }

    // ── Verteiler-Aktionen ───────────────────────────────────
    private function verteilerSave(): void
    {
        $b             = $this->body;
        $id            = isset($b['id']) ? (int)$b['id'] : 0;
        $gebaeudeId    = (int)($b['gebaeudeId'] ?? 0);
        $bezeichnung   = trim((string)($b['bezeichnung'] ?? ''));
        $nennstrom     = isset($b['nennstrom']) && $b['nennstrom'] !== '' ? (float)$b['nennstrom'] : null;
        $rcd_vorhanden = !empty($b['rcd_vorhanden']) ? 1 : 0;
        $rcd_nennstrom = isset($b['rcd_nennstrom']) && $b['rcd_nennstrom'] !== '' ? (float)$b['rcd_nennstrom'] : null;
        $rcd_nennfehler= isset($b['rcd_nennfehler']) && $b['rcd_nennfehler'] !== '' ? (float)$b['rcd_nennfehler'] : null;
        $rcd_typ       = trim((string)($b['rcd_typ'] ?? ''));
        $sortPos       = (int)($b['sortPos'] ?? 0);
        $bemerkung     = trim((string)($b['bemerkung'] ?? ''));

        if ($gebaeudeId <= 0) \jsonOut(['error' => 'gebaeudeId fehlt.'], 400);
        if ($bezeichnung === '') \jsonOut(['error' => 'Bezeichnung ist erforderlich.'], 400);

        // Fixiert-Check via Gebäude → Protokoll
        $gRow = $this->db->prepare("SELECT protokollId FROM vde0100_gebaeude WHERE id=?");
        $gRow->execute([$gebaeudeId]);
        $g = $gRow->fetch(\PDO::FETCH_ASSOC);
        if ($g && $this->isFixiert((int)$g['protokollId'])) \jsonOut(['error' => 'Protokoll ist fixiert.'], 400);

        if ($id > 0) {
            $stmt = $this->db->prepare(
                "UPDATE vde0100_verteiler
                 SET bezeichnung=?, nennstrom=?, rcd_vorhanden=?, rcd_nennstrom=?,
                     rcd_nennfehler=?, rcd_typ=?, sortPos=?, bemerkung=?
                 WHERE id=? AND gebaeudeId=?"
            );
            $stmt->execute([$bezeichnung, $nennstrom, $rcd_vorhanden, $rcd_nennstrom,
                            $rcd_nennfehler, $rcd_typ, $sortPos, $bemerkung, $id, $gebaeudeId]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO vde0100_verteiler
                 (gebaeudeId, bezeichnung, nennstrom, rcd_vorhanden, rcd_nennstrom,
                  rcd_nennfehler, rcd_typ, sortPos, bemerkung)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([$gebaeudeId, $bezeichnung, $nennstrom, $rcd_vorhanden, $rcd_nennstrom,
                            $rcd_nennfehler, $rcd_typ, $sortPos, $bemerkung]);
            $id = (int)$this->db->lastInsertId();
        }
        \jsonOut(['ok' => true, 'id' => $id]);
    }

    private function verteilerDelete(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        if ($id <= 0) \jsonOut(['error' => 'Fehlende ID.'], 400);
        $this->db->prepare(
            "DELETE FROM vde0100_sicherungen WHERE rcdId IN (SELECT id FROM vde0100_rcd WHERE verteilerId = ?)"
        )->execute([$id]);
        $this->db->prepare("DELETE FROM vde0100_rcd WHERE verteilerId = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM vde0100_verteiler WHERE id = ?")->execute([$id]);
        \jsonOut(['ok' => true]);
    }

    // ── Verteiler KI-Erkennung (Foto → Verteilerbaum) ────────
    private function verteilerKiScan(): void
    {
        $settings = Auth::loadSettings($this->db);
        $apiKey   = trim((string)($settings['gemini_api_key'] ?? ''));
        $model    = trim((string)($settings['gemini_model']   ?? 'gemini-2.5-flash-lite'));
        if ($apiKey === '') {
            \jsonOut(['error' => 'Kein Gemini API-Key konfiguriert. Bitte unter Einstellungen → KI hinterlegen.'], 400);
        }

        $mimeType = trim((string)($this->body['mimeType'] ?? ''));
        $data     = (string)($this->body['data'] ?? '');
        $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mimeType, $allowed, true)) {
            \jsonOut(['error' => 'Nur Fotos erlaubt (JPG, PNG, WEBP, GIF).'], 400);
        }
        if ($data === '') \jsonOut(['error' => 'Keine Bilddaten übermittelt.'], 400);
        if (strlen($data) > 14_000_000) \jsonOut(['error' => 'Bild zu groß (max. 10 MB).'], 400);
        // Nur Base64-Zeichen erlaubt (OWASP Input Validation)
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $data)) {
            \jsonOut(['error' => 'Ungültige Bilddaten (kein gültiges Base64).'], 400);
        }

        $prompt = 'Analysiere dieses Foto eines elektrischen Verteilers / Unterverteilers / Baustromverteilers'
            . ' und extrahiere seinen Aufbau. Antworte ausschließlich mit einem JSON-Objekt (kein Markdown,'
            . ' kein zusätzlicher Text) mit folgender Struktur:'
            . ' { "bezeichnung": String, "nennstrom": Zahl|null, "rcd_gruppen": [ { "bezeichnung": String,'
            . ' "nennstrom": Zahl|null, "nennfehlerstrom": Zahl|null, "typ": String, "sicherungen": [ { "bezeichnung": String,'
            . ' "typ": String, "nennstrom": Zahl|null, "leiterquerschnitt": String } ] } ] }.'
            . ' "bezeichnung" des Verteilers: Typenschild-Angabe oder eine sinnvolle Kurzbeschreibung (z.B. "Baustromverteiler"),'
            . ' leerer String wenn nichts erkennbar.'
            . ' "nennstrom" des Verteilers ist die Haupteinspeisung/-sicherung in Ampere, null wenn nicht erkennbar.'
            . ' "rcd_gruppen": eine Gruppe je sichtbarem FI/RCD-Schutzschalter im Verteiler.'
            . ' "bezeichnung" der Gruppe z.B. "FI 40A/30mA Typ A". "nennstrom" (A) und "nennfehlerstrom" (mA) des FI,'
            . ' "typ" ist der FI-Typ (z.B. "A", "B", "AC", "F"), leerer String wenn nicht erkennbar.'
            . ' Abgänge OHNE erkennbaren vorgeschalteten FI fasse in einer zusätzlichen Gruppe'
            . ' "bezeichnung":"Abgänge ohne RCD" zusammen (nennstrom/nennfehlerstrom dann null, typ leer).'
            . ' "sicherungen" sind die einzelnen Leitungsschutzschalter unterhalb eines FI:'
            . ' "bezeichnung" (Beschriftung/Verwendungszweck, sonst z.B. "B16"), "typ" (z.B. "LS", "NH", "Sonstige"),'
            . ' "nennstrom" in Ampere (z.B. 16 für B16), "leiterquerschnitt" in mm² als String, leerer String wenn nicht erkennbar.'
            . ' Wenn kein Verteiler erkennbar ist, gib { "bezeichnung": "", "nennstrom": null, "rcd_gruppen": [] } zurück.';

        $payload = [
            'contents' => [[
                'parts' => [
                    ['inlineData' => ['mimeType' => $mimeType, 'data' => $data]],
                    ['text'       => $prompt],
                ],
            ]],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature'        => 0.1,
                'maxOutputTokens'    => 8192,
            ],
        ];

        $url         = 'https://generativelanguage.googleapis.com/v1beta/models/'
                       . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $responseBody = $this->callGemini($url, $jsonPayload);
        if ($responseBody === false) {
            \jsonOut(['error' => 'Gemini API nicht erreichbar. Bitte Internetverbindung prüfen.'], 502);
        }

        $resp = json_decode($responseBody, true);
        if (!is_array($resp)) \jsonOut(['error' => 'Ungültige Antwort der KI-API.'], 502);
        if (isset($resp['error'])) {
            $msg = strip_tags((string)($resp['error']['message'] ?? 'Unbekannter API-Fehler'));
            \jsonOut(['error' => 'Gemini-Fehler: ' . $msg], 502);
        }

        // Gemini 2.5+ Thinking-Modelle: ersten Non-Thought-Part verwenden
        $text = '';
        foreach ($resp['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['text']) && !($part['thought'] ?? false)) { $text = $part['text']; break; }
        }

        $verteiler = $this->parseVerteilerKiResponse($text);
        if ($verteiler === null) {
            \jsonOut(['error' => 'Antwort der KI konnte nicht ausgewertet werden.'], 502);
        }
        if (empty($verteiler['rcd_gruppen']) && $verteiler['bezeichnung'] === '') {
            \jsonOut([
                'ok'        => true,
                'verteiler' => $verteiler,
                'hint'      => 'Kein Verteiler auf dem Foto erkannt. Bitte prüfen oder manuell ergänzen.',
            ]);
        }

        \jsonOut(['ok' => true, 'verteiler' => $verteiler]);
    }

    /** Parst die Gemini-Antwort in ein normalisiertes Verteiler-Array (oder null bei Parse-Fehler). */
    private function parseVerteilerKiResponse(string $text): ?array
    {
        $text = trim($text);
        // Markdown-Fences entfernen, falls KI sie trotzdem liefert
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $decoded = json_decode(trim($text), true);
        if (!is_array($decoded)) return null;

        $gruppen = [];
        foreach ((array)($decoded['rcd_gruppen'] ?? []) as $rg) {
            if (!is_array($rg)) continue;
            $sicherungen = [];
            foreach ((array)($rg['sicherungen'] ?? []) as $s) {
                if (!is_array($s)) continue;
                $sicherungen[] = [
                    'bezeichnung'       => (string)($s['bezeichnung'] ?? ''),
                    'typ'               => (string)($s['typ'] ?? ''),
                    'nennstrom'         => isset($s['nennstrom']) && $s['nennstrom'] !== '' ? (float)$s['nennstrom'] : null,
                    'leiterquerschnitt' => (string)($s['leiterquerschnitt'] ?? ''),
                ];
            }
            $gruppen[] = [
                'bezeichnung'     => (string)($rg['bezeichnung'] ?? 'RCD'),
                'nennstrom'       => isset($rg['nennstrom']) && $rg['nennstrom'] !== '' ? (float)$rg['nennstrom'] : null,
                'nennfehlerstrom' => isset($rg['nennfehlerstrom']) && $rg['nennfehlerstrom'] !== '' ? (float)$rg['nennfehlerstrom'] : null,
                'typ'             => (string)($rg['typ'] ?? ''),
                'sicherungen'     => $sicherungen,
            ];
        }

        return [
            'bezeichnung' => (string)($decoded['bezeichnung'] ?? ''),
            'nennstrom'   => isset($decoded['nennstrom']) && $decoded['nennstrom'] !== '' ? (float)$decoded['nennstrom'] : null,
            'rcd_gruppen' => $gruppen,
        ];
    }

    /** HTTP-Call an Gemini (curl mit file_get_contents-Fallback). */
    private function callGemini(string $url, string $payload): string|false
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            return ($body !== false) ? (string)$body : false;
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 45,
            'ignore_errors' => true,
        ]]);
        $r = @file_get_contents($url, false, $ctx);
        return ($r !== false) ? (string)$r : false;
    }

    private function verteilerKiImport(): void
    {
        $gebaeudeId = (int)($this->body['gebaeudeId'] ?? 0);
        $daten      = (array)($this->body['daten'] ?? []);
        if ($gebaeudeId <= 0) \jsonOut(['error' => 'gebaeudeId fehlt.'], 400);
        if (trim((string)($daten['bezeichnung'] ?? '')) === '') {
            \jsonOut(['error' => 'Bezeichnung ist erforderlich.'], 400);
        }

        $gRow = $this->db->prepare("SELECT protokollId FROM vde0100_gebaeude WHERE id=?");
        $gRow->execute([$gebaeudeId]);
        $g = $gRow->fetch(\PDO::FETCH_ASSOC);
        if (!$g) \jsonOut(['error' => 'Gebäude nicht gefunden.'], 404);
        if ($this->isFixiert((int)$g['protokollId'])) \jsonOut(['error' => 'Protokoll ist fixiert.'], 400);

        $verteilerId = $this->insertVerteilerTree($gebaeudeId, $daten);
        \jsonOut(['ok' => true, 'protokollId' => (int)$g['protokollId'], 'verteilerId' => $verteilerId]);
    }

    // ── RCD-Aktionen ─────────────────────────────────────────
    private function rcdSave(): void
    {
        $b               = $this->body;
        $id              = isset($b['id']) ? (int)$b['id'] : 0;
        $verteilerId     = (int)($b['verteilerId'] ?? 0);
        $bezeichnung     = trim((string)($b['bezeichnung'] ?? 'RCD'));
        $nennstrom       = isset($b['nennstrom'])       && $b['nennstrom']       !== '' ? (float)$b['nennstrom']       : null;
        $nennfehlerstrom = isset($b['nennfehlerstrom']) && $b['nennfehlerstrom'] !== '' ? (float)$b['nennfehlerstrom'] : null;
        $typ             = trim((string)($b['typ'] ?? ''));
        $sortPos         = (int)($b['sortPos'] ?? 0);
        $bemerkung       = trim((string)($b['bemerkung'] ?? ''));

        if ($verteilerId <= 0) \jsonOut(['error' => 'verteilerId fehlt.'], 400);
        if ($bezeichnung === '') $bezeichnung = 'RCD';

        // Fixiert-Check via Verteiler → Gebäude → Protokoll
        $vRow = $this->db->prepare("SELECT gebaeudeId FROM vde0100_verteiler WHERE id=?");
        $vRow->execute([$verteilerId]);
        $vData = $vRow->fetch(\PDO::FETCH_ASSOC);
        if ($vData) {
            $gRow = $this->db->prepare("SELECT protokollId FROM vde0100_gebaeude WHERE id=?");
            $gRow->execute([$vData['gebaeudeId']]);
            $gData = $gRow->fetch(\PDO::FETCH_ASSOC);
            if ($gData && $this->isFixiert((int)$gData['protokollId'])) {
                \jsonOut(['error' => 'Protokoll ist fixiert.'], 400);
            }
        }

        $messRcdId = isset($b['messung_rcd_id']) && $b['messung_rcd_id'] !== '' ? (float)$b['messung_rcd_id'] : null;
        $messRcdTt = isset($b['messung_rcd_tt']) && $b['messung_rcd_tt'] !== '' ? (float)$b['messung_rcd_tt'] : null;

        if ($id > 0) {
            $stmt = $this->db->prepare(
                "UPDATE vde0100_rcd
                 SET bezeichnung=?, nennstrom=?, nennfehlerstrom=?, typ=?, sortPos=?, bemerkung=?,
                     messung_rcd_id=?, messung_rcd_tt=?
                 WHERE id=? AND verteilerId=?"
            );
            $stmt->execute([$bezeichnung, $nennstrom, $nennfehlerstrom, $typ, $sortPos, $bemerkung,
                            $messRcdId, $messRcdTt, $id, $verteilerId]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO vde0100_rcd
                 (verteilerId, bezeichnung, nennstrom, nennfehlerstrom, typ, sortPos, bemerkung,
                  messung_rcd_id, messung_rcd_tt)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([$verteilerId, $bezeichnung, $nennstrom, $nennfehlerstrom, $typ, $sortPos, $bemerkung,
                            $messRcdId, $messRcdTt]);
            $id = (int)$this->db->lastInsertId();
        }
        \jsonOut(['ok' => true, 'id' => $id]);
    }

    private function rcdDelete(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        if ($id <= 0) \jsonOut(['error' => 'Fehlende ID.'], 400);
        $this->db->prepare("DELETE FROM vde0100_sicherungen WHERE rcdId = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM vde0100_rcd WHERE id = ?")->execute([$id]);
        \jsonOut(['ok' => true]);
    }

    // ── Sicherungs-Aktionen ──────────────────────────────────
    private function sicherungSave(): void
    {
        $b           = $this->body;
        $id          = isset($b['id']) ? (int)$b['id'] : 0;
        // rcdId kann null sein (Abgang direkt am Verteiler, ohne RCD)
        $rcdIdRaw    = (isset($b['rcdId']) && $b['rcdId'] !== '' && $b['rcdId'] !== null) ? (int)$b['rcdId'] : null;
        $bezeichnung = trim((string)($b['bezeichnung'] ?? ''));
        $typ         = trim((string)($b['typ'] ?? ''));
        $nennstrom   = isset($b['nennstrom']) && $b['nennstrom'] !== '' ? (float)$b['nennstrom'] : null;
        $leiter      = trim((string)($b['leiterquerschnitt'] ?? ''));
        $sortPos     = (int)($b['sortPos'] ?? 0);
        $pruefstatus = trim((string)($b['pruefstatus'] ?? ''));
        $bemerkung   = trim((string)($b['bemerkung'] ?? ''));

        // Messwerte (null wenn leer)
        $floatOrNull = function ($key) use ($b): ?float {
            return isset($b[$key]) && $b[$key] !== '' ? (float)$b[$key] : null;
        };
        $iso        = $floatOrNull('messung_iso');
        $zs         = $floatOrNull('messung_zs');
        // messung_rcd_id / messung_rcd_tt werden zur Backward-Compat noch gelesen,
        // aber primär auf RCD-Ebene gespeichert (v2.8.0)
        $messRcdId  = $floatOrNull('messung_rcd_id');
        $rcdTt      = $floatOrNull('messung_rcd_tt');
        $rb         = $floatOrNull('messung_rb');
        $re         = $floatOrNull('messung_re');
        $zi         = $floatOrNull('messung_zi');
        $polaritaet = trim((string)($b['messung_polaritaet'] ?? ''));

        if ($bezeichnung === '') \jsonOut(['error' => 'Bezeichnung ist erforderlich.'], 400);

        // verteilerId: entweder aus RCD-Tabelle ermitteln oder direkt aus Body (wenn kein RCD)
        if ($rcdIdRaw !== null) {
            $rRow = $this->db->prepare("SELECT verteilerId FROM vde0100_rcd WHERE id=?");
            $rRow->execute([$rcdIdRaw]);
            $rData = $rRow->fetch(\PDO::FETCH_ASSOC);
            $verteilerId = $rData ? (int)$rData['verteilerId'] : 0;
        } else {
            $verteilerId = isset($b['verteilerId']) && $b['verteilerId'] !== '' ? (int)$b['verteilerId'] : 0;
            if ($verteilerId <= 0) \jsonOut(['error' => 'verteilerId fehlt.'], 400);
        }

        if ($id > 0) {
            $stmt = $this->db->prepare(
                "UPDATE vde0100_sicherungen
                 SET bezeichnung=?, typ=?, nennstrom=?, leiterquerschnitt=?,
                     messung_iso=?, messung_zs=?, messung_rcd_id=?, messung_rcd_tt=?,
                     messung_rb=?, messung_re=?, messung_zi=?, messung_polaritaet=?,
                     pruefstatus=?, sortPos=?, bemerkung=?
                 WHERE id=?"
            );
            $stmt->execute([$bezeichnung, $typ, $nennstrom, $leiter,
                            $iso, $zs, $messRcdId, $rcdTt, $rb, $re, $zi, $polaritaet,
                            $pruefstatus, $sortPos, $bemerkung, $id]);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO vde0100_sicherungen
                 (rcdId, verteilerId, bezeichnung, typ, nennstrom, leiterquerschnitt,
                  messung_iso, messung_zs, messung_rcd_id, messung_rcd_tt,
                  messung_rb, messung_re, messung_zi, messung_polaritaet,
                  pruefstatus, sortPos, bemerkung)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([$rcdIdRaw, $verteilerId, $bezeichnung, $typ, $nennstrom, $leiter,
                            $iso, $zs, $messRcdId, $rcdTt, $rb, $re, $zi, $polaritaet,
                            $pruefstatus, $sortPos, $bemerkung]);
            $id = (int)$this->db->lastInsertId();
        }
        \jsonOut(['ok' => true, 'id' => $id]);
    }

    private function sicherungDelete(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        if ($id <= 0) \jsonOut(['error' => 'Fehlende ID.'], 400);
        $this->db->prepare("DELETE FROM vde0100_sicherungen WHERE id = ?")->execute([$id]);
        \jsonOut(['ok' => true]);
    }

    // ── Gruppen-Vorlagen ──────────────────────────────────────
    private function getPredefinedTemplates(): array
    {
        $mkRcd = fn(string $bez, ?float $ns, ?float $nf, string $typ, array $sicherungen): array => [
            'bezeichnung' => $bez, 'nennstrom' => $ns, 'nennfehlerstrom' => $nf,
            'typ' => $typ, 'sicherungen' => $sicherungen,
        ];
        $mkS = fn(string $bez, string $typ, float $ns, string $mm2): array => [
            'bezeichnung' => $bez, 'typ' => $typ, 'nennstrom' => $ns, 'leiterquerschnitt' => $mm2,
        ];
        return [
            [
                'id' => 'pre_wohnbereich_std', 'name' => 'Wohnbereich Standard',
                'beschreibung' => 'FI 40A/30mA Typ A + 5× B16 + B10 Beleuchtung',
                'readonly' => true,
                'daten' => [
                    'bezeichnung' => 'UV Wohnbereich', 'nennstrom' => 40,
                    'rcd_gruppen' => [
                        $mkRcd('FI 40A / 30mA Typ A', 40, 30, 'A', [
                            $mkS('Steckdosen Wohnzimmer',   'LS', 16, '1,5'),
                            $mkS('Steckdosen Schlafzimmer', 'LS', 16, '1,5'),
                            $mkS('Steckdosen Kinderzimmer', 'LS', 16, '1,5'),
                            $mkS('Steckdosen Allgemein',    'LS', 16, '1,5'),
                            $mkS('Steckdosen Korridor',     'LS', 16, '1,5'),
                            $mkS('Beleuchtung EG',          'LS', 10, '1,5'),
                        ]),
                    ],
                ],
            ],
            [
                'id' => 'pre_kueche', 'name' => 'Küche',
                'beschreibung' => 'FI 40A/30mA Typ A + B32 Herd + B16×3 + B10 Beleuchtung',
                'readonly' => true,
                'daten' => [
                    'bezeichnung' => 'UV Küche', 'nennstrom' => 40,
                    'rcd_gruppen' => [
                        $mkRcd('FI 40A / 30mA Typ A', 40, 30, 'A', [
                            $mkS('Herd / Cerankochfeld',       'LS', 32, '2,5'),
                            $mkS('Kühlschrank / Gefrierkombi', 'LS', 16, '1,5'),
                            $mkS('Geschirrspüler',             'LS', 16, '1,5'),
                            $mkS('Steckdosen Arbeitsfläche',   'LS', 16, '1,5'),
                            $mkS('Beleuchtung Küche',          'LS', 10, '1,5'),
                        ]),
                    ],
                ],
            ],
            [
                'id' => 'pre_keller_technik', 'name' => 'Keller / Technikraum',
                'beschreibung' => 'FI 40A/100mA Typ B + B25 Heizung + B16×2 + B10',
                'readonly' => true,
                'daten' => [
                    'bezeichnung' => 'UV Keller', 'nennstrom' => 40,
                    'rcd_gruppen' => [
                        $mkRcd('FI 40A / 100mA Typ B', 40, 100, 'B', [
                            $mkS('Heizung / Wärmepumpe', 'LS', 25, '2,5'),
                            $mkS('Waschmaschine',        'LS', 16, '1,5'),
                            $mkS('Trockner',             'LS', 16, '1,5'),
                            $mkS('Steckdosen Keller',    'LS', 16, '1,5'),
                            $mkS('Beleuchtung Keller',   'LS', 10, '1,5'),
                        ]),
                    ],
                ],
            ],
            [
                'id' => 'pre_aussen', 'name' => 'Außenanlage / Garage',
                'beschreibung' => 'FI 25A/30mA Typ F + B16×3 Außensteckdosen + B10',
                'readonly' => true,
                'daten' => [
                    'bezeichnung' => 'UV Außen', 'nennstrom' => 25,
                    'rcd_gruppen' => [
                        $mkRcd('FI 25A / 30mA Typ F', 25, 30, 'F', [
                            $mkS('Außensteckdosen',   'LS', 16, '1,5'),
                            $mkS('Gartensteckdosen',  'LS', 16, '1,5'),
                            $mkS('Garage Steckdosen', 'LS', 16, '1,5'),
                            $mkS('Außenbeleuchtung',  'LS', 10, '1,5'),
                        ]),
                    ],
                ],
            ],
            [
                'id' => 'pre_buero', 'name' => 'Büro / Gewerbe',
                'beschreibung' => 'FI 63A/30mA Typ A + B16×6 EDV/Steckdosen + B10',
                'readonly' => true,
                'daten' => [
                    'bezeichnung' => 'UV Büro', 'nennstrom' => 63,
                    'rcd_gruppen' => [
                        $mkRcd('FI 63A / 30mA Typ A', 63, 30, 'A', [
                            $mkS('EDV / Server',          'LS', 16, '1,5'),
                            $mkS('Steckdosen Büro 1',     'LS', 16, '1,5'),
                            $mkS('Steckdosen Büro 2',     'LS', 16, '1,5'),
                            $mkS('Drucker / Kopierer',    'LS', 16, '1,5'),
                            $mkS('Klimaanlage',           'LS', 16, '2,5'),
                            $mkS('Steckdosen Allgemein',  'LS', 16, '1,5'),
                            $mkS('Beleuchtung Büro',      'LS', 10, '1,5'),
                        ]),
                    ],
                ],
            ],
            [
                'id' => 'pre_bad', 'name' => 'Bad / Nassbereich',
                'beschreibung' => 'FI 25A/30mA Typ A (Badschutz) + B16×2 + B10',
                'readonly' => true,
                'daten' => [
                    'bezeichnung' => 'UV Bad', 'nennstrom' => 25,
                    'rcd_gruppen' => [
                        $mkRcd('FI 25A / 30mA Typ A', 25, 30, 'A', [
                            $mkS('Steckdosen Bad',             'LS', 16, '1,5'),
                            $mkS('Handtuchheizung / Boiler',   'LS', 16, '1,5'),
                            $mkS('Beleuchtung Bad',            'LS', 10, '1,5'),
                        ]),
                    ],
                ],
            ],
        ];
    }

    private function templateList(): void
    {
        $predefined = $this->getPredefinedTemplates();
        // Strip internal 'daten' (PHP array) -> encode to string for JSON output
        foreach ($predefined as &$t) {
            $t['daten'] = json_encode($t['daten'], JSON_UNESCAPED_UNICODE);
        }

        $stmt = $this->db->query("SELECT id, name, beschreibung, erstellt_von, erstellt_am FROM vde0100_templates ORDER BY erstellt_am DESC");
        $userTemplates = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($userTemplates as &$t) {
            $t['id']       = (int)$t['id'];
            $t['readonly'] = false;
        }

        \jsonOut(['ok' => true, 'predefined' => $predefined, 'user' => $userTemplates]);
    }

    private function templateSave(): void
    {
        $name         = trim((string)($this->body['name'] ?? ''));
        $beschreibung = trim((string)($this->body['beschreibung'] ?? ''));
        $verteilerId  = isset($this->body['verteilerId']) ? (int)$this->body['verteilerId'] : 0;

        if ($name === '') \jsonOut(['error' => 'Name ist erforderlich.'], 400);

        if ($verteilerId > 0) {
            // Aus bestehendem Verteiler mit RCD-Gruppen aufbauen
            $vStmt = $this->db->prepare("SELECT * FROM vde0100_verteiler WHERE id = ?");
            $vStmt->execute([$verteilerId]);
            $v = $vStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$v) \jsonOut(['error' => 'Verteiler nicht gefunden.'], 404);

            // RCDs und ihre Sicherungen laden
            $rStmt = $this->db->prepare(
                "SELECT * FROM vde0100_rcd WHERE verteilerId = ? ORDER BY sortPos, id"
            );
            $rStmt->execute([$verteilerId]);
            $rcds = $rStmt->fetchAll(\PDO::FETCH_ASSOC);

            $rcdGruppen = [];
            foreach ($rcds as $r) {
                $sStmt = $this->db->prepare(
                    "SELECT bezeichnung, typ, nennstrom, leiterquerschnitt
                     FROM vde0100_sicherungen WHERE rcdId = ? ORDER BY sortPos, id"
                );
                $sStmt->execute([$r['id']]);
                $sicherungen = $sStmt->fetchAll(\PDO::FETCH_ASSOC);

                $rcdGruppen[] = [
                    'bezeichnung'     => $r['bezeichnung'],
                    'nennstrom'       => $r['nennstrom']       !== null ? (float)$r['nennstrom']       : null,
                    'nennfehlerstrom' => $r['nennfehlerstrom'] !== null ? (float)$r['nennfehlerstrom'] : null,
                    'typ'             => $r['typ'],
                    'sicherungen'     => array_map(fn($s) => [
                        'bezeichnung'       => $s['bezeichnung'],
                        'typ'               => $s['typ'],
                        'nennstrom'         => $s['nennstrom'] !== null ? (float)$s['nennstrom'] : null,
                        'leiterquerschnitt' => $s['leiterquerschnitt'],
                    ], $sicherungen),
                ];
            }

            $daten = [
                'bezeichnung' => $v['bezeichnung'],
                'nennstrom'   => $v['nennstrom'] !== null ? (float)$v['nennstrom'] : null,
                'rcd_gruppen' => $rcdGruppen,
            ];
        } else {
            $daten = $this->body['daten'] ?? [];
        }

        $stmt = $this->db->prepare(
            "INSERT INTO vde0100_templates (name, beschreibung, daten, erstellt_von) VALUES (?,?,?,?)"
        );
        $stmt->execute([$name, $beschreibung, json_encode($daten, JSON_UNESCAPED_UNICODE), $this->user]);
        $id = (int)$this->db->lastInsertId();
        \jsonOut(['ok' => true, 'id' => $id]);
    }

    private function templateDelete(): void
    {
        $id = (int)($this->body['id'] ?? 0);
        if ($id <= 0) \jsonOut(['error' => 'Fehlende ID.'], 400);
        $this->db->prepare("DELETE FROM vde0100_templates WHERE id = ?")->execute([$id]);
        \jsonOut(['ok' => true]);
    }

    private function templateInsert(): void
    {
        $gebaeudeId = (int)($this->body['gebaeudeId'] ?? 0);
        $templateId = (string)($this->body['templateId'] ?? '');
        if ($gebaeudeId <= 0) \jsonOut(['error' => 'gebaeudeId fehlt.'], 400);

        // Fixiert-Check
        $gRow = $this->db->prepare("SELECT protokollId FROM vde0100_gebaeude WHERE id=?");
        $gRow->execute([$gebaeudeId]);
        $g = $gRow->fetch(\PDO::FETCH_ASSOC);
        if (!$g) \jsonOut(['error' => 'Gebäude nicht gefunden.'], 404);
        if ($this->isFixiert((int)$g['protokollId'])) \jsonOut(['error' => 'Protokoll ist fixiert.'], 400);

        // Daten laden
        if (str_starts_with($templateId, 'pre_')) {
            $all = $this->getPredefinedTemplates();
            $tpl = null;
            foreach ($all as $t) { if ($t['id'] === $templateId) { $tpl = $t; break; } }
            if (!$tpl) \jsonOut(['error' => 'Vordefinierte Vorlage nicht gefunden.'], 404);
            $daten = $tpl['daten']; // PHP-Array
        } else {
            $id = (int)$templateId;
            $stmt = $this->db->prepare("SELECT daten FROM vde0100_templates WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) \jsonOut(['error' => 'Vorlage nicht gefunden.'], 404);
            $daten = json_decode($row['daten'], true) ?: [];
        }

        $verteilerId = $this->insertVerteilerTree($gebaeudeId, $daten);
        \jsonOut(['ok' => true, 'protokollId' => (int)$g['protokollId'], 'verteilerId' => $verteilerId]);
    }

    /**
     * Legt einen Verteiler samt RCD-Gruppen und Abgängen an – gemeinsame Basis für
     * die Vorlagen-Einfügung (templateInsert) und die KI-Erkennung (verteilerKiImport).
     */
    private function insertVerteilerTree(int $gebaeudeId, array $daten): int
    {
        // Verteiler anlegen (ohne RCD-Felder in der Verteiler-Tabelle)
        $stmt = $this->db->prepare(
            "INSERT INTO vde0100_verteiler (gebaeudeId, bezeichnung, nennstrom, sortPos)
             VALUES (?,?,?,0)"
        );
        $stmt->execute([
            $gebaeudeId,
            $daten['bezeichnung'] ?? 'Neuer Verteiler',
            $daten['nennstrom']   ?? null,
        ]);
        $verteilerId = (int)$this->db->lastInsertId();

        // RCD-Gruppen anlegen
        $rcdGruppen = $daten['rcd_gruppen'] ?? null;

        // Rückwärtskompatibilität: altes Format mit flachen rcd_*-Feldern
        if ($rcdGruppen === null) {
            $rcdGruppen = [[
                'bezeichnung'     => $daten['rcd_vorhanden']
                    ? ('FI ' . ($daten['rcd_nennstrom'] ?? '') . 'A / ' . ($daten['rcd_nennfehler'] ?? '') . ' mA Typ ' . ($daten['rcd_typ'] ?? 'A'))
                    : 'Abgänge',
                'nennstrom'       => $daten['rcd_nennstrom']  ?? null,
                'nennfehlerstrom' => $daten['rcd_nennfehler'] ?? null,
                'typ'             => $daten['rcd_typ'] ?? '',
                'sicherungen'     => $daten['sicherungen'] ?? [],
            ]];
        }

        foreach ($rcdGruppen as $ri => $rg) {
            $rStmt = $this->db->prepare(
                "INSERT INTO vde0100_rcd
                 (verteilerId, bezeichnung, nennstrom, nennfehlerstrom, typ, sortPos)
                 VALUES (?,?,?,?,?,?)"
            );
            $rStmt->execute([
                $verteilerId,
                $rg['bezeichnung']     ?? 'RCD',
                $rg['nennstrom']       ?? null,
                $rg['nennfehlerstrom'] ?? null,
                $rg['typ']             ?? '',
                $ri,
            ]);
            $rcdId = (int)$this->db->lastInsertId();

            foreach (($rg['sicherungen'] ?? []) as $si => $s) {
                $sStmt = $this->db->prepare(
                    "INSERT INTO vde0100_sicherungen
                     (rcdId, verteilerId, bezeichnung, typ, nennstrom, leiterquerschnitt, sortPos)
                     VALUES (?,?,?,?,?,?,?)"
                );
                $sStmt->execute([
                    $rcdId,
                    $verteilerId,
                    $s['bezeichnung']       ?? 'Abgang ' . ($si + 1),
                    $s['typ']               ?? 'LS',
                    $s['nennstrom']         ?? null,
                    $s['leiterquerschnitt'] ?? '',
                    $si,
                ]);
            }
        }

        return $verteilerId;
    }

    // ── PDF-Erzeugung ─────────────────────────────────────────

    /**
     * Ladet alle Daten fuer ein Protokoll, rendert das PDF und gibt
     * die Binaerdaten sowie den Dateinamen zurueck.
     *
     * @return array{pdfData: string, fileName: string, baustelleId: int}
     * @throws \RuntimeException wenn dompdf fehlt oder das Protokoll nicht existiert
     */
    private function renderPdf(int $id): array
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new \RuntimeException('PDF-Funktion nicht verfügbar (dompdf fehlt).');
        }

        $stmt = $this->db->prepare("SELECT * FROM vde0100_protokolle WHERE id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$p) throw new \RuntimeException('Protokoll nicht gefunden.');

        $kunde = null;
        if (!empty($p['kundeId'])) {
            $kStmt = $this->db->prepare("SELECT * FROM kunden WHERE id = ?");
            $kStmt->execute([$p['kundeId']]);
            $kunde = $kStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        $baustelle = null;
        if (!empty($p['baustelleId'])) {
            $bStmt = $this->db->prepare("SELECT id, name, data FROM baustellen WHERE id = ?");
            $bStmt->execute([$p['baustelleId']]);
            $baustelle = $bStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        $gebStmt = $this->db->prepare("SELECT * FROM vde0100_gebaeude WHERE protokollId = ? ORDER BY sortPos, id");
        $gebStmt->execute([$id]);
        $gebRows = $gebStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($gebRows as &$g) {
            $vStmt = $this->db->prepare("SELECT * FROM vde0100_verteiler WHERE gebaeudeId = ? ORDER BY sortPos, id");
            $vStmt->execute([$g['id']]);
            $verteiler = $vStmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($verteiler as &$v) {
                $rStmt = $this->db->prepare("SELECT * FROM vde0100_rcd WHERE verteilerId = ? ORDER BY sortPos, id");
                $rStmt->execute([$v['id']]);
                $rcds = $rStmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rcds as &$r) {
                    $sStmt = $this->db->prepare("SELECT * FROM vde0100_sicherungen WHERE rcdId = ? ORDER BY sortPos, id");
                    $sStmt->execute([$r['id']]);
                    $r['sicherungen'] = $sStmt->fetchAll(\PDO::FETCH_ASSOC);
                }
                unset($r);
                $v['rcd'] = $rcds;
            }
            unset($v);
            $g['verteiler'] = $verteiler;
        }
        unset($g);

        $settings = Auth::loadSettings($this->db);
        $html     = $this->buildPdfHtml($p, $gebRows, $kunde, $baustelle, $settings);

        $prevErrorLevel = error_reporting(E_ALL & ~E_DEPRECATED);
        try {
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $pgCanvas = $dompdf->getCanvas();
            try {
                $pgFont = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
            } catch (\Throwable $e) {
                $pgFont = null;
            }
            $pgCanvas->page_text(
                $pgCanvas->get_width() - 105,
                $pgCanvas->get_height() - 20,
                'Seite {PAGE_NUM} / {PAGE_COUNT}',
                $pgFont, 7, [0.33, 0.33, 0.33]
            );
            $pdfData = $dompdf->output();
        } finally {
            error_reporting($prevErrorLevel);
        }

        $safeTitel = preg_replace('/[^A-Za-z0-9_\-]/', '_', $p['titel'] ?: 'Protokoll');
        $fileName  = 'VDE0100_' . $safeTitel . '_' . date('Ymd') . '.pdf';

        return [
            'pdfData'     => $pdfData,
            'fileName'    => $fileName,
            'baustelleId' => (int)($p['baustelleId'] ?? 0),
        ];
    }

    private function protokollPdf(): void
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            \jsonOut(['error' => 'PDF-Funktion nicht verfügbar (dompdf fehlt).'], 503);
        }

        $id = (int)($this->body['id'] ?? 0);
        if ($id <= 0) \jsonOut(['error' => 'Fehlende ID.'], 400);

        $result = $this->renderPdf($id);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $result['fileName'] . '"');
        header('Content-Length: ' . strlen($result['pdfData']));
        header('Cache-Control: private');
        echo $result['pdfData'];
        exit;
    }

    /**
     * Gibt PDF-Bytes für ein Prüfprotokoll zurück, ohne HTTP-Output.
     * Wird von EmailActions::sendVde() genutzt.
     *
     * @return array{pdfData: string, fileName: string}
     * @throws \RuntimeException
     */
    public function getPdfData(int $id): array
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new \RuntimeException('PDF-Funktion nicht verfügbar (dompdf fehlt).');
        }
        if ($id <= 0) {
            throw new \RuntimeException('Fehlende Protokoll-ID.');
        }
        return $this->renderPdf($id);
    }

    private function buildPdfHtml(array $p, array $gebRows, ?array $kunde, ?array $baustelle, array $s): string
    {
        $h   = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fmt = fn($v): string => $v !== null ? str_replace('.', ',', (string)$v) : '–';

        // ── Firmendaten ────────────────────────────────────────
        $firma        = $h($s['firma_name']   ?? '');
        $strasse      = $h($s['firma_strasse'] ?? '');
        $plzOrt       = $h(trim(($s['firma_plz'] ?? '') . ' ' . ($s['firma_ort'] ?? '')));
        $tel          = $s['firma_telefon'] ?? '';
        $email        = $s['firma_email']   ?? '';
        $firmaAdr     = array_filter([$strasse, $plzOrt]);
        $firmaContact = array_filter([$tel ? 'Tel.: ' . $tel : '', $email]);
        $firmaAdrLine = $h(implode(' · ', $firmaAdr));
        $firmaConLine = $h(implode(' · ', $firmaContact));
        // ── Firmen-Logo (inline Base64) ────────────────────────
        $logoHtml = '';
        $logoUri  = FirmenLogo::dataUri($s);
        if ($logoUri !== '') {
            $logoHtml = '<img src="' . $logoUri . '" style="max-height:42px;max-width:150px;display:block;" />';
        }

        // ── Kundendaten ────────────────────────────────────────
        $kundeName    = '';
        $kundeAdr1    = '';
        $kundeAdr2    = '';
        $kundeTel     = '';
        if ($kunde) {
            $kundeName = $h(trim(($kunde['firma'] ?: '') ?: (($kunde['anrede'] ?? '') . ' ' . ($kunde['vorname'] ?? '') . ' ' . ($kunde['nachname'] ?? ''))));
            $kundeAdr1 = $h(trim($kunde['strasse'] ?? ''));
            $kundeAdr2 = $h(trim(($kunde['plz'] ?? '') . ' ' . ($kunde['ort'] ?? '')));
            $kundeTel  = $h(trim($kunde['telefon'] ?? $kunde['mobile'] ?? ''));
        }
        $baustelleName = $baustelle ? $h($baustelle['name'] ?? '') : '';

        // ── Projektnummer: Baustelle.data.projektNr › Protokoll.projektnummer › Fallback ──
        $projektNr = '';
        if (!empty($p['projektnummer'])) {
            $projektNr = $h($p['projektnummer']);
        } elseif ($baustelle && !empty($baustelle['data'])) {
            $bauData = json_decode((string)$baustelle['data'], true) ?: [];
            if (!empty($bauData['projektNr'])) {
                $projektNr = $h((string)$bauData['projektNr']);
            }
        }
        if (!$projektNr) {
            $projektNr = 'VDE-' . sprintf('%05d', (int)$p['id']);
        }

        // ── Norm-abhängige Texte ───────────────────────────────
        $norm = $p['norm'] ?? 'vde0100_600';
        if ($norm === 'vde0105') {
            $normTitle    = 'Pr&uuml;fprotokoll &ndash; Betrieb elektrischer Anlagen';
            $normSub      = 'DIN VDE 0105-100 &nbsp;&middot;&nbsp; EN&nbsp;50110-1';
            $normFooter   = 'Gem&auml;&szlig; DIN VDE 0105-100 / EN 50110-1';
            $normRefLabel = 'Gepr&uuml;ft nach';
            $normRefVal   = 'DIN VDE 0105-100 (EN 50110-1)';
        } elseif ($norm === 'dguv_v3') {
            $normTitle    = 'Pr&uuml;fprotokoll &ndash; Elektrische Anlagen &amp; Betriebsmittel';
            $normSub      = 'DGUV Vorschrift 3 (BGV A3) &nbsp;&middot;&nbsp; DIN VDE 0701-0702';
            $normFooter   = 'Gem&auml;&szlig; DGUV Vorschrift 3 (BGV A3) / DIN VDE 0701-0702';
            $normRefLabel = 'Gepr&uuml;ft nach';
            $normRefVal   = 'DGUV Vorschrift 3 (BGV A3)';
        } else {
            $normTitle    = 'Pr&uuml;fprotokoll &ndash; Erst- &amp; Wiederholungspr&uuml;fung';
            $normSub      = 'DIN VDE 0100-600 &nbsp;&middot;&nbsp; HD&nbsp;60364-6 &nbsp;&middot;&nbsp; IEC&nbsp;60364-6';
            $normFooter   = 'Gem&auml;&szlig; DIN VDE 0100-600 / HD 60364-6 / IEC 60364-6';
            $normRefLabel = 'Gepr&uuml;ft nach';
            $normRefVal   = 'DIN VDE 0100-600 (HD 60364-6 / IEC 60364-6)';
        }

        // ── Protokoll-Metadaten ───────────────────────────────
        $titel           = $h($p['titel'] ?? '');
        $anlagenart      = $h($p['anlagenart'] ?? '') ?: '–';
        $schutzmassnahme = $h($p['schutzmassnahme'] ?? '') ?: '–';
        $nennspannung    = $h($p['nennspannung'] ?? '') ?: '–';
        $nennfrequenz    = $h($p['nennfrequenz'] ?? '') ?: '–';
        $nennstrom       = ($p['nennstrom'] ?? '') !== '' ? $h($p['nennstrom']) . ' A' : '–';
        $messgeraetHersteller  = $h($p['messgeraet_hersteller'] ?? '');
        $messgeraetTyp         = $h($p['messgeraet_typ'] ?? '');
        $messgeraetKalibrierung = $h($p['messgeraet_kalibrierung'] ?? '');
        $erstelltVon     = $h($p['erstellt_von'] ?? '');
        $erstelltAm      = $h(substr($p['erstellt_am'] ?? '', 0, 10));
        $anlagenanschrift = nl2br($h($p['anlagenanschrift'] ?? ''));
        $kundenanschrift  = nl2br($h($p['kundenanschrift'] ?? ''));
        $netzbetreiber    = $h($p['netzbetreiber'] ?? '');
        $fixiertAm       = $p['fixiert_am'] ? $h(substr($p['fixiert_am'], 0, 10)) : '';
        $statusLabel     = $p['status'] === 'fixiert' ? 'Gepr&uuml;ft &#10003;' : 'Entwurf';
        $statusColor     = $p['status'] === 'fixiert' ? '#1b5e20' : '#e65100';
        $bemerkung       = $h($p['bemerkung'] ?? '');

        // ── Unterschrift ──────────────────────────────────────
        // Sichtpruefung + Funktionspruefung (v2.8.0)
        $fmtPruefStatus = static function (string $st): string {
            return match($st) {
                'ok'         => '<span style="background:#e8f5e9;color:#1b5e20;font-weight:bold;padding:1px 6px;border-radius:3px;">&#10003; OK</span>',
                'fehler'     => '<span style="background:#ffebee;color:#b71c1c;font-weight:bold;padding:1px 6px;border-radius:3px;">&#10007; Fehler</span>',
                'ausstehend' => '<span style="background:#fff3e0;color:#e65100;padding:1px 6px;border-radius:3px;">&#9679; Ausstehend</span>',
                default      => '<span style="color:#bbb;">&#8211;</span>',
            };
        };
        $sichtStatus   = $p['sichtpruefung_status'] ?? '';
        $sichtBem      = $h($p['sichtpruefung_bem'] ?? '');
        $funkStatus    = $p['funktionspruefung_status'] ?? '';
        $funkBem       = $h($p['funktionspruefung_bem'] ?? '');
        $hasSichtFunk  = $sichtStatus !== '' || $sichtBem !== '' || $funkStatus !== '' || $funkBem !== '';

        $unterschriftHtml = '';
        if (!empty($p['unterschrift'])) {
            $unterschriftHtml = '<img src="' . $h($p['unterschrift']) . '" style="max-height:70px;max-width:220px;" />';
        }

        // ── Messtabellen ──────────────────────────────────────
        $tabellen = '';
        foreach ($gebRows as $g) {
            $tabellen .= '<div class="geb-block">';
            $tabellen .= '<div class="geb-title">' . $h($g['bezeichnung']) . '</div>';
            foreach ($g['verteiler'] as $v) {
                $vNenn = $v['nennstrom'] ? ' &nbsp;<span style="font-size:7pt;color:#555;">In = ' . $v['nennstrom'] . ' A</span>' : '';
                $tabellen .= '<div class="vtl-block" style="page-break-inside:avoid;">';
                $tabellen .= '<div class="vtl-title">' . $h($v['bezeichnung']) . $vNenn . '</div>';
                if ($v['bemerkung']) {
                    $tabellen .= '<p style="font-size:7.5pt;color:#666;padding:2px 6px 4px;">&#8618; ' . $h($v['bemerkung']) . '</p>';
                }
                if (empty($v['rcd'])) {
                    $tabellen .= '<p style="font-size:7.5pt;color:#aaa;padding:4px 6px;">– Keine Abg&auml;nge –</p>';
                } else {
                    foreach ($v['rcd'] as $r) {
                        $rcdInfo = '';
                        if ($r['nennstrom'] !== null)       $rcdInfo .= $r['nennstrom'] . ' A';
                        if ($r['nennfehlerstrom'] !== null) $rcdInfo .= ($rcdInfo ? ' / ' : '') . $r['nennfehlerstrom'] . ' mA';
                        if ($r['typ'])                     $rcdInfo .= ($rcdInfo ? ' Typ ' : 'Typ ') . $h($r['typ']);
                        $rcdSub = $rcdInfo
                            ? $h($r['bezeichnung']) . ' <span style="font-size:6.5pt;font-weight:normal;color:#3949ab;">(' . $rcdInfo . ')</span>'
                            : $h($r['bezeichnung']);
                        // RCD-Messwerte (Id + Auslösezeit)
                        $rcdMessLine = '';
                        $rcdMessParts = [];
                        if (isset($r['messung_rcd_id']) && $r['messung_rcd_id'] !== null && $r['messung_rcd_id'] !== '') {
                            $rcdMessParts[] = 'I&Delta;N gemessen: <strong>' . $fmt((float)$r['messung_rcd_id']) . ' mA</strong>';
                        }
                        if (isset($r['messung_rcd_tt']) && $r['messung_rcd_tt'] !== null && $r['messung_rcd_tt'] !== '') {
                            $rcdMessParts[] = 'Auslösezeit: <strong>' . $fmt((float)$r['messung_rcd_tt']) . ' ms</strong>';
                        }
                        if ($rcdMessParts) {
                            $rcdMessLine = ' &nbsp;<span style="font-size:6pt;color:#5c6bc0;">' . implode(' &nbsp;|&nbsp; ', $rcdMessParts) . '</span>';
                        }
                        $tabellen .= '<div style="margin:4px 0 2px 0;padding:3px 8px;background:#e8eaf6;border-left:4px solid #3949ab;font-size:7.5pt;font-weight:bold;color:#1a237e;">'
                            . '&#9697; ' . $rcdSub . $rcdMessLine . '</div>';
                        if (empty($r['sicherungen'])) {
                            $tabellen .= '<p style="font-size:7.5pt;color:#aaa;padding:2px 14px;">– Keine Abg&auml;nge –</p>';
                        } else {
                            $tabellen .= '<table class="mess-tab" style="margin-left:4px;width:calc(100% - 4px);">'
                                . '<thead><tr>'
                                . '<th style="width:19%;">Bezeichnung</th>'
                                . '<th style="width:7%;">Typ</th>'
                                . '<th style="width:6%;">In (A)</th>'
                                . '<th style="width:6%;">mm&sup2;</th>'
                                . '<th style="width:8%;">Iso-R M&#937;</th>'
                                . '<th style="width:8%;">Zs &#937;</th>'
                                . '<th style="width:8%;">Zi &#937;</th>'
                                . '<th style="width:8%;">Polarit&auml;t</th>'
                                . '<th style="width:8%;">Re &#937;</th>'
                                . '<th style="width:10%;">Status</th>'
                                . '</tr></thead><tbody>';
                            foreach ($r['sicherungen'] as $idx => $s2) {
                                $st  = $s2['pruefstatus'] ?? '';
                                $stc = match($st) {
                                    'ok'         => '<span style="color:#1b5e20;font-weight:bold;">&#10003; OK</span>',
                                    'fehler'     => '<span style="color:#b71c1c;font-weight:bold;">&#10007; Fehler</span>',
                                    'ausstehend' => '<span style="color:#e65100;">&#9679; Offen</span>',
                                    default      => '<span style="color:#bbb;">–</span>',
                                };
                                $pol = $s2['messung_polaritaet'] ?? '';
                                $polc = match($pol) {
                                    'ok'     => '<span style="color:#1b5e20;">&#10003;</span>',
                                    'fehler' => '<span style="color:#b71c1c;">&#10007;</span>',
                                    default  => '<span style="color:#bbb;">–</span>',
                                };
                                $rowBg = ($idx % 2 === 1) ? 'background:#f4f7ff;' : '';
                                $tabellen .= '<tr style="' . $rowBg . '">'
                                    . '<td>' . $h($s2['bezeichnung']) . '</td>'
                                    . '<td style="text-align:center;">' . ($h($s2['typ']) ?: '–') . '</td>'
                                    . '<td style="text-align:center;">' . ($s2['nennstrom'] !== null ? $fmt($s2['nennstrom']) : '–') . '</td>'
                                    . '<td style="text-align:center;">' . ($s2['leiterquerschnitt'] ? $h($s2['leiterquerschnitt']) : '–') . '</td>'
                                    . '<td style="text-align:center;">' . $fmt($s2['messung_iso']) . '</td>'
                                    . '<td style="text-align:center;">' . $fmt($s2['messung_zs'])  . '</td>'
                                    . '<td style="text-align:center;">' . $fmt($s2['messung_zi'] ?? null) . '</td>'
                                    . '<td style="text-align:center;">' . $polc . '</td>'
                                    . '<td style="text-align:center;">' . $fmt($s2['messung_re'])  . '</td>'
                                    . '<td style="text-align:center;">' . $stc . '</td>'
                                    . '</tr>';
                            }
                            $tabellen .= '</tbody></table>';
                        }
                    }
                }
                $tabellen .= '</div>'; // vtl-block
            }
            $tabellen .= '</div>'; // geb-block
        }
        if (!$tabellen) {
            $tabellen = '<p style="font-size:8pt;color:#aaa;padding:8px;">Noch keine Messdaten erfasst.</p>';
        }

        // ── Signatur-Zeile ────────────────────────────────────
        // pruef_datum (manuell gesetzt) hat Vorrang vor fixiert_am
        $pruefdatum = ($p['pruef_datum'] ?? '') ?: ($fixiertAm ?: '_______________');

        // ── Logo-Fallback: Firmenname in Letterhead ───────────
        $lhLogoBlock = $logoHtml ?: '<span style="font-size:10pt;font-weight:bold;color:#1565c0;">' . $firma . '</span>';

        // ── HTML ───────────────────────────────────────────────
        $html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 35mm 12mm 16mm 12mm; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 9pt; color: #1a1a1a; background: #fff; color-scheme: light; }

  /* ── Sektions-Überschrift ────────────────────── */
  .sec-head { background: #1565c0; color: #fff; font-size: 8.5pt; font-weight: bold;
              padding: 4px 9px; margin: 9px 0 4px; }

  /* ── Sektions-Wrapper (Seitenumbruch vor Abschnitt) ── */
  .sec-block { page-break-inside: avoid; }

  /* ── Info-Tabelle ────────────────────────────── */
  .info-tab     { width: 100%; border-collapse: collapse; font-size: 8pt; }
  .info-tab td  { padding: 3px 6px; border: 1px solid #dde; vertical-align: top; }
  .info-tab .lbl { width: 44%; background: #f0f4ff; font-weight: bold; color: #444; }

  /* ── Gebäude-Blöcke ──────────────────────────── */
  .geb-block { margin-bottom: 10px; }
  .geb-title { font-size: 9.5pt; font-weight: bold; background: #dce8ff;
               padding: 4px 9px; border-left: 4px solid #1565c0; margin-bottom: 3px; }
  .vtl-block { margin-bottom: 6px; padding-left: 6px; }
  .vtl-title { font-size: 8.5pt; font-weight: bold; padding: 3px 7px;
               background: #f5f7ff; border-bottom: 1px solid #c5cae9; margin-bottom: 3px; }

  /* ── Mess-Tabelle ────────────────────────────── */
  .mess-tab    { width: 100%; border-collapse: collapse; font-size: 7pt; }
  .mess-tab th { background: #1a237e; color: #fff; padding: 3px 3px; text-align: center;
                 border: 1px solid #0d47a1; font-size: 6.5pt; }
  .mess-tab td { padding: 2px 3px; border: 1px solid #dde; }

  /* ── Kopf- und Fußzeile ──────────────────────── */
  .pdf-header-fixed { position: fixed; top: 0; left: 0; right: 0; height: 33mm; background: #fff; }
  .pdf-footer-fixed { position: fixed; bottom: 0; left: 0; right: 0; height: 14mm;
                      background: #fff; border-top: 2px solid #1565c0; padding: 3px 12px 0; }
</style>
</head>
<body>
<div class="pdf-header-fixed">

  <!-- ── Briefkopf ─────────────────────────────── -->
  <table style="width:100%;border-collapse:collapse;border-bottom:2px solid #1565c0;">
    <tr>
      <td style="width:40%;vertical-align:middle;padding:5px 12px 4px;">{$lhLogoBlock}</td>
      <td style="width:60%;vertical-align:middle;text-align:right;padding:5px 12px 4px;">
HTML;
        if ($firma) {
            $html .= '        <div style="font-size:9pt;font-weight:bold;color:#1565c0;">' . $firma . '</div>' . "\n";
        }
        if ($firmaAdrLine || $firmaConLine) {
            $html .= '        <div style="font-size:6.5pt;color:#555;margin-top:2px;line-height:1.4;">';
            if ($firmaAdrLine) $html .= $firmaAdrLine;
            if ($firmaAdrLine && $firmaConLine) $html .= '<br />';
            if ($firmaConLine) $html .= $firmaConLine;
            $html .= '</div>' . "\n";
        }
        $html .= <<<HTML
      </td>
    </tr>
  </table>

  <!-- ── Titelband ─────────────────────────────── -->
  <div style="background:#1565c0;">
    <table style="width:100%;border-collapse:collapse;">
      <tr>
        <td style="vertical-align:middle;padding:5px 12px;">
          <div style="font-size:10pt;font-weight:bold;color:#fff;">&#9889; {$normTitle}</div>
          <div style="font-size:6pt;color:#bbdefb;margin-top:2px;letter-spacing:0.02em;">{$normSub}</div>
        </td>
        <td style="width:36%;vertical-align:middle;text-align:right;padding:5px 12px;">
          <div style="display:inline-block;background:rgba(255,255,255,0.15);color:#fff;font-size:7.5pt;font-weight:bold;padding:2px 8px;border-radius:3px;border:1px solid rgba(255,255,255,0.3);">Nr. {$projektNr}</div>
        </td>
      </tr>
    </table>
  </div>
</div>
<div class="pdf-footer-fixed">
  <table style="width:100%;border-collapse:collapse;font-size:7pt;color:#555;padding:3px 0;">
    <tr>
      <td style="text-align:left;">Nr. {$projektNr} &nbsp;|&nbsp; {$erstelltAm} &nbsp;|&nbsp; {$statusLabel}</td>
      <td style="text-align:right;">{$firma}</td>
    </tr>
  </table>
</div>

  <!-- ── Inhalt ────────────────────────────────── -->
  <div style="padding:36mm 14px 18mm;">
    <table style="width:100%;border-collapse:collapse;margin-bottom:9px;border-bottom:1px solid #ddd;">
      <tr>
        <td style="vertical-align:middle;font-size:11pt;font-weight:bold;padding-bottom:5px;">{$titel}</td>
        <td style="vertical-align:middle;text-align:right;font-size:8pt;font-weight:bold;padding-bottom:5px;color:{$statusColor};">{$statusLabel}</td>
      </tr>
    </table>

    <!-- ── Anlage & Auftraggeber ─────────── -->
    <div class="sec-block">
    <div class="sec-head">Anlage &amp; Auftraggeber</div>
    <table style="width:100%;border-collapse:collapse;margin-bottom:4px;">
      <tr>
        <td style="width:50%;vertical-align:top;padding-right:3px;">
          <table class="info-tab">
            <tr><td class="lbl">Gepr&uuml;ft nach</td><td>{$normRefVal}</td></tr>
            <tr><td class="lbl">Anlagenart</td><td>{$anlagenart}</td></tr>
            <tr><td class="lbl">Netzform</td><td>{$schutzmassnahme}</td></tr>
            <tr><td class="lbl">Nennspannung</td><td>{$nennspannung} V</td></tr>
            <tr><td class="lbl">Nennfrequenz</td><td>{$nennfrequenz} Hz</td></tr>
            <tr><td class="lbl">Nennstrom</td><td>{$nennstrom}</td></tr>
HTML;
        if ($messgeraetHersteller) $html .= '<tr><td class="lbl">Hersteller Messger&auml;t</td><td>' . $messgeraetHersteller . '</td></tr>';
        if ($messgeraetTyp)        $html .= '<tr><td class="lbl">Typ Messger&auml;t</td><td>' . $messgeraetTyp . '</td></tr>';
        if ($messgeraetKalibrierung) $html .= '<tr><td class="lbl">Letzte Kalibrierung</td><td>' . $messgeraetKalibrierung . '</td></tr>';
        $html .= <<<HTML
          </table>
        </td>
        <td style="width:50%;vertical-align:top;padding-left:3px;">
          <table class="info-tab">
HTML;
        $html .= '<tr><td class="lbl">Auftraggeber</td><td>' . ($kundeName ?: '–') . '</td></tr>';
        if ($kundeAdr1) $html .= '<tr><td class="lbl">Adresse</td><td>' . $kundeAdr1 . ($kundeAdr2 ? ', ' . $kundeAdr2 : '') . '</td></tr>';
        if ($kundeTel)  $html .= '<tr><td class="lbl">Telefon</td><td>' . $kundeTel . '</td></tr>';
        if ($kundenanschrift) $html .= '<tr><td class="lbl">Kundenanschrift</td><td>' . $kundenanschrift . '</td></tr>';
        $html .= '<tr><td class="lbl">Anlage / Baustelle</td><td>' . ($baustelleName ?: '–') . '</td></tr>';
        if ($anlagenanschrift) $html .= '<tr><td class="lbl">Anlagenanschrift</td><td>' . $anlagenanschrift . '</td></tr>';
        if ($netzbetreiber)   $html .= '<tr><td class="lbl">Netzbetreiber</td><td>' . $netzbetreiber . '</td></tr>';
        $html .= '<tr><td class="lbl">Projektnummer</td><td><strong>' . $projektNr . '</strong></td></tr>';
        $html .= '<tr><td class="lbl">Gepr&uuml;ft von</td><td>' . $erstelltVon . '</td></tr>';
        $html .= '<tr><td class="lbl">Erstellt am</td><td>' . $erstelltAm . '</td></tr>';
        if ($fixiertAm) $html .= '<tr><td class="lbl">Gepr&uuml;ft am</td><td><strong>' . $fixiertAm . '</strong></td></tr>';
        $html .= <<<HTML
          </table>
        </td>
      </tr>
    </table>
    </div><!-- /sec-block Anlage -->

    <!-- ── Messwerte ─────────────────────── -->
    <div class="sec-head">Messergebnisse</div>
    {$tabellen}

HTML;
        $html .= '    <div class="sec-block">' . "\n";
        $html .= '    <div class="sec-head">Sichtpr&uuml;fung &amp; Funktionspr&uuml;fung</div>' . "\n";
        $html .= '    <table class="info-tab" style="width:100%;margin-bottom:6px;">' . "\n";
        $html .= '      <tr>'
            . '<td class="lbl" style="width:22%;">Sichtpr&uuml;fung</td>'
            . '<td style="width:28%;">' . $fmtPruefStatus($sichtStatus) . '</td>'
            . '<td class="lbl" style="width:22%;border-left:3px solid #c5cae9;">Funktionspr&uuml;fung</td>'
            . '<td style="width:28%;">' . $fmtPruefStatus($funkStatus) . '</td>'
            . '</tr>' . "\n";
        if ($sichtBem || $funkBem) {
            $html .= '      <tr>'
                . '<td class="lbl">Bemerkung Sicht</td>'
                . '<td>' . $sichtBem . '</td>'
                . '<td class="lbl" style="border-left:3px solid #c5cae9;">Bemerkung Funkt.</td>'
                . '<td>' . $funkBem . '</td>'
                . '</tr>' . "\n";
        }
        $html .= '    </table>' . "\n";
        $html .= '    </div>' . "\n"; // /sec-block Sichtpruefung
        if ($bemerkung) {
            $html .= '    <div class="sec-block">' . "\n";
            $html .= '    <div class="sec-head">Bemerkungen</div>' . "\n";
            $html .= '    <p style="font-size:8.5pt;padding:5px 0;">' . $bemerkung . '</p>' . "\n";
            $html .= '    </div>' . "\n"; // /sec-block Bemerkungen
        }

        $html .= <<<HTML

    <!-- ── Best&auml;tigung / Unterschrift ────── -->
    <div class="sec-block" style="page-break-before:always;padding-top:36mm;margin-top:0;">
    <div class="sec-head">Best&auml;tigung</div>
    <table style="width:100%;border-collapse:collapse;border:1px solid #ccd;background:#fafbff;margin-top:10px;">
      <tr>
        <td style="width:33.33%;padding:10px 10px;text-align:center;border-right:1px solid #ccd;vertical-align:bottom;">
          <div style="font-size:8.5pt;font-weight:bold;">{$pruefdatum}</div>
          <div style="border-top:1px solid #555;padding-top:3px;font-size:7pt;color:#666;margin-top:70px;">Datum der Pr&uuml;fung</div>
        </td>
        <td style="width:33.33%;padding:10px 10px;text-align:center;border-right:1px solid #ccd;vertical-align:bottom;">
          <div style="min-height:70px;">{$unterschriftHtml}</div>
          <div style="border-top:1px solid #555;padding-top:3px;font-size:7pt;color:#666;">Unterschrift Pr&uuml;fer</div>
        </td>
        <td style="width:33.33%;padding:10px 10px;text-align:center;vertical-align:bottom;">
          <div style="border:1px dashed #bbb;min-height:70px;margin-bottom:2px;"></div>
          <div style="border-top:1px solid #555;padding-top:3px;font-size:7pt;color:#666;">Stempel / Firmenstempel</div>
        </td>
      </tr>
    </table>
    </div><!-- /sec-block Bestaetigung -->

    <p style="font-size:7pt;color:#888;margin-top:6px;text-align:center;">{$normFooter}</p>

  </div><!-- /body-pad -->
</body>
</html>
HTML;
        return $html;
    }
}
