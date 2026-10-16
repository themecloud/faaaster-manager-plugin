<?php

// Self-calls over HTTPS: for a loopback host of the pod, the WordPress HTTP API
// verifies against the system bundle (which holds the pod's self-signed loopback
// certificate) instead of WordPress's own ca-bundle.crt.
// Run: php test/loopback-tls.php

require_once __DIR__ . '/../class/loopback-tls.php';

$failures = 0;
function check_tls($label, $actual, $expected)
{
    global $failures;
    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, "FAIL {$label}: got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n");
        return;
    }
    echo "OK {$label}\n";
}

// /etc/hosts as kubelet writes it in a site pod (inst123562, 13/10/2026).
$etcHosts = <<<HOSTS
# Kubernetes-managed hosts file.
127.0.0.1	localhost
::1	localhost ip6-localhost ip6-loopback
fe00::0	ip6-localnet
10.4.34.5	inst123562-0.inst123562.default.svc.cluster.local	inst123562-0

# Entries added by HostAliases.
127.0.0.1	graazie.com	www.graazie.com # trailing comment
127.0.0.1 Shop.Graazie.com
HOSTS;

$hosts = FaaasterLoopbackTls::loopbackHosts($etcHosts);
check_tls('loopback hosts of the pod', $hosts, array('localhost', 'graazie.com', 'www.graazie.com', 'shop.graazie.com'));
check_tls('no hosts in an empty file', FaaasterLoopbackTls::loopbackHosts(''), array());

$wpBundle = '/app/www/wp-includes/certificates/ca-bundle.crt';
$system = '/etc/ssl/certs/ca-certificates.crt';
$defaults = array('sslverify' => true, 'sslcertificates' => $wpBundle);
$run = function ($url, $args = null, $readable = true) use ($hosts, $wpBundle, $system, $defaults) {
    return FaaasterLoopbackTls::withSystemBundle($args === null ? $defaults : $args, $url, $hosts, $wpBundle, $system, $readable);
};

$own = $run('https://graazie.com/wp-json/wc/v3/products');
check_tls('own domain: system bundle', $own['sslcertificates'], $system);
check_tls('own domain: sslverify untouched', $own['sslverify'], true);
check_tls('alias, any case, with port and query', $run('https://WWW.Graazie.com:443/?a=1')['sslcertificates'], $system);
check_tls('localhost is a loopback host', $run('https://localhost/wp-cron.php')['sslcertificates'], $system);

check_tls('external host unchanged', $run('https://api.wordpress.org/core/version-check/1.7/')['sslcertificates'], $wpBundle);
check_tls('lookalike host unchanged', $run('https://graazie.com.evil.test/')['sslcertificates'], $wpBundle);
check_tls('http unchanged', $run('http://graazie.com/')['sslcertificates'], $wpBundle);
check_tls('bundle chosen by the caller respected', $run('https://graazie.com/', array('sslverify' => true, 'sslcertificates' => '/custom/ca.pem'))['sslcertificates'], '/custom/ca.pem');
check_tls('sslverify false left as is', $run('https://graazie.com/', array('sslverify' => false, 'sslcertificates' => $wpBundle))['sslverify'], false);
check_tls('unreadable system bundle: unchanged', $run('https://graazie.com/', null, false)['sslcertificates'], $wpBundle);
check_tls('malformed url unchanged', $run('https:///nohost')['sslcertificates'], $wpBundle);

if ($failures) {
    fwrite(STDERR, "{$failures} failure(s)\n");
    exit(1);
}
echo "all green\n";
