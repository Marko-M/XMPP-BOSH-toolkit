<?php
if( !(isset($_COOKIE['boshJid'])
        &&
    isset($_COOKIE['boshSid'])
        &&
    isset($_COOKIE['boshRid'])
        &&
    isset($_COOKIE['boshUrl']))){
    require '../../lib/XmppBosh.php';

    $boshUrl = 'http://example.com/http-bind/'; // BOSH url
    $domain = 'example.com';                    // XMPP host

    $xmppBosh = new XmppBosh($domain, $boshUrl,  '', false, false);
    $xmppBosh->connect();       // For anonymous set node = null and password = null

    $boshSession = $xmppBosh->getSessionInfo();

    setcookie('boshJid', $boshSession['jid'], 0, '/');
    setcookie('boshSid', $boshSession['sid'], 0, '/');
    setcookie('boshRid', $boshSession['rid'], 0, '/');
    setcookie('boshUrl', $boshSession['url'], 0, '/');
}
?>
<html>
    <head>
        <title>Anonymous XMPP connection</title>
        <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.7/jquery.min.js"></script>
        <script type="text/javascript" src='https://raw.github.com/carhartl/jquery-cookie/master/jquery.cookie.js'></script>
        <script type="text/javascript" src='../../js/XmppBosh.js'></script>
        <script type="text/javascript" src='anonymous.js'></script>
    </head>

    <body>
        <button id="disconnect">Disconnect</button>
        <br>

        <div id="log">
        </div>
    </body>
</html>