<?php

/*
 * ******************************************************************************
 * Copyright 2011-2017 DANTE Ltd. and GÉANT on behalf of the GN3, GN3+, GN4-1 
 * and GN4-2 consortia
 *
 * License: see the web/copyright.php file in the file structure
 * ******************************************************************************
 */

/**
 * This file contains the ProfileSilverbullet class.
 *
 * @author Stefan Winter <stefan.winter@restena.lu>
 * @author Tomasz Wolniewicz <twoln@umk.pl>
 *
 * @package Developer
 *
 */

namespace core;

use \Exception;

/**
 * Silverbullet (marketed as "Managed IdP") is a RADIUS profile which 
 * corresponds directly to a built-in RADIUS server and CA. 
 * It provides all functions needed for a admin-side web interface where users
 * can be added and removed, and new devices be enabled.
 * 
 * When downloading a Silverbullet based profile, the profile includes per-user
 * per-device client certificates which can be immediately used to log into 
 * eduroam.
 *
 * @author Stefan Winter <stefan.winter@restena.lu>
 * @author Tomasz Wolniewicz <twoln@umk.pl>
 *
 * @license see LICENSE file in root directory
 *
 * @package Developer
 */
class ProfileSilverbullet extends AbstractProfile {

    const SB_CERTSTATUS_VALID = 1;
    const SB_CERTSTATUS_EXPIRED = 2;
    const SB_CERTSTATUS_REVOKED = 3;
    const SB_ACKNOWLEDGEMENT_REQUIRED_DAYS = 365;

    public $termsAndConditions;

    /*
     * 
     */

    const PRODUCTNAME = "Managed IdP";

    /**
     * produces a random string
     * @param int $length the length of the string to produce
     * @param string $keyspace the pool of characters to use for producing the string
     * @return string
     * @throws Exception
     */
    public static function randomString(
    $length, $keyspace = '23456789abcdefghijkmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'
    ) {
        $str = '';
        $max = strlen($keyspace) - 1;
        if ($max < 1) {
            throw new Exception('$keyspace must be at least two characters long');
        }
        for ($i = 0; $i < $length; ++$i) {
            $str .= $keyspace[random_int(0, $max)];
        }
        return $str;
    }

