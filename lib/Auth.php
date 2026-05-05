<?php
// lib/Auth.php — Login staff usando tabella Espabiblio `staff` (username + md5)
class Auth {
  private PDO $db; private array $T;
  public function __construct(PDO $db, array $tables = []) { $this->db=$db; $this->T=$tables + ['staff'=>'staff']; }

  public function login(string $username, string $password): bool {
    $st = $this->db->prepare("SELECT userid, username, pwd, last_name, first_name, admin_flg, catalog_flg, circ_flg FROM {$this->T['staff']} WHERE username=? LIMIT 1");
    $st->execute([$username]);
    $u = $st->fetch();
    if ($u && strtolower($u['pwd']) === md5($password)) {
      $role = 'read';
      if (($u['admin_flg'] ?? '') === 'Y') $role='admin';
      elseif (($u['catalog_flg'] ?? '') === 'Y') $role='catalog';
      elseif (($u['circ_flg'] ?? '') === 'Y') $role='circulation';
      $_SESSION['staff'] = [
        'id' => (int)$u['userid'],
        'name' => trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')) ?: $u['username'],
        'username' => $u['username'],
        'role' => $role,
      ];
      return true;
    }
    return false;
  }
  public function logout(): void { unset($_SESSION['staff']); }
  public function check(): bool { return !empty($_SESSION['staff']); }
  public function user(): ?array { return $_SESSION['staff'] ?? null; }
  public function role(): string { return $this->user()['role'] ?? 'guest'; }
  public function can(string $need): bool {
    $map = [
      'admin'       => ['admin'],
      'catalog'     => ['admin','catalog'],
      'circulation' => ['admin','circulation'],
      'read'        => ['admin','catalog','circulation','read'],
    ];
    $role = $this->role();
    foreach ($map[$need] ?? [] as $ok) if ($role === $ok) return true;
    return false;
  }
}
