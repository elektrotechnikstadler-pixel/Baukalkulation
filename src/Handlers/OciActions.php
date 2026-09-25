<?php
// ============================================================
// OciActions – OCI 4.0 Punchout-Schnittstelle (v2.6.9)
// ============================================================
// Workflow:
//   1. Admin konfiguriert Lieferanten (Name, OCI-URL, Credentials).
//   2. Benutzer klickt "OCI Großhandel" im Material-Picker.
//   3. Frontend ruft oci_prepare auf → erhält einmaligen Nonce.
//   4. Popup öffnet api.php?action=oci_start&t=<nonce> →
//      Server rendert Auto-Submit-Formular zum Lieferanten.
//   5. User wählt Artikel im Lieferanten-Shop.
//   6. Lieferant POSTet an api.php?action=oci_hook&t=<nonce> →
//      Server parst NEW_ITEM-*[n] Parameter, gibt HTML+JS zurück,
//      das per window.opener.postMessage die Artikel ans Frontend sendet.
//   7. Frontend-Listener empfängt Artikel und fügt sie zur Baustelle hinzu.
// ============================================================

namespace App\Handlers;

use App\Auth;
use App\Database;

class OciActions
{
    // Nonce-Gültigkeitsdauer in Sekunden (30 Minuten)
    private const NONCE_TTL = 1800;

    public function __construct(private \PDO $db, private array $body) {}

    // ══════════════════════════════════════════════════════════
    // ADMIN: Lieferanten verwalten
    // ══════════════════════════════════════════════════════════

    /** GET: Liste aller OCI-Lieferanten (Passwort maskiert). */
    public function listLieferanten(): void
    {
        Auth::requireAuth();
        $rows = Database::fetchAll($this->db, "SELECT id, name, url, username, aktiv, notizen, erstelltAm FROM oci_lieferanten ORDER BY name");
        jsonOut(['ok' => true, 'lieferanten' => $rows]);
    }

    /** POST: Lieferant anlegen oder aktualisieren (nur Admin). */
    public function saveLieferant(): void
    {
        Auth::requireRole('admin', 'master');

        $id       = isset($this->body['id']) ? (int)$this->body['id'] : 0;
        $name     = trim($this->body['name']     ?? '');
        $url      = trim($this->body['url']      ?? '');
        $username = trim($this->body['username'] ?? '');
        $password = $this->body['password']      ?? '';
        $aktiv    = (int)(bool)($this->body['aktiv'] ?? true);
        $notizen  = trim($this->body['notizen']  ?? '');

        if ($name === '' || $url === '') {
            jsonOut(['error' => 'Name und URL sind erforderlich.'], 400);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            jsonOut(['error' => 'Ungültige OCI-URL.'], 400);
        }

        if ($id > 0) {
            // Update – Passwort nur überschreiben wenn neu übergeben (nicht '***')
            if ($password !== '' && $password !== '***') {
                $this->db->prepare(
                    "UPDATE oci_lieferanten SET name=?, url=?, username=?, password=?, aktiv=?, notizen=? WHERE id=?"
                )->execute([$name, $url, $username, $password, $aktiv, $notizen, $id]);
            } else {
                $this->db->prepare(
                    "UPDATE oci_lieferanten SET name=?, url=?, username=?, aktiv=?, notizen=? WHERE id=?"
                )->execute([$name, $url, $username, $aktiv, $notizen, $id]);
            }
            jsonOut(['ok' => true, 'id' => $id]);
        } else {
            $this->db->prepare(
                "INSERT INTO oci_lieferanten (name, url, username, password, aktiv, notizen, erstelltAm) VALUES (?,?,?,?,?,?,?)"
            )->execute([$name, $url, $username, $password, $aktiv, $notizen, date('Y-m-d H:i:s')]);
            jsonOut(['ok' => true, 'id' => (int)$this->db->lastInsertId()]);
        }
    }

