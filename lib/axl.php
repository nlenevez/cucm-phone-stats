<?php
/**
 * axl.php - CUCM Administrative XML (AXL) client.
 *
 * Endpoint:    https://<cucm>:8443/axl/
 * Namespace:   http://www.cisco.com/AXL/API/<schema version>
 * SOAPAction:  "CUCM:DB ver=<schema version> <method>"
 * Role needed: Standard AXL API Access  (see docs/cucm-permissions.md)
 *
 * WHAT AXL IS AND IS NOT GOOD FOR HERE
 * ------------------------------------
 * AXL is the *configuration* API. The `device` table tells you a phone is
 * configured; it does not tell you the phone is registered, and it does not
 * carry the address the phone is currently on. Registration is runtime state
 * and belongs to RisPort70 (see risport.php), which is why that is the
 * primary discovery source in this tool.
 *
 * AXL still earns its place twice over:
 *   - it supplies the configuration context RisPort does not return
 *     (device pool, calling search space, phone template, owner);
 *   - `executeSQLQuery` against RegistrationDynamic is a serviceable
 *     fallback when RisPort is throttled, deactivated or unreachable.
 *
 * SQL DIALECT TRAP: the CUCM database is IBM Informix, not MySQL. There is
 * no LIMIT clause - paging is `SELECT SKIP n FIRST m ...`. A query written
 * with LIMIT fails with a syntax error that reads like a permissions
 * problem if you are not expecting it.
 */

require_once __DIR__ . '/soap.php';

class CpsAxlClient
{
    /**
     * Schema versions to try, newest first. CUCM accepts a request whose
     * schema version is at or below the cluster version, so walking down
     * finds a workable one without knowing the cluster version up front.
     */
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
     * Find a schema version this cluster accepts, by calling getCCMVersion
     * with each candidate until one is not rejected.
     *
     * A wrong version does not come back as a clean "unsupported version"
     * error on every release - some return a generic parse fault - so any
     * fault is treated as "try the next one" and only the exhausted list is
     * fatal. The first HTTP-level failure (401/403/connect) is re-thrown
     * immediately, because retrying seven more times against a box that
     * refused the credentials just wastes a minute and muddies the log.
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
                // 401/403 and connection failures will not improve with a
                // different schema version, so stop. Anything AXL itself
                // refused is a reason to try the next one - an older cluster
                // rejects the newer schemas, which is the entire point of
                // walking the list.
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
     * Configured phones, from listPhone. Paged with skip/first because AXL
     * throttles a single list response (1000 rows by default, and an admin
     * can lower it).
     *
     * $maxRows caps the total fetched; 0 means every phone. Without it, a
     * small $pageSize does NOT mean "fetch a few" - it means "fetch all of
     * them, a few at a time", which on a 6,757-phone cluster with pageSize 3
     * is over two thousand SOAP round trips. Callers that only want a sample
     * must say so.
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
     * Each phone's Common Phone Profile, in one query.
     *
     * device.fkcommonphoneconfig is the profile's pkid - the same value AXL
     * gives as the uuid attribute of commonPhoneConfigName - so one SQL call
     * replaces a getPhone per handset. The uuid is normalised the way
     * listPhones() does it: uppercase, no braces.
     *
     * @return array<string,array{uuid:?string,name:?string}> by device name
     */
    public function commonPhoneProfiles(): array
    {
        $sql = 'SELECT d.name AS devicename, d.fkcommonphoneconfig AS cppuuid, c.name AS cppname '
             . 'FROM device d LEFT OUTER JOIN commonphoneconfig c ON c.pkid = d.fkcommonphoneconfig '
             . 'WHERE d.tkclass = 1';
        $out = [];
        foreach ($this->executeSqlQuery($sql) as $row) {
            $name = $row['devicename'] ?? '';
            if ($name === '') { continue; }
            $uuid = strtoupper(trim((string)($row['cppuuid'] ?? ''), '{} '));
            $out[$name] = ['uuid' => $uuid !== '' ? $uuid : null,
                           'name' => ($row['cppname'] ?? '') !== '' ? $row['cppname'] : null];
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
    public function setPhoneCommonProfile(string $device, string $uuid): void
    {
        $this->call('updatePhone', '<name>' . cps_xesc($device) . '</name>'
                  . '<commonPhoneConfigName uuid="{' . cps_xesc($uuid) . '}"/>');
    }

    /** "Apply Config" for one phone, so a changed profile is picked up. */
    public function applyPhone(string $device): void
    {
        $this->call('applyPhone', '<name>' . cps_xesc($device) . '</name>');
    }

    /** Cached enum => model name, so one sweep costs one query. */
    private ?array $modelNames = null;

    /**
     * Model enum to human-readable name, from CUCM's own typemodel table.
     *
     * RisPort reports a device's model as a numeric enum - 627, 683 and so
     * on - which is meaningless in a report. The mapping is version-specific
     * and runs to hundreds of entries, so it is read from the cluster rather
     * than hard-coded: a built-in list would be wrong for some release, and
     * silently wrong at that.
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

    /**
     * Registration state from the configuration database, as a fallback for
     * RisPort70.
     *
     * RegistrationDynamic holds the last known address per device. It is a
     * weaker source than RisPort - "last known" is not "registered now", and
     * a row can outlive the registration - so callers should prefer RisPort
     * and treat this as degraded. Every row is stamped source='axl-sql' so a
     * report can tell the two apart.
     *
     * The exact column names here are the single least certain thing in this
     * tool; bin/cucm-probe.php probes each one separately for that reason.
     */
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