    /**
     * Class constructor for existing profiles (use IdP::newProfile() to actually create one). Retrieves all attributes and 
     * supported EAP types from the DB and stores them in the priv_ arrays.
     * 
     * @param int $profileId identifier of the profile in the DB
     * @param IdP $idpObject optionally, the institution to which this Profile belongs. Saves the construction of the IdP instance. If omitted, an extra query and instantiation is executed to find out.
     */
    public function __construct($profileId, $idpObject = NULL) {
        parent::__construct($profileId, $idpObject);

        $this->entityOptionTable = "profile_option";
        $this->entityIdColumn = "profile_id";
        $this->attributes = [];

        $tempMaxUsers = 200; // abolutely last resort fallback if no per-fed and no config option
// set to global config value

        if (isset(CONFIG_CONFASSISTANT['SILVERBULLET']['default_maxusers'])) {
            $tempMaxUsers = CONFIG_CONFASSISTANT['SILVERBULLET']['default_maxusers'];
        }
        $myInst = new IdP($this->institution);
        $myFed = new Federation($myInst->federation);
        $fedMaxusers = $myFed->getAttributes("fed:silverbullet-maxusers");
        if (isset($fedMaxusers[0])) {
            $tempMaxUsers = $fedMaxusers[0]['value'];
        }

// realm is automatically calculated, then stored in DB

        $this->realm = "opaquehash@$myInst->identifier-$this->identifier." . strtolower($myInst->federation) . CONFIG_CONFASSISTANT['SILVERBULLET']['realm_suffix'];
        $localValueIfAny = "";

// but there's some common internal attributes populated directly
        $internalAttributes = [
            "internal:profile_count" => $this->idpNumberOfProfiles,
            "internal:realm" => preg_replace('/^.*@/', '', $this->realm),
            "internal:use_anon_outer" => FALSE,
            "internal:checkuser_outer" => TRUE,
            "internal:checkuser_value" => "anonymous",
            "internal:anon_local_value" => $localValueIfAny,
            "internal:silverbullet_maxusers" => $tempMaxUsers,
            "profile:production" => "on",
        ];

// and we need to populate eap:server_name and eap:ca_file with the NRO-specific EAP information
        $silverbulletAttributes = [
            "eap:server_name" => "auth." . strtolower($myFed->tld) . CONFIG_CONFASSISTANT['SILVERBULLET']['server_suffix'],
        ];
        $x509 = new \core\common\X509();
        $caHandle = fopen(dirname(__FILE__) . "/../config/SilverbulletServerCerts/" . strtoupper($myFed->tld) . "/root.pem", "r");
        if ($caHandle !== FALSE) {
            $cAFile = fread($caHandle, 16000000);
            $silverbulletAttributes["eap:ca_file"] = $x509->der2pem(($x509->pem2der($cAFile)));
        }

        $temp = array_merge($this->addInternalAttributes($internalAttributes), $this->addInternalAttributes($silverbulletAttributes));
        $tempArrayProfLevel = array_merge($this->addDatabaseAttributes(), $temp);

// now, fetch and merge IdP-wide attributes

        $this->attributes = $this->levelPrecedenceAttributeJoin($tempArrayProfLevel, $this->idpAttributes, "IdP");

        $this->privEaptypes = $this->fetchEAPMethods();

        $this->name = ProfileSilverbullet::PRODUCTNAME;

        $this->loggerInstance->debug(3, "--- END Constructing new Profile object ... ---\n");

        $this->termsAndConditions = "<h2>Product Definition</h2>
        <p>" . \core\ProfileSilverbullet::PRODUCTNAME . " outsources the technical setup of " . CONFIG_CONFASSISTANT['CONSORTIUM']['display_name'] . " " . CONFIG_CONFASSISTANT['CONSORTIUM']['nomenclature_institution'] . " functions to the " . CONFIG_CONFASSISTANT['CONSORTIUM']['display_name'] . " Operations Team. The system includes</p>
            <ul>
                <li>a web-based user management interface where user accounts and access credentials can be created and revoked (there is a limit to the number of active users)</li>
                <li>a technical infrastructure ('CA') which issues and revokes credentials</li>
                <li>a technical infrastructure ('RADIUS') which verifies access credentials and subsequently grants access to " . CONFIG_CONFASSISTANT['CONSORTIUM']['display_name'] . "</li>
                <li><span style='color: red;'>TBD: a lookup/notification system which informs you of network abuse complaints by " . CONFIG_CONFASSISTANT['CONSORTIUM']['display_name'] . " Service Providers that pertain to your users</span></li>
            </ul>
        <h2>User Account Liability</h2>
        <p>As an " . CONFIG_CONFASSISTANT['CONSORTIUM']['display_name'] . " " . CONFIG_CONFASSISTANT['CONSORTIUM']['nomenclature_institution'] . " administrator using this system, you are authorized to create user accounts according to your local " . CONFIG_CONFASSISTANT['CONSORTIUM']['nomenclature_institution'] . " policy. You are fully responsible for the accounts you issue and are the data controller for all user information you deposit in this system; the system is a data processor.</p>";
        $this->termsAndConditions .= "<p>Your responsibilities include that you</p>
        <ul>
            <li>only issue accounts to members of your " . CONFIG_CONFASSISTANT['CONSORTIUM']['nomenclature_institution'] . ", as defined by your local policy.</li>
            <li>must make sure that all accounts that you issue can be linked by you to actual human end users</li>
            <li>have to immediately revoke accounts of users when they leave or otherwise stop being a member of your " . CONFIG_CONFASSISTANT['CONSORTIUM']['nomenclature_institution'] . "</li>
            <li>will act upon notifications about possible network abuse by your users and will appropriately sanction them</li>
        </ul>
        <p>";
        $this->termsAndConditions .= "Failure to comply with these requirements may make your " . CONFIG_CONFASSISTANT['CONSORTIUM']['nomenclature_federation'] . " act on your behalf, which you authorise, and will ultimately lead to the deletion of your " . CONFIG_CONFASSISTANT['CONSORTIUM']['nomenclature_institution'] . " (and all the users you create inside) in this system.";
        $this->termsAndConditions .= "</p>
        <h2>Privacy</h2>
        <p>With " . \core\ProfileSilverbullet::PRODUCTNAME . ", we are necessarily storing personally identifiable information about the end users you create. While the actual human is only identifiable with your help, we consider all the user data as relevant in terms of privacy jurisdiction. Please note that</p>
        <ul>
            <li>You are the only one who needs to be able to make a link to the human behind the usernames you create. The usernames you create in the system have to be rich enough to allow you to make that identification step. Also consider situations when you are unavailable or leave the organisation and someone else needs to perform the matching to an individual.</li>
            <li>The identifiers we create in the credentials are not linked to the usernames you add to the system; they are randomly generated pseudonyms.</li>
            <li>Each access credential carries a different pseudonym, even if it pertains to the same username.</li>
            <li>If you choose to deposit users' email addresses in the system, you authorise the system to send emails on your behalf regarding operationally relevant events to the users in question (e.g. notification of nearing expiry dates of credentials, notification of access revocation).
        </ul>";
    }
    