    /** POST: Lieferant löschen (nur Admin). */
    public function deleteLieferant(): void
    {
        Auth::requireRole('admin', 'master');
        $id = (int)($this->body['id'] ?? 0);
        if ($id <= 0) {
            jsonOut(['error' => 'Ungültige ID.'], 400);
        }
        $this->db->prepare("DELETE FROM oci_lieferanten WHERE id = ?")->execute([$id]);
        // Verwaiste Nonces aufräumen
        $this->db->prepare("DELETE FROM oci_nonces WHERE lieferantId = ?")->execute([$id]);
        jsonOut(['ok' => true]);
    }

    // ══════════════════════════════════════════════════════════
    // PUNCHOUT FLOW
    // ══════════════════════════════════════════════════════════

    /** GET: Nonce erzeugen und zurückgeben (Auth erforderlich). */
    public function prepare(): void
    {
        Auth::requireAuth();
        $lieferantId = (int)($_GET['lieferantId'] ?? 0);
        if ($lieferantId <= 0) {
            jsonOut(['error' => 'lieferantId fehlt.'], 400);
        }
        $lieferant = Database::fetchOne($this->db, "SELECT id FROM oci_lieferanten WHERE id = ? AND aktiv = 1", [$lieferantId]);
        if (!$lieferant) {
            jsonOut(['error' => 'Lieferant nicht gefunden oder inaktiv.'], 404);
        }

        // Abgelaufene Nonces aufräumen
        $cutoff = date('Y-m-d H:i:s', time() - self::NONCE_TTL);
        $this->db->prepare("DELETE FROM oci_nonces WHERE erstelltAm < ?")->execute([$cutoff]);

        $nonce = bin2hex(random_bytes(32));
        $this->db->prepare(
            "INSERT INTO oci_nonces (nonce, lieferantId, erstelltAm) VALUES (?,?,?)"
        )->execute([$nonce, $lieferantId, date('Y-m-d H:i:s')]);

        jsonOut(['ok' => true, 'nonce' => $nonce]);
    }

    /**
     * GET: Punchout starten – gibt HTML-Seite zurück, die ein Formular
     * zum Lieferanten auto-submitted. Kein Session-Auth nötig (Nonce-Validierung).
     */
    public function start(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');

        $nonce = trim($_GET['t'] ?? '');
        if (!$nonce) {
            http_response_code(400);
            echo '<!DOCTYPE html><html><body><p>Fehler: Parameter fehlt.</p></body></html>';
            exit;
        }

        $nonceRow = Database::fetchOne(
            $this->db,
            "SELECT lieferantId, erstelltAm, verwendetAm FROM oci_nonces WHERE nonce = ?",
            [$nonce]
        );

        if (!$nonceRow || $nonceRow['verwendetAm'] !== null) {
            http_response_code(403);
            echo '<!DOCTYPE html><html><body><p>Fehler: Ungültiger oder bereits verwendeter Sitzungs-Token.</p></body></html>';
            exit;
        }
        // TTL prüfen
        $age = time() - strtotime($nonceRow['erstelltAm']);
        if ($age > self::NONCE_TTL) {
            http_response_code(403);
            echo '<!DOCTYPE html><html><body><p>Fehler: Sitzungs-Token abgelaufen. Bitte erneut versuchen.</p></body></html>';
            exit;
        }

        $lieferant = Database::fetchOne(
            $this->db,
            "SELECT name, url, username, password FROM oci_lieferanten WHERE id = ? AND aktiv = 1",
            [(int)$nonceRow['lieferantId']]
        );
        if (!$lieferant) {
            http_response_code(404);
            echo '<!DOCTYPE html><html><body><p>Fehler: Lieferant nicht gefunden.</p></body></html>';
            exit;
        }

        // Nonce als "in Verwendung" markieren (nicht löschen – hook braucht sie noch)
        $this->db->prepare("UPDATE oci_nonces SET verwendetAm = ? WHERE nonce = ?")
                 ->execute([date('Y-m-d H:i:s'), $nonce]);

        // HOOK_URL aufbauen (absolute URL ableiten)
        $proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        $hookUrl  = $proto . '://' . $host . $script . '/api.php?action=oci_hook&t=' . urlencode($nonce);

        $supplierUrl = htmlspecialchars($lieferant['url'],      ENT_QUOTES, 'UTF-8');
        $user        = htmlspecialchars($lieferant['username'], ENT_QUOTES, 'UTF-8');
        $pass        = htmlspecialchars($lieferant['password'], ENT_QUOTES, 'UTF-8');
        $hookUrlHtml = htmlspecialchars($hookUrl,               ENT_QUOTES, 'UTF-8');
        $nameHtml    = htmlspecialchars($lieferant['name'],     ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>OCI – {$nameHtml}</title>
  <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5}
  .box{text-align:center;padding:32px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.12)}
  p{color:#444;font-size:1rem}.spinner{display:inline-block;width:36px;height:36px;border:4px solid #ddd;border-top-color:#00B4D8;border-radius:50%;animation:spin .8s linear infinite;margin-bottom:16px}
  @keyframes spin{to{transform:rotate(360deg)}}</style>
</head>
<body>
  <div class="box">
    <div class="spinner"></div>
    <p>Verbindung zu <strong>{$nameHtml}</strong> wird hergestellt…</p>
  </div>
  <form id="oci_form" method="POST" action="{$supplierUrl}">
    <input type="hidden" name="USERNAME"      value="{$user}">
    <input type="hidden" name="PASSWORD"      value="{$pass}">
    <input type="hidden" name="HOOK_URL"      value="{$hookUrlHtml}">
    <input type="hidden" name="~OCI_VERSION"  value="4.0">
    <input type="hidden" name="~Language"     value="de">
    <input type="hidden" name="~Target"       value="no">
    <input type="hidden" name="FUNCTION"      value="SOURCING">
  </form>
  <script>
    // Kurze Verzögerung damit der Spinner sichtbar wird
    setTimeout(function(){ document.getElementById('oci_form').submit(); }, 200);
  </script>
</body>
</html>
HTML;
        exit;
    }

