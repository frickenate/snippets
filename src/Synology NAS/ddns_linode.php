#!/usr/bin/env php
<?php

/*
 * installation
 *
 * please note that this requires a certain amount of knowledge with
 * the ssh shell (vi, chmod, etc.). this process will not be easy
 * for someone without existing experience using the command line.
 *
 * 1. check the "editable configuration" section below. defaults should work out of the box
 * 2. ssh as root to the NAS (enabled via web manager: Control Panel > Terminal & SNMP > Enable SSH service)
 * 3. place this file on the NAS at /etc.defaults/ddns_linode.php (hint: 1. cat > 2. paste 3. ctrl+d)
 * 4. set file permissions: chmod 755 /etc.defaults/ddns_linode.php
 * 5. add a "Linode" section at the bottom of /etc.defaults/ddns_provider.conf:
 *      [Linode]
 *          modulepath=/etc.defaults/ddns_linode.php
 *          queryurl=linode.com
 * 6. now configure your linode details in the web manager (example for home.example.com)
 *      a) navigate to: Control Panel > External Access, click the "Add" button
 *      b) Service provider: select "Linode"
 *      c) Hostname: enter the SUBDOMAIN of your primary domain you have created at Linode (ex: home)
 *      d) Username/Email: enter the PRIMARY domain you have hosted with Linode DNS (ex: example.com)
 *      e) Password: enter your Linode API key - NOT YOUR LINODE PASSWORD
 */

/* editable configuration */

// writable temporary directory for log file and tracking last ip
const TEMP_DIR = '/var/services/tmp';

// the NAS passes what the current IP address is. if you'd rather use an external service
// to determine the IP, set this to a URL that outputs the IP of the accessing client
const IP_ALTERNATIVE = null;
//const IP_ALTERNATIVE = 'http://ip.dnsexit.com/';

// TTL of the DNS record in seconds. 3600 makes for a good default.
// 300 = 5 mins, 3600 = 1 hr, 7200 = 2 hrs, 14400 = 4 hrs, 28800 = 8 hrs, 86400 = 24 hrs
const LINODE_DNS_TTL = 3600;

/* end of editable configuration */

// map of cURL errors to NAS DDNS errors
$CURL_ERRORS = [
    CURLE_COULDNT_RESOLVE_HOST => 'badresolv',
    CURLE_OPERATION_TIMEOUTED  => 'badconn',
    CURLE_URL_MALFORMAT        => 'badagent',
];

// map of linode errors to NAS DDNS errors
$LINODE_ERRORS = [4 => 'badauth', 5 => 'nohost'];

class DnsException extends Exception {
    public function __construct($message, $code) { parent::__construct($message); $this->code = $code; }
}

try {
    // parse script arguments
    if (!preg_match('/^([^ ]+) ([^ ]+) ([^ ]+) ([^ ]+)$/', (string)@$argv[1], $match)) {
        throw new DnsException('synology ddns service provided invalid script arguments', '911');
    }
    list($domain, $apiKey, $subdomain, $ip) = array_slice($match, 1);

    // override current ip reported by NAS with an external service
    if (is_string(IP_ALTERNATIVE)) {
        $ip = curlPost(IP_ALTERNATIVE);
    }

    // extract the current ip from the source text
    if (($newIp = preg_match('/(?:\d{1,3}\.){3}\d{1,3}/', $ip, $match) ? $match[0] : false) === false) {
        throw new DnsException("new ip address not valid: '{$ip}'", '911');
    }

    // compare against last known ip
    if (($lastIp = (string)@file_get_contents($lastIpFile = TEMP_DIR . '/ddns_linode.lastip')) === $newIp) {
        throw new DnsException("ip address unchanged: '{$newIp}'", 'nochg');
    }

    // find linode primary domain record
    if (($domainId = array_reduce(linode($apiKey, 'domain.list'), function ($ret, $item) use ($domain) {
        return $item['DOMAIN'] === $domain ? $item['DOMAINID'] : $ret;
    })) === null) {
        throw new DnsException("linode domain zone not found: '{$domain}'", 'nohost');
    }

    // find linode subdomain record
    if (($subdomainId = array_reduce(linode(
        $apiKey, 'domain.resource.list', ['DomainID' => $domainId]
    ), function ($ret, $item) use ($subdomain) {
        return $item['NAME'] === $subdomain ? $item['RESOURCEID'] : $ret;
    })) === null) {
        throw new DnsException("linode subdomain not found: '{$subdomain}.{$domain}'", 'nohost');
    }

    // update the dns record
    linode($apiKey, 'domain.resource.update', [
        'DomainId' => $domainId, 'ResourceId' => $subdomainId, 'Target' => $newIp, 'TTL_sec' => LINODE_DNS_TTL
    ]);

    // store the new current ip as the last known ip
    @file_put_contents($lastIpFile, $newIp);
    throw new DnsException("ip successfully updated: '{$newIp}'", 'good');
} catch (DnsException $e) {
    // log entry
    @file_put_contents(TEMP_DIR . '/ddns_linode.log', sprintf(
        "%s : %s : %s\n", date('Y-m-d H:i T'), $e->getCode(), $e->getMessage()
    ), FILE_APPEND);

    echo $e->getCode() . "\n";
}

function linode($apiKey, $action, array $params = []) {
    if (!is_array($data = @json_decode($response = curlPost(
        'https://api.linode.com/', ['api_key' => $apiKey, 'api_action' => $action] + $params
    ), true))) {
        throw new DnsException("bad linode response: '" . trim(preg_replace('/\s+/', ' ', $response)) . "'", '911');
    }

    if (($linodeCode = @$data['ERRORARRAY'][0]['ERRORCODE']) !== null) {
        throw new DnsException("linode error: '{$data['ERRORARRAY'][0]['ERRORMESSAGE']}'", array_reduce(
            array_keys($GLOBALS['LINODE_ERRORS']), function ($ret, $item) use ($linodeCode) {
                return (int)$linodeCode === (int)$item ? $GLOBALS['LINODE_ERRORS'][$item] : $ret;
            }, '911'
        ));
    }

    return $data['DATA'];
}

function curlPost($url, array $params = []) {
    if (!($ch = @curl_init($url)) || !@curl_setopt_array($ch, [
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_POST           => true,                      CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_RETURNTRANSFER => true,                      CURLOPT_TIMEOUT        => 10,
    ]) || ($response = @curl_exec($ch)) === false) {
        $code = @$GLOBALS['CURL_ERRORS'][$ch ? @curl_errno($ch) : ''] ? : '911';
        throw new DnsException("failed curl request: '" . ($ch ? @curl_error($ch) : 'init error') .  "'", $code);
    }

    return $response;
}
