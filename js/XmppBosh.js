/*
 *      XMPP BOSH for jQuery
 *
 *      Copyright 2012 Marko Martinović <marko@techytalk.info>
 *
 *      This program is free software; you can redistribute it and/or modify
 *      it under the terms of the GNU General Public License as published by
 *      the Free Software Foundation; either version 3 of the License, or
 *      (at your option) any later version.
 *
 *      This program is distributed in the hope that it will be useful,
 *      but WITHOUT ANY WARRANTY; without even the implied warranty of
 *      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *      GNU General Public License for more details.
 *
 *      You should have received a copy of the GNU General Public License
 *      along with this program; if not, write to the Free Software
 *      Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 *      MA 02110-1301, USA.
 */

(function($) {
    $.xmpp = {
        // XMPP request ID
        rid:null,

        // XMPP session ID
        sid:null,

        // XMPP JID
        jid:null,

        // Resource part of JID
        resource: null,

        // Node part of JID
        node: null,

        // Domain part of JID
        domain: null,

        // BOSH URL
        url: null,

        // Callbacks
        onDisconnect: null,
        onConnect: null,
        onMessage: null,
        onIq: null,
        onPresence: null,
        onError: null,

        // Log to browser console
        debug: false,

        // Helpers
        connected: false,
        paused: false,
        requestCount: 0,

        /**
         * Common namespace constants from the XMPP RFCs and XEPs
         *
         * NS.HTTPBIND - HTTP BIND namespace from XEP 124.
         * NS.BOSH - BOSH namespace from XEP 206.
         * NS.CLIENT - Main XMPP client namespace.
         * NS.AUTH - Legacy authentication namespace.
         * NS.ROSTER - Roster operations namespace.
         * NS.PROFILE - Profile namespace.
         * NS.DISCO_INFO - Service discovery info namespace from XEP 30.
         * NS.DISCO_ITEMS - Service discovery items namespace from XEP 30.
         * NS.MUC - Multi-User Chat namespace from XEP 45.
         * NS.SASL - XMPP SASL namespace from RFC 3920.
         * NS.STREAM - XMPP Streams namespace from RFC 3920.
         * NS.BIND - XMPP Binding namespace from RFC 3920.
         * NS.SESSION - XMPP Session namespace from RFC 3920.
         * NS.XHTML_IM - XHTML-IM namespace from XEP 71.
         * NS.XHTML - XHTML body namespace from XEP 71.
         *
         * Taken from Strophe library
         */
        NS: {
            HTTPBIND: "http://jabber.org/protocol/httpbind",
            BOSH: "urn:xmpp:xbosh",
            CLIENT: "jabber:client",
            AUTH: "jabber:iq:auth",
            ROSTER: "jabber:iq:roster",
            PROFILE: "jabber:iq:profile",
            DISCO_INFO: "http://jabber.org/protocol/disco#info",
            DISCO_ITEMS: "http://jabber.org/protocol/disco#items",
            MUC: "http://jabber.org/protocol/muc",
            SASL: "urn:ietf:params:xml:ns:xmpp-sasl",
            STREAM: "http://etherx.jabber.org/streams",
            BIND: "urn:ietf:params:xml:ns:xmpp-bind",
            SESSION: "urn:ietf:params:xml:ns:xmpp-session",
            VERSION: "jabber:iq:version",
            STANZAS: "urn:ietf:params:xml:ns:xmpp-stanzas",
            XHTML_IM: "http://jabber.org/protocol/xhtml-im",
            XHTML: "http://www.w3.org/1999/xhtml"
        },
        /**
         * Attach to existing BOSH session
         * @param {String} jid XMPP JID
         * @param {String} sid XMPP session ID
         * @param {String} rid XMPP request ID
         * @param {String} url XMPP BOSH URL
         * @param {Object} callbacks Initial BOSH parameters and event callbacks
         * @param {Boolean} debug  Log to browser console
         * {
         * onConnect: function(){},
         * onDisconnect: function(){},
         * onPresence: function(response){},
         * onMessage: function(response){},
         * onIq: function(response){}
         * onError: function(error){}
         * }
         */
        attach: function(jid, sid, rid, url, callbacks, debug){
            var xmpp = this;

            // Set BOSH parameters
            xmpp.jid = jid;
            xmpp.sid = sid;
            xmpp.rid = rid;
            xmpp.url = url;
            xmpp.domain = xmpp.getDomainFromJid(xmpp.jid);
            xmpp.resource = xmpp.getResourceFromJid(xmpp.jid);
            xmpp.node = xmpp.getNodeFromJid(xmpp.jid);
            xmpp.debug = debug;

            // Set callbacks
            xmpp.onDisconnect = callbacks.onDisconnect;
            xmpp.onConnect = callbacks.onConnect;
            xmpp.onMessage = callbacks.onMessage;
            xmpp.onIq = callbacks.onIq;
            xmpp.onPresence = callbacks.onPresence;
            xmpp.onError = callbacks.onError;

            // Start listening for incoming messages
            xmpp.listen();

            // Connected, initiate on Attach callback
            xmpp.connected = true;
            if(xmpp.onConnect != null)
               xmpp.onConnect();
        },
        /**
         * Disconnect current BOSH session
         */
        disconnect: function(){
            var xmpp = this;

            // Add type="terminate" to body wrapper and send type="unavailable" presence
            xmpp.sendStanza('<presence xmlns="'+$.xmpp.NS.CLIENT+'" type="unavailable"/>', 'type="terminate"');
        },
        /**
         * Listen for incoming messages
         */
        listen: function(){
            var xmpp = this;

            // Send empty body wrapper
            xmpp.sendStanza(null);
        },
        /**
         * Wrap stanza with body wrapper, if necessary add attr to wrapper and send stanza.
         * @param {String|null} stanza Stanza string to be sent
         * @param {String|null} attr Additional attributes string to add to body wrapper,
         * if null then we add nothing
         */
        sendStanza: function(stanza, attr){
            var xmpp = this;

            // Generate boddy wrapper, add additionall attributes if any
            var msga = [];
            msga.push('<body');
            msga.push('rid="'+ xmpp.rid +'"');
            msga.push('sid="'+ xmpp.sid +'"');
            msga.push('xmlns="'+xmpp.NS.HTTPBIND+'"');
            if(attr != null)
                msga.push(attr);
            if(stanza != null)
                msga.push('>'+stanza+'</body');
            else
                msga.push('/>');
            var msg = msga.join(' ');

            // Log to browser console
            if(xmpp.debug)
                xmpp.log('OUT: '+ msg);

            /* Very important! We keep one request open at all times so we need to track this.
             * XMPP server hangs on this open request untill it has something to send back */
            xmpp.requestCount++;

            // Send stanza using Ajax and expect xml document back
            $.ajax({
                type: 'POST',
                url: xmpp.url,
                data: msg,
                dataType: 'xml',
                success: function(xmlData){
                    // This request is back, decrease requests count
                    xmpp.requestCount--;

                    // Log to browser console
                    if(xmpp.debug)
                        xmpp.log('IN: '+ xmpp.xmlToString(xmlData));

                    // Transform XML document into jQuery object for traversal
                    var body = $(xmlData).children('body');

                    if($(body).attr('type') == 'terminate'){
                        /* Detected servers acknowledge to our disconnect request,
                        * initiate onDisconnect callback
                        */
                        xmpp.connected = false;
                        if(xmpp.onDisconnect != null)
                            xmpp.onDisconnect();
                    } else{
                        // Search response for incoming message, initiat onMessage callback if found
                        $(body).find("message").each(function(){
                            xmpp.onMessage($(this));
                        });

                        // Search response for incoming iq, initiat onIq callback if found
                        $(body).find("iq").each(function(){
                            xmpp.onIq($(this));
                        });

                        // Search response for incoming presence, initiat onPresence callback if found
                        $(body).find("presence").each(function(){
                            xmpp.onPresence($(this));
                        });

                        /* Open requests push data from server to our client. If there
                        * are no open requests and if session is not paused we must
                        * send empty request by calling xmpp.listen() to keep incoming
                        * chanenel open
                        */
                        if(!xmpp.paused & xmpp.requestCount == 0){
                            xmpp.listen();
                        }
                    }
                }
            });

            /* Every sent stanzas body wrapper must have request ID inside tight
             * window defined by XMPP server. This is to prevent session hijack
             * by stealing session ID and guessing request ID. With each sent
             * stanza we increase body wrapper rid by 1 and that is as tight
             * as it gets.
             */
            xmpp.rid++;
        },
        /**
         * Pause current BOSH session
         */
        pause: function (){
            var xmpp = this;
            xmpp.paused = true;
        },
        /**
         * Resume current BOSH session paused by $.xmpp.pause()
         */
        resume: function (){
            var xmpp = this;
            xmpp.paused = false;
            xmpp.listen();
        },
        /**
         * Test is session in connected state
         * @return {Boolean} True if connected, false otherwise
         */
        isConnected: function(){
            var xmpp = this;
            return xmpp.connected;
        },
        /**
         * Test is listening paused
         * @return {Boolean} True if paused, false otherwise
         */
        isPaused: function(){
            var xmpp = this;
            return xmpp.paused;
        },
        /**
         * Log message to browser console
         * @param {String} message Message to be logged to browser console
         */
        log: function(message){
            console.log(message);
        },
        /**
         * Convert XMLDocument object to string
         * @param {Object} XMLData XMLDocument DOM element to be serialized
         * @return {String} String containing serialized XMLDocument
         */
        xmlToString: function(XMLData) {
            var XMLString;
            //IE
            if (window.ActiveXObject){
                XMLString = XMLData.xml;
            }
            // code for Mozilla, Firefox, Opera, etc.
            else{
                XMLString = (new XMLSerializer()).serializeToString(XMLData);
            }
            return XMLString;
        },
        /**
         * Get the bare JID from a JID String
         * @param {String} jid XMPP JID
         * @return {String} A String containing the bare JID
         *
         * Taken from Strophe library
         */
        getBareJidFromJid: function (jid){
            return jid ? jid.split("/")[0] : null;
        },
        /**
         * Get the resource portion of a JID String
         * @param {String} jid XMPP JID
         * @return {String} A String containing the resource
         *
         * Taken from Strophe library
         */
        getResourceFromJid: function (jid){
            var s = jid.split("/");
            if (s.length < 2) {return null;}
            s.splice(0, 1);
            return s.join('/');
        },
        /**
         * Get the domain portion of a JID String.
         * @param {String} jid XMPP JID
         * @return {String} A String containing the domain
         *
         * Taken from Strophe library
         */
        getDomainFromJid: function (jid){
            var xmpp = this;
            var bare = xmpp.getBareJidFromJid(jid);
            if (bare.indexOf("@") < 0) {
                return bare;
            } else {
                var parts = bare.split("@");
                parts.splice(0, 1);
                return parts.join('@');
            }
        },
        /**
         * Get the node portion of a JID String
         * @param {String} jid XMPP JID
         * @return {String} A String containing the node
         *
         * Taken from Strophe library
         */
        getNodeFromJid: function (jid){
            if (jid.indexOf("@") < 0) {return null;}
            return jid.split("@")[0];
        }
    }
})(jQuery);