<?php
// Връзка с docs.rtdalia.com (doc-transcriber-app). Използва същия вход като
// Android скенера: POST /api/analyze-doc със суровите байтове на файла;
// сървърът там прави анализа с Gemini и слага документа във входящите,
// откъдето уеб приложението го взима при следващо зареждане.

function transcriber_base(): string {
    return rtrim(TRANSCRIBER_URL, '/');
}

function transcriber_send(string $bytes, string $filename, string $clientDocId): array {
    $url = transcriber_base() . '/api/analyze-doc?' . http_build_query([
        'filename'    => $filename,
        'clientDocId' => $clientDocId,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $bytes,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/octet-stream', 'X-Sync-Token: ' . TRANSCRIBER_PIN],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 180, // анализът с Gemini отнема до ~2 минути
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => "Няма връзка с docs ($err)", 'retryable' => true];
    }
    $json = json_decode((string)$body, true);
    if ($code === 200 && is_array($json) && !empty($json['success'])) {
        return ['ok' => true] + $json;
    }
    if ($code === 401) {
        return ['ok' => false, 'error' => 'Грешен PIN за docs (TRANSCRIBER_PIN в config.php).', 'retryable' => false];
    }
    $msg = is_array($json) && !empty($json['error']) ? (string)$json['error'] : "HTTP $code";
    if ($msg === 'no_gemini_key') $msg = 'В docs.rtdalia.com не е въведен Gemini API ключ (Настройки там).';
    $retryable = is_array($json) && isset($json['retryable']) ? (bool)$json['retryable'] : ($code === 0 || $code >= 500);
    return ['ok' => false, 'error' => $msg, 'retryable' => $retryable];
}

// Проверка на адреса и PIN-а (без да създава документ).
function transcriber_check(): array {
    $ch = curl_init(transcriber_base() . '/api/state-version');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['X-Sync-Token: ' . TRANSCRIBER_PIN],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'error' => "Няма връзка ($err)"];
    if ($code === 401) return ['ok' => false, 'error' => 'Грешен PIN (TRANSCRIBER_PIN в config.php).'];
    $json = json_decode((string)$body, true);
    if ($code === 200 && !empty($json['success'])) return ['ok' => true];
    return ['ok' => false, 'error' => "Неочакван отговор (HTTP $code)"];
}
