<?php
/**
 * XMPP BOSH for PHP
 *
 * @author Michael Weibel <michael.weibel@amiadogroup.com>
 * @author Marko Martinović <marko@techytalk.info>
 *
 * XMPP Library using BOSH extension (XEP-0206) for connecting to XMPP server
 * & receiving sid and rid for attaching to existing BOSH session.
 *
 * Supports PLAIN, DIGEST_MD5, CRAM_MD5 and ANONYMOUS SASL mechanisms
 *
 */

/**
 * PEAR Auth_SASL
 */
require 'Auth/SASL.php';

class XmppBosh {
    const XMLNS_BODY    = 'http://jabber.org/protocol/httpbind';
    const XMLNS_BOSH    = 'urn:xmpp:xbosh';
    const XMLNS_CLIENT  = 'jabber:client';
    const XMLNS_SESSION = 'urn:ietf:params:xml:ns:xmpp-session';
    const XMLNS_BIND    = 'urn:ietf:params:xml:ns:xmpp-bind';
    const XMLNS_SASL    = 'urn:ietf:params:xml:ns:xmpp-sasl';
    const XMLNS_VCARD   = 'vcard-temp';
    const XML_LANG      = 'en';
    const CONTENT_TYPE  = 'text/xml charset=utf-8';
    const SERVICE_NAME = 'xmpp';

    const ENCRYPTION_PLAIN      = 'PLAIN';
    const ENCRYPTION_DIGEST_MD5 = 'DIGEST-MD5';
    const ENCRYPTION_CRAM_MD5 = 'CRAM-MD5';
    const ENCRYPTION_ANONYMOUS = 'ANONYMOUS';

    private $jid = '';
    private $node   = '';
    private $domain = '';
    private $resource   = '';
    private $password = '';
    private $url    = '';
    private $rid = '';
    private $sid = '';

    private $wait = 60;
    private $hold = 1;
    private $useSSL = false;
    private $doSession = false;
    private $doBind    = false;
    private $mechanisms = array();
    private $encryption = null;

    /**
     * Create a new XmppBosh Object with the required params
     *
     * @param string $domain    XMPP Server domain
     * @param string $url       URL to the http-bind
     * @param string $resource  Resource identifier
     * @param bool   $useSSL    Use SSL (TODO)
     */
    public function __construct($domain, $url, $resource = '', $useSSL = false) {
        $this->domain = $domain;
        $this->url    = $url;
        $this->resource = $resource;
        $this->useSSL = $useSSL;

        // Generate request id, will be incremented after every request
        if (function_exists('mt_rand')) {
            $this->rid = mt_rand(1000000000, 10000000000);
        } else {
            $this->rid = rand(1000000000, 10000000000);
        }
    }

