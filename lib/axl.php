<?php
/**
 * axl.php - CUCM AXL client (configuration API; registration state is RisPort).
 *
 * The database is Informix: no LIMIT, use SELECT SKIP n FIRST m.
 */

require_once __DIR__ . '/soap.php';

class CpsAxlClient
{
    /** Schema versions to try, newest first. */
    public const KNOWN_VERSIONS = ['15.0', '14.0', '12.5', '12.0', '11.5', '11.0', '10.5', '10.0'];

    private ?string $version = null;

    public function __construct(
        private CpsSoapClient $soap,
        ?string $version = null,
    ) {
        $this->version = $version;
    }

    public function baseUrl(): string { return $this->soap->baseUrl(); }
    public function version(): ?string { return $this->version; }

    /** Issue one AXL method and return the <return> element (or the Body). */
    public function call(string $method, string $innerXml = ''): DOMElement
    {
        $ver  = $this->version ?? $this->discoverVersion();
        $env  = CpsSoapClient::envelope(
            "<ns:$method>$innerXml</ns:$method>",
            ['ns' => "http://www.cisco.com/AXL/API/$ver"]
        );
        $doc  = $this->soap->post('/axl/', $env, "\"CUCM:DB ver=$ver $method\"");
        $ret  = cps_xml_first($doc, 'return');
        if ($ret === null) {
            // Some methods (e.g. doAuthenticateUser) answer without <return>.
            $body = cps_xml_first($doc, 'Body');
            if ($body === null) {
                throw new CpsHttpError("AXL $method: response had no SOAP Body");
            }
            return $body;
        }
        return $ret;
    }

    /**
     * Find an accepted schema version by trying getCCMVersion with each one.
     * Faults and 599s try the next; other HTTP errors are rethrown.
     */
    public function discoverVersion(): string
    {
        if ($this->version !== null) { return $this->version; }
        $lastFault = null;
        $tried     = [];
        foreach (self::KNOWN_VERSIONS as $v) {
            $env = CpsSoapClient::envelope(
                '<ns:getCCMVersion></ns:getCCMVersion>',
                ['ns' => "http://www.cisco.com/AXL/API/$v"]
            );
            try {
                $doc = $this->soap->post('/axl/', $env, "\"CUCM:DB ver=$v getCCMVersion\"");
                $this->version = $v;
                cps_log("AXL schema version negotiated: $v", 2);
                return $v;
            } catch (CpsSoapFault $e) {
                cps_log("AXL schema $v rejected: " . $e->getMessage(), 3);
                $tried[$v] = $e->getMessage();
                $lastFault = $e;
            } catch (CpsHttpError $e) {
                if ($e->httpCode === 599) {
                    cps_log("AXL schema $v rejected (HTTP 599): " . $e->getMessage(), 3);
                    $tried[$v] = $e->getMessage();
                    continue;
                }
                throw $e;
            }
        }
        throw new CpsHttpError(
            'no AXL schema version accepted (tried ' . implode(', ', array_keys($tried) ?: self::KNOWN_VERSIONS) . ')'
            . ($lastFault ? '; last fault: ' . $lastFault->getMessage() : '')
        );
    }

    /** Cluster version string, e.g. "14.0.1.13900-155". */
    public function getCcmVersion(): array
    {
        $ret = $this->call('getCCMVersion');
        return [
            'version' => cps_xml_text($ret, 'version'),
            'schema'  => $this->version,
        ];
    }

    /**
     * Run a raw SQL SELECT against the CUCM (Informix) database.
     *
     * @return array<int,array<string,string>> one map per <row>
     */
    public function executeSqlQuery(string $sql): array
    {
        $ret  = $this->call('executeSQLQuery', '<sql>' . cps_xesc($sql) . '</sql>');
        $rows = [];
        foreach (cps_xml_find($ret, 'row') as $row) {
            $r = [];
            foreach (cps_xml_children($row) as $col) {
                $r[strtolower($col->localName)] = trim($col->textContent);
            }
            $rows[] = $r;
        }
        return $rows;
    }

    /**
     * Configured phones from listPhone, paged with skip/first.
     * $maxRows caps the total (0 = all); $pageSize only sets the page size.
     *
     * @return array<int,array<string,string>>
     */
    public function listPhones(
        array $returnedTags = [],
        int $pageSize = 500,
        ?string $nameLike = null,
        int $maxRows = 0,
    ): array {
        if (!$returnedTags) {
            $returnedTags = ['name', 'description', 'model', 'devicePoolName', 'callingSearchSpaceName', 'product', 'class'];
        }
        $tagXml = '';
        foreach ($returnedTags as $t) { $tagXml .= "<$t/>"; }

        $out  = [];
        $skip = 0;
        while (true) {
            $crit = '<name>' . cps_xesc($nameLike ?? '%') . '</name>';
            $inner = "<searchCriteria>$crit</searchCriteria>"
                   . "<returnedTags>$tagXml</returnedTags>"
                   . "<skip>$skip</skip><first>$pageSize</first>";
            $ret  = $this->call('listPhone', $inner);
            $page = [];
            foreach (cps_xml_find($ret, 'phone') as $ph) {
                $r = ['uuid' => strtoupper(trim($ph->getAttribute('uuid'), '{}'))];
                foreach (cps_xml_children($ph) as $f) {
                    $r[$f->localName] = trim($f->textContent);
                }
                $page[] = $r;
            }
            $out = array_merge($out, $page);
            cps_log('listPhone: ' . count($page) . " rows (skip=$skip), " . count($out) . ' total', 2);
            if (count($page) < $pageSize) { break; }
            if ($maxRows > 0 && count($out) >= $maxRows) { break; }
            $skip += $pageSize;
        }
        return $maxRows > 0 ? array_slice($out, 0, $maxRows) : $out;
    }