    /**
     * POST: OCI-Hook – empfängt NEW_ITEM-*[n] Parameter vom Lieferanten,
     * gibt eine HTML-Seite zurück die per postMessage die Artikel
     * ans öffnende Fenster sendet und sich dann schließt.
     * Kein Session-Auth nötig (Nonce-Validierung).
     */
    public function hook(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');

        $nonce = trim($_GET['t'] ?? '');
        if (!$nonce) {
            http_response_code(400);
            $this->renderHookError('Parameter fehlt.');
            exit;
        }

        $nonceRow = Database::fetchOne(
            $this->db,
            "SELECT lieferantId, erstelltAm FROM oci_nonces WHERE nonce = ?",
            [$nonce]
        );
        if (!$nonceRow) {
            http_response_code(403);
            $this->renderHookError('Ungültiger Sitzungs-Token.');
            exit;
        }
        // TTL prüfen (großzügig: NONCE_TTL * 2, da start() die Nonce schon verwendet hat)
        $age = time() - strtotime($nonceRow['erstelltAm']);
        if ($age > self::NONCE_TTL * 2) {
            http_response_code(403);
            $this->renderHookError('Sitzungs-Token abgelaufen.');
            exit;
        }

        // Nonce verbrauchen (Einmal-Verwendung für hook)
        $this->db->prepare("DELETE FROM oci_nonces WHERE nonce = ?")->execute([$nonce]);

        // NEW_ITEM-*[n] Parameter einlesen
        $items = [];
        $n = 1;
        while (true) {
            $key = "NEW_ITEM-DESCRIPTION[{$n}]";
            // Lieferanten schicken manchmal ohne eckige Klammern
            $descr = $_POST[$key] ?? $_POST["NEW_ITEM-DESCRIPTION_{$n}"] ?? null;
            if ($descr === null) break;

            $price     = (float)str_replace(',', '.', $_POST["NEW_ITEM-PRICE[{$n}]"]      ?? $_POST["NEW_ITEM-PRICE_{$n}"]      ?? '0');
            $priceUnit = max(1, (int)($_POST["NEW_ITEM-PRICEUNIT[{$n}]"]                  ?? $_POST["NEW_ITEM-PRICEUNIT_{$n}"]  ?? 1));
            $unit      = trim($_POST["NEW_ITEM-UNIT[{$n}]"]                               ?? $_POST["NEW_ITEM-UNIT_{$n}"]       ?? '');
            $quantity  = max(0.001, (float)str_replace(',', '.', $_POST["NEW_ITEM-QUANTITY[{$n}]"] ?? $_POST["NEW_ITEM-QUANTITY_{$n}"] ?? '1'));
            $matnr     = trim($_POST["NEW_ITEM-MATNR[{$n}]"]                             ?? $_POST["NEW_ITEM-MATNR_{$n}"]      ?? '');

            // EK berechnen (Preis dividiert durch Preiseinheit)
            $ek = $priceUnit > 1 ? round($price / $priceUnit, 4) : $price;

            // Bezeichnung aufbauen
            $bezeichnung = trim((string)$descr);
            if ($matnr !== '') {
                $bezeichnung .= ' [' . $matnr . ']';
            }

            $items[] = [
                'bezeichnung' => $bezeichnung,
                'einheit'     => self::mapUnit($unit),
                'ek'          => $ek,
                'anzahl'      => $quantity,
            ];
            $n++;
        }

        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);
        $count     = count($items);