    /**
     * Connect to the XMPP server with the supplied node & password
     *
     * @param string $node Node without domain
     * @param string $password Password
     */
    public function connect($node = null, $password = null) {
        if($node != null && $password != null){
            $this->node = $node;
            $this->password = $password;
            $this->jid = $this->node . '@' . $this->domain;

            if($this->resource != '')
                $this->jid .= '/' . $this->resource;
        }

        // Init connection & get server supported mechanisms
        $response = $this->sendInitConnection();
        $body = self::getBodyFromXml($response);
        $this->sid = $body->getAttribute('sid');
        $mechanisms = $body->firstChild->getElementsByTagName('mechanism');

        foreach ($mechanisms as $value) {
            $this->mechanisms[] = $value->nodeValue;
        }

        /* If node & password not provided and server supports ANONYMOUS
         * we select that mechanism. To select other mechanisms we must have node
         * & password
         */
        if (in_array(self::ENCRYPTION_ANONYMOUS, $this->mechanisms)
                && $node == null
                && $password == null) {
            $this->encryption = self::ENCRYPTION_ANONYMOUS;
        }else if (in_array(self::ENCRYPTION_DIGEST_MD5, $this->mechanisms)
                && $node != null
                && $password != null) {
            $this->encryption = self::ENCRYPTION_DIGEST_MD5;
        } elseif (in_array(self::ENCRYPTION_CRAM_MD5, $this->mechanisms)
                && $node != null
                && $password != null) {
            $this->encryption = self::ENCRYPTION_CRAM_MD5;
        } elseif (in_array(self::ENCRYPTION_PLAIN, $this->mechanisms)
                && $node != null
                && $password != null) {
            $this->encryption = self::ENCRYPTION_PLAIN;
        } else {
            throw new XmppBoshConnectionException("No encryption supported by the server is supported by this library.");
        }

        // Authentication process using selected mechanism
        $auth = Auth_SASL::factory($this->encryption);
        switch ($this->encryption) {
                case self::ENCRYPTION_PLAIN:
                        $response = $this->buildPlainAuth($auth);
                        break;
                case self::ENCRYPTION_DIGEST_MD5:
                        $response = $this->sendChallengeAndBuildDigestMd5Auth($auth);
                        break;
                case self::ENCRYPTION_CRAM_MD5:
                        $response = $this->sendChallengeAndBuildCramMd5Auth($auth);
                        break;
                case self::ENCRYPTION_ANONYMOUS:
                        $response = $this->buildAnonymousAuth($auth);
                        break;
        }

        $response = $this->send($response);
        $body = self::getBodyFromXml($response);

        // Authentication success or failure
        if (!$body->hasChildNodes() || $body->firstChild->nodeName !== 'success') {
            throw new XmppBoshException("Invalid login");
        }

        // For ANONYMOUS mechanism we send new init, for others restart
        if($this->encryption != self::ENCRYPTION_ANONYMOUS){
            $response = $this->sendRestart();
        }else{
            $response = $this->sendInitConnection();
        }

        // Detect are bind and session required
        $body = self::getBodyFromXml($response);
        foreach ($body->childNodes as $bodyChildNodes) {
            if ($bodyChildNodes->nodeName === 'stream:features') {
                foreach ($bodyChildNodes->childNodes as $streamFeatures) {
                    if ($streamFeatures->nodeName === 'bind') {
                            $this->doBind = true;
                    } elseif ($streamFeatures->nodeName === 'session') {
                            $this->doSession = true;
                    }
                }
            }
        }

        // If bind required we send it
        if ($this->doBind) {
            $response = $this->sendBind();

            // For ANONYMOUS mechanism bind returns full JID, so lets store it
            if($this->encryption == self::ENCRYPTION_ANONYMOUS){
                $body = self::getBodyFromXml($response);
                if ($body->hasChildNodes() && $body->firstChild->getAttribute('type') == 'result'){
                    $this->jid = $body->firstChild->firstChild->firstChild->nodeValue;
                }else{
                    throw new XmppException('Subscription request not successful');
                }
            }
        }

        // If session required we send it
        if($this->doSession){
            $response = $this->sendSession();
        }
    }

    /**
     * Send initial connection string
     *
     * @return string Response
     */
    private function sendInitConnection() {
        $domDocument = $this->buildBody();
        $body = self::getBodyFromDomDocument($domDocument);

        $body->appendChild(self::getNewTextAttribute($domDocument, 'hold', $this->hold));
        $body->appendChild(self::getNewTextAttribute($domDocument, 'to', $this->domain));
        $body->appendChild(self::getNewTextAttribute($domDocument, 'xmlns:xmpp', self::XMLNS_BOSH));
        $body->appendChild(self::getNewTextAttribute($domDocument, 'xmpp:version', '1.0'));
        $body->appendChild(self::getNewTextAttribute($domDocument, 'wait', $this->wait));

        return $this->send($domDocument->saveXML());
    }

    /**
     * Send xmpp restart message after successful auth
     *
     * @return string Response
     */
    private function sendRestart() {
        $domDocument = $this->buildBody();
        $body = self::getBodyFromDomDocument($domDocument);
        $body->appendChild(self::getNewTextAttribute($domDocument, 'to', $this->domain));
        $body->appendChild(self::getNewTextAttribute($domDocument, 'xmlns:xmpp', self::XMLNS_BOSH));
        $body->appendChild(self::getNewTextAttribute($domDocument, 'xmpp:restart', 'true'));

        return $this->send($domDocument->saveXML());
    }

    /**
     * Send bind if there's a bind node in the restart or second init
     * response (within stream:features)
     *
     * @return string Response
     */
    private function sendBind() {
        $domDocument = $this->buildBody();
        $body = self::getBodyFromDomDocument($domDocument);

        $iq = $domDocument->createElement('iq');
        $iq->appendChild(self::getNewTextAttribute($domDocument, 'xmlns', self::XMLNS_CLIENT));
        $iq->appendChild(self::getNewTextAttribute($domDocument, 'type', 'set'));
        $iq->appendChild(self::getNewTextAttribute($domDocument, 'id', 'bind_' . rand()));

        $bind = $domDocument->createElement('bind');
        $bind->appendChild(self::getNewTextAttribute($domDocument, 'xmlns', self::XMLNS_BIND));

        $resource = $domDocument->createElement('resource');
        $resource->appendChild($domDocument->createTextNode($this->resource));

        $bind->appendChild($resource);
        $iq->appendChild($bind);
        $body->appendChild($iq);

        return $this->send($domDocument->saveXML());
    }