    /**
     * Updates database with new installer location; NOOP because we do not
     * cache anything in Silverbullet
     * 
     * @param string $device the device identifier string
     * @param string $path the path where the new installer can be found
     * @param string $mime the mime type of the new installer
     * @param int $integerEapType the inter-representation of the EAP type that is configured in this installer
     */
    public function updateCache($device, $path, $mime, $integerEapType) {
        // caching is not supported in SB (private key in installers)
        // the following merely makes the "unused parameter" warnings go away
        // the FALSE in condition one makes sure it never gets executed
        if (FALSE || $device == "Macbeth" || $path == "heath" || $mime == "application/witchcraft" || $integerEapType == 0) {
            throw new Exception("FALSE is TRUE, and TRUE is FALSE! Hover through the browser and filthy code!");
        }
    }

    /**
     * register new supported EAP method for this profile
     *
     * @param \core\common\EAP $type The EAP Type, as defined in class EAP
     * @param int $preference preference of this EAP Type. If a preference value is re-used, the order of EAP types of the same preference level is undefined.
     *
     */
    public function addSupportedEapMethod(\core\common\EAP $type, $preference) {
        // the parameters really should only list SB and with prio 1 - otherwise,
        // something fishy is going on
        if ($type->getIntegerRep() != \core\common\EAP::INTEGER_SILVERBULLET || $preference != 1) {
            throw new Exception("Silverbullet::addSupportedEapMethod was called for a non-SP EAP type or unexpected priority!");
        }
        parent::addSupportedEapMethod($type, 1);
    }

    /**
     * It's EAP-TLS and there is no point in anonymity
     * @param boolean $shallwe
     */
    public function setAnonymousIDSupport($shallwe) {
        // we don't do anonymous outer IDs in SB
        if ($shallwe === TRUE) {
            throw new Exception("Silverbullet: attempt to add anonymous outer ID support to a SB profile!");
        }
        $this->databaseHandle->exec("UPDATE profile SET use_anon_outer = 0 WHERE profile_id = $this->identifier");
    }

    /**
     * create a CSR
     * 
     * @param resource $privateKey the private key to create the CSR with
     * @return array with the CSR and some meta info
     */
    private function generateCsr($privateKey) {
        // token leads us to the NRO, to set the OU property of the cert
        $inst = new IdP($this->institution);
        $federation = strtoupper($inst->federation);
        $usernameIsUnique = FALSE;
        $username = "";
        while ($usernameIsUnique === FALSE) {
            $usernameLocalPart = self::randomString(64 - 1 - strlen($this->realm), "0123456789abcdefghijklmnopqrstuvwxyz");
            $username = $usernameLocalPart . "@" . $this->realm;
            $uniquenessQuery = $this->databaseHandle->exec("SELECT cn from silverbullet_certificate WHERE cn = ?", "s", $username);
            // SELECT -> resource, not boolean
            if (mysqli_num_rows(/** @scrutinizer ignore-type */ $uniquenessQuery) == 0) {
                $usernameIsUnique = TRUE;
            }
        }

        $this->loggerInstance->debug(5, "generateCertificate: generating private key.\n");

        $newCsr = openssl_csr_new(
                    ['O' => CONFIG_CONFASSISTANT['CONSORTIUM']['name'],
                'OU' => $federation,
                'CN' => $username,
                'emailAddress' => $username,
                    ], $privateKey, [
                'digest_alg' => 'sha256',
                'req_extensions' => 'v3_req',
                    ]
            );
        if ($newCsr === FALSE) {
            throw new Exception("Unable to create a CSR!");
        }
        return [
            "CSR" => $newCsr,
            "USERNAME" => $username
        ];
    }

