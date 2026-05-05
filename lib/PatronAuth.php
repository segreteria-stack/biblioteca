<?php
// lib/PatronAuth.php — autenticazione Patron unificata
// 1) preferisce patron_auth.pass_hash (password_hash/password_verify)
// 2) fallback su member.pass_user (MD5) per compatibilità storica
// 3) upgrade automatico: se login via MD5, crea/aggiorna patron_auth
class PatronAuth
{
  public static function user(): ?array
  {
    return $_SESSION['patron'] ?? null;
  }

  /**
   * Valida la complessità della password.
   * Restituisce null se valida, oppure una stringa con il messaggio di errore.
   */
  public static function validatePassword(string $password): ?string
  {
    if (strlen($password) < 8) {
      return 'La password deve avere almeno 8 caratteri.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
      return 'La password deve contenere almeno una lettera maiuscola.';
    }
    if (!preg_match('/[0-9]/', $password)) {
      return 'La password deve contenere almeno un numero.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
      return 'La password deve contenere almeno un carattere speciale (es. ! @ # $ % &).';
    }
    return null;
  }

  public static function login(PDO $db, array $T, string $login, string $password): bool
  {
    $memberTbl = $T['member'] ?? 'member';
    $authTbl   = $T['patron_auth'] ?? 'patron_auth';
    // 1) Tentativo moderno: patron_auth + member (email o barcode)
    $st = $db->prepare("
      SELECT pa.mbrid, pa.email AS auth_email, pa.pass_hash,
             m.first_name, m.last_name, m.email AS member_email, m.barcode_nmbr
      FROM {$authTbl} pa
      JOIN {$memberTbl} m ON m.mbrid = pa.mbrid
      WHERE pa.email = ? OR m.barcode_nmbr = ?
      LIMIT 1
    ");
    $st->execute([$login, $login]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if ($u && !empty($u['pass_hash']) && password_verify($password, (string)$u['pass_hash'])) {
      session_regenerate_id(true);
      $_SESSION['patron'] = [
        'mbrid' => (int)$u['mbrid'],
        'name'  => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
        'email' => $u['auth_email'] ?? $u['member_email'] ?? null,
      ];
      $_SESSION['_last_activity'] = time();
      return true;
    }
    // 2) Fallback storico: member.pass_user MD5 (barcode o email)
    $st2 = $db->prepare("
      SELECT mbrid, barcode_nmbr, first_name, last_name, email, pass_user
      FROM {$memberTbl}
      WHERE barcode_nmbr = ? OR email = ?
      LIMIT 1
    ");
    $st2->execute([$login, $login]);
    $m = $st2->fetch(PDO::FETCH_ASSOC);
    if ($m && strtolower((string)($m['pass_user'] ?? '')) === md5($password)) {
      $email = $m['email'] ?? null;
      session_regenerate_id(true);
      $_SESSION['patron'] = [
        'mbrid' => (int)$m['mbrid'],
        'name'  => trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')),
        'email' => $email,
      ];
      $_SESSION['_last_activity'] = time();
      // 3) Upgrade automatico a password_hash
      if ($email) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $up = $db->prepare("UPDATE {$authTbl} SET email = ?, pass_hash = ? WHERE mbrid = ?");
        $up->execute([$email, $newHash, (int)$m['mbrid']]);
        if ($up->rowCount() === 0) {
          try {
            $ins = $db->prepare("INSERT INTO {$authTbl} (mbrid, email, pass_hash) VALUES (?, ?, ?)");
            $ins->execute([(int)$m['mbrid'], $email, $newHash]);
          } catch (Throwable $e) {
            // se esiste già per vincoli unici, ignoriamo
          }
        }
      }
      return true;
    }
    return false;
  }

  public static function logout(): void
  {
    unset($_SESSION['patron']);
  }
}