    /**
     * Send session if there's a session node in the restart or second
     * init response (within stream:features)
     *
     * @return string Response
     */
    private function sendSession() {
        $domDocument = $this->buildBody();
        $body = self::getBodyFromDomDocument($domDocument);

        $iq = $domDocument->createElement('iq');
        $iq->appendChild(self::getNewTextAttribute($domDocument, 'xmlns', self::XMLNS_CLIENT));
        $iq->appendChild(self::getNewTextAttribute($domDocument, 'type', 'set'));
        $iq->appendChild(self::getNewTextAttribute($domDocument, 'id', 'session_auth_' . rand()));

        $session = $domDocument->createElement('session');
        $session->appendChild(self::getNewTextAttribute($domDocument, 'xmlns', self::XMLNS_SESSION));

        $iq->appendChild($session);
        $body->appendChild($iq);

        return $this->send($domDocument->saveXML());
    }

    /**
     * Send challenge request
     *
     * @return string Challenge
     */
    private function sendChallenge() {
        $domDocument = $this->buildBody();
        $body = self::getBodyFromDomDocument($domDocument);

        $auth = $domDocument->createElement('auth');
        $auth->appendChild(self::getNewTextAttribute($domDocument, 'xmlns', self::XMLNS_SASL));
        $auth->appendChild(self::getNewTextAttribute($domDocument, 'mechanism', $this->encryption));
        $body->appendChild($auth);

        $response = $this->send($domDocument->saveXML());

        $body = $this->getBodyFromXml($response);
        $challenge = base64_decode($body->firstChild->nodeValue);

        return $challenge;
    }

    /**
     * Build anonymous auth, no encoding, hashing or encryption takes place for
     * this mechanism
     *
     * @param Auth_SASL_Common $auth
     * @return string Response
     */
    private function buildAnonymousAuth(Auth_SASL_Common $auth) {
        $domDocument = $this->buildBody();
        $body = self::getBodyFromDomDocument($domDocument);

        $auth = $domDocument->createElement('auth');
        $auth->appendChild(self::getNewTextAttribute($domDocument, 'xmlns', self::XMLNS_SASL));
        $auth->appendChild(self::getNewTextAttribute($domDocument, 'mechanism', $this->encryption));
        $body->appendChild($auth);

        return $domDocument->saveXML();
    }

    /**
     * Build PLAIN auth string
     *
     * @param Auth_SASL_Common $auth
     * @return string Auth XML to send
     */
    private function buildPlainAuth(Auth_SASL_Common $auth) {
        $authString = $auth->getResponse($this->node, $this->password, self::getBareJidFromJid($this->jid));
        $authString = base64_encode($authString);

        $domDocument = $this->buildBody();
        $body = self::getBodyFromDomDocument($domDocument);

        $auth = $domDocument->createElement('auth');
        $auth->appendChild(self::getNewTextAttribute($domDocument, 'xmlns', self::XMLNS_SASL));
        $auth->appendChild(self::getNewTextAttribute($domDocument, 'mechanism', $this->encryption));
        $auth->appendChild($domDocument->createTextNode($authString));
        $body->appendChild($auth);

        return $domDocument->saveXML();
    }

    /**
     * Send challenge request and build DIGEST-MD5 auth string
     *
     * @param Auth_SASL_Common $auth
     * @return string Auth XML to send
     */
    private function sendChallengeAndBuildDigestMd5Auth(Auth_SASL_Common $auth) {
        $challenge = $this->sendChallenge();

        $authString = $auth->getResponse($this->node, $this->password, $challenge, $this->domain, self::SERVICE_NAME);
        $authString = base64_encode($authString);

        $domDocument = $this->buildBody();
        $body = self::getBodyFromDomDocument($domDocument);

        $response = $domDocument->createElement('response');
        $response->appendChild(self::getNewTextAttribute($domDocument, 'xmlns', self::XMLNS_SASL));
        $response->appendChild($domDocument->createTextNode($authString));

        $body->appendChild($response);

        $challengeResponse = $this->send($domDocument->saveXML());

        return $this->replyToChallengeResponse($challengeResponse);
    }

