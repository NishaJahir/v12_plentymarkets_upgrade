var $ = jQuery.noConflict();
var paymentName = $('#paymentKey').val();
var updated = paymentName.split("_");
var res = updated.toLowerCase();
console.log(res);

$(document).ready( function () {
    
    //~ Save card details process
        if (jQuery("#nnToggleForm").length <= 0 || jQuery("#nnToggleForm").is(':checked') ) {
            jQuery("#radioOption").show();
        } else {
            jQuery("#newCardDetails").hide();
        }
        
         jQuery("input[type='radio']").click(function(){
            var tokenValue = jQuery("input[id='maskingDetails']:checked").val();
            if(tokenValue){
                var token = jQuery("#nn_saved_" + payment_name  + "_token").val();
                jQuery('#nnSelected'+ res[0] + res[1] +'_token').val(token);
            } else {
                jQuery('#nnSelected'+ res[0] + res[1] +'_token').val('');
            }
    });

        jQuery("input[name='radioOption']").on(
            'click', function () {
                if(jQuery(this).attr('id') == 'nnToggleForm') {
                    jQuery("#newCardDetails").show();
                    jQuery("#newForm").val('1');
                } else {
                    jQuery("#newCardDetails").hide();
                }
            }
        );
    
        jQuery("input[name='radioOption']:first").attr("checked","checked");
    
    // For credit card payment form process
        if (paymentName == 'NOVALNET_CC') {
            loadNovalnetCcIframe();
            jQuery('#novalnetForm').submit( function (e) {
                    if($('#nnCcPanHash').val().trim() == '') {
                        NovalnetUtility.getPanHash();
                        e.preventDefault();
                        e.stopImmediatePropagation();
                    }
                });
        }
    
    // For Direct Debit SEPA payment form process
    if(paymentName == 'NOVALNET_SEPA') {
        $('#nnSepaIban').on('input',function ( event ) {
        let iban = $(this).val().replace( /[^a-zA-Z0-9]+/g, "" ).replace( /\s+/g, "" );
            $(this).val(iban);      
        });

        $('#novalnetForm').on('submit',function(){
          $('#novalnetFormBtn').attr('disabled',true);      
        });
    }
    
});


