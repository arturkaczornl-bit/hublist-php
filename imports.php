<?php
declare(strict_types=1);

function public_hub_sources(): array
{
    return [
        [
            'id' => 'te-home',
            'name' => 'Team Elite',
            'url' => 'https://www.te-home.net/?do=hublist&get=hublist.xml',
            'format' => 'xml',
        ],
        [
            'id' => 'dchublist-org',
            'name' => 'dchublist.org',
            'url' => 'https://dchublist.org/hublist.xml',
            'format' => 'xml',
        ],
        [
            'id' => 'pwiam',
            'name' => 'Public DC Hublist (PWiAM)',
            'url' => 'https://hublist.pwiam.com/hublist.json',
            'format' => 'json',
        ],
        [
            'id' => 'dchublist-biz',
            'name' => 'dchublist.biz',
            'url' => 'https://dchublist.biz/?do=hublist&get=hublist.xml',
            'format' => 'xml',
        ],
        [
            'id' => 'dchublists-com',
            'name' => 'dchublists.com',
            'url' => 'https://dchublists.com/?do=hublist&get=hublist.xml',
            'format' => 'xml',
        ],
    ];
}

function fetch_public_hub_sources(): array
{
    if (!function_exists('curl_multi_init') || !class_exists(DOMDocument::class)) {
        throw new RuntimeException('Import wymaga rozszerzeń PHP cURL i DOM.');
    }

    $multi = curl_multi_init();
    $requests = [];
    $bodies = [];
    foreach (public_hub_sources() as $source) {
        $handle = curl_init($source['url']);
        if ($handle === false) {
            continue;
        }
        $bodies[$source['id']] = '';
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Hublist-PHP/1.0 (verified public hublist import)',
            CURLOPT_HTTPHEADER => ['Accept: application/xml, application/json'],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$bodies, $source): int {
                $bodies[$source['id']] .= $chunk;
                return strlen($bodies[$source['id']]) <= 4194304 ? strlen($chunk) : 0;
            },
        ]);
        curl_multi_add_handle($multi, $handle);
        $requests[$source['id']] = ['handle' => $handle, 'source' => $source];
    }

    $active = 0;
    do {
        do {
            $status = curl_multi_exec($multi, $active);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        if ($active > 0) {
            $selected = curl_multi_select($multi, 1.0);
            if ($selected === -1) {
                usleep(100000);
            }
        }
    } while ($active > 0 && $status === CURLM_OK);

    $results = [];
    foreach ($requests as $id => $request) {
        $handle = $request['handle'];
        $source = $request['source'];
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
        if ($status !== CURLM_OK || $error !== '' || $httpCode < 200 || $httpCode >= 300) {
            $results[$id] = ['source' => $source, 'error' => $error ?: 'HTTP ' . $httpCode];
            continue;
        }
        try {
            $hubs = parse_public_hub_feed($bodies[$id], $source);
            $results[$id] = ['source' => $source, 'hubs' => $hubs];
        } catch (Throwable $exception) {
            $results[$id] = ['source' => $source, 'error' => $exception->getMessage()];
        }
    }
    curl_multi_close($multi);
    return $results;
}

function parse_public_hub_feed(string $body, array $source): array
{
    if (strlen($body) > 4194304) {
        throw new RuntimeException('Lista przekroczyła limit rozmiaru.');
    }
    if ($source['format'] === 'json') {
        $document = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!isset($document['hublist']) || !is_array($document['hublist'])) {
            throw new RuntimeException('W źródle JSON brakuje listy hublist.');
        }
        $items = $document['hublist'];
    } else {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException('Nieprawidłowy dokument XML.');
        }
        $items = [];
        foreach ($document->getElementsByTagName('Hub') as $element) {
            $item = [];
            foreach ($element->attributes as $attribute) {
                $item[strtolower($attribute->nodeName)] = $attribute->nodeValue;
            }
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $item[strtolower($child->tagName)] = $child->textContent;
                }
            }
            $items[] = $item;
        }
    }

    $hubs = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $fields = [];
        foreach ($item as $key => $value) {
            if (is_scalar($value)) {
                $fields[strtolower((string) $key)] = trim((string) $value);
            }
        }
        $address = $fields['address'] ?? '';
        if ($address === '' || stripos($address, 'do not connect') !== false) {
            continue;
        }
        $parts = parse_url($address);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            continue;
        }
        $protocol = match (strtolower($parts['scheme'])) {
            'adc' => 'ADC',
            'adcs' => 'ADCS',
            'nmdc' => 'NMDC',
            'nmdcs' => 'NMDCS',
            'dchub' => 'DCHUB',
            default => '',
        };
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($protocol === '' || !valid_host($host)) {
            continue;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : protocol_default_port($protocol);
        if ($port < 1 || $port > 65535) {
            continue;
        }
        $name = trim((string) ($fields['name'] ?? $address));
        if ($name === '') {
            $name = $address;
        }

        $hubs[] = [
            'name' => utf8_truncate($name, 150),
            'protocol' => $protocol,
            'host' => substr($host, 0, 253),
            'port' => $port,
            'country' => isset($fields['country']) && preg_match('/^[a-z]{2}$/i', $fields['country'])
                ? strtoupper($fields['country'])
                : null,
            'description' => isset($fields['description']) ? utf8_truncate($fields['description'], 5000) : null,
            'software' => isset($fields['software']) ? utf8_truncate($fields['software'], 120) : null,
        ];
    }
    return $hubs;
}

function import_public_hubs(PDO $pdo): array
{
    $feeds = fetch_public_hub_sources();
    $available = 0;
    $newHubs = 0;
    $sourceLinks = 0;
    $errors = [];
    $findHub = $pdo->prepare("SELECT id,status FROM hubs WHERE host=? AND port=? AND protocol=? AND status <> 'rejected' LIMIT 1");
    $insertHub = $pdo->prepare(
        "INSERT INTO hubs (name,protocol,host,port,country,description,software,status)
         VALUES (?,?,?,?,?,?,?,'pending')"
    );
    $insertSource = $pdo->prepare(
        'INSERT IGNORE INTO hub_sources (hub_id,source_id,source_name,feed_url) VALUES (?,?,?,?)'
    );

    $pdo->beginTransaction();
    try {
        foreach ($feeds as $feed) {
            if (isset($feed['error'])) {
                $errors[] = $feed['source']['name'] . ': ' . $feed['error'];
                continue;
            }
            $available++;
            $source = $feed['source'];
            foreach ($feed['hubs'] as $hub) {
                $findHub->execute([$hub['host'], $hub['port'], $hub['protocol']]);
                $existing = $findHub->fetch();
                if ($existing) {
                    $hubId = (int) $existing['id'];
                } else {
                    $insertHub->execute([
                        $hub['name'], $hub['protocol'], $hub['host'], $hub['port'],
                        $hub['country'], $hub['description'], $hub['software'],
                    ]);
                    $hubId = (int) $pdo->lastInsertId();
                    $newHubs++;
                }
                $insertSource->execute([$hubId, $source['id'], $source['name'], $source['url']]);
                $sourceLinks += $insertSource->rowCount();
            }
        }
        if ($available === 0) {
            throw new RuntimeException('Żadne publiczne źródło nie odpowiedziało; nie dodano hubów. Spróbuj ponownie później.');
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'feeds' => $available,
        'new_hubs' => $newHubs,
        'source_links' => $sourceLinks,
        'errors' => $errors,
    ];
}