    /**
     * Each phone's Common Phone Profile, in one SQL query (uuid uppercase, no braces).
     *
     * @return array<string,array{uuid:?string,name:?string}> by device name
     */
    public function commonPhoneProfiles(): array
    {
        // Paged: AXL refuses SQL results above its size limit.
        $out  = [];
        $page = 2000;
        for ($skip = 0; ; $skip += $page) {
            $rows = $this->executeSqlQuery(
                "SELECT SKIP $skip FIRST $page d.name AS devicename, d.fkcommonphoneconfig AS cppuuid, c.name AS cppname "
              . 'FROM device d LEFT OUTER JOIN commonphoneconfig c ON c.pkid = d.fkcommonphoneconfig '
              . 'WHERE d.tkclass = 1 ORDER BY d.name');
            foreach ($rows as $row) {
                $name = $row['devicename'] ?? '';
                if ($name === '') { continue; }
                $out[$name] = ['uuid' => self::normUuid((string)($row['cppuuid'] ?? '')),
                               'name' => ($row['cppname'] ?? '') !== '' ? $row['cppname'] : null];
            }
            if (count($rows) < $page) { break; }
        }
        return $out;
    }

    /** "{ac24...}" / "AC24..." -> "AC24..." (uppercase, no braces), or null if not a uuid. */
    public static function normUuid(string $u): ?string
    {
        $u = strtoupper(trim($u, "{} \t"));
        return preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $u) ? $u : null;
    }

    /** The phone's current Common Phone Profile uuid, live from the cluster. */
    public function phoneCommonProfileUuid(string $device): ?string
    {
        $ret = $this->call('getPhone', '<name>' . cps_xesc($device) . '</name>'
                         . '<returnedTags><commonPhoneConfigName/></returnedTags>');
        $el = cps_xml_first($ret, 'commonPhoneConfigName');
        return $el === null ? null : self::normUuid($el->getAttribute('uuid'));
    }

    /** Name of the Common Phone Profile with this uuid; throws if it does not exist. */
    public function commonPhoneProfileName(string $uuid): string
    {
        $ret = $this->call('getCommonPhoneConfig', '<uuid>{' . cps_xesc($uuid) . '}</uuid>'
                         . '<returnedTags><name/></returnedTags>');
        return (string)cps_xml_text($ret, 'name', '');
    }

    /** Point a phone at another Common Phone Profile. Takes effect on Apply Config / reset. */
    public function setPhoneCommonProfile(string $device, string $uuid, string $profileName = ''): void
    {
        $this->call('updatePhone', '<name>' . cps_xesc($device) . '</name>'
                  . '<commonPhoneConfigName uuid="{' . cps_xesc($uuid) . '}">' . cps_xesc($profileName) . '</commonPhoneConfigName>');
    }

    /** "Apply Config" for one phone, so a changed profile is picked up. */
    public function applyPhone(string $device): void
    {
        $this->call('applyPhone', '<name>' . cps_xesc($device) . '</name>');
    }

    /** Cached enum => model name, so one sweep costs one query. */
    private ?array $modelNames = null;

    /**
     * Model enum => name, from the cluster's typemodel table.
     *
     * @return array<int,string>
     */
    public function modelNames(): array
    {
        if ($this->modelNames !== null) { return $this->modelNames; }
        $out = [];
        foreach ($this->executeSqlQuery('SELECT enum, name FROM typemodel') as $row) {
            if (isset($row['enum'], $row['name']) && $row['name'] !== '') {
                $out[(int)$row['enum']] = $row['name'];
            }
        }
        return $this->modelNames = $out;
    }

    /** Last known address per device from RegistrationDynamic (RisPort fallback). */
    public function registrationDynamic(): array
    {
        $sql = "SELECT d.name AS devicename, d.description, tm.name AS model, "
             . "rd.lastknownipaddress, rd.lastknownucm, rd.lastseen "
             . "FROM device d "
             . "INNER JOIN registrationdynamic rd ON rd.fkdevice = d.pkid "
             . "LEFT JOIN typemodel tm ON tm.enum = d.tkmodel";
        return $this->executeSqlQuery($sql);
    }
}
