<?php
declare(strict_types=1);

require __DIR__ . '/pinger.php';
require_once __DIR__ . '/imports.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$input = [
    'name' => 'Żółty hub',
    'protocol' => 'nmdc',
    'host' => 'hub.example.org',
    'port' => '',
    'country' => 'pl',
];
$hub = normalize_hub_input($input);
expect($hub['port'] === 411, 'NMDC default port should be 411.');
expect($hub['country'] === 'PL', 'Country code should be normalized.');
expect(utf8_length('Żółć 🎯') === 6, 'UTF-8 length should count characters.');
expect(utf8_truncate('Żółć 🎯x', 6) === 'Żółć 🎯', 'UTF-8 truncation should not split a character.');
expect(valid_host('hub.example.org'), 'Public host name should be accepted.');
expect(!valid_host('127.0.0.1'), 'Loopback address should be rejected.');
expect(base32_encode_bytes('f') === 'MY', 'Base32 encoding should match RFC 4648.');
expect(decode_adc_text('A\\sB\\nC\\\\D') === "A B\nC\\D", 'ADC escape sequences should be decoded.');
$xml = build_hublist_xml([[
    'name' => 'Żółty & biały',
    'protocol' => 'ADC',
    'host' => 'hub.example.org',
    'port' => 1511,
    'description' => 'Opis z & oraz "cudzysłowem"' . "\x01",
    'country' => 'PL',
    'online_users' => 7,
    'shared_bytes' => 1024,
    'pinger_status' => 'online',
]]);
$parsedXml = parse_public_hub_feed($xml, ['format' => 'xml']);
expect(count($parsedXml) === 1, 'Generated XML should be accepted as a hublist feed.');
expect($parsedXml[0]['name'] === 'Żółty & biały' && $parsedXml[0]['protocol'] === 'ADC', 'Generated XML fields should be escaped and round-trip correctly.');
expect(!str_contains($xml, "\x01"), 'Generated XML should omit characters forbidden by XML 1.0.');
if (function_exists('bzcompress') && function_exists('bzdecompress')) {
    $compressed = bzcompress($xml, 9);
    expect(is_string($compressed) && bzdecompress($compressed) === $xml, 'BZip2 feed should round-trip correctly.');
}
$feed = json_encode([
    'hublist' => [
        ['address' => 'adc://hub.example.org:1511', 'name' => 'Żółty hub', 'country' => 'pl'],
        ['address' => 'nmdc://127.0.0.1:411', 'name' => 'Private hub'],
    ],
], JSON_THROW_ON_ERROR);
$parsedHubs = parse_public_hub_feed($feed, ['format' => 'json']);
expect(count($parsedHubs) === 1, 'Private hub addresses should not be imported.');
expect($parsedHubs[0]['protocol'] === 'ADC' && $parsedHubs[0]['name'] === 'Żółty hub', 'JSON feed fields should be normalized.');

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($server === false) {
    throw new RuntimeException('Could not create the local NMDC test server: ' . $errorMessage, $errorCode);
}
$address = stream_socket_get_name($server, false);
$client = stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 1);
$peer = stream_socket_accept($server, 1);
fclose($server);
if ($client === false || $peer === false) {
    throw new RuntimeException('Could not connect to the local NMDC test server: ' . $errorMessage, $errorCode);
}
$server = $peer;
$messages = '$Lock EXTENDEDPROTOCOLABCABCABCABC Pk=Hub|'
    . '$HubName Test Hub|$HubTopic Protocol test|$Hello TestBot|$Hello User1|'
    . '$MyINFO $ALL User1 description$ $DSL$email$123$|$NickList User1$$User2$$|';
fwrite($server, $messages);
$result = ping_nmdc($client, 'TestBot', (int) microtime(true));
fclose($server);
fclose($client);
expect($result['hub_name'] === 'Test Hub', 'NMDC hub name should be parsed.');
expect($result['hub_topic'] === 'Protocol test', 'NMDC topic should be parsed.');
expect($result['online_users'] === 2, 'NMDC nick list should count unique users.');
expect($result['shared_bytes'] === 123, 'NMDC user share should be parsed.');

echo "Protocol smoke tests passed.\n";