    /**
     * Send challenge request and build CRAM-MD5 auth string
     *
     * @param Auth_SASL_Common $auth
     * @return string Auth XML to send
     */
    private function sendChallengeAndBuildCramMd5Auth(Auth_SASL_Common $auth) {
        $challenge = $this->sendChallenge();

        $authString = $auth->getResponse($this->node, $this->password, $challenge);
        $authString = base64_encode($authString);

        $domDocument = $this->buildBody();
        $body = self::getBodyFromDomDocument($domDocument);

        $response = $domDocument->createElement('response');
        $response->appendChild(self::getNewTextAttribute($domDocument, 'xmlns', self::XMLNS_SASL));
        $response->appendChild($domDocument->createTextNode($authString));

        $body->appendChild($response);

        $challengeResponse = $this->send($domDocument->saveXML());

        return $this->replyToChallengeResponse($challengeResponse);
    }

    /**
     * CRAM-MD5 and DIGEST-MD5 reply with an additional challenge response which
     * must be replied to. After this additional reply, the server should reply
     * with "success".
     */
    private function replyToChallengeResponse($challengeResponse) {
        $body = self::getBodyFromXml($challengeResponse);
        $challenge = base64_decode((string)$body->firstChild->nodeValue);
        if (strpos($challenge, 'rspauth') === false) {
                throw new XmppBoshConnectionException('Invalid challenge response received');
        }

        $domDocument = $this->buildBody();
        $body = self::getBodyFromDomDocument($domDocument);
        $response = $domDocument->createElement('response');
        $response->appendChild(self::getNewTextAttribute($domDocument, 'xmlns', self::XMLNS_SASL));

        $body->appendChild($response);

        return $domDocument->saveXML();
    }

    /**
     * Send XML via CURL
     *
     * @param string $xml
     * @return string Response
     */
    private function send($xml) {
        $ch = curl_init($this->url);

        // Set SSL options
        if($this->useSSL) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $header = array('Content-Type: ' . self::CONTENT_TYPE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($ch);

        curl_close($ch);

        return $response;
    }

    /**
     * Build DOMDocument with standard BOSH body child node.
     *
     * @return DOMDocument
     */
    private function buildBody() {
        $xml = new DOMDocument('1.0', 'UTF-8');

        $body = $xml->createElement('body');
        $xml->appendChild($body);

        $body->appendChild(self::getNewTextAttribute($xml, 'xmlns', self::XMLNS_BODY));
        $body->appendChild(self::getNewTextAttribute($xml, 'content', self::CONTENT_TYPE));
        $body->appendChild(self::getNewTextAttribute($xml, 'rid', $this->getAndIncrementRid()));
        $body->appendChild(self::getNewTextAttribute($xml, 'xml:lang', self::XML_LANG));

        if ($this->sid != '') {
            $body->appendChild(self::getNewTextAttribute($xml, 'sid', $this->sid));
        }

        return $xml;
    }

    /**
     * Get jid, sid and rid
     *
     * @return array
     */
    public function getSessionInfo() {
        return array('jid' => $this->jid, 'sid' => $this->sid, 'rid' => $this->rid, 'url' => $this->url);
    }

    /**
     * Get bare JID from full JID in form of node@domain
     *
     * @param string $jid full JID in form node@domain/Resource
     * @return string Bare JID
     */
    public static function getBareJidFromJid($jid) {
        $splittedJid = explode('/', $jid, 1);
        return $splittedJid[0];
    }

    /**
     * Append new attribute to existing DOMDocument.
     *
     * @param DOMDocument $domDocument
     * @param string $attributeName
     * @param string $value
     * @return DOMNode
     */
    private static function getNewTextAttribute($domDocument, $attributeName, $value) {
        $attribute = $domDocument->createAttribute($attributeName);
        $attribute->appendChild($domDocument->createTextNode($value));

        return $attribute;
    }

    /**
     * Get body node from DOMDocument
     *
     * @param DOMDocument $domDocument
     * @return DOMNode
     */
    private static function getBodyFromDomDocument($domDocument) {
        $body = $domDocument->getElementsByTagName('body');
        return $body->item(0);
    }

    /**
     * Parse XML and return DOMNode of the body
     *
     * @uses XmppBosh::getBodyFromDomDocument()
     * @param string $xml
     * @return DOMNode
     */
    private static function getBodyFromXml($xml) {
        $domDocument = new DOMDocument();
        $domDocument->loadXml($xml);

        return self::getBodyFromDomDocument($domDocument);
    }

    /**
     * Get the rid and increment it by one.
     *
     * @return int
     */
    private function getAndIncrementRid() {
        return $this->rid++;
    }
}

/**
 * Standard XmppBosh Exception
 */
class XmppBoshException extends Exception{}

/**
 * XmppBosh Connection Exception
 */
class XmppBoshConnectionException extends XmppBoshException {}