        echo <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>OCI – Artikel werden übertragen</title>
  <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5}
  .box{text-align:center;padding:32px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.12)}
  p{color:#444;font-size:1rem}.ok{color:#2e7d32;font-size:1.1rem;font-weight:600}.err{color:#c62828}</style>
</head>
<body>
  <div class="box" id="msg">
    <p>Artikel werden übertragen…</p>
  </div>
  <script>
  (function(){
    var items = {$itemsJson};
    var count = {$count};
    try {
      if (window.opener && !window.opener.closed) {
        window.opener.postMessage(
          { type: 'oci_items', items: items },
          window.location.origin
        );
        document.getElementById('msg').innerHTML =
          '<p class="ok">✓ ' + count + ' Artikel übertragen. Dieses Fenster schließt sich automatisch.</p>';
        setTimeout(function(){ window.close(); }, 1500);
      } else {
        document.getElementById('msg').innerHTML =
          '<p class="err">Fehler: Ursprüngliches Fenster nicht erreichbar.<br>Bitte dieses Fenster schließen und erneut versuchen.</p>';
      }
    } catch(e) {
      document.getElementById('msg').innerHTML =
        '<p class="err">Fehler beim Übertragen: ' + e.message + '</p>';
    }
  })();
  </script>
</body>
</html>
HTML;
        exit;
    }

    // ══════════════════════════════════════════════════════════
    // HILFSMETHODEN
    // ══════════════════════════════════════════════════════════

    /** Gibt eine einfache Fehlerseite aus (für hook/start im HTML-Modus). */
    private function renderHookError(string $msg): void
    {
        // Content-Type ist bereits am Methoden-Anfang gesetzt
        $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        echo "<!DOCTYPE html><html lang=\"de\"><head><meta charset=\"utf-8\"><title>OCI Fehler</title></head>"
           . "<body style=\"font-family:sans-serif;padding:2rem\"><p style=\"color:#c62828\">OCI Fehler: {$safe}</p>"
           . "<p><button onclick=\"window.close()\">Fenster schließen</button></p></body></html>";
    }

    /**
     * Mappt OCI-Einheitencodes auf deutsche Abkürzungen.
     * Unbekannte Einheiten werden unverändert zurückgegeben.
     */
    private static function mapUnit(string $unit): string
    {
        $map = [
            'ST'  => 'Stk.',  'STK' => 'Stk.',  'PC'  => 'Stk.',
            'EA'  => 'Stk.',  'PCS' => 'Stk.',
            'M'   => 'm',     'LM'  => 'm',
            'M2'  => 'm²',    'QM'  => 'm²',
            'M3'  => 'm³',
            'KG'  => 'kg',    'G'   => 'g',
            'L'   => 'l',     'LTR' => 'l',
            'ROL' => 'Rolle',
            'PAK' => 'Pak.',  'PAC' => 'Pak.',  'PCK' => 'Pak.',
            'SET' => 'Set',
            'PR'  => 'Paar',  'PAR' => 'Paar',
        ];
        $upper = strtoupper(trim($unit));
        return $map[$upper] ?? ($unit !== '' ? $unit : 'Stk.');
    }
}