    /**
     * take a CSR and sign it with our issuing CA's certificate
     * 
     * @param mixed $csr the CSR
     * @param int $expiryDays the number of days until the cert is going to expire
     * @return array the cert and some meta info
     */
    private function signCsr($csr, $expiryDays) {
        switch (CONFIG_CONFASSISTANT['SILVERBULLET']['CA']['type']) {
            case "embedded":
                $rootCaPem = file_get_contents(ROOT . "/config/SilverbulletClientCerts/rootca.pem");
                $issuingCaPem = file_get_contents(ROOT . "/config/SilverbulletClientCerts/real.pem");
                $issuingCa = openssl_x509_read($issuingCaPem);
                $issuingCaKey = openssl_pkey_get_private("file://" . ROOT . "/config/SilverbulletClientCerts/real.key");
                $nonDupSerialFound = FALSE;
                do {
                    $serial = random_int(1000000000, PHP_INT_MAX);
                    $dupeQuery = $this->databaseHandle->exec("SELECT serial_number FROM silverbullet_certificate WHERE serial_number = ?", "i", $serial);
                    // SELECT -> resource, not boolean
                    if (mysqli_num_rows(/** @scrutinizer ignore-type */$dupeQuery) == 0) {
                        $nonDupSerialFound = TRUE;
                    }
                } while (!$nonDupSerialFound);
                $this->loggerInstance->debug(5, "generateCertificate: signing imminent with unique serial $serial.\n");
                return [
                    "CERT" => openssl_csr_sign($csr, $issuingCa, $issuingCaKey, $expiryDays, ['digest_alg' => 'sha256'], $serial),
                    "SERIAL" => $serial,
                    "ISSUER" => $issuingCaPem,
                    "ROOT" => $rootCaPem,
                ];
            default:
                /* HTTP POST the CSR to the CA with the $expiryDays as parameter
                 * on successful execution, gets back a PEM file which is the
                 * certificate (structure TBD)
                 * $httpResponse = httpRequest("https://clientca.hosted.eduroam.org/issue/", ["csr" => $csr, "expiry" => $expiryDays ] );
                 *
                 * The result of this if clause has to be a certificate in PHP's 
                 * "openssl_object" style (like the one that openssl_csr_sign would 
                 * produce), to be stored in the variable $cert; we also need the
                 * serial - which can be extracted from the received cert and has
                 * to be stored in $serial.
                 */
                throw new Exception("External silverbullet CA is not implemented yet!");
        }
    }

