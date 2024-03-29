<?php
/**
 * SPorto is a minimal SAML SP implementation for use in a hub federation as wayf.dk.
 *
 * Core functionallity is:
 * - Send a signed AuthnRequest to an IdP - Only one IdP supported
 * - Receive and verify a signed SAMLResponse 
 * - Accept an optional list of IdP entityID's used for scoping
 * 
 * It returns an array of the attributes in the AttributeStatement of the
 * response and the response it self.
 */

/**
 * @namespace
 */
namespace WAYF\SAML;

class SPorto
{
    private $config = array();

    public function __construct($config) {
        $this->config = $config;
    }
    
    public function authenticate($providerids = array()) {
        if (isset($_POST['SAMLResponse'])) {
            // Handle SAML response
            $message = base64_decode($_POST['SAMLResponse']);
            $document = new \DOMDocument();
            $document->loadXML($message);
            $xp = new \DomXPath($document);
            $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
            $xp->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
            $xp->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
            $this->verifySignature($xp, true);
            $this->validateResponse($xp);

            // @TODO Add IdP, Authtime??
            return array(
                'attributes' => $this->extractAttributes($xp),
                'response' => $message,
            );
        } else {
            // Handle SAML request
            $id =  '_' . sha1(uniqid(mt_rand(), true));
            $issueInstant = gmdate('Y-m-d\TH:i:s\Z', time());
            $sp = $this->config['entityid'];
            $asc = $this->config['asc'];
            $sso = $this->config['sso'];

            // Add scoping
            $scoping = '';
            foreach($providerids as $provider) {
                $scoping .= "<samlp:IDPEntry ProviderID=\"$provider\"/>";
            }
            if ($scoping) {
                $scoping = '<samlp:Scoping><samlp:IDPList>'.$scoping . '</samlp:IDPList></samlp:Scoping>';
            }

            // Construct request
            $request = <<<eof
<?xml version="1.0"?>
<samlp:AuthnRequest
    ID="$id"
    Version="2.0"
    IssueInstant="$issueInstant"
    Destination="$sso"
    AssertionConsumerServiceURL="$asc" 
    ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" 
    xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol">
    <saml:Issuer xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">$sp</saml:Issuer>
    $scoping
</samlp:AuthnRequest>
eof;

            // Construct request
            $queryString = "SAMLRequest=" . urlencode(base64_encode(gzdeflate($request)));;
            $queryString .= '&SigAlg=' . urlencode('http://www.w3.org/2000/09/xmldsig#rsa-sha1');
            
            // Get private key
            $key = openssl_pkey_get_private("-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split($this->config['private_key'], 64) ."-----END RSA PRIVATE KEY-----");
            if (!$key) {           
                throw new \Exception\SPortoException('Invalid private key used');                                                                             
            }

            // Sign the request
            $signature = "";
            openssl_sign($queryString, $signature, $key, OPENSSL_ALGO_SHA1);
            openssl_free_key($key);

            // Send request 
            header('Location: ' .  $this->config['sso'] . "?" . $queryString . '&Signature=' . urlencode(base64_encode($signature)));
            exit;
        }
    }

    private function extractAttributes($xp)
    {
        $res = array();
        // Grab attributes from AttributeSattement
        $attributes  = $xp->query("/samlp:Response/saml:Assertion/saml:AttributeStatement/saml:Attribute");
        foreach($attributes as $attribute) {
            $valuearray = array();
            $values = $xp->query('./saml:AttributeValue', $attribute);
            foreach($values as $value) {
                $valuearray[] = $value->textContent;
            }
            $res[$attribute->getAttribute('Name')] = $valuearray;
        }
        return $res;
    }

    private function verifySignature($xp, $assertion = true)
    {
        if ($assertion) {
            $context = $xp->query('/samlp:Response/saml:Assertion')->item(0);
        } else {
            $context = $xp->query('/samlp:Response')->item(0);
        }

        // Get signature and digest value
        $signatureValue = base64_decode($xp->query('ds:Signature/ds:SignatureValue', $context)->item(0)->textContent);
        $digestValue    = base64_decode($xp->query('ds:Signature/ds:SignedInfo/ds:Reference/ds:DigestValue', $context)->item(0)->textContent);
        $id = $xp->query('@ID', $context)->item(0)->value;

        $signedElement  = $context;
        $signature      = $xp->query("ds:Signature", $signedElement)->item(0);    
        $signedInfo     = $xp->query("ds:SignedInfo", $signature)->item(0)->C14N(true, false);
        $signature->parentNode->removeChild($signature);
        $canonicalXml = $signedElement->C14N(true, false);

        // Get IdP certificate
        $publicKey = openssl_get_publickey("-----BEGIN CERTIFICATE-----\n" . chunk_split($this->config['idp_certificate'], 64) . "-----END CERTIFICATE-----");
        if (!$publicKey) {           
            throw new \Exception\SPortoException('Invalid public key used');                                                                             
        }

        // Verify signature
        if (!((sha1($canonicalXml, TRUE) == $digestValue) && @openssl_verify($signedInfo, $signatureValue, $publicKey) == 1)) {
            throw new \Exception\SPortoException('Error verifying incoming SAMLResponse');
        }
    }

    private function validateResponse($xp)
    {
        $issues = array();

        // Verify destination
        $destination = $xp->query('/samlp:Response/@Destination')->item(0)->value;
        if ($destination != null && $destination != $this->config['asc']) { // Destination is optional
            $issues[] = "Destination: {$message['_Destination']} is not here; message not destined for us";
        }

        // Verify time stampss
        $skew = 60;
        $aShortWhileAgo = gmdate('Y-m-d\TH:i:s\Z', time() - $skew);
        $inAShortWhile = gmdate('Y-m-d\TH:i:s\Z', time() + $skew);
        
        $assertion = $xp->query('/samlp:Response/saml:Assertion')->item(0);
        $subjectConfirmationData_NotBefore = $xp->query('./saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData/@NotBefore', $assertion);
        if ($subjectConfirmationData_NotBefore->length  && $aShortWhileAgo < $subjectConfirmationData_NotBefore->item(0)->value) {
            $issues[] = 'SubjectConfirmation not valid yet';
        }

        $subjectConfirmationData_NotOnOrAfter = $xp->query('./saml:Subject/saml:SubjectConfirmation/saml:SubjectConfirmationData/@NotOnOrAfter', $assertion);
        if ($subjectConfirmationData_NotOnOrAfter->length && $inAShortWhile >= $subjectConfirmationData_NotOnOrAfter->item(0)->value) {
            $issues[] = 'SubjectConfirmation too old';
        }

        $conditions_NotBefore = $xp->query('./saml:Conditions/@NotBefore', $assertion);
        if ($conditions_NotBefore->length && $aShortWhileAgo > $conditions_NotBefore->item(0)->value) {
            $issues[] = 'Assertion Conditions not yet valid';
        }

        $conditions_NotOnOrAfter = $xp->query('./saml:Conditions/@NotOnOrAfter', $assertion);
        if ($conditions_NotOnOrAfter->length && $aShortWhileAgo >= $conditions_NotOnOrAfter->item(0)->value) {
            $issues[] = 'Assertions Condition too old';
        }

        $authStatement_SessionNotOnOrAfter = $xp->query('./saml:AuthStatement/@SessionNotOnOrAfter', $assertion);
        if ($authStatement_SessionNotOnOrAfter->length && $aShortWhileAgo >= $authStatement_SessionNotOnOrAfter->item(0)->value) {
            $issues[] = 'AuthnStatement Session too old';
        }

        if (!empty($issues)) {
            throw new \WAYF\Exceptions\SPortoException('Problems detected with response. ' . PHP_EOL. 'Issues: ' . PHP_EOL . implode(PHP_EOL, $issues));
        }
    }
}
