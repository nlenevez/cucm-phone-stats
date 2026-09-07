<?php
/**
 * soap.php - minimal SOAP-over-HTTPS transport for CUCM's AXL and RisPort70.
 *
 * Why not PHP's SoapClient: it wants a WSDL, and CUCM's AXL WSDL is not
 * published anywhere - you download AXLAPI.wsdl from the cluster's plugin
 * page and it must match the cluster version. On an airgapped host that is
 * an extra artefact to carry and keep in step with every CUCM upgrade.
 * Hand-built envelopes over curl have no such dependency, and the responses
 * are parsed namespace-blind (see cps_xml_find) so a schema version bump
 * does not break parsing.
 */

require_once __DIR__ . '/util.php';

class CpsSoapFault extends RuntimeException {
    public function __construct(
        string $message,
        public readonly ?string $faultcode = null,
        public readonly ?string $axlcode = null,
        public readonly ?string $detail = null,
        public readonly int $httpCode = 0,
    ) {
        parent::__construct($message);
    }
}

class CpsHttpError extends RuntimeException {
    public function __construct(
        string $message,
        public readonly int $httpCode = 0,
        public readonly string $body = '',
        public readonly int $curlErrno = 0,
    ) {
        parent::__construct($message);
    }
}

class CpsSoapClient
{
    public function __construct(
        private string $baseUrl,      // e.g. https://cucm1.example.com:8443
        private string $username,
        private string $password,
        private bool   $verifyTls = false,
        private int    $timeout = 30,
        private int    $connectTimeout = 10,
        private ?string $caBundle = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function baseUrl(): string { return $this->baseUrl; }

    /**
     * POST a SOAP envelope and return the parsed DOMDocument.
     *
     * @throws CpsHttpError on transport/HTTP failure
     * @throws CpsSoapFault on a SOAP Fault (including AXL's axlcode detail)
     */
    public function post(string $path, string $envelope, string $soapAction): DOMDocument
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ' . $soapAction,
                'Accept: text/xml',
                'Expect:', // curl otherwise waits 1s for a 100-continue CUCM never sends
            ],
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->username . ':' . $this->password,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($this->caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        }

        cps_log("SOAP $soapAction -> $url", 3);
        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new CpsHttpError("$url: curl error $errno: $err", 0, '', $errno);
        }
        cps_log("SOAP <- HTTP $code, " . strlen($body) . " bytes", 3);

        // A SOAP Fault arrives as HTTP 500 with a parseable body, so parse
        // before judging the status code - the fault text is the diagnosis.
        $doc = new DOMDocument();
        $ok  = @$doc->loadXML($body);

        if ($ok) {
            $fault = cps_xml_first($doc, 'Fault');
            if ($fault !== null) {
                throw $this->faultFrom($fault, $code);
            }
        }

        if ($code !== 200) {
            $hint = match (true) {
                $code === 401 => ' (401: bad username/password)',
                $code === 403 => ' (403: credentials accepted but the account lacks the role - see docs/cucm-permissions.md)',
                $code === 404 => ' (404: wrong path or the service is not activated on this node)',
                $code === 599 => '',
                default       => '',
            };
            throw new CpsHttpError("$url returned HTTP $code$hint", $code, substr($body, 0, 2000));
        }
        if (!$ok) {
            throw new CpsHttpError("$url returned unparseable XML", $code, substr($body, 0, 2000));
        }
        return $doc;
    }

    private function faultFrom(DOMElement $fault, int $httpCode): CpsSoapFault
    {
        $faultcode   = cps_xml_text($fault, 'faultcode');
        $faultstring = cps_xml_text($fault, 'faultstring', '(no faultstring)');
        // AXL puts a structured error inside <detail><axlError>.
        $axlcode = cps_xml_text($fault, 'axlcode');
        $axlmsg  = cps_xml_text($fault, 'axlmessage');
        $detail  = null;
        if ($d = cps_xml_first($fault, 'detail')) {
            $detail = trim(preg_replace('/\s+/', ' ', $d->textContent));
        }
        $msg = $axlmsg !== null ? "$faultstring: $axlmsg" : $faultstring;
        if ($axlcode !== null) { $msg .= " [axlcode $axlcode]"; }
        return new CpsSoapFault($msg, $faultcode, $axlcode, $detail, $httpCode);
    }

    /** Wrap a body fragment in a SOAP 1.1 envelope. */
    public static function envelope(string $bodyXml, array $nsMap = []): string
    {
        $ns = 'xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"';
        foreach ($nsMap as $prefix => $uri) {
            $ns .= ' xmlns:' . $prefix . '="' . $uri . '"';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
             . '<soapenv:Envelope ' . $ns . '>'
             . '<soapenv:Header/><soapenv:Body>' . $bodyXml . '</soapenv:Body>'
             . '</soapenv:Envelope>';
    }
}