    /**
     * issue a certificate based on a token
     *
     * @param string $token
     * @param string $importPassword
     * @return array
     */
    public function issueCertificate($token, $importPassword) {
        $this->loggerInstance->debug(5, "generateCertificate() - starting.\n");
        $invitationObject = new SilverbulletInvitation($token);
        $this->loggerInstance->debug(5, "tokenStatus: done, got " . $invitationObject->invitationTokenStatus . ", " . $invitationObject->profile . ", " . $invitationObject->userId . ", " . $invitationObject->expiry . ", " . $invitationObject->invitationTokenString . "\n");
        if ($invitationObject->invitationTokenStatus != SilverbulletInvitation::SB_TOKENSTATUS_VALID && $invitationObject->invitationTokenStatus != SilverbulletInvitation::SB_TOKENSTATUS_PARTIALLY_REDEEMED) {
            throw new Exception("Attempt to generate a SilverBullet installer with an invalid/redeemed/expired token. The user should never have gotten that far!");
        }
        if ($invitationObject->profile != $this->identifier) {
            throw new Exception("Attempt to generate a SilverBullet installer, but the profile ID (constructor) and the profile from token do not match!");
        }
        // SQL query to find the expiry date of the *user* to find the correct ValidUntil for the cert
        $user = $invitationObject->userId;
        $userrow = $this->databaseHandle->exec("SELECT expiry FROM silverbullet_user WHERE id = ?", "i", $user);
        // SELECT -> resource, not boolean
        if ($userrow->num_rows != 1) {
            throw new Exception("Despite a valid token, the corresponding user was not found in database or database query error!");
        }
        $expiryObject = mysqli_fetch_object(/** @scrutinizer ignore-type */ $userrow);
        $this->loggerInstance->debug(5, "EXP: " . $expiryObject->expiry . "\n");
        $expiryDateObject = date_create_from_format("Y-m-d H:i:s", $expiryObject->expiry);
        if ($expiryDateObject === FALSE) {
            throw new Exception("The expiry date we got from the DB is bogus!");
        }
        $this->loggerInstance->debug(5, $expiryDateObject->format("Y-m-d H:i:s") . "\n");
        // date_create with no parameters can't fail, i.e. is never FALSE
        $validity = date_diff(/** @scrutinizer ignore-type */ date_create(), $expiryDateObject);
        $expiryDays = $validity->days + 1;
        if ($validity->invert == 1) { // negative! That should not be possible
            throw new Exception("Attempt to generate a certificate for a user which is already expired!");
        }

        $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'encrypt_key' => FALSE]);
        $csr = $this->generateCsr($privateKey);

        $this->loggerInstance->debug(5, "generateCertificate: proceeding to sign cert.\n");

        $certMeta = $this->signCsr($csr["CSR"], $expiryDays);
        $cert = $certMeta["CERT"];
        $issuingCaPem = $certMeta["ISSUER"];
        $rootCaPem = $certMeta["ROOT"];
        $serial = $certMeta["SERIAL"];

        $this->loggerInstance->debug(5, "generateCertificate: post-processing certificate.\n");

        // get the SHA1 fingerprint, this will be handy for Windows installers
        $sha1 = openssl_x509_fingerprint($cert, "sha1");
        // with the cert, our private key and import password, make a PKCS#12 container out of it
        $exportedCertProt = "";
        openssl_pkcs12_export($cert, $exportedCertProt, $privateKey, $importPassword, ['extracerts' => [$issuingCaPem /* , $rootCaPem */]]);
        $exportedCertClear = "";
        openssl_pkcs12_export($cert, $exportedCertClear, $privateKey, "", ['extracerts' => [$issuingCaPem, $rootCaPem]]);
        // store resulting cert CN and expiry date in separate columns into DB - do not store the cert data itself as it contains the private key!
        // we need the *real* expiry date, not just the day-approximation
        $x509 = new \core\common\X509();
        $certString = "";
        openssl_x509_export($cert, $certString);
        $parsedCert = $x509->processCertificate($certString);
        $this->loggerInstance->debug(5, "CERTINFO: " . print_r($parsedCert['full_details'], true));
        $realExpiryDate = date_create_from_format("U", $parsedCert['full_details']['validTo_time_t'])->format("Y-m-d H:i:s");

        // store new cert info in DB
        $newCertificateResult = $this->databaseHandle->exec("INSERT INTO `silverbullet_certificate` (`profile_id`, `silverbullet_user_id`, `silverbullet_invitation_id`, `serial_number`, `cn` ,`expiry`) VALUES (?, ?, ?, ?, ?, ?)", "iiisss", $invitationObject->profile, $invitationObject->userId, $invitationObject->identifier, $serial, $csr["USERNAME"], $realExpiryDate);
        if ($newCertificateResult === false) {
            throw new Exception("Unable to update database with new cert details!");
        }
        $certificateId = $this->databaseHandle->lastID();

        // newborn cert immediately gets its "valid" OCSP response
        ProfileSilverbullet::triggerNewOCSPStatement((int) $serial);
