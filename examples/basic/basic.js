var Basic = {
    setCookies: function() {
        $.cookie('boshRid', $.xmpp.jid, {path: '/'});
        $.cookie('boshSid', $.xmpp.sid, {path: '/'});
        $.cookie('boshRid', $.xmpp.rid, {path: '/'});
        $.cookie('boshUrl', $.xmpp.url, {path: '/'});
    },
    delCookies: function(){
        if($.cookie('boshJid'))
            $.cookie('boshJid', null, {path: '/'});

        if($.cookie('boshSid'))
            $.cookie('boshSid', null, {path: '/'});

        if($.cookie('boshRid'))
            $.cookie('boshRid', null, {path: '/'});

        if($.cookie('boshUrl'))
            $.cookie('boshUrl', null, {path: '/'});
    }
};

$(document).ready(function(){
    var log = $('#log');

    $.xmpp.attach($.cookie('boshJid'), $.cookie('boshSid'), $.cookie('boshRid'), $.cookie('boshUrl'),{
        onConnect: function(){
            log.append('onConnect<br/>');

            // Send initial presence stanza
            var presence = '<presence xmlns="'+$.xmpp.NS.CLIENT+'"/>';
            $.xmpp.sendStanza(presence);
        },
        onDisconnect: function(){
            log.append('onDisconnect<br/>');
        },
        onPresence: function(presence){
            log.append('onPresence<br/>');
        },
        onMessage: function(message){
            log.append('onMessage<br/>');
        },
        onIq: function(iq){
            log.append('onIq<br/>');
        },
        onError: function(error){
            log.append('onError: '+ error +'<br/>');
        }
    });

    $("#disconnect").click(function(){
        $.xmpp.disconnect();
    });

    // On beforeunload we set cookies (to resume BOSH session after page reload)
    $(window).bind('beforeunload', function (){
        if($.xmpp.isConnected()){
            $.xmpp.pause();
            Basic.setCookies();
        } else{
            Basic.delCookies();
        }
    });

    // On blur we delete cookies (to make sure that new tab starts new BOSH session)
    $(window).bind('blur', function(){
        Basic.delCookies();
    });
});