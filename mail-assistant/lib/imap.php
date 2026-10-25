<?php
// Минимален IMAP клиент върху SSL сокет — без нужда от PHP разширението imap
// (премахнато от PHP 8.4 и често липсващо на споделен хостинг).

class ImapError extends RuntimeException {}

class Imap {
    private $fp;
    private int $tag = 0;
    private array $caps = [];

    public function __construct(string $host, int $port = 993, int $timeout = 60) {
        $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
        $this->fp = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->fp) {
            throw new ImapError("Няма връзка с пощенския сървър $host:$port ($errstr)");
        }
        stream_set_timeout($this->fp, $timeout);
        $greeting = $this->readLine();
        if (!preg_match('/^\* (OK|PREAUTH)/i', $greeting)) {
            throw new ImapError('Неочакван отговор от пощенския сървър: ' . trim($greeting));
        }
        if (preg_match('/\[CAPABILITY ([^\]]*)\]/i', $greeting, $m)) {
            $this->caps = array_map('strtoupper', explode(' ', $m[1]));
        }
    }

    public function login(string $user, string $pass): void {
        try {
            $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($pass));
        } catch (ImapError $e) {
            throw new ImapError('Неуспешен вход в пощата: ' . $e->getMessage()
                . (stripos(IMAP_HOST, 'gmail') !== false ? ' (за Gmail е нужна „Парола за приложения“)' : ''));
        }
        // Списъкът с възможности може да се промени след вход.
        foreach ($this->command('CAPABILITY') as $r) {
            if (strtoupper($r[1] ?? '') === 'CAPABILITY') {
                $this->caps = array_map('strtoupper', array_slice($r, 2));
            }
        }
    }

    public function hasCap(string $cap): bool {
        return in_array(strtoupper($cap), $this->caps, true);
    }

    // Отваря папка само за четене; връща UIDVALIDITY.
    public function examine(string $folder): int {
        $uidv = 0;
        foreach ($this->command('EXAMINE ' . self::quote(mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8'))) as $r) {
            if (strtoupper($r[1] ?? '') === 'OK' && preg_match('/^\[UIDVALIDITY (\d+)\]$/i', (string)($r[2] ?? ''), $m)) {
                $uidv = (int)$m[1];
            }
        }
        return $uidv;
    }

    /** @return int[] */
    public function search(string $criteria): array {
        $uids = [];
        foreach ($this->command("UID SEARCH $criteria") as $r) {
            if (strtoupper($r[1] ?? '') === 'SEARCH') {
                foreach (array_slice($r, 2) as $n) $uids[] = (int)$n;
            }
        }
        return $uids;
    }

    /**
     * UID FETCH; връща [uid => [ИМЕ_НА_ПОЛЕ => стойност]].
     * @param int[] $uids
     */
    public function fetch(array $uids, string $items): array {
        if (!$uids) return [];
        $out = [];
        foreach ($this->command('UID FETCH ' . implode(',', $uids) . " ($items)") as $r) {
            if (strtoupper($r[2] ?? '') !== 'FETCH' || !is_array($r[3] ?? null)) continue;
            $f = [];
            $list = $r[3];
            for ($i = 0; $i + 1 < count($list); $i += 2) {
                $f[strtoupper((string)$list[$i])] = $list[$i + 1];
            }
            if (isset($f['UID'])) $out[(int)$f['UID']] = $f;
        }
        return $out;
    }

    // Суровото (още кодирано) съдържание на една MIME част, без да маркира
    // писмото като прочетено. $length ограничава броя байтове (за преглед).
    public function fetchSection(int $uid, string $section, ?int $length = null): ?string {
        $partial = $length !== null ? "<0.$length>" : '';
        $rows = $this->fetch([$uid], "BODY.PEEK[$section]$partial");
        if (!isset($rows[$uid])) return null;
        foreach ($rows[$uid] as $k => $v) {
            if (strpos($k, "BODY[$section]") === 0) return $v === null ? '' : (string)$v;
        }
        return null;
    }

    public function logout(): void {
        if (!$this->fp) return;
        try { $this->command('LOGOUT'); } catch (Throwable $e) { /* без значение */ }
        @fclose($this->fp);
        $this->fp = null;
    }

    public function __destruct() {
        if ($this->fp) @fclose($this->fp);
    }

    // ---- протокол ----

    /** Изпраща команда и връща непомаркираните (*) отговори като списъци. */
    private function command(string $cmd): array {
        $tag = 'A' . (++$this->tag);
        $this->write("$tag $cmd\r\n");
        $untagged = [];
        while (true) {
            [$line, $literals] = $this->readResponse();
            if (strncmp($line, "$tag ", strlen($tag) + 1) === 0) {
                $rest = substr($line, strlen($tag) + 1);
                if (stripos($rest, 'OK') !== 0) {
                    throw new ImapError(trim(preg_replace('/^(NO|BAD)\s*/i', '', $rest)));
                }
                return $untagged;
            }
            if (strncmp($line, '* ', 2) === 0) {
                $untagged[] = self::parse($line, $literals);
            }
            // Отговори „+“ (продължение) не се използват.
        }
    }

    private function write(string $data): void {
        while ($data !== '') {
            $n = @fwrite($this->fp, $data);
            if ($n === false || $n === 0) throw new ImapError('Връзката с пощенския сървър прекъсна.');
            $data = substr($data, $n);
        }
    }

    private function readLine(): string {
        $line = fgets($this->fp);
        if ($line === false) {
            $meta = stream_get_meta_data($this->fp);
            throw new ImapError(!empty($meta['timed_out'])
                ? 'Пощенският сървър не отговаря (изтекло време).'
                : 'Връзката с пощенския сървър прекъсна.');
        }
        return $line;
    }

    private function readBytes(int $n): string {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->fp, min(65536, $n - strlen($buf)));
            if ($chunk === false || ($chunk === '' && feof($this->fp))) {
                throw new ImapError('Връзката с пощенския сървър прекъсна.');
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($this->fp);
                if (!empty($meta['timed_out'])) throw new ImapError('Пощенският сървър не отговаря (изтекло време).');
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    // Един логически отговор: ред, в който литералите {n} са заменени с
    // маркери \0<индекс>\0, а съдържанието им е в отделен масив.
    private function readResponse(): array {
        $text = '';
        $literals = [];
        while (true) {
            $line = $this->readLine();
            if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                $text .= substr($line, 0, -strlen($m[0])) . "\0" . count($literals) . "\0";
                $literals[] = $this->readBytes((int)$m[1]);
                continue;
            }
            return [$text . rtrim($line, "\r\n"), $literals];
        }
    }

    public static function parse(string $s, array $literals = []): array {
        $i = 0;
        return self::parseList($s, $i, $literals, null);
    }

    // Атомите стават низове, NIL — null, (…) — вложени масиви. Квадратните
    // скоби остават част от атома: BODY[HEADER.FIELDS (SUBJECT)], [UIDVALIDITY 5].
    private static function parseList(string $s, int &$i, array $literals, ?string $close): array {
        $out = [];
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if ($c === ' ') { $i++; continue; }
            if ($close !== null && $c === $close) { $i++; return $out; }
            if ($c === '(') {
                $i++;
                $out[] = self::parseList($s, $i, $literals, ')');
                continue;
            }
            if ($c === '"') {
                $i++;
                $buf = '';
                while ($i < $n && $s[$i] !== '"') {
                    if ($s[$i] === '\\' && $i + 1 < $n) $i++;
                    $buf .= $s[$i++];
                }
                $i++;
                $out[] = $buf;
                continue;
            }
            if ($c === "\0") {
                $j = strpos($s, "\0", $i + 1);
                $out[] = $literals[(int)substr($s, $i + 1, $j - $i - 1)] ?? '';
                $i = $j + 1;
                continue;
            }
            $buf = '';
            while ($i < $n) {
                $c = $s[$i];
                if ($c === '[') {
                    $j = strpos($s, ']', $i);
                    if ($j === false) $j = $n - 1;
                    $buf .= substr($s, $i, $j - $i + 1);
                    $i = $j + 1;
                    continue;
                }
                if ($c === ' ' || $c === '(' || $c === ')' || $c === '"') break;
                $buf .= $c;
                $i++;
            }
            if ($buf === '') { $i++; continue; } // неочакван символ — пропуска се
            $out[] = strtoupper($buf) === 'NIL' ? null : $buf;
        }
        return $out;
    }

    public static function quote(string $s): string {
        return '"' . addcslashes($s, "\\\"") . '"';
    }
}