// return PKCS#12 data stream
        return [
            "username" => $csr["USERNAME"],
            "certdata" => $exportedCertProt,
            "certdataclear" => $exportedCertClear,
            "expiry" => $expiryDateObject->format("Y-m-d\TH:i:s\Z"),
            "sha1" => $sha1,
            'importPassword' => $importPassword,
            'serial' => $serial,
            'certificateId' => $certificateId,
        ];
    }

    /**
     * triggers a new OCSP statement for the given serial number
     * 
     * @param int $serial the serial number of the cert in question (decimal)
     * @return string DER-encoded OCSP status info (binary data!)
     */
    public static function triggerNewOCSPStatement($serial) {
        $logHandle = new \core\common\Logging();
        $logHandle->debug(2, "Triggering new OCSP statement for serial $serial.\n");
        switch (CONFIG_CONFASSISTANT['SILVERBULLET']['CA']['type']) {
            case "embedded":
                // get all relevant info from DB
                $cn = "";
                $federation = NULL;
                $certstatus = "";
                $originalExpiry = date_create_from_format("Y-m-d H:i:s", "2000-01-01 00:00:00");
                $dbHandle = DBConnection::handle("INST");
                $originalStatusQuery = $dbHandle->exec("SELECT profile_id, cn, revocation_status, expiry, revocation_time, OCSP FROM silverbullet_certificate WHERE serial_number = ?", "i", $serial);
                // SELECT -> resource, not boolean
                if (mysqli_num_rows(/** @scrutinizer ignore-type */ $originalStatusQuery) > 0) {
                    $certstatus = "V";
                }
                while ($runner = mysqli_fetch_object(/** @scrutinizer ignore-type */ $originalStatusQuery)) { // there can be only one row
                    if ($runner->revocation_status == "REVOKED") {
                        // already revoked, simply return canned OCSP response
                        $certstatus = "R";
                    }
                    $originalExpiry = date_create_from_format("Y-m-d H:i:s", $runner->expiry);
                    if ($originalExpiry === FALSE) {
                        throw new Exception("Unable to calculate original expiry date, input data bogus!");
                    }
                    $validity = date_diff(/** @scrutinizer ignore-type */ date_create(), $originalExpiry);
                    if ($validity->invert == 1) {
                        // negative! Cert is already expired, no need to revoke. 
                        // No need to return anything really, but do return the last known OCSP statement to prevent special case
                        $certstatus = "E";
                    }
                    $cn = $runner->cn;
                    $profile = new ProfileSilverbullet($runner->profile_id);
                    $inst = new IdP($profile->institution);
                    $federation = strtoupper($inst->federation);
                }

                // generate stub index.txt file
                $cat = new CAT();
                $tempdirArray = $cat->createTemporaryDirectory("test");
                $tempdir = $tempdirArray['dir'];
                $nowIndexTxt = (new \DateTime())->format("ymdHis") . "Z";
                $expiryIndexTxt = $originalExpiry->format("ymdHis") . "Z";
                $serialHex = strtoupper(dechex($serial));
                if (strlen($serialHex) % 2 == 1) {
                    $serialHex = "0" . $serialHex;
                }
                
                $indexStatement = "$certstatus\t$expiryIndexTxt\t" . ($certstatus == "R" ? "$nowIndexTxt,unspecified" : "") . "\t$serialHex\tunknown\t/O=" . CONFIG_CONFASSISTANT['CONSORTIUM']['name'] . "/OU=$federation/CN=$cn/emailAddress=$cn\n";
                $logHandle->debug(4, "index.txt contents-to-be: $indexStatement");
                if (!file_put_contents($tempdir . "/index.txt", $indexStatement)) {
                $logHandle->debug(1,"Unable to write openssl index.txt file for revocation handling!");
                }
                // index.txt.attr is dull but needs to exist
                file_put_contents($tempdir . "/index.txt.attr", "unique_subject = yes\n");
                // call "openssl ocsp" to manufacture our own OCSP statement
                // adding "-rmd sha1" to the following command-line makes the
                // choice of signature algorithm for the response explicit
                // but it's only available from openssl-1.1.0 (which we do not
                // want to require just for that one thing).
                $execCmd = CONFIG['PATHS']['openssl'] . " ocsp -issuer " . ROOT . "/config/SilverbulletClientCerts/real.pem -sha1 -ndays 10 -no_nonce -serial 0x$serialHex -CA " . ROOT . "/config/SilverbulletClientCerts/real.pem -rsigner " . ROOT . "/config/SilverbulletClientCerts/real.pem -rkey " . ROOT . "/config/SilverbulletClientCerts/real.key -index $tempdir/index.txt -no_cert_verify -respout $tempdir/$serialHex.response.der";
                $logHandle->debug(2, "Calling openssl ocsp with following cmdline: $execCmd\n");
                $output = [];
                $return = 999;
                exec($execCmd, $output, $return);
                if ($return !== 0) {
                    throw new Exception("Non-zero return value from openssl ocsp!");
                }
                $ocspFile = fopen($tempdir . "/$serialHex.response.der", "r");
                $ocsp = fread($ocspFile, 1000000);
                fclose($ocspFile);
                break;
            default:
                /* HTTP POST the serial to the CA. The CA knows about the state of
                 * the certificate.
                 *
                 * $httpResponse = httpRequest("https://clientca.hosted.eduroam.org/ocsp/", ["serial" => $serial ] );
                 *
                 * The result of this if clause has to be a DER-encoded OCSP statement
                 * to be stored in the variable $ocsp
                 */
                throw new Exception("External silverbullet CA is not implemented yet!");
        }
        // write the new statement into DB
        $dbHandle->exec("UPDATE silverbullet_certificate SET OCSP = ?, OCSP_timestamp = NOW() WHERE serial_number = ?", "si", $ocsp, $serial);
        return $ocsp;
    }

    /**
     * revokes a certificate
     * @param int $serial the serial number of the cert to revoke (decimal!)
     * @return array with revocation information
     */
    public function revokeCertificate($serial) {


// TODO for now, just mark as revoked in the certificates table (and use the stub OCSP updater)
        $nowSql = (new \DateTime())->format("Y-m-d H:i:s");
        if (CONFIG_CONFASSISTANT['SILVERBULLET']['CA']['type'] != "embedded") {
            // send revocation request to CA.
            // $httpResponse = httpRequest("https://clientca.hosted.eduroam.org/revoke/", ["serial" => $serial ] );
            throw new Exception("External silverbullet CA is not implemented yet!");
        }
        // regardless if embedded or not, always keep local state in our own DB
        $this->databaseHandle->exec("UPDATE silverbullet_certificate SET revocation_status = 'REVOKED', revocation_time = ? WHERE serial_number = ?", "si", $nowSql, $serial);
        $this->loggerInstance->debug(2, "Certificate revocation status updated, about to call triggerNewOCSPStatement($serial).\n");
        $ocsp = ProfileSilverbullet::triggerNewOCSPStatement($serial);
        return ["OCSP" => $ocsp];
    }

    /**
     * performs an HTTP request. Currently unused, will be for external CA API calls.
     * 
     * @param string $url the URL to send the request to
     * @param array $postValues POST values to send
     * @return string the returned HTTP content
     */
    private function httpRequest($url, $postValues) {
        $options = [
            'http' => ['header' => 'Content-type: application/x-www-form-urlencoded\r\n', "method" => 'POST', 'content' => http_build_query($postValues)]
        ];
        $context = stream_context_create($options);
        return file_get_contents($url, false, $context);
    }

    /**
     * checks a certificate's status in the database and delivers its properties in an array
     * 
     * @param \mysqli_result $certQuery
     * @return array properties of the cert in questions
     */
    public static function enumerateCertDetails($certQuery) {
        $retval = [];
        while ($resource = mysqli_fetch_object($certQuery)) {
            // is the cert expired?
            $now = new \DateTime();
            $cert_expiry = new \DateTime($resource->expiry);
            $delta = $now->diff($cert_expiry);
            $certStatus = ($delta->invert == 1 ? self::SB_CERTSTATUS_EXPIRED : self::SB_CERTSTATUS_VALID);
            // expired is expired; even if it was previously revoked. But do update status for revoked ones...
            if ($certStatus == self::SB_CERTSTATUS_VALID && $resource->revocation_status == "REVOKED") {
                $certStatus = self::SB_CERTSTATUS_REVOKED;
            }
            $retval[] = [
                "status" => $certStatus,
                "serial" => $resource->serial_number,
                "name" => $resource->cn,
                "issued" => $resource->issued,
                "expiry" => $resource->expiry,
                "device" => $resource->device,
            ];
        }
        return $retval;
    }

    /**
     * For a given certificate username, find the profile and username in CAT
     * this needs to be static because we do not have a known profile instance
     * 
     * @param string $certUsername a username from CN or sAN:email
     * @return array
     */
    public static function findUserIdFromCert($certUsername) {
        $dbHandle = \core\DBConnection::handle("INST");
        $userrows = $dbHandle->exec("SELECT silverbullet_user_id AS user_id, profile_id AS profile FROM silverbullet_certificate WHERE cn = ?", "s", $certUsername);
        // SELECT -> resource, not boolean
        while ($returnedData = mysqli_fetch_object(/** @scrutinizer ignore-type */ $userrows)) { // only one
            return ["profile" => $returnedData->profile, "user" => $returnedData->user_id];
        }
    }

    /**
     * find out about the status of a given SB user; retrieves the info regarding all his tokens (and thus all his certificates)
     * @param int $userId
     * @return array of invitationObjects
     */
    public function userStatus($userId) {
        $retval = [];
        $userrows = $this->databaseHandle->exec("SELECT `token` FROM `silverbullet_invitation` WHERE `silverbullet_user_id` = ? AND `profile_id` = ? ", "ii", $userId, $this->identifier);
        // SELECT -> resource, not boolean
        while ($returnedData = mysqli_fetch_object(/** @scrutinizer ignore-type */ $userrows)) {
            $retval[] = new SilverbulletInvitation($returnedData->token);
        }
        return $retval;
    }

    /**
     * finds out the expiry date of a given user
     * @param int $userId
     * @return string
     */
    public function getUserExpiryDate($userId) {
        $query = $this->databaseHandle->exec("SELECT expiry FROM silverbullet_user WHERE id = ? AND profile_id = ? ", "ii", $userId, $this->identifier);
        // SELECT -> resource, not boolean
        while ($returnedData = mysqli_fetch_object(/** @scrutinizer ignore-type */ $query)) {
            return $returnedData->expiry;
        }
    }
    
    /**
     * sets the expiry date of a user to a new date of choice
     * @param int $userId
     * @param \DateTime $date
     */
    public function setUserExpiryDate($userId, $date) {
        $query = "UPDATE silverbullet_user SET expiry = ? WHERE profile_id = ? AND id = ?";
        $theDate = $date->format("Y-m-d");
        $this->databaseHandle->exec($query, "sii", $theDate, $this->identifier, $userId);
    }

    /**
     * lists all users of this SB profile
     * @return array
     */
    public function listAllUsers() {
        $userArray = [];
        $users = $this->databaseHandle->exec("SELECT `id`, `username` FROM `silverbullet_user` WHERE `profile_id` = ? ", "i", $this->identifier);
        // SELECT -> resource, not boolean
        while ($res = mysqli_fetch_object(/** @scrutinizer ignore-type */ $users)) {
            $userArray[$res->id] = $res->username;
        }
        return $userArray;
    }

    /**
     * lists all users which are currently active (i.e. have pending invitations and/or valid certs)
     * @return array
     */
    public function listActiveUsers() {
        // users are active if they have a non-expired invitation OR a non-expired, non-revoked certificate
        $userCount = [];
        $users = $this->databaseHandle->exec("SELECT DISTINCT u.id AS usercount FROM silverbullet_user u, silverbullet_invitation i, silverbullet_certificate c "
                . "WHERE u.profile_id = ? "
                . "AND ( "
                . "( u.id = i.silverbullet_user_id AND i.expiry >= NOW() )"
                . "     OR"
                . "  ( u.id = c.silverbullet_user_id AND c.expiry >= NOW() AND c.revocation_status != 'REVOKED' ) "
                . ")", "i", $this->identifier);
        // SELECT -> resource, not boolean
        while ($res = mysqli_fetch_object(/** @scrutinizer ignore-type */ $users)) {
            $userCount[] = $res->usercount;
        }
        return $userCount;
    }

    /**
     * adds a new user to the profile
     * 
     * @param string $username
     * @param \DateTime $expiry
     * @return int row ID of the new user in the database
     */
    public function addUser($username, \DateTime $expiry) {
        $query = "INSERT INTO silverbullet_user (profile_id, username, expiry) VALUES(?,?,?)";
        $date = $expiry->format("Y-m-d");
        $this->databaseHandle->exec($query, "iss", $this->identifier, $username, $date);
        return $this->databaseHandle->lastID();
    }

    /**
     * revoke all active certificates and pending invitations of a user
     * @param int $userId
     */
    public function deactivateUser($userId) {
        // set the expiry date of any still valid invitations to NOW()
        $query = "SELECT id FROM silverbullet_invitation WHERE profile_id = $this->identifier AND silverbullet_user_id = ? AND expiry >= NOW()";
        $exec = $this->databaseHandle->exec($query, "i", $userId);
        // SELECT -> resource, not boolean
        while ($result = mysqli_fetch_object(/** @scrutinizer ignore-type */ $exec)) {
            $invitation = new SilverbulletInvitation($result->id);
            $invitation->revokeInvitation();
        }
        // and revoke all certificates
        $query2 = "SELECT serial_number FROM silverbullet_certificate WHERE profile_id = $this->identifier AND silverbullet_user_id = ? AND expiry >= NOW() AND revocation_status = 'NOT_REVOKED'";
        $exec2 = $this->databaseHandle->exec($query2, "i", $userId);
        // SELECT -> resource, not boolean
        while ($result = mysqli_fetch_object(/** @scrutinizer ignore-type */ $exec2)) {
            $this->revokeCertificate($result->serial_number);
        }
        // and finally set the user expiry date to NOW(), too
        $query3 = "UPDATE silverbullet_user SET expiry = NOW() WHERE profile_id = $this->identifier AND id = ?";
        $this->databaseHandle->exec($query3, "i", $userId);
    }
    
    /**
     * updates the last_ack for all users (invoked when the admin claims to have re-verified continued eligibility of all users)
     */
    public function refreshEligibility() {
        $query = "UPDATE silverbullet_user SET last_ack = NOW() WHERE profile_id = ?";
        $this->databaseHandle->exec($query, "i", $this->identifier);
    }
}
