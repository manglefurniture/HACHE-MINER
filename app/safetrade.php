<?php
declare(strict_types=1);
/**
 * SafeTrade REST client: intentionally GET-only.
 * No credentials or response bodies are ever written to logs.
 * Not enabled until authenticated API paths are validated against a real read-only key.
 */
function miner_safetrade_signature(string $nonce, string $key, string $secret): string {
    return hash_hmac('sha256', $nonce.$key, $secret);
}
function miner_safetrade_allowed_path(string $path): bool {
    return in_array($path, [
        '/trade/account/members/me',
        '/trade/account/balances',
        '/trade/account/deposits',
        '/trade/account/withdraws',
        '/trade/market/trades',
    ], true);
}
function miner_safetrade_read(string $path, array $query=[], ?callable $transport=null): array {
    if (!miner_safetrade_allowed_path($path)) throw new InvalidArgumentException('SafeTrade path not authorized for read-only client');
    foreach ($query as $name=>$value) {
        if (!is_string($name) || !preg_match('/^[a-z_]{1,35}$/D', $name) || !is_scalar($value)) {
            throw new InvalidArgumentException('Invalid SafeTrade query');
        }
    }
    $key=miner_get_secret('safetrade:api-key');
    $secret=miner_get_secret('safetrade:api-secret');
    if ($key===null || $secret===null) throw new RuntimeException('SafeTrade credentials not configured');
    $nonce=(string)floor(microtime(true)*1000);
    $headers=[
        'X-Auth-Apikey: '.$key,
        'X-Auth-Nonce: '.$nonce,
        'X-Auth-Signature: '.miner_safetrade_signature($nonce,$key,$secret),
        'Accept: application/json',
    ];
    $url='https://safe.trade/api/v2'.$path;
    if ($query) $url.='?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    if ($transport!==null) return $transport($url,$headers);
    $ch=curl_init($url);
    if ($ch===false) throw new RuntimeException('SafeTrade connection failed');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_TIMEOUT=>12,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_MAXREDIRS=>0,
    ]);
    try {
        $response=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    } finally {
        curl_close($ch);
    }
    if (!is_string($response) || $status!==200) {
        // Do not expose provider bodies, HTTP headers, keys, or signatures.
        throw new RuntimeException('SafeTrade read failed (HTTP '.($status ?: 'transport').')');
    }
    if (strlen($response)>2000000) throw new RuntimeException('SafeTrade response too large');
    $decoded=json_decode($response,true,64,JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException('SafeTrade response invalid');
    return $decoded;
}
