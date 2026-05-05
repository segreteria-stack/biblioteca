<?php
declare(strict_types=1);

final class EmailService
{
    private array $mailCfg;
    private string $projectRoot;

    public function __construct(array $cfg, string $projectRoot)
    {
        $this->mailCfg = (isset($cfg['mail']) && is_array($cfg['mail'])) ? $cfg['mail'] : [];
        $this->projectRoot = rtrim($projectRoot, '/\\');
    }

    public function isEnabled(): bool
    {
        return !empty($this->mailCfg['enabled']);
    }

    /**
     * $template es: "patron/reset_password" -> templates/email/patron/reset_password.php
     */
    public function send(string $to, string $subject, string $template, array $data = []): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $to = trim($to);
        if ($to === '') {
            return false;
        }

        $fromEmail = (string)($this->mailCfg['from_email'] ?? '');
        $fromName  = (string)($this->mailCfg['from_name'] ?? '');

        if ($fromEmail === '') {
            return false;
        }

        $html = $this->renderTemplate($template, $data);
        if ($html === '') {
            // template mancante o vuoto: non inviamo email “rotte”
            return false;
        }

        $driver = strtolower((string)($this->mailCfg['driver'] ?? 'mail'));

        if ($driver === 'smtp') {
            return $this->sendViaSmtp($to, $subject, $html, $fromEmail, $fromName, $template);
        }

        return $this->sendViaMail($to, $subject, $html, $fromEmail, $fromName, $template);
    }

    private function sendViaSmtp(
        string $to,
        string $subject,
        string $html,
        string $fromEmail,
        string $fromName,
        string $template
    ): bool {
        // Caricamento PHPMailer (installazione manuale in lib/vendor/PHPMailer/)
        $phpMailerBase = $this->projectRoot . '/lib/vendor/PHPMailer/';

        $ex = $phpMailerBase . 'Exception.php';
        $pm = $phpMailerBase . 'PHPMailer.php';
        $st = $phpMailerBase . 'SMTP.php';

        if (!is_file($ex) || !is_file($pm) || !is_file($st)) {
            // PHPMailer non trovato
            return false;
        }

        require_once $ex;
        require_once $pm;
        require_once $st;

        // Namespace PHPMailer
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $host   = (string)($this->mailCfg['host'] ?? '');
            $port   = (int)($this->mailCfg['port'] ?? 587);
            $secure = strtolower((string)($this->mailCfg['secure'] ?? 'tls')); // 'tls'|'ssl'|''
            $user   = (string)($this->mailCfg['username'] ?? '');
            $pass   = (string)($this->mailCfg['password'] ?? '');

            if ($host === '' || $user === '' || $pass === '') {
                return false;
            }

            $mailer->isSMTP();
            $mailer->Host       = $host;
            $mailer->Port       = $port;
            $mailer->SMTPAuth   = true;
            $mailer->Username   = $user;
            $mailer->Password   = $pass;

            if ($secure === 'tls') {
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($secure === 'ssl') {
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mailer->SMTPSecure = false;
                $mailer->SMTPAutoTLS = false;
            }

            // Impostazioni messaggio
            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom($fromEmail, $fromName !== '' ? $fromName : $fromEmail);
            $mailer->addAddress($to);
            $mailer->Subject = $subject;

            // HTML + alternative plain-text
            $mailer->isHTML(true);
            $mailer->Body    = $html;
            $mailer->AltBody = $this->htmlToText($html);

            // Header utili (diagnostica)
            $mailer->addCustomHeader('X-OPAC-Mailer', 'EmailService');
            $mailer->addCustomHeader('X-OPAC-Template', $this->safeHeaderValue($template));

            // Invia
            return $mailer->send();
        } catch (\Throwable $e) {
            // Se vuoi, qui possiamo aggiungere logging su file o DB in micro-step successivo
            return false;
        }
    }

    private function sendViaMail(
        string $to,
        string $subject,
        string $html,
        string $fromEmail,
        string $fromName,
        string $template
    ): bool {
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . $this->formatFrom($fromName, $fromEmail);
        $headers[] = 'X-OPAC-Mailer: EmailService';
        $headers[] = 'X-OPAC-Template: ' . $this->safeHeaderValue($template);

        // Nota: niente -f qui; con Workspace/DMARC può peggiorare.
        return @mail($to, $subject, $html, implode("\r\n", $headers));
    }

    private function renderTemplate(string $template, array $data): string
    {
        $rel = str_replace(['..', '\\'], ['', '/'], $template);
        $path = $this->projectRoot . '/templates/email/' . $rel . '.php';

        if (!is_file($path)) {
            // template mancante: fallimento “pulito”
            return '';
        }

        // variabili disponibili nel template
        extract($data, EXTR_SKIP);

        // helper h() se non esiste
        if (!function_exists('h')) {
            function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
        }

        ob_start();
        require $path;
        return (string)ob_get_clean();
    }

    private function formatFrom(string $name, string $email): string
    {
        $name = trim($name);
        $safeName = str_replace(["\r", "\n"], '', $name);
        $safeEmail = str_replace(["\r", "\n"], '', $email);

        if ($safeName === '') {
            return $safeEmail;
        }
        return sprintf('"%s" <%s>', addslashes($safeName), $safeEmail);
    }

    private function htmlToText(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // normalizza spazi
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\r\n|\r|\n/", "\n", $text);
        return trim((string)$text);
    }

    private function safeHeaderValue(string $v): string
    {
        return str_replace(["\r", "\n"], '', $v);
    }
}
