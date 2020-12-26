var $ = jQuery.noConflict();
var nnButton, nnIfrmButton, iframeWindow, targetOrigin;
nnButton = nnIfrmButton = iframeWindow = targetOrigin = false;
var paymentName = $('#paymentKey').val();

function initIframe()
{
    var request = {
        callBack: 'createElements',
        customStyle: {
            labelStyle: $('#nnCcStandardStyleLabel').val(),
            inputStyle: $('#nnCcStandardStyleInput').val(),
            styleText: $('#nnCcStandardStyleCss').val(),
            }
    };

    var iframe = $('#nnIframe')[0];
    iframeWindow = iframe.contentWindow ? iframe.contentWindow : iframe.contentDocument.defaultView;
    targetOrigin = 'https://secure.novalnet.de';
    iframeWindow.postMessage(JSON.stringify(request), targetOrigin);
}

function getHash(e)
{   
    $('#novalnetFormBtn').attr('disabled',true);
    
    if($('#nnPanHash').val().trim() == '') {
        alert('yes');
        e.preventDefault();
        e.stopImmediatePropagation();
        iframeWindow.postMessage(
            JSON.stringify(
                {
                'callBack': 'getHash',
                }
            ), targetOrigin
        );
    } else {
        alert('enter');
        return true;
    }
}

function reSize()
{
    if ($('#nnIframe').length > 0) {
        var iframe = $('#nnIframe')[0];
        iframeWindow = iframe.contentWindow ? iframe.contentWindow : iframe.contentDocument.defaultView;
        targetOrigin = 'https://secure.novalnet.de/';
        iframeWindow.postMessage(JSON.stringify({'callBack' : 'getHeight'}), targetOrigin);
    }
}

window.addEventListener(
    'message', function (e) {
    var data = (typeof e.data === 'string') ? eval('(' + e.data + ')') : e.data;
        
    if (e.origin === 'https://secure.novalnet.de') {
        if (data['callBack'] == 'getHash') {
            if (data['error_message'] != undefined) {
                $('#novalnetFormBtn').attr('disabled',false); 
                alert($('<textarea />').html(data['error_message']).text());
            } else {
        $('#nnPanHash').val(data['hash']);
                $('#nnUniqueId').val(data['unique_id']);
                $('#novalnetForm').submit();
            }
        }

        if (data['callBack'] == 'getHeight') {
            $('#nnIframe').attr('height', data['contentHeight']);
        }
    }
    }, false
);

$(document).ready( function () {
    
    if(paymentName == 'NOVALNET_CC') {
        $(window).resize( function() {
            reSize();
        });
    }
    
    if(paymentName == 'NOVALNET_SEPA') {
        $('#nnSepaIban').on('input',function ( event ) {
        let iban = $(this).val().replace( /[^a-zA-Z0-9]+/g, "" ).replace( /\s+/g, "" );
            $(this).val(iban);      
        });
    
        $('#nnSepaCardholder').keypress(function (event) {
         var keycode = ( 'which' in event ) ? event.which : event.keyCode,
         reg     = /[^0-9\[\]\/\\#,+@!^()$~%'"=:;<>{}\_\|*?`]/g;
         return ( reg.test( String.fromCharCode( keycode ) ) || 0 === keycode || 8 === keycode );
         });

        $('#novalnetForm').on('submit',function(){
          $('#novalnetFormBtn').attr('disabled',true);      
        });
    }
    
});