function loadNovalnetCcIframe()
{
     var ccCustomFields = $('#nnCcFormFields').val() != '' ? JSON.parse($('#nnCcFormFields').val()) : null;
     var ccFormDetails= $('#nnCcFormDetails').val() != '' ? JSON.parse($('#nnCcFormDetails').val()) : null;
    console.log(ccCustomFields);
    console.log(ccFormDetails);
    
    // Set your Client key
    NovalnetUtility.setClientKey((ccFormDetails.client_key !== undefined) ? ccFormDetails.client_key : '');

     var requestData = {
        'callback': {
          on_success: function (result) {
            $('#nnCcPanHash').val(result['hash']);
            $('#nnCcUniqueId').val(result['unique_id']);
            $('#nnCc3dRedirect').val(result['do_redirect']);
            jQuery('#novalnetForm').submit();
            return true;
          },
          on_error: function (result) {
           if ( undefined !== result['error_message'] ) {
              alert(result['error_message']);
              return false;
            }
          },

           // Called in case the challenge window Overlay (for 3ds2.0) displays
          on_show_overlay:  function (result) {
            $( '#nnIframe' ).addClass( '.overlay' );
          },

           // Called in case the Challenge window Overlay (for 3ds2.0) hided
          on_hide_overlay:  function (result) {
            $( '#nnIframe' ).removeClass( '.overlay' );
          }
        },

         // You can customize your Iframe container style, text etc.
        'iframe': {

         // Passed the Iframe Id
          id: "nnIframe",

          // Display the inline form if the values is set as 1
          inline: (ccFormDetails.inline_form !== undefined) ? ccFormDetails.inline_form : '0',
         
          // Adjust the creditcard style and text 
          style: {
            container: (ccCustomFields.novalnet_cc_standard_style_css !== undefined) ? ccCustomFields.novalnet_cc_standard_style_css : '',
            input: (ccCustomFields.novalnet_cc_standard_style_field !== undefined) ? ccCustomFields.novalnet_cc_standard_style_field : '' ,
            label: (ccCustomFields.novalnet_cc_standard_style_label !== undefined) ? ccCustomFields.novalnet_cc_standard_style_label : '' ,
          },
          
          text: {
            lang : (ccFormDetails.lang !== undefined) ? ccFormDetails.lang : 'en',
            error: (ccCustomFields.credit_card_error !== undefined) ? ccCustomFields.credit_card_error : '',
            card_holder : {
              label: (ccCustomFields.novalnetCcHolderLabel !== undefined) ? ccCustomFields.novalnetCcHolderLabel : '',
              place_holder: (ccCustomFields.novalnetCcHolderInput !== undefined) ? ccCustomFields.novalnetCcHolderInput : '',
              error: (ccCustomFields.novalnetCcError !== undefined) ? ccCustomFields.novalnetCcError : ''
            },
            card_number : {
              label: (ccCustomFields.novalnetCcNumberLabel !== undefined) ? ccCustomFields.novalnetCcNumberLabel : '',
              place_holder: (ccCustomFields.novalnetCcNumberInput !== undefined) ? ccCustomFields.novalnetCcNumberInput : '',
              error: (ccCustomFields.novalnetCcError !== undefined) ? ccCustomFields.novalnetCcError : ''
            },
            expiry_date : {
              label: (ccCustomFields.novalnetCcExpiryDateLabel !== undefined) ? ccCustomFields.novalnetCcExpiryDateLabel : '',
              place_holder: (ccCustomFields.novalnetCcExpiryDateInput !== undefined) ? ccCustomFields.novalnetCcExpiryDateInput : '',
              error: (ccCustomFields.novalnetCcError !== undefined) ? ccCustomFields.novalnetCcError : ''
            },
            cvc : {
              label: (ccCustomFields.novalnetCcCvcLabel !== undefined) ? ccCustomFields.novalnetCcCvcLabel : '',
              place_holder: (ccCustomFields.novalnetCcCvcInput !== undefined) ? ccCustomFields.novalnetCcCvcInput : '',
              error: (ccCustomFields.novalnetCcError !== undefined) ? ccCustomFields.novalnetCcError : ''
            }
          }
        },

         // Add Customer data
        customer: {
          first_name: (ccFormDetails.first_name !== undefined) ? ccFormDetails.first_name : '',
          last_name: (ccFormDetails.last_name !== undefined) ? ccFormDetails.last_name : ccFormDetails.first_name,
          email: (ccFormDetails.email !== undefined) ? ccFormDetails.email : '',
          billing: {
            street: (ccFormDetails.street !== undefined) ? ccFormDetails.street : '',
            city: (ccFormDetails.city !== undefined) ? ccFormDetails.city : '',
            zip: (ccFormDetails.zip !== undefined) ? ccFormDetails.zip : '',
            country_code: (ccFormDetails.country_code !== undefined) ? ccFormDetails.country_code : ''
          },
          shipping: {
            same_as_billing: (ccFormDetails.same_as_billing !== undefined) ? ccFormDetails.same_as_billing : 0,
          },
        },
        
         // Add transaction data
        transaction: {
          amount: (ccFormDetails.amount !== undefined) ? ccFormDetails.amount : '',
          currency: (ccFormDetails.currency !== undefined) ? ccFormDetails.currency : '',
          test_mode: (ccFormDetails.test_mode !== undefined) ? ccFormDetails.test_mode : '0',
        }
      };

      NovalnetUtility.createCreditCardForm(requestData);
}

function removeCardDetails(token)
{
    var removeSavedCardParams = { 'token' : token };   
    console.log(removeSavedCardParams);
    removeSavedCardRequestHandler(removeSavedCardParams);
}

// Remove the save card details based on the customer input
function removeSavedCardRequestHandler(removeSavedCardParams) {
    console.log(removeSavedCardParams);
    if ('XDomainRequest' in window && window.XDomainRequest !== null) {
        var xdr = new XDomainRequest(); // Use Microsoft XDR
        var removeSavedCardParams = $.param(removeSavedCardParams);
        xdr.open('POST', $('#removalProcessUrl').val());
        xdr.onload = function (result) {
            $('#remove_'+removeSavedCardParams['token']).remove();
                alert($('#removeCardDetail').val());
                window.location.reload();
        };
        xdr.onerror = function () {
            _result = false;
        };
        xdr.send(removeSavedCardParams);
    } else {
        $.ajax(
            {
                url      : $('#removalProcessUrl').val(),
                type     : 'post',
                dataType : 'html',
                data     : removeSavedCardParams,
                success  : function (result) {
                    console.log(result);
                       console.log($('#removalProcessUrl').val());
                    $('#remove_'+removeSavedCardParams['token']).remove();
                    console.log($('#remove_'+removeSavedCardParams['token']).val());
                    alert($('#removeCardDetail').val());
               
                }
            }
        );
    }
    
    
}
