<?php
declare(strict_types=1);

require __DIR__ . '/common.php';

$format = is_string($_GET['format'] ?? null) ? strtolower($_GET['format']) : 'xml';
if (!in_array($format, ['xml', 'bz2'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Nieznany format. Użyj ?format=xml lub ?format=bz2.\n");
}

security_headers();
try {
    $hubs = db()->query(
        "SELECT name,protocol,host,port,description,online_users,shared_bytes,country,pinger_status
         FROM hubs WHERE status='approved' ORDER BY name ASC"
    )->fetchAll();
    $xml = build_hublist_xml($hubs);
    if ($format === 'bz2') {
        if (!function_exists('bzcompress')) {
            throw new RuntimeException('Kompresja BZip2 nie jest włączona na serwerze.');
        }
        $body = bzcompress($xml, 9);
        if (!is_string($body)) {
            throw new RuntimeException('Nie udało się skompresować listy hubów.');
        }
        header('Content-Type: application/x-bzip2');
        header('Content-Disposition: attachment; filename="hublist.xml.bz2"');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: public, max-age=300');
        echo $body;
        exit;
    }

    header('Content-Type: text/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="hublist.xml"');
    header('Cache-Control: public, max-age=300');
    echo $xml;
} catch (Throwable $exception) {
    error_log('Hublist XML feed failed: ' . $exception->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Nie można wygenerować feedu hublisty. Sprawdź konfigurację serwera.\n";
}
