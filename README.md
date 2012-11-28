xmpp-bosh-php-jquery
====================
This code enables you to [prebind](http://metajack.im/2009/12/14/fastest-xmpp-sessions-with-http-prebinding/) a XMPP BOSH session with PHP and attach to the same session with jQuery. Traditionally when connecting to XMPP from Javascript using BOSH you need to pass JID and password to the client side that triggers authentication trough BOSH. This is both insecure and inefficient because your Javascript library must support all required authentication mechanisms. Much better idea is to do the authentication on the safe ground of your server using PHP (prebind BOSH conenction) and instead of passing password to client side pass only JID, SID and RID for attaching to BOSH session from client side. This way the worst case scenario from security point of view means that only one session can be compromised, and that your can drop authentification layer from your Javascript library.

Contents
--------
This repository holds PHP prebind library forked from [xmpp-prebind-php](https://github.com/candy-chat/xmpp-prebind-php/) and patched to support additional authentication mechanisms. It also holds jQuery plugin for handling XMPP BOSH session attachment from client side using JID, SID and RID.

Examples
--------
Inside examples directory there are two examples where I pass BOSH session data using cookies. One example shows you how to do this for ANONYMOUS and the other for other SASL mechanisms like DIGEST_MD5.